#!/bin/bash
# Build the plugin package and update version/MD5 in the .plg.
# Usage: ./build.sh [version]   (default version: today's date, yyyy.mm.dd)
set -euo pipefail

cd "$(dirname "$0")"
NAME=disk.identificator
VERSION=${1:-$(date +%Y.%m.%d)}
PKG=archive/$NAME-$VERSION-x86_64-1.txz

mkdir -p archive
rm -f "$PKG"

# Unraid needs LF line endings and sane permissions, whatever the build host is.
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
cp -r source/. "$STAGE/"
find "$STAGE" -type f \( -name '*.php' -o -name '*.page' -o -name '*.js' -o -name '*.css' -o -name '*.md' \) -exec sed -i 's/\r$//' {} +
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +

tar -C "$STAGE" --owner=0 --group=0 --numeric-owner -cJf "$PKG" usr

MD5=$(md5sum "$PKG" | cut -d' ' -f1)
sed -i \
  -e "s|<!ENTITY version   \"[^\"]*\">|<!ENTITY version   \"$VERSION\">|" \
  -e "s|<!ENTITY md5       \"[^\"]*\">|<!ENTITY md5       \"$MD5\">|" \
  $NAME.plg

echo "Built $PKG"
echo "MD5   $MD5"
echo "Remember to add a '### $VERSION' entry to <CHANGES> in $NAME.plg if this is a new release."
