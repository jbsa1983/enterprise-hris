<?php
// Offline license verification. Keys are signed by the vendor's private key and
// verified here with the embedded public key. A key is bound to a domain and may
// carry an expiry. No network/license server is required.
class License
{
    // Set to true in a sold package to lock the app until a valid key is entered.
    // Left false so an un-activated install (e.g. the vendor's own) runs normally.
    const ENFORCE = false;

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

    public static function status(): array
    {
        [$valid, $reason, $data] = self::verify(self::stored());
        return [
            'active' => $valid, 'reason' => $reason, 'enforced' => self::ENFORCE,
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
