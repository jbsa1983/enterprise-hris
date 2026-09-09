<?php
// Loads config.php (your real settings) or falls back to config.sample.php.

class Config
{
    private static ?array $data = null;

    public static function all(): array
    {
        if (self::$data === null) {
            $real = __DIR__ . '/settings.php';
            $sample = __DIR__ . '/settings.sample.php';
            self::$data = require (file_exists($real) ? $real : $sample);
        }
        return self::$data;
    }

    public static function get(string $key, $default = null)
    {
        return self::all()[$key] ?? $default;
    }
}
