<?php
// Seeder — run from CLI:  php db/seed.php [--minimal]
//   default  : RBAC + Superadmin + statutory rules + a small demo dataset
//   --minimal: RBAC + Superadmin + statutory rules only (for real deployments)
//
// Idempotent: does nothing if users already exist.

// Locate the app/ directory whether db/ sits beside public/ (repo layout) or
// inside public_html next to app/ (typical cPanel upload).
$appDir = null;
foreach ([__DIR__ . '/../public/app', __DIR__ . '/../app', __DIR__ . '/app'] as $c) {
    if (file_exists($c . '/Config.php')) { $appDir = $c; break; }
}
if (!$appDir) { fwrite(STDERR, "Cannot locate app/ directory near the seeder.\n"); exit(1); }
require $appDir . '/Config.php';
require $appDir . '/Database.php';
require $appDir . '/Rbac.php';

function uuid(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

$minimal = in_array('--minimal', $argv, true);
$pdo = Database::pdo();

if ((int) Database::scalar('SELECT COUNT(*) FROM users') > 0) {
    echo "[seed] users already exist — skipping.\n";
    exit(0);
}

$pdo->beginTransaction();

// --- RBAC --------------------------------------------------------------------
$permId = [];
foreach (Rbac::PERMISSIONS as $code => $desc) {
    $permId[$code] = Database::insert('permissions', ['code' => $code, 'description' => $desc]);
}
$roleId = [];
foreach (Rbac::roleMap() as $role => $codes) {
    $rid = Database::insert('roles', ['name' => $role, 'description' => "Default role: $role", 'is_system' => 1]);
    $roleId[$role] = $rid;
    foreach ($codes as $c) {
        if (isset($permId[$c])) Database::insert('role_permissions', ['role_id' => $rid, 'permission_id' => $permId[$c]]);
    }
}
echo "[seed] RBAC: " . count($permId) . " permissions, " . count($roleId) . " roles\n";

// --- Superadmin --------------------------------------------------------------
$adminEmail = getenv('DEFAULT_ADMIN_EMAIL') ?: 'admin@demo-hris.local';
$adminPass  = getenv('DEFAULT_ADMIN_PASSWORD') ?: 'Admin123!';
$adminId = Database::insert('users', [
    'uuid' => uuid(), 'email' => strtolower($adminEmail), 'full_name' => 'System Administrator',
    'hashed_password' => password_hash($adminPass, PASSWORD_BCRYPT), 'is_active' => 1, 'is_superadmin' => 1,
]);
Database::insert('user_roles', ['user_id' => $adminId, 'role_id' => $roleId['Super Admin']]);
echo "[seed] Superadmin: $adminEmail\n";

// --- Statutory rules (illustrative prototype values) -------------------------
$stat = [
    ['SSS', ['employee_rate' => 0.045, 'msc_cap' => 30000]],
    ['PHIC', ['employee_rate' => 0.025, 'salary_cap' => 100000, 'floor' => 10000]],
    ['HDMF', ['employee_rate' => 0.02, 'contribution_cap' => 200]],
    ['BIR', ['brackets' => [[0, 0, 0], [20833, 0, 0.15], [33333, 1875, 0.20], [66667, 8541.80, 0.25],
        [166667, 33541.80, 0.30], [666667, 183541.80, 0.35]]]],
];
foreach ($stat as [$name, $params]) {
    Database::insert('statutory_rule_sets', [
        'rule_name' => $name, 'rule_version' => 'PROTO-2024.1', 'effective_from' => '2024-01-01',
        'is_prototype_data' => 1, 'parameters_json' => json_encode($params),
        'notes' => 'Prototype value — validate before real payroll.',
    ]);
}
echo "[seed] statutory rule sets (PROTOTYPE)\n";

if ($minimal) {
    $pdo->commit();
    echo "[seed] minimal seed complete.\n";
    exit(0);
}

// --- Demo dataset ------------------------------------------------------------
$entId = Database::insert('enterprises', ['uuid' => uuid(), 'name' => 'Demo Enterprise Group', 'code' => 'DEMO']);
$orgs = [];
foreach ([['Exigent Corporation', 'EXG'], ['GreatnessLab', 'GLB']] as [$n, $c]) {
    $orgs[] = Database::insert('organizations', ['uuid' => uuid(), 'enterprise_id' => $entId,
        'name' => $n, 'code' => $c, 'legal_name' => $n, 'address' => 'Metro Manila']);
}
foreach ($orgs as $oid) {
    Database::insert('organization_users', ['organization_id' => $oid, 'user_id' => $adminId, 'is_primary' => 0]);
}

$first = ['Juan', 'Maria', 'Jose', 'Anna', 'Pedro', 'Liza', 'Mark', 'Grace', 'Paolo', 'Nadine', 'Miguel', 'Sofia'];
$last = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Ramos', 'Aquino'];
$types = ['REGULAR', 'REGULAR', 'PROBATIONARY', 'PROJECT_BASED', 'CONSULTANT_INDIVIDUAL'];

function stat_get($name) { return json_decode(Database::scalar('SELECT parameters_json FROM statutory_rule_sets WHERE rule_name=? LIMIT 1', [$name]), true); }
$sss = stat_get('SSS'); $phic = stat_get('PHIC'); $hdmf = stat_get('HDMF'); $bir = stat_get('BIR');

$empNo = 1000;
foreach ($orgs as $oi => $oid) {
    $deptId = Database::insert('departments', ['organization_id' => $oid, 'name' => 'Operations', 'code' => 'OPS']);
    $posId = Database::insert('positions', ['organization_id' => $oid, 'title' => 'Associate', 'job_grade' => 'P2', 'department_id' => $deptId]);
    $period = Database::insert('payroll_periods', ['organization_id' => $oid, 'name' => date('F Y'),
        'frequency' => 'MONTHLY', 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t'), 'pay_date' => date('Y-m-t')]);
    $run = Database::insert('payroll_runs', ['uuid' => uuid(), 'organization_id' => $oid, 'period_id' => $period,
        'reference' => 'RUN-' . date('Ym'), 'status' => 'APPROVED',
        'rule_version_snapshot' => json_encode(['SSS' => 'PROTO-2024.1', 'PHIC' => 'PROTO-2024.1', 'HDMF' => 'PROTO-2024.1', 'BIR' => 'PROTO-2024.1'])]);
    $gt = $dt = $nt = 0.0;
    for ($i = 0; $i < 10; $i++) {
        $pid = Database::insert('people', ['uuid' => uuid(),
            'first_name' => $first[array_rand($first)], 'last_name' => $last[array_rand($last)],
            'email' => 'demo' . ($empNo) . '@example.com',
            'sss_number' => '34-' . rand(1000000, 9999999) . '-1',
            'bank_name' => 'BDO', 'bank_account_number' => (string) rand(1000000000, 9999999999)]);
        $type = $types[$i % count($types)];
        $isCon = str_starts_with($type, 'CONSULTANT');
        $base = [22000, 26000, 30000, 38000, 45000][$i % 5];
        $eid = Database::insert('engagements', ['uuid' => uuid(), 'person_id' => $pid, 'organization_id' => $oid,
            'engagement_type' => $type, 'employee_number' => ($isCon ? 'CON-' : 'EMP-') . (++$empNo),
            'salary_basis' => 'MONTHLY', 'base_rate' => $base, 'department_id' => $deptId, 'position_id' => $posId,
            'start_date' => date('Y-m-d', strtotime('-1 year')), 'status' => 'ACTIVE']);

        // simple compute
        $earn = ['basic' => $base, 'allowance' => $isCon ? 0 : 2000];
        $gross = array_sum($earn);
        if ($isCon) {
            $ded = ['withholding_tax_ewt' => round($gross * 0.10, 2)];
        } else {
            $s = round(min($base, $sss['msc_cap']) * $sss['employee_rate'], 2);
            $ph = round(max(min($base, $phic['salary_cap']), $phic['floor']) * $phic['employee_rate'], 2);
            $hd = round(min($base * $hdmf['employee_rate'], $hdmf['contribution_cap']), 2);
            $taxable = max($gross - ($s + $ph + $hd), 0);
            $tax = 0.0;
            foreach ($bir['brackets'] as [$lo, $b, $rate]) if ($taxable > $lo) $tax = $b + ($taxable - $lo) * $rate;
            $ded = ['sss' => $s, 'philhealth' => $ph, 'pagibig' => $hd, 'withholding_tax' => round(max($tax, 0), 2)];
        }
        $totalDed = round(array_sum($ded), 2);
        $net = round($gross - $totalDed, 2);
        Database::insert('payroll_run_people', ['run_id' => $run, 'engagement_id' => $eid, 'gross_pay' => $gross,
            'total_deductions' => $totalDed, 'net_pay' => $net, 'earnings' => json_encode($earn), 'deductions' => json_encode($ded)]);
        $gt += $gross; $dt += $totalDed; $nt += $net;

        if ($i < 3) Database::insert('leave_requests', ['organization_id' => $oid, 'engagement_id' => $eid,
            'leave_type' => 'Vacation', 'date_from' => date('Y-m-d', strtotime('+5 days')),
            'date_to' => date('Y-m-d', strtotime('+6 days')), 'days' => 2, 'status' => 'PENDING']);
    }
    Database::update('payroll_runs', $run, ['gross_total' => round($gt, 2), 'deduction_total' => round($dt, 2), 'net_total' => round($nt, 2)]);
}
$pdo->commit();
echo "[seed] demo data committed (2 orgs, ~20 people, payroll).\n";
