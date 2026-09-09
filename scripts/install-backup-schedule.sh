#!/usr/bin/env bash
# =============================================================================
# Schedule the HRIS backup to run automatically.
#   macOS  → installs a launchd user agent (no sudo).
#   Linux  → prints the cron line to add.
#
# Usage:   scripts/install-backup-schedule.sh [HOUR] [MINUTE]
#   e.g.   scripts/install-backup-schedule.sh 2 0     # daily at 02:00 (default)
# Uninstall (macOS):
#   launchctl unload ~/Library/LaunchAgents/com.hris.backup.plist
#   rm ~/Library/LaunchAgents/com.hris.backup.plist
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOUR="${1:-2}"
MINUTE="${2:-0}"
LABEL="com.hris.backup"
BACKUP_DIR="${BACKUP_DIR:-$HOME/hris-backups}"
mkdir -p "$BACKUP_DIR"

if [ "$(uname)" = "Darwin" ]; then
  PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
  mkdir -p "$(dirname "$PLIST")"
  cat > "$PLIST" <<PLISTEOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$LABEL</string>
  <key>ProgramArguments</key>
  <array>
    <string>/bin/bash</string>
    <string>$SCRIPT_DIR/backup.sh</string>
  </array>
  <key>EnvironmentVariables</key>
  <dict>
    <key>PATH</key><string>/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin</string>
    <key>BACKUP_DIR</key><string>$BACKUP_DIR</string>
  </dict>
  <key>StartCalendarInterval</key>
  <dict><key>Hour</key><integer>$HOUR</integer><key>Minute</key><integer>$MINUTE</integer></dict>
  <key>StandardOutPath</key><string>$BACKUP_DIR/backup.log</string>
  <key>StandardErrorPath</key><string>$BACKUP_DIR/backup.log</string>
</dict>
</plist>
PLISTEOF
  launchctl unload "$PLIST" 2>/dev/null || true
  launchctl load "$PLIST"
  printf 'Scheduled daily backup at %02d:%02d via launchd (%s).\n' "$HOUR" "$MINUTE" "$LABEL"
  echo "Backups: $BACKUP_DIR   Logs: $BACKUP_DIR/backup.log"
  echo "Requires Docker Desktop to be running at that time."
  echo "Remove with: launchctl unload '$PLIST' && rm '$PLIST'"
else
  echo "Add this line to your crontab (run: crontab -e):"
  printf '%d %d * * * BACKUP_DIR=%s /bin/bash %s/backup.sh >> %s/backup.log 2>&1\n' \
    "$MINUTE" "$HOUR" "$BACKUP_DIR" "$SCRIPT_DIR" "$BACKUP_DIR"
fi
