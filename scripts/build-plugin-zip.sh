#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
slug="tn-meeting-polls"
mkdir -p dist
rm -rf "dist/$slug"
rm -f "dist/$slug.zip" "$slug.zip"
cp -R "$slug" "dist/$slug"
find "dist/$slug" -name '.DS_Store' -delete
rm -rf "dist/$slug/node_modules"
(
    cd dist
    zip -qr "$slug.zip" "$slug"
)
cp "dist/$slug.zip" "$slug.zip"
