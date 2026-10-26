# 08 — Security audit

How we audit and clean a WordPress site that is compromised or suspected of being compromised. For hardening a site that is not compromised, see [05-hardening.md](05-hardening.md).

The method comes from two real incident responses:

- A site compromised for years, with four malware families living side by side in the files.
- A site whose file system was spotless (core checksums, signatures, mu-plugins, cron, users: all clean) and that had still been serving SEO spam from the database for years. The first verdict, "not compromised", was wrong.

It covers three layers: **WordPress** (files and database), **content** (`post_content`, comments, `robots.txt`, `.htaccess`) and the **operating system** (accounts, SSH, cron, systemd, ports, mail, binaries). Everything below is read-only until the eradicate phase, and even then the rule is: quarantine, never delete.

## Phase order

**Inventory → contain → eradicate → verify → harden.**

Contain comes before clean because a live reinfection mechanism brings back every file you delete. You lose hours chasing ghosts.

## 1. Inventory (read only)

Write down: WordPress version, the real webroot path, PHP version and the user running the pool, database engine and version, web server, listening ports. Without the real webroot and the PHP user, later steps fail silently.

```bash
wp --path=/home/siteuser/public_html core version --skip-plugins --skip-themes
php -v | head -1
ps -eo user,comm | grep -E "php-fpm|apache2|nginx" | sort -u
mysql --version
ss -tlnp
```

## 2. Contain: cut the reinfection

Look for the three persistence mechanisms, in this order, and neutralize them before touching any other file.

**1. `wp-content/mu-plugins/`.** Must-use plugins run on every request, do not appear in the plugin list and cannot be deactivated from the admin. In one incident, dozens of files were cleaned and the ghost administrator came back minutes later, because the mechanism lived here and nobody had looked. Look first, always.

```bash
ls -la /home/siteuser/public_html/wp-content/mu-plugins/
```

A malicious one usually has a plausible name (`wp-core-update-3a9f1c.php`) and either restores a webshell from a hidden copy or guards an entry point.

**2. Crontabs of every user.** WordPress never creates system crontabs; its cron is internal. A job that copies files into the webroot is a watchdog:

```bash
for u in $(cut -d: -f1 /etc/passwd); do
  c=$(crontab -l -u "$u" 2>/dev/null | grep -vE '^#'); [ -n "$c" ] && echo "=== $u ===" && echo "$c"
done
ls -la /var/spool/cron/crontabs/ /etc/cron.d/
```

```
*/5 * * * * test -f .../plugins/X/backdoor.php || cp .../uploads/copy.php .../plugins/X/backdoor.php  # WP_CRON
```

The `# WP_CRON` comment is camouflage.

**3. Drop-ins.** `object-cache.php`, `advanced-cache.php`, `db.php` and `sunrise.php` load before everything else. Check that each belongs to a plugin that is really installed.

Then block the entry point that is being exploited, if the logs show one. For a core REST batch endpoint exploit, put the rule **before** the WordPress block in `.htaccess` (the `[L]` of the WP block cuts processing):

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^wp-json/batch/v1 - [F,L]
  RewriteCond %{QUERY_STRING} (^|&)rest_route=/batch/v1 [NC]
  RewriteRule ^ - [F,L]
