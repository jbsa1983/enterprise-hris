#!/usr/bin/env bash
# Build a deployable ZIP for Bluehost/Zoom shared hosting:
#   PHP/MySQL backend + the built React SPA front-end.
# Usage:  ./package.sh [output.zip]
# Extract the zip into your subdomain's document root. Keep db/ to import
# schema.sql + run seed.php, then remove it if you like.
set -euo pipefail
cd "$(dirname "$0")"

OUT_ARG="${1:-geek-hris-bluehost.zip}"
case "$OUT_ARG" in /*) OUT="$OUT_ARG";; *) OUT="$PWD/$OUT_ARG";; esac

# 1. Build the React SPA (produces spa/dist).
if [ ! -f spa/dist/index.html ] || [ "${REBUILD_SPA:-0}" = "1" ]; then
    echo "Building React SPA…"
    ( cd spa && { [ -d node_modules ] || npm install --no-audit --no-fund; } && npm run build )
fi

STAGEROOT="$(mktemp -d)"
STAGE="$STAGEROOT/geek-hris-bluehost"
mkdir -p "$STAGE"

# 2. PHP backend (api/, app/, storage/, .htaccess) — drop the dev router and the
#    foundation index.html (the SPA provides index.html instead).
rsync -a --exclude 'router.php' --exclude 'index.html' --exclude 'app/settings.php' --exclude 'app/installed-license.php' public/ "$STAGE/"

# 3. The built SPA (index.html + assets/) as the web-root front-end.
rsync -a spa/dist/ "$STAGE/"

# 4. Database + docs.
cp -r db "$STAGE/db"
cp README-DEPLOY.md "$STAGE/"

rm -f "$OUT"
( cd "$STAGEROOT" && zip -rq "$OUT" geek-hris-bluehost )
rm -rf "$STAGEROOT"
echo "Built: $OUT"
echo "Web root = React SPA (index.html + assets/) + PHP api/ + app/ + storage/ + .htaccess; plus db/ + README."
