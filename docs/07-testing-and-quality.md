# 07. Testing and quality

A typical WordPress site is glue: hooks, templates, a few custom plugins and a
lot of third-party code. A full PHPUnit setup (test suite scaffolding, a second
database, fixtures) costs more than it returns for that kind of codebase. We use
a layered approach instead, cheapest first:

1. a **pre-commit hook** that fixes style before it is committed;
2. a **PR lint job** that catches syntax errors and style on changed files only;
3. an optional **AI review** on PRs, split into focused lanes;
4. **tests without PHPUnit**: `wp eval-file` for logic, `curl` for what the
   visitor sees;
5. **migrations** written as safe, repeatable commands;
6. and always: **look at it in a real browser**.

Everything is advisory unless stated. Checks that block people get bypassed;
checks that inform get read.

## 1. Pre-commit: phpcbf on staged files

Style arguments in code review are wasted time. A hook runs `phpcbf` (the
auto-fixer of PHP_CodeSniffer, with the WordPress standard) on the PHP files in
the commit and stages the result.

The hook is [`.githooks/pre-commit.sh`](../.githooks/pre-commit.sh).

What it does and why:

- Only **first-party** PHP files: staged `.php` under `src/`. Never `wordpress/`
  (core and plugins installed by Composer), `vendor/` or `node_modules/`: those
  are not ours to reformat, and touching them makes huge, meaningless diffs.
- Runs `phpcbf` and re-adds the files, so the commit contains the fixed version.
- **`phpcbf` exit code 1 means "I fixed things", which is success.** A hook that
  treats it as failure blocks every commit that needed formatting, which is
  exactly when you want it to pass. The template accepts 0 and 1, warns on 2
  (some violations cannot be auto-fixed), and fails only on a real tool error.
- Skips files that also have **unstaged edits**: `git add` would stage the
  unstaged part too and put half-finished work in the commit.
- If `vendor/bin/phpcbf` is missing (fresh clone before `composer install`) it
  skips instead of breaking commits.

### Installing the hook for everyone: composer-git-hooks

