<?php
// Payslip snapshot build, versioned generation, and printable HTML rendering.

class PayslipService
{
    const CONSULTANT_TYPES = ['CONSULTANT_INDIVIDUAL', 'CONSULTANT_COMPANY'];

    public static function buildSnapshot(array $run, array $line): array
    {
        $eng = Database::one('SELECT * FROM engagements WHERE id = ?', [$line['engagement_id']]);
        $person = Database::one('SELECT * FROM people WHERE id = ?', [$eng['person_id']]);
        $org = Database::one('SELECT * FROM organizations WHERE id = ?', [$run['organization_id']]);
        $period = Database::one('SELECT * FROM payroll_periods WHERE id = ?', [$run['period_id']]);
        $dept = $eng['department_id'] ? Database::scalar('SELECT name FROM departments WHERE id = ?', [$eng['department_id']]) : null;
        $pos = $eng['position_id'] ? Database::scalar('SELECT title FROM positions WHERE id = ?', [$eng['position_id']]) : null;
        $proj = $eng['project_id'] ? Database::scalar('SELECT project_name FROM projects WHERE id = ?', [$eng['project_id']]) : null;
        $isCon = in_array($eng['engagement_type'], self::CONSULTANT_TYPES, true);

        $name = trim(implode(' ', array_filter([$person['first_name'], $person['middle_name'], $person['last_name'], $person['suffix']])));
        $obl = Obligation::summaryAsOf((int) $eng['id'], (int) $person['id'], (int) $run['id'], $period['period_end']);

        return [
            'document_type' => $isCon ? 'PAYMENT_ADVICE' : 'PAYSLIP',
            'header' => [
                'company_name' => $org['name'], 'organization_code' => $org['code'],
                'payroll_period' => $period['name'], 'period_start' => $period['period_start'],
                'period_end' => $period['period_end'], 'pay_date' => $period['pay_date'],
                'name' => $name, 'employee_number' => $eng['employee_number'],
                'department' => $dept, 'position' => $pos, 'employment_type' => $eng['engagement_type'],
                'project' => $proj, 'cost_center' => $eng['cost_center'],
                'bank_account_masked' => Util::maskAccount($person['bank_account_number']),
                'bank_name' => $person['bank_name'],
            ],
            'earnings' => json_decode($line['earnings'] ?: '{}', true),
            'gross_pay' => (float) $line['gross_pay'],
            'deductions' => json_decode($line['deductions'] ?: '{}', true),
            'total_deductions' => (float) $line['total_deductions'],
            'net_pay' => (float) $line['net_pay'],
            'obligations' => $obl,
            'run_reference' => $run['reference'],
            'rule_versions' => json_decode($run['rule_version_snapshot'] ?: '{}', true),
            'prototype_notice' => 'Statutory contributions computed using the 2025 SSS, PhilHealth, Pag-IBIG and BIR (TRAIN) withholding tables. Verify against the latest official circulars.',
        ];
    }

    /** (Re)generate one engagement's current payslip in a run; returns the payslip row. */
    public static function generateForEngagement(array $run, int $engagementId, ?int $userId): array
    {
        $existing = Database::one(
            'SELECT * FROM payslips WHERE payroll_run_id = ? AND engagement_id = ? AND is_current = 1',
            [$run['id'], $engagementId]);
        if ($existing) return $existing;

        $line = Database::one('SELECT * FROM payroll_run_people WHERE run_id = ? AND engagement_id = ?',
            [$run['id'], $engagementId]);
        if (!$line) throw new HttpError('No payroll line for this engagement', 404);

        $snap = self::buildSnapshot($run, $line);
        $snap['version'] = 1;
        $eng = Database::one('SELECT person_id FROM engagements WHERE id = ?', [$engagementId]);
        $id = Database::insert('payslips', [
            'uuid' => Util::uuid(), 'organization_id' => $run['organization_id'], 'payroll_run_id' => $run['id'],
            'engagement_id' => $engagementId, 'person_id' => $eng['person_id'], 'document_type' => $snap['document_type'],
            'version' => 1, 'is_current' => 1, 'status' => 'ISSUED', 'net_pay' => $line['net_pay'],
            'snapshot' => json_encode($snap), 'generated_by' => $userId,
        ]);
        return Database::one('SELECT * FROM payslips WHERE id = ?', [$id]);
    }

    public static function generateForRun(array $run, ?int $userId): int
    {
        $lines = Database::all('SELECT * FROM payroll_run_people WHERE run_id = ?', [$run['id']]);
        $count = 0;
        foreach ($lines as $line) {
            $prior = Database::one('SELECT * FROM payslips WHERE payroll_run_id = ? AND engagement_id = ? AND is_current = 1',
                [$run['id'], $line['engagement_id']]);
            $version = 1;
            if ($prior) {
                Database::update('payslips', (int) $prior['id'], ['is_current' => 0, 'status' => 'SUPERSEDED']);
                $version = (int) $prior['version'] + 1;
            }
            $snap = self::buildSnapshot($run, $line);
            $snap['version'] = $version;
            $eng = Database::one('SELECT person_id FROM engagements WHERE id = ?', [$line['engagement_id']]);
            Database::insert('payslips', [
                'uuid' => Util::uuid(), 'organization_id' => $run['organization_id'], 'payroll_run_id' => $run['id'],
                'engagement_id' => $line['engagement_id'], 'person_id' => $eng['person_id'],
                'document_type' => $snap['document_type'], 'version' => $version, 'is_current' => 1, 'status' => 'ISSUED',
                'net_pay' => $line['net_pay'], 'snapshot' => json_encode($snap), 'generated_by' => $userId,
            ]);
            $count++;
        }
        return $count;
    }

