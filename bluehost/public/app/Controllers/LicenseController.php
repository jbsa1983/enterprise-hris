<?php
// License status + activation (offline signed keys).
class LicenseController
{
    public static function routes(Router $r): void
    {
        $r->get('/license', [self::class, 'status']);            // public — for the activation screen / gate
        $r->post('/admin/license', [self::class, 'activate']);   // superadmin
        $r->delete('/admin/license', [self::class, 'deactivate']);
    }

    public static function status(): void
    {
        Http::json(License::status());
    }

    public static function activate(): void
    {
        Auth::requirePerm('system.admin');
        $key = trim((string) (Http::body()['key'] ?? ''));
        if ($key === '') throw new HttpError('Paste a license key', 422);
        [$valid, $reason] = License::verify($key);
        if (!$valid) throw new HttpError('License not valid — ' . $reason, 422);
        License::save($key);
        Http::json(License::status());
    }

    public static function deactivate(): void
    {
        Auth::requirePerm('system.admin');
        License::clear();
        Http::json(License::status());
    }
}
