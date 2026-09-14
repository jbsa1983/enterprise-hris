<?php
class PeopleController
{
    const CONSULTANT_TYPES = ['CONSULTANT_INDIVIDUAL', 'CONSULTANT_COMPANY'];
    const ENG_TYPES = ['REGULAR', 'PROBATIONARY', 'PROJECT_BASED', 'FIXED_TERM', 'DAILY_PAID', 'HOURLY_PAID',
        'PART_TIME', 'CONSULTANT_INDIVIDUAL', 'CONSULTANT_COMPANY', 'CONTRACTOR', 'OJT', 'TRAINEE'];

    const PERSON_FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix', 'preferred_name', 'birth_date',
        'gender', 'civil_status', 'email', 'mobile', 'address', 'emergency_contact', 'tin', 'sss_number',
        'philhealth_number', 'pagibig_number', 'bank_name', 'bank_account_number', 'bank_account_name'];
    const ENG_FIELDS = ['engagement_type', 'employee_number', 'start_date', 'end_date', 'regularization_date',
        'department_id', 'position_id', 'job_grade', 'salary_basis', 'base_rate', 'payroll_group', 'cost_center',
        'project_id', 'work_site', 'status', 'ewt_rate', 'hdmf_extra', 'hdmf_mp2'];

    // Columns for the bulk-import CSV template (order matters).
    const IMPORT_HEADERS = ['first_name', 'middle_name', 'last_name', 'suffix', 'birth_date', 'gender', 'civil_status',
        'email', 'mobile', 'address', 'tin', 'sss_number', 'philhealth_number', 'pagibig_number',
        'bank_name', 'bank_account_number', 'bank_account_name',
        'employee_number', 'engagement_type', 'department', 'position', 'job_grade', 'salary_basis', 'base_rate', 'start_date', 'status'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}';
        $r->get("$b/people", [self::class, 'people']);
        $r->get("$b/employees", [self::class, 'employees']);
        $r->get("$b/consultants", [self::class, 'consultants']);
        $r->post("$b/people/create", [self::class, 'create']);
        $r->get("$b/people/template", [self::class, 'template']);
        $r->post("$b/people/import", [self::class, 'importPeople']);
        $r->get("$b/people/{engagement_id}", [self::class, 'detail']);
        $r->put("$b/people/{engagement_id}", [self::class, 'update']);
        $r->post("$b/people/bulk-delete", [self::class, 'bulkDestroy']);
        $r->post("$b/people/bulk-transfer", [self::class, 'bulkTransfer']);
        $r->post("$b/people/bulk-reclassify", [self::class, 'bulkReclassify']);
        $r->post("$b/people/{engagement_id}/archive", [self::class, 'archive']);
        $r->delete("$b/people/{engagement_id}", [self::class, 'destroy']);
    }

    private static function pick(array $src, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) if (array_key_exists($f, $src) && $src[$f] !== null && $src[$f] !== '') $out[$f] = $src[$f];
        return $out;
    }

    public static function create(array $p): void
    {
        [$user, $orgId] = Auth::org($p, 'employee.create');
        // Licensed edition may cap the number of active employees.
        $max = License::maxUsers();
        if ($max > 0) {
            $active = (int) Database::scalar("SELECT COUNT(DISTINCT person_id) FROM engagements WHERE status = 'ACTIVE'");
            if ($active >= $max) {
                throw new HttpError("You've reached your plan's limit of {$max} active employees. Please upgrade your plan to add more.", 403);
            }
        }
        $b = Http::body();
        $eng = $b['engagement'] ?? [];
        $person = self::pick($b, self::PERSON_FIELDS);
        if (empty($person['first_name']) || empty($person['last_name'])) throw new HttpError('first_name and last_name are required', 422);
        $person['uuid'] = Util::uuid();
        $person['status'] = 'ACTIVE';
        $pid = Database::insert('people', $person);
        $engData = self::pick($eng, self::ENG_FIELDS);
        $engData['uuid'] = Util::uuid();
        $engData['person_id'] = $pid;
        $engData['organization_id'] = $orgId;
        if (empty($engData['status'])) $engData['status'] = 'ACTIVE';
        $eid = Database::insert('engagements', $engData);
        Audit::record('employee.create', $user, ['organization_id' => $orgId, 'entity' => 'person', 'entity_id' => $pid]);
        Http::json(['person_id' => $pid, 'engagement_id' => $eid]);
    }

    /** Download a CSV template (opens in Excel) with all importable columns + one example row. */
    public static function template(array $p): void
    {
        Auth::org($p, 'employee.view');
        $example = ['Juan', 'Cruz', 'Dela Cruz', '', '1995-06-15', 'Male', 'Single',
            'juan@company.com', '0917 000 0000', 'Metro Manila', '123-456-789-000', '34-1234567-8', '01-234567890-1', '1234-5678-9012',
            'BDO', '001234567890', 'Juan Dela Cruz',
            'EMP-1001', 'REGULAR', 'Operations', 'Staff', 'G3', 'MONTHLY', '25000', date('Y-m-d'), 'ACTIVE'];
        $q = fn($v) => '"' . str_replace('"', '""', (string) $v) . '"';
        $out = implode(',', array_map($q, self::IMPORT_HEADERS)) . "\n" . implode(',', array_map($q, $example)) . "\n";
        Http::file($out, 'text/csv', 'employees_template.csv');
    }

    /** Bulk-create employees from an uploaded CSV. */
    public static function importPeople(array $p): void
    {
        [$user, $o] = Auth::org($p, 'employee.create');
        if (empty($_FILES['file']['tmp_name'])) throw new HttpError('No file uploaded', 422);
        if (str_ends_with(strtolower($_FILES['file']['name'] ?? ''), '.xlsx'))
            throw new HttpError('Please save the file as CSV and upload the .csv version', 422);

        $rows = [];
        if (($fh = fopen($_FILES['file']['tmp_name'], 'r')) !== false) {
            $header = fgetcsv($fh);
            if ($header) {
                $header = array_map(fn($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $h))), $header);
                while (($line = fgetcsv($fh)) !== false) {
                    if (count(array_filter($line, fn($x) => trim((string) $x) !== '')) === 0) continue;
                    $row = [];
                    foreach ($header as $ci => $col) $row[$col] = isset($line[$ci]) ? trim((string) $line[$ci]) : '';
                    $rows[] = $row;
                }
            }
            fclose($fh);
        }
        if (!$rows) throw new HttpError('No data rows found in the file', 422);

        $max = License::maxUsers();
        $active = $max > 0 ? (int) Database::scalar("SELECT COUNT(DISTINCT person_id) FROM engagements WHERE status = 'ACTIVE'") : 0;

        $deptMap = []; foreach (Database::all('SELECT id, LOWER(name) n FROM departments WHERE organization_id = ?', [$o]) as $d) $deptMap[$d['n']] = (int) $d['id'];
        $posMap = [];  foreach (Database::all('SELECT id, LOWER(title) t FROM positions WHERE organization_id = ?', [$o]) as $q) $posMap[$q['t']] = (int) $q['id'];

        $imported = 0; $skipped = 0; $errors = []; $rowNo = 1;
        foreach ($rows as $r) {
            $rowNo++;
            if (($r['first_name'] ?? '') === '' || ($r['last_name'] ?? '') === '') {
                $errors[] = "Row $rowNo: first_name and last_name are required"; $skipped++; continue;
            }
            if ($max > 0 && $active >= $max) {
                $errors[] = "Row $rowNo onward: plan limit of $max active employees reached — remaining rows skipped"; $skipped++; break;
            }
            $person = ['uuid' => Util::uuid(), 'status' => 'ACTIVE'];
            foreach (self::PERSON_FIELDS as $f) if (!empty($r[$f])) $person[$f] = $r[$f];
            $pid = Database::insert('people', $person);

            $eng = ['uuid' => Util::uuid(), 'person_id' => $pid, 'organization_id' => $o,
                'engagement_type' => ($r['engagement_type'] ?? '') ?: 'REGULAR', 'status' => ($r['status'] ?? '') ?: 'ACTIVE'];
            foreach (['employee_number', 'job_grade', 'salary_basis', 'start_date'] as $f) if (!empty($r[$f])) $eng[$f] = $r[$f];
            if (!empty($r['base_rate']) && is_numeric($r['base_rate'])) $eng['base_rate'] = (float) $r['base_rate'];
            if (!empty($r['department']) && isset($deptMap[strtolower($r['department'])])) $eng['department_id'] = $deptMap[strtolower($r['department'])];
            if (!empty($r['position']) && isset($posMap[strtolower($r['position'])])) $eng['position_id'] = $posMap[strtolower($r['position'])];
            Database::insert('engagements', $eng);

            $imported++; if ($max > 0) $active++;
        }
        Audit::record('employee.import', $user, ['organization_id' => $o, 'entity' => 'engagement', 'after' => ['imported' => $imported]]);
        Http::json(['imported' => $imported, 'skipped' => $skipped, 'error_count' => count($errors), 'errors' => array_slice($errors, 0, 50)]);
    }

    private static function detailArr(array $eng, array $person): array
    {
        $e = [];
        foreach (self::ENG_FIELDS as $f) $e[$f] = $eng[$f] ?? null;
        $pp = [];
        foreach (self::PERSON_FIELDS as $f) $pp[$f] = $person[$f] ?? null;
        return ['engagement_id' => (int) $eng['id'], 'person_id' => (int) $person['id'], 'person' => $pp, 'engagement' => $e];
    }

    public static function detail(array $p): void
    {
        [, $orgId] = Auth::org($p, 'employee.view');
        $eng = Database::one('SELECT * FROM engagements WHERE id = ? AND organization_id = ?', [(int) $p['engagement_id'], $orgId]);
        if (!$eng) throw new HttpError('Engagement not found', 404);
        $person = Database::one('SELECT * FROM people WHERE id = ?', [$eng['person_id']]);
        Http::json(self::detailArr($eng, $person));
    }

    public static function update(array $p): void
    {
        [$user, $orgId] = Auth::org($p, 'employee.edit');
        $eng = Database::one('SELECT * FROM engagements WHERE id = ? AND organization_id = ?', [(int) $p['engagement_id'], $orgId]);
        if (!$eng) throw new HttpError('Engagement not found', 404);
        $person = Database::one('SELECT * FROM people WHERE id = ?', [$eng['person_id']]);
        $b = Http::body();
        if (!empty($b['person'])) Database::update('people', (int) $person['id'], self::pick($b['person'], self::PERSON_FIELDS));
        if (!empty($b['engagement'])) Database::update('engagements', (int) $eng['id'], self::pick($b['engagement'], self::ENG_FIELDS));
        Audit::record('employee.edit', $user, ['organization_id' => $orgId, 'entity' => 'engagement', 'entity_id' => $eng['id']]);
        $eng = Database::one('SELECT * FROM engagements WHERE id = ?', [$eng['id']]);
        $person = Database::one('SELECT * FROM people WHERE id = ?', [$person['id']]);
        Http::json(self::detailArr($eng, $person));
    }

    public static function archive(array $p): void
    {
        [$user, $orgId] = Auth::org($p, 'employee.archive');
        $eng = Database::one('SELECT * FROM engagements WHERE id = ? AND organization_id = ?', [(int) $p['engagement_id'], $orgId]);
        if (!$eng) throw new HttpError('Engagement not found', 404);
        $status = Http::body()['status'] ?? 'SEPARATED';
        Database::update('engagements', (int) $eng['id'], ['status' => $status]);
        Audit::record('employee.archive', $user, ['organization_id' => $orgId, 'entity' => 'engagement', 'entity_id' => $eng['id'], 'after' => ['status' => $status]]);
        Http::json(['engagement_id' => (int) $eng['id'], 'status' => $status]);
    }

    private static function hasPayrollHistory(int $engId): bool
    {
        return ((int) Database::scalar('SELECT COUNT(*) FROM payroll_run_people WHERE engagement_id = ?', [$engId])
            + (int) Database::scalar('SELECT COUNT(*) FROM payslips WHERE engagement_id = ?', [$engId])) > 0;
    }

    /** Cascade-delete one engagement (payroll guard assumed already passed). Runs its
     *  own transaction; returns whether the person record was removed too. */
    private static function cascadeDelete(array $eng): bool
    {
        $engId = (int) $eng['id'];
        $personId = (int) $eng['person_id'];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $loanIds = array_column(Database::all('SELECT id FROM loans WHERE engagement_id = ?', [$engId]), 'id');
            foreach ($loanIds as $lid) Database::exec('DELETE FROM loan_transactions WHERE loan_id = ?', [(int) $lid]);
            Database::exec('DELETE FROM loans WHERE engagement_id = ?', [$engId]);
            foreach (['project_assignments', 'attendance_logs', 'leave_requests', 'overtime_requests', 'leave_balances',
                      'special_pay_lines', 'performance_reviews', 'training_assignments', 'service_tickets', 'lifecycle_checklists'] as $t) {
                Database::exec("DELETE FROM `$t` WHERE engagement_id = ?", [$engId]);
            }
            Database::exec('DELETE FROM engagements WHERE id = ?', [$engId]);

            $personDeleted = false;
            if ((int) Database::scalar('SELECT COUNT(*) FROM engagements WHERE person_id = ?', [$personId]) === 0) {
                $benIds = array_column(Database::all('SELECT id FROM benefits WHERE person_id = ?', [$personId]), 'id');
                foreach ($benIds as $bid) Database::exec('DELETE FROM benefit_beneficiaries WHERE benefit_id = ?', [(int) $bid]);
                Database::exec('DELETE FROM benefits WHERE person_id = ?', [$personId]);
                $pLoanIds = array_column(Database::all('SELECT id FROM loans WHERE person_id = ?', [$personId]), 'id');
                foreach ($pLoanIds as $lid) Database::exec('DELETE FROM loan_transactions WHERE loan_id = ?', [(int) $lid]);
                Database::exec('DELETE FROM loans WHERE person_id = ?', [$personId]);
                Database::exec('UPDATE assets SET assigned_person_id = NULL WHERE assigned_person_id = ?', [$personId]);
                Database::exec('DELETE FROM asset_events WHERE person_id = ?', [$personId]);
                $docs = Database::all('SELECT object_key FROM employee_documents WHERE person_id = ?', [$personId]);
                $base = dirname(dirname(__DIR__)) . '/storage/documents/';
                foreach ($docs as $d) { $f = $base . $d['object_key']; if (is_file($f)) @unlink($f); }
                Database::exec('DELETE FROM employee_documents WHERE person_id = ?', [$personId]);
                Database::exec('UPDATE users SET person_id = NULL WHERE person_id = ?', [$personId]);
                Database::exec('DELETE FROM people WHERE id = ?', [$personId]);
                $personDeleted = true;
            }
            $pdo->commit();
            return $personDeleted;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Permanently delete one engagement. Superadmin only; refuses on payroll history. */
    public static function destroy(array $p): void
    {
        $user = Auth::require();
        if (!$user['is_superadmin']) throw new HttpError('Only a superadmin can permanently delete records. Use Archive instead.', 403);
        $orgId = (int) ($p['organization_id'] ?? 0);
        $eng = Database::one('SELECT * FROM engagements WHERE id = ? AND organization_id = ?', [(int) $p['engagement_id'], $orgId]);
        if (!$eng) throw new HttpError('Engagement not found', 404);
        if (self::hasPayrollHistory((int) $eng['id'])) {
            throw new HttpError('This person has payroll history and cannot be deleted — archive them instead to keep the records intact.', 409);
        }
        $person = Database::one('SELECT first_name, last_name FROM people WHERE id = ?', [(int) $eng['person_id']]);
        try { $personDeleted = self::cascadeDelete($eng); }
        catch (\Throwable $e) { throw new HttpError('Could not delete this record: ' . $e->getMessage(), 500); }
        Audit::record('employee.delete', $user, ['organization_id' => $orgId, 'entity' => 'engagement', 'entity_id' => (int) $eng['id'],
            'before' => ['person_id' => (int) $eng['person_id'], 'employee_number' => $eng['employee_number'],
                'name' => $person ? trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')) : null]]);
        Http::json(['ok' => true, 'engagement_id' => (int) $eng['id'], 'person_deleted' => $personDeleted]);
    }

    /** Delete several engagements at once. Superadmin only. Skips (does not fail on)
     *  anyone with payroll history and reports them back. */
    public static function bulkDestroy(array $p): void
    {
        $user = Auth::require();
        if (!$user['is_superadmin']) throw new HttpError('Only a superadmin can permanently delete records. Use Archive instead.', 403);
        $orgId = (int) ($p['organization_id'] ?? 0);
        $ids = array_values(array_unique(array_map('intval', (array) (Http::body()['engagement_ids'] ?? []))));
        if (!$ids) throw new HttpError('No records selected', 422);
        $deleted = []; $skipped = [];
        foreach ($ids as $engId) {
            $eng = Database::one('SELECT * FROM engagements WHERE id = ? AND organization_id = ?', [$engId, $orgId]);
            if (!$eng) { $skipped[] = ['engagement_id' => $engId, 'reason' => 'not found']; continue; }
            if (self::hasPayrollHistory($engId)) { $skipped[] = ['engagement_id' => $engId, 'reason' => 'has payroll history — archive instead']; continue; }
            try { self::cascadeDelete($eng); $deleted[] = $engId; }
            catch (\Throwable $e) { $skipped[] = ['engagement_id' => $engId, 'reason' => 'error: ' . $e->getMessage()]; }
        }
        Audit::record('employee.bulk_delete', $user, ['organization_id' => $orgId, 'entity' => 'engagement',
            'after' => ['deleted' => count($deleted), 'skipped' => count($skipped)]]);
        Http::json(['deleted' => $deleted, 'skipped' => $skipped]);
    }

    /** Reclassify selected engagements — e.g. move an employee to Consultant or back.
     *  Just changes engagement_type; keeps the person and all their records. */
    public static function bulkReclassify(array $p): void
    {
        [$user, $orgId] = Auth::org($p, 'employee.edit');
        $body = Http::body();
        $type = (string) ($body['engagement_type'] ?? '');
        if (!in_array($type, self::ENG_TYPES, true)) throw new HttpError('Unknown engagement type', 422);
        $ids = array_values(array_unique(array_map('intval', (array) ($body['engagement_ids'] ?? []))));
        if (!$ids) throw new HttpError('No records selected', 422);
        $updated = 0;
        foreach ($ids as $engId) {
            if (!Database::one('SELECT id FROM engagements WHERE id = ? AND organization_id = ?', [$engId, $orgId])) continue;
            Database::update('engagements', $engId, ['engagement_type' => $type]);
            $updated++;
        }
        Audit::record('employee.reclassify', $user, ['organization_id' => $orgId, 'entity' => 'engagement',
            'after' => ['engagement_type' => $type, 'count' => $updated]]);
        Http::json(['updated' => $updated, 'engagement_type' => $type]);
    }

    /** Move engagements to another organization (superadmin only). Payroll history and
     *  activity logs stay with the source org; forward-looking HR data follows the person. */
    public static function bulkTransfer(array $p): void
    {
        $user = Auth::require();
        if (!$user['is_superadmin']) throw new HttpError('Only a superadmin can transfer people between organizations.', 403);
        $fromOrg = (int) ($p['organization_id'] ?? 0);
        $body = Http::body();
        $toOrg = (int) ($body['target_organization_id'] ?? 0);
        $ids = array_values(array_unique(array_map('intval', (array) ($body['engagement_ids'] ?? []))));
        if (!$ids) throw new HttpError('No records selected', 422);
        if (!$toOrg || $toOrg === $fromOrg) throw new HttpError('Choose a different destination organization', 422);
        if (!Database::one('SELECT id FROM organizations WHERE id = ?', [$toOrg])) throw new HttpError('Destination organization not found', 404);

        $moved = [];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($ids as $engId) {
                $eng = Database::one('SELECT * FROM engagements WHERE id = ? AND organization_id = ?', [$engId, $fromOrg]);
                if (!$eng) continue;
                $personId = (int) $eng['person_id'];
                // Department / position / project belong to the source org — clear them for re-assignment.
                Database::exec('UPDATE engagements SET organization_id = ?, department_id = NULL, position_id = NULL, project_id = NULL WHERE id = ?', [$toOrg, $engId]);
                // Forward-looking HR data follows the employee.
                Database::exec('UPDATE leave_balances SET organization_id = ? WHERE engagement_id = ?', [$toOrg, $engId]);
                Database::exec('UPDATE loans SET organization_id = ? WHERE engagement_id = ?', [$toOrg, $engId]);
                Database::exec('UPDATE benefits SET organization_id = ? WHERE person_id = ? AND organization_id = ?', [$toOrg, $personId, $fromOrg]);
                Database::exec('UPDATE employee_documents SET organization_id = ? WHERE person_id = ? AND organization_id = ?', [$toOrg, $personId, $fromOrg]);
                $moved[] = $engId;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new HttpError('Could not transfer: ' . $e->getMessage(), 500);
        }
        Audit::record('employee.transfer', $user, ['organization_id' => $fromOrg, 'entity' => 'engagement',
            'after' => ['moved' => count($moved), 'to_organization_id' => $toOrg]]);
        Http::json(['moved' => $moved, 'to_organization_id' => $toOrg]);
    }

    private static function rows(int $orgId, ?string $mode): array
    {
        $sql = "SELECT e.id eng_id, e.person_id, e.engagement_type, e.employee_number, e.status,
                       e.base_rate, e.start_date, e.end_date,
                       p.first_name, p.middle_name, p.last_name, p.suffix
                  FROM engagements e JOIN people p ON p.id = e.person_id
                 WHERE e.organization_id = ?";
        $params = [$orgId];
        if ($mode === 'employees') {
            $in = implode(',', array_fill(0, count(self::CONSULTANT_TYPES), '?'));
            $sql .= " AND e.engagement_type NOT IN ($in)";
            $params = array_merge($params, self::CONSULTANT_TYPES);
        } elseif ($mode === 'consultants') {
            $in = implode(',', array_fill(0, count(self::CONSULTANT_TYPES), '?'));
            $sql .= " AND e.engagement_type IN ($in)";
            $params = array_merge($params, self::CONSULTANT_TYPES);
        }
        $sql .= ' ORDER BY p.last_name';
        return array_map(function ($r) {
            $name = trim(implode(' ', array_filter([$r['first_name'], $r['middle_name'], $r['last_name'], $r['suffix']])));
            return [
                'engagement_id' => (int) $r['eng_id'], 'person_id' => (int) $r['person_id'],
                'full_name' => $name, 'engagement_type' => $r['engagement_type'],
                'employee_number' => $r['employee_number'], 'status' => $r['status'],
                'base_rate' => $r['base_rate'] !== null ? (float) $r['base_rate'] : null,
                'start_date' => $r['start_date'], 'end_date' => $r['end_date'],
            ];
        }, Database::all($sql, $params));
    }

    private static function guard(array $p): int
    {
        $u = Auth::requirePerm('employee.view');
        $id = (int) $p['organization_id'];
        Auth::requireOrg($u, $id);
        return $id;
    }

    public static function people(array $p): void { Http::json(self::rows(self::guard($p), null)); }
    public static function employees(array $p): void { Http::json(self::rows(self::guard($p), 'employees')); }
    public static function consultants(array $p): void { Http::json(self::rows(self::guard($p), 'consultants')); }
}
