<?php
// Backup & migration. Builds a downloadable ZIP containing a full SQL dump and
// (optionally) the whole installation, so the owner can restore or move hosts.
class BackupController
{
    public static function routes(Router $r): void
    {
        $r->get('/admin/backup', [self::class, 'backup']);
        $r->get('/admin/backup/schedule', [self::class, 'getSchedule']);
        $r->post('/admin/backup/schedule', [self::class, 'saveScheduleReq']);
        $r->post('/admin/backup/run-now', [self::class, 'runNow']);
        $r->get('/admin/backup/file', [self::class, 'downloadFile']);
    }

    // --- Scheduled backups ---------------------------------------------------
    // Auto-backups live ABOVE the web root so they are never downloadable directly.
    private static function backupsDir(): string
    {
        return dirname(dirname(dirname(__DIR__))) . '/geek-hris-backups';
    }
    private static function ensureBackupsDir(): string
    {
        $dir = self::backupsDir();
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        return $dir;
    }
    private static function scheduleFile(): string { return self::backupsDir() . '/schedule.json'; }

    public static function schedule(): array
    {
        $f = self::scheduleFile();
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return (is_array($d) ? $d : []) + ['enabled' => false, 'time' => '00:00', 'retain' => 14, 'last_run' => null];
    }
    public static function saveSchedule(array $cfg): void
    {
        self::ensureBackupsDir();
        file_put_contents(self::scheduleFile(), json_encode($cfg));
    }

    /** Write a gzipped SQL dump into the backups folder; returns the file path. */
    private static function runBackupToDir(): string
    {
        $dir = self::ensureBackupsDir();
        $tmp = tempnam(sys_get_temp_dir(), 'hrisdb');
        self::writeDump($tmp);
        $gz = $dir . '/database-' . date('Y-m-d-His') . '.sql.gz';
        $in = fopen($tmp, 'rb'); $out = gzopen($gz, 'wb6');
        while (!feof($in)) gzwrite($out, fread($in, 262144));
        fclose($in); gzclose($out); @unlink($tmp);
        return $gz;
    }
    private static function prune(int $retainDays): void
    {
        $cutoff = time() - max(1, $retainDays) * 86400;
        foreach (glob(self::backupsDir() . '/database-*.sql.gz') ?: [] as $f) {
            if (filemtime($f) < $cutoff) @unlink($f);
        }
    }

    /** Called by cron. Runs a backup once per day, at or after the configured time. */
    public static function runScheduled(): void
    {
        $cfg = self::schedule();
        if (empty($cfg['enabled'])) { echo "scheduled backup: disabled\n"; return; }
        $today = date('Y-m-d');
        if (($cfg['last_run'] ?? '') === $today) { echo "scheduled backup: already ran today\n"; return; }
        if (time() < strtotime($today . ' ' . ($cfg['time'] ?? '00:00'))) { echo "scheduled backup: not due yet\n"; return; }
        $file = self::runBackupToDir();
        $cfg['last_run'] = $today;
        self::saveSchedule($cfg);
        self::prune((int) ($cfg['retain'] ?? 14));
        echo "scheduled backup: wrote " . basename($file) . "\n";
    }

    private static function scheduleResponse(): array
    {
        $cfg = self::schedule();
        $dir = self::backupsDir();
        $backups = [];
        foreach (glob($dir . '/database-*.sql.gz') ?: [] as $f) {
            $backups[] = ['name' => basename($f), 'size' => filesize($f), 'date' => date('Y-m-d H:i', filemtime($f))];
        }
        usort($backups, fn($a, $b) => strcmp($b['name'], $a['name']));
        $php = '/usr/local/bin/php';
        return [
            'enabled' => (bool) $cfg['enabled'], 'time' => $cfg['time'], 'retain' => (int) $cfg['retain'],
            'last_run' => $cfg['last_run'], 'dir' => $dir,
            'cron_command' => "0 * * * * $php " . dirname(dirname(__DIR__)) . "/cron/backup.php >/dev/null 2>&1",
            'backups' => $backups,
        ];
    }

    public static function getSchedule(): void
    {
        Auth::requirePerm('system.admin');
        Http::json(self::scheduleResponse());
    }

    public static function saveScheduleReq(): void
    {
        Auth::requirePerm('system.admin');
        $b = Http::body();
        $cfg = self::schedule();
        $cfg['enabled'] = !empty($b['enabled']);
        if (isset($b['time']) && preg_match('/^\d{1,2}:\d{2}$/', (string) $b['time'])) $cfg['time'] = $b['time'];
        if (isset($b['retain'])) $cfg['retain'] = max(1, (int) $b['retain']);
        self::saveSchedule($cfg);
        Http::json(self::scheduleResponse());
    }

