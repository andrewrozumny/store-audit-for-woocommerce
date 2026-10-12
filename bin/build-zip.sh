#!/usr/bin/env bash
# Builds dist/store-audit-for-woocommerce-<version>.zip with shipping files only.
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="store-audit-for-woocommerce"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$SLUG.php" | head -n 1 | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "Cannot read version from $SLUG.php" >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG" dist

rsync -a --exclude-from=.distignore ./ "$STAGE/$SLUG/"

ZIP="$PWD/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr "$ZIP" "$SLUG" )

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1))"
unzip -l "$ZIP"

fail=0
while IFS= read -r -d '' f; do
	php -l "$f" > /dev/null || { echo "Syntax error: $f" >&2; fail=1; }
done < <(find "$STAGE/$SLUG" -name '*.php' -print0)
[ "$fail" -eq 0 ] || exit 1
echo "php -l OK"
