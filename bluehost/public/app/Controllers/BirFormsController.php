<?php
// BIR withholding-tax forms (Philippines).
//   1601-C  Monthly remittance of income tax withheld on COMPENSATION (employees)
//   0619-E  Monthly remittance of EXPANDED withholding tax (consultants / professionals)
//   2307    Certificate of creditable tax withheld at source (per payee)
//   2316    Annual certificate of compensation payment / tax withheld (per employee)
//
// Figures are read from posted payroll (payroll_run_people.deductions):
//   employees   -> deductions.withholding_tax        (compensation WT  -> 1601-C / 2316)
//   consultants -> deductions.withholding_tax_ewt     (expanded WT      -> 0619-E / 2307)
//
// The "generate" endpoints return a clean, print-ready HTML working copy that
// mirrors the official form's fields. It is NOT the official BIR PDF — HR
// reviews the figures here, then files through eBIRForms / eFPS.
class BirFormsController
{
    const CONSULTANT_TYPES = ['CONSULTANT_INDIVIDUAL', 'CONSULTANT_COMPANY'];
    const MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}/bir';
        $r->get("$b/months", [self::class, 'months']);
        $r->get("$b/people", [self::class, 'people']);
        $r->get("$b/1601c", [self::class, 'data1601c']);
        $r->get("$b/0619e", [self::class, 'data0619e']);
        $r->get("$b/2307", [self::class, 'data2307']);
        $r->get("$b/2316", [self::class, 'data2316']);
        $r->post("$b/1601c/generate", [self::class, 'gen1601c']);
        $r->post("$b/0619e/generate", [self::class, 'gen0619e']);
        $r->post("$b/2307/generate", [self::class, 'gen2307']);
        $r->post("$b/2316/generate", [self::class, 'gen2316']);
    }

    private static function company(int $o): array
    {
        $org = Database::one('SELECT name, legal_name, tin, address FROM organizations WHERE id = ?', [$o]);
        return ['name' => $org['legal_name'] ?: $org['name'], 'tin' => $org['tin'] ?: '', 'address' => $org['address'] ?: ''];
    }
    private static function contrib(array $d): array
    {
        return [
            'sss' => (float) ($d['sss'] ?? 0), 'philhealth' => (float) ($d['philhealth'] ?? 0),
            'pagibig' => (float) ($d['pagibig'] ?? 0),
            'wtax' => (float) ($d['withholding_tax'] ?? 0), 'ewt' => (float) ($d['withholding_tax_ewt'] ?? 0),
        ];
    }
    private static function inTypes(): string { return implode(',', array_fill(0, count(self::CONSULTANT_TYPES), '?')); }

    /** Months that have posted payroll, newest first. */
    public static function months(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $rows = Database::all(
            "SELECT DISTINCT YEAR(COALESCE(pp.pay_date, pp.period_end)) y, MONTH(COALESCE(pp.pay_date, pp.period_end)) m
               FROM payroll_runs pr JOIN payroll_periods pp ON pp.id = pr.period_id
              WHERE pr.organization_id = ? ORDER BY y DESC, m DESC", [$o]);
        $out = array_map(fn($r) => ['year' => (int) $r['y'], 'month' => (int) $r['m'],
            'label' => self::MONTHS[(int) $r['m']] . ' ' . $r['y']], $rows);
        $years = array_values(array_unique(array_map(fn($r) => (int) $r['y'], $rows)));
        Http::json(['months' => $out, 'years' => $years]);
    }

    /** Employees or consultants for the form pickers (payroll-gated, no employee.view needed). */
    public static function people(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $c = self::inTypes();
        $op = (Http::query('type', 'employees') === 'consultants') ? 'IN' : 'NOT IN';
        $rows = Database::all(
            "SELECT e.id engagement_id, e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, e.engagement_type
               FROM engagements e JOIN people pe ON pe.id = e.person_id
              WHERE e.organization_id = ? AND e.engagement_type $op ($c) ORDER BY pe.last_name, pe.first_name",
            array_merge([$o], self::CONSULTANT_TYPES));
        Http::json(array_map(fn($r) => ['engagement_id' => (int) $r['engagement_id'],
            'employee_number' => $r['employee_number'], 'name' => $r['name'], 'type' => $r['engagement_type']], $rows));
    }

    // ---- 1601-C: monthly compensation withholding, per employee ------------
    public static function data1601c(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $year = (int) Http::query('year', 0); $month = (int) Http::query('month', 0);
        if (!$year || !$month) throw new HttpError('Choose a month and year', 422);
        $c = self::inTypes();
        $rows = Database::all(
            "SELECT e.id engagement_id, e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, pe.tin,
                    prp.gross_pay, prp.deductions
               FROM payroll_run_people prp
               JOIN payroll_runs pr ON pr.id = prp.run_id
               JOIN payroll_periods pp ON pp.id = pr.period_id
               JOIN engagements e ON e.id = prp.engagement_id
               JOIN people pe ON pe.id = e.person_id
              WHERE pr.organization_id = ? AND e.engagement_type NOT IN ($c)
                AND YEAR(COALESCE(pp.pay_date, pp.period_end)) = ? AND MONTH(COALESCE(pp.pay_date, pp.period_end)) = ?",
            array_merge([$o], self::CONSULTANT_TYPES, [$year, $month]));

        $by = [];
        foreach ($rows as $r) {
            $d = self::contrib(json_decode($r['deductions'] ?: '{}', true) ?: []);
            $gross = (float) $r['gross_pay'];
            $taxable = max($gross - $d['sss'] - $d['philhealth'] - $d['pagibig'], 0);
            $id = (int) $r['engagement_id'];
            if (!isset($by[$id])) $by[$id] = ['engagement_id' => $id, 'employee_number' => $r['employee_number'],
                'name' => $r['name'], 'tin' => $r['tin'] ?: '', 'gross' => 0, 'taxable' => 0, 'withholding_tax' => 0];
            $by[$id]['gross'] += round($gross, 2);
            $by[$id]['taxable'] += round($taxable, 2);
            $by[$id]['withholding_tax'] += round($d['wtax'], 2);
        }
        $lines = array_values($by);
        Http::json([
            'company' => self::company($o), 'year' => $year, 'month' => $month,
            'month_name' => self::MONTHS[$month], 'lines' => $lines,
            'totals' => ['count' => count($lines),
                'taxable' => round(array_sum(array_column($lines, 'taxable')), 2),
                'withholding_tax' => round(array_sum(array_column($lines, 'withholding_tax')), 2)],
        ]);
    }

    // ---- 0619-E: monthly expanded withholding (consultants), per payee -----
    public static function data0619e(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $year = (int) Http::query('year', 0); $month = (int) Http::query('month', 0);
        if (!$year || !$month) throw new HttpError('Choose a month and year', 422);
        $c = self::inTypes();
        $rows = Database::all(
            "SELECT e.id engagement_id, e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, pe.tin,
                    prp.gross_pay, prp.deductions
               FROM payroll_run_people prp
               JOIN payroll_runs pr ON pr.id = prp.run_id
               JOIN payroll_periods pp ON pp.id = pr.period_id
               JOIN engagements e ON e.id = prp.engagement_id
               JOIN people pe ON pe.id = e.person_id
              WHERE pr.organization_id = ? AND e.engagement_type IN ($c)
                AND YEAR(COALESCE(pp.pay_date, pp.period_end)) = ? AND MONTH(COALESCE(pp.pay_date, pp.period_end)) = ?",
            array_merge([$o], self::CONSULTANT_TYPES, [$year, $month]));
        $by = [];
        foreach ($rows as $r) {
            $d = self::contrib(json_decode($r['deductions'] ?: '{}', true) ?: []);
            $id = (int) $r['engagement_id'];
            if (!isset($by[$id])) $by[$id] = ['engagement_id' => $id, 'employee_number' => $r['employee_number'],
                'name' => $r['name'], 'tin' => $r['tin'] ?: '', 'income_payment' => 0, 'ewt' => 0];
            $by[$id]['income_payment'] += round((float) $r['gross_pay'], 2);
            $by[$id]['ewt'] += round($d['ewt'], 2);
        }
        $lines = array_values($by);
        Http::json([
            'company' => self::company($o), 'year' => $year, 'month' => $month, 'month_name' => self::MONTHS[$month],
            'lines' => $lines,
            'totals' => ['count' => count($lines),
                'income_payment' => round(array_sum(array_column($lines, 'income_payment')), 2),
                'ewt' => round(array_sum(array_column($lines, 'ewt')), 2)],
        ]);
    }

    // ---- 2307: creditable-tax certificate for one payee (a quarter) --------
    public static function data2307(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $year = (int) Http::query('year', 0); $eng = (int) Http::query('engagement_id', 0);
        if (!$year || !$eng) throw new HttpError('Choose a payee and year', 422);
        $payee = Database::one(
            "SELECT e.id, e.engagement_type, CONCAT_WS(' ', pe.first_name, pe.last_name) name, pe.tin, pe.address
               FROM engagements e JOIN people pe ON pe.id = e.person_id WHERE e.id = ? AND e.organization_id = ?", [$eng, $o]);
        if (!$payee) throw new HttpError('Payee not found', 404);
        $rows = Database::all(
            "SELECT MONTH(COALESCE(pp.pay_date, pp.period_end)) m, prp.gross_pay, prp.deductions
               FROM payroll_run_people prp
               JOIN payroll_runs pr ON pr.id = prp.run_id
               JOIN payroll_periods pp ON pp.id = pr.period_id
              WHERE pr.organization_id = ? AND prp.engagement_id = ? AND YEAR(COALESCE(pp.pay_date, pp.period_end)) = ?",
            [$o, $eng, $year]);
        $months = array_fill(1, 12, ['income' => 0.0, 'ewt' => 0.0]);
        foreach ($rows as $r) {
            $m = (int) $r['m']; $d = self::contrib(json_decode($r['deductions'] ?: '{}', true) ?: []);
            $months[$m]['income'] += round((float) $r['gross_pay'], 2);
            $months[$m]['ewt'] += round($d['ewt'], 2);
        }
        $monthly = [];
        foreach ($months as $m => $v) $monthly[] = ['month' => $m, 'month_name' => self::MONTHS[$m],
            'income' => round($v['income'], 2), 'ewt' => round($v['ewt'], 2)];
        Http::json([
            'company' => self::company($o), 'year' => $year,
            'payee' => ['name' => $payee['name'], 'tin' => $payee['tin'] ?: '', 'address' => $payee['address'] ?: '',
                'type' => $payee['engagement_type']],
            'months' => $monthly,
            'total_income' => round(array_sum(array_column($monthly, 'income')), 2),
            'total_ewt' => round(array_sum(array_column($monthly, 'ewt')), 2),
        ]);
    }

    // ---- 2316: annual compensation certificate for one employee -----------
    public static function data2316(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $year = (int) Http::query('year', 0); $eng = (int) Http::query('engagement_id', 0);
        if (!$year || !$eng) throw new HttpError('Choose an employee and year', 422);
        $emp = Database::one(
            "SELECT e.id, e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, pe.tin, pe.address, pe.birth_date
               FROM engagements e JOIN people pe ON pe.id = e.person_id WHERE e.id = ? AND e.organization_id = ?", [$eng, $o]);
        if (!$emp) throw new HttpError('Employee not found', 404);
        $rows = Database::all(
            "SELECT prp.gross_pay, prp.deductions
               FROM payroll_run_people prp
               JOIN payroll_runs pr ON pr.id = prp.run_id
               JOIN payroll_periods pp ON pp.id = pr.period_id
              WHERE pr.organization_id = ? AND prp.engagement_id = ? AND YEAR(COALESCE(pp.pay_date, pp.period_end)) = ?",
            [$o, $eng, $year]);
        $sum = ['gross' => 0.0, 'sss' => 0.0, 'philhealth' => 0.0, 'pagibig' => 0.0, 'wtax' => 0.0];
        foreach ($rows as $r) {
            $d = self::contrib(json_decode($r['deductions'] ?: '{}', true) ?: []);
            $sum['gross'] += round((float) $r['gross_pay'], 2);
            $sum['sss'] += $d['sss']; $sum['philhealth'] += $d['philhealth'];
            $sum['pagibig'] += $d['pagibig']; $sum['wtax'] += $d['wtax'];
        }
        $contrib = round($sum['sss'] + $sum['philhealth'] + $sum['pagibig'], 2);
        Http::json([
            'company' => self::company($o), 'year' => $year,
            'employee' => ['name' => $emp['name'], 'employee_number' => $emp['employee_number'],
                'tin' => $emp['tin'] ?: '', 'address' => $emp['address'] ?: '', 'birth_date' => $emp['birth_date']],
            'gross' => round($sum['gross'], 2),
            'sss' => round($sum['sss'], 2), 'philhealth' => round($sum['philhealth'], 2), 'pagibig' => round($sum['pagibig'], 2),
            'total_contributions' => $contrib,
            'taxable' => round($sum['gross'] - $contrib, 2),
            'withholding_tax' => round($sum['wtax'], 2),
        ]);
    }

    // ---------------- Printable form generation (returns HTML) --------------
    private static function n($v): string { return number_format((float) $v, 2); }
    private static function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }

    private static function page(string $formNo, string $formTitle, string $inner): void
    {
        $css = 'body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#111;margin:0;padding:24px;background:#f3f4f6}'
            . '.sheet{max-width:820px;margin:0 auto;background:#fff;padding:28px 32px;box-shadow:0 1px 4px rgba(0,0,0,.1)}'
            . '.hd{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:14px}'
            . '.formno{font-size:22px;font-weight:700;letter-spacing:1px}.formsub{font-size:11px;color:#444}'
            . '.title{font-size:14px;font-weight:700;text-align:right;max-width:360px}'
            . '.box{border:1px solid #999;border-radius:4px;padding:8px 10px;margin-bottom:10px}'
            . '.box h4{margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#666}'
            . '.row{display:flex;gap:16px;flex-wrap:wrap}.row>div{flex:1;min-width:180px}'
            . '.lbl{font-size:10px;color:#666;text-transform:uppercase}.val{font-size:13px;font-weight:600}'
            . 'table{border-collapse:collapse;width:100%;margin-top:6px}th,td{border:1px solid #bbb;padding:5px 7px;font-size:11px}'
            . 'th{background:#f3f4f6;text-align:left}td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}'
            . 'tfoot td{font-weight:700;background:#fafafa}'
            . '.tot{display:flex;justify-content:flex-end;margin-top:12px}.totbox{border:2px solid #111;border-radius:4px;padding:8px 16px;text-align:right}'
            . '.totbox .lbl{font-size:10px}.totbox .amt{font-size:20px;font-weight:700}'
            . '.sig{display:flex;justify-content:space-between;gap:24px;margin-top:34px}.sig>div{flex:1;text-align:center}'
            . '.sig .line{border-top:1px solid #333;margin-top:38px;padding-top:4px;font-size:11px}'
            . '.note{margin-top:18px;padding:8px 10px;border:1px dashed #c58a00;background:#fff8e6;color:#7a5200;font-size:10px;border-radius:4px}'
            . '.bar{height:5px;background:linear-gradient(90deg,#EA4335,#F9AB00,#34A853,#4285F4);margin:-28px -32px 16px}'
            . '@media print{body{background:#fff;padding:0}.sheet{box-shadow:none;max-width:none}.noprint{display:none}}'
            . '.noprint{margin-bottom:14px}.btn{background:#1A73E8;color:#fff;border:0;border-radius:6px;padding:8px 14px;font-size:13px;cursor:pointer}';
        $t = self::e($formTitle);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><meta charset='utf-8'><title>BIR $formNo</title><style>$css</style></head><body>"
            . "<div class='noprint'><button class='btn' onclick='window.print()'>🖨 Print / Save as PDF</button></div>"
            . "<div class='sheet'><div class='bar'></div>"
            . "<div class='hd'><div><div class='formno'>BIR Form $formNo</div><div class='formsub'>Bureau of Internal Revenue · Republic of the Philippines</div></div>"
            . "<div class='title'>$t</div></div>"
            . $inner
            . "<div class='note'><b>System-generated working copy.</b> This mirrors the fields of BIR Form $formNo for your review; it is not the official BIR form. "
            . "Verify the figures, then file and pay through eBIRForms or eFPS. Amounts are based on posted payroll.</div>"
            . "</div></body></html>";
        exit;
    }

    private static function agentBox(array $company): string
    {
        return "<div class='box'><h4>Withholding Agent</h4><div class='row'>"
            . "<div><div class='lbl'>Registered name</div><div class='val'>" . self::e($company['name']) . "</div></div>"
            . "<div><div class='lbl'>TIN</div><div class='val'>" . (self::e($company['tin']) ?: '—') . "</div></div>"
            . "</div><div class='lbl' style='margin-top:6px'>Registered address</div><div class='val' style='font-weight:400'>" . (self::e($company['address']) ?: '—') . "</div></div>";
    }

    public static function gen1601c(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $b = Http::body();
        $company = self::company($o);
        $lines = is_array($b['lines'] ?? null) ? $b['lines'] : [];
        $monthName = self::e($b['month_name'] ?? ''); $year = (int) ($b['year'] ?? 0);
        $rows = ''; $totTax = 0.0; $totWt = 0.0;
        foreach ($lines as $ln) {
            $tax = (float) ($ln['taxable'] ?? 0); $wt = (float) ($ln['withholding_tax'] ?? 0);
            $totTax += $tax; $totWt += $wt;
            $rows .= "<tr><td>" . self::e($ln['employee_number'] ?? '') . "</td><td>" . self::e($ln['name'] ?? '')
                . "</td><td>" . self::e($ln['tin'] ?? '') . "</td><td class='num'>" . self::n($tax)
                . "</td><td class='num'>" . self::n($wt) . "</td></tr>";
        }
        $inner = self::agentBox($company)
            . "<div class='row'><div><div class='lbl'>Return period (month)</div><div class='val'>$monthName $year</div></div>"
            . "<div><div class='lbl'>No. of employees</div><div class='val'>" . count($lines) . "</div></div></div>"
            . "<table><thead><tr><th>Emp. No.</th><th>Employee</th><th>TIN</th><th class='num'>Taxable compensation</th><th class='num'>Tax withheld</th></tr></thead>"
            . "<tbody>$rows</tbody><tfoot><tr><td colspan='3'>Totals</td><td class='num'>" . self::n($totTax) . "</td><td class='num'>" . self::n($totWt) . "</td></tr></tfoot></table>"
            . "<div class='tot'><div class='totbox'><div class='lbl'>Total tax withheld — remittable (1601-C)</div><div class='amt'>₱ " . self::n($totWt) . "</div></div></div>"
            . self::signatures();
        self::page('1601-C', 'Monthly Remittance Return of Income Taxes Withheld on Compensation', $inner);
    }

    public static function gen0619e(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $b = Http::body();
        $company = self::company($o);
        $lines = is_array($b['lines'] ?? null) ? $b['lines'] : [];
        $monthName = self::e($b['month_name'] ?? ''); $year = (int) ($b['year'] ?? 0);
        $rows = ''; $totInc = 0.0; $totEwt = 0.0;
        foreach ($lines as $ln) {
            $inc = (float) ($ln['income_payment'] ?? 0); $ewt = (float) ($ln['ewt'] ?? 0);
            $totInc += $inc; $totEwt += $ewt;
            $rows .= "<tr><td>" . self::e($ln['name'] ?? '') . "</td><td>" . self::e($ln['tin'] ?? '')
                . "</td><td class='num'>" . self::n($inc) . "</td><td class='num'>" . self::n($ewt) . "</td></tr>";
        }
        $inner = self::agentBox($company)
            . "<div class='row'><div><div class='lbl'>Return period (month)</div><div class='val'>$monthName $year</div></div>"
            . "<div><div class='lbl'>No. of payees</div><div class='val'>" . count($lines) . "</div></div></div>"
            . "<table><thead><tr><th>Payee</th><th>TIN</th><th class='num'>Income payment</th><th class='num'>Expanded tax withheld</th></tr></thead>"
            . "<tbody>$rows</tbody><tfoot><tr><td colspan='2'>Totals</td><td class='num'>" . self::n($totInc) . "</td><td class='num'>" . self::n($totEwt) . "</td></tr></tfoot></table>"
            . "<div class='tot'><div class='totbox'><div class='lbl'>Total EWT — remittable (0619-E)</div><div class='amt'>₱ " . self::n($totEwt) . "</div></div></div>"
            . self::signatures();
        self::page('0619-E', 'Monthly Remittance Form for Creditable Income Taxes Withheld (Expanded)', $inner);
    }

    public static function gen2307(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $b = Http::body();
        $company = self::company($o);
        $payee = is_array($b['payee'] ?? null) ? $b['payee'] : [];
        $atc = self::e($b['atc'] ?? 'WI010 / WI011'); $rate = (float) ($b['rate'] ?? 10);
        $nature = self::e($b['nature'] ?? 'Professional fees');
        $items = is_array($b['items'] ?? null) ? $b['items'] : []; // [{quarter_month, income}]
        $rows = ''; $totInc = 0.0; $totTax = 0.0;
        foreach ($items as $it) {
            $inc = (float) ($it['income'] ?? 0); if ($inc <= 0) continue;
            $tax = round($inc * $rate / 100, 2); $totInc += $inc; $totTax += $tax;
            $rows .= "<tr><td>" . self::e($it['label'] ?? '') . "</td><td>$nature</td><td class='num'>" . self::e($atc)
                . "</td><td class='num'>" . self::n($inc) . "</td><td class='num'>" . rtrim(rtrim(number_format($rate, 2), '0'), '.') . "%</td><td class='num'>" . self::n($tax) . "</td></tr>";
        }
        $inner = "<div class='row'>"
            . "<div class='box' style='flex:1'><h4>Payee</h4><div class='lbl'>Name</div><div class='val'>" . self::e($payee['name'] ?? '') . "</div>"
            . "<div class='lbl' style='margin-top:4px'>TIN</div><div class='val'>" . (self::e($payee['tin'] ?? '') ?: '—') . "</div>"
            . "<div class='lbl' style='margin-top:4px'>Address</div><div class='val' style='font-weight:400'>" . (self::e($payee['address'] ?? '') ?: '—') . "</div></div>"
            . "<div class='box' style='flex:1'><h4>Payor (Withholding Agent)</h4><div class='lbl'>Name</div><div class='val'>" . self::e($company['name']) . "</div>"
            . "<div class='lbl' style='margin-top:4px'>TIN</div><div class='val'>" . (self::e($company['tin']) ?: '—') . "</div>"
            . "<div class='lbl' style='margin-top:4px'>Address</div><div class='val' style='font-weight:400'>" . (self::e($company['address']) ?: '—') . "</div></div></div>"
            . "<table><thead><tr><th>Period</th><th>Nature of payment</th><th class='num'>ATC</th><th class='num'>Amount of income payment</th><th class='num'>Rate</th><th class='num'>Tax withheld</th></tr></thead>"
            . "<tbody>$rows</tbody><tfoot><tr><td colspan='3'>Totals</td><td class='num'>" . self::n($totInc) . "</td><td></td><td class='num'>" . self::n($totTax) . "</td></tr></tfoot></table>"
            . "<div class='tot'><div class='totbox'><div class='lbl'>Total creditable tax withheld (2307)</div><div class='amt'>₱ " . self::n($totTax) . "</div></div></div>"
            . self::signatures('Payor / Authorized representative', 'Payee');
        self::page('2307', 'Certificate of Creditable Tax Withheld at Source', $inner);
    }

    public static function gen2316(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $b = Http::body();
        $company = self::company($o);
        $emp = is_array($b['employee'] ?? null) ? $b['employee'] : [];
        $year = (int) ($b['year'] ?? 0);
        $gross = (float) ($b['gross'] ?? 0); $sss = (float) ($b['sss'] ?? 0);
        $phic = (float) ($b['philhealth'] ?? 0); $hdmf = (float) ($b['pagibig'] ?? 0);
        $contrib = round($sss + $phic + $hdmf, 2);
        $taxable = (float) ($b['taxable'] ?? ($gross - $contrib));
        $wt = (float) ($b['withholding_tax'] ?? 0);
        $line = fn($l, $v) => "<tr><td>" . self::e($l) . "</td><td class='num'>" . self::n($v) . "</td></tr>";
        $inner = "<div class='row'>"
            . "<div class='box' style='flex:1'><h4>Employee</h4><div class='lbl'>Name</div><div class='val'>" . self::e($emp['name'] ?? '') . "</div>"
            . "<div class='lbl' style='margin-top:4px'>TIN</div><div class='val'>" . (self::e($emp['tin'] ?? '') ?: '—') . "</div>"
            . "<div class='lbl' style='margin-top:4px'>Address</div><div class='val' style='font-weight:400'>" . (self::e($emp['address'] ?? '') ?: '—') . "</div></div>"
            . "<div class='box' style='flex:1'><h4>Employer</h4><div class='lbl'>Name</div><div class='val'>" . self::e($company['name']) . "</div>"
            . "<div class='lbl' style='margin-top:4px'>TIN</div><div class='val'>" . (self::e($company['tin']) ?: '—') . "</div>"
            . "<div class='lbl' style='margin-top:4px'>Year</div><div class='val'>" . $year . "</div></div></div>"
            . "<table><thead><tr><th>Compensation summary for the year</th><th class='num'>Amount (₱)</th></tr></thead><tbody>"
            . $line('Gross compensation income', $gross)
            . $line('Less: SSS contributions', $sss) . $line('Less: PhilHealth contributions', $phic) . $line('Less: Pag-IBIG contributions', $hdmf)
            . "<tr><td>Total non-taxable / mandatory contributions</td><td class='num'>" . self::n($contrib) . "</td></tr>"
            . "<tr><td><b>Taxable compensation income</b></td><td class='num'><b>" . self::n($taxable) . "</b></td></tr>"
            . "</tbody></table>"
            . "<div class='tot'><div class='totbox'><div class='lbl'>Tax withheld for the year (2316)</div><div class='amt'>₱ " . self::n($wt) . "</div></div></div>"
            . self::signatures('Employer / Authorized representative', 'Employee');
        self::page('2316', 'Certificate of Compensation Payment / Tax Withheld for Compensation', $inner);
    }

    private static function signatures(string $left = 'Authorized representative', string $right = 'Date'): string
    {
        return "<div class='sig'><div><div class='line'>" . self::e($left) . "</div></div>"
            . "<div><div class='line'>" . self::e($right) . "</div></div></div>";
    }
}
