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
            case 'leave':
                return Database::all("SELECT e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, lr.leave_type, lr.date_from, lr.date_to, lr.days, lr.status
                    FROM leave_requests lr JOIN engagements e ON e.id = lr.engagement_id JOIN people pe ON pe.id = e.person_id WHERE lr.organization_id = ? ORDER BY lr.id DESC", [$o]);
            case 'attendance':
                return Database::all("SELECT e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, a.log_date, a.hours_worked, a.late_minutes, a.overtime_hours, a.status
                    FROM attendance_logs a JOIN engagements e ON e.id = a.engagement_id JOIN people pe ON pe.id = e.person_id WHERE a.organization_id = ? ORDER BY a.log_date DESC", [$o]);
            case 'benefits':
                return Database::all("SELECT CONCAT_WS(' ', pe.first_name, pe.last_name) name, b.benefit_type, b.provider, b.policy_number, b.coverage_amount, b.start_date, b.end_date, b.status,
                    (SELECT GROUP_CONCAT(bb.name SEPARATOR '; ') FROM benefit_beneficiaries bb WHERE bb.benefit_id = b.id) beneficiaries
                    FROM benefits b JOIN people pe ON pe.id = b.person_id WHERE b.organization_id = ? ORDER BY b.id DESC", [$o]);
            case 'assets':
                return Database::all("SELECT a.asset_number, a.item, a.serial_number, CONCAT_WS(' ', pe.first_name, pe.last_name) assigned_to, a.status, a.`condition` `condition`, a.issue_date, a.returned_date, a.cost
                    FROM assets a LEFT JOIN people pe ON pe.id = a.assigned_person_id WHERE a.organization_id = ? ORDER BY a.id DESC", [$o]);
            case 'thirteenth_month':
                return Database::all("SELECT r.name run, r.pay_type, r.year, e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, COALESCE(l.override_amount, l.computed_amount) amount, r.status
                    FROM special_pay_lines l JOIN special_pay_runs r ON r.id = l.run_id JOIN engagements e ON e.id = l.engagement_id JOIN people pe ON pe.id = e.person_id WHERE r.organization_id = ? ORDER BY r.year DESC, r.id DESC", [$o]);
        }
        throw new HttpError('Unknown dataset', 400);
    }

    const NAMES = ['employees', 'consultants', 'payroll', 'payroll_by_period', 'payroll_by_project', 'loans', 'projects',
        'statutory_contributions', 'leave', 'attendance', 'benefits', 'assets', 'thirteenth_month'];

    // Column lists (match the SELECT aliases in dataset()) — used for the dataset
    // picker so we don't run every query just to read column names.
    const DATASET_COLUMNS = [
        'employees' => ['employee_number', 'name', 'engagement_type', 'status', 'base_rate', 'salary_basis', 'start_date'],
        'consultants' => ['contract_number', 'name', 'type', 'fee', 'status'],
        'payroll' => ['run', 'employee_number', 'name', 'gross_pay', 'total_deductions', 'net_pay'],
        'payroll_by_period' => ['period', 'headcount', 'gross', 'deductions', 'net'],
        'payroll_by_project' => ['project_code', 'project_name', 'headcount', 'total_cost'],
        'loans' => ['name', 'type', 'reference', 'principal', 'balance', 'installment', 'status'],
        'projects' => ['project_code', 'project_name', 'status', 'labor_budget', 'start_date'],
        'statutory_contributions' => ['employee_number', 'name', 'sss', 'philhealth', 'pagibig', 'withholding_tax'],
        'leave' => ['employee_number', 'name', 'leave_type', 'date_from', 'date_to', 'days', 'status'],
        'attendance' => ['employee_number', 'name', 'log_date', 'hours_worked', 'late_minutes', 'overtime_hours', 'status'],
        'benefits' => ['name', 'benefit_type', 'provider', 'policy_number', 'coverage_amount', 'start_date', 'end_date', 'status', 'beneficiaries'],
        'assets' => ['asset_number', 'item', 'serial_number', 'assigned_to', 'status', 'condition', 'issue_date', 'returned_date', 'cost'],
        'thirteenth_month' => ['run', 'pay_type', 'year', 'employee_number', 'name', 'amount', 'status'],
    ];

    public static function datasets(array $p): void
    {
        Auth::org($p, 'reports.view');
        $out = [];
        foreach (self::NAMES as $n) $out[] = ['name' => $n, 'columns' => self::DATASET_COLUMNS[$n] ?? []];
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
        if ($fmt === 'xlsx' && class_exists('ZipArchive')) {
            Http::file(self::buildXlsx($cols, $rows, (string) $b['dataset']),
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $b['dataset'] . '_report.xlsx');
        }
        // csv (and fallback if the zip extension is unavailable for xlsx)
        $out = implode(',', array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $cols)) . "\n";
        foreach ($rows as $r) $out .= implode(',', array_map(fn($c) => '"' . str_replace('"', '""', (string) ($r[$c] ?? '')) . '"', $cols)) . "\n";
        Http::file($out, 'text/csv', $b['dataset'] . '_report.csv');
    }

    // --- Minimal native .xlsx writer (dependency-free; uses ext-zip) ----------
    private static function colRef(int $i): string
    {
        $s = ''; $i++;
        while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - $m, 26); }
        return $s;
    }

    private static function xlsxCell(string $ref, $val): string
    {
        $s = (string) ($val ?? '');
        if ($s === '') return "<c r=\"$ref\"/>";
        if (is_numeric($s) && !preg_match('/^[+\-]?0\d/', $s) && strlen($s) <= 15) {
            return "<c r=\"$ref\"><v>" . htmlspecialchars($s, ENT_XML1) . "</v></c>";
        }
        return "<c r=\"$ref\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . htmlspecialchars($s, ENT_XML1) . "</t></is></c>";
    }

    private static function buildXlsx(array $cols, array $rows, string $sheet): string
    {
        $rn = 1; $sd = '<row r="1">';
        foreach ($cols as $ci => $c) $sd .= self::xlsxCell(self::colRef($ci) . '1', $c);
        $sd .= '</row>';
        foreach ($rows as $r) {
            $rn++;
            $sd .= "<row r=\"$rn\">";
            foreach ($cols as $ci => $c) $sd .= self::xlsxCell(self::colRef($ci) . $rn, $r[$c] ?? '');
            $sd .= '</row>';
        }
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sd . '</sheetData></worksheet>';
        $name = htmlspecialchars(substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $sheet) ?: 'Sheet1', 0, 31), ENT_XML1);

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheetXml,
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($parts as $path => $content) $zip->addFromString($path, $content);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }
}
