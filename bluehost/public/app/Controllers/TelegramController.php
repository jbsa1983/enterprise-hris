<?php
class TelegramController
{
    public static function routes(Router $r): void
    {
        $r->post('/telegram/webhook', [self::class, 'webhook']);   // public — Telegram calls this
        $r->get('/admin/telegram', [self::class, 'adminStatus']);
        $r->post('/admin/telegram', [self::class, 'adminSave']);
        $r->get('/me/telegram', [self::class, 'meStatus']);
        $r->post('/me/telegram/unlink', [self::class, 'meUnlink']);
        $r->post('/me/telegram/test', [self::class, 'meTest']);
    }

    /** Telegram pushes updates here. Handles "/start <code>" to link a chat. */
    public static function webhook(): void
    {
        $secret = Telegram::secret();
        $got = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
        if (!$secret || !hash_equals($secret, (string) $got)) { http_response_code(403); echo 'forbidden'; exit; }
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

    public static function adminStatus(): void
    {
        Auth::requirePerm('system.admin');
        $cfg = Telegram::config();
        Http::json(['configured' => Telegram::configured(), 'bot_username' => $cfg['bot_username'] ?? null,
            'webhook_set' => !empty($cfg['webhook_set'])]);
    }

    public static function adminSave(): void
    {
        Auth::requirePerm('system.admin');
        $token = trim((string) (Http::body()['bot_token'] ?? ''));
        if ($token === '') throw new HttpError('Paste your bot token from @BotFather', 422);
        $cfg = Telegram::config();
        $cfg['bot_token'] = $token;
        if (empty($cfg['webhook_secret'])) $cfg['webhook_secret'] = bin2hex(random_bytes(16));
        Telegram::saveConfig($cfg);

        $me = Telegram::getMe();
        if (!$me || empty($me['username'])) throw new HttpError('Could not reach Telegram with that token — double-check it', 422);
        $cfg['bot_username'] = $me['username'];

        $host = $_SERVER['HTTP_HOST'] ?? '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $cfg['webhook_set'] = Telegram::setWebhook("$scheme://$host/api/v1/telegram/webhook", $cfg['webhook_secret']);
        Telegram::saveConfig($cfg);

        Http::json(['configured' => true, 'bot_username' => $cfg['bot_username'], 'webhook_set' => $cfg['webhook_set']]);
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