</IfModule>
```

Check the logs for it: `grep -cE "batch/v1|rest_route=/batch" access_log`. A 2026 core vulnerability chain in that endpoint (wp2shell, CVE-2026-63030 and CVE-2026-60137) affected WordPress 6.9.0 to 6.9.4 and 7.0.0 to 7.0.1; it is fixed in 6.9.5 and 7.0.2. If the version is old, the entry was more likely there than through a plugin. Blocking the endpoint can affect legitimate features, so treat it as a stopgap until the site is patched.

Neutralize compromised accounts by disabling, not deleting (you keep the evidence), and invalidate sessions:

```sql
UPDATE wp_users SET user_pass = CONCAT('!', user_pass) WHERE ID = <id>;
UPDATE wp_usermeta SET meta_value = 'a:0:{}' WHERE user_id = <id> AND meta_key = 'wp_capabilities';
DELETE FROM wp_usermeta WHERE meta_key = 'session_tokens';
```

## 3. Eradicate

### Quarantine, not deletion

Files are evidence: they tell you the family, what it was after and sometimes how it got in. Moving them out of the webroot neutralizes them just as well, and a false positive is one `mv` away from being undone.

```bash
Q=/root/incident-$(date +%F)/quarantine
mv_q() {
  src="$1"; [ -e "$src" ] || return
  dst="$Q/$(dirname "${src#/}")"; mkdir -p "$dst"
  chmod -R u+w "$src" 2>/dev/null   # malware often comes as 444
  mv "$src" "$dst/"
}
```

### Core checksums: the check that finds the most

Compare against the official manifest instead of hunting patterns:

```bash
wp core verify-checksums --path=/home/siteuser/public_html
wp plugin verify-checksums --all --path=/home/siteuser/public_html
```

Also list the `.php` files in `wp-admin/` and `wp-includes/` that are **not in the official manifest**. In one incident this exposed six backdoors with believable names (`class-wp-query-extra.php`, `wp-job-runner.php`) that three signature passes had missed; none used `eval`, `base64_decode` or `shell_exec`. Paid plugins cannot be verified this way, so reinstall them from the vendor.

### Signature sweeps: one pass is never enough

Each family uses different constructions. Cover command execution, classic obfuscation, `goto` obfuscation, known-family markers and scanner-fooling filler (long literary text inside a plugin's `.php`):

```bash
cd /home/siteuser/public_html
grep -rlE "shell_exec|passthru|proc_open|popen\(|base64_decode\(|gzinflate\(|str_rot13|hex2bin|assert\(|create_function" --include=*.php wp-content | head -50
grep -rlE "^<\?php goto " --include=*.php . | head
find . -name "*.php" -path "*/uploads/*"
find . -name "*.php" -newer wp-config.php -path "*/wp-content/*" | head -50
```

Expect false positives: core's `Text/Diff/Engine/shell.php`, `ID3`, `kses.php`, `SimplePie`, `vendor/` and `node_modules/`. Before flagging core, verify against checksums.

### Hash sweep: the net for what is left

Once the quarantine is populated, hash everything in it, **without filtering by extension**, and search the whole system for those hashes, including `/srv`, `/opt`, `/var/tmp` and `/dev/shm`. Filter by size first and hash only candidates; ignore files under 64 bytes, which match by coincidence.

```bash
find "$Q" -type f -size +63c -exec md5sum {} + | awk '{print $1}' | sort -u > /root/quarantine.md5
# then, per candidate path: md5sum the files whose size matches one in quarantine and compare
```

### Binaries have no extension

In a real incident a statically linked, stripped 4 MB XMRig miner sat in a theme at a path like `wp-content/themes/<theme>/images/gallery/cache/class.render`. Checksums skip themes, signature sweeps read only `.php` and `.js`, and an extension-filtered hash sweep skipped the CI build directory. Search by magic bytes:

```bash
find /home/siteuser /srv /tmp /var/tmp /dev/shm /opt \
  \( -name node_modules -o -name .cache \) -prune -o -type f -size +3c -print0 2>/dev/null \
  | LC_ALL=C xargs -0 awk 'FNR==1 { if ($0 ~ /^(\177ELF|MZ|#!|\376\355\372[\316\317]|[\316\317]\372\355\376)/) print FILENAME; nextfile }'
grep -aoiE 'xmrig|stratum|randomx|monero|cryptonight|donate-level' suspicious-file | sort | uniq -c
ss -tnp | awk '$5 ~ /:(3333|4444|5555|7777|14444|10128|10343|45700)$/'
ps -eo pid,user,pcpu,etime,args --sort=-pcpu | head
```

An ELF, Mach-O or PE inside the webroot is critical; WordPress needs none. Build and CI directories (`/srv`, `/opt`, the runner's `_work`) go into every sweep, otherwise you audit the output and not the source.

**Audit the tree before the first commit** when moving a site into Git or CI. A `cp -a` drags the binary, the webshell and a tampered `.htaccess` into history, and from there into every build and every clone. Run the sweeps, and check that `git status` lists only what you recognize before `git add`.

### Timestamps lie

Malware often `touch`es its own files to copy a neighbor's `mtime`. Build timelines from `ctime`, which `touch` cannot forge, and from the web server access logs:

```bash
find /home/siteuser/public_html -type f -ctime -30 -printf '%CY-%Cm-%Cd %CH:%CM %u %p\n' | sort -r | head -30
```

A WordPress update also moves `ctime` in bulk; cross-check the peaks with the checksum results.

### The database hosts backdoors too

A backdoor in the database survives any file cleanup.

```bash
wp user list --role=administrator --fields=ID,user_login,user_email,user_registered
wp user application-password list <user_id>
wp cron event list --fields=hook,next_run_gmt,recurrence
```

```sql
-- two ways of counting administrators; if they differ, someone wrote capabilities by hand
SELECT user_id FROM wp_usermeta WHERE meta_key='wp_capabilities' AND meta_value LIKE '%administrator%';
-- duplicated wp_capabilities rows mean direct SQL insertion
SELECT user_id, COUNT(*) FROM wp_usermeta WHERE meta_key='wp_capabilities' GROUP BY user_id HAVING COUNT(*) > 1;

-- code in options (LIKE, not REGEXP: REGEXP times out on large options in MySQL 8)
SELECT option_name, LENGTH(option_value) FROM wp_options
WHERE LEFT(option_name,11)<>'_transient_' AND LEFT(option_name,16)<>'_site_transient_'
  AND (option_value LIKE '%<script%' OR option_value LIKE '%eval(%' OR option_value LIKE '%atob(%'
    OR option_value LIKE '%fromCharCode%' OR option_value LIKE '%base64_decode%' OR option_value LIKE '%<?php%');

-- code snippet plugins store arbitrary PHP in wp_posts
SELECT ID, post_status, post_title FROM wp_posts WHERE post_type IN ('wpcode','snippet');

-- siteurl/home must be your domain
SELECT option_name, option_value FROM wp_options WHERE option_name IN ('siteurl','home');
```

Also look at application passwords (they survive a password change), custom roles with `manage_options`, `edit_users` or `install_plugins`, and `widget_custom_html`, `widget_text` and `custom_css`. On WordPress 6.6 and later the autoload column uses `on`/`off`/`auto`; a query for `'yes'` returns 0 and looks clean when it is not.

## 4. The content layer

**A site can have a spotless file system and still be compromised.** SEO spam executes nothing on the server, so no code scanner sees it. It lives in `post_content` and survives any file cleanup. Always look, even if every earlier phase is clean.

### How it hides

| Technique | Signature |
|---|---|
| Fake posts published in bulk | None: normal posts nobody on the team wrote |
| Off-screen `<div>` | `position:absolute; left:-3514px; top:-3655px` |
| Squashed `<div>` | `overflow:hidden; height:0.2px` |
| Packed JS hiding a block | `eval(function(p,a,c,k,e,d)` decoding to `getElementById("X").style.display="none"` |
| `<script>` injecting CSS into `<head>` | `insertAdjacentHTML("beforeend", ` with `<st`+`yle` split to dodge scanners |
| One link inside parentheses | `Publisher: Example Institute. (<a href="https://spam.example/x/">Brand</a>)`: hides nothing, passes for a footnote |

```sql
SELECT ID, post_title FROM wp_posts
WHERE post_status IN ('publish','private') AND (
     post_content REGEXP 'position: ?(absolute|fixed)[^"]*(left|top|right): ?-[0-9]{3,}px'
  OR post_content REGEXP 'overflow: ?hidden;? ?height: ?0'
  OR post_content LIKE '%eval(function(p,a,c,k,e%'
  OR post_content LIKE '%insertAdjacentHTML%'
  OR post_content LIKE '%display:none%');
```

Search by **shape**, not by words. A list of drug or casino names misses injected links whose path is random junk. The parenthesized link has this shape:

```
\(\s*<a[^>]+href=["\']https?://[^"\']+["\'][^>]*>.*?</a>\s*\)
```

### The three signals that discriminate best

1. **Volume per year.** The attacker publishes at a rate the real team never had. This places the compromise window in seconds:

   ```sql
   SELECT YEAR(post_date) y, COUNT(*) n FROM wp_posts
   WHERE post_type='post' AND post_status='publish' GROUP BY y ORDER BY y;
   -- e.g. 61, then 1070, then 62: the middle year is the window
   ```

   Also check the author: fake posts were credited to a `post_author` that no longer exists in `wp_users`. Attackers reuse the site's real categories to blend in.
2. **Outbound domains sorted by rarity.** List every external domain and look at the ones that appear in a single post. Legitimate ones (press, universities, public bodies) repeat in dozens. This is the robust signal.
3. **Language, with a warning.** A language filter (for example Spanish marks such as `[áéíóúñ¿¡]` on a Spanish site) gave zero false positives over about three thousand posts, but a few dozen false negatives, because part of the spam arrives machine-translated into the site's language. Use language to rule things out, never to absolve. With Polylang or WPML it is useless.

### Check the homepage even if it looks fine

In one incident the homepage `post_content` was entirely replaced by casino spam. Nobody noticed for years because the template called `the_content()` inside a secondary loop, so it was never printed. Check `page_on_front` and `page_for_posts` explicitly.

### Configuration files contain no code, which is why they are found late

`robots.txt` and `.htaccess` are plain text, match no signature, are not in the core manifest and are not in `wp_posts`. Yet they are what makes the spam profitable: a doorway Google does not index is worthless. Read them by hand.

```
Sitemap: https://example.org/?sitemap123.xml
Sitemap: https://example.org/shop.php?sitemap204.xml     <- names the doorway
```

- A `Sitemap:` pointing at a `.php` (legitimate ones are `.xml`) or with a query string (`?sitemapNNN.xml`; no SEO plugin generates those).
- An `.htaccess` that denies all `.php` and then whitelists a list: it looks like hardening, but names in the list that are not core are the attacker's doorways.
- `auto_prepend_file` in `.htaccess` or `.user.ini` (equivalent to a mu-plugin; a `.user.ini` from Wordfence's WAF is normal, so read it).
- `RewriteCond` on `HTTP_USER_AGENT` or `HTTP_REFERER` with search engines: cloaking.
- Ownership verification files (`google*.html`, `BingSiteAuth.xml`) the client does not recognize.
- `uploads/.htaccess` missing, or present but allowing PHP. Check the **real** uploads path: `wp eval 'echo wp_upload_dir(null,false)["basedir"];'`.

### Cleaning content: trash, never delete

There is no quarantine in the database, so the substitute is the trash. Take a full dump first, do a dry run before every pass, and verify afterwards.

```php
wp_trash_post( $id );      // reversible from the dashboard
// NEVER wp_delete_post( $id, true ) in a cleanup
```

- Fake posts go to the trash. Legitimate posts with an injected block are edited, removing only the block.
- A legitimate title outranks a 100% spam body: clean it and keep it.
- A percentage threshold does not work on short posts. Measure whether **visible text** remains.
- Zero visible characters does not mean empty: some real posts are just a poster image.
- Rerun the full content scan after every cleanup; stop only when two consecutive passes find nothing new. In the real case it took five passes, and each found a campaign the previous one could not see.

### Comment spam is another front

If moderation is on, spam is not published but it piles up (about four thousand pending, 99% spam, three quarters aimed at one decade-old post). In order of impact:

```bash
wp option update close_comments_for_old_posts 1
wp option update close_comments_days_old 60
wp plugin install antispam-bee --activate
```

Closing comments on old posts costs nothing and cuts almost everything. Antispam Bee needs no API key and sends nothing to a third party, unlike Akismet. A captcha goes last or never: it adds friction for everyone and is an accessibility problem. Verify with `comments_open($id)`, because `comment_status` in the database still says `open`. Also check approved comments with an external author URL, since each is a free backlink for the spam:

```sql
SELECT comment_ID, comment_post_ID, comment_author, comment_author_url FROM wp_comments
WHERE comment_approved='1' AND comment_author_url<>'' AND comment_author_url NOT LIKE '%example.org%';
```

## 5. The system layer

A compromised WordPress is almost never only a WordPress problem. The checks, with the commands:

```bash
awk -F: '$3==0 {print}' /etc/passwd                              # UID 0: only root
find / -name "authorized_keys*" -not -path "/proc/*" 2>/dev/null # SSH keys, all of them
sshd -T | grep -E "^(permitrootlogin|passwordauthentication|allowusers|port)"   # effective config
last -n 30 -F; lastb -n 20 -F
cat /etc/ld.so.preload 2>/dev/null                               # rootkit classic
lsattr -R /home/siteuser/public_html /etc/cron* /root/.ssh 2>/dev/null | awk '$1 ~ /[ia]/'   # immutable files
find / -xdev -perm /6000 -type f 2>/dev/null | while read f; do dpkg -S "$f" >/dev/null 2>&1 || echo "NO PACKAGE: $f"; done
ls -la /home/*/.config/systemd/user; ls /var/lib/systemd/linger  # user units that start without a session
for p in /proc/[0-9]*; do e=$(readlink $p/exe 2>/dev/null); case "$e" in *memfd:*|*"(deleted)"*) echo "${p#/proc/} $e";; esac; done
mailq | tail -3; grep -oE "from=<[^>]*>" /var/log/mail.log | sort | uniq -c | sort -rn | head
```

- Control panels create FTP and mail accounts that share the site owner's UID: whatever those accounts do happens with the web owner's permissions.
- `sshd -T` shows the effective configuration; a drop-in can override a correct `sshd_config`. `AllowUsers` is a whitelist, and with repeated directives the first one wins.
- A file with `chattr +i` or `+a` cannot be removed even by root. If `dpkg -V e2fsprogs` flags `chattr` or `lsattr`, the tool you look with is not trustworthy.
- Unknown senders in `mail.log` mean the server is a spam relay and the IP is heading for blocklists. If the MX points at an external provider, the server does not need to receive mail at all, and the whole mail stack can usually be removed.
- In MariaDB, legacy password hashes live in the `Password` column, not `authentication_string`; querying only the second gives false "no password" findings.
- `ss -tulpn` mixes TCP and UDP. A control panel can show a UDP discovery listener on `0.0.0.0` and the TCP interface correctly bound to `127.0.0.1`. Look at `ss -tlnp` and `ss -ulnp` separately, and confirm from outside.

## 6. Verify from outside

A configuration that looks right proves nothing. What counts is what the server answers to a third party.

```bash
for p in 21 22 25 3306; do nc -z -w5 SERVER_IP $p && echo "$p OPEN"; done   # add your panel's port
curl -s -o /dev/null -w "%{http_code}\n" https://example.org/wp-content/uploads/test.php            # create a test file first: expect 403, then delete it
curl -s -A "Mozilla/5.0 (compatible; Googlebot/2.1)" -e "https://www.google.com/" https://example.org/ | wc -c   # cloaking: compare with a normal request
curl -s -A "Mozilla/5.0" -e "https://www.google.com/search?q=test" https://example.org/ | grep -icE "[ぁ-んァ-ン一-龥]|viagra|casino"
```

Then external reputation: Google Safe Browsing (transparency report), Sucuri SiteCheck (it caches, so force a re-scan after cleaning), `crt.sh` for forgotten subdomains and hosted phishing, DNS, and Spamhaus (`zen` for the IP, `dbl` for the domain; a `127.255.255.x` answer from a public resolver means "query refused", not "clean"). By hand: `site:example.org` on Google, also with `casino`, `viagra`, `betting` and Japanese characters, and Search Console (Security and manual actions, odd queries). Ask the client about complaints: a "deceptive site" warning, bounced mail, abuse notices from the host. They are the timeline the logs do not keep.

## 7. Harden

Apply what closes the real entry point, not a generic list. The highest-yield measures:

- **No PHP execution in uploads.** Put this in `wp-content/uploads/.htaccess`, then test it: drop a `.php` there, request it, expect 403, delete it.

  ```apache
  <FilesMatch "\.(?i:php|phtml|php[0-9]|phps|phar|inc|cgi|pl|py|suspected)$">
    Require all denied
  </FilesMatch>
  ```

- **Rotate every credential.** If there was a password stealer, everything used since its installation is compromised: WordPress admins, `wp-config.php` salts (this invalidates all cookies), the database password (in MySQL and `wp-config.php`), system, panel and mail passwords, plugin API keys. Restart PHP-FPM afterwards: workers keep the cached config and the site returns 500 with the old password. Generate with `openssl rand -base64 24 | tr -d '/+=' | cut -c1-24`.
- **Block user creation at the database level** if the attack created administrators. It stops direct SQL from a webshell, where no WordPress protection reaches. Remove it temporarily to create a legitimate user.

  ```sql
  CREATE TRIGGER block_user_creation BEFORE INSERT ON wp_users
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User creation blocked';
  ```

- **`DISALLOW_FILE_EDIT`** in `wp-config.php`. `DISALLOW_FILE_MODS` also blocks updates, so use it only if another process patches.
- **Remove services you do not use** instead of turning them off: FTP, IMAP/POP3, unneeded panels. Do not use `systemctl mask`: the package's post-install script fails on the next upgrade and leaves `dpkg` in a cascade of errors. Use `apt purge`, or `systemctl disable --now` without `mask`.
- **Admin panels behind an SSH tunnel or VPN.** Bind the service to `127.0.0.1`, close its port, and forward it (`ssh -N -L <local-port>:127.0.0.1:<panel-port> user@host`).
- **Check that fail2ban really bans.** A jail with thousands of attempts in the log and zero bans has a broken filter, usually journald against a log file or the other way around.

Before rebooting after a major OS upgrade, run `findmnt --verify`, confirm a new kernel is installed, run `sshd -t`, list masked units with `systemctl list-unit-files --state=masked`, and have the provider's out-of-band console at hand.

## The biggest lessons

- **Contain before you clean.** If a reinfection mechanism is alive, every file you delete comes back.
- **Quarantine, never delete.** In the database, use the trash.
- **Check mu-plugins first.** They run on every request and are invisible in the admin.
- **Checksums find what signatures cannot.**
- **Malware is not always code.** SEO spam lives in the database, `robots.txt` and `.htaccess` are plain text, a miner can be an extensionless ELF. Search by magic bytes, by content hash and by shape.
- **One pass is never enough.** Each sweep changes the picture. Rerun everything after each cleanup.
- **A negative is worth what it covered.** A scan that says "clean" must also say what it did not look at. A skipped check is not a clean one.
- **Separate what is confirmed from what is suspected,** and say openly when a later sweep corrects an earlier conclusion.
- **Be honest about the limit.** After a long compromise, an in-place cleanup does not give certainty: in one incident the fourth sweep still found seven new backdoors. Say so, and offer a rebuild on a clean server with verified content. Often the real cost is in mail and DNS, not in the website.
