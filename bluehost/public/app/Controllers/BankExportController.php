<?php
class BankExportController
{
    const SYSTEM_FIELDS = ['bank_account', 'account_name', 'full_name', 'net_pay', 'amount', 'employee_number',
        'reference', 'payroll_date', 'bank_name', 'department', 'cost_center', 'engagement_type'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}';
        $r->get("$b/bank-templates", [self::class, 'listTemplates']);
        $r->get("$b/bank-fields", [self::class, 'fields']);
        $r->post("$b/bank-templates", [self::class, 'createTemplate']);
        $r->put("$b/bank-templates/{id}", [self::class, 'updateTemplate']);
        $r->post("$b/payroll/runs/{run_id}/bank-export/preview", [self::class, 'preview']);
        $r->post("$b/payroll/runs/{run_id}/bank-export/generate", [self::class, 'generate']);
        $r->get("$b/bank-export-runs", [self::class, 'runs']);
    }

    private static function templateDict(array $t): array
    {
        $cols = Database::all('SELECT * FROM bank_export_columns WHERE template_id = ? ORDER BY order_index', [$t['id']]);
        return array_merge($t, ['id' => (int) $t['id'], 'header_required' => (int) $t['header_required'] === 1, 'active' => (int) $t['active'] === 1,
            'columns' => array_map(fn($c) => ['system_field' => $c['system_field'], 'output_header' => $c['output_header'], 'order_index' => (int) $c['order_index'],
                'default_value' => $c['default_value'], 'formatting' => $c['formatting'], 'padding' => $c['padding'], 'required' => (int) $c['required'] === 1], $cols)]);
    }

    public static function listTemplates(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        Http::json(array_map([self::class, 'templateDict'], Database::all('SELECT * FROM bank_export_templates WHERE organization_id = ?', [$o])));
    }

    public static function fields(array $p): void
    {
        Auth::org($p, 'payroll.view');
        Http::json(['system_fields' => self::SYSTEM_FIELDS, 'formats' => ['', 'amount', 'amount_cents', 'date', 'upper'], 'file_types' => ['CSV', 'TXT', 'XLSX', 'FIXED_WIDTH']]);
    }

    public static function createTemplate(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.export'); $b = Http::body();
        $id = Database::insert('bank_export_templates', ['uuid' => Util::uuid(), 'organization_id' => $o, 'template_name' => $b['template_name'],
            'bank_name' => $b['bank_name'], 'file_type' => $b['file_type'] ?? 'CSV', 'delimiter' => $b['delimiter'] ?? ',',
            'header_required' => isset($b['header_required']) ? (int) (bool) $b['header_required'] : 1, 'date_format' => $b['date_format'] ?? '%Y-%m-%d',
            'decimal_places' => $b['decimal_places'] ?? 2, 'filename_pattern' => $b['filename_pattern'] ?? '{bank}_{org}_{date}', 'template_version' => 1]);
        foreach ($b['columns'] ?? [] as $i => $c) Database::insert('bank_export_columns', ['template_id' => $id, 'order_index' => $c['order_index'] ?? $i,
            'system_field' => $c['system_field'], 'output_header' => $c['output_header'], 'default_value' => $c['default_value'] ?? null,
            'formatting' => $c['formatting'] ?? null, 'padding' => $c['padding'] ?? null, 'required' => !empty($c['required']) ? 1 : 0]);
        Http::json(self::templateDict(Database::one('SELECT * FROM bank_export_templates WHERE id = ?', [$id])));
    }

    public static function updateTemplate(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.export'); $b = Http::body();
        $t = Database::one('SELECT * FROM bank_export_templates WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$t) throw new HttpError('Template not found', 404);
        $u = ['template_version' => (int) $t['template_version'] + 1];
        foreach (['template_name', 'bank_name', 'file_type', 'delimiter', 'date_format', 'decimal_places', 'filename_pattern'] as $f) if (isset($b[$f])) $u[$f] = $b[$f];
        if (isset($b['header_required'])) $u['header_required'] = (int) (bool) $b['header_required'];
        Database::update('bank_export_templates', (int) $t['id'], $u);
        Database::exec('DELETE FROM bank_export_columns WHERE template_id = ?', [$t['id']]);
        foreach ($b['columns'] ?? [] as $i => $c) Database::insert('bank_export_columns', ['template_id' => $t['id'], 'order_index' => $c['order_index'] ?? $i,
            'system_field' => $c['system_field'], 'output_header' => $c['output_header'], 'default_value' => $c['default_value'] ?? null,
            'formatting' => $c['formatting'] ?? null, 'padding' => $c['padding'] ?? null, 'required' => !empty($c['required']) ? 1 : 0]);
        Http::json(self::templateDict(Database::one('SELECT * FROM bank_export_templates WHERE id = ?', [$t['id']])));
    }

    private static function getRun(int $orgId, int $runId): array
    {
        $run = Database::one('SELECT * FROM payroll_runs WHERE id = ? AND organization_id = ?', [$runId, $orgId]);
        if (!$run) throw new HttpError('Payroll run not found', 404);
        return $run;
    }

    private static function resolveFields(array $run, array $line, array $eng, array $person): array
    {
        $period = Database::one('SELECT * FROM payroll_periods WHERE id = ?', [$run['period_id']]);
        $dept = $eng['department_id'] ? Database::scalar('SELECT name FROM departments WHERE id = ?', [$eng['department_id']]) : '';
        $payDate = $period['pay_date'] ?: $period['period_end'];
        $net = (float) $line['net_pay'];
        $name = trim($person['first_name'] . ' ' . $person['last_name']);
        return ['bank_account' => $person['bank_account_number'] ?? '', 'account_name' => $person['bank_account_name'] ?: $name,
            'full_name' => $name, 'net_pay' => $net, 'amount' => $net, 'employee_number' => $eng['employee_number'] ?? '',
            'reference' => $eng['employee_number'] ?? '', 'payroll_date' => $payDate, 'bank_name' => $person['bank_name'] ?? '',
            'department' => $dept ?: '', 'cost_center' => $eng['cost_center'] ?? '', 'engagement_type' => $eng['engagement_type']];
    }

    private static function strftimeToPhp(string $f): string
    {
        return strtr($f, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%y' => 'y', '%H' => 'H', '%M' => 'i', '%S' => 's']);
    }

    private static function applyFormat($value, array $col, array $tpl): string
    {
        $fmt = strtolower($col['formatting'] ?? '');
        if ($value === null || $value === '') $value = $col['default_value'] ?? '';
        if ($fmt === 'amount') $value = number_format((float) $value, (int) $tpl['decimal_places'], '.', '');
        elseif ($fmt === 'amount_cents') $value = (string) (int) round((float) $value * 100);
        elseif ($fmt === 'date') { $ts = strtotime((string) $value); $value = $ts ? date(self::strftimeToPhp($tpl['date_format']), $ts) : (string) $value; }
        elseif ($fmt === 'upper') $value = strtoupper((string) $value);
        else $value = (string) $value;
        if (!empty($col['padding'])) {
            $parts = explode(':', $col['padding']); $side = $parts[0] ?? 'left'; $width = (int) ($parts[1] ?? 0); $fill = $parts[2] ?? '0';
            if ($width) $value = str_pad($value, $width, $fill !== '' ? $fill : ' ', $side === 'left' ? STR_PAD_LEFT : STR_PAD_RIGHT);
        }
        return $value;
    }

    private static function buildRows(array $run, array $filters): array
    {
        $sql = 'SELECT prp.*, e.employee_number, e.department_id, e.cost_center, e.engagement_type, e.person_id
                  FROM payroll_run_people prp JOIN engagements e ON e.id = prp.engagement_id WHERE prp.run_id = ?';
        $params = [$run['id']];
        if (!empty($filters['engagement_type'])) { $sql .= ' AND e.engagement_type = ?'; $params[] = $filters['engagement_type']; }
        if (!empty($filters['department_id'])) { $sql .= ' AND e.department_id = ?'; $params[] = (int) $filters['department_id']; }
        $out = [];
        foreach (Database::all($sql, $params) as $line) {
            $person = Database::one('SELECT * FROM people WHERE id = ?', [$line['person_id']]);
            $out[] = ['name' => trim($person['first_name'] . ' ' . $person['last_name']),
                'fields' => self::resolveFields($run, $line, $line, $person)];
        }
        return $out;
    }

    private static function validate(array $rows): array
    {
        $missing = []; $invalid = []; $zero = []; $seen = [];
        foreach ($rows as $r) {
            $acct = trim((string) $r['fields']['bank_account']); $name = $r['name'];
            if ($acct === '') $missing[] = $name;
            else { if (!ctype_digit($acct) || strlen($acct) < 6) $invalid[] = "$name ($acct)"; $seen[$acct] = ($seen[$acct] ?? 0) + 1; }
            if ((float) $r['fields']['net_pay'] <= 0) $zero[] = $name;
        }
        $dups = array_keys(array_filter($seen, fn($c) => $c > 1));
        $errors = [];
        if ($missing) $errors[] = count($missing) . ' missing bank account(s)';
        if ($invalid) $errors[] = count($invalid) . ' invalid account number(s)';
        if ($dups) $errors[] = count($dups) . ' duplicate account(s)';
        if ($zero) $errors[] = count($zero) . ' zero/negative net pay';
        return ['errors' => $errors, 'missing_bank' => $missing, 'invalid_accounts' => $invalid, 'duplicate_accounts' => $dups,
            'zero_or_negative' => $zero, 'has_critical' => (bool) ($missing || $invalid || $dups || $zero)];
    }

    public static function preview(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view'); $b = Http::body();
        $run = self::getRun($o, (int) $p['run_id']);
        $tpl = Database::one('SELECT * FROM bank_export_templates WHERE id = ? AND organization_id = ?', [(int) $b['template_id'], $o]);
        if (!$tpl) throw new HttpError('Template not found', 404);
        $cols = Database::all('SELECT * FROM bank_export_columns WHERE template_id = ? ORDER BY order_index', [$tpl['id']]);
        $resolved = self::buildRows($run, $b['filters'] ?? []);
        $headers = array_map(fn($c) => $c['output_header'], $cols);
        $table = []; $total = 0.0;
        foreach ($resolved as $r) {
            $table[] = array_map(fn($c) => self::applyFormat($r['fields'][$c['system_field']] ?? null, $c, $tpl), $cols);
            $total += (float) $r['fields']['net_pay'];
        }
        Http::json(['headers' => $headers, 'rows' => array_slice($table, 0, 100), 'employee_count' => count($resolved),
            'total_amount' => round($total, 2), 'validation' => self::validate($resolved),
            'template' => ['name' => $tpl['template_name'], 'bank' => $tpl['bank_name'], 'version' => (int) $tpl['template_version'], 'file_type' => $tpl['file_type']]]);
    }

    public static function generate(array $p): void
    {
        [$u, $o] = Auth::org($p, 'payroll.export'); $b = Http::body();
        $run = self::getRun($o, (int) $p['run_id']);
        $tpl = Database::one('SELECT * FROM bank_export_templates WHERE id = ? AND organization_id = ?', [(int) $b['template_id'], $o]);
        if (!$tpl) throw new HttpError('Template not found', 404);
        $cols = Database::all('SELECT * FROM bank_export_columns WHERE template_id = ? ORDER BY order_index', [$tpl['id']]);
        $resolved = self::buildRows($run, $b['filters'] ?? []);
        $validation = self::validate($resolved);
        if ($validation['has_critical'] && empty($b['allow_with_errors'])) {
            Http::error('Validation errors block generation', 422, ['validation' => $validation]);
        }
        $headers = array_map(fn($c) => $c['output_header'], $cols);
        $rows = []; $total = 0.0;
        foreach ($resolved as $r) { $rows[] = array_map(fn($c) => self::applyFormat($r['fields'][$c['system_field']] ?? null, $c, $tpl), $cols); $total += (float) $r['fields']['net_pay']; }

        $delim = $tpl['delimiter'] ?: ',';
        $lines = [];
        if ((int) $tpl['header_required'] === 1) $lines[] = implode($delim, $headers);
        foreach ($rows as $row) $lines[] = implode($delim, $row);
        $data = implode("\n", $lines) . "\n";
        $hash = hash('sha256', $data);
        $org = Database::scalar('SELECT code FROM organizations WHERE id = ?', [$o]);
        $fname = str_replace(['{bank}', '{org}', '{date}'], [str_replace(' ', '', $tpl['bank_name']), $org, date('Ymd')], $tpl['filename_pattern']) . '.csv';

        Database::insert('bank_export_runs', ['uuid' => Util::uuid(), 'organization_id' => $o, 'payroll_run_id' => $run['id'],
            'template_id' => $tpl['id'], 'template_version' => (int) $tpl['template_version'], 'file_name' => $fname,
            'file_hash' => $hash, 'row_count' => count($rows), 'total_amount' => round($total, 2),
            'filters_json' => json_encode($b['filters'] ?? []), 'generated_by' => $u['id']]);
        Audit::record('payroll.bank_export', $u, ['organization_id' => $o, 'entity' => 'payroll_run', 'entity_id' => $run['id'], 'after' => ['file' => $fname, 'hash' => $hash]]);
        Http::file($data, 'text/csv', $fname);
    }

    public static function runs(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        Http::json(array_map(fn($r) => ['uuid' => $r['uuid'], 'file_name' => $r['file_name'], 'file_hash' => $r['file_hash'],
            'row_count' => (int) $r['row_count'], 'total_amount' => (float) $r['total_amount'], 'template_version' => (int) $r['template_version'], 'generated_at' => $r['created_at']],
            Database::all('SELECT * FROM bank_export_runs WHERE organization_id = ? ORDER BY id DESC', [$o])));
    }
}
