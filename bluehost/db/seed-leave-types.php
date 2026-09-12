<?php
// Backfill the default leave types (Vacation, Sick, Emergency, Birthday) into
// every existing organization. Idempotent — skips types that already exist by
// name. Run once:  php db/seed-leave-types.php

$appDir = null;
foreach ([__DIR__ . '/../public/app', __DIR__ . '/../app', __DIR__ . '/app'] as $c) {
    if (file_exists($c . '/Config.php')) { $appDir = $c; break; }
}
if (!$appDir) { fwrite(STDERR, "Cannot locate app/ directory near this script.\n"); exit(1); }
require $appDir . '/Config.php';
require $appDir . '/Database.php';

$defaults = [['Vacation', 15], ['Sick', 15], ['Emergency', 5], ['Birthday', 1],
    ['Bereavement', 3], ['Maternity', 105], ['Paternity', 7]];

$orgs = Database::all('SELECT id, name FROM organizations');
$added = 0;
foreach ($orgs as $o) {
    foreach ($defaults as [$name, $credits]) {
        $exists = Database::scalar('SELECT id FROM leave_types WHERE organization_id = ? AND name = ?', [(int) $o['id'], $name]);
        if (!$exists) {
            Database::insert('leave_types', ['organization_id' => (int) $o['id'], 'name' => $name, 'default_credits' => $credits, 'paid' => 1]);
            $added++;
            echo "  + {$o['name']}: $name ($credits)\n";
        }
    }
}
echo "\n[seed-leave-types] done — added $added leave type(s) across " . count($orgs) . " organization(s).\n";
if ($added === 0) echo "[seed-leave-types] all organizations already had the defaults.\n";