    public static function runNow(): void
    {
        Auth::requirePerm('system.admin');
        if (!class_exists('ZipArchive') && !function_exists('gzopen')) throw new HttpError('Compression not available on this host', 500);
        @set_time_limit(0);
        $file = self::runBackupToDir();
        self::prune((int) self::schedule()['retain']);
        Http::json(['ok' => true, 'file' => basename($file)]);
    }

    public static function downloadFile(): void
    {
        Auth::requirePerm('system.admin');
        $name = basename((string) Http::query('name'));
        $path = self::backupsDir() . '/' . $name;
        if (!preg_match('/^database-[\w\-]+\.sql\.gz$/', $name) || !is_file($path)) throw new HttpError('Backup file not found', 404);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }

    public static function backup(): void
    {
        Auth::requirePerm('system.admin');
        if (!class_exists('ZipArchive')) throw new HttpError('The PHP zip extension is not available on this host', 500);
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $scope = Http::query('scope') === 'db' ? 'db' : 'full';
        $zipPath = tempnam(sys_get_temp_dir(), 'hriszip');
        self::buildZip($zipPath, $scope === 'full');

        $fname = 'geek-hris-' . ($scope === 'db' ? 'database' : 'backup') . '-' . date('Y-m-d-His') . '.zip';
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-store');
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    private static function buildZip(string $zipPath, bool $includeCode): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new HttpError('Could not create the backup archive', 500);
        }

        $dumpTmp = tempnam(sys_get_temp_dir(), 'hrisdb');
        self::writeDump($dumpTmp);
        $zip->addFile($dumpTmp, 'database.sql');

        if ($includeCode) {
            $root = dirname(dirname(__DIR__)); // web document root (contains index.html, app/, api/)
            $exclude = ['app/settings.php', 'app/installed-license.php'];
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                $rel = str_replace('\\', '/', ltrim(str_replace($root, '', $file->getPathname()), '/\\'));
                if (in_array($rel, $exclude, true)) continue;              // never ship secrets
                if (str_starts_with($rel, 'uploads/backups/')) continue;   // don't nest old backups
                if (preg_match('/\.zip$/i', $rel)) continue;
                $zip->addFile($file->getPathname(), 'app-files/' . $rel);
            }
            $zip->addFromString('MIGRATION.txt', self::migrationReadme());
        }

        $zip->close();
        @unlink($dumpTmp);
    }

    /** Pure-PHP mysqldump: schema + data for every table. */
    private static function writeDump(string $path): void
    {
        $pdo = Database::pdo();
        $fh = fopen($path, 'w');
        fwrite($fh, "-- GEEK HRIS database backup — " . date('c') . "\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach (Database::all('SHOW TABLES') as $row) {
            $t = array_values($row)[0];
            $create = Database::one("SHOW CREATE TABLE `$t`");
            $ddl = $create['Create Table'] ?? ($create['Create View'] ?? '');
            fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n" . $ddl . ";\n\n");
            $stmt = $pdo->query("SELECT * FROM `$t`");
            $cols = null;
            while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($cols === null) $cols = '`' . implode('`,`', array_keys($r)) . '`';
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($r));
                fwrite($fh, "INSERT INTO `$t` ($cols) VALUES (" . implode(',', $vals) . ");\n");
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
    }

    private static function migrationReadme(): string
    {
        return <<<TXT
GEEK HRIS — Migration / Restore
================================
This archive contains everything to restore or move this installation:
  • database.sql   — a full dump of your database (schema + all data)
  • app-files/     — the complete application (code, uploads, branding, settings sample)

Steps on the NEW hosting account (same procedure as a fresh install):

1. Create a subdomain and note its Document Root.
2. Create a MySQL database + user (grant ALL PRIVILEGES). Note name/user/password.
3. Extract the contents of app-files/ into the subdomain's Document Root
   (so index.html sits directly in that folder).
4. Import the database. In cPanel Terminal, from the subdomain folder:
       mysql -u DBUSER -p DBNAME < /path/to/database.sql
   (or import database.sql through phpMyAdmin).
5. Configure app/settings.php:
       copy app/settings.sample.php to app/settings.php
       set db_name / db_user / db_pass to the NEW database, and set a jwt_secret.
   (If you still have your old settings.php, just update the db_* values.)
6. cPanel → SSL/TLS Status → Run AutoSSL for HTTPS.
7. If your copy is licensed and the DOMAIN changed, request a new license key for the
   new domain and enter it in Administration → License. (Same domain = same key works.)

All users, employees, payroll, uploads and settings come across intact.
Full details are in the Installation Guide.
TXT;
    }
}
