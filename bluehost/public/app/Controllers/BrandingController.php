<?php
// Branding / white-label: superadmin uploads a custom logo and app name.
// Config is a small JSON file on disk; the logo lives in a web-served uploads
// folder. No database table needed.
class BrandingController
{
    const ALLOWED = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'];
    const MAX_BYTES = 2097152; // 2 MB

    public static function routes(Router $r): void
    {
        $r->get('/branding', [self::class, 'get']);              // public — used by the login page
        $r->post('/admin/branding', [self::class, 'update']);   // superadmin
        $r->delete('/admin/branding/logo', [self::class, 'removeLogo']);
    }

    /** [webroot, logo dir, config json] absolute paths. */
    private static function paths(): array
    {
        $root = dirname(dirname(__DIR__)); // <docroot> (contains index.html, app/, api/)
        return [$root, $root . '/uploads/branding', $root . '/uploads/branding.json'];
    }

    private static function read(): array
    {
        [, , $json] = self::paths();
        $d = is_file($json) ? json_decode((string) file_get_contents($json), true) : null;
        return is_array($d) ? $d : [];
    }

    private static function out(array $d): array
    {
        return ['app_name' => $d['app_name'] ?? 'GEEK Group', 'logo_url' => $d['logo_path'] ?? null];
    }

    public static function get(): void
    {
        Http::json(self::out(self::read()));
    }

    public static function update(): void
    {
        Auth::requirePerm('system.admin');
        [$root, $dir, $json] = self::paths();
        $d = self::read();

        if (isset($_POST['app_name'])) {
            $d['app_name'] = trim((string) $_POST['app_name']) ?: 'GEEK Group';
        }

        if (!empty($_FILES['logo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED, true)) throw new HttpError('Logo must be PNG, JPG, WEBP, GIF, or SVG', 422);
            if (($_FILES['logo']['size'] ?? 0) > self::MAX_BYTES) throw new HttpError('Logo must be 2 MB or smaller', 422);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new HttpError('Could not create the uploads folder', 500);
            // Remove the previous logo file.
            if (!empty($d['logo_path'])) { $prev = $root . $d['logo_path']; if (is_file($prev)) @unlink($prev); }
            $fname = 'logo-' . time() . '.' . $ext;
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dir . '/' . $fname))
                throw new HttpError('Could not save the uploaded file — check folder permissions', 500);
            $d['logo_path'] = '/uploads/branding/' . $fname;
        }

        if (!is_dir(dirname($json)) && !@mkdir(dirname($json), 0755, true) && !is_dir(dirname($json)))
            throw new HttpError('Could not create the uploads folder', 500);
        file_put_contents($json, json_encode($d));
        Http::json(self::out($d));
    }

    public static function removeLogo(): void
    {
        Auth::requirePerm('system.admin');
        [$root, , $json] = self::paths();
        $d = self::read();
        if (!empty($d['logo_path'])) { $prev = $root . $d['logo_path']; if (is_file($prev)) @unlink($prev); }
        unset($d['logo_path']);
        file_put_contents($json, json_encode($d));
        Http::json(self::out($d));
    }
}
