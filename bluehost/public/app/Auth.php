<?php
// Authentication + authorization (JWT bearer, RBAC, org scoping).

class Auth
{
    private static ?array $user = null;

    private static function bearerToken(): ?string
    {
        $hdr = null;
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) $hdr = $_SERVER['HTTP_AUTHORIZATION'];
        elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        elseif (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
            }
        }
        if ($hdr && preg_match('/Bearer\s+(.+)/i', $hdr, $m)) return trim($m[1]);
        return null;
    }

    /** Load the authenticated user (throws 401 if missing/invalid). */
    public static function require(): array
    {
        if (self::$user !== null) return self::$user;
        $token = self::bearerToken();
        if (!$token) throw new HttpError('Not authenticated', 401);
        $payload = Jwt::decode($token, Config::get('jwt_secret'));
        if (!$payload || ($payload['type'] ?? '') !== 'access') throw new HttpError('Invalid token', 401);
        $u = Database::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $payload['sub']]);
        if (!$u) throw new HttpError('User not found', 401);

        $perms = [];
        if ((int) $u['is_superadmin'] === 1) {
            $perms = ['*'];
        } else {
            $rows = Database::all(
                'SELECT p.code FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                   JOIN user_roles ur ON ur.role_id = rp.role_id
                  WHERE ur.user_id = ?', [$u['id']]);
            $perms = array_values(array_unique(array_map(fn($r) => $r['code'], $rows)));
        }
        $orgIds = array_map('intval', array_column(
            Database::all('SELECT organization_id FROM organization_users WHERE user_id = ?', [$u['id']]),
            'organization_id'));
        $roles = array_column(
            Database::all('SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?', [$u['id']]),
            'name');

        self::$user = [
            'id' => (int) $u['id'], 'uuid' => $u['uuid'], 'email' => $u['email'],
            'full_name' => $u['full_name'], 'is_superadmin' => (int) $u['is_superadmin'] === 1,
            'person_id' => $u['person_id'] !== null ? (int) $u['person_id'] : null,
            'permissions' => $perms, 'org_ids' => $orgIds, 'roles' => $roles,
        ];
        return self::$user;
    }

    public static function has(array $user, string $perm): bool
    {
        return in_array('*', $user['permissions'], true) || in_array($perm, $user['permissions'], true);
    }

    /** Require one or more permissions (throws 403). */
    public static function requirePerm(string ...$perms): array
    {
        $u = self::require();
        foreach ($perms as $p) {
            if (!self::has($u, $p)) throw new HttpError("Missing permission: $p", 403);
        }
        return $u;
    }

    /** Verify the user can act within an organization (throws 403). */
    public static function requireOrg(array $user, int $orgId): void
    {
        if ($user['is_superadmin']) return;
        if (!in_array($orgId, $user['org_ids'], true)) {
            throw new HttpError('You do not have access to this organization', 403);
        }
    }

    public static function accessibleOrgIds(array $user): array
    {
        if ($user['is_superadmin']) {
            return array_map('intval', array_column(Database::all('SELECT id FROM organizations'), 'id'));
        }
        return $user['org_ids'];
    }
}
