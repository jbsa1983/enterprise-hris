<?php
class RecruitmentController
{
    const STAGES = ['NEW', 'SCREENING', 'INTERVIEW', 'OFFER', 'HIRED', 'REJECTED'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}/recruitment';
        $r->get("$b/requisitions", [self::class, 'listReq']);
        $r->post("$b/requisitions", [self::class, 'createReq']);
        $r->put("$b/requisitions/{id}", [self::class, 'updateReq']);
        $r->delete("$b/requisitions/{id}", [self::class, 'deleteReq']);
        $r->get("$b/applicants", [self::class, 'listApplicants']);
        $r->post("$b/applicants", [self::class, 'createApplicant']);
        $r->get("$b/applications", [self::class, 'listApplications']);
        $r->post("$b/applications/{id}/advance", [self::class, 'advance']);
        $r->post("$b/applications/{id}/hire", [self::class, 'hire']);
    }

    private static function reqShape(array $r): array
    {
        $proj = $r['project_id'] ? Database::scalar('SELECT project_name FROM projects WHERE id = ?', [$r['project_id']]) : null;
        return ['id' => (int) $r['id'], 'uuid' => $r['uuid'], 'title' => $r['title'], 'headcount' => (int) $r['headcount'],
            'status' => $r['status'], 'job_description' => $r['job_description'], 'placement_type' => $r['placement_type'],
            'employment_type' => $r['employment_type'], 'project_id' => $r['project_id'] !== null ? (int) $r['project_id'] : null,
            'project_name' => $proj, 'department_id' => $r['department_id'] !== null ? (int) $r['department_id'] : null,
            'target_start_date' => $r['target_start_date'], 'target_end_date' => $r['target_end_date'],
            'budget' => $r['budget'] !== null ? (float) $r['budget'] : null];
    }

    private static function applyReq(int $id, array $b): void
    {
        $u = [];
        if (!empty($b['title'])) $u['title'] = $b['title'];
        foreach (['headcount', 'department_id', 'project_id', 'job_description', 'employment_type', 'budget', 'status',
                  'target_start_date', 'target_end_date'] as $f) if (array_key_exists($f, $b) && $b[$f] !== null) $u[$f] = $b[$f];
        if (!empty($b['placement_type'])) { $u['placement_type'] = strtoupper($b['placement_type']); if ($u['placement_type'] === 'OFFICE') $u['project_id'] = null; }
        if ($u) Database::update('job_requisitions', $id, $u);
    }

    public static function listReq(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        Http::json(array_map([self::class, 'reqShape'], Database::all('SELECT * FROM job_requisitions WHERE organization_id = ? ORDER BY id DESC', [$o])));
    }

    public static function createReq(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        if (empty($b['title'])) throw new HttpError('title is required', 422);
        $id = Database::insert('job_requisitions', ['uuid' => Util::uuid(), 'organization_id' => $o, 'title' => $b['title'],
            'headcount' => $b['headcount'] ?? 1, 'status' => 'OPEN', 'placement_type' => 'OFFICE']);
        self::applyReq($id, $b);
        Http::json(self::reqShape(Database::one('SELECT * FROM job_requisitions WHERE id = ?', [$id])));
    }

    public static function updateReq(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit');
        $r = Database::one('SELECT id FROM job_requisitions WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$r) throw new HttpError('Requisition not found', 404);
        self::applyReq((int) $r['id'], Http::body());
        Http::json(self::reqShape(Database::one('SELECT * FROM job_requisitions WHERE id = ?', [$r['id']])));
    }

    public static function deleteReq(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit');
        Database::exec('DELETE FROM job_requisitions WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        Http::json(['deleted' => (int) $p['id']]);
    }

    public static function listApplicants(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        Http::json(array_map(fn($a) => ['id' => (int) $a['id'], 'uuid' => $a['uuid'], 'name' => trim($a['first_name'] . ' ' . $a['last_name']),
            'email' => $a['email'], 'hired_person_id' => $a['hired_person_id']],
            Database::all('SELECT * FROM applicants WHERE organization_id = ?', [$o])));
    }

    public static function createApplicant(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $aid = Database::insert('applicants', ['uuid' => Util::uuid(), 'organization_id' => $o, 'first_name' => $b['first_name'],
            'last_name' => $b['last_name'], 'email' => $b['email'] ?? null, 'mobile' => $b['mobile'] ?? null]);
        $appId = null;
        if (!empty($b['requisition_id'])) $appId = Database::insert('job_applications', ['organization_id' => $o, 'requisition_id' => (int) $b['requisition_id'], 'applicant_id' => $aid, 'stage' => 'NEW']);
        Http::json(['id' => $aid, 'application_id' => $appId]);
    }

    public static function listApplications(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        Http::json(array_map(fn($r) => ['id' => (int) $r['id'], 'applicant' => trim($r['first_name'] . ' ' . $r['last_name']),
            'requisition_id' => (int) $r['requisition_id'], 'stage' => $r['stage']],
            Database::all("SELECT ja.*, a.first_name, a.last_name FROM job_applications ja JOIN applicants a ON a.id = ja.applicant_id WHERE ja.organization_id = ?", [$o])));
    }

    public static function advance(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $app = Database::one('SELECT * FROM job_applications WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$app) throw new HttpError('Application not found', 404);
        if (!empty($b['stage'])) {
            if (!in_array($b['stage'], self::STAGES, true)) throw new HttpError('invalid stage', 422);
            $stage = $b['stage'];
        } else {
            $idx = array_search($app['stage'], self::STAGES, true) ?: 0;
            $stage = self::STAGES[min($idx + 1, count(self::STAGES) - 2)];
        }
        Database::update('job_applications', (int) $app['id'], ['stage' => $stage]);
        Http::json(['id' => (int) $app['id'], 'stage' => $stage]);
    }

    public static function hire(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.create'); $b = Http::body();
        $app = Database::one('SELECT * FROM job_applications WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$app) throw new HttpError('Application not found', 404);
        $a = Database::one('SELECT * FROM applicants WHERE id = ?', [$app['applicant_id']]);
        $pid = Database::insert('people', ['uuid' => Util::uuid(), 'first_name' => $a['first_name'], 'last_name' => $a['last_name'],
            'email' => $a['email'], 'mobile' => $a['mobile'], 'status' => 'ACTIVE']);
        $eid = Database::insert('engagements', ['uuid' => Util::uuid(), 'person_id' => $pid, 'organization_id' => $o,
            'engagement_type' => $b['engagement_type'] ?? 'PROBATIONARY', 'employee_number' => $b['employee_number'] ?? ('NEW-' . $pid),
            'start_date' => date('Y-m-d'), 'salary_basis' => 'MONTHLY', 'base_rate' => $b['base_rate'] ?? 25000, 'status' => 'ACTIVE']);
        Database::update('applicants', (int) $a['id'], ['hired_person_id' => $pid]);
        Database::update('job_applications', (int) $app['id'], ['stage' => 'HIRED']);
        Http::json(['person_id' => $pid, 'engagement_id' => $eid, 'stage' => 'HIRED']);
    }
}
