<?php
// Scheduled backup runner. Add this to cPanel → Cron Jobs to run hourly:
//   0 * * * * /usr/local/bin/php /home/USER/your-subdomain/cron/backup.php >/dev/null 2>&1
// It backs up once per day, at or after the time set in Administration →
// Backup & Migration. Runs from the command line only.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from cron (command line) only.\n");
}
require __DIR__ . '/../app/bootstrap.php';
BackupController::runScheduled();
