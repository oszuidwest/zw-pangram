#!/usr/bin/env bash
set -euo pipefail

# Regenerate the POT, or pass --check to compare it without volatile headers.

cd "$(dirname "${BASH_SOURCE[0]}")/.."

POT="languages/zw-pangram.pot"

make_pot() {
	local output="$1"
	shift
	wp i18n make-pot . "$output" \
		--slug=zw-pangram \
		--domain=zw-pangram \
		--include=src,assets,zw-pangram.php,uninstall.php \
		--exclude=dist,node_modules,vendor,playground,scripts,tests \
		--headers='{"Report-Msgid-Bugs-To":"https://github.com/oszuidwest/zw-pangram/issues"}' \
		"$@"
}

if [ "${1:-}" = "--check" ]; then
	tmp="$(mktemp -d)"
	trap 'rm -rf "$tmp"' EXIT
	make_pot "$tmp/generated.pot" --skip-audit
	sed -E '/POT-Creation-Date:|X-Generator:/d' "$POT" > "$tmp/committed.stripped"
	sed -E '/POT-Creation-Date:|X-Generator:/d' "$tmp/generated.pot" > "$tmp/generated.stripped"
	diff -u "$tmp/committed.stripped" "$tmp/generated.stripped"
else
	make_pot "$POT"
fi
