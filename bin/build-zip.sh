#!/usr/bin/env bash
# Builds the installable release ZIP: trustorder-for-woo-<version>.zip
#
# Source = `git archive` of the plugin folder at HEAD (committed files only, so the committed
# vendor-prefixed/ and build/ are in, and everything marked export-ignore in
# .gitattributes is out), minus composer.json/composer.lock (Composer
# metadata, not needed at runtime). Then the ZIP is checked against the
# dev-file list and the build fails if anything slipped in.
#
# Works both as the plugin/ folder of the monorepo and as a standalone repo.
#
# Usage: bin/build-zip.sh [output-dir]   (default: <plugin>/dist)
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_DIR="$(git -C "$PLUGIN_DIR" rev-parse --show-toplevel)"
# "" in a standalone repo, "plugin/" in the monorepo.
PREFIX="$(git -C "$PLUGIN_DIR" rev-parse --show-prefix)"
OUT_DIR="${1:-$PLUGIN_DIR/dist}"
SLUG="trustorder-for-woo"

if [ -n "$(git -C "$REPO_DIR" status --porcelain -- "$PLUGIN_DIR")" ]; then
	echo "warning: the plugin has uncommitted changes; the ZIP is built from HEAD" >&2
fi

VERSION="$(git -C "$REPO_DIR" show "HEAD:$PREFIX$SLUG.php" | sed -n 's/^ \* Version:[[:space:]]*\([0-9A-Za-z.-]*\).*/\1/p')"
[ -n "$VERSION" ] || { echo "error: no Version: header in $SLUG.php" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/$SLUG" "$OUT_DIR"
# The ZIP is written from inside $WORK, so a relative output dir must be absolute.
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
git -C "$REPO_DIR" archive "HEAD:$PREFIX" | tar -x -C "$WORK/$SLUG"
rm -f "$WORK/$SLUG/composer.json" "$WORK/$SLUG/composer.lock"

for required in "$SLUG.php" uninstall.php readme.txt includes build/index.js build/index.asset.php vendor-prefixed/autoload.php languages; do
	[ -e "$WORK/$SLUG/$required" ] || { echo "error: $required missing from the package" >&2; exit 1; }
done

ZIP="$OUT_DIR/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(cd "$WORK" && zip -qrX "$ZIP" "$SLUG")

FORBIDDEN='^'"$SLUG"'/(tests|src|node_modules|vendor|bin|docs|dist|\.superpowers|\.claude|\.github)/|/\.superpowers/|/docs/superpowers/|^'"$SLUG"'/(phpcs\.xml\.dist|phpstan[^/]*|phpunit\.xml\.dist|package(-lock)?\.json|composer\.(json|lock)|strauss\.phar|\.gitattributes|\.gitignore|\.phpunit\.result\.cache)$'
if unzip -Z1 "$ZIP" | grep -E "$FORBIDDEN"; then
	echo "error: dev files in the package (listed above)" >&2
	exit 1
fi

echo "$ZIP ($(unzip -Z1 "$ZIP" | grep -vc '/$') files, $(du -h "$ZIP" | cut -f1 | tr -d ' '))"
