# 03 - Deploy

How code gets from Git to a server. Two variants: your own VPS with a self-hosted runner (A), and WP Engine (B). The principles are the same for both.

Files: [`.github/workflows/deploy-self-hosted.yml`](../.github/workflows/deploy-self-hosted.yml), [`.github/workflows/deploy-wpengine.yml`](../.github/workflows/deploy-wpengine.yml), [`.deployignore`](../.deployignore). They build the layout in [01-project-layout.md](01-project-layout.md): `composer install --no-dev` produces `wordpress/` (core and plugins, with `src/wp-content` and `src/wp-config.php` copied in). Config and secrets: [02-config-and-secrets.md](02-config-and-secrets.md).

## Principles

1. **Git is the only way code reaches a server.** No FTP, no editing files over SSH, no installing or updating plugins from wp-admin (`DISALLOW_FILE_MODS`). If it is not in Git, the next deploy removes it, and that is a feature: the server cannot drift from the repository.
2. **Build once, in CI.** `composer install --no-dev` runs in the pipeline, never on the server. What is tested is what is shipped.
3. **A dry run always runs, and its output is kept.** Every run does an rsync dry run first and writes the itemized list to the job summary. After a deploy you can read exactly what changed. A manual run can stop after the dry run.
4. **Deploy only the code directories.** Core, plugins, themes, mu-plugins, languages. Never `uploads/`, never caches, never host drop-ins (`object-cache.php`, `advanced-cache.php`), never `.htaccess` or host config. Each deployed directory is listed by name; nothing is "everything except".
5. **One deploy at a time, never cancelled halfway.** GitHub `concurrency` with `cancel-in-progress: false`: a newer run waits. A half-written plugins directory is worse than a slow queue.
6. **Rollback is a revert.** `git revert <commit>` and push; the pipeline deploys the previous code. There is no special rollback tool to break at 2 a.m.
7. **Database backups are separate.** The pipeline does not back up or restore the database. If a release changed the schema, restoring code is not enough: restore the database from your regular backups (see [05-hardening.md](05-hardening.md)).

### The DEPLOY_TARGET switch

Both workflows live in `.github/workflows/` but are **off by default**. Every job starts with an `if:` that needs the repository variable `DEPLOY_TARGET` (Settings, Secrets and variables, Actions, Variables):

| `DEPLOY_TARGET` | Effect |
| --- | --- |
| unset (or anything else) | Both workflows are skipped. No runner is needed and no secret is read. |
| `self-hosted` | Only `deploy-self-hosted.yml` runs (Variant A). |
| `wpengine` | Only `deploy-wpengine.yml` runs (Variant B). |

So you can clone this repository, work on it, and nothing deploys until you choose. If you use only one variant, you may delete the other file.

Both workflows trigger only on `push` to `main` and `workflow_dispatch`. They never use `pull_request` or `pull_request_target`, so a fork or a pull request cannot start a job, in particular not on a self-hosted runner.

---

## Variant A: your own VPS, self-hosted runner

No SSH from GitHub to the server, no deploy key on GitHub. The workflow runs on a GitHub Actions runner installed on the server itself and writes to the docroot locally.

### Users and rights

- The **runner** is its own user, `deployer`. It checks out the code, runs Composer and rsync.
- The **site** runs as `siteuser` (PHP-FPM or Apache user). A compromised site must not be able to tamper with the runner, its workspace or the build, so these are two different users.
- `deployer` may run exactly `rsync` and `wp` as `siteuser` through sudo:

  ```
  deployer ALL=(siteuser) NOPASSWD: /usr/bin/rsync, /usr/local/bin/wp
  ```

- Be honest about what that rule is. `sudo -u siteuser rsync -e <command> ...` runs any command as `siteuser`, so the rule is in effect a shell as `siteuser`. That is acceptable only because a deploy already means "whoever can run the workflow can put any PHP on the site". Do not pretend it is a narrow rule, and do not widen it.
- Keep `siteuser` out of the `sudo` and `docker` groups, so a compromised site cannot step up to root.

### What the workflow does

`deploy-self-hosted.yml`, in order:

1. Gate: only when `DEPLOY_TARGET` is `self-hosted`, only on `main`, and only when the repository variable `DEPLOY_ENABLED` is `true` (a manual dry run is always allowed, and `dry_run` defaults to true).
2. Checkout with `persist-credentials: false`.
3. `composer install --no-dev`.
4. Check the build: key files exist, every PHP file under `src/` passes `php -l`.
5. Dry run: `rsync -n --itemize-changes`, output into the job summary.
6. Deploy: the same rsync function without `-n`. Skipped on a manual dry run.
7. Post-deploy as `siteuser`: `wp core update-db`, `wp cache flush`, page-cache clear.

