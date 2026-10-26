#!/usr/bin/env bash
# run.sh
#
# What it is: runs every test-*.php next to this script with `wp eval-file`
# against the local site and exits non-zero if any file fails.
#
# The code under test must be in wordpress/: run `composer build` after editing src/.
#
# Usage:
#   tests/eval-harness/run.sh
#   WP_DIR=/path/to/wordpress tests/eval-harness/run.sh
#   tests/eval-harness/run.sh test-example.php          # only some files
#
# Needs WP-CLI and a local, installed WordPress (WP_DIR defaults to ../../wordpress,
# where Composer installs core). Tests write to the database, so never point it
# at production.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_DIR="${WP_DIR:-$SCRIPT_DIR/../../wordpress}"

if ! command -v wp > /dev/null 2>&1; then
	echo "run.sh: WP-CLI ('wp') not found in PATH." >&2
	exit 2
fi
if [ ! -d "$WP_DIR" ]; then
	echo "run.sh: WP_DIR '$WP_DIR' does not exist." >&2
	exit 2
fi

if [ "$#" -gt 0 ]; then
	files=()
	for name in "$@"; do
		files+=("$SCRIPT_DIR/$(basename "$name")")
	done
else
	files=("$SCRIPT_DIR"/test-*.php)
fi

status=0
for f in "${files[@]}"; do
	if [ ! -f "$f" ]; then
		echo "run.sh: no test file '$f'" >&2
		status=1
		continue
	fi

	echo "=== $(basename "$f")"
	out="$(wp --path="$WP_DIR" eval-file "$f" 2>&1)"
	code=$?

	# Show the check lines (and the expected/actual detail under a FAIL), hide PHP deprecation noise.
	printf '%s\n' "$out" | grep -E '^(PASS|FAIL|TOTAL|      )' || true

	# A test that died before printing its TOTAL line did not pass either.
	if [ "$code" -ne 0 ] || ! printf '%s\n' "$out" | grep -q '^TOTAL'; then
		status=1
		if ! printf '%s\n' "$out" | grep -q '^TOTAL'; then
			echo "FAIL: $(basename "$f") ended without a TOTAL line. Raw output:"
			printf '%s\n' "$out" | tail -n 20
		fi
	fi
done

echo ""
if [ "$status" -eq 0 ]; then
	echo "All test files passed."
else
	echo "At least one test file failed."
fi
exit "$status"
