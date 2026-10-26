#!/usr/bin/env bash
# smoke.sh
#
# What it is: curl-based smoke tests for a running site. Status codes,
# single-hop redirects, canonical tags, pagination and title matrices. It tests
# what a visitor and a crawler actually receive, which is where routing,
# redirect and SEO regressions show up.
#
# How to adopt: replace the example checks at the bottom with your own routes and
# run it against the LOCAL site (it is a manual smoke test, not a CI job: CI has
# no populated database).
#
# Usage:
#   BASE_URL=https://example.test tests/smoke.sh
#   INSECURE=0 BASE_URL=https://staging.example.org tests/smoke.sh   # real certificate
#
# Output: one line per check, "PASS: ..." or "FAIL: ...". Exit code is 0 only when
# every check passed.

set -uo pipefail

BASE_URL="${BASE_URL:-https://example.test}"
BASE_URL="${BASE_URL%/}"
B="$BASE_URL"
INSECURE="${INSECURE:-1}"   # 1 = accept the self-signed certificate of a local dev site

PASSED=0
FAILED=0

pass() { PASSED=$((PASSED + 1)); echo "PASS: $1"; }
fail() { FAILED=$((FAILED + 1)); echo "FAIL: $1"; }

_curl() {
	if [ "$INSECURE" = "1" ]; then
		curl -sk "$@"
	else
		curl -s "$@"
	fi
}

# assert_status URL EXPECTED_CODE DESCRIPTION
assert_status() {
	local url="$1" expected="$2" desc="$3" code
	code=$(_curl -o /dev/null -w '%{http_code}' "$url")
	if [ "$code" = "$expected" ]; then
		pass "$desc ($code)"
	else
		fail "$desc (expected $expected, got $code) [$url]"
	fi
}

# assert_redirect URL EXPECTED_TARGET DESCRIPTION [EXPECTED_CODE=301]
# Does not follow the redirect, so a chain of two hops shows up as a mismatch.
assert_redirect() {
	local url="$1" expected="$2" desc="$3" want="${4:-301}" headers code location
	headers=$(_curl -D - -o /dev/null "$url")
	code=$(printf '%s' "$headers" | head -1 | awk '{print $2}')
	location=$(printf '%s' "$headers" | grep -i '^location:' | tail -1 | sed -E 's/^[Ll]ocation:[[:space:]]*//' | tr -d '\r')
	if [ "$code" != "$want" ]; then
		fail "$desc (expected $want, got $code) [$url]"
	elif [ "$location" = "$expected" ]; then
		pass "$desc -> $location"
	else
		fail "$desc (expected target $expected, got '$location') [$url]"
	fi
}

# assert_canonical URL EXPECTED_CANONICAL DESCRIPTION
assert_canonical() {
	local url="$1" expected="$2" desc="$3" canon
	canon=$(_curl "$url" | grep -o '<link rel="canonical"[^>]*>' | head -1 | sed -E 's/.*href="([^"]*)".*/\1/')
	if [ "$canon" = "$expected" ]; then
		pass "$desc"
	else
		fail "$desc (expected canonical $expected, got '$canon') [$url]"
	fi
}

# assert_contains URL NEEDLE DESCRIPTION
# Captures the body first and matches with a bash glob. Do not write
# `curl | grep -q`: under `pipefail`, grep -q exits at the first match, curl
# gets SIGPIPE, and the whole pipeline reports a false failure.
assert_contains() {
	local url="$1" needle="$2" desc="$3" body
	body=$(_curl "$url")
	if [[ "$body" == *"$needle"* ]]; then
		pass "$desc"
	else
		fail "$desc (did not find '$needle') [$url]"
	fi
}

# assert_not_contains URL NEEDLE DESCRIPTION
# Fails unless the page itself loaded (200 and a body), so an error page or a
# dead server can never make a negative check pass.
assert_not_contains() {
	local url="$1" needle="$2" desc="$3" out code body
	out=$(_curl -w '\n%{http_code}' "$url") || { fail "$desc (request failed) [$url]"; return; }
	code="${out##*$'\n'}"
	body="${out%$'\n'*}"
	if [ "$code" != "200" ] || [ -z "$body" ]; then
		fail "$desc (page did not load with 200 and a body) [$url]"
	elif [[ "$body" == *"$needle"* ]]; then
		fail "$desc (found '$needle') [$url]"
	else
		pass "$desc"
	fi
}

# ---------------------------------------------------------------------------
# Example checks: replace with your own routes.
# ---------------------------------------------------------------------------

echo "== Smoke tests against $B =="

echo "-- Status --"
assert_status "$B/" 200 "home returns 200"
assert_status "$B/this-page-does-not-exist-$$" 404 "unknown URL returns 404"

echo "-- Redirects --"
assert_redirect "$B/blog/" "$B/blog" "trailing slash is removed in one hop"
# Table-driven: "from|to" pairs, one assertion each.
while IFS='|' read -r from to; do
	[ -z "$from" ] && continue
	assert_redirect "$B$from" "$B$to" "legacy $from -> $to"
done <<'EOF'
/old-section/|/new-section
/old-page|/new-page
EOF

echo "-- Canonical --"
assert_canonical "$B/" "$B/" "home is self-canonical"
assert_canonical "$B/blog/page/2?utm_source=x" "$B/blog/page/2" "tracking parameters are not in the canonical"

echo "-- Pagination --"
assert_status   "$B/blog/page/2" 200 "page 2 exists"
assert_status   "$B/blog/page/9999" 404 "a page past the last one is a 404"
assert_contains "$B/blog/page/2" '<link rel="prev"' "page 2 has rel=prev"

echo "-- Titles and content --"
assert_contains     "$B/blog/page/2" '(Page 2)</title>' "page 2 title carries the page number"
assert_not_contains "$B/blog" '(Page 2)</title>' "page 1 title has no page number"
assert_not_contains "$B/" 'localhost' "no localhost URLs leaked into the HTML"

# ---------------------------------------------------------------------------

echo ""
echo "== TOTAL: $PASSED passed, $FAILED failed =="
[ "$FAILED" -eq 0 ]
