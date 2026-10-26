# 05. Hardening

Hardening here means one thing: make the common ways a WordPress site gets owned
fail closed, using settings that live in the repo or in provisioning, so they
survive a redeploy and a new server. None of it replaces keeping software
updated and watching the site. If you suspect a site is already compromised, go
to [08-security-audit.md](08-security-audit.md) first.

The principles behind every item below:

- **Allowlist, not blocklist.** A blocklist of "bad extensions" loses to the
  next extension you did not think of. List what is allowed and deny the rest.
- **Code only comes from git and Composer.** The server never receives code
  from the dashboard, from an upload, or from a cron job that updates things.
- **Layers.** Each control assumes the one before it failed.

## 1. The uploads folder is an allowlist

`wp-content/uploads` is the one place where untrusted input becomes files on
disk, and it sits under the web root. If anything (a vulnerable plugin, a leaked
SFTP password, a bad import) writes a `.php` file there, the attacker owns the
site as soon as they request the URL. WordPress checks the MIME type on upload,
but that check only guards the front door. The server rule guards every other
way in.

There are two layers. Use both.

### Layer 1 (primary): deny script files in the web server config

A rule in the vhost cannot be changed or removed by whoever can write into the
uploads folder, and it works however PHP is attached. Apache:

```apache
<VirtualHost *:443>
    # ...
    # <LocationMatch> (URL based) and not <Directory> (filesystem based): a request
    # proxied to PHP-FPM by ProxyPassMatch is routed by URL and never goes
    # through <Directory> or .htaccess processing. Location sections always apply.
    <LocationMatch "(?i)^/wp-content/uploads/.*\.(php[0-9]*|phtml|pht|phar|phps|cgi|pl|py|sh|asp|aspx|jsp)(\.|/|$)">
        Require all denied
    </LocationMatch>

    <Directory /home/siteuser/public_html/wp-content/uploads>
        Options -Indexes -ExecCGI
    </Directory>
</VirtualHost>
```

The pattern also matches `shell.php.jpg` (a script extension anywhere in the
name) and `shell.php/anything.jpg` (path info). Change the path if the site moved
its uploads folder.

Nginx: the uploads rule has to be evaluated **before** the generic PHP location.
Nginx uses the first matching regex `location` in file order, so if
`location ~ \.php$` comes first, uploaded PHP runs:

```nginx
# 1. FIRST: refuse script files under uploads (.php, .php5, .phtml, .pht, .phar, ...)
location ~* ^/wp-content/uploads/.*\.(php[0-9]*|phtml|pht|phar|phps)(\.|/|$) {
    return 403;
}

# 2. Only after that, the generic PHP handler
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

A rule that does not depend on order: put the uploads folder in a
`location ^~ /wp-content/uploads/ { ... }` block, which stops nginx from trying
regex locations, and put the `return 403;` regex location inside it.

### Layer 2 (second layer): the allowlist in `uploads/.htaccess`

[`server/uploads.htaccess`](../server/uploads.htaccess) denies everything and
re-allows only what a media library holds. It:

1. denies everything (`Require all denied`);
2. re-allows only the types a media library holds: images (including `webp`,
   `avif` and, with care, `svg`), `pdf`, office documents, `mp3`/`mp4` and
   similar, `zip`;
3. then denies any file name containing a script extension anywhere
   (`shell.php.jpg` ends in `.jpg`, so step 2 alone would let it through);
4. removes PHP/CGI handlers and turns the PHP engine off where `mod_php` is in
   use; sets `Options -Indexes -ExecCGI`;
5. sends `nosniff`, and a locked-down `Content-Security-Policy` for SVG.

It is an allowlist rather than only "PHP off" so an unknown binary or script
type is also refused. Its limit is that Apache has to read it:

| How PHP is attached | Is `uploads/.htaccess` consulted? |
| --- | --- |
| `mod_php` | Yes. `php_flag engine off` also works. |
| php-fpm with `<FilesMatch \.php$> SetHandler "proxy:unix:...\|fcgi://localhost"` | Yes. The path is mapped to a file first, so the deny rules fire before FPM is called. |
| php-fpm with `ProxyPassMatch ^/(.*\.php)$ fcgi://...` | **No.** Apache proxies by URL and never reads the file; an uploaded `.php` runs. Only the layer 1 `<LocationMatch>` protects this setup. |
| Hosts where `AllowOverride` is `None` | No. The file is ignored. |

