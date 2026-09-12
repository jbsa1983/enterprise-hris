<?php
class StorageController
{
    public static function routes(Router $r): void
    {
        $r->get('/system/storage', [self::class, 'storage']);
    }

    /** Recursive size (bytes) of a directory; 0 if missing/unreadable. */
    private static function dirSize(?string $path): int
    {
        if (!$path || !is_dir($path)) return 0;
        $bytes = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                if ($file->isFile()) $bytes += (int) $file->getSize();
            }
        } catch (\Throwable $e) {
            // Unreadable subtree — return what we counted so far.
        }
        return $bytes;
    }

    public static function storage(): void
    {
        Auth::requirePerm('storage.view');

        // --- Real usage --------------------------------------------------------
        // 1) Actual MySQL database size (data + indexes) for THIS database.
        $dbName  = Config::get('db_name');
        $row = Database::one(
            'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes
               FROM information_schema.TABLES WHERE table_schema = ?', [$dbName]);
        $dbBytes = (int) ($row['bytes'] ?? 0);

        // 2) Actual uploaded documents on disk (recursive) under storage_path.
        $storagePath = Config::get('storage_path');
        $filesBytes  = self::dirSize($storagePath);

        // 3) Actual backups on disk, if a backup_path is configured.
        $backupPath  = Config::get('backup_path', null);
        $backupBytes = self::dirSize($backupPath);

        $usedBytes = $dbBytes + $filesBytes + $backupBytes;

        // --- Capacity ----------------------------------------------------------
        // Prefer the plan limit the admin set (storage_quota_gb in settings.php).
        // Without one we fall back to the real server disk so the % is at least
        // truthful — just not specific to your hosting plan.
        $quotaGb = (float) Config::get('storage_quota_gb', 0);
        if ($quotaGb > 0) {
            $totalBytes = (int) round($quotaGb * (1024 ** 3));
            $basis = 'plan-quota';
        } else {
            $totalBytes = (int) (@disk_total_space($storagePath) ?: 0);
            $basis = 'server-disk';
        }

        $pct = $totalBytes > 0 ? round($usedBytes / $totalBytes * 100, 2) : 0;
        $status = 'NORMAL';
        if ($pct >= 90) $status = 'CRITICAL';
        elseif ($pct >= 80) $status = 'WARNING';
        elseif ($pct >= 70) $status = 'ADVISORY';

        $gb = fn($b) => round($b / (1024 ** 3), 2);

        Http::json([
            'path' => $storagePath,
            // Bytes are the source of truth; the widget formats them (B/KB/MB/GB).
            'used_bytes' => $usedBytes,
            'available_bytes' => max($totalBytes - $usedBytes, 0),
            'total_bytes' => $totalBytes,
            // GB fields kept for backward compatibility.
            'used_gb' => $gb($usedBytes),
            'available_gb' => $gb(max($totalBytes - $usedBytes, 0)),
            'total_gb' => $gb($totalBytes),
            'percent_used' => $pct,
            'status' => $status,
            'capacity_basis' => $basis,          // 'plan-quota' | 'server-disk'
            'breakdown_bytes' => [
                'database' => $dbBytes,
                'documents' => $filesBytes,
                'backups' => $backupBytes,
            ],
            // GB breakdown kept for backward compatibility.
            'breakdown' => [
                'database' => $gb($dbBytes),
                'documents' => $gb($filesBytes),
                'backups' => $gb($backupBytes),
            ],
            'refresh_seconds' => 30,
            'simulated_capacity' => false,
            'by_organization' => [],
        ]);
    }
}
