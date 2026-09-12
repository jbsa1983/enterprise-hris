<?php
// Telegram bot notifications. Config (bot token) is stored in app/telegram-config.php
// (protected, not web-readable). Per-user chat ids live in telegram_links.
class Telegram
{
    private static function configFile(): string { return __DIR__ . '/telegram-config.php'; }

    public static function config(): array
    {
        $f = self::configFile();
        if (!is_file($f)) return [];
        $v = @include $f;
        return is_array($v) ? $v : [];
    }
    public static function saveConfig(array $cfg): void
    {
        file_put_contents(self::configFile(), "<?php return " . var_export($cfg, true) . ";\n");
    }
    public static function token(): ?string { return self::config()['bot_token'] ?? null; }
    public static function username(): ?string { return self::config()['bot_username'] ?? null; }
    public static function secret(): ?string { return self::config()['webhook_secret'] ?? null; }
    public static function configured(): bool { return !empty(self::token()) && !empty(self::username()); }

    private static function api(string $method, array $params = []): ?array
    {
        $token = self::token();
        if (!$token) return null;
        $url = "https://api.telegram.org/bot{$token}/{$method}";
        $data = http_build_query($params);
        $resp = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $data, CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => true]);
            $resp = curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => $data, 'timeout' => 12]]);
            $resp = @file_get_contents($url, false, $ctx);
        }
        if (!$resp) return null;
        $j = json_decode($resp, true);
        return is_array($j) ? $j : null;
    }

    public static function getMe(): ?array
    {
        $r = self::api('getMe');
        return ($r && !empty($r['ok'])) ? $r['result'] : null;
    }

    /** [ok(bool), error(?string)]. The URL carries the secret in its path. */
    public static function setWebhook(string $url): array
    {
        $r = self::api('setWebhook', ['url' => $url, 'allowed_updates' => json_encode(['message'])]);
        if ($r && !empty($r['ok'])) return [true, null];
        return [false, $r['description'] ?? 'No response from Telegram — check the server can make outbound HTTPS calls (cURL).'];
    }

    /** Ensure a webhook secret exists (persisted). */
    public static function ensureSecret(): string
    {
        $cfg = self::config();
        if (empty($cfg['webhook_secret'])) { $cfg['webhook_secret'] = bin2hex(random_bytes(16)); self::saveConfig($cfg); }
        return $cfg['webhook_secret'];
    }

    public static function send(string $chatId, string $text): bool
    {
        $r = self::api('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => 'true']);
        return $r && !empty($r['ok']);
    }

    public static function chatIdForUser(int $userId): ?string
    {
        try { $c = Database::scalar('SELECT chat_id FROM telegram_links WHERE user_id = ?', [$userId]); return $c ?: null; }
        catch (\Throwable $e) { return null; }
    }

    /** Get (or create) the user's deep-link code. */
    public static function linkCode(int $userId): string
    {
        try {
            $row = Database::one('SELECT link_code FROM telegram_links WHERE user_id = ?', [$userId]);
            if ($row && !empty($row['link_code'])) return $row['link_code'];
            $code = bin2hex(random_bytes(8));
            if ($row) Database::exec('UPDATE telegram_links SET link_code = ? WHERE user_id = ?', [$code, $userId]);
            else Database::insert('telegram_links', ['user_id' => $userId, 'link_code' => $code]);
            return $code;
        } catch (\Throwable $e) { return ''; }
    }

    public static function linkChat(string $code, string $chatId): bool
    {
        try {
            $row = Database::one('SELECT user_id FROM telegram_links WHERE link_code = ?', [$code]);
            if (!$row) return false;
            Database::exec('UPDATE telegram_links SET chat_id = ? WHERE user_id = ?', [$chatId, (int) $row['user_id']]);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    public static function unlink(int $userId): void
    {
        try { Database::exec('UPDATE telegram_links SET chat_id = NULL WHERE user_id = ?', [$userId]); } catch (\Throwable $e) {}
    }

    /** Best-effort: notify a user by Telegram if a bot is configured and they linked. */
    public static function notifyUser(int $userId, string $text): void
    {
        if (!self::configured()) return;
        $chat = self::chatIdForUser($userId);
        if ($chat) self::send($chat, $text);
    }
}
