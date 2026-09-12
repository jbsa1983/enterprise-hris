<?php
// Sync the RBAC catalog (permissions + role→permission grants) into an existing
// database, without wiping anything. Run once after updating app/Rbac.php:
//
//   php db/sync-rbac.php
//
// Idempotent and additive: it inserts permissions/roles/grants that are missing
// and leaves every existing role, permission, and custom grant untouched.

$appDir = null;
foreach ([__DIR__ . '/../public/app', __DIR__ . '/../app', __DIR__ . '/app'] as $c) {
    if (file_exists($c . '/Config.php')) { $appDir = $c; break; }
}
if (!$appDir) { fwrite(STDERR, "Cannot locate app/ directory near this script.\n"); exit(1); }
require $appDir . '/Config.php';
require $appDir . '/Database.php';
require $appDir . '/Rbac.php';

$added = ['perm' => 0, 'role' => 0, 'grant' => 0];

// 1) Permissions.
$permId = [];
foreach (Rbac::PERMISSIONS as $code => $desc) {
    $id = Database::scalar('SELECT id FROM permissions WHERE code = ?', [$code]);
    if (!$id) { $id = Database::insert('permissions', ['code' => $code, 'description' => $desc]); $added['perm']++; echo "  + permission  $code\n"; }
    $permId[$code] = (int) $id;
}

// 2) Roles + grants.
foreach (Rbac::roleMap() as $role => $codes) {
    $rid = Database::scalar('SELECT id FROM roles WHERE name = ?', [$role]);
    if (!$rid) { $rid = Database::insert('roles', ['name' => $role, 'description' => "Default role: $role", 'is_system' => 1]); $added['role']++; echo "  + role        $role\n"; }
    $rid = (int) $rid;
    foreach ($codes as $c) {
        if (!isset($permId[$c])) continue;
        $has = Database::scalar('SELECT id FROM role_permissions WHERE role_id = ? AND permission_id = ?', [$rid, $permId[$c]]);
        if (!$has) { Database::insert('role_permissions', ['role_id' => $rid, 'permission_id' => $permId[$c]]); $added['grant']++; echo "  + grant       $role -> $c\n"; }
    }
}

echo "\n[sync-rbac] done — added {$added['perm']} permission(s), {$added['role']} role(s), {$added['grant']} grant(s).\n";
if (array_sum($added) === 0) echo "[sync-rbac] everything was already in place.\n";
