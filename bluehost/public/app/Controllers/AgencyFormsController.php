<?php
// Statutory remittance forms (Philippines), generated from posted payroll:
//   R-3   SSS Contribution Collection List
//   RF-1  PhilHealth Employer Remittance Report
//   MCRF  Pag-IBIG Membership Contribution Remittance Form
//
// Employee shares come from posted payroll (deductions.sss / philhealth / pagibig).
// Employer shares are derived from the statutory rule effective that month
// (SSS employer/employee ratio) and the standard equal split for PhilHealth and
// Pag-IBIG. These are print-ready WORKING COPIES to verify, not the official forms.
class AgencyFormsController
{
    const CONSULTANT_TYPES = ['CONSULTANT_INDIVIDUAL', 'CONSULTANT_COMPANY'];
    const MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}/agency';
        $r->get("$b/months", [self::class, 'months']);
        $r->get("$b/data", [self::class, 'data']);
        $r->post("$b/generate", [self::class, 'generate']);
    }

    private static function company(int $o): array
    {
        $org = Database::one('SELECT name, legal_name, tin, address FROM organizations WHERE id = ?', [$o]);
        return ['name' => $org['legal_name'] ?: $org['name'], 'tin' => $org['tin'] ?: '', 'address' => $org['address'] ?: ''];
    }

    public static function months(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $rows = Database::all(
            "SELECT DISTINCT YEAR(COALESCE(pp.pay_date, pp.period_end)) y, MONTH(COALESCE(pp.pay_date, pp.period_end)) m
               FROM payroll_runs pr JOIN payroll_periods pp ON pp.id = pr.period_id
              WHERE pr.organization_id = ? ORDER BY y DESC, m DESC", [$o]);
        Http::json(['months' => array_map(fn($r) => ['year' => (int) $r['y'], 'month' => (int) $r['m'],
            'label' => self::MONTHS[(int) $r['m']] . ' ' . $r['y']], $rows)]);
    }

    /** Per-employee EE/ER contributions for the month, for all three agencies. */
    public static function data(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $year = (int) Http::query('year', 0); $month = (int) Http::query('month', 0);
        if (!$year || !$month) throw new HttpError('Choose a month and year', 422);
        $c = implode(',', array_fill(0, count(self::CONSULTANT_TYPES), '?'));
        $rows = Database::all(
            "SELECT e.id engagement_id, e.employee_number, CONCAT_WS(' ', pe.first_name, pe.last_name) name,
                    pe.sss_number, pe.philhealth_number, pe.pagibig_number, prp.deductions
               FROM payroll_run_people prp
               JOIN payroll_runs pr ON pr.id = prp.run_id
               JOIN payroll_periods pp ON pp.id = pr.period_id
               JOIN engagements e ON e.id = prp.engagement_id
               JOIN people pe ON pe.id = e.person_id
              WHERE pr.organization_id = ? AND e.engagement_type NOT IN ($c)
                AND YEAR(COALESCE(pp.pay_date, pp.period_end)) = ? AND MONTH(COALESCE(pp.pay_date, pp.period_end)) = ?",
            array_merge([$o], self::CONSULTANT_TYPES, [$year, $month]));

        // SSS employer/employee ratio + rate from the effective rule.
        $sssRule = Payroll::resolveRule('SSS', sprintf('%04d-%02d-15', $year, $month)) ?: [];
        $eeRate = (float) ($sssRule['employee_rate'] ?? 0.05);
        $erRate = (float) ($sssRule['employer_rate'] ?? ($eeRate * 2));
        $ratio = $eeRate > 0 ? $erRate / $eeRate : 2.0;

        $by = [];
        foreach ($rows as $r) {
            $d = json_decode($r['deductions'] ?: '{}', true) ?: [];
            $id = (int) $r['engagement_id'];
            if (!isset($by[$id])) $by[$id] = ['employee_number' => $r['employee_number'], 'name' => $r['name'],
                'sss_number' => $r['sss_number'] ?: '', 'philhealth_number' => $r['philhealth_number'] ?: '', 'pagibig_number' => $r['pagibig_number'] ?: '',
                'sss_ee' => 0.0, 'phic_ee' => 0.0, 'hdmf_ee' => 0.0, 'hdmf_extra' => 0.0, 'hdmf_mp2' => 0.0];
            $by[$id]['sss_ee'] += (float) ($d['sss'] ?? 0);
            $by[$id]['phic_ee'] += (float) ($d['philhealth'] ?? 0);
            $by[$id]['hdmf_ee'] += (float) ($d['pagibig'] ?? 0);
            $by[$id]['hdmf_extra'] += (float) ($d['pagibig_extra'] ?? 0);
            $by[$id]['hdmf_mp2'] += (float) ($d['pagibig_mp2'] ?? 0);
        }

        $sss = []; $phic = []; $hdmf = [];
        foreach ($by as $x) {
            if ($x['sss_ee'] > 0) {
                $er = round($x['sss_ee'] * $ratio, 2);
                $msc = $eeRate > 0 ? $x['sss_ee'] / $eeRate : 0;
                $ec = $msc >= 15000 ? 30.0 : 10.0;   // Employees' Compensation (employer)
                $sss[] = ['id_number' => $x['sss_number'], 'employee_number' => $x['employee_number'], 'name' => $x['name'],
                    'ee' => round($x['sss_ee'], 2), 'er' => $er, 'ec' => $ec, 'total' => round($x['sss_ee'] + $er + $ec, 2)];
            }
            if ($x['phic_ee'] > 0) {
                $er = round($x['phic_ee'], 2); // 50/50 split
                $phic[] = ['id_number' => $x['philhealth_number'], 'employee_number' => $x['employee_number'], 'name' => $x['name'],
                    'ee' => round($x['phic_ee'], 2), 'er' => $er, 'total' => round($x['phic_ee'] + $er, 2)];
            }
            if ($x['hdmf_ee'] > 0 || $x['hdmf_extra'] > 0 || $x['hdmf_mp2'] > 0) {
                // Employee share = mandatory + voluntary additional; employer matches the mandatory.
                $ee = round($x['hdmf_ee'] + $x['hdmf_extra'], 2);
                $er = round($x['hdmf_ee'], 2);
                $mp2 = round($x['hdmf_mp2'], 2);
                $hdmf[] = ['id_number' => $x['pagibig_number'], 'employee_number' => $x['employee_number'], 'name' => $x['name'],
                    'ee' => $ee, 'er' => $er, 'extra' => round($x['hdmf_extra'], 2), 'mp2' => $mp2, 'total' => round($ee + $er, 2)];
            }
        }
        // Pag-IBIG MP2 — one row per MP2 account (a person may hold several), for anyone
        // paid this month (employees AND consultants). Employee + employer share are the
        // configured monthly amounts; total remittance per account = EE + ER.
        $mp2 = [];
        $mp2rows = Database::all(
            "SELECT m.account_number, m.employee_share, m.employer_share, e.employee_number,
                    CONCAT_WS(' ', pe.first_name, pe.last_name) name, pe.pagibig_number
               FROM mp2_accounts m
               JOIN engagements e ON e.id = m.engagement_id
               JOIN people pe ON pe.id = e.person_id
              WHERE m.organization_id = ?
                AND m.engagement_id IN (
                    SELECT DISTINCT prp.engagement_id
                      FROM payroll_run_people prp
                      JOIN payroll_runs pr ON pr.id = prp.run_id
                      JOIN payroll_periods pp ON pp.id = pr.period_id
                     WHERE pr.organization_id = ?
                       AND YEAR(COALESCE(pp.pay_date, pp.period_end)) = ?
                       AND MONTH(COALESCE(pp.pay_date, pp.period_end)) = ?)
              ORDER BY name, m.id", [$o, $o, $year, $month]);
        foreach ($mp2rows as $r) {
            $ee = round((float) $r['employee_share'], 2);
            $er = round((float) $r['employer_share'], 2);
            $mp2[] = ['id_number' => $r['account_number'] ?: '', 'pagibig_number' => $r['pagibig_number'] ?: '',
                'employee_number' => $r['employee_number'], 'name' => $r['name'],
                'ee' => $ee, 'er' => $er, 'total' => round($ee + $er, 2)];
        }

        $sum = fn($arr, $k) => round(array_sum(array_column($arr, $k)), 2);
        Http::json([
            'company' => self::company($o), 'year' => $year, 'month' => $month, 'month_name' => self::MONTHS[$month],
            'sss' => $sss, 'philhealth' => $phic, 'pagibig' => $hdmf, 'mp2' => $mp2,
            'totals' => [
                'sss' => ['ee' => $sum($sss, 'ee'), 'er' => $sum($sss, 'er'), 'ec' => $sum($sss, 'ec'), 'total' => $sum($sss, 'total'), 'count' => count($sss)],
                'philhealth' => ['ee' => $sum($phic, 'ee'), 'er' => $sum($phic, 'er'), 'total' => $sum($phic, 'total'), 'count' => count($phic)],
                'pagibig' => ['ee' => $sum($hdmf, 'ee'), 'er' => $sum($hdmf, 'er'), 'mp2' => $sum($hdmf, 'mp2'), 'total' => $sum($hdmf, 'total'), 'count' => count($hdmf)],
                'mp2' => ['ee' => $sum($mp2, 'ee'), 'er' => $sum($mp2, 'er'), 'total' => $sum($mp2, 'total'), 'count' => count($mp2)],
            ],
        ]);
    }

    private static function n($v): string { return number_format((float) $v, 2); }
    private static function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }

    public static function generate(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $b = Http::body();
        $form = strtolower((string) ($b['form'] ?? ''));
        $company = self::company($o);
        $period = self::e(($b['month_name'] ?? '') . ' ' . ($b['year'] ?? ''));
        $lines = is_array($b['lines'] ?? null) ? $b['lines'] : [];

        $map = [
            'r3' => ['SSS Form R-3', 'Contribution Collection List (SSS)', 'SSS No.', true],
            'rf1' => ['PhilHealth RF-1', 'Employer Remittance Report (PhilHealth)', 'PhilHealth No.', false],
            'mcrf' => ['Pag-IBIG MCRF', 'Membership Contribution Remittance Form (Pag-IBIG)', 'Pag-IBIG MID No.', false],
            'mp2' => ['Pag-IBIG MP2', 'MP2 Savings Remittance (Pag-IBIG Modified Pag-IBIG II)', 'MP2 Account No.', false],
        ];
        if (!isset($map[$form])) throw new HttpError('Unknown form', 400);
        [$formNo, $formTitle, $idLabel, $hasEc] = $map[$form];
        $hasMp2 = ($form === 'mcrf'); // Pag-IBIG MP2 savings column on the MCRF
        $isMp2Form = ($form === 'mp2');

        $rows = ''; $tEE = 0; $tER = 0; $tEC = 0; $tMp2 = 0; $tT = 0;
        foreach ($lines as $l) {
            $ee = (float) ($l['ee'] ?? 0); $er = (float) ($l['er'] ?? 0); $ec = (float) ($l['ec'] ?? 0); $mp2 = (float) ($l['mp2'] ?? 0);
            $tot = (float) ($l['total'] ?? ($ee + $er + ($hasEc ? $ec : 0)));
            $tEE += $ee; $tER += $er; $tEC += $ec; $tMp2 += $mp2; $tT += $tot;
            $rows .= '<tr><td>' . self::e($l['id_number'] ?? '') . '</td><td>' . self::e($l['name'] ?? '')
                . "</td><td class='num'>" . self::n($ee) . "</td><td class='num'>" . self::n($er) . '</td>'
                . ($hasEc ? "<td class='num'>" . self::n($ec) . '</td>' : '')
                . "<td class='num'>" . self::n($tot) . '</td>'
                . ($hasMp2 ? "<td class='num'>" . self::n($mp2) . '</td>' : '') . '</tr>';
        }
        $ecHead = $hasEc ? "<th class='num'>EC (ER)</th>" : '';
        $ecFoot = $hasEc ? "<td class='num'>" . self::n($tEC) . '</td>' : '';
        $mp2Head = $hasMp2 ? "<th class='num'>MP2 savings</th>" : '';
        $mp2Foot = $hasMp2 ? "<td class='num'>" . self::n($tMp2) . '</td>' : '';

        $css = 'body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#111;margin:0;padding:24px;background:#f3f4f6}'
            . '.sheet{max-width:900px;margin:0 auto;background:#fff;padding:26px 30px;box-shadow:0 1px 4px rgba(0,0,0,.1)}'
            . '.bar{height:5px;background:linear-gradient(90deg,#EA4335,#F9AB00,#34A853,#4285F4);margin:-26px -30px 16px}'
            . '.hd{display:flex;justify-content:space-between;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:12px}'
            . '.formno{font-size:20px;font-weight:700}.title{font-size:13px;font-weight:700;text-align:right;max-width:340px}'
            . '.box{border:1px solid #999;border-radius:4px;padding:8px 10px;margin-bottom:10px}.lbl{font-size:10px;color:#666;text-transform:uppercase}.val{font-weight:600}'
            . '.row{display:flex;gap:16px;flex-wrap:wrap}.row>div{flex:1;min-width:160px}'
            . 'table{border-collapse:collapse;width:100%;margin-top:6px}th,td{border:1px solid #bbb;padding:5px 7px;font-size:11px}th{background:#f3f4f6;text-align:left}.num{text-align:right;font-variant-numeric:tabular-nums}tfoot td{font-weight:700;background:#fafafa}'
            . '.note{margin-top:16px;padding:8px 10px;border:1px dashed #c58a00;background:#fff8e6;color:#7a5200;font-size:10px;border-radius:4px}'
            . '.sig{display:flex;justify-content:space-between;gap:24px;margin-top:34px}.sig>div{flex:1;text-align:center}.sig .line{border-top:1px solid #333;margin-top:38px;padding-top:4px;font-size:11px}'
            . '@media print{body{background:#fff;padding:0}.sheet{box-shadow:none;max-width:none}.noprint{display:none}}.noprint{margin-bottom:14px}.btn{background:#1A73E8;color:#fff;border:0;border-radius:6px;padding:8px 14px;font-size:13px;cursor:pointer}';
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><meta charset='utf-8'><title>$formNo</title><style>$css</style></head><body>"
            . "<div class='noprint'><button class='btn' onclick='window.print()'>🖨 Print / Save as PDF</button></div>"
            . "<div class='sheet'><div class='bar'></div>"
            . "<div class='hd'><div><div class='formno'>$formNo</div><div style='font-size:11px;color:#444'>Republic of the Philippines</div></div><div class='title'>" . self::e($formTitle) . "</div></div>"
            . "<div class='box'><div class='row'><div><div class='lbl'>Employer</div><div class='val'>" . self::e($company['name']) . "</div></div>"
            . "<div><div class='lbl'>Employer TIN / No.</div><div class='val'>" . (self::e($company['tin']) ?: '—') . "</div></div>"
            . "<div><div class='lbl'>Applicable month</div><div class='val'>$period</div></div></div></div>"
            . "<table><thead><tr><th>$idLabel</th><th>Member name</th><th class='num'>Employee share</th><th class='num'>Employer share</th>$ecHead<th class='num'>Total</th>$mp2Head</tr></thead>"
            . "<tbody>$rows</tbody><tfoot><tr><td colspan='2'>Totals (" . count($lines) . ($isMp2Form ? " accounts)" : " members)") . "</td><td class='num'>" . self::n($tEE) . "</td><td class='num'>" . self::n($tER) . "</td>$ecFoot<td class='num'>" . self::n($tT) . "</td>$mp2Foot</tr></tfoot></table>"
            . "<div class='sig'><div><div class='line'>Prepared by</div></div><div><div class='line'>Authorized representative</div></div></div>"
            . ($isMp2Form
                ? "<div class='note'><b>System-generated working copy of $formNo.</b> One row per MP2 account (a member may hold several, each with its own MP2 account number, separate from the compulsory Pag-IBIG MID). "
                    . "Employee and employer shares are the configured monthly amounts; total remittance per account = employee + employer share. Verify and file through the Pag-IBIG MP2 facility. This is not the official form.</div>"
                : "<div class='note'><b>System-generated working copy of $formNo.</b> Employee shares come from posted payroll; employer shares are computed from the statutory rates. "
                    . "Verify against the official agency tables (SSS MSC / EC / WISP, PhilHealth, Pag-IBIG) and file through the agency's own facility (e.g. SSS/PhilHealth/Pag-IBIG online). This is not the official form.</div>")
            . "</div></body></html>";
        exit;
    }
}
