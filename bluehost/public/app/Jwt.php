<?php
// Minimal HS256 JWT — no external dependencies (uses hash_hmac).

class Jwt
{
    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64d(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function encode(array $payload, string $secret): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $seg = [self::b64(json_encode($header)), self::b64(json_encode($payload))];
        $signing = implode('.', $seg);
        $sig = hash_hmac('sha256', $signing, $secret, true);
        $seg[] = self::b64($sig);
        return implode('.', $seg);
    }

    /** Decode & verify. Returns payload array, or null if invalid/expired. */
    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$h, $p, $s] = $parts;
        $expected = self::b64(hash_hmac('sha256', "$h.$p", $secret, true));
        if (!hash_equals($expected, $s)) return null;
        $payload = json_decode(self::b64d($p), true);
        if (!is_array($payload)) return null;
        if (isset($payload['exp']) && time() >= (int) $payload['exp']) return null;
        return $payload;
    }
}
