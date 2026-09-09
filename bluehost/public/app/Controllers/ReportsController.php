<?php
class ReportsController
{
    const CONSULTANT_TYPES = ['CONSULTANT_INDIVIDUAL', 'CONSULTANT_COMPANY'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}/reports';
        $r->get("$b/datasets", [self::class, 'datasets']);
        $r->post("$b/preview", [self::class, 'preview']);
        $r->post("$b/export", [self::class, 'export']);
    }

    private static function dataset(string $name, int $o): array
    {
        $c = implode(',', array_fill(0, count(self::CONSULTANT_TYPES), '?'));
        switch ($name) {
            case 'employees':
                return Database::all("SELECT e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, e.engagement_type, e.status, e.base_rate, e.salary_basis, e.start_date
                    FROM engagements e JOIN people pe ON pe.id = e.person_id WHERE e.organization_id = ? AND e.engagement_type NOT IN ($c)", array_merge([$o], self::CONSULTANT_TYPES));
            case 'consultants':
                return Database::all("SELECT e.employee_number contract_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, e.engagement_type type, e.base_rate fee, e.status
                    FROM engagements e JOIN people pe ON pe.id = e.person_id WHERE e.organization_id = ? AND e.engagement_type IN ($c)", array_merge([$o], self::CONSULTANT_TYPES));
            case 'payroll':
                return Database::all("SELECT pr.reference run, e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, prp.gross_pay, prp.total_deductions, prp.net_pay
                    FROM payroll_run_people prp JOIN engagements e ON e.id = prp.engagement_id JOIN people pe ON pe.id = e.person_id JOIN payroll_runs pr ON pr.id = prp.run_id WHERE pr.organization_id = ?", [$o]);
            case 'payroll_by_period':
                return Database::all("SELECT pp.name period, COUNT(prp.id) headcount, COALESCE(SUM(prp.gross_pay),0) gross, COALESCE(SUM(prp.total_deductions),0) deductions, COALESCE(SUM(prp.net_pay),0) net
                    FROM payroll_periods pp JOIN payroll_runs pr ON pr.period_id = pp.id JOIN payroll_run_people prp ON prp.run_id = pr.id WHERE pp.organization_id = ? GROUP BY pp.id, pp.name", [$o]);
            case 'payroll_by_project':
                return Database::all("SELECT p.project_code, p.project_name, COUNT(DISTINCT e.id) headcount, COALESCE(SUM(prp.gross_pay),0) total_cost
                    FROM projects p JOIN engagements e ON e.project_id = p.id JOIN payroll_run_people prp ON prp.engagement_id = e.id WHERE p.organization_id = ? GROUP BY p.id, p.project_code, p.project_name", [$o]);
            case 'loans':
                return Database::all("SELECT CONCAT_WS(' ', pe.first_name, pe.last_name) name, l.obligation_type type, l.reference_number reference, l.principal, l.balance, l.installment_amount installment, l.status
                    FROM loans l JOIN people pe ON pe.id = l.person_id WHERE l.organization_id = ?", [$o]);
            case 'projects':
                return Database::all("SELECT project_code, project_name, status, labor_budget, start_date FROM projects WHERE organization_id = ?", [$o]);
            case 'statutory_contributions':
                $rows = Database::all("SELECT e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, prp.deductions
                    FROM payroll_run_people prp JOIN engagements e ON e.id = prp.engagement_id JOIN people pe ON pe.id = e.person_id JOIN payroll_runs pr ON pr.id = prp.run_id WHERE pr.organization_id = ?", [$o]);
                return array_map(function ($r) { $d = json_decode($r['deductions'] ?: '{}', true);
                    return ['employee_number' => $r['employee_number'], 'name' => $r['name'], 'sss' => (float) ($d['sss'] ?? 0), 'philhealth' => (float) ($d['philhealth'] ?? 0), 'pagibig' => (float) ($d['pagibig'] ?? 0), 'withholding_tax' => (float) ($d['withholding_tax'] ?? 0)]; }, $rows);
        }
        throw new HttpError('Unknown dataset', 400);
    }

    const NAMES = ['employees', 'consultants', 'payroll', 'payroll_by_period', 'payroll_by_project', 'loans', 'projects', 'statutory_contributions'];

    public static function datasets(array $p): void
    {
        [, $o] = Auth::org($p, 'reports.view');
        $out = [];
        foreach (self::NAMES as $n) { $rows = self::dataset($n, $o); $out[] = ['name' => $n, 'columns' => $rows ? array_keys($rows[0]) : []]; }
        Http::json($out);
    }

    private static function apply(array $rows, array $config): array
    {
        foreach ($config['filters'] ?? [] as $f) {
            $field = $f['field'] ?? ''; $op = $f['op'] ?? 'eq'; $val = $f['value'] ?? '';
            $rows = array_values(array_filter($rows, function ($r) use ($field, $op, $val) {
                $v = $r[$field] ?? null;
                if ($op === 'eq') return (string) $v === (string) $val;
                if ($op === 'contains') return stripos((string) $v, (string) $val) !== false;
                if ($op === 'gte') return (float) $v >= (float) $val;
                if ($op === 'lte') return (float) $v <= (float) $val;
                return true;
            }));
        }
        if (!empty($config['sort']['field'])) {
            $sf = $config['sort']['field']; $dir = ($config['sort']['dir'] ?? 'asc') === 'desc' ? -1 : 1;
            usort($rows, fn($a, $b) => ($a[$sf] <=> $b[$sf]) * $dir);
        }
        $cols = $config['columns'] ?? ($rows ? array_keys($rows[0]) : []);
        if (!empty($config['limit'])) $rows = array_slice($rows, 0, (int) $config['limit']);
        return [$cols, $rows];
    }

    public static function preview(array $p): void
    {
        [, $o] = Auth::org($p, 'reports.view'); $b = Http::body();
        if (!in_array($b['dataset'] ?? '', self::NAMES, true)) throw new HttpError('Unknown dataset', 400);
        [$cols, $rows] = self::apply(self::dataset($b['dataset'], $o), $b['config'] ?? []);
        Http::json(['columns' => $cols, 'rows' => array_slice($rows, 0, 100), 'row_count' => count($rows)]);
    }

    public static function export(array $p): void
    {
        [, $o] = Auth::org($p, 'reports.export'); $b = Http::body();
        $fmt = strtolower(Http::query('fmt', 'csv'));
        if (!in_array($b['dataset'] ?? '', self::NAMES, true)) throw new HttpError('Unknown dataset', 400);
        [$cols, $rows] = self::apply(self::dataset($b['dataset'], $o), $b['config'] ?? []);
        if ($fmt === 'pdf') {
            $th = implode('', array_map(fn($c) => "<th>$c</th>", $cols));
            $trs = '';
            foreach ($rows as $r) { $trs .= '<tr>' . implode('', array_map(fn($c) => '<td>' . htmlspecialchars((string) ($r[$c] ?? '')) . '</td>', $cols)) . '</tr>'; }
            $html = "<!doctype html><html><head><meta charset='utf-8'><title>{$b['dataset']}</title><style>@media print{.noprint{display:none}}body{font-family:Arial;font-size:11px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:3px 5px}th{background:#f3f4f6}</style></head><body><div class='noprint'><button onclick='window.print()'>Print / Save as PDF</button></div><h3>" . ucwords(str_replace('_', ' ', $b['dataset'])) . " Report</h3><table><thead><tr>$th</tr></thead><tbody>$trs</tbody></table></body></html>";
            header('Content-Type: text/html; charset=utf-8'); echo $html; exit;
        }
        // csv / xlsx → CSV bytes (opens in Excel).
        $out = implode(',', array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $cols)) . "\n";
        foreach ($rows as $r) $out .= implode(',', array_map(fn($c) => '"' . str_replace('"', '""', (string) ($r[$c] ?? '')) . '"', $cols)) . "\n";
        $ext = $fmt === 'xlsx' ? 'csv' : 'csv';
        Http::file($out, 'text/csv', $b['dataset'] . "_report.$ext");
    }
}
