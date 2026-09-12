<?php
// Daily digest: once a day, notify each org's approvers of everything still
// pending their approval. Config in app/digest-config.php (protected).
class Digest
{
    private static function file(): string { return __DIR__ . '/digest-config.php'; }

    public static function config(): array
    {
        $f = self::file();
        $d = is_file($f) ? (@include $f) : null;
        return (is_array($d) ? $d : []) + ['enabled' => false, 'time' => '08:00', 'last_run' => null];
    }
    public static function save(array $cfg): void
    {
        file_put_contents(self::file(), "<?php return " . var_export($cfg, true) . ";\n");
    }

    /** Called by cron. Sends once per day at or after the configured time. */
    public static function run(bool $force = false): string
    {
        $cfg = self::config();
        if (!$force) {
            if (empty($cfg['enabled'])) return "digest: disabled\n";
            $today = date('Y-m-d');
            if (($cfg['last_run'] ?? '') === $today) return "digest: already sent today\n";
            if (time() < strtotime($today . ' ' . ($cfg['time'] ?? '08:00'))) return "digest: not due yet\n";
        }
        $sent = self::send();
        if (!$force) { $cfg['last_run'] = date('Y-m-d'); self::save($cfg); }
        return "digest: notified {$sent} approver(s)\n";
    }

    private static function send(): int
    {
        $sent = 0;
        foreach (Database::all('SELECT id, name FROM organizations') as $org) {
            $o = (int) $org['id'];
            $leave = (int) Database::scalar("SELECT COUNT(*) FROM leave_requests WHERE organization_id = ? AND status = 'PENDING'", [$o]);
            $ot    = (int) Database::scalar("SELECT COUNT(*) FROM overtime_requests WHERE organization_id = ? AND status = 'PENDING'", [$o]);
            $loans = (int) Database::scalar("SELECT COUNT(*) FROM loans WHERE organization_id = ? AND status = 'PENDING'", [$o]);
            if ($leave + $ot + $loans === 0) continue;

            $approvers = Database::all(
                "SELECT DISTINCT u.id, u.email, u.full_name
                   FROM users u
                   JOIN organization_users ou ON ou.user_id = u.id AND ou.organization_id = ?
                   JOIN user_roles ur ON ur.user_id = u.id
                   JOIN role_permissions rp ON rp.role_id = ur.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE u.is_active = 1 AND p.code IN ('leave.approve','attendance.approve','loan.approve')", [$o]);
            if (!$approvers) continue;

            $lines = [];
            if ($leave) $lines[] = "• {$leave} leave request(s)";
            if ($ot)    $lines[] = "• {$ot} overtime request(s)";
            if ($loans) $lines[] = "• {$loans} loan/advance request(s)";
            $subject = "Pending approvals in {$org['name']}";
            $body = "{$subject}:\n" . implode("\n", $lines) . "\n\nLog in to review and act on them.";
            foreach ($approvers as $a) {
                Mailer::send($a['email'], $subject, $body);
                Telegram::notifyUser((int) $a['id'], "🗒 {$subject}\n" . implode("\n", $lines));
                $sent++;
            }
        }
        return $sent;
    }
}