Git does not version `.git/hooks`. [composer-git-hooks](https://github.com/BrainMaestro/composer-git-hooks)
installs hooks from `composer.json`, so a `composer install` wires the hook for
every developer:

```json
{
  "require-dev": {
    "brainmaestro/composer-git-hooks": "^3.0",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
    "wp-coding-standards/wpcs": "^3.0"
  },
  "extra": {
    "hooks": {
      "config": { "stop-on-failure": ["pre-commit"] },
      "pre-commit": "bash .githooks/pre-commit.sh"
    }
  },
  "scripts": {
    "install-hooks": [
      "[ ! -x vendor/bin/cghooks ] || [ ! -d .git ] || vendor/bin/cghooks add --ignore-lock"
    ],
    "post-install-cmd": ["@build", "@install-hooks"],
    "post-update-cmd":  ["@build", "@install-hooks"]
  },
  "config": {
    "allow-plugins": { "dealerdirect/phpcodesniffer-composer-installer": true }
  }
}
```

This is the shape already in this repo's `composer.json`; `wpcs` brings
PHP_CodeSniffer (and so `vendor/bin/phpcbf`) with it.

The `[ ! -x ... ] || [ ! -d .git ] ||` guard keeps `composer install --no-dev`
(no `cghooks`) and deploy checkouts without a `.git` folder from failing.
`allow-plugins` is required because Composer 2 refuses to run plugins you have
not listed.

A client-side hook can always be skipped (`git commit --no-verify`). That is why
the same checks run again in CI (next section): the hook is for convenience, CI
is the net.

A ruleset file keeps editor, hook and CI in agreement. Add a `phpcs.xml.dist`
at the repo root. The hook uses it when it exists (and falls back to the plain
`WordPress` standard when it does not); point the CI job at it the same way:

```xml
<?xml version="1.0"?>
<ruleset name="MySite">
  <rule ref="WordPress"/>
  <exclude-pattern>/wordpress/*</exclude-pattern>
  <exclude-pattern>/vendor/*</exclude-pattern>
  <exclude-pattern>/node_modules/*</exclude-pattern>
  <rule ref="WordPress.NamingConventions.PrefixAllGlobals">
    <properties><property name="prefixes" type="array"><element value="mysite"/></property></properties>
  </rule>
</ruleset>
```

## 2. PR lint job: changed first-party files only, advisory

Linting the whole repo on every PR buries a two-line change under thousands of
legacy findings. Lint **only the files the PR changed**, and only first-party
ones (same exclusion list as the hook; keep it in one place so they never
disagree).

Two checks with different severity:

| Check | What it finds | Blocking? |
| --- | --- | --- |
| `php -l` | Syntax errors: a guaranteed fatal in production | Can be required once the team trusts it. It has no false positives. |
| `phpcs --standard=WordPress` | Style and some WordPress pitfalls | **Advisory.** Never turns the PR red. |

How the advisory part reports, so people read it instead of resenting it:

- findings go out as **`::notice` annotations** on the changed lines (not
  `::error`), which show in "Files changed" without failing the check;
- a **step summary** lists the report and ends with the exact command to fix the
  mechanical part:
  `./vendor/bin/phpcbf --standard=WordPress $(git diff --name-only origin/main...HEAD -- '*.php')`
- the step itself ends with `exit 0`, and the job has `continue-on-error: true`.

Getting the changed file list:

```bash
git diff --name-only --diff-filter=ACM "origin/${BASE_REF}...HEAD" -- '*.php' \
  | grep -Ev '^(wordpress|vendor|node_modules)/' > /tmp/changed_php.txt
```

Install PHPCS in a scratch directory on the runner
(`composer require --dev squizlabs/php_codesniffer wp-coding-standards/wpcs` in a
temp folder) rather than doing a full `composer install` of the project. The full
install pulls WordPress and every plugin, may need private credentials, and the
linter needs none of that.

Run lint on GitHub-hosted runners; see [03-deploy.md](03-deploy.md) for how
self-hosted runners are used (deploy only). The skeleton of the job, to add under
`.github/workflows/lint.yml` in your project:

```yaml
name: PHP Lint
on:
  pull_request:
    types: [opened, synchronize, reopened, ready_for_review]
permissions:
  contents: read
jobs:
  php-lint:
    runs-on: ubuntu-latest
    if: github.event.pull_request.draft == false
    continue-on-error: true            # advisory: remove once the team trusts it
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1  # v7.0.1
        with:
          fetch-depth: 0
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962  # 2.40.0
        with:
          php-version: '8.4'
          tools: none
          coverage: none
      - name: Changed first-party PHP files
        run: |
          git diff --name-only --diff-filter=ACM "origin/${{ github.base_ref }}...HEAD" -- 'src/*.php' > "$RUNNER_TEMP/changed.txt"
          echo "$(wc -l < "$RUNNER_TEMP/changed.txt") file(s)"
      - name: php -l
        run: |
          status=0
          while IFS= read -r f; do php -l "$f" || status=1; done < "$RUNNER_TEMP/changed.txt"
          exit $status
      - name: PHPCS (advisory)
        run: |
          [ -s "$RUNNER_TEMP/changed.txt" ] || exit 0
          composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
          composer global require --quiet squizlabs/php_codesniffer wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer
          "$(composer global config bin-dir --absolute)/phpcs" --standard=WordPress --report=full $(cat "$RUNNER_TEMP/changed.txt") || true
```

Third-party actions are pinned by full commit SHA with the version in a comment,
as in the deploy workflows of this repo. To pin another one, resolve the tag with
`gh api repos/OWNER/REPO/git/ref/tags/vX.Y.Z`; if `object.type` is `tag` (an
annotated tag), dereference it with `gh api repos/OWNER/REPO/git/tags/<sha>` and
use the commit SHA it returns.

## 3. Optional: AI review on PRs, in focused lanes

A single "review this PR" prompt gives vague, repetitive answers. What works
better is several **narrow reviewers (lanes)**, each with one job and a prompt
that tells it what to ignore because another lane owns it.

Lanes we use for WordPress, chosen by **what the diff touches**:

| Lane | Looks for | Runs when |
| --- | --- | --- |
| Hook safety | Fatals, hooks registered too late or on the wrong action, filters that do not `return`, wrong priority | Any first-party PHP change (a missing `return` has no greppable keyword, so no content gate) |
| Security | Unauthenticated AJAX/REST routes, unsanitized superglobals, raw `$wpdb` queries, `unserialize`/`eval` | PHP plus a security signal in the diff |
| Performance and cache | Queries in loops, unbounded `WP_Query`, missing cache, render-blocking enqueues, remote calls on page load | PHP/JS/CSS plus a performance signal |
| Forms and integrations | Form hooks, CRM/analytics tags, captcha wiring | A forms signal in the diff |
| Dependency safety | `composer.json`/`composer.lock`, `.htaccess`, workflow files | Those files changed |

The reason for selecting by diff is cost and noise: four lanes reviewing the same
untouched file produce four overlapping comments.

Design decisions that make it trustworthy:

- **Output contract.** *Critical* findings become inline review comments on the
  exact line. Everything else (style, suggestions) is merged across lanes into
  **one sticky digest comment**, updated in place using a hidden HTML marker, and
  deleted when nothing is left. A PR never gets thirty bot comments.
- **Scripts come from the base commit.** The runner that holds the model API key
  is extracted with `git archive "$BASE_SHA" <scripts dir>`, not read from the
  PR branch. Otherwise a PR could rewrite its own reviewer to approve itself or
  print the secret. The data-shaping scripts that touch no secret may run from
  the PR head.
- **Read-only agents.** After a lane runs, if `git status --porcelain` shows the
  working tree changed, the output is discarded.
- **Per-run temp directories.** Self-hosted runners reuse disks, and `/tmp` is
  not cleared between jobs. A shared path let one PR's findings show up on
  another PR's digest. Use `/tmp/review-${run_id}-${run_attempt}-${job}`, wipe it
  at the start of the job and remove it at the end (`if: always()`).
- **Skip, never truncate.** If a lane's diff is above a size cap (for example
  250 KB), skip the lane and say so. A truncated diff produces a confident
  review of code the model never saw. Vendored code is the usual cause, so keep
  an explicit list of paths that are not yours.
