#!/usr/bin/env bash
# Builds dist/mailprobe-<version>.zip from the last commit: a folder `mailprobe/`
# with what WordPress needs, without the files marked export-ignore.
set -euo pipefail
cd "$(dirname "$0")/.."
version="$(sed -n 's/^ \* Version:[[:space:]]*//p' mailprobe.php | tr -d '\r')"
stable="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | tr -d '\r')"
if [ "$version" != "$stable" ]; then
  echo "Version $version in mailprobe.php, Stable tag $stable in readme.txt: align them first." >&2
  exit 1
fi
if ! grep -q "define( 'MAILPROBE_VERSION', '$version' );" mailprobe.php; then
  echo "MAILPROBE_VERSION does not say $version." >&2
  exit 1
fi
if [ -n "$(git status --porcelain)" ]; then
  echo "Uncommitted changes: the zip is built from the last commit, commit first." >&2
  exit 1
fi
mkdir -p dist
git archive --format=zip --prefix=mailprobe/ -o "dist/mailprobe-$version.zip" HEAD
echo "dist/mailprobe-$version.zip"
