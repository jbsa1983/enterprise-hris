<?php
// Offline license verification. Keys are signed by the vendor's private key and
// verified here with the embedded public key. A key is bound to a domain and may
// carry an expiry. No network/license server is required.
class License
{
    // Set to true in a sold package to lock the app until a valid key is entered.
    // Left false so an un-activated install (e.g. the vendor's own) runs normally.
    const ENFORCE = false;

    // Built-in free trial: a fresh install (no license ever entered) runs for this
    // many days, then locks until a key is activated. Only applies when ENFORCE is on.
    const TRIAL_DAYS = 14;

    // Vendor public key — safe to ship. Only the matching private key can mint keys.
    const PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAxpUYPHVaVQk0UKjVX9cx
yKZaSPpu5G1Yz1n3nU+ncMOv1CDQDbjFMZhKVKwJCjmo5pWJ6TRMgrUjZKvBn32s
41+ua4KZ+bwQGyrAO+77SqlhH8cO6Dy82Dp+Jw9Zo2W5hEFKzZLaQOeyIpe7ipd1
4hpE6QqscBQCbzEM5LAfOP4Mlf7Adocwry/TqVdJ6XapUboRidEeAfRTkvDQIEBv
K7yQgQcmS5I3QeA726C8gvMUCAEK/YAxtlntH9pmoGhIJek4LHmD6j3R4HKhtq/C
jUPqKLqkjX4etPgfa7e/1zrHP2v2EgAfsMCBmmyIEJk2fcki1CvfwLoDdeWh62/p
zwIDAQAB
-----END PUBLIC KEY-----
";

    private static function file(): string { return __DIR__ . '/installed-license.php'; }

    public static function stored(): ?string
    {
        $f = self::file();
        if (!is_file($f)) return null;
        $v = @include $f;
        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function save(string $key): void
    {
        file_put_contents(self::file(), "<?php return " . var_export(trim($key), true) . ";\n");
    }

    public static function clear(): void { @unlink(self::file()); }

    /** The domain this site is served on, normalized (no port, no www). */
    public static function currentHost(): string
    {
        $h = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $h = preg_replace('/:\d+$/', '', $h);
        return preg_replace('/^www\./', '', $h);
    }

    private static function b64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /** [valid(bool), reason(string), data(array|null)]. */
    public static function verify(?string $key): array
    {
        if (!$key) return [false, 'No license installed', null];
        $parts = explode('.', trim($key));
        if (count($parts) !== 3 || $parts[0] !== 'GEEKHRIS') return [false, 'Malformed license key', null];
        [, $p, $s] = $parts;
        $ok = @openssl_verify($p, self::b64urlDecode($s), self::PUBLIC_KEY, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) return [false, 'Invalid signature', null];
        $data = json_decode(self::b64urlDecode($p), true);
        if (!is_array($data)) return [false, 'Unreadable license payload', null];

        $host = self::currentHost();
        $licDomain = strtolower((string) ($data['domain'] ?? ''));
        if ($licDomain !== '' && $licDomain !== $host && !in_array($host, ['localhost', '127.0.0.1', ''], true)) {
            return [false, "This key is licensed to {$licDomain}, but the site is {$host}", $data];
        }
        if (!empty($data['expires']) && strtotime((string) $data['expires']) < strtotime(date('Y-m-d'))) {
            return [false, 'License expired on ' . $data['expires'], $data];
        }
        return [true, 'Active', $data];
    }

    private static function trialFile(): string { return __DIR__ . '/trial.php'; }

    public static function trialStart(): ?string
    {
        $f = self::trialFile();
        if (!is_file($f)) return null;
        $v = @include $f;
        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function beginTrial(): string
    {
        $d = date('Y-m-d');
        @file_put_contents(self::trialFile(), "<?php return " . var_export($d, true) . ";\n");
        return $d;
    }

    /** Free-trial state. Pass $begin=true to start the clock on first run. */
    public static function trial(bool $begin = false): array
    {
        $start = self::trialStart();
        if ($start === null) {
            if (!$begin) return ['started' => null, 'active' => false, 'expired' => false, 'days_left' => 0, 'ends' => null];
            $start = self::beginTrial();
        }
        $endTs = strtotime($start . ' 00:00:00') + self::TRIAL_DAYS * 86400;
        $daysLeft = (int) ceil(($endTs - time()) / 86400);
        return [
            'started' => $start, 'ends' => date('Y-m-d', $endTs),
            'active' => $daysLeft > 0, 'expired' => $daysLeft <= 0, 'days_left' => max($daysLeft, 0),
        ];
    }

    public static function status(): array
    {
        [$valid, $reason, $data] = self::verify(self::stored());
        $mode = 'unlicensed';
        $trialDaysLeft = 0; $trialEnds = null;

        if ($valid) {
            $mode = 'licensed';
        } elseif (self::ENFORCE) {
            if (self::stored() !== null) {
                // A license was installed but is no longer valid (e.g. expired) —
                // this is a past customer, so don't fall back to a fresh trial.
                $mode = 'unlicensed';
            } else {
                // Brand-new install, no key yet → run the free trial.
                $t = self::trial(true);
                $trialEnds = $t['ends'];
                if ($t['active']) { $mode = 'trial'; $trialDaysLeft = $t['days_left']; }
                else { $mode = 'trial_expired'; }
            }
        }

        $active = ($mode === 'licensed' || $mode === 'trial');
        return [
            'active' => $active,
            'licensed' => $valid,
            'mode' => $mode,
            'reason' => $valid ? $reason
                : ($mode === 'trial' ? "Free trial — $trialDaysLeft day" . ($trialDaysLeft === 1 ? '' : 's') . " left"
                : ($mode === 'trial_expired' ? 'Free trial ended' : $reason)),
            'enforced' => self::ENFORCE,
            'trial_days_left' => $trialDaysLeft,
            'trial_ends' => $trialEnds,
            'customer' => $data['customer'] ?? null, 'domain' => $data['domain'] ?? null,
            'edition' => $data['edition'] ?? null, 'expires' => $data['expires'] ?? null,
            'max_users' => isset($data['max']) ? (int) $data['max'] : null,
            'host' => self::currentHost(),
        ];
    }

    /** Licensed employee cap from an ACTIVE license; 0 means unlimited / not capped. */
    public static function maxUsers(): int
    {
        [$valid, , $data] = self::verify(self::stored());
        return $valid && !empty($data['max']) ? (int) $data['max'] : 0;
    }
}
