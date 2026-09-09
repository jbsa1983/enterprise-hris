<?php
class Util
{
    public static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    public static function maskAccount(?string $acct): string
    {
        if (!$acct) return '—';
        return str_repeat('*', max(strlen($acct) - 4, 0)) . substr($acct, -4);
    }
}