- **Advisory.** `fail-fast: false`, `continue-on-error: true`, no merge gate.
  Add a gate later only if the signal has proven itself.
- **Bootstrap case.** The first PR that adds the pipeline has no scripts on the
  base commit yet; detect it and skip with a warning rather than failing.

Never run this on a self-hosted runner attached to a public repository (see
[03-deploy.md](03-deploy.md)): anyone can open a PR that executes on your
machine. Public repos use GitHub-hosted runners only.

Treat model output as untrusted data: it is posted as text, never executed, and
never fed back into a shell.

## 4. Tests without PHPUnit

### `wp eval-file` for logic

Run test files inside a fully booted local WordPress with WP-CLI. No separate
bootstrap, no test database: the code is exercised with the real plugins and
theme loaded.

Files: [`tests/eval-harness/`](../tests/eval-harness/).

- `helpers.php`: `mysite_t_assert_true()`, `mysite_t_assert_same()` (prints
  expected and actual), and `mysite_t_run()`, which runs the test, **restores the
  database**, prints `TOTAL n passed, m failed` and exits non-zero on any
  failure.
- `mysite_t_post()` and `mysite_t_option()` are the only ways a test writes. They
  record what they changed and undo it in a `finally` block, so a failing
  test does not leave junk behind.
- `run.sh` runs each `test-*.php` against the local `wordpress/` folder (run
  `composer build` first so it contains your latest `src/` code) and also fails a file that died before
  printing its `TOTAL` line (a fatal error otherwise looks like "no failures").
- `test-example.php` checks that a custom query variable does not collide with a
  reserved one, a post type, a pure function, and shows the restore mechanics.

```bash
tests/eval-harness/run.sh                      # everything
tests/eval-harness/run.sh test-example.php     # one file
```

Rules: run only against a local database; restore everything you write; if you
disable side effects of the code under test (an outgoing email, a cache purge,
a cron event queued on publish), do it in the test with a filter or by removing
the hook, never in the production code.

This is not CI: the tests need a populated local site. Run them before opening a
PR that touches the code they cover. If a piece of logic is pure enough to test
without WordPress, write it as a pure function and test it that way.

### `curl` for what the visitor and the crawler get

Routing, redirects, canonicals and pagination break silently and only show up in
Search Console weeks later. A smoke script checks them in seconds.

Script: [`tests/smoke.sh`](../tests/smoke.sh).

```bash
BASE_URL=https://example.test tests/smoke.sh
```

It provides `assert_status`, `assert_redirect` (one hop, exact target, no
following), `assert_canonical`, `assert_contains` and `assert_not_contains`, then
a **matrix** of the cases you care about:

- every legacy URL redirects in **one** hop to the new one;
- the canonical of `/page/2?utm_source=x` has no tracking parameter;
- a page past the last one is a 404, not an empty 200;
- page 2's title carries "Page 2" and page 1's does not.

