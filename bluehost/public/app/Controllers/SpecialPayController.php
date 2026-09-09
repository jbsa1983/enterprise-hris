<?php
class SpecialPayController
{
    const CONSULTANT_TYPES = ['CONSULTANT_INDIVIDUAL', 'CONSULTANT_COMPANY'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}/special-pay';
        $r->get($b, [self::class, 'list']);
        $r->post($b, [self::class, 'create']);
        $r->get("$b/{id}", [self::class, 'get']);
        $r->put("$b/{id}/lines/{line_id}", [self::class, 'overrideLine']);
        $r->post("$b/{id}/finalize", [self::class, 'finalize']);
        $r->get("$b/{id}/export", [self::class, 'export']);
    }

    private static function basicEarned(int $engId, int $year): float
    {
        $rows = Database::all("SELECT prp.earnings FROM payroll_run_people prp
            JOIN payroll_runs pr ON pr.id = prp.run_id JOIN payroll_periods pp ON pp.id = pr.period_id
            WHERE prp.engagement_id = ? AND YEAR(pp.period_start) = ?", [$engId, $year]);
        $t = 0.0;
        foreach ($rows as $r) { $e = json_decode($r['earnings'] ?: '{}', true); $t += (float) ($e['basic'] ?? 0); }
        return $t;
    }

    private static function finalAmount(array $ln): float
    {
        return (float) ($ln['override_amount'] !== null ? $ln['override_amount'] : $ln['computed_amount']);
    }

    public static function list(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        Http::json(array_map(fn($r) => ['id' => (int) $r['id'], 'uuid' => $r['uuid'], 'pay_type' => $r['pay_type'],
            'name' => $r['name'], 'year' => (int) $r['year'], 'status' => $r['status'], 'total_amount' => (float) $r['total_amount'],
            'line_count' => (int) Database::scalar('SELECT COUNT(*) FROM special_pay_lines WHERE run_id = ?', [$r['id']])],
            Database::all('SELECT * FROM special_pay_runs WHERE organization_id = ? ORDER BY id DESC', [$o])));
    }

    public static function create(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.compute'); $b = Http::body();
        $type = strtoupper($b['pay_type'] ?? '13TH_MONTH');
        if (!in_array($type, ['13TH_MONTH', 'BONUS'], true)) throw new HttpError('pay_type must be 13TH_MONTH or BONUS', 422);
        $year = (int) $b['year'];
        $runId = Database::insert('special_pay_runs', ['uuid' => Util::uuid(), 'organization_id' => $o, 'pay_type' => $type,
            'name' => $b['name'] ?? "$type $year", 'year' => $year, 'status' => 'DRAFT']);
        $in = implode(',', array_fill(0, count(self::CONSULTANT_TYPES), '?'));
        $sql = "SELECT id FROM engagements WHERE organization_id = ? AND status='ACTIVE' AND engagement_type NOT IN ($in)";
        $params = array_merge([$o], self::CONSULTANT_TYPES);
        if (!empty($b['engagement_ids'])) { $ph = implode(',', array_fill(0, count($b['engagement_ids']), '?')); $sql .= " AND id IN ($ph)"; $params = array_merge($params, $b['engagement_ids']); }
        $total = 0.0;
        foreach (Database::all($sql, $params) as $e) {
            $computed = $type === '13TH_MONTH' ? round(self::basicEarned((int) $e['id'], $year) / 12, 2) : 0.0;
            Database::insert('special_pay_lines', ['run_id' => $runId, 'engagement_id' => (int) $e['id'], 'computed_amount' => $computed]);
            $total += $computed;
        }
        Database::update('special_pay_runs', $runId, ['total_amount' => round($total, 2)]);
        Http::json(['id' => $runId, 'line_count' => (int) Database::scalar('SELECT COUNT(*) FROM special_pay_lines WHERE run_id = ?', [$runId]), 'total_amount' => round($total, 2)]);
    }

    public static function get(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $run = Database::one('SELECT * FROM special_pay_runs WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$run) throw new HttpError('Run not found', 404);
        $lines = array_map(function ($ln) {
            $eng = Database::one('SELECT employee_number, person_id FROM engagements WHERE id = ?', [$ln['engagement_id']]);
            $name = $eng ? Database::scalar("SELECT CONCAT_WS(' ', first_name, last_name) FROM people WHERE id = ?", [$eng['person_id']]) : null;
            return ['line_id' => (int) $ln['id'], 'engagement_id' => (int) $ln['engagement_id'], 'employee_number' => $eng['employee_number'] ?? null,
                'name' => $name, 'computed_amount' => (float) $ln['computed_amount'],
                'override_amount' => $ln['override_amount'] !== null ? (float) $ln['override_amount'] : null,
                'final_amount' => self::finalAmount($ln), 'remarks' => $ln['remarks']];
        }, Database::all('SELECT * FROM special_pay_lines WHERE run_id = ?', [$run['id']]));
        Http::json(['id' => (int) $run['id'], 'pay_type' => $run['pay_type'], 'name' => $run['name'], 'year' => (int) $run['year'],
            'status' => $run['status'], 'total_amount' => (float) $run['total_amount'], 'lines' => $lines]);
    }

    private static function recompute(int $runId): float
    {
        $t = 0.0;
        foreach (Database::all('SELECT * FROM special_pay_lines WHERE run_id = ?', [$runId]) as $ln) $t += self::finalAmount($ln);
        Database::update('special_pay_runs', $runId, ['total_amount' => round($t, 2)]);
        return round($t, 2);
    }

    public static function overrideLine(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.compute'); $b = Http::body();
        $run = Database::one('SELECT * FROM special_pay_runs WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$run) throw new HttpError('Run not found', 404);
        if ($run['status'] === 'GENERATED') throw new HttpError('Run already generated', 409);
        $ln = Database::one('SELECT * FROM special_pay_lines WHERE id = ? AND run_id = ?', [(int) $p['line_id'], $run['id']]);
        if (!$ln) throw new HttpError('Line not found', 404);
        $u = [];
        if (array_key_exists('override_amount', $b)) $u['override_amount'] = $b['override_amount'] === null ? null : (float) $b['override_amount'];
        if (array_key_exists('remarks', $b)) $u['remarks'] = $b['remarks'];
        if ($u) Database::update('special_pay_lines', (int) $ln['id'], $u);
        $total = self::recompute((int) $run['id']);
        $ln = Database::one('SELECT * FROM special_pay_lines WHERE id = ?', [$ln['id']]);
        Http::json(['line_id' => (int) $ln['id'], 'final_amount' => self::finalAmount($ln), 'run_total' => $total]);
    }

    public static function finalize(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.approve');
        $run = Database::one('SELECT * FROM special_pay_runs WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$run) throw new HttpError('Run not found', 404);
        $total = self::recompute((int) $run['id']);
        Database::update('special_pay_runs', (int) $run['id'], ['status' => 'GENERATED']);
        Http::json(['id' => (int) $run['id'], 'status' => 'GENERATED', 'total_amount' => $total]);
    }

    public static function export(array $p): void
    {
        [, $o] = Auth::org($p, 'reports.view');
        $run = Database::one('SELECT * FROM special_pay_runs WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$run) throw new HttpError('Run not found', 404);
        $out = "Employee No.,Name,Computed,Override,Final,Remarks\n"; $total = 0.0;
        foreach (Database::all('SELECT * FROM special_pay_lines WHERE run_id = ?', [$run['id']]) as $ln) {
            $eng = Database::one('SELECT employee_number, person_id FROM engagements WHERE id = ?', [$ln['engagement_id']]);
            $name = $eng ? Database::scalar("SELECT CONCAT_WS(' ', first_name, last_name) FROM people WHERE id = ?", [$eng['person_id']]) : '';
            $fin = self::finalAmount($ln); $total += $fin;
            $out .= '"' . ($eng['employee_number'] ?? '') . '","' . $name . '",' . (float) $ln['computed_amount'] . ',' . ($ln['override_amount'] ?? '') . ',' . $fin . ',"' . ($ln['remarks'] ?? '') . "\"\n";
        }
        $out .= ",,,TOTAL,$total,\n";
        Http::file($out, 'text/csv', $run['pay_type'] . '_' . $run['year'] . '.csv');
    }
}
