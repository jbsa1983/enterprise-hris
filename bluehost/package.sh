#!/usr/bin/env bash
# Build a deployable ZIP for Bluehost/Zoom shared hosting.
# Usage:  ./package.sh [output.zip]
# Extract the zip into your subdomain's document root; keep the db/ folder to run
# the seeder, then remove it if you like.
set -euo pipefail
cd "$(dirname "$0")"

OUT_ARG="${1:-geek-hris-bluehost.zip}"
case "$OUT_ARG" in /*) OUT="$OUT_ARG";; *) OUT="$PWD/$OUT_ARG";; esac

STAGEROOT="$(mktemp -d)"
STAGE="$STAGEROOT/geek-hris-bluehost"
mkdir -p "$STAGE"

# Web-root contents (exclude the dev-only router and any local secrets).
rsync -a --exclude 'router.php' --exclude 'app/settings.php' public/ "$STAGE/"
# Database schema + seeder (protected by db/.htaccess if left in web root).
cp -r db "$STAGE/db"
cp README-DEPLOY.md "$STAGE/"

rm -f "$OUT"
( cd "$STAGEROOT" && zip -rq "$OUT" geek-hris-bluehost )
rm -rf "$STAGEROOT"
echo "Built: $OUT"
echo "Contents: web root (index.html, .htaccess, api/, app/, storage/) + db/ + README-DEPLOY.md"
