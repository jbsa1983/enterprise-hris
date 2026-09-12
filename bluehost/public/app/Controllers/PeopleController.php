<?php
class PeopleController
{
    const CONSULTANT_TYPES = ['CONSULTANT_INDIVIDUAL', 'CONSULTANT_COMPANY'];

    const PERSON_FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix', 'preferred_name', 'birth_date',
        'gender', 'civil_status', 'email', 'mobile', 'address', 'emergency_contact', 'tin', 'sss_number',
        'philhealth_number', 'pagibig_number', 'bank_name', 'bank_account_number', 'bank_account_name'];
    const ENG_FIELDS = ['engagement_type', 'employee_number', 'start_date', 'end_date', 'regularization_date',
        'department_id', 'position_id', 'job_grade', 'salary_basis', 'base_rate', 'payroll_group', 'cost_center',
        'project_id', 'work_site', 'status'];

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
        $r->post("$b/people/{engagement_id}/archive", [self::class, 'archive']);
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
