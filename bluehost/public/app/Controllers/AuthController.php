<?php
class AuthController
{
    public static function routes(Router $r): void
    {
        $r->post('/auth/login', [self::class, 'login']);
        $r->post('/auth/refresh', [self::class, 'refresh']);
        $r->get('/auth/me', [self::class, 'me']);
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
