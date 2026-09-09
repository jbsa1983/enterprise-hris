#!/usr/bin/env bash
# =============================================================================
# Enterprise HRIS — restore
# Restores a backup created by scripts/backup.sh. Stops the app while it works,
# restores Postgres and MinIO, then restarts.
#
# Usage:   scripts/restore.sh <path-to-backup-folder>
#   e.g.   scripts/restore.sh ~/hris-backups/20260909_020000
#
# WARNING: this OVERWRITES the current database and documents.
# =============================================================================
set -euo pipefail
export PATH="/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:${PATH:-}"

BACKUP_PATH="${1:-}"
if [ -z "$BACKUP_PATH" ] || [ ! -d "$BACKUP_PATH" ]; then
  echo "Usage: $0 <path-to-backup-folder>" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
[ -f "$ROOT_DIR/.env" ] && { set -a; . "$ROOT_DIR/.env"; set +a; }

PROJECT="${COMPOSE_PROJECT_NAME:-enterprise-hris}"
PG_CONTAINER="${PG_CONTAINER:-hris-postgres}"
MINIO_CONTAINER="${MINIO_CONTAINER:-hris-minio}"
MINIO_VOLUME="${MINIO_VOLUME:-${PROJECT}_minio_data}"
BACKEND_CONTAINER="${BACKEND_CONTAINER:-hris-backend}"
WORKER_CONTAINER="${WORKER_CONTAINER:-hris-worker}"
POSTGRES_USER="${POSTGRES_USER:-hris}"
POSTGRES_DB="${POSTGRES_DB:-hris}"

DUMP="$BACKUP_PATH/postgres_${POSTGRES_DB}.dump"
MINIO_TAR="$BACKUP_PATH/minio_data.tar.gz"
[ -f "$DUMP" ] || { echo "ERROR: $DUMP not found" >&2; exit 1; }

echo "About to OVERWRITE database '$POSTGRES_DB' and MinIO documents from:"
echo "  $BACKUP_PATH"
read -r -p "Type 'RESTORE' to continue: " confirm
[ "$confirm" = "RESTORE" ] || { echo "Aborted."; exit 1; }

log() { echo "[restore $(date +%H:%M:%S)] $*"; }

# Stop app so nothing writes during the restore.
log "stopping app containers…"
docker stop "$BACKEND_CONTAINER" "$WORKER_CONTAINER" >/dev/null 2>&1 || true

# --- PostgreSQL --------------------------------------------------------------
log "restoring PostgreSQL…"
docker exec -i "$PG_CONTAINER" pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
  --clean --if-exists --no-owner < "$DUMP"

# --- MinIO -------------------------------------------------------------------
if [ -f "$MINIO_TAR" ]; then
  log "restoring MinIO documents (stop → replace → start)…"
  docker stop "$MINIO_CONTAINER" >/dev/null 2>&1 || true
  docker run --rm -v "${MINIO_VOLUME}:/data" -v "$BACKUP_PATH:/backup:ro" alpine:3.20 \
    sh -c "rm -rf /data/* /data/..?* /data/.[!.]* 2>/dev/null; tar xzf /backup/minio_data.tar.gz -C /data"
  docker start "$MINIO_CONTAINER" >/dev/null 2>&1 || true
else
  log "no MinIO archive in backup — skipping document restore."
fi

# Restart app.
log "starting app containers…"
docker start "$BACKEND_CONTAINER" "$WORKER_CONTAINER" >/dev/null 2>&1 || true

log "restore complete."
