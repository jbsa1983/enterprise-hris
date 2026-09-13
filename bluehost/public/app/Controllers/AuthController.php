<?php
class AuthController
{
    public static function routes(Router $r): void
    {
        $r->post('/auth/login', [self::class, 'login']);
        $r->post('/auth/refresh', [self::class, 'refresh']);
        $r->get('/auth/me', [self::class, 'me']);
        $r->post('/auth/forgot', [self::class, 'forgot']);
        $r->post('/auth/reset', [self::class, 'reset']);
    }

    /** Send a password-reset link. Always responds OK (never reveals if the email exists). */
    public static function forgot(): void
    {
        $email = strtolower(trim((string) (Http::body()['email'] ?? '')));
        if ($email !== '') {
            $u = Database::one('SELECT id, email, full_name, hashed_password FROM users WHERE email = ? AND is_active = 1', [$email]);
            if ($u) {
                $token = Jwt::encode([
                    'sub' => (string) $u['id'], 'type' => 'reset',
                    'pwv' => substr(sha1($u['hashed_password']), 0, 12), // invalidates the link once the password changes
                    'iat' => time(), 'exp' => time() + 3600,
                ], Config::get('jwt_secret'));
                $host = $_SERVER['HTTP_HOST'] ?? '';
                $link = "https://$host/reset?token=" . urlencode($token);
                Mailer::send($u['email'], 'Reset your GEEK HRIS password',
                    "Hi {$u['full_name']},\n\nWe received a request to reset your password. Open the link below to set a new one (valid for 1 hour):\n\n$link\n\nIf you didn't request this, just ignore this email — your password stays the same.");
            }
        }
        Http::json(['ok' => true]);
    }

    public static function reset(): void
    {
        $b = Http::body();
        $token = (string) ($b['token'] ?? '');
        $new = (string) ($b['new_password'] ?? '');
        if (strlen($new) < 8) throw new HttpError('New password must be at least 8 characters', 422);
        $payload = Jwt::decode($token, Config::get('jwt_secret'));
        if (!$payload || ($payload['type'] ?? '') !== 'reset') throw new HttpError('This reset link is invalid or has expired', 400);
        $u = Database::one('SELECT id, hashed_password FROM users WHERE id = ? AND is_active = 1', [(int) $payload['sub']]);
        if (!$u) throw new HttpError('Account not found', 404);
        if (($payload['pwv'] ?? '') !== substr(sha1($u['hashed_password']), 0, 12)) {
            throw new HttpError('This reset link has already been used or has expired. Request a new one.', 400);
        }
        Database::update('users', (int) $u['id'], ['hashed_password' => password_hash($new, PASSWORD_BCRYPT)]);
        Http::json(['ok' => true]);
    }

    public static function login(): void
    {
        $b = Http::body();
        $email = strtolower(trim($b['email'] ?? ''));
        $password = $b['password'] ?? '';
        $u = Database::one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$u || !password_verify($password, $u['hashed_password'])) {
            Http::error('Invalid email or password', 401);
        }
        if ((int) $u['is_active'] !== 1) Http::error('Account is disabled', 403);
        Audit::record('auth.login', ['id' => $u['id'], 'email' => $u['email']],
            ['entity' => 'user', 'entity_id' => $u['id']]);
        Http::json([
            'access_token' => Tokens::access((int) $u['id'], $u['email']),
            'refresh_token' => Tokens::refresh((int) $u['id']),
            'token_type' => 'bearer',
        ]);
    }

    public static function refresh(): void
    {
        $b = Http::body();
        $payload = Jwt::decode($b['refresh_token'] ?? '', Config::get('jwt_secret'));
        if (!$payload || ($payload['type'] ?? '') !== 'refresh') Http::error('Invalid refresh token', 401);
        $u = Database::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $payload['sub']]);
        if (!$u) Http::error('User not found', 401);
        Http::json([
            'access_token' => Tokens::access((int) $u['id'], $u['email']),
            'refresh_token' => Tokens::refresh((int) $u['id']),
            'token_type' => 'bearer',
        ]);
    }

    public static function me(): void
    {
        $u = Auth::require();
        $orgs = Database::all(
            'SELECT ou.organization_id, o.name, o.code, ou.is_primary
               FROM organization_users ou JOIN organizations o ON o.id = ou.organization_id
              WHERE ou.user_id = ?', [$u['id']]);
        Http::json([
            'id' => $u['id'], 'uuid' => $u['uuid'], 'email' => $u['email'], 'full_name' => $u['full_name'],
            'is_superadmin' => $u['is_superadmin'], 'roles' => $u['roles'],
            'permissions' => $u['permissions'],
            'organizations' => array_map(fn($o) => [
                'organization_id' => (int) $o['organization_id'], 'name' => $o['name'],
                'code' => $o['code'], 'is_primary' => (int) $o['is_primary'] === 1,
            ], $orgs),
        ]);
    }
}