Find out which one you have (`grep -rn "ProxyPassMatch\|SetHandler" /etc/apache2 /etc/httpd 2>/dev/null`) and then prove it with the probe below instead of trusting this table. If you cannot touch the vhost (shared hosting), the `.htaccess` is what you have, and the probe is how you know it works.

**SVG is XML and can contain `<script>`.** If you allow it, only accept SVGs
from people you trust, and keep the CSP header from the template so a directly
opened SVG cannot run script. If you do not need SVG, delete it from the
allowlist.

Because uploads are never in git (see below), the file has to be installed by
provisioning or by a deploy step:

```bash
install -m 644 server/uploads.htaccess /home/siteuser/public_html/wp-content/uploads/.htaccess
```

The file stays in the repo under `server/` (see [`server/README.md`](../server/README.md));
never put it inside `uploads/` locally. Make sure your deploy's `rsync --delete`
does not remove the installed copy. See [03-deploy.md](03-deploy.md).

### Verify from outside

A rule you have not tested is a hope. After installing it, from your laptop,
not from the server:

```bash
# 1. Put a harmless probe in uploads (over SSH/SFTP, the same way an attacker with a leaked login would)
ssh siteuser@example.org 'printf "<?php echo \"EXECUTED\";" > public_html/wp-content/uploads/probe.php'

# 2. It must be refused. Expect HTTP/2 403 (not 200, and never the word EXECUTED)
curl -sI https://example.org/wp-content/uploads/probe.php | head -1
curl -s  https://example.org/wp-content/uploads/probe.php | grep -c EXECUTED   # expect 0

# 3. Double extension: allowed type, script inside. Expect 403 as well
ssh siteuser@example.org 'cp public_html/wp-content/uploads/probe.php public_html/wp-content/uploads/probe.php.jpg'
curl -sI https://example.org/wp-content/uploads/probe.php.jpg | head -1

# 4. Delete the probes
ssh siteuser@example.org 'rm -f public_html/wp-content/uploads/probe.php public_html/wp-content/uploads/probe.php.jpg'
```

Run it again after any change to the web server, the PHP setup, or a server
migration. It is also worth a line in [`tests/smoke.sh`](../tests/smoke.sh)
if you can create a probe file in a staging environment.

## 2. No code changes from the dashboard

Plugins, themes and core are installed by Composer and deployed from git. A
second path that changes code (the plugin editor, "Add New" plugin, a theme
update button) means the server drifts from the repo, the next deploy silently
reverts it, and an attacker with an admin login gets a code-execution feature
for free.

In the environment config (see [02-config-and-secrets.md](02-config-and-secrets.md)):

```php
define( 'DISALLOW_FILE_EDIT', true );  // no theme/plugin editor in wp-admin
define( 'DISALLOW_FILE_MODS', true );  // no installs, updates or deletes from wp-admin
```

`DISALLOW_FILE_MODS` also hides the updates screen, which is what you want when
Composer owns versions. Set it on staging and production; leave it off locally
if you like installing things to try them, since local changes never reach git
unless you commit them.

## 3. Auto-updates off, because Composer owns the versions

Core, plugin, theme and translation background updates are turned off:

```php
define( 'WP_AUTO_UPDATE_CORE', false );
add_filter( 'automatic_updater_disabled', '__return_true' ); // in an mu-plugin or the theme
```

Two reasons beyond "the repo is the source of truth":

- An update installed on the server by a cron job is overwritten by the next
  deploy, so you debug a problem that the code in git does not have.
- Every update fires `upgrader_process_complete`, and plugins hang work on it.
  Some of that work is destructive: a page-builder that wipes its generated CSS
  files, or a cache plugin that purges everything, then cached pages request
  stylesheets that are gone until the cache expires.

If your host updates core for you (a managed host), say so in the repo's README
so nobody is surprised, and still disable the dashboard paths above.

Turning auto-updates off moves the responsibility to you: put
`composer outdated` / a dependency bot on a schedule, and read security
advisories for the plugins you run.

## 4. Hide what identifies the install

`readme.html`, `license.txt` and `wp-config-sample.php` are in the WordPress
root and tell a scanner the exact version. Deny them in the root `.htaccess`:

```apache
<FilesMatch "^(readme\.html|license\.txt|wp-config-sample\.php|wp-config\.php\.bak)$">
  Require all denied
</FilesMatch>
```

This is hygiene, not security: it only stops lazy fingerprinting. Do not rely on
it, and do not add "security through obscurity" plugins that rename `wp-admin`.

Also deny the files that should never be served at all: backups, dumps, editor
leftovers and `.env`-style files.

