<?php
// Access/refresh token issuance.

class Tokens
{
    public static function access(int $userId, string $email): string
    {
        $now = time();
        return Jwt::encode([
            'sub' => (string) $userId, 'type' => 'access', 'email' => $email,
            'iat' => $now, 'exp' => $now + (int) Config::get('access_ttl', 1800),
        ], Config::get('jwt_secret'));
    }

    public static function refresh(int $userId): string
    {
        $now = time();
        return Jwt::encode([
            'sub' => (string) $userId, 'type' => 'refresh',
            'iat' => $now, 'exp' => $now + (int) Config::get('refresh_ttl', 604800),
        ], Config::get('jwt_secret'));
    }
}
