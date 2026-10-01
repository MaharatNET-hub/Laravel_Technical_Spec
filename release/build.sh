#!/usr/bin/env bash
# Builds release/submittal-review-laravel.zip: the app + production vendor/, ready to extract into a
# shared host's web root (no Composer or terminal needed there). .env is created on the first request.
set -euo pipefail
cd "$(dirname "$0")/.."
OUT="$PWD/release/submittal-review-laravel.zip"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

git ls-files -co --exclude-standard | grep -vE '^(docs|release|tests)/|^phpunit\.xml$' \
  | while read -r f; do if [ -e "$f" ]; then printf '%s\n' "$f"; fi; done | tar -cf - -T - | tar -xf - -C "$TMP"

cd "$TMP"
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction -q
# source checkouts carry .git folders and test suites: not needed to run
find vendor -name .git -type d -prune -exec rm -rf {} +
find vendor -mindepth 3 -maxdepth 3 -type d \( -name tests -o -name Tests -o -name docs -o -name doc -o -name .github \) -prune -exec rm -rf {} +
rm -rf vendor/laravel/framework/bin
mkdir -p storage/app/demo/sample storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
printf 'Put the sample submittal here as submittal.pdf (by FTP). Never commit it.\n' > storage/app/demo/sample/README.txt
rm -f .env

rm -f "$OUT"
zip -qr -X "$OUT" . -x '*.pdf'
echo "$OUT: $(du -h "$OUT" | cut -f1), $(unzip -l "$OUT" | tail -1 | awk '{print $2}') files"
