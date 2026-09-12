<?php
// Minimal e-mail sender using the server MTA (cPanel mail). Best-effort — never
// throws, so a mail hiccup can't break the request that triggered it.
class Mailer
{
    public static function fromAddress(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $host = preg_replace('/:\d+$/', '', $host);
        $host = preg_replace('/^www\./', '', $host);
        return 'no-reply@' . ($host ?: 'localhost');
    }

    public static function send(string $to, string $subject, string $body): bool
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        $from = self::fromAddress();
        $headers = "From: GEEK HRIS <$from>\r\n"
            . "Reply-To: $from\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";
        try {
            return @mail($to, $subject, $body, $headers, '-f' . $from);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
