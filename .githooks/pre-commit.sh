#!/usr/bin/env bash
# pre-commit.sh
#
# What it is: runs phpcbf (WordPress coding standard) on the PHP files staged for
# this commit and stages the fixes, so style never reaches a PR as noise.
#
# How it runs: composer-git-hooks installs it from composer.json ("extra.hooks")
# when you run `composer install`; see docs/07-testing-and-quality.md.
#
# Needs ./vendor/bin/phpcbf (PHP_CodeSniffer, pulled in by wp-coding-standards/wpcs).
# If it is missing the hook says so and lets the commit through.
#
# Rules this script follows:
#   - Only first-party files: PHP under src/. Never wordpress/ (Composer-installed
#     core), vendor/ or node_modules/. Change SCOPE if your first-party code lives
#     elsewhere.
#   - phpcbf exit code 1 means "I fixed things": that is success, never a block.
#     Exit 2 means some violations are not auto-fixable: warn, do not block (the
#     PR lint job reports them). Exit 3+ is a real tool failure: block.
#   - A file with unstaged changes on top of the staged ones is skipped, because
#     `git add` would silently stage the unstaged part too.

set -euo pipefail

SCOPE='^src/'
PHPCBF="./vendor/bin/phpcbf"
# A phpcs.xml(.dist) at the repo root wins; otherwise use the plain WordPress standard.
if [ -f phpcs.xml ] || [ -f phpcs.xml.dist ]; then
	STANDARD_ARGS=()
else
	STANDARD_ARGS=(--standard="${PHPCS_STANDARD:-WordPress}")
fi

if [ ! -x "$PHPCBF" ]; then
	echo "pre-commit: $PHPCBF not found (run 'composer install'); skipping phpcbf."
	exit 0
fi

# Staged, added/copied/modified PHP files that are ours.
staged=()
while IFS= read -r f; do
	[ -n "$f" ] && staged+=("$f")
done < <(git diff --cached --name-only --diff-filter=ACM | grep -E '\.php$' | grep -E "$SCOPE" || true)

if [ "${#staged[@]}" -eq 0 ]; then
	exit 0
fi

# Drop files that also carry unstaged edits.
files=()
for f in "${staged[@]}"; do
	if git diff --quiet -- "$f"; then
		files+=("$f")
	else
		echo "pre-commit: $f has unstaged changes; not auto-fixing it. Run phpcbf by hand."
	fi
done

if [ "${#files[@]}" -eq 0 ]; then
	exit 0
fi

echo "pre-commit: phpcbf on ${#files[@]} file(s)"

status=0
"$PHPCBF" ${STANDARD_ARGS[@]+"${STANDARD_ARGS[@]}"} "${files[@]}" || status=$?

case "$status" in
	0|1) ;;  # 0: nothing to fix, 1: everything fixable was fixed
	2) echo "pre-commit: some violations are not auto-fixable; the PR lint job will list them." ;;
	*) echo "pre-commit: phpcbf failed (exit $status)." >&2; exit "$status" ;;
esac

# Put the fixed versions into the commit.
git add -- "${files[@]}"
exit 0
