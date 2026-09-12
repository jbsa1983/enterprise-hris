<?php
class AttendanceController
{
    const COLS = ['employee_number', 'log_date', 'time_in', 'time_out', 'hours_worked', 'late_minutes', 'overtime_hours', 'status'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}';
        $r->get("$b/attendance", [self::class, 'listAttendance']);
        $r->post("$b/attendance", [self::class, 'createAttendance']);
        $r->get("$b/attendance/template", [self::class, 'template']);
        $r->get("$b/attendance/format", [self::class, 'format']);
        $r->post("$b/attendance/import", [self::class, 'import']);
        $r->post("$b/attendance/device", [self::class, 'device']);
        $r->get("$b/leave", [self::class, 'listLeave']);
        $r->post("$b/leave", [self::class, 'applyLeave']);
        $r->post("$b/leave/{id}/decision", [self::class, 'decideLeave']);
        $r->get("$b/overtime", [self::class, 'listOvertime']);
        $r->post("$b/overtime/{id}/decision", [self::class, 'decideOvertime']);
        $r->get("$b/leave-types", [self::class, 'leaveTypes']);
        $r->post("$b/leave-types", [self::class, 'createLeaveType']);
        $r->put("$b/leave-types/{id}", [self::class, 'updateLeaveType']);
        $r->delete("$b/leave-types/{id}", [self::class, 'deleteLeaveType']);
        $r->get("$b/leave-balances", [self::class, 'listLeaveBalances']);
        $r->post("$b/leave-balances", [self::class, 'setLeaveBalance']);
    }

    public static function listAttendance(array $p): void
    {
        [, $o] = Auth::org($p, 'attendance.view');
        $sql = 'SELECT id, engagement_id, log_date, hours_worked, late_minutes, overtime_hours, source, status FROM attendance_logs WHERE organization_id = ?';
        $params = [$o];
        if ($e = Http::query('engagement_id')) { $sql .= ' AND engagement_id = ?'; $params[] = (int) $e; }
        Http::json(Database::all($sql . ' ORDER BY log_date DESC LIMIT 200', $params));
    }

    public static function createAttendance(array $p): void
    {
        [, $o] = Auth::org($p, 'attendance.edit'); $b = Http::body();
        Http::json(['id' => Database::insert('attendance_logs', ['organization_id' => $o, 'engagement_id' => (int) $b['engagement_id'],
            'log_date' => $b['log_date'] ?? date('Y-m-d'), 'hours_worked' => $b['hours_worked'] ?? 8,
            'late_minutes' => $b['late_minutes'] ?? 0, 'overtime_hours' => $b['overtime_hours'] ?? 0,
            'source' => $b['source'] ?? 'MANUAL', 'status' => $b['status'] ?? 'PRESENT'])]);
    }

    public static function template(array $p): void
    {
        Auth::org($p, 'attendance.view');
        $out = implode(',', self::COLS) . "\n" . 'EMP-1000,' . date('Y-m-d') . ",08:00,17:00,8,0,0,PRESENT\n";
        Http::file($out, 'text/csv', 'attendance_template.csv');
    }

    public static function format(array $p): void
    {
        Auth::org($p, 'attendance.view');
        Http::json(['columns' => self::COLS,
            'notes' => 'One row per employee per day. Match is by employee_number. Dates YYYY-MM-DD, times HH:MM. Re-importing the same employee+date updates it.',
            'accepts' => ['CSV (.csv)', 'JSON via /attendance/device for biometric devices/APIs']]);
    }

    private static function ingest(int $orgId, array $rows): array
    {
        $map = [];
        foreach (Database::all('SELECT id, employee_number FROM engagements WHERE organization_id = ? AND employee_number IS NOT NULL', [$orgId]) as $e) $map[$e['employee_number']] = (int) $e['id'];
        $imported = 0; $updated = 0; $errors = [];
        foreach ($rows as $i => $row) {
            $emp = trim((string) ($row['employee_number'] ?? ''));
            if (!$emp || !isset($map[$emp])) { $errors[] = "row " . ($i + 1) . ": unknown employee_number '$emp'"; continue; }
            $d = trim((string) ($row['log_date'] ?? $row['date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $errors[] = "row " . ($i + 1) . ": invalid date '$d'"; continue; }
            $eid = $map[$emp];
            $data = ['hours_worked' => (float) ($row['hours_worked'] ?? 8), 'late_minutes' => (int) ($row['late_minutes'] ?? 0),
                'overtime_hours' => (float) ($row['overtime_hours'] ?? 0), 'status' => $row['status'] ?: 'PRESENT', 'source' => $row['source'] ?? 'CSV'];
            $existing = Database::one('SELECT id FROM attendance_logs WHERE engagement_id = ? AND log_date = ?', [$eid, $d]);
            if ($existing) { Database::update('attendance_logs', (int) $existing['id'], $data); $updated++; }
            else { $data['organization_id'] = $orgId; $data['engagement_id'] = $eid; $data['log_date'] = $d; Database::insert('attendance_logs', $data); $imported++; }
        }
        return ['imported' => $imported, 'updated' => $updated, 'errors' => array_slice($errors, 0, 50), 'error_count' => count($errors)];
    }

    public static function import(array $p): void
    {
        [, $o] = Auth::org($p, 'attendance.edit');
        if (empty($_FILES['file']['tmp_name'])) throw new HttpError('No file uploaded', 422);
        $name = strtolower($_FILES['file']['name'] ?? '');
        if (str_ends_with($name, '.xlsx')) throw new HttpError('Excel import needs PhpSpreadsheet; please upload CSV on this build', 422);
        $rows = [];
        if (($fh = fopen($_FILES['file']['tmp_name'], 'r')) !== false) {
            $header = fgetcsv($fh);
            if ($header) { $header = array_map(fn($h) => trim((string) $h), $header);
                while (($line = fgetcsv($fh)) !== false) { $row = []; foreach ($header as $ci => $col) $row[$col] = $line[$ci] ?? null; $rows[] = $row; } }
            fclose($fh);
        }
        Http::json(self::ingest($o, $rows));
    }

    public static function device(array $p): void
    {
        [, $o] = Auth::org($p, 'attendance.edit');
        $logs = Http::body()['logs'] ?? [];
        if (!is_array($logs)) throw new HttpError("'logs' must be a list", 422);
        Http::json(self::ingest($o, $logs));
    }

    public static function listLeave(array $p): void
    {
        [, $o] = Auth::org($p, 'leave.view');
        $sql = 'SELECT id, engagement_id, leave_type, date_from, date_to, days, status FROM leave_requests WHERE organization_id = ?';
        $params = [$o];
        if ($s = Http::query('status')) { $sql .= ' AND status = ?'; $params[] = $s; }
        Http::json(Database::all($sql . ' ORDER BY id DESC LIMIT 200', $params));
    }

    public static function applyLeave(array $p): void
    {
        [, $o] = Auth::org($p, 'leave.apply'); $b = Http::body();
        Http::json(['id' => Database::insert('leave_requests', ['organization_id' => $o, 'engagement_id' => (int) $b['engagement_id'],
            'leave_type' => $b['leave_type'] ?? 'Vacation', 'date_from' => $b['date_from'] ?? null, 'date_to' => $b['date_to'] ?? null,
            'days' => $b['days'] ?? 1, 'status' => 'PENDING']), 'status' => 'PENDING']);
    }

    public static function decideLeave(array $p): void
    {
        [$u, $o] = Auth::org($p, 'leave.approve'); $b = Http::body();
        $lr = Database::one('SELECT * FROM leave_requests WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$lr) throw new HttpError('Leave request not found', 404);
        $dec = strtoupper($b['decision'] ?? 'APPROVED');
        if (!in_array($dec, ['APPROVED', 'REJECTED'], true)) throw new HttpError('decision must be APPROVED or REJECTED', 422);
        $was = strtoupper((string) $lr['status']);
        Database::update('leave_requests', (int) $lr['id'], ['status' => $dec]);
        // Keep the leave balance in step with usage: deduct on approval, restore
        // if an approved leave is later rejected.
        $days = (float) $lr['days'];
        if ($dec === 'APPROVED' && $was !== 'APPROVED') self::adjustBalanceUsed($o, (int) $lr['engagement_id'], $lr['leave_type'], $days);
        elseif ($dec === 'REJECTED' && $was === 'APPROVED') self::adjustBalanceUsed($o, (int) $lr['engagement_id'], $lr['leave_type'], -$days);
        Audit::record('leave.decision', $u, ['organization_id' => $o, 'entity' => 'leave_request', 'entity_id' => $lr['id'], 'after' => ['status' => $dec]]);
        Http::json(['id' => (int) $lr['id'], 'status' => $dec]);
    }

    /** Move `used` on a leave balance by $delta days, creating the row if needed. */
    private static function adjustBalanceUsed(int $o, int $engagementId, string $leaveType, float $delta): void
    {
        $bal = Database::one('SELECT * FROM leave_balances WHERE organization_id = ? AND engagement_id = ? AND leave_type = ?',
            [$o, $engagementId, $leaveType]);
        if ($bal) {
            $used = max(0, (float) $bal['used'] + $delta);
            Database::update('leave_balances', (int) $bal['id'], ['used' => $used]);
        } elseif ($delta > 0) {
            $credits = (float) (Database::scalar('SELECT default_credits FROM leave_types WHERE organization_id = ? AND name = ?', [$o, $leaveType]) ?? 0);
            Database::insert('leave_balances', ['organization_id' => $o, 'engagement_id' => $engagementId,
                'leave_type' => $leaveType, 'credits' => $credits, 'used' => $delta]);
        }
    }

    public static function createLeaveType(array $p): void
    {
        [, $o] = Auth::org($p, 'leave.approve'); $b = Http::body();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') throw new HttpError('Leave type name is required', 422);
        $id = Database::insert('leave_types', ['organization_id' => $o, 'name' => $name,
            'default_credits' => (float) ($b['default_credits'] ?? 0), 'paid' => isset($b['paid']) ? (int) (bool) $b['paid'] : 1]);
        Http::json(['id' => $id]);
    }

    public static function updateLeaveType(array $p): void
    {
        [, $o] = Auth::org($p, 'leave.approve'); $b = Http::body();
        $t = Database::one('SELECT id FROM leave_types WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$t) throw new HttpError('Leave type not found', 404);
        $data = [];
        if (isset($b['name'])) $data['name'] = $b['name'];
        if (isset($b['default_credits'])) $data['default_credits'] = (float) $b['default_credits'];
        if (isset($b['paid'])) $data['paid'] = (int) (bool) $b['paid'];
        if ($data) Database::update('leave_types', (int) $t['id'], $data);
        Http::json(['id' => (int) $t['id']]);
    }

    public static function deleteLeaveType(array $p): void
    {
        [, $o] = Auth::org($p, 'leave.approve');
        Database::exec('DELETE FROM leave_types WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        Http::json(['deleted' => (int) $p['id']]);
    }

    /** Leave balances for one engagement — every leave type, with remaining computed. */
    public static function listLeaveBalances(array $p): void
    {
        [, $o] = Auth::org($p, 'leave.view');
        $eng = (int) Http::query('engagement_id');
        $types = Database::all('SELECT name, default_credits FROM leave_types WHERE organization_id = ? ORDER BY name', [$o]);
        $bal = [];
        foreach (Database::all('SELECT leave_type, credits, used FROM leave_balances WHERE organization_id = ? AND engagement_id = ?', [$o, $eng]) as $b) {
            $bal[$b['leave_type']] = $b;
        }
        $out = [];
        foreach ($types as $t) {
            $row = $bal[$t['name']] ?? null;
            $credits = $row ? (float) $row['credits'] : (float) $t['default_credits'];
            $used = $row ? (float) $row['used'] : 0;
            $out[] = ['leave_type' => $t['name'], 'credits' => $credits, 'used' => $used, 'remaining' => $credits - $used];
            unset($bal[$t['name']]);
        }
        // Any balance whose type was deleted still shows.
        foreach ($bal as $name => $row) {
            $out[] = ['leave_type' => $name, 'credits' => (float) $row['credits'], 'used' => (float) $row['used'], 'remaining' => (float) $row['credits'] - (float) $row['used']];
        }
        Http::json($out);
    }

    public static function setLeaveBalance(array $p): void
    {
        [, $o] = Auth::org($p, 'leave.approve'); $b = Http::body();
        $eng = (int) ($b['engagement_id'] ?? 0);
        $type = trim((string) ($b['leave_type'] ?? ''));
        if (!$eng || $type === '') throw new HttpError('engagement_id and leave_type are required', 422);
        $credits = (float) ($b['credits'] ?? 0);
        $existing = Database::one('SELECT id, used FROM leave_balances WHERE organization_id = ? AND engagement_id = ? AND leave_type = ?', [$o, $eng, $type]);
        if ($existing) {
            $data = ['credits' => $credits];
            if (isset($b['used'])) $data['used'] = (float) $b['used'];
            Database::update('leave_balances', (int) $existing['id'], $data);
        } else {
            Database::insert('leave_balances', ['organization_id' => $o, 'engagement_id' => $eng, 'leave_type' => $type,
                'credits' => $credits, 'used' => (float) ($b['used'] ?? 0)]);
        }
        Http::json(['ok' => true]);
    }

    public static function listOvertime(array $p): void
    {
        [, $o] = Auth::org($p, 'attendance.view');
        $sql = 'SELECT id, engagement_id, ot_date, hours, status FROM overtime_requests WHERE organization_id = ?';
        $params = [$o];
        if ($s = Http::query('status')) { $sql .= ' AND status = ?'; $params[] = $s; }
        Http::json(Database::all($sql . ' ORDER BY id DESC LIMIT 200', $params));
    }

    public static function decideOvertime(array $p): void
    {
        [, $o] = Auth::org($p, 'attendance.approve'); $b = Http::body();
        $ot = Database::one('SELECT * FROM overtime_requests WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$ot) throw new HttpError('Overtime request not found', 404);
        Database::update('overtime_requests', (int) $ot['id'], ['status' => strtoupper($b['decision'] ?? 'APPROVED')]);
        Http::json(['id' => (int) $ot['id'], 'status' => strtoupper($b['decision'] ?? 'APPROVED')]);
    }

    public static function leaveTypes(array $p): void
    {
        [, $o] = Auth::org($p, 'leave.view');
        Http::json(array_map(fn($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'default_credits' => (float) $t['default_credits'], 'paid' => (int) $t['paid'] === 1],
            Database::all('SELECT * FROM leave_types WHERE organization_id = ?', [$o])));
    }
}
