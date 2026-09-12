<?php
class DigestController
{
    public static function routes(Router $r): void
    {
        $r->get('/admin/digest', [self::class, 'get']);
        $r->post('/admin/digest', [self::class, 'save']);
        $r->post('/admin/digest/run-now', [self::class, 'runNow']);
    }

    private static function payload(): array
    {
        $cfg = Digest::config();
        $php = '/usr/local/bin/php';
        return [
            'enabled' => (bool) $cfg['enabled'], 'time' => $cfg['time'], 'last_run' => $cfg['last_run'],
            'cron_command' => "0 * * * * $php " . dirname(dirname(__DIR__)) . "/cron/digest.php >/dev/null 2>&1",
        ];
    }

    public static function get(): void
    {
        Auth::requirePerm('system.admin');
        Http::json(self::payload());
    }

    public static function save(): void
    {
        Auth::requirePerm('system.admin');
        $b = Http::body();
        $cfg = Digest::config();
        $cfg['enabled'] = !empty($b['enabled']);
        if (isset($b['time']) && preg_match('/^\d{1,2}:\d{2}$/', (string) $b['time'])) $cfg['time'] = $b['time'];
        Digest::save($cfg);
        Http::json(self::payload());
    }

    public static function runNow(): void
    {
        Auth::requirePerm('system.admin');
        @set_time_limit(0);
        $result = Digest::run(true);
        Http::json(['ok' => true, 'result' => trim($result)]);
    }
}
