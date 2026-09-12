<?php
// Authentication + authorization (JWT bearer, RBAC, org scoping).

class Auth
{
    private static ?array $user = null;

    private static function bearerToken(): ?string
    {
        $hdr = null;
        // 1. LiteSpeed/Apache usually expose the header via getallheaders().
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
            }
        }
        // 2. Some builds expose it only via apache_request_headers().
        if (!$hdr && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
            }
        }
        // 3. CGI/FCGI: it may land in $_SERVER under HTTP_AUTHORIZATION or one/more
        //    REDIRECT_ prefixes (REDIRECT_HTTP_AUTHORIZATION, REDIRECT_REDIRECT_...).
        if (!$hdr) {
            foreach ($_SERVER as $k => $v) {
                if (substr($k, -18) === 'HTTP_AUTHORIZATION') { $hdr = $v; break; }
            }
        }
        if ($hdr && preg_match('/Bearer\s+(.+)/i', (string) $hdr, $m)) return trim($m[1]);
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

    /** Controller helper: require a permission AND org access; returns [user, orgId]. */
    public static function org(array $p, string $perm): array
    {
        $u = self::requirePerm($perm);
        $orgId = (int) ($p['organization_id'] ?? 0);
        self::requireOrg($u, $orgId);
        return [$u, $orgId];
    }

    public static function accessibleOrgIds(array $user): array
    {
        if ($user['is_superadmin']) {
            return array_map('intval', array_column(Database::all('SELECT id FROM organizations'), 'id'));
        }
        return $user['org_ids'];
    }
}
