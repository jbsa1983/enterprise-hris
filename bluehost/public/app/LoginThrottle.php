<?php
// Brute-force protection for the public login endpoint.
// Tracks failed attempts per (email + client IP) and locks the pair for a
// cool-off window after too many failures. Best-effort: if the throttle table
// is missing (migration not yet run), it fails open so logins still work.
class LoginThrottle
{
    const MAX_FAILS = 5;      // failures allowed before lock-out
    const WINDOW    = 900;    // seconds: failures older than this don't count
    const LOCK      = 900;    // seconds: how long the lock lasts (15 min)

    private static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
    private static function id(string $email): string
    {
        return substr(strtolower(trim($email)) . '|' . self::ip(), 0, 255);
    }

    /** Seconds until the caller may try again, or 0 if not locked. */
    public static function lockedFor(string $email): int
    {
        try {
            $row = Database::one('SELECT locked_until FROM login_throttle WHERE identifier = ?', [self::id($email)]);
            if ($row && $row['locked_until']) {
                $left = strtotime((string) $row['locked_until']) - time();
                return $left > 0 ? $left : 0;
            }
        } catch (\Throwable $e) { /* table missing → fail open */ }
        return 0;
    }

    /** Record a failed login. Returns seconds locked (0 if not yet locked). */
    public static function fail(string $email): int
    {
        try {
            $id = self::id($email);
            $row = Database::one('SELECT fails, first_fail_at FROM login_throttle WHERE identifier = ?', [$id]);
            $now = time();
            $withinWindow = $row && $row['first_fail_at'] && ($now - strtotime((string) $row['first_fail_at'])) <= self::WINDOW;
            $fails = $withinWindow ? ((int) $row['fails'] + 1) : 1;
            $firstFail = $withinWindow ? (string) $row['first_fail_at'] : date('Y-m-d H:i:s', $now);
            $lockedUntil = $fails >= self::MAX_FAILS ? date('Y-m-d H:i:s', $now + self::LOCK) : null;
            Database::exec(
                'INSERT INTO login_throttle (identifier, fails, first_fail_at, locked_until)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE fails = VALUES(fails), first_fail_at = VALUES(first_fail_at), locked_until = VALUES(locked_until)',
                [$id, $fails, $firstFail, $lockedUntil]);
            return $lockedUntil ? self::LOCK : 0;
        } catch (\Throwable $e) { return 0; }
    }

    /** Clear the counter after a successful login. */
    public static function clear(string $email): void
    {
        try { Database::exec('DELETE FROM login_throttle WHERE identifier = ?', [self::id($email)]); }
        catch (\Throwable $e) { /* ignore */ }
    }
}