The dry run and the deploy call the same shell function, so what the summary shows is what the deploy does.

### What gets written

- **Mirrored, with `--delete-after`:** `wp-admin/`, `wp-includes/`, `wp-content/plugins/`, `wp-content/themes/`, `wp-content/mu-plugins/`, `wp-content/languages/`. Anything in them that is not in the build is removed. `--delay-updates` keeps each directory consistent while it is replaced.
- **Root files, added or updated, never deleted:** `index.php`, `wp-*.php`, `xmlrpc.php`, `license.txt`. Skip `readme.html`: it advertises the WordPress version. `wp-config.php` is sent separately with mode 640 so it is not world-readable.
- **Never given to rsync:** `wp-content/uploads/`, caches, drop-ins, `.htaccess`, `.user.ini`, `.well-known/`, security plugin files.

### Security of the setup

- **Never register a self-hosted runner on a public repository, and use this variant only on a private one.** Anyone who can open a pull request or push a branch can make the runner execute their code, and through the sudo rule that is a shell as `siteuser`. Keep the repository private.
- Restrict the `production` environment to `main`. Note this only guards the jobs that use that environment: another workflow that targets the same runner label is not covered, so the people who can push branches are, in practice, people who have a shell on the server. Keep collaborators few, 2FA on, `main` protected against force pushes and deletion.
- `permissions: contents: read` on the workflow, and no secrets in it. The runner needs none.

### One-time setup checklist

Do it by hand, once. The order matters; step 8 turns deploys on.

1. Install PHP, Composer (system-wide, from the official installer with hash verification), WP-CLI and rsync, so `deployer` can run them.
2. Put the site's secrets where `wp-config.php` reads them (see [02-config-and-secrets.md](02-config-and-secrets.md)). Read the live `wp-config.php` first if you took the site over.
3. Move any `.git` directory out of the docroot.
4. Create the runner user, no password: `useradd --system --create-home --shell /bin/bash deployer && passwd -l deployer`.
5. Add the sudoers rule above in `/etc/sudoers.d/deployer` (mode 440) and check it with `visudo -cf`.
6. Install the runner as `deployer`, registered to this one repository, as a service, with a label such as `site-prod`. Give it a work directory outside its home, for example `/srv/site-build`, owned by `deployer` with mode 711: `siteuser` can pass through to read the build but cannot list it. Keep the runner's home at 700.
7. On GitHub: create the environment `production` restricted to `main`; protect `main`.
8. Set the repository variable `DEPLOY_TARGET` to `self-hosted`. Then run the workflow by hand on `main` with `dry_run` ticked and read the summary. The first one is long (permissions and timestamps everywhere, stale plugins to delete). When it looks right, set the repository variable `DEPLOY_ENABLED` to `true`. **This is the last step.** Until then a push cannot deploy before the server is ready. To stop all deploys later, set it to anything else: it is the kill switch.

---

## Variant B: WP Engine

