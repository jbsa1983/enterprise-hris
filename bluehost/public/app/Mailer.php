<?php
// E-mail sender. Uses SMTP when configured (Administration → Notifications),
// otherwise falls back to the server MTA (PHP mail()). Best-effort: send() never
// throws, but records the last error for the "Send test" button.
class Mailer
{
    public static ?string $lastError = null;

    private static function configFile(): string { return __DIR__ . '/mail-config.php'; }

    public static function config(): array
    {
        $f = self::configFile();
        $v = is_file($f) ? (@include $f) : null;
        return is_array($v) ? $v : [];
    }
    public static function saveConfig(array $cfg): void
    {
        file_put_contents(self::configFile(), "<?php return " . var_export($cfg, true) . ";\n");
    }

    private static function host(): string
    {
        $h = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $h = preg_replace('/:\d+$/', '', $h);
        return preg_replace('/^www\./', '', $h) ?: 'localhost';
    }
    public static function fromAddress(): string
    {
        $cfg = self::config();
        return !empty($cfg['from_email']) ? $cfg['from_email'] : 'no-reply@' . self::host();
    }
    private static function fromName(): string
    {
        $cfg = self::config();
        return !empty($cfg['from_name']) ? $cfg['from_name'] : 'GEEK HRIS';
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[\x80-\xFF]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    public static function send(string $to, string $subject, string $body): bool
    {
        self::$lastError = null;
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { self::$lastError = 'Invalid recipient'; return false; }
        $cfg = self::config();
        if (($cfg['transport'] ?? 'mail') === 'smtp' && !empty($cfg['host'])) {
            return self::sendSmtp($cfg, $to, $subject, $body);
        }
        // PHP mail() fallback
        $from = self::fromAddress();
        $headers = "From: " . self::encodeHeader(self::fromName()) . " <$from>\r\n"
            . "Reply-To: $from\r\n" . "MIME-Version: 1.0\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
        try {
            $ok = @mail($to, self::encodeHeader($subject), $body, $headers, '-f' . $from);
            if (!$ok) self::$lastError = 'PHP mail() returned false (server mail may be disabled)';
            return $ok;
        } catch (\Throwable $e) { self::$lastError = $e->getMessage(); return false; }
    }

    private static function sendSmtp(array $cfg, string $to, string $subject, string $body): bool
    {
        $host = (string) $cfg['host'];
        $port = (int) ($cfg['port'] ?? 587);
        $secure = $cfg['secure'] ?? 'tls'; // tls (STARTTLS) | ssl (SMTPS) | none
        $user = (string) ($cfg['username'] ?? '');
        $pass = (string) ($cfg['password'] ?? '');
        $from = self::fromAddress();

        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $dsn = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($dsn, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) { self::$lastError = "Connect failed: $errstr"; return false; }
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 600)) !== false) { $data .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
            return $data;
        };
        $cmd = function ($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
        $ok = fn($r, $codes) => in_array((int) substr($r, 0, 3), (array) $codes, true);
        $fail = function ($m, $r = '') use ($fp) { self::$lastError = trim($m . ' ' . preg_replace('/\s+/', ' ', $r)); @fclose($fp); return false; };

        $read(); // greeting
        $ehlo = preg_replace('/[^a-zA-Z0-9.\-]/', '', self::host()) ?: 'localhost';
        $cmd("EHLO $ehlo");
        if ($secure === 'tls') {
            $r = $cmd("STARTTLS");
            if (!$ok($r, 220)) return $fail('STARTTLS refused:', $r);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return $fail('TLS negotiation failed');
            $cmd("EHLO $ehlo");
        }
        if ($user !== '') {
            if (!$ok($cmd("AUTH LOGIN"), 334)) return $fail('Server did not accept AUTH LOGIN');
            if (!$ok($cmd(base64_encode($user)), 334)) return $fail('Username not accepted');
            $r = $cmd(base64_encode($pass));
            if (!$ok($r, 235)) return $fail('Login failed (check username/password / app password):', $r);
        }
        if (!$ok($cmd("MAIL FROM:<$from>"), 250)) return $fail('MAIL FROM rejected');
        if (!$ok($cmd("RCPT TO:<$to>"), [250, 251])) return $fail('Recipient rejected');
        if (!$ok($cmd("DATA"), 354)) return $fail('DATA command rejected');

        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . self::host() . '>';
        $headers = "From: " . self::encodeHeader(self::fromName()) . " <$from>\r\n"
            . "To: <$to>\r\n" . "Reply-To: $from\r\n" . "Subject: " . self::encodeHeader($subject) . "\r\n"
            . "Date: " . date('r') . "\r\n" . "Message-ID: $messageId\r\n" . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";
        $data = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body));
        $data = preg_replace('/^\./m', '..', $data);   // dot-stuffing
        $r = $cmd($headers . "\r\n" . $data . "\r\n.");
        if (!$ok($r, 250)) return $fail('Message not accepted:', $r);
        $cmd("QUIT");
        @fclose($fp);
        return true;
    }
}
