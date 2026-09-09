#!/usr/bin/env bash
# =============================================================================
# Enterprise HRIS — backup
# Dumps the Postgres database (logical, compressed) and the MinIO document
# volume (tarball) into a timestamped folder, with checksums and retention.
#
# Usage:   scripts/backup.sh
# Config (env overrides):
#   BACKUP_DIR       where backups go            (default: $HOME/hris-backups)
#   RETENTION_DAYS   prune backups older than N  (default: 14)
#   PG_CONTAINER     postgres container name     (default: hris-postgres)
#   MINIO_CONTAINER  minio container name        (default: hris-minio)
#   COMPOSE_PROJECT_NAME  compose project        (default: enterprise-hris)
# =============================================================================
set -euo pipefail

# Ensure docker is on PATH even under launchd/cron minimal environments.
export PATH="/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:${PATH:-}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

# Load DB/MinIO settings from the project's .env if present.
if [ -f "$ROOT_DIR/.env" ]; then
  set -a; . "$ROOT_DIR/.env"; set +a
fi

PROJECT="${COMPOSE_PROJECT_NAME:-enterprise-hris}"
PG_CONTAINER="${PG_CONTAINER:-hris-postgres}"
MINIO_VOLUME="${MINIO_VOLUME:-${PROJECT}_minio_data}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/hris-backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
POSTGRES_USER="${POSTGRES_USER:-hris}"
POSTGRES_DB="${POSTGRES_DB:-hris}"

TS="$(date +%Y%m%d_%H%M%S)"
DEST="$BACKUP_DIR/$TS"
mkdir -p "$DEST"

log() { echo "[backup $(date +%H:%M:%S)] $*"; }

if ! docker inspect "$PG_CONTAINER" >/dev/null 2>&1; then
  echo "ERROR: container '$PG_CONTAINER' not found. Is the stack running?" >&2
  exit 1
fi

log "destination: $DEST"

# 1) PostgreSQL — logical dump in custom format (restorable with pg_restore).
log "dumping PostgreSQL database '$POSTGRES_DB'…"
docker exec "$PG_CONTAINER" pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB" > "$DEST/postgres_${POSTGRES_DB}.dump"

# 2) MinIO — archive the document data volume (read-only mount).
log "archiving MinIO volume '$MINIO_VOLUME'…"
docker run --rm -v "${MINIO_VOLUME}:/data:ro" -v "$DEST:/backup" alpine:3.20 \
  tar czf /backup/minio_data.tar.gz -C /data . 2>/dev/null || \
  log "WARN: MinIO volume archive skipped (volume '$MINIO_VOLUME' not found)."

# 3) Checksums + manifest.
(
  cd "$DEST"
  if command -v shasum >/dev/null 2>&1; then shasum -a 256 ./* > SHA256SUMS
  else sha256sum ./* > SHA256SUMS; fi
) 2>/dev/null || true
{
  echo "project=$PROJECT"
  echo "created=$TS"
  echo "database=$POSTGRES_DB"
  echo "size=$(du -sh "$DEST" | cut -f1)"
} > "$DEST/manifest.txt"

log "backup complete: $(du -sh "$DEST" | cut -f1) in $DEST"

# 4) Retention — drop backup folders older than RETENTION_DAYS.
log "pruning backups older than ${RETENTION_DAYS} days…"
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime +"$RETENTION_DAYS" -exec rm -rf {} + 2>/dev/null || true

log "done."