Two traps worth knowing, both handled in the template:

- `curl | grep -q` under `set -o pipefail` gives false failures (grep exits
  early, curl is killed by SIGPIPE). Capture the body in a variable first.
- A negative check ("page does NOT contain X") passes on an error page or a dead
  server. Require a `200` with a body before trusting it.

## 5. Migrations as idempotent WP-CLI commands

Content migrations (renaming URL structures, moving data between fields,
importing from another system) are code that runs once on production data.
Write them so running them twice is harmless.

- **A WP-CLI command, not a button or a one-off script.** It can be run from SSH,
  logged, and run on a copy of the database first.
- **`--dry-run` is mandatory.** It does everything except write, and prints what
  it would change plus a summary. A second real run must report `0 updated`.
- **Match on a legacy-id meta key.** Store the old system's id on each created
  or updated item (`_mysite_legacy_id`). Re-runs look it up and update instead
  of inserting a duplicate. Matching on title or slug breaks as soon as someone
  edits them.
- **Its own plugin, deactivatable.** Put the command in a small plugin
  (`mysite-migrate-articles`). Activate it, run it, verify, deactivate it. It
  never loads on the live site afterwards, and the history of what ran is in git.

```php
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'mysite migrate-articles', 'MySite_Migrate_Articles' );
}

class MySite_Migrate_Articles {
	/**
	 * Create or update articles from a CSV (legacy_id,title,body).
	 *
	 * ## OPTIONS
	 * <file>
	 * : Path to the CSV file.
	 *
	 * [--dry-run]
	 * : Report what would change without writing.
	 */
	public function __invoke( $args, $assoc_args ) {
		$dry     = ! empty( $assoc_args['dry-run'] );
		$handle  = fopen( $args[0], 'r' );
		$created = 0;
		$updated = 0;
		$same    = 0;

		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			list( $legacy_id, $title, $body ) = $row;

			$existing = get_posts( array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'meta_key'       => '_mysite_legacy_id',
				'meta_value'     => $legacy_id,
				'fields'         => 'ids',
				'posts_per_page' => 1,
			) );

			if ( $existing && get_post_field( 'post_content', $existing[0] ) === $body ) {
				++$same;
				continue;
			}

			if ( $dry ) {
				WP_CLI::log( ( $existing ? 'would update ' : 'would create ' ) . $legacy_id );
			} elseif ( $existing ) {
				wp_update_post( array( 'ID' => $existing[0], 'post_content' => $body ) );
			} else {
				$id = wp_insert_post( array( 'post_title' => $title, 'post_content' => $body, 'post_status' => 'draft' ) );
				update_post_meta( $id, '_mysite_legacy_id', $legacy_id );
			}
			$existing ? ++$updated : ++$created;
		}
		fclose( $handle );

		WP_CLI::success( "$created created, $updated updated, $same unchanged" . ( $dry ? ' (dry run)' : '' ) );
	}
}
```

Order of work: take a database backup, run on a copy, run `--dry-run` on the real
site, run for real, run `--dry-run` again and expect "0 created, 0 updated".
Then purge caches and run the smoke tests for the URLs involved.

## 6. Done means you saw it work

Passing lint and tests tells you the code is well-formed, not that the feature
works. Before calling something done, open it in a real browser, on the real
page, as the real user type (logged out, if visitors see it):

- load the page and click through the changed behavior, not just the happy path;
- watch the console and the Network tab for errors and unexpectedly large or
  repeated requests;
- check a narrow (phone) viewport;
- confirm the change on a cached page: purge the cache, reload twice, compare.

Write down what you checked in the PR description. "Looks fine" is not a
report; "loaded `/blog/page/2` logged out, 12 articles, no console errors,
canonical correct" is.

## Checklist

- [ ] Pre-commit hook installed through `composer install`; it only touches `src/`
- [ ] `phpcs.xml.dist` committed; editor, hook and CI use it
- [ ] PR lint: `php -l` and phpcs on changed first-party files only; phpcs advisory
- [ ] If using AI review: lanes by diff, scripts from the base SHA, per-run temp
      dirs, digest comment, no merge gate
- [ ] `tests/eval-harness/` and `tests/smoke.sh` exist and run locally
- [ ] Migrations are WP-CLI commands with `--dry-run`, a legacy-id key, and their
      own plugin
- [ ] Verified in a browser before "done"
