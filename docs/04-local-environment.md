# 04 - Local environment

Goal: a local copy of the site that runs the same code as production (from Git), with a copy of the production database, and without copying gigabytes of uploads. macOS with [Laravel Valet](https://laravel.com/docs/valet) is the setup described here; the ideas carry to any local stack.

Related: [project layout](01-project-layout.md), [config and secrets](02-config-and-secrets.md).

## Requirements

```bash
brew install php@8.4 composer mysql wp-cli
composer global require laravel/valet
valet install
mysql.server start
```

You also need SSH access to the server (or a way to export the database from your host) and, for private plugins, the `auth.json` described in [project layout](01-project-layout.md#private-vendor-repositories).

## Quick start

From a fresh clone to a working site, with an empty database and the starter theme:

```bash
git clone git@github.com:your-org/your-site.git mysite && cd mysite
composer install                       # builds wordpress/ (core, plugins, theme, translations)
cp .env.example .env                   # fill in DB credentials and fresh salts; set WP_ENV=local
mysql -u root -e "CREATE DATABASE mysite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
cd wordpress
valet link mysite --secure            # https://mysite.test
wp core install --url=https://mysite.test --title="My Site" \
  --admin_user=dev --admin_email=dev@mysite.test --admin_password='<something local>'
wp theme activate mytheme
```

No credentials are needed: the starter requires only free packages. To work on an existing site instead of a blank one, skip `wp core install` and follow [Database](#4-database) below to import a production dump. The rest of this document explains each step.

## 1. Build the site

```bash
git clone git@github.com:your-org/your-site.git mysite && cd mysite
composer install        # core, plugins, themes, translations, then `composer build`; also installs the git hook
cp .env.example .env    # fill in database credentials and keys; set WP_ENV=local
```

If the site sends mail, make sure a local copy cannot email real users: a production dump contains real addresses. Leave mail credentials out of the local `.env`, or use a mail catcher.

## 2. Serve it with Valet

The docroot is `wordpress/`, not the repository root. Link it under the name you want:

```bash
cd wordpress
valet link mysite --secure           # https://mysite.test, local certificate
valet isolate php@8.4 --site=mysite  # this site gets its own PHP version
```

The link name becomes the `.test` host (`valet links` lists them). `valet isolate` pins the site to a PHP version without changing the global one, so you can run an older site next to a newer one. Use the same host in the `search-replace` and mu-plugin steps below.

## 3. WP-CLI with the right PHP

`wp` uses whatever `php` is first on your `PATH`, which may not be the version the site is isolated to. Tell WP-CLI which binary to use:

```bash
export WP_CLI_PHP=/opt/homebrew/opt/php@8.4/bin/php   # put it in your shell profile
cd wordpress && wp core version
```

Run `wp` from inside `wordpress/`, or pass `--path=wordpress`.

## 4. Database

Create an empty database with the name and credentials from your `.env`:

```bash
mysql -u root -e "CREATE DATABASE mysite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

### Import a production dump

Stream it. Do not save it to a file:

```bash
ssh siteuser@example.org "wp --path=/home/siteuser/public_html db export - --single-transaction" | mysql -u root mysite
```

`wp db export -` writes the SQL to standard output, SSH carries it, `mysql` reads it. No dump file ever exists on your disk. If your SSH login is not the user that owns the site, run `wp` through `sudo -u siteuser`.

### Rewrite the URLs

The imported database still points to production. Replace the URL in every table, skipping the `guid` column (a post's GUID is its permanent identifier; changing it makes feed readers see every post as new). Do `https` and `http`, because old content often has both:

```bash
cd wordpress
wp search-replace 'https://www.example.org' 'https://mysite.test' --all-tables --skip-columns=guid --dry-run
wp search-replace 'https://www.example.org' 'https://mysite.test' --all-tables --skip-columns=guid
wp search-replace 'http://www.example.org'  'https://mysite.test' --all-tables --skip-columns=guid
```

`wp search-replace` understands serialized data, so it fixes the string lengths inside options and page-builder content. Plain SQL `REPLACE()` does not.

Afterwards give yourself a login you control:

```bash
wp user create dev dev@mysite.test --role=administrator --user_pass='<something local>'
```

## Uploads: do not copy them

The uploads folder of an active site is often several gigabytes. You do not need it to work on the site; you need the images to show up. The mu-plugin [src/wp-content/mu-plugins/local-uploads-from-production.php](../src/wp-content/mu-plugins/local-uploads-from-production.php) does that: when a request for a file under the uploads path is not on disk, it answers `302` to the same file on production.

It is already in the starter. Set the three constants at the top (local host, production origin, uploads path; WordPress' default path is `/wp-content/uploads/`). Details worth knowing:

- **Gated twice.** It runs only if `wp_get_environment_type()` is `local` ([config](02-config-and-secrets.md#environment-type)) **and** the request host is the local one. Production never defines `local`, so the file can be committed and deployed with everything else and does nothing there.
- **Why Valet runs it.** For a missing static file Valet falls through to WordPress' `index.php`, which loads must-use plugins first, so the redirect fires before WordPress does any real work.
- **`Cache-Control: public, max-age=86400`** on the redirect: the browser reuses it for a day instead of asking WordPress again for every image. If you later add a file locally, a hard refresh gets past the cached redirect.
- **Path checks.** It refuses paths containing `..`, and only ever redirects to the fixed production origin.

### Production must allow the local referer

If production blocks hotlinking, the redirected image request arrives with `Referer: https://mysite.test/` and gets a 403. Allow it in the hotlink block of production's `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTP_REFERER} !^$
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?example\.org [NC]
RewriteCond %{HTTP_REFERER} !^https?://mysite\.test [NC]
RewriteRule \.(jpe?g|png|gif|webp|svg)$ - [F,NC]
```

The third line is the one to add. Because it lists only a `.test` name that nobody outside developers' laptops can serve, it does not open a real hole.

## Clean up

The database you imported is a copy of production, including users, emails, orders and form entries. Treat it as personal data:

- If you ever did save a dump to disk (for example from a host's export button), delete it as soon as it is imported. `*.sql` is in the [.gitignore](../.gitignore) so it cannot be committed by accident, but it can still sit in Downloads or a synced folder.
- Do not share the local database or put it in cloud-synced folders.
- When you stop working on a snapshot, drop it: `mysql -u root -e "DROP DATABASE mysite"`.

## Variant: managed host

On a host that owns `wp-config.php` and does not give you a shell with `wp db export` (WP Engine and similar), the differences are:

1. **Database:** take the dump from the host's own tools: a backup point you download from the portal, or the database export in its phpMyAdmin. Import it, then delete the file:

   ```bash
   mysql -u root mysite < ~/Downloads/dump.sql && rm ~/Downloads/dump.sql
   ```

2. **Config:** there is no `.env`. Create your own git-ignored `src/wp-config.php` (see [Model B](02-config-and-secrets.md#model-b-the-host-owns-wp-configphp)), then `composer build`:

   ```bash
   wp config create --dbname=mysite --dbuser=root
   ```

   and add `define( 'WP_ENVIRONMENT_TYPE', 'local' );` to it so the uploads redirect and other local-only code can recognize your machine.

3. Everything else is the same: `valet link`, `wp search-replace`, the uploads mu-plugin and a hotlink rule on the host that allows your `.test` referer.