    private static function money($v): string { return 'PHP ' . number_format((float) $v, 2); }

    /** Render a printable HTML payslip (browser → Save as PDF on shared hosting). */
    public static function renderHtml(array $snap): string
    {
        $h = $snap['header'];
        $rows = fn($items) => implode('', array_map(
            fn($k, $v) => '<tr><td>' . ucwords(str_replace('_', ' ', $k)) . '</td><td class="n">' . self::money($v) . '</td></tr>',
            array_keys($items), array_values($items)));
        $oblRows = '';
        foreach ($snap['obligations']['rows'] as $o) {
            $oblRows .= '<tr><td>' . htmlspecialchars($o['description']) . '</td><td class="n">' . self::money($o['original_amount'])
                . '</td><td class="n">' . self::money($o['current_deduction']) . '</td><td class="n">' . self::money($o['total_paid'])
                . '</td><td class="n">' . self::money($o['remaining_balance']) . '</td><td class="n">' . $o['remaining_installments'] . '</td></tr>';
        }
        $title = $snap['document_type'] === 'PAYMENT_ADVICE' ? 'Payment Advice' : 'Payslip';
        return '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><style>
          body{font-family:Arial,Helvetica,sans-serif;color:#1f2937;font-size:12px;max-width:760px;margin:20px auto;padding:0 16px}
          h1{font-size:18px;color:#1e3a8a;margin:0} .sub{color:#6b7280;font-size:11px}
          table{width:100%;border-collapse:collapse;margin-top:6px} .amt th,.amt td{border:1px solid #e5e7eb;padding:5px 7px;text-align:left}
          .amt th{background:#f3f4f6} .n{text-align:right} .kv td{padding:2px 4px} .kv td.k{color:#6b7280;width:130px}
          .net{font-size:15px;color:#065f46;font-weight:bold} .section{margin:14px 0 2px;font-weight:bold;border-bottom:2px solid #e5e7eb;padding-bottom:2px}
          .notice{background:#fff7ed;color:#9a3412;padding:5px 8px;font-size:10px;border:1px solid #fed7aa;border-radius:4px;margin-top:10px}
          @media print{.noprint{display:none}}
          </style></head><body>
          <div class="noprint" style="text-align:right;margin-bottom:8px"><button onclick="window.print()">Print / Save as PDF</button></div>
          <h1>' . htmlspecialchars($h['company_name']) . '</h1>
          <div class="sub">' . $title . ' — ' . htmlspecialchars($h['payroll_period']) . ' (' . $h['period_start'] . ' to ' . $h['period_end'] . ')</div>
          <table class="kv">
            <tr><td class="k">Employee</td><td>' . htmlspecialchars($h['name']) . '</td><td class="k">Employee No.</td><td>' . htmlspecialchars($h['employee_number'] ?? '—') . '</td></tr>
            <tr><td class="k">Department</td><td>' . htmlspecialchars($h['department'] ?? '—') . '</td><td class="k">Position</td><td>' . htmlspecialchars($h['position'] ?? '—') . '</td></tr>
            <tr><td class="k">Type</td><td>' . htmlspecialchars($h['employment_type']) . '</td><td class="k">Bank</td><td>' . htmlspecialchars(($h['bank_name'] ?? '') . ' ' . $h['bank_account_masked']) . '</td></tr>
          </table>
          <div style="display:flex;gap:14px;margin-top:8px">
            <div style="flex:1"><div class="section">Earnings</div><table class="amt"><tr><th>Item</th><th class="n">Amount</th></tr>' . $rows($snap['earnings']) . '<tr><td><b>Gross</b></td><td class="n"><b>' . self::money($snap['gross_pay']) . '</b></td></tr></table></div>
            <div style="flex:1"><div class="section">Deductions</div><table class="amt"><tr><th>Item</th><th class="n">Amount</th></tr>' . $rows($snap['deductions']) . '<tr><td><b>Total</b></td><td class="n"><b>' . self::money($snap['total_deductions']) . '</b></td></tr></table></div>
          </div>
          <table class="amt" style="margin-top:10px"><tr><td><b>NET PAY</b></td><td class="n net">' . self::money($snap['net_pay']) . '</td></tr></table>
          <div class="section">Outstanding Obligations (as of ' . $h['period_end'] . ')</div>'
          . ($oblRows ? '<table class="amt"><tr><th>Description</th><th class="n">Original</th><th class="n">This Period</th><th class="n">Total Paid</th><th class="n">Remaining</th><th class="n">Inst. Left</th></tr>' . $oblRows
             . '<tr><td colspan="4"><b>Total Outstanding</b></td><td class="n"><b>' . self::money($snap['obligations']['total_outstanding']) . '</b></td><td></td></tr></table>'
             : '<div class="sub">No outstanding obligations as of this period.</div>')
          . (!empty($snap['prototype_notice']) ? '<div class="notice">' . htmlspecialchars($snap['prototype_notice']) . '</div>' : '')
          . '</body></html>';
    }
}
