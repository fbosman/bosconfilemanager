#!/bin/sh
# Build the installable package bin/module_bosconfilemanager-<version>.zip (Dolibarr naming convention:
# module_<modulename>-<version>.zip with the module directory at the root of the archive).
# The version is read from the module descriptor. Files marked export-ignore in .gitattributes are left out.
set -e
cd "$(dirname "$0")/.."
VERSION=$(sed -n "s/.*\$this->version = '\([^']*\)'.*/\1/p" core/modules/modBosConfilemanager.class.php)
[ -n "$VERSION" ] || { echo "Version not found in descriptor" >&2; exit 1; }
mkdir -p bin
ZIP="bin/module_bosconfilemanager-$VERSION.zip"
rm -f "$ZIP"
git archive --format=zip --prefix=bosconfilemanager/ -o "$ZIP" HEAD
echo "$ZIP"
