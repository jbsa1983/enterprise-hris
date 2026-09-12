<?php
class AdminController
{
    const CONSULTANT_TYPES = ['CONSULTANT_INDIVIDUAL', 'CONSULTANT_COMPANY'];
    const MIN_PW = 8;

    public static function routes(Router $r): void
    {
        $r->get('/admin/permissions', [self::class, 'permissions']);
        $r->get('/admin/roles', [self::class, 'listRoles']);
        $r->post('/admin/roles', [self::class, 'createRole']);
        $r->put('/admin/roles/{id}', [self::class, 'updateRole']);
        $r->delete('/admin/roles/{id}', [self::class, 'deleteRole']);
        $r->get('/admin/users', [self::class, 'listUsers']);
        $r->post('/admin/users', [self::class, 'createUser']);
        $r->put('/admin/users/{id}', [self::class, 'updateUser']);
        $r->post('/admin/users/{id}/password', [self::class, 'resetPassword']);
        $r->post('/admin/users/{id}/deactivate', [self::class, 'deactivate']);
        $r->post('/admin/provision-ess', [self::class, 'provisionEss']);
        $r->get('/admin/organizations', [self::class, 'allOrgs']);
        $r->post('/admin/organizations', [self::class, 'createOrganization']);
        $r->put('/admin/organizations/{id}', [self::class, 'updateOrganization']);
    }

    private static function checkPw(string $pw): void
    {
        if (strlen($pw) < self::MIN_PW) throw new HttpError('Password must be at least ' . self::MIN_PW . ' characters', 422);
    }

    public static function permissions(): void
    {
        Auth::requirePerm('system.admin');
        $rows = Database::all('SELECT code, description FROM permissions ORDER BY code');
        Http::json($rows ?: array_map(fn($c) => ['code' => $c, 'description' => Rbac::PERMISSIONS[$c]], Rbac::all()));
    }

    private static function roleOut(array $role): array
    {
        $codes = array_column(Database::all(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.code',
            [$role['id']]), 'code');
        $uc = (int) Database::scalar('SELECT COUNT(*) FROM user_roles WHERE role_id = ?', [$role['id']]);
        return ['id' => (int) $role['id'], 'name' => $role['name'], 'description' => $role['description'],
            'is_system' => (int) $role['is_system'] === 1, 'permissions' => $codes, 'user_count' => $uc];
    }

    public static function listRoles(): void
    {
        Auth::requirePerm('system.admin');
        Http::json(array_map([self::class, 'roleOut'], Database::all('SELECT * FROM roles ORDER BY name')));
    }

