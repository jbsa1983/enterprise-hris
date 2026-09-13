<?php
// Superadmin outbound e-mail (SMTP) settings.
class MailController
{
    public static function routes(Router $r): void
    {
        $r->get('/admin/mail', [self::class, 'get']);
        $r->post('/admin/mail', [self::class, 'save']);
        $r->post('/admin/mail/test', [self::class, 'test']);
    }

    /** Never returns the stored password — only whether one is set. */
    private static function out(array $cfg): array
    {
        return [
            'transport' => $cfg['transport'] ?? 'mail',
            'host' => $cfg['host'] ?? '', 'port' => (int) ($cfg['port'] ?? 587),
            'secure' => $cfg['secure'] ?? 'tls', 'username' => $cfg['username'] ?? '',
            'from_email' => $cfg['from_email'] ?? '', 'from_name' => $cfg['from_name'] ?? 'GEEK HRIS',
            'has_password' => !empty($cfg['password']),
        ];
    }

    public static function get(): void
    {
        Auth::requirePerm('system.admin');
        Http::json(self::out(Mailer::config()));
    }

    public static function save(): void
    {
        Auth::requirePerm('system.admin');
        $b = Http::body();
        $cfg = Mailer::config();
        $cfg['transport'] = (($b['transport'] ?? '') === 'smtp') ? 'smtp' : 'mail';
        foreach (['host', 'secure', 'username', 'from_email', 'from_name'] as $f) {
            if (array_key_exists($f, $b)) $cfg[$f] = trim((string) $b[$f]);
        }
        if (isset($b['port'])) $cfg['port'] = (int) $b['port'];
        // Only overwrite the password when a new one is supplied.
        if (array_key_exists('password', $b) && (string) $b['password'] !== '') $cfg['password'] = (string) $b['password'];
        Mailer::saveConfig($cfg);
        Http::json(self::out($cfg));
    }

    public static function test(): void
    {
        $u = Auth::requirePerm('system.admin');
        $b = Http::body();
        // Save first so the test uses the latest settings.
        if (!empty($b)) self::applyIncoming($b);
        $to = trim((string) ($b['to'] ?? $u['email']));
        $ok = Mailer::send($to, 'GEEK HRIS test email', "This is a test email from your GEEK HRIS installation.\nIf you received it, outbound email is working.");
        if (!$ok) throw new HttpError('Could not send: ' . (Mailer::$lastError ?: 'unknown error'), 502);
        Http::json(['ok' => true, 'to' => $to]);
    }

    private static function applyIncoming(array $b): void
    {
        $cfg = Mailer::config();
        $cfg['transport'] = (($b['transport'] ?? '') === 'smtp') ? 'smtp' : 'mail';
        foreach (['host', 'secure', 'username', 'from_email', 'from_name'] as $f) {
            if (array_key_exists($f, $b)) $cfg[$f] = trim((string) $b[$f]);
        }
        if (isset($b['port'])) $cfg['port'] = (int) $b['port'];
        if (array_key_exists('password', $b) && (string) $b['password'] !== '') $cfg['password'] = (string) $b['password'];
        Mailer::saveConfig($cfg);
    }
}
