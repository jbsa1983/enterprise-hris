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

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}';
        $r->get("$b/people", [self::class, 'people']);
        $r->get("$b/employees", [self::class, 'employees']);
        $r->get("$b/consultants", [self::class, 'consultants']);
        $r->post("$b/people/create", [self::class, 'create']);
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
