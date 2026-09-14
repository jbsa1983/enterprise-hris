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
        $r->get("$b/1604c", [self::class, 'data1604c']);
        $r->get("$b/1604e", [self::class, 'data1604e']);
        $r->post("$b/1604c/generate", [self::class, 'gen1604c']);
        $r->post("$b/1604e/generate", [self::class, 'gen1604e']);
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

    /** Render an "MM/DD/YYYY" string into the form's 8 separate date boxes. */
    private static function dateBoxes(string $mdY): string
    {
        $digits = preg_replace('/\D/', '', $mdY); // MMDDYYYY
        $digits = str_pad(substr($digits, 0, 8), 8, ' ');
        $cells = '';
        for ($i = 0; $i < 8; $i++) $cells .= "<span class='db'>" . self::e($digits[$i] === ' ' ? '' : $digits[$i]) . '</span>';
        return "<span class='dbwrap'>$cells</span>";
    }
    /** Render a TIN string into boxed groups (000-000-000-000). */
    private static function tinBoxes(string $tin): string
    {
        $d = preg_replace('/\D/', '', $tin);
        $groups = [substr($d, 0, 3), substr($d, 3, 3), substr($d, 6, 3), substr($d, 9, 5)];
        $out = '';
        foreach ($groups as $gi => $g) {
            $out .= "<span class='tinb'>" . self::e($g) . '</span>';
            if ($gi < 3) $out .= "<span class='tindash'>-</span>";
        }
        return "<span class='tinwrap'>$out</span>";
    }

    public static function gen2307(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $b = Http::body();
        $company = self::company($o);
        $payee = is_array($b['payee'] ?? null) ? $b['payee'] : [];
        $atc = self::e($b['atc'] ?? '');
        $rate = (float) ($b['rate'] ?? 10);
        $nature = self::e($b['nature'] ?? 'Professional fees');
        $from = self::dateBoxes((string) ($b['period_from'] ?? ''));
        $to = self::dateBoxes((string) ($b['period_to'] ?? ''));
        $cols = is_array($b['cols'] ?? null) ? array_map('floatval', $b['cols']) : [0, 0, 0];
        $c1 = $cols[0] ?? 0; $c2 = $cols[1] ?? 0; $c3 = $cols[2] ?? 0;
        $total = (float) ($b['total'] ?? ($c1 + $c2 + $c3));
        $tax = (float) ($b['tax'] ?? 0);
        $m = fn($v) => $v > 0 ? self::n($v) : '';

        // Part III income rows: our data row first, then blank rows to fill the form.
        $incRows = "<tr><td class='l'>" . $nature . "</td><td class='c'>$atc</td><td class='num'>" . $m($c1) . "</td><td class='num'>" . $m($c2) . "</td><td class='num'>" . $m($c3) . "</td><td class='num'>" . $m($total) . "</td><td class='num'>" . $m($tax) . '</td></tr>';
        for ($i = 0; $i < 9; $i++) $incRows .= "<tr><td class='l'>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td></tr>";
        $bizRows = '';
        for ($i = 0; $i < 8; $i++) $bizRows .= "<tr><td class='l'>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td></tr>";

        $payeeName = self::e($payee['name'] ?? '');
        $payeeAddr = self::e($payee['address'] ?? '');
        $payeeTin = self::tinBoxes((string) ($payee['tin'] ?? ''));
        $coName = self::e($company['name']);
        $coAddr = self::e($company['address']);
        $coTin = self::tinBoxes((string) ($company['tin'] ?? ''));

        self::render2307($from, $to, $payeeTin, $payeeName, $payeeAddr, $coTin, $coName, $coAddr, $incRows, $bizRows, self::n($total), self::n($tax));
    }

    private static function render2307($from, $to, $payeeTin, $payeeName, $payeeAddr, $coTin, $coName, $coAddr, $incRows, $bizRows, $totInc, $totTax): void
    {
        $css = <<<CSS
*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;font-size:9.5px;color:#000;margin:0;padding:14px;background:#eceff3}
.sheet{width:816px;margin:0 auto;background:#fff;border:1.5px solid #000}
.noprint{max-width:816px;margin:0 auto 10px}.btn{background:#1A73E8;color:#fff;border:0;border-radius:6px;padding:8px 14px;font-size:13px;cursor:pointer}
table.g{border-collapse:collapse;width:100%}
.g td,.g th{border:1px solid #000;padding:2px 4px;vertical-align:top}
.bar{background:#d9d9d9;font-weight:bold;text-align:center;padding:2px}
.no{width:16px;text-align:center;font-weight:bold}
.lbl{font-size:9px}.it{font-style:italic}
.field{font-weight:bold;font-size:11px;min-height:15px;padding-top:2px}
.hdr td{border:1px solid #000}.formno{font-size:26px;font-weight:800;line-height:1}
.dbwrap,.tinwrap{display:inline-flex;gap:2px;vertical-align:middle}
.db{display:inline-block;width:14px;height:16px;border:1px solid #000;text-align:center;font-weight:bold;line-height:16px}
.tinb{display:inline-block;min-width:34px;height:16px;border:1px solid #000;text-align:center;font-weight:bold;line-height:16px;padding:0 3px}
.tinb:last-child{min-width:52px}.tindash{line-height:16px;font-weight:bold}
table.p3{border-collapse:collapse;width:100%}
.p3 td,.p3 th{border:1px solid #000;font-size:9px;padding:2px 4px}
.p3 th{background:#fff;text-align:center;font-weight:bold}
.p3 td.l{width:210px}.p3 td.c{text-align:center}.p3 td.num,.p3 th.num{text-align:right}
.p3 tr{height:15px}
.sig{height:34px}.declaration{padding:6px 8px;font-size:9px;line-height:1.35}
@media print{body{background:#fff;padding:0}.sheet{border:none}.noprint{display:none}@page{size:Letter;margin:8mm}}
CSS;

        $barcode = "<div style='font-family:monospace;font-size:22px;letter-spacing:-2px;overflow:hidden;height:34px'>▐█▌│█▐▌│▐█│█▌▐│█▐▌│▐█▌│█▐│▌█▐│█▌▐▌</div><div style='text-align:right;font-size:8px'>2307 01/18ENCS</div>";

        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><meta charset='utf-8'><title>BIR 2307</title><style>$css</style></head><body>"
            . "<div class='noprint'><button class='btn' onclick='window.print()'>🖨 Print / Save as PDF</button></div>"
            . "<div class='sheet'>"
            // Masthead
            . "<table class='g'><tr>"
            . "<td style='width:118px'><div class='lbl'>For BIR&nbsp;&nbsp;BCS/</div><div class='lbl'>Use Only&nbsp;Item:</div><div class='lbl' style='margin-top:6px'>BIR Form No.</div><div class='formno'>2307</div><div class='lbl'>January 2018 (ENCS)</div></td>"
            . "<td style='text-align:center'><div style='font-size:8px'>Republic of the Philippines<br>Department of Finance<br><b>Bureau of Internal Revenue</b></div><div style='font-size:17px;font-weight:800;margin-top:4px'>Certificate of Creditable Tax<br>Withheld at Source</div></td>"
            . "<td style='width:210px'>$barcode</td></tr></table>"
            . "<div style='border:1px solid #000;border-top:none;padding:1px 4px;font-size:9px'>Fill in all applicable spaces. Mark all appropriate boxes with an \"X\".</div>"
            // Item 1 period
            . "<table class='g'><tr><td class='no'>1</td><td>For the Period&nbsp;&nbsp;&nbsp; From $from &nbsp;<span class='it'>(MM/DD/YYYY)</span> &nbsp;&nbsp;&nbsp; To $to &nbsp;<span class='it'>(MM/DD/YYYY)</span></td></tr></table>"
            . "<table class='g'><tr><td class='bar'>Part I &ndash; Payee Information</td></tr></table>"
            . "<table class='g'><tr><td class='no'>2</td><td>Taxpayer Identification Number <span class='it'>(TIN)</span> &nbsp;&nbsp; $payeeTin</td></tr>"
            . "<tr><td class='no'>3</td><td><div class='lbl'>Payee's Name <span class='it'>(Last Name, First Name, Middle Name for Individual OR Registered Name for Non-Individual)</span></div><div class='field'>$payeeName</div></td></tr>"
            . "<tr><td class='no'>4</td><td><div class='lbl'>Registered Address</div><div class='field'>$payeeAddr</div></td></tr>"
            . "<tr><td class='no'>5</td><td><div class='lbl'>Foreign Address, <span class='it'>if applicable</span></div><div class='field'>&nbsp;</div></td></tr></table>"
            . "<table class='g'><tr><td class='bar'>Part II &ndash; Payor Information</td></tr></table>"
            . "<table class='g'><tr><td class='no'>6</td><td>Taxpayer Identification Number <span class='it'>(TIN)</span> &nbsp;&nbsp; $coTin</td></tr>"
            . "<tr><td class='no'>7</td><td><div class='lbl'>Payor's Name <span class='it'>(Last Name, First Name, Middle Name for Individual OR Registered Name for Non-Individual)</span></div><div class='field'>$coName</div></td></tr>"
            . "<tr><td class='no'>8</td><td><div class='lbl'>Registered Address</div><div class='field'>$coAddr</div></td></tr></table>"
            . "<table class='g'><tr><td class='bar'>Part III &ndash; Details of Monthly Income Payments and Taxes Withheld</td></tr></table>"
            // Part III income table
            . "<table class='p3'><thead><tr>"
            . "<th rowspan='2' style='width:210px'>Income Payments Subject to Expanded Withholding Tax</th><th rowspan='2' style='width:44px'>ATC</th>"
            . "<th colspan='4'>AMOUNT OF INCOME PAYMENTS</th><th rowspan='2' style='width:96px'>Tax Withheld for the Quarter</th></tr>"
            . "<tr><th>1st Month of the Quarter</th><th>2nd Month of the Quarter</th><th>3rd Month of the Quarter</th><th>Total</th></tr></thead>"
            . "<tbody>$incRows"
            . "<tr><td class='l'><b>Total</b></td><td></td><td></td><td></td><td></td><td class='num'><b>$totInc</b></td><td class='num'><b>$totTax</b></td></tr>"
            . "<tr><td class='l bar' style='text-align:left'>Money Payments Subject to Withholding of Business Tax (Government &amp; Private)</td><td class='bar'></td><td class='bar'></td><td class='bar'></td><td class='bar'></td><td class='bar'></td><td class='bar'></td></tr>"
            . "$bizRows"
            . "<tr><td class='l'><b>Total</b></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>"
            . "</tbody></table>"
            // Declaration + signatures
            . "<table class='g'><tr><td class='declaration'>We declare under the penalties of perjury that this certificate has been made in good faith, verified by us, and to the best of our knowledge and belief, is true and correct, pursuant to the provisions of the National Internal Revenue Code, as amended, and the regulations issued under authority thereof. Further, we give our consent to the processing of our information as contemplated under the *Data Privacy Act of 2012 (R.A. No. 10173) for legitimate and lawful purposes.</td></tr>"
            . "<tr><td class='sig'>&nbsp;</td></tr>"
            . "<tr><td style='text-align:center;font-size:9px'>Signature over Printed Name of Payor/Payor's Authorized Representative/Tax Agent<br><span class='it'>(Indicate Title/Designation and TIN)</span></td></tr></table>"
            . "<table class='g'><tr><td style='width:50%'><div class='lbl'>Tax Agent Accreditation No./ Attorney's Roll No. (if applicable)</div><div class='field'>&nbsp;</div></td><td><div class='lbl'>Date of Issue <span class='it'>(MM/DD/YYYY)</span></div><div class='field'>&nbsp;</div></td><td><div class='lbl'>Date of Expiry <span class='it'>(MM/DD/YYYY)</span></div><div class='field'>&nbsp;</div></td></tr></table>"
            . "<table class='g'><tr><td class='bar'>CONFORME:</td></tr>"
            . "<tr><td class='sig'>&nbsp;</td></tr>"
            . "<tr><td style='text-align:center;font-size:9px'>Signature over Printed Name of Payee/Payee's Authorized Representative/Tax Agent<br><span class='it'>(Indicate Title/Designation and TIN)</span></td></tr></table>"
            . "<table class='g'><tr><td style='width:50%'><div class='lbl'>Tax Agent Accreditation No./ Attorney's Roll No. (if applicable)</div><div class='field'>&nbsp;</div></td><td><div class='lbl'>Date of Issue <span class='it'>(MM/DD/YYYY)</span></div><div class='field'>&nbsp;</div></td><td><div class='lbl'>Date of Expiry <span class='it'>(MM/DD/YYYY)</span></div><div class='field'>&nbsp;</div></td></tr></table>"
            . "<div style='padding:2px 4px;font-size:8px'>*NOTE: The BIR Data Privacy is in the BIR website (www.bir.gov.ph)</div>"
            . "</div></body></html>";
        exit;
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

    // ---- 1604-C: annual alphalist of compensation withholding (all employees) ----
    public static function data1604c(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $year = (int) Http::query('year', 0);
        if (!$year) throw new HttpError('Choose a year', 422);
        $c = self::inTypes();
        $rows = Database::all(
            "SELECT e.id engagement_id, e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, pe.tin, prp.gross_pay, prp.deductions
               FROM payroll_run_people prp
               JOIN payroll_runs pr ON pr.id = prp.run_id
               JOIN payroll_periods pp ON pp.id = pr.period_id
               JOIN engagements e ON e.id = prp.engagement_id
               JOIN people pe ON pe.id = e.person_id
              WHERE pr.organization_id = ? AND e.engagement_type NOT IN ($c)
                AND YEAR(COALESCE(pp.pay_date, pp.period_end)) = ?",
            array_merge([$o], self::CONSULTANT_TYPES, [$year]));
        $by = [];
        foreach ($rows as $r) {
            $d = self::contrib(json_decode($r['deductions'] ?: '{}', true) ?: []);
            $taxable = max((float) $r['gross_pay'] - $d['sss'] - $d['philhealth'] - $d['pagibig'], 0);
            $id = (int) $r['engagement_id'];
            if (!isset($by[$id])) $by[$id] = ['employee_number' => $r['employee_number'], 'name' => $r['name'], 'tin' => $r['tin'] ?: '', 'gross' => 0, 'taxable' => 0, 'withholding_tax' => 0];
            $by[$id]['gross'] += round((float) $r['gross_pay'], 2);
            $by[$id]['taxable'] += round($taxable, 2);
            $by[$id]['withholding_tax'] += round($d['wtax'], 2);
        }
        $lines = array_values($by);
        Http::json(['company' => self::company($o), 'year' => $year, 'lines' => $lines,
            'totals' => ['count' => count($lines), 'gross' => round(array_sum(array_column($lines, 'gross')), 2),
                'taxable' => round(array_sum(array_column($lines, 'taxable')), 2), 'withholding_tax' => round(array_sum(array_column($lines, 'withholding_tax')), 2)]]);
    }

    // ---- 1604-E: annual alphalist of expanded withholding (all payees) -----------
    public static function data1604e(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $year = (int) Http::query('year', 0);
        if (!$year) throw new HttpError('Choose a year', 422);
        $c = self::inTypes();
        $rows = Database::all(
            "SELECT e.id engagement_id, e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name, pe.tin, prp.gross_pay, prp.deductions
               FROM payroll_run_people prp
               JOIN payroll_runs pr ON pr.id = prp.run_id
               JOIN payroll_periods pp ON pp.id = pr.period_id
               JOIN engagements e ON e.id = prp.engagement_id
               JOIN people pe ON pe.id = e.person_id
              WHERE pr.organization_id = ? AND e.engagement_type IN ($c)
                AND YEAR(COALESCE(pp.pay_date, pp.period_end)) = ?",
            array_merge([$o], self::CONSULTANT_TYPES, [$year]));
        $by = [];
        foreach ($rows as $r) {
            $d = self::contrib(json_decode($r['deductions'] ?: '{}', true) ?: []);
            $id = (int) $r['engagement_id'];
            if (!isset($by[$id])) $by[$id] = ['employee_number' => $r['employee_number'], 'name' => $r['name'], 'tin' => $r['tin'] ?: '', 'income_payment' => 0, 'ewt' => 0];
            $by[$id]['income_payment'] += round((float) $r['gross_pay'], 2);
            $by[$id]['ewt'] += round($d['ewt'], 2);
        }
        $lines = array_values($by);
        Http::json(['company' => self::company($o), 'year' => $year, 'lines' => $lines,
            'totals' => ['count' => count($lines), 'income_payment' => round(array_sum(array_column($lines, 'income_payment')), 2), 'ewt' => round(array_sum(array_column($lines, 'ewt')), 2)]]);
    }

    public static function gen1604c(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $b = Http::body();
        $company = self::company($o); $year = (int) ($b['year'] ?? 0);
        $lines = is_array($b['lines'] ?? null) ? $b['lines'] : [];
        $rows = ''; $tTax = 0.0; $tWt = 0.0;
        foreach ($lines as $ln) {
            $tax = (float) ($ln['taxable'] ?? 0); $wt = (float) ($ln['withholding_tax'] ?? 0);
            $tTax += $tax; $tWt += $wt;
            $rows .= '<tr><td>' . self::e($ln['employee_number'] ?? '') . '</td><td>' . self::e($ln['name'] ?? '')
                . '</td><td>' . self::e($ln['tin'] ?? '') . "</td><td class='num'>" . self::n($tax) . "</td><td class='num'>" . self::n($wt) . '</td></tr>';
        }
        $inner = self::agentBox($company)
            . "<div class='row'><div><div class='lbl'>For the year</div><div class='val'>$year</div></div><div><div class='lbl'>No. of employees</div><div class='val'>" . count($lines) . "</div></div></div>"
            . "<table><thead><tr><th>Emp. No.</th><th>Employee (alphalist)</th><th>TIN</th><th class='num'>Taxable compensation (year)</th><th class='num'>Tax withheld (year)</th></tr></thead>"
            . "<tbody>$rows</tbody><tfoot><tr><td colspan='3'>Totals</td><td class='num'>" . self::n($tTax) . "</td><td class='num'>" . self::n($tWt) . "</td></tr></tfoot></table>"
            . "<div class='tot'><div class='totbox'><div class='lbl'>Total tax withheld on compensation for $year (1604-C)</div><div class='amt'>₱ " . self::n($tWt) . "</div></div></div>"
            . self::signatures();
        self::page('1604-C', 'Annual Information Return of Income Taxes Withheld on Compensation', $inner);
    }

    public static function gen1604e(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $b = Http::body();
        $company = self::company($o); $year = (int) ($b['year'] ?? 0);
        $lines = is_array($b['lines'] ?? null) ? $b['lines'] : [];
        $rows = ''; $tInc = 0.0; $tEwt = 0.0;
        foreach ($lines as $ln) {
            $inc = (float) ($ln['income_payment'] ?? 0); $ewt = (float) ($ln['ewt'] ?? 0);
            $tInc += $inc; $tEwt += $ewt;
            $rows .= '<tr><td>' . self::e($ln['name'] ?? '') . '</td><td>' . self::e($ln['tin'] ?? '')
                . "</td><td class='num'>" . self::n($inc) . "</td><td class='num'>" . self::n($ewt) . '</td></tr>';
        }
        $inner = self::agentBox($company)
            . "<div class='row'><div><div class='lbl'>For the year</div><div class='val'>$year</div></div><div><div class='lbl'>No. of payees</div><div class='val'>" . count($lines) . "</div></div></div>"
            . "<table><thead><tr><th>Payee (alphalist)</th><th>TIN</th><th class='num'>Income payments (year)</th><th class='num'>EWT withheld (year)</th></tr></thead>"
            . "<tbody>$rows</tbody><tfoot><tr><td colspan='2'>Totals</td><td class='num'>" . self::n($tInc) . "</td><td class='num'>" . self::n($tEwt) . "</td></tr></tfoot></table>"
            . "<div class='tot'><div class='totbox'><div class='lbl'>Total expanded tax withheld for $year (1604-E)</div><div class='amt'>₱ " . self::n($tEwt) . "</div></div></div>"
            . self::signatures();
        self::page('1604-E', 'Annual Information Return of Creditable Income Taxes Withheld (Expanded)', $inner);
    }

    private static function signatures(string $left = 'Authorized representative', string $right = 'Date'): string
    {
        return "<div class='sig'><div><div class='line'>" . self::e($left) . "</div></div>"
            . "<div><div class='line'>" . self::e($right) . "</div></div></div>";
    }
}
