# 04 - Local environment

Goal: a local copy of the site that runs the same code as production (from Git), with a copy of the production database, and without copying gigabytes of uploads.

Related: [project layout](01-project-layout.md), [config and secrets](02-config-and-secrets.md).

## Pick one

| | Option A: Valet | Option B: Docker |
|---|---|---|
| Runs on | macOS | Any OS |
| Needs on your machine | PHP, MySQL, Composer, WP-CLI | Docker (Composer is optional) |
| URL | https://mysite.test (HTTPS) | http://localhost:8080 (no HTTPS) |
| Speed | Fastest | Slower on macOS and Windows file sharing |

Both use the same `composer install` build, the same `.env` model and the same uploads mu-plugin. Do the option you chose, then the [common steps](#import-a-production-dump).

You also need SSH access to the server (or a way to export the database from your host) to work on an existing site, and, for private plugins, the `auth.json` described in [project layout](01-project-layout.md#private-vendor-repositories).

## Option A: Valet

[Laravel Valet](https://laravel.com/docs/valet) serves `wordpress/` at `https://mysite.test` with a local certificate.

### Requirements

```bash
brew install php@8.4 composer mysql wp-cli
composer global require laravel/valet
valet install
mysql.server start
```

### Quick start

From a fresh clone to a working site, with an empty database and the starter theme:

```bash
git clone git@github.com:your-org/your-site.git mysite && cd mysite
composer install                       # builds wordpress/ (core, plugins, theme, translations), installs the git hook
cp .env.example .env                   # fill in DB credentials and fresh salts; set WP_ENV=local
mysql -u root -e "CREATE DATABASE mysite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
cd wordpress
valet link mysite --secure             # https://mysite.test
valet isolate php@8.4 --site=mysite    # this site gets its own PHP version
wp core install --url=https://mysite.test --title="My Site" \
  --admin_user=dev --admin_email=dev@mysite.test --admin_password='<something local>'
wp theme activate mytheme
```

No credentials are needed: the starter requires only free packages. To work on an existing site instead of a blank one, skip `wp core install` and continue with [Import a production dump](#import-a-production-dump).

### How it is served

The docroot is `wordpress/`, not the repository root. The link name becomes the `.test` host (`valet links` lists them). `valet isolate` pins the site to a PHP version without changing the global one, so you can run an older site next to a newer one.

`wp` uses whatever `php` is first on your `PATH`, which may not be the version the site is isolated to. Tell WP-CLI which binary to use, and run it from inside `wordpress/` (or pass `--path=wordpress`):

```bash
export WP_CLI_PHP=/opt/homebrew/opt/php@8.4/bin/php   # put it in your shell profile
cd wordpress && wp core version
```

## Option B: Docker

[docker-compose.yml](../docker-compose.yml) runs MariaDB, Apache with PHP 8.4 and, on request, WP-CLI. The site is served from the same `wordpress/` folder that `composer install` builds, so code changes show up immediately.

### Requirements

Docker with the Compose plugin. Composer on the host is optional: `docker run --rm -v "$PWD":/app composer:2 install` does the same build.

### Quick start

```bash
git clone git@github.com:your-org/your-site.git mysite && cd mysite
composer install                  # or: docker run --rm -v "$PWD":/app composer:2 install
cp .env.example .env              # then set the Docker values below, fresh salts and WP_ENV=local
docker compose up -d              # site at http://localhost:8080
docker compose run --rm wpcli core install --url=http://localhost:8080 --title="My Site" \
  --admin_user=dev --admin_email=dev@mysite.test --admin_password='<something local>'
docker compose run --rm wpcli theme activate mytheme
```

`docker compose run --rm wpcli <wp arguments>` does the same as `wp <wp arguments>` on the host. The `wpcli` service sits behind the `tools` profile, so `docker compose up` does not start it.

### How it works

- **Fixed database credentials.** `docker-compose.yml` sets local-only values, so the `.env` must match them: `DB_HOST=db`, `DB_NAME=wordpress`, `DB_USER=wordpress`, `DB_PASSWORD=wordpress`. Never reuse them anywhere else.
- **The `.env` is mounted read-only** into the containers and found through `MYSITE_ENV_FILE` (see [config](02-config-and-secrets.md#where-it-looks-for-the-file)).
- **Rewrite rules** live in `docker/apache-wordpress.conf`, inside the container's Apache config, never in `wordpress/.htaccess`: the self-hosted deploy uploads the top-level files of `wordpress/` and would overwrite production's `.htaccess`.
- **Linux file permissions.** The files in `wordpress/` belong to your user, while Apache and WP-CLI run as `www-data` (uid 33), which then cannot write uploads. Locally only: `chmod -R a+rwX wordpress/wp-content/uploads`.
- **Compose warnings.** Compose also reads `.env` for its own variable substitution, which `docker-compose.yml` does not use. A `$` in a salt prints "variable is not set" warnings; they are harmless, and `export COMPOSE_DISABLE_ENV_FILE=1` silences them.
- **Stop** with `docker compose down`. Add `-v` to also delete the database volume (see [Clean up](#clean-up)).

## Import a production dump

Skip this for a blank site. Stream the dump from the server into the database; never save it to a file, and never inside `wordpress/`, which is served by the web server.

Valet:

```bash
ssh siteuser@example.org "wp --path=/home/siteuser/public_html db export - --single-transaction" | mysql -u root mysite
```

Docker:

```bash
ssh siteuser@example.org "wp --path=/home/siteuser/public_html db export - --single-transaction" \
  | docker compose run --rm -T wpcli db import -
```

`wp db export -` writes the SQL to standard output, SSH carries it, and the database client reads it. No dump file ever exists on your disk. If your SSH login is not the user that owns the site, run `wp` through `sudo -u siteuser`.

### Rewrite the URLs

The imported database still points to production. Replace the URL in every table, skipping the `guid` column (a post's GUID is its permanent identifier; changing it makes feed readers see every post as new). Do `https` and `http`, because old content often has both.

Valet (from `wordpress/`):

```bash
wp search-replace 'https://www.example.org' 'https://mysite.test' --all-tables --skip-columns=guid --dry-run
wp search-replace 'https://www.example.org' 'https://mysite.test' --all-tables --skip-columns=guid
wp search-replace 'http://www.example.org'  'https://mysite.test' --all-tables --skip-columns=guid
```

Docker:

```bash
docker compose run --rm wpcli search-replace 'https://www.example.org' 'http://localhost:8080' --all-tables --skip-columns=guid --dry-run
docker compose run --rm wpcli search-replace 'https://www.example.org' 'http://localhost:8080' --all-tables --skip-columns=guid
docker compose run --rm wpcli search-replace 'http://www.example.org'  'http://localhost:8080' --all-tables --skip-columns=guid
```

`wp search-replace` understands serialized data, so it fixes the string lengths inside options and page-builder content. Plain SQL `REPLACE()` does not.

Afterwards give yourself a login you control: `wp user create dev dev@mysite.test --role=administrator --user_pass='<something local>'` (Docker: prefix with `docker compose run --rm wpcli` and drop the leading `wp`).

## Uploads: do not copy them

The uploads folder of an active site is often several gigabytes. You do not need it to work on the site; you need the images to show up. The mu-plugin [src/wp-content/mu-plugins/local-uploads-from-production.php](../src/wp-content/mu-plugins/local-uploads-from-production.php) does that: when a request for a file under the uploads path is not on disk, it answers `302` to the same file on production.

It is already in the starter. Set the three constants at the top (local hosts, production origin, uploads path; WordPress' default path is `/wp-content/uploads/`). Details worth knowing:

- **Gated twice.** It runs only if `wp_get_environment_type()` is `local` ([config](02-config-and-secrets.md#environment-type)) **and** the request host is one of the local hosts (`mysite.test` for Valet, `localhost:8080` for Docker; the host includes the port). Production never defines `local`, so the file can be committed and deployed with everything else and does nothing there.
- **Why it runs.** Valet and the Docker Apache config both hand a request for a missing static file to WordPress' `index.php`, which loads must-use plugins first, so the redirect fires before WordPress does any real work.
- **`Cache-Control: public, max-age=86400`** on the redirect: the browser reuses it for a day instead of asking WordPress again for every image. If you later add a file locally, a hard refresh gets past the cached redirect.
- **Path checks.** It refuses paths containing `..`, and only ever redirects to the fixed production origin.

### Production must allow the local referer

If production blocks hotlinking, the redirected image request arrives with `Referer: https://mysite.test/` (Valet) or `http://localhost:8080/` (Docker) and gets a 403. Allow the host you use in the hotlink block of production's `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTP_REFERER} !^$
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?example\.org [NC]
RewriteCond %{HTTP_REFERER} !^https?://mysite\.test [NC]
RewriteCond %{HTTP_REFERER} !^https?://localhost:8080 [NC]
RewriteRule \.(jpe?g|png|gif|webp|svg)$ - [F,NC]
```

The last two conditions are the ones to add (only the one you use is needed). They list names that only resolve on a developer's own machine, so they do not open a real hole.

## Clean up

The database you imported is a copy of production, including users, emails, orders and form entries. Treat it as personal data:

- If you ever did save a dump to disk (for example from a host's export button), delete it as soon as it is imported. `*.sql` is in the [.gitignore](../.gitignore) so it cannot be committed by accident, but it can still sit in Downloads or a synced folder.
- Do not share the local database or put it in cloud-synced folders.
- If the site sends mail, make sure a local copy cannot email real users: leave mail credentials out of the local `.env`, or use a mail catcher.
- When you stop working on a snapshot, drop it. Valet: `mysql -u root -e "DROP DATABASE mysite"`. Docker: `docker compose down -v`.

## Variant: managed host

On a host that owns `wp-config.php` and does not give you a shell with `wp db export` (WP Engine and similar), the differences are:

1. **Database:** take the dump from the host's own tools: a backup point you download from the portal, or the database export in its phpMyAdmin. Import it, then delete the file.

   Valet:

   ```bash
   mysql -u root mysite < ~/Downloads/dump.sql && rm ~/Downloads/dump.sql
   ```

   Docker:

   ```bash
   docker compose run --rm -T wpcli db import - < ~/Downloads/dump.sql && rm ~/Downloads/dump.sql
   ```

2. **Config:** there is no `.env`. Create your own git-ignored `src/wp-config.php` (see [Model B](02-config-and-secrets.md#model-b-the-host-owns-wp-configphp)), then `composer build`. With Valet, `wp config create --dbname=mysite --dbuser=root` writes the database lines; with Docker use the fixed values above (`DB_HOST` `db`, and `wordpress` for name, user and password). Add `define( 'WP_ENVIRONMENT_TYPE', 'local' );` so the uploads redirect and other local-only code can recognize your machine.

3. Everything else is the same: the URL rewrite, the uploads mu-plugin and a hotlink rule on the host that allows your local referer.
