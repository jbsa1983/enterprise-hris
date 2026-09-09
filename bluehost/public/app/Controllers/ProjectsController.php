<?php
class ProjectsController
{
    const PFIELDS = ['project_code', 'project_name', 'client_id', 'project_manager', 'start_date',
        'target_end_date', 'actual_end_date', 'site', 'cost_center', 'status', 'project_budget', 'labor_budget'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}';
        $r->get("$b/projects", [self::class, 'list']);
        $r->post("$b/projects", [self::class, 'create']);
        $r->put("$b/projects/{id}", [self::class, 'update']);
        $r->delete("$b/projects/{id}", [self::class, 'delete']);
        $r->get("$b/projects/{id}/allocations", [self::class, 'listAllocations']);
        $r->post("$b/projects/{id}/allocations", [self::class, 'setAllocation']);
        $r->get("$b/projects/{id}/budget", [self::class, 'budget']);
        $r->get("$b/projects/{id}/report", [self::class, 'report']);
    }

    private static function shape(array $p): array
    {
        return ['id' => (int) $p['id'], 'uuid' => $p['uuid'], 'organization_id' => (int) $p['organization_id'],
            'project_code' => $p['project_code'], 'project_name' => $p['project_name'], 'status' => $p['status'],
            'start_date' => $p['start_date'], 'target_end_date' => $p['target_end_date'],
            'labor_budget' => $p['labor_budget'] !== null ? (float) $p['labor_budget'] : null];
    }

    public static function list(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.view');
        Http::json(array_map([self::class, 'shape'], Database::all('SELECT * FROM projects WHERE organization_id = ? ORDER BY project_name', [$o])));
    }

    private static function pick(array $b): array
    {
        $out = [];
        foreach (self::PFIELDS as $f) if (array_key_exists($f, $b) && $b[$f] !== null && $b[$f] !== '') $out[$f] = $b[$f];
        return $out;
    }

    public static function create(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.manage'); $b = Http::body();
        if (empty($b['project_code']) || empty($b['project_name'])) throw new HttpError('project_code and project_name are required', 422);
        $d = self::pick($b);
        $d['uuid'] = Util::uuid(); $d['organization_id'] = $o;
        if (empty($d['status'])) $d['status'] = 'ACTIVE';
        Http::json(['id' => Database::insert('projects', $d)]);
    }

    public static function update(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.manage');
        $proj = Database::one('SELECT * FROM projects WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$proj) throw new HttpError('Project not found', 404);
        Database::update('projects', (int) $proj['id'], self::pick(Http::body()));
        Http::json(['id' => (int) $proj['id']]);
    }

    public static function delete(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.manage');
        $proj = Database::one('SELECT id FROM projects WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$proj) throw new HttpError('Project not found', 404);
        $n = (int) Database::scalar('SELECT COUNT(*) FROM project_assignments WHERE project_id = ?', [$proj['id']]);
        if ($n) throw new HttpError("Reassign $n personnel before deleting this project", 409);
        Database::exec('DELETE FROM project_budget_allocations WHERE project_id = ?', [$proj['id']]);
        Database::exec('DELETE FROM projects WHERE id = ?', [$proj['id']]);
        Http::json(['deleted' => (int) $proj['id']]);
    }

    public static function listAllocations(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.view');
        Http::json(Database::all('SELECT id, period_label, amount FROM project_budget_allocations WHERE project_id = ?', [(int) $p['id']]));
    }

    public static function setAllocation(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.manage'); $b = Http::body();
        $proj = Database::one('SELECT id FROM projects WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$proj) throw new HttpError('Project not found', 404);
        $existing = Database::one('SELECT id FROM project_budget_allocations WHERE project_id = ? AND period_label = ?', [$proj['id'], $b['period_label']]);
        if ($existing) Database::update('project_budget_allocations', (int) $existing['id'], ['amount' => (float) ($b['amount'] ?? 0)]);
        else Database::insert('project_budget_allocations', ['organization_id' => $o, 'project_id' => $proj['id'], 'period_label' => $b['period_label'], 'amount' => (float) ($b['amount'] ?? 0)]);
        Http::json(['ok' => true]);
    }

    private static function actualByPeriod(int $projectId): array
    {
        $rows = Database::all(
            "SELECT DATE_FORMAT(pp.period_start,'%Y-%m') period, COALESCE(SUM(prp.gross_pay),0) total
               FROM payroll_periods pp
               JOIN payroll_runs pr ON pr.period_id = pp.id
               JOIN payroll_run_people prp ON prp.run_id = pr.id
               JOIN engagements e ON e.id = prp.engagement_id
              WHERE e.project_id = ? GROUP BY period", [$projectId]);
        $out = [];
        foreach ($rows as $r) $out[$r['period']] = (float) $r['total'];
        return $out;
    }

    public static function budget(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.view');
        $proj = Database::one('SELECT * FROM projects WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$proj) throw new HttpError('Project not found', 404);
        $alloc = [];
        foreach (Database::all('SELECT period_label, amount FROM project_budget_allocations WHERE project_id = ?', [$proj['id']]) as $a) $alloc[$a['period_label']] = (float) $a['amount'];
        $actual = self::actualByPeriod((int) $proj['id']);
        $periods = array_values(array_unique(array_merge(array_keys($alloc), array_keys($actual))));
        sort($periods);
        $from = Http::query('date_from'); $to = Http::query('date_to');
        if ($from) $periods = array_filter($periods, fn($x) => $x >= $from);
        if ($to) $periods = array_filter($periods, fn($x) => $x <= $to);
        $rows = [];
        foreach ($periods as $pl) {
            $a = $alloc[$pl] ?? 0.0; $ac = $actual[$pl] ?? 0.0;
            $rows[] = ['period' => $pl, 'allocated' => $a, 'actual' => $ac, 'variance' => round($a - $ac, 2)];
        }
        $at = array_sum(array_column($rows, 'allocated'));
        $act = array_sum(array_column($rows, 'actual'));
        $lb = (float) ($proj['labor_budget'] ?? 0);
        Http::json(['project_name' => $proj['project_name'], 'labor_budget' => $lb,
            'allocated_total' => round($at, 2), 'actual_total' => round($act, 2),
            'remaining_vs_budget' => round($lb - $act, 2), 'remaining_vs_allocated' => round($at - $act, 2),
            'periods' => array_values($rows)]);
    }

    public static function report(array $p): void
    {
        [, $o] = Auth::org($p, 'reports.view');
        $proj = Database::one('SELECT * FROM projects WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$proj) throw new HttpError('Project not found', 404);
        $from = Http::query('date_from'); $to = Http::query('date_to'); $fmt = Http::query('fmt', 'json');
        $rows = Database::all(
            "SELECT e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name,
                    DATE_FORMAT(pp.period_start,'%Y-%m') period, COALESCE(SUM(prp.gross_pay),0) cost
               FROM engagements e JOIN people pe ON pe.id = e.person_id
               JOIN payroll_run_people prp ON prp.engagement_id = e.id
               JOIN payroll_runs pr ON pr.id = prp.run_id
               JOIN payroll_periods pp ON pp.id = pr.period_id
              WHERE e.project_id = ? GROUP BY e.id, pe.id, period", [$proj['id']]);
        $data = [];
        foreach ($rows as $r) {
            if ($from && $r['period'] < $from) continue;
            if ($to && $r['period'] > $to) continue;
            $data[] = ['employee_number' => $r['employee_number'], 'name' => $r['name'], 'period' => $r['period'], 'cost' => (float) $r['cost']];
        }
        $total = round(array_sum(array_column($data, 'cost')), 2);
        if ($fmt === 'csv') {
            $out = "Employee No.,Name,Period,Cost\n";
            foreach ($data as $d) $out .= '"' . $d['employee_number'] . '","' . $d['name'] . '","' . $d['period'] . '",' . $d['cost'] . "\n";
            $out .= ",,TOTAL,$total\n";
            Http::file($out, 'text/csv', 'project_' . $proj['project_code'] . '_report.csv');
        }
        $names = array_unique(array_column($data, 'name'));
        Http::json(['project_name' => $proj['project_name'], 'project_code' => $proj['project_code'],
            'rows' => $data, 'total_cost' => $total, 'headcount' => count($names)]);
    }
}
