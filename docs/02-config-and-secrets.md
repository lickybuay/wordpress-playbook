# 02 - Config and secrets

`wp-config.php` holds the database password and the keys that sign every login cookie. It is also the file attackers like to modify, because it runs on every request. Two rules follow: secrets stay out of Git, and the file that runs on every request should be small, reviewed and, where possible, committed.

There are two valid models. Pick by who owns `wp-config.php`.

| | A. You own the server | B. A managed host owns the config |
|---|---|---|
| `wp-config.php` | Committed, no secrets | Managed by the host; local copy is git-ignored |
| Secrets | `.env` file outside the docroot | Defined per environment on the host |
| Fits | VPS, dedicated server, any host where you control files | WP Engine and similar |
| File | [src/wp-config.php](../src/wp-config.php) | n/a |

## Model A: committed `wp-config.php` and a `.env` outside the docroot

The committed file contains logic but no secrets. It reads a plain `KEY=value` file, validates it, defines the constants WordPress needs and gets out of the way. Files: [src/wp-config.php](../src/wp-config.php) (copied to `wordpress/wp-config.php` by `composer build`) and [.env.example](../.env.example).

Why committed: the config goes through review and deploys with the code, so the server never holds a hand-edited copy that nobody remembers. Why a separate `.env`: the only things that differ per environment are the values, and those can be rotated without a deploy.

### Where it looks for the file

The first readable file wins:

1. The path in the `MYSITE_ENV_FILE` environment variable. Explicit override for tooling and tests.
2. A fixed path **outside the docroot** on the server, for example `/home/siteuser/.env`, `chmod 600`, owned by the site user.
3. `.env` in the repository root, for local work. This one is used **only if the parent of `wordpress/` has a `composer.json`**, i.e. only inside the real repository. On a server the parent of the docroot may be served by a virtual host; a stray `.env` there must never be picked up.

### Required keys and failure mode

`DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST` and the eight keys and salts. If the file or any key is missing, the request ends with **HTTP 503** and a plain-text message that names the missing **keys**, never their values. A 503 with `Retry-After` is the honest answer: the site is not configured, search engines should come back later, and an operator reading the page knows what to fix. WordPress is not loaded yet at this point, so `wp_die()` is not available; the file stops with `http_response_code()` and `exit`.

### What the file does after loading

- **Unsets the parsed array** (and the helper variables) before WordPress loads, so a plugin cannot read a password out of the global scope. This is hygiene, not a boundary: `DB_PASSWORD` and the other constants stay defined, and any code that runs in the request can read constants. The real protections are the file permissions and keeping untrusted code out of the site.
- **Parses its own file format.** It does not call `putenv()` or fill `$_ENV`, so nothing leaks to child processes or `phpinfo()`. The format splits on the first `=` and does not treat `#` as a comment inside a value, because salts legitimately contain `#`, `'`, `"`, `$` and `=`.

### Environment type

```php
$mysite_is_local = isset( $mysite_env['WP_ENV'] ) && 'local' === $mysite_env['WP_ENV'];
if ( $mysite_is_local ) {
    define( 'WP_ENVIRONMENT_TYPE', 'local' );
}
```

Only a local copy declares itself. **Production keeps WordPress' default, `production`**, so forgetting a variable on the server fails safe. Code that must run only locally (like the [uploads redirect](04-local-environment.md#uploads-do-not-copy-them)) checks `wp_get_environment_type()` and does nothing otherwise, which makes it safe to deploy with the rest.

Locally it also turns on `WP_DEBUG` and `WP_DEBUG_LOG` and keeps `WP_DEBUG_DISPLAY` off: errors go to `wp-content/debug.log`, not into the HTML, so layout and AJAX responses stay intact.

### Outgoing mail (optional)

Outgoing mail is your call, and the starter ships no SMTP plugin. If you add one, keep its credentials in the `.env` or in server-defined constants rather than in the database (most SMTP plugins accept constants), so they are not editable from the dashboard and not copied around in database dumps.

Whatever you use, mail must leave through a server that the domain's SPF record authorizes and, ideally, that signs with DKIM. A web server calling PHP's `mail()` with `From: info@example.org` is almost never in that record, so Gmail, Microsoft and others bounce it or send it to spam, and a strict DMARC policy rejects it outright. After setting it up, send a test mail and check that SPF, DKIM and DMARC say `pass` in the headers.

### Hardening constants

```php
define( 'DISALLOW_FILE_EDIT', true );        // no theme/plugin editor in the dashboard
define( 'DISALLOW_FILE_MODS', true );        // no plugin/theme/core installs or updates from the dashboard
define( 'AUTOMATIC_UPDATER_DISABLED', true ); // no background updates: Composer owns versions
```

`DISALLOW_FILE_MODS` already blocks editing and installs, but `DISALLOW_FILE_EDIT` is kept as a second, explicit line. The consequence of all three is the rule from [project layout](01-project-layout.md): Git is the only way code changes. More in [hardening](05-hardening.md).

## Model B: the host owns `wp-config.php`

Managed hosts (WP Engine and similar) generate and sometimes rewrite `wp-config.php` and handle the database credentials themselves. Fighting that gets you deploys that overwrite the host's file or are overwritten by it. Instead:

- **Locally**, each developer has their own `wp-config.php`, **git-ignored** (in a Composer project, `src/wp-config.php`, which `composer build` copies into `wordpress/`). Create it with `wp config create --dbname=mysite --dbuser=root`, add the local constants (`WP_ENVIRONMENT_TYPE` = `'local'`, debug flags, `DISALLOW_FILE_MODS`) and nothing from production.
- **Per environment**, constants that the site needs (API keys, mail credentials, feature switches) are defined **on each server** with the means the host provides: its environment-variable or config panel, or a small file that exists only on that server and is not in the repository. Check your host's documentation for the supported way; it changes.
- **Features stay inert until their constants exist.** Code that needs `MYSITE_SOME_API_KEY` starts with `if ( ! defined( 'MYSITE_SOME_API_KEY' ) ) { return; }`. A new environment, or a developer's laptop, then runs fine without it, and a missing secret turns a feature off instead of producing a fatal error.

Do not commit the host's `wp-config.php` or any file containing real credentials, even to a private repository: repositories get shared, forked and cloned onto laptops.

## Checklist

- `.env` and `auth.json` are in `.gitignore` ([.gitignore](../.gitignore)); only `.env.example` is committed.
- Keys and salts are generated per environment, never copied between production and local.
- The `.env` on the server is outside the docroot, `chmod 600`, owned by the site user.
- A broken or missing `.env` gives a 503 that names keys, not a white page and not a leaked value.
- Production does not define `WP_ENVIRONMENT_TYPE`.