```apache
<FilesMatch "(?i)(^\.|\.(sql|bak|old|orig|swp|log|ini|sh)$|~$)">
  Require all denied
</FilesMatch>
```

The rule matches file names, not folders, so files under `.well-known/` (which
certificate validation needs) stay reachable. Check a real one after adding it.

## 5. Hotlink protection (with a local-dev exception)

Other sites embedding your images burn your bandwidth. In the root `.htaccess`:

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  # Allow requests with no referer (direct visits, privacy tools, some feed readers)
  RewriteCond %{HTTP_REFERER} !^$
  # Allow the site itself
  RewriteCond %{HTTP_REFERER} !^https?://(www\.)?example\.org(/|$) [NC]
  # Local development: the same files are requested from the .test domain.
  # Without this line the local site shows broken images.
  RewriteCond %{HTTP_REFERER} !^https?://([a-z0-9-]+\.)?example\.test(/|$) [NC]
  # Search engines and social previews
  RewriteCond %{HTTP_REFERER} !(google|bing|duckduckgo|facebook|linkedin|twitter|t\.co)\. [NC]
  RewriteRule \.(jpe?g|png|gif|webp|avif)$ - [F,NC,L]
</IfModule>
```

The local exception is explicit on purpose: when you pull production uploads or
proxy them from the live site (see [04-local-environment.md](04-local-environment.md)),
the referer is your `.test` domain and would otherwise be blocked. Referer
checks are a deterrent, not a lock; anyone can forge the header. If you use a
CDN, do this there instead.

## 6. Security headers

Set them once in the root `.htaccess` (or in the server config):

```apache
<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Frame-Options "SAMEORIGIN"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
</IfModule>
```

- `X-XSS-Protection` is obsolete and was removed from modern browsers. Do not
  add it to new sites.
- **HSTS** (`Strict-Transport-Security`) is worth having, but only after every
  subdomain really serves HTTPS, because browsers will refuse HTTP for the whole
  max-age. Start with a short `max-age` (a day), then raise it.
- **Content-Security-Policy** on WordPress breaks easily (inline scripts from
  plugins, tag managers). Roll it out as `Content-Security-Policy-Report-Only`
  first and read the reports before enforcing.
- Confirm with `curl -sI https://example.org/` and a header scanner after each
  change.

## 7. Uploads never go in git

Uploads are user data, they are large, and committing them puts every private
file ever uploaded into history forever (history that you cannot clean once it
is pushed to a public remote). Ignore them:

```gitignore
/wp-content/uploads/
/src/wp-content/uploads/
```

Pull a copy when you need one locally (`rsync -avz --exclude 'cache/' siteuser@example.org:public_html/wp-content/uploads/ ./uploads/`)
or proxy missing files from the live site, as described in
[04-local-environment.md](04-local-environment.md). Back them up on the server
side, separately from the code.

## 8. After an incident: rotate everything the attacker could have read

Cleaning malware does not end an incident if the attacker still has a working
credential. Rotate, in this order, **after** the site is clean and the entry
point is closed (otherwise you rotate onto a machine they still control):

1. **Database password**, then update the environment config.
2. **WordPress salts and keys.** Replacing them logs every user out and kills
   all existing sessions, including the attacker's cookies.
3. **All WordPress user passwords**, starting with administrators. Delete
   admin accounts nobody recognises. Check for new users and for roles changed
   on existing ones.
4. **SFTP/SSH passwords and authorized keys.** Remove keys you cannot account
   for.
5. **Deploy credentials**: GitHub secrets, runner registration tokens, any
   deploy keys. See [03-deploy.md](03-deploy.md).
6. **Third-party API keys** stored in the environment or in plugin settings:
   SMTP, payment, CRM, analytics, object-storage.
7. **The hosting and DNS accounts**, if the attacker had shell access.

Write down what you rotated and when. The full audit procedure (what to look
for on disk, in the database, in cron and in the content) is in
[08-security-audit.md](08-security-audit.md).

## Checklist

- [ ] Vhost deny rule for scripts under uploads, plus `uploads/.htaccess` installed from `server/`; probe `.php` returns 403
- [ ] `DISALLOW_FILE_EDIT` and `DISALLOW_FILE_MODS` on staging and production
- [ ] Core, plugin and theme auto-updates disabled; updates reviewed on a schedule
- [ ] `readme.html`, `license.txt` and backup/dump files not served
- [ ] Hotlink rule has the local-dev exception
- [ ] Security headers present (`curl -sI`)
- [ ] Uploads in `.gitignore`; backups of uploads exist outside the web root
- [ ] You know the rotation list from section 8 before you need it