    private static function setRolePerms(int $roleId, array $codes): void
    {
        Database::exec('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
        foreach ($codes as $c) {
            $pid = Database::scalar('SELECT id FROM permissions WHERE code = ?', [$c]);
            if (!$pid) throw new HttpError("Unknown permission: $c", 422);
            Database::insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $pid]);
        }
    }

    public static function createRole(): void
    {
        $u = Auth::requirePerm('system.admin');
        $b = Http::body();
        if (Database::one('SELECT id FROM roles WHERE name = ?', [$b['name']])) throw new HttpError('Role name already exists', 409);
        $id = Database::insert('roles', ['name' => $b['name'], 'description' => $b['description'] ?? null, 'is_system' => 0]);
        self::setRolePerms($id, $b['permissions'] ?? []);
        Audit::record('role.create', $u, ['entity' => 'role', 'entity_id' => $id]);
        Http::json(self::roleOut(Database::one('SELECT * FROM roles WHERE id = ?', [$id])));
    }

    public static function updateRole(array $p): void
    {
        $u = Auth::requirePerm('system.admin');
        $role = Database::one('SELECT * FROM roles WHERE id = ?', [(int) $p['id']]);
        if (!$role) throw new HttpError('Role not found', 404);
        $b = Http::body();
        $upd = [];
        if (isset($b['name'])) $upd['name'] = $b['name'];
        if (array_key_exists('description', $b)) $upd['description'] = $b['description'];
        if ($upd) Database::update('roles', (int) $role['id'], $upd);
        if (isset($b['permissions'])) self::setRolePerms((int) $role['id'], $b['permissions']);
        Audit::record('role.update', $u, ['entity' => 'role', 'entity_id' => $role['id']]);
        Http::json(self::roleOut(Database::one('SELECT * FROM roles WHERE id = ?', [$role['id']])));
    }

    public static function deleteRole(array $p): void
    {
        Auth::requirePerm('system.admin');
        $role = Database::one('SELECT * FROM roles WHERE id = ?', [(int) $p['id']]);
        if (!$role) throw new HttpError('Role not found', 404);
        if ((int) $role['is_system'] === 1) throw new HttpError('System roles cannot be deleted', 409);
        if ((int) Database::scalar('SELECT COUNT(*) FROM user_roles WHERE role_id = ?', [$role['id']]) > 0)
            throw new HttpError('Reassign users before deleting this role', 409);
        Database::exec('DELETE FROM roles WHERE id = ?', [$role['id']]);
        Http::json(['deleted' => (int) $role['id']]);
    }

    private static function userOut(array $u): array
    {
        $roles = array_column(Database::all('SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?', [$u['id']]), 'name');
        $orgs = Database::all('SELECT ou.organization_id, o.name, ou.is_primary FROM organization_users ou JOIN organizations o ON o.id = ou.organization_id WHERE ou.user_id = ?', [$u['id']]);
        return ['id' => (int) $u['id'], 'uuid' => $u['uuid'], 'email' => $u['email'], 'full_name' => $u['full_name'],
            'is_active' => (int) $u['is_active'] === 1, 'is_superadmin' => (int) $u['is_superadmin'] === 1,
            'person_id' => $u['person_id'] !== null ? (int) $u['person_id'] : null, 'roles' => $roles,
            'organizations' => array_map(fn($o) => ['organization_id' => (int) $o['organization_id'], 'name' => $o['name'], 'is_primary' => (int) $o['is_primary'] === 1], $orgs)];
    }

    private static function setRoles(int $userId, array $roleIds): void
    {
        Database::exec('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
        foreach (array_unique($roleIds) as $rid) {
            if (Database::scalar('SELECT id FROM roles WHERE id = ?', [$rid]))
                Database::insert('user_roles', ['user_id' => $userId, 'role_id' => (int) $rid]);
        }
    }

    private static function setOrgs(int $userId, array $orgIds): void
    {
        Database::exec('DELETE FROM organization_users WHERE user_id = ?', [$userId]);
        $i = 0;
        foreach (array_unique($orgIds) as $oid) {
            if (Database::scalar('SELECT id FROM organizations WHERE id = ?', [$oid]))
                Database::insert('organization_users', ['organization_id' => (int) $oid, 'user_id' => $userId, 'is_primary' => $i++ === 0 ? 1 : 0]);
        }
    }

    public static function listUsers(): void
    {
        Auth::requirePerm('system.admin');
        Http::json(array_map([self::class, 'userOut'], Database::all('SELECT * FROM users ORDER BY email')));
    }

    public static function createUser(): void
    {
        $actor = Auth::requirePerm('system.admin');
        $b = Http::body();
        $email = strtolower(trim($b['email'] ?? ''));
        if (Database::one('SELECT id FROM users WHERE email = ?', [$email])) throw new HttpError('Email already exists', 409);
        self::checkPw($b['password'] ?? '');
        $id = Database::insert('users', ['uuid' => Util::uuid(), 'email' => $email, 'full_name' => $b['full_name'] ?? '',
            'hashed_password' => password_hash($b['password'], PASSWORD_BCRYPT),
            'is_active' => !empty($b['is_active']) || !isset($b['is_active']) ? 1 : 0,
            'is_superadmin' => !empty($b['is_superadmin']) ? 1 : 0, 'person_id' => $b['person_id'] ?? null]);
        self::setRoles($id, $b['role_ids'] ?? []);
        self::setOrgs($id, $b['organization_ids'] ?? []);
        Audit::record('user.create', $actor, ['entity' => 'user', 'entity_id' => $id]);
        Http::json(self::userOut(Database::one('SELECT * FROM users WHERE id = ?', [$id])));
    }

    private static function lastSuperadmin(int $excludeId): bool
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM users WHERE is_superadmin = 1 AND is_active = 1 AND id != ?', [$excludeId]) === 0;
    }

    public static function updateUser(array $p): void
    {
        $actor = Auth::requirePerm('system.admin');
        $u = Database::one('SELECT * FROM users WHERE id = ?', [(int) $p['id']]);
        if (!$u) throw new HttpError('User not found', 404);
        $b = Http::body();
        $demoting = (array_key_exists('is_superadmin', $b) && !$b['is_superadmin'] && (int) $u['is_superadmin'] === 1)
            || (array_key_exists('is_active', $b) && !$b['is_active'] && (int) $u['is_active'] === 1);
        if ($demoting && (int) $u['is_superadmin'] === 1 && self::lastSuperadmin((int) $u['id']))
            throw new HttpError('Cannot remove the last active Superadmin', 409);
        $upd = [];
        foreach (['full_name', 'is_active', 'is_superadmin', 'person_id'] as $f) {
            if (array_key_exists($f, $b)) $upd[$f] = in_array($f, ['is_active', 'is_superadmin']) ? (int) (bool) $b[$f] : $b[$f];
        }
        if (isset($b['email'])) {
            $ne = strtolower(trim($b['email']));
            if (Database::one('SELECT id FROM users WHERE email = ? AND id != ?', [$ne, $u['id']])) throw new HttpError('Email already exists', 409);
            $upd['email'] = $ne;
        }
        if ($upd) Database::update('users', (int) $u['id'], $upd);
        if (isset($b['role_ids'])) self::setRoles((int) $u['id'], $b['role_ids']);
        if (isset($b['organization_ids'])) self::setOrgs((int) $u['id'], $b['organization_ids']);
        Audit::record('user.update', $actor, ['entity' => 'user', 'entity_id' => $u['id']]);
        Http::json(self::userOut(Database::one('SELECT * FROM users WHERE id = ?', [$u['id']])));
    }

    public static function resetPassword(array $p): void
    {
        Auth::requirePerm('system.admin');
        $u = Database::one('SELECT id FROM users WHERE id = ?', [(int) $p['id']]);
        if (!$u) throw new HttpError('User not found', 404);
        $pw = Http::body()['password'] ?? '';
        self::checkPw($pw);
        Database::update('users', (int) $u['id'], ['hashed_password' => password_hash($pw, PASSWORD_BCRYPT)]);
        Http::json(['ok' => true]);
    }

    public static function deactivate(array $p): void
    {
        $actor = Auth::requirePerm('system.admin');
        $u = Database::one('SELECT * FROM users WHERE id = ?', [(int) $p['id']]);
        if (!$u) throw new HttpError('User not found', 404);
        if ((int) $u['id'] === $actor['id']) throw new HttpError('You cannot deactivate your own account', 409);
        if ((int) $u['is_superadmin'] === 1 && self::lastSuperadmin((int) $u['id'])) throw new HttpError('Cannot deactivate the last active Superadmin', 409);
        Database::update('users', (int) $u['id'], ['is_active' => 0]);
        Http::json(['ok' => true, 'is_active' => false]);
    }

    public static function provisionEss(): void
    {
        $actor = Auth::requirePerm('system.admin');
        $b = Http::body();
        $defaultPw = $b['default_password'] ?? null;
        if ($defaultPw !== null) self::checkPw($defaultPw);
        $employeeRoleId = Database::scalar('SELECT id FROM roles WHERE name = ?', ['Employee']);
        if (!$employeeRoleId) throw new HttpError('Employee role missing — reseed RBAC', 500);

        $in = implode(',', array_fill(0, count(self::CONSULTANT_TYPES), '?'));
        $sql = "SELECT * FROM engagements WHERE status = 'ACTIVE' AND engagement_type NOT IN ($in)";
        $params = self::CONSULTANT_TYPES;
        if (!empty($b['organization_id'])) { $sql .= ' AND organization_id = ?'; $params[] = (int) $b['organization_id']; }
        $engs = Database::all($sql, $params);

        $byPerson = [];
        foreach ($engs as $e) $byPerson[(int) $e['person_id']][] = (int) $e['organization_id'];

        $created = 0; $skipped = 0; $creds = [];
        foreach ($byPerson as $personId => $orgIds) {
            if (Database::one('SELECT id FROM users WHERE person_id = ?', [$personId])) { $skipped++; continue; }
            $person = Database::one('SELECT * FROM people WHERE id = ?', [$personId]);
            if (!$person) continue;
            $email = strtolower(trim($person['email'] ?? ''));
            if (!$email || Database::one('SELECT id FROM users WHERE email = ?', [$email])) {
                $empNo = Database::scalar('SELECT employee_number FROM engagements WHERE person_id = ? AND employee_number IS NOT NULL LIMIT 1', [$personId]);
                $base = 'emp' . ($empNo ?: $personId) . '@ess.local';
                $email = strtolower($base); $n = 1;
                while (Database::one('SELECT id FROM users WHERE email = ?', [$email])) { $email = str_replace('@', $n++ . '@', $base); }
            }
            $pw = $defaultPw ?: (rtrim(strtr(base64_encode(random_bytes(6)), '+/', 'ab'), '=') . 'A1!');
            $name = trim(implode(' ', array_filter([$person['first_name'], $person['middle_name'], $person['last_name'], $person['suffix']])));
            $uid = Database::insert('users', ['uuid' => Util::uuid(), 'email' => $email, 'full_name' => $name,
                'hashed_password' => password_hash($pw, PASSWORD_BCRYPT), 'is_active' => 1, 'is_superadmin' => 0, 'person_id' => $personId]);
            Database::insert('user_roles', ['user_id' => $uid, 'role_id' => $employeeRoleId]);
            $i = 0;
            foreach (array_unique($orgIds) as $oid) Database::insert('organization_users', ['organization_id' => $oid, 'user_id' => $uid, 'is_primary' => $i++ === 0 ? 1 : 0]);
            $created++;
            $creds[] = ['name' => $name, 'email' => $email, 'temp_password' => $pw];
        }
        Audit::record('ess.provision', $actor, ['entity' => 'user', 'after' => ['created' => $created, 'skipped' => $skipped]]);
        Http::json(['created' => $created, 'skipped' => $skipped, 'credentials' => $creds]);
    }

    public static function allOrgs(): void
    {
        Auth::requirePerm('system.admin');
        Http::json(array_map(fn($o) => ['id' => (int) $o['id'], 'name' => $o['name'], 'code' => $o['code'],
            'legal_name' => $o['legal_name'], 'tin' => $o['tin'], 'address' => $o['address'],
            'is_active' => (int) $o['is_active'] === 1, 'enterprise_id' => (int) $o['enterprise_id']],
            Database::all('SELECT * FROM organizations ORDER BY name')));
    }

    public static function createOrganization(): void
    {
        $actor = Auth::requirePerm('organization.manage');
        $b = Http::body();
        if (empty($b['name']) || empty($b['code'])) throw new HttpError('name and code are required', 422);
        if (Database::one('SELECT id FROM organizations WHERE code = ?', [$b['code']])) throw new HttpError('Organization code already exists', 409);
        // Attach to the given enterprise, or the first one, creating a default group if none exists.
        $entId = $b['enterprise_id'] ?? null;
        if (!$entId) {
            $ent = Database::one('SELECT id FROM enterprises ORDER BY id LIMIT 1');
            $entId = $ent ? (int) $ent['id'] : Database::insert('enterprises', ['uuid' => Util::uuid(), 'name' => 'Enterprise Group', 'code' => 'GROUP']);
        }
        $id = Database::insert('organizations', ['uuid' => Util::uuid(), 'enterprise_id' => (int) $entId, 'name' => $b['name'],
            'code' => $b['code'], 'legal_name' => $b['legal_name'] ?? null, 'tin' => $b['tin'] ?? null, 'address' => $b['address'] ?? null, 'is_active' => 1]);
        // Start every new organization with the default leave types.
        AttendanceController::ensureDefaultLeaveTypes($id);
        // Grant a non-superadmin creator access so it shows in their selector.
        if (!$actor['is_superadmin']) Database::insert('organization_users', ['organization_id' => $id, 'user_id' => $actor['id'], 'is_primary' => 0]);
        Audit::record('organization.create', $actor, ['organization_id' => $id, 'entity' => 'organization', 'entity_id' => $id, 'after' => ['code' => $b['code']]]);
        Http::json(['id' => $id, 'name' => $b['name'], 'code' => $b['code']]);
    }

    public static function updateOrganization(array $p): void
    {
        $actor = Auth::requirePerm('organization.manage');
        $org = Database::one('SELECT * FROM organizations WHERE id = ?', [(int) $p['id']]);
        if (!$org) throw new HttpError('Organization not found', 404);
        $b = Http::body();
        $u = [];
        foreach (['name', 'legal_name', 'tin', 'address'] as $f) if (isset($b[$f])) $u[$f] = $b[$f];
        if (isset($b['is_active'])) $u['is_active'] = (int) (bool) $b['is_active'];
        if ($u) Database::update('organizations', (int) $org['id'], $u);
        Audit::record('organization.update', $actor, ['organization_id' => $org['id'], 'entity' => 'organization', 'entity_id' => $org['id']]);
        Http::json(['id' => (int) $org['id'], 'name' => $b['name'] ?? $org['name'], 'is_active' => isset($b['is_active']) ? (bool) $b['is_active'] : (int) $org['is_active'] === 1]);
    }
}
