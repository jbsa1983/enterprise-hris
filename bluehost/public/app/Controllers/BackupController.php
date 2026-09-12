<?php
// Backup & migration. Builds a downloadable ZIP containing a full SQL dump and
// (optionally) the whole installation, so the owner can restore or move hosts.
class BackupController
{
    public static function routes(Router $r): void
    {
        $r->get('/admin/backup', [self::class, 'backup']);
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
