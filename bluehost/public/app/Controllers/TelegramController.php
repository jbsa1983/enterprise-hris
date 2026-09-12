<?php
class TelegramController
{
    public static function routes(Router $r): void
    {
        $r->post('/telegram/webhook/{secret}', [self::class, 'webhook']);   // public — Telegram calls this
        $r->get('/admin/telegram', [self::class, 'adminStatus']);
        $r->post('/admin/telegram', [self::class, 'adminSave']);
        $r->post('/admin/telegram/webhook', [self::class, 'retryWebhook']);
        $r->get('/me/telegram', [self::class, 'meStatus']);
        $r->post('/me/telegram/unlink', [self::class, 'meUnlink']);
        $r->post('/me/telegram/test', [self::class, 'meTest']);
    }

    /** Telegram delivers updates here; the secret is a path segment (never stripped like a header). */
    public static function webhook(array $p): void
    {
        $secret = Telegram::secret();
        if (!$secret || !hash_equals($secret, (string) ($p['secret'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
        $update = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $msg = $update['message'] ?? null;
        if ($msg && isset($msg['text']) && strpos($msg['text'], '/start') === 0) {
            $chatId = (string) ($msg['chat']['id'] ?? '');
            $parts = explode(' ', trim($msg['text']), 2);
            $code = trim($parts[1] ?? '');
            if ($code !== '' && $chatId !== '' && Telegram::linkChat($code, $chatId)) {
                Telegram::send($chatId, "✅ Linked! You'll now get GEEK HRIS approval alerts here.");
            } elseif ($chatId !== '') {
                Telegram::send($chatId, "Open the HRIS → My Self-Service → Security → Connect Telegram to link your account.");
            }
        }
        http_response_code(200);
        echo 'ok';
        exit;
    }

    private static function webhookUrl(): string
    {
        $host = preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        return 'https://' . $host . '/api/v1/telegram/webhook/' . Telegram::ensureSecret(); // Telegram requires https
    }

    private static function register(): array
    {
        $url = self::webhookUrl();
        [$ok, $err] = Telegram::setWebhook($url);
        $cfg = Telegram::config();
        $cfg['webhook_set'] = $ok;
        $cfg['webhook_error'] = $ok ? null : $err;
        $cfg['webhook_url'] = $url;
        Telegram::saveConfig($cfg);
        return [$ok, $err, $url];
    }

    private static function status(): array
    {
        $cfg = Telegram::config();
        return ['configured' => Telegram::configured(), 'bot_username' => $cfg['bot_username'] ?? null,
            'webhook_set' => !empty($cfg['webhook_set']), 'webhook_error' => $cfg['webhook_error'] ?? null,
            'webhook_url' => $cfg['webhook_url'] ?? null];
    }

    public static function adminStatus(): void
    {
        Auth::requirePerm('system.admin');
        Http::json(self::status());
    }

    public static function adminSave(): void
    {
        Auth::requirePerm('system.admin');
        $token = trim((string) (Http::body()['bot_token'] ?? ''));
        if ($token === '') throw new HttpError('Paste your bot token from @BotFather', 422);
        $cfg = Telegram::config();
        $cfg['bot_token'] = $token;
        Telegram::saveConfig($cfg);

        $me = Telegram::getMe();
        if (!$me || empty($me['username'])) throw new HttpError('Could not reach Telegram with that token — double-check it', 422);
        $cfg = Telegram::config();
        $cfg['bot_username'] = $me['username'];
        Telegram::saveConfig($cfg);

        self::register();
        Http::json(self::status());
    }

    public static function retryWebhook(): void
    {
        Auth::requirePerm('system.admin');
        if (!Telegram::token()) throw new HttpError('Set the bot token first', 422);
        self::register();
        Http::json(self::status());
    }

    public static function meStatus(): void
    {
        $u = Auth::require();
        $configured = Telegram::configured();
        $linked = $configured && Telegram::chatIdForUser($u['id']) !== null;
        $link_url = null;
        if ($configured && !$linked) {
            $code = Telegram::linkCode($u['id']);
            $link_url = 'https://t.me/' . Telegram::username() . '?start=' . $code;
        }
        Http::json(['configured' => $configured, 'linked' => $linked, 'link_url' => $link_url]);
    }

    public static function meUnlink(): void
    {
        $u = Auth::require();
        Telegram::unlink($u['id']);
        Http::json(['linked' => false]);
    }

    public static function meTest(): void
    {
        $u = Auth::require();
        $chat = Telegram::chatIdForUser($u['id']);
        if (!$chat) throw new HttpError('Not linked yet — connect Telegram first', 409);
        Telegram::send($chat, "🔔 Test alert from GEEK HRIS — notifications are working!");
        Http::json(['ok' => true]);
    }
}