WP Engine owns WordPress core, `wp-config.php` and the server configuration. We deploy only `wp-content`: plugins, themes, mu-plugins. The workflow is active when `DEPLOY_TARGET` is `wpengine`. It runs on a GitHub-hosted runner and rsyncs over the [SSH Gateway](https://wpengine.com/support/ssh-gateway/).

### Branch to environment

| Trigger | Environment |
| --- | --- |
| push to `main` | staging, automatically |
| Run workflow, `production` | production, manually, only from `main` (enforced by the environment, see below) |
| push to `dev/**` (optional) | development |

Production is never automatic. Someone presses the button, after looking at staging.

### Environments, secrets and variables

The deploy credentials live in **GitHub Environments**, not at repository level. Create the environments `staging` and `production` (Settings, Environments), and put the following in each one:

| Name | Kind | Purpose |
| --- | --- | --- |
| `WPE_SSHG_KEY_PRIVATE` | environment secret | Private ed25519 key for the SSH Gateway. A different key per environment. |
| `WPE_INSTALL` | environment variable | The install name from the User Portal. The workflow checks it is lowercase letters and digits before using it in a host name. |
| `WPE_SSH_KNOWN_HOSTS` | environment variable | The pinned host key line for `INSTALL.ssh.wpengine.net`. The deploy fails without it. |

Optional, repository or environment level: `COMPOSER_AUTH_JSON` (secret, Composer auth JSON for private packages; use read-only tokens, it is passed to Composer as `COMPOSER_AUTH` only in the install step) and `SLACK_WEBHOOK_URL` (secret, chat notification; no secret, no message). The repository variable `DEPLOY_TARGET` is `wpengine`.

**Why environments, and what you must configure.** A `workflow_dispatch` from any branch runs that branch's copy of the workflow, so a rule written inside the file ("production only from main") can be edited away by anyone who can push a branch. A repository-level secret is also readable by a workflow on any branch. Environment secrets are released only to a job that targets the environment, and the environment's deployment-branch rule is enforced by GitHub outside the file. So:

1. In the `production` environment, set **Deployment branches and tags** to `main` only. This is the control. Without it, production credentials are reachable from any branch.
2. Optionally add **required reviewers** to `production`, so every release needs a second approval.
3. Do not also set `WPE_SSHG_KEY_PRIVATE` at repository level; that would defeat the point.
4. Protect `main` as well: whoever can merge to `main` can change the workflow that production runs.

The workflow keeps a main-only check for production as defense in depth. It is not the control.

**Keys.** Add each public key under your WP Engine profile (SSH Keys). WP Engine keys belong to a user and work on every install that user can reach, so a separate staging key only isolates anything if it belongs to a separate WP Engine user with access to the staging installs alone. Do that if you can.

**Pinning the host key, once.** The deploy never runs `ssh-keyscan`; it trusts only the line you store. From a machine you trust, per install:

```bash
ssh-keyscan -t ed25519 myinstall.ssh.wpengine.net > myinstall.known_hosts
ssh-keygen -lf myinstall.known_hosts          # print the fingerprint
```

Compare that fingerprint with the one WP Engine publishes or gives you through support (their SSH Gateway article does not list it), and only then paste the line into `WPE_SSH_KNOWN_HOSTS` of the matching environment. The line must start with the plain host name (not a hashed entry). If WP Engine rotates the key, the deploy fails closed until you update the variable.

### What the workflow does

`deploy-wpengine.yml`:

1. Gate on `DEPLOY_TARGET` (every job, including the tag job). The job runs in the GitHub environment of the target, which is what releases that environment's secrets. Pick the target from the trigger, refuse production outside `main` (defense in depth), validate the install name.
2. Checkout, set up PHP, `composer install --no-dev` with the auth secret.
3. Check the build: the three directories under `wordpress/wp-content/` exist, `.deployignore` (repo root) exists, our PHP under `src/` lints.
4. Write the key to a temporary file, `chmod 600`, and write the pinned `WPE_SSH_KNOWN_HOSTS` line to `known_hosts`. Missing key, missing host line or a host line for another install fails the run with a clear error. ssh runs with `StrictHostKeyChecking=yes`.
5. Dry run: `rsync --dry-run --itemize-changes` for each directory, into the job summary. Always runs. The `dry_run` input defaults to true on manual runs.
6. Deploy: the same function without `--dry-run`.
7. Purge caches over SSH with WP-CLI (page cache and CDN). A failed purge warns and does not fail the deploy.
8. Remove the key, optionally notify chat.
9. On a real production deploy, a separate job (the only one with `contents: write`) tags the commit `production-YYYYMMDD-HHMMSS`, so "what is live" is a tag, and a revert has a point to compare to.

### Why plain rsync and not the official action

WP Engine publishes `wpengine/github-action-wpe-site-deploy`. It deploys one source to one destination per call and has no dry-run or itemized-changes mode, and we want several directories, additive ones, a dry run in the summary and a tag. Plain rsync over SSH is short, easy to read, and leaves no third-party deploy code between your repository and production. If you only need to push one theme, the official action is a fine choice: pin it by commit SHA.

### Mirrored and additive paths

- **Mirrored** (`plugins`, `themes`): rsync runs with `--delete-after`. What is not in Git is removed.
- **Additive** (`mu-plugins`): no `--delete`. WP Engine puts its own files there, and deleting them breaks platform features. We only add and update ours. Put any other host-managed directory in `ADDITIVE_PATHS`.

`.deployignore` is passed as `--exclude-from`. Excluded paths are neither sent nor deleted, which is what protects `uploads/` and friends. Never add `--delete-excluded`.

### Things to know

- Uploads, the database and core updates are managed in the WP Engine portal, not by this pipeline. Take a backup point in the portal before a production release that changes the database.
- Rollback is the same as everywhere: revert the commit, run the production workflow again.
- Run the first production deploy as a dry run and read the list. The first one deletes whatever was installed by hand.
