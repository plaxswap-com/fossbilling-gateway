#!/bin/bash
# Builds the ready-to-install package: the zip root is the content of library/Payment/Adapter/Plaxswap/.
# Runs the tests and php -l first. Local only: uploads nothing.
# Files get the build time as their date: a fixed date would let PHP OPcache keep serving an older version after an
# upgrade, because OPcache notices changed files by their modification time (seen in the FOSSBilling end-to-end test).
set -euo pipefail
cd "$(dirname "$0")"
PHP=${PHP:-/opt/homebrew/bin/php}
VERSION=$("$PHP" -r 'echo json_decode(file_get_contents("src/Plaxswap/manifest.json"), true)["version"];')

for f in src/Plaxswap/Plaxswap.php src/Plaxswap/lib/*.php tests/*.php; do "$PHP" -l "$f" >/dev/null; done
"$PHP" tests/run-tests.php >/dev/null

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
cp -R src/Plaxswap/. "$STAGE/"
cp LICENSE README.md CHANGELOG.md "$STAGE/"
find "$STAGE" -name '.DS_Store' -delete
# Only one PHP file may sit at the package root: FOSSBilling lists every top-level .php file in an adapter folder as a gateway.
[ "$(find "$STAGE" -maxdepth 1 -name '*.php' | wc -l | tr -d ' ')" = "1" ]

mkdir -p dist
OUT="dist/plaxswap-fossbilling-$VERSION.zip"
rm -f "$OUT"
(cd "$STAGE" && touch $(find . -mindepth 1) && zip -X -q -r - . -x '.*') > "$OUT"
shasum -a 256 "$OUT" | tee "$OUT.sha256"
unzip -l "$OUT"
