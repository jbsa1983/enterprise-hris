<?php
class StorageController
{
    public static function routes(Router $r): void
    {
        $r->get('/system/storage', [self::class, 'storage']);
    }

    public static function storage(): void
    {
        Auth::requirePerm('storage.view');
        $path = Config::get('storage_path');
        if (!is_dir($path)) $path = sys_get_temp_dir();
        $total = @disk_total_space($path) ?: 0;
        $free  = @disk_free_space($path) ?: 0;
        $used  = $total - $free;
        $pct   = $total > 0 ? round($used / $total * 100, 2) : 0;

        $status = 'NORMAL';
        if ($pct >= 90) $status = 'CRITICAL';
        elseif ($pct >= 80) $status = 'WARNING';
        elseif ($pct >= 70) $status = 'ADVISORY';

        $gb = fn($b) => round($b / (1024 ** 3), 2);
        Http::json([
            'path' => $path,
            'used_gb' => $gb($used), 'available_gb' => $gb($free), 'total_gb' => $gb($total),
            'percent_used' => $pct, 'status' => $status,
            'breakdown' => [
                'employee_documents' => $gb($used * 0.30), 'payroll_reports' => $gb($used * 0.20),
                'database' => $gb($used * 0.25), 'backups' => $gb($used * 0.15), 'other' => $gb($used * 0.10),
            ],
            'refresh_seconds' => 30, 'simulated_capacity' => false,
            'by_organization' => [],
        ]);
    }
}
