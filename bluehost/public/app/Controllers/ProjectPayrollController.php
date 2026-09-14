<?php
// Project (construction) payroll — short-cycle daily-wage pay runs per project.
// A run covers an arbitrary date range (a week, 3 days, whatever); the PM/HR enters
// days worked per worker; pay = daily rate x days (+ OT + allowance). Statutory is
// prorated by time worked and tagged to the project, so SSS/PhilHealth/Pag-IBIG,
// 13th month and the DOLE labour report can all be produced per project.
class ProjectPayrollController
{
    // Engagement types managed as project (site) workers.
    const PROJECT_TYPES = ['PROJECT_BASED', 'DAILY_PAID'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}';
        $r->get("$b/projects/{project_id}/pay-runs", [self::class, 'listRuns']);
        $r->post("$b/projects/{project_id}/pay-runs", [self::class, 'createRun']);
        $r->get("$b/project-pay-runs/{run_id}", [self::class, 'getRun']);
        $r->put("$b/project-pay-runs/{run_id}/lines/{line_id}", [self::class, 'updateLine']);
        $r->post("$b/project-pay-runs/{run_id}/recompute", [self::class, 'recompute']);
        $r->post("$b/project-pay-runs/{run_id}/approve", [self::class, 'approve']);
        $r->post("$b/project-pay-runs/{run_id}/reopen", [self::class, 'reopen']);
        $r->delete("$b/project-pay-runs/{run_id}", [self::class, 'destroy']);
        $r->get("$b/project-pay-runs/{run_id}/payslips", [self::class, 'payslips']);
        $r->get("$b/projects/{project_id}/dole-report", [self::class, 'doleReport']);
        $r->get("$b/projects/{project_id}/statutory-report", [self::class, 'statutoryReport']);
        $r->get("$b/projects/{project_id}/thirteenth-month", [self::class, 'thirteenth']);
        $r->post("$b/projects/{project_id}/end", [self::class, 'endProject']);
    }

    private static function project(int $orgId, int $projectId): array
    {
        $p = Database::one('SELECT * FROM projects WHERE id = ? AND organization_id = ?', [$projectId, $orgId]);
        if (!$p) throw new HttpError('Project not found', 404);
        return $p;
    }

    private static function findRun(int $orgId, int $runId): array
    {
        $run = Database::one('SELECT * FROM project_pay_runs WHERE id = ? AND organization_id = ?', [$runId, $orgId]);
        if (!$run) throw new HttpError('Pay run not found', 404);
        return $run;
    }

    private static function engName(int $engId): string
    {
        $r = Database::one("SELECT CONCAT_WS(' ', pe.first_name, pe.last_name) name FROM engagements e JOIN people pe ON pe.id = e.person_id WHERE e.id = ?", [$engId]);
        return $r['name'] ?? '';
    }

    /** Recompute one line from its days/OT/allowance and the run's pay date. */
    private static function computeAndSaveLine(array $run, array $line): array
    {
        $eng = Database::one('SELECT * FROM engagements WHERE id = ?', [(int) $line['engagement_id']]);
        $rate = (float) ($line['daily_rate'] ?? 0);
        if ($rate <= 0 && $eng) $rate = Payroll::dailyRate($eng);
        $onDate = $run['pay_date'] ?: $run['period_end'];
        $c = Payroll::computeProjectLine($rate, (float) $line['days_worked'], $onDate,
            (float) $line['ot_amount'], (float) $line['allowance'], (float) $line['other_deduction']);
        Database::update('project_pay_lines', (int) $line['id'], [
            'daily_rate' => $rate, 'basic_pay' => $c['basic_pay'], 'gross_pay' => $c['gross_pay'],
            'sss' => $c['sss'], 'philhealth' => $c['philhealth'], 'pagibig' => $c['pagibig'],
            'withholding_tax' => $c['withholding_tax'], 'total_deductions' => $c['total_deductions'], 'net_pay' => $c['net_pay'],
        ]);
        return $c;
    }

    private static function retotal(int $runId): array
    {
        $lines = Database::all('SELECT * FROM project_pay_lines WHERE run_id = ?', [$runId]);
        $g = $s = $d = $n = 0.0;
        foreach ($lines as $l) {
            $g += (float) $l['gross_pay'];
            $s += (float) $l['sss'] + (float) $l['philhealth'] + (float) $l['pagibig'];
            $d += (float) $l['total_deductions'];
            $n += (float) $l['net_pay'];
        }
        $t = ['gross_total' => round($g, 2), 'statutory_total' => round($s, 2), 'deduction_total' => round($d, 2), 'net_total' => round($n, 2)];
        Database::update('project_pay_runs', $runId, $t);
        return $t;
    }

    public static function listRuns(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $proj = self::project($o, (int) $p['project_id']);
        $runs = Database::all('SELECT * FROM project_pay_runs WHERE project_id = ? ORDER BY period_start DESC, id DESC', [$proj['id']]);
        Http::json(array_map(fn($r) => [
            'id' => (int) $r['id'], 'reference' => $r['reference'], 'status' => $r['status'],
            'period_start' => $r['period_start'], 'period_end' => $r['period_end'], 'pay_date' => $r['pay_date'],
            'gross_total' => (float) $r['gross_total'], 'statutory_total' => (float) $r['statutory_total'],
            'net_total' => (float) $r['net_total'],
            'line_count' => (int) Database::scalar('SELECT COUNT(*) FROM project_pay_lines WHERE run_id = ?', [$r['id']]),
        ], $runs));
    }

    public static function createRun(array $p): void
    {
        [$u, $o] = Auth::org($p, 'payroll.compute');
        $proj = self::project($o, (int) $p['project_id']);
        $b = Http::body();
        $start = trim((string) ($b['period_start'] ?? '')); $end = trim((string) ($b['period_end'] ?? ''));
        if (!$start || !$end) throw new HttpError('period_start and period_end are required', 422);
        $ref = trim((string) ($b['reference'] ?? '')) ?: ($proj['project_code'] . ' ' . $start . '→' . $end);
        $runId = Database::insert('project_pay_runs', [
            'uuid' => Util::uuid(), 'organization_id' => $o, 'project_id' => (int) $proj['id'],
            'reference' => $ref, 'period_start' => $start, 'period_end' => $end,
            'pay_date' => trim((string) ($b['pay_date'] ?? '')) ?: null, 'status' => 'DRAFT',
        ]);
        // Populate lines from this project's site workers (or an explicit selection).
        $ids = array_values(array_unique(array_map('intval', (array) ($b['engagement_ids'] ?? []))));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $engs = Database::all("SELECT * FROM engagements WHERE organization_id = ? AND id IN ($ph)", array_merge([$o], $ids));
        } else {
            $tp = implode(',', array_fill(0, count(self::PROJECT_TYPES), '?'));
            $engs = Database::all("SELECT * FROM engagements WHERE organization_id = ? AND project_id = ? AND status = 'ACTIVE' AND engagement_type IN ($tp)",
                array_merge([$o, (int) $proj['id']], self::PROJECT_TYPES));
        }
        foreach ($engs as $e) {
            Database::insert('project_pay_lines', ['run_id' => $runId, 'engagement_id' => (int) $e['id'], 'daily_rate' => Payroll::dailyRate($e)]);
        }
        Audit::record('project_payroll.create', $u, ['organization_id' => $o, 'entity' => 'project_pay_run', 'entity_id' => $runId,
            'after' => ['project_id' => (int) $proj['id'], 'period' => "$start..$end", 'workers' => count($engs)]]);
        Http::json(['id' => $runId, 'reference' => $ref, 'line_count' => count($engs)]);
    }

    public static function getRun(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $run = self::findRun($o, (int) $p['run_id']);
        $proj = self::project($o, (int) $run['project_id']);
        $lines = array_map(function ($l) {
            $eng = Database::one('SELECT employee_number, engagement_type FROM engagements WHERE id = ?', [$l['engagement_id']]);
            return ['line_id' => (int) $l['id'], 'engagement_id' => (int) $l['engagement_id'],
                'name' => self::engName((int) $l['engagement_id']), 'employee_number' => $eng['employee_number'] ?? null,
                'days_worked' => (float) $l['days_worked'], 'daily_rate' => (float) $l['daily_rate'],
                'ot_amount' => (float) $l['ot_amount'], 'allowance' => (float) $l['allowance'], 'other_deduction' => (float) $l['other_deduction'],
                'basic_pay' => (float) $l['basic_pay'], 'gross_pay' => (float) $l['gross_pay'],
                'sss' => (float) $l['sss'], 'philhealth' => (float) $l['philhealth'], 'pagibig' => (float) $l['pagibig'],
                'withholding_tax' => (float) $l['withholding_tax'], 'total_deductions' => (float) $l['total_deductions'],
                'net_pay' => (float) $l['net_pay'], 'remarks' => $l['remarks']];
        }, Database::all('SELECT * FROM project_pay_lines WHERE run_id = ? ORDER BY id', [$run['id']]));
        Http::json(['id' => (int) $run['id'], 'project_id' => (int) $proj['id'], 'project_name' => $proj['project_name'],
            'reference' => $run['reference'], 'status' => $run['status'], 'period_start' => $run['period_start'],
            'period_end' => $run['period_end'], 'pay_date' => $run['pay_date'],
            'gross_total' => (float) $run['gross_total'], 'statutory_total' => (float) $run['statutory_total'],
            'deduction_total' => (float) $run['deduction_total'], 'net_total' => (float) $run['net_total'], 'lines' => $lines]);
    }

    public static function updateLine(array $p): void
    {
        [$u, $o] = Auth::org($p, 'payroll.compute');
        $run = self::findRun($o, (int) $p['run_id']);
        if ($run['status'] === 'APPROVED') throw new HttpError('This run is approved — reopen it to edit.', 409);
        $line = Database::one('SELECT * FROM project_pay_lines WHERE id = ? AND run_id = ?', [(int) $p['line_id'], $run['id']]);
        if (!$line) throw new HttpError('Line not found', 404);
        $b = Http::body();
        foreach (['days_worked', 'ot_amount', 'allowance', 'other_deduction'] as $f)
            if (array_key_exists($f, $b)) $line[$f] = (float) $b[$f];
        if (array_key_exists('remarks', $b)) $line['remarks'] = (string) $b['remarks'];
        Database::update('project_pay_lines', (int) $line['id'], [
            'days_worked' => (float) $line['days_worked'], 'ot_amount' => (float) $line['ot_amount'],
            'allowance' => (float) $line['allowance'], 'other_deduction' => (float) $line['other_deduction'], 'remarks' => $line['remarks']]);
        self::computeAndSaveLine($run, Database::one('SELECT * FROM project_pay_lines WHERE id = ?', [(int) $line['id']]));
        $totals = self::retotal((int) $run['id']);
        $fresh = Database::one('SELECT * FROM project_pay_lines WHERE id = ?', [(int) $line['id']]);
        Http::json(['line_id' => (int) $line['id'], 'gross_pay' => (float) $fresh['gross_pay'], 'net_pay' => (float) $fresh['net_pay'],
            'sss' => (float) $fresh['sss'], 'philhealth' => (float) $fresh['philhealth'], 'pagibig' => (float) $fresh['pagibig'],
            'withholding_tax' => (float) $fresh['withholding_tax'], 'basic_pay' => (float) $fresh['basic_pay'], 'totals' => $totals]);
    }

    public static function recompute(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.compute');
        $run = self::findRun($o, (int) $p['run_id']);
        if ($run['status'] === 'APPROVED') throw new HttpError('This run is approved — reopen it to recompute.', 409);
        foreach (Database::all('SELECT * FROM project_pay_lines WHERE run_id = ?', [$run['id']]) as $l) {
            // Re-snapshot the daily rate from the engagement in case it changed.
            $eng = Database::one('SELECT * FROM engagements WHERE id = ?', [(int) $l['engagement_id']]);
            if ($eng) $l['daily_rate'] = Payroll::dailyRate($eng);
            self::computeAndSaveLine($run, $l);
        }
        Http::json(['id' => (int) $run['id']] + self::retotal((int) $run['id']));
    }

    public static function approve(array $p): void
    {
        [$u, $o] = Auth::org($p, 'payroll.approve');
        $run = self::findRun($o, (int) $p['run_id']);
        self::retotal((int) $run['id']);
        Database::update('project_pay_runs', (int) $run['id'], ['status' => 'APPROVED']);
        Audit::record('project_payroll.approve', $u, ['organization_id' => $o, 'entity' => 'project_pay_run', 'entity_id' => (int) $run['id']]);
        Http::json(['id' => (int) $run['id'], 'status' => 'APPROVED']);
    }

    public static function reopen(array $p): void
    {
        [$u, $o] = Auth::org($p, 'payroll.compute');
        $run = self::findRun($o, (int) $p['run_id']);
        Database::update('project_pay_runs', (int) $run['id'], ['status' => 'DRAFT']);
        Audit::record('project_payroll.reopen', $u, ['organization_id' => $o, 'entity' => 'project_pay_run', 'entity_id' => (int) $run['id']]);
        Http::json(['id' => (int) $run['id'], 'status' => 'DRAFT']);
    }

    public static function destroy(array $p): void
    {
        $u = Auth::require();
        if (!$u['is_superadmin']) throw new HttpError('Only a superadmin can delete a pay run.', 403);
        $o = (int) ($p['organization_id'] ?? 0);
        $run = self::findRun($o, (int) $p['run_id']);
        Database::exec('DELETE FROM project_pay_lines WHERE run_id = ?', [(int) $run['id']]);
        Database::exec('DELETE FROM project_pay_runs WHERE id = ?', [(int) $run['id']]);
        Audit::record('project_payroll.delete', $u, ['organization_id' => $o, 'entity' => 'project_pay_run', 'entity_id' => (int) $run['id'],
            'before' => ['reference' => $run['reference'], 'status' => $run['status']]]);
        Http::json(['ok' => true, 'id' => (int) $run['id']]);
    }

    // ---- Reports ---------------------------------------------------------------

    private static function company(int $o): array
    {
        $org = Database::one('SELECT name, legal_name, tin, address FROM organizations WHERE id = ?', [$o]);
        return ['name' => $org['legal_name'] ?: $org['name'], 'tin' => $org['tin'] ?: '', 'address' => $org['address'] ?: ''];
    }

    /** All pay lines for a project, optionally within a date range (by run period). */
    private static function lines(int $projectId, ?string $from, ?string $to): array
    {
        $sql = "SELECT l.*, r.period_start, r.period_end, r.reference,
                       e.employee_number, e.engagement_type,
                       CONCAT_WS(' ', pe.first_name, pe.last_name) name,
                       pe.sss_number, pe.philhealth_number, pe.pagibig_number, pe.tin
                  FROM project_pay_lines l
                  JOIN project_pay_runs r ON r.id = l.run_id
                  JOIN engagements e ON e.id = l.engagement_id
                  JOIN people pe ON pe.id = e.person_id
                 WHERE r.project_id = ?";
        $params = [$projectId];
        if ($from) { $sql .= ' AND r.period_end >= ?'; $params[] = $from; }
        if ($to) { $sql .= ' AND r.period_start <= ?'; $params[] = $to; }
        return Database::all($sql . ' ORDER BY name, r.period_start', $params);
    }

    private static function n($v): string { return number_format((float) $v, 2); }
    private static function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }

    private static function reportCss(): string
    {
        return 'body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#111;margin:0;padding:24px;background:#f3f4f6}'
            . '.sheet{max-width:980px;margin:0 auto;background:#fff;padding:26px 30px;box-shadow:0 1px 4px rgba(0,0,0,.1)}'
            . '.hd{border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:12px}.formno{font-size:19px;font-weight:700}'
            . '.sub{font-size:11px;color:#555}.meta{display:flex;flex-wrap:wrap;gap:14px;margin:10px 0}.meta>div{font-size:11px}.meta .lbl{color:#666;text-transform:uppercase;font-size:9px}'
            . 'table{border-collapse:collapse;width:100%;margin-top:6px}th,td{border:1px solid #bbb;padding:5px 7px;font-size:11px}th{background:#f3f4f6;text-align:left}'
            . '.num{text-align:right;font-variant-numeric:tabular-nums}tfoot td{font-weight:700;background:#fafafa}'
            . '.cards{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0}.cardx{flex:1;min-width:150px;border:1px solid #ddd;border-radius:6px;padding:10px 12px}.cardx .lbl{font-size:9px;color:#666;text-transform:uppercase}.cardx .v{font-size:17px;font-weight:700}'
            . '.note{margin-top:16px;padding:8px 10px;border:1px dashed #c58a00;background:#fff8e6;color:#7a5200;font-size:10px;border-radius:4px}'
            . '.sig{display:flex;justify-content:space-between;gap:24px;margin-top:34px}.sig>div{flex:1;text-align:center}.sig .line{border-top:1px solid #333;margin-top:38px;padding-top:4px;font-size:11px}'
            . '@media print{body{background:#fff;padding:0}.sheet{box-shadow:none;max-width:none}.noprint{display:none}}.noprint{margin-bottom:14px}.btn{background:#1A73E8;color:#fff;border:0;border-radius:6px;padding:8px 14px;font-size:13px;cursor:pointer}';
    }

    private static function emitHtml(string $title, string $bodyHtml): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><meta charset='utf-8'><title>" . self::e($title) . '</title><style>' . self::reportCss() . '</style></head><body>'
            . "<div class='noprint'><button class='btn' onclick='window.print()'>🖨 Print / Save as PDF</button></div>"
            . "<div class='sheet'>$bodyHtml</div></body></html>";
        exit;
    }

    /** DOLE labour report: all staff on a project with days, gross, statutory, net, plus budget. */
    public static function doleReport(array $p): void
    {
        [, $o] = Auth::org($p, 'reports.view');
        $proj = self::project($o, (int) $p['project_id']);
        $from = Http::query('date_from') ?: null; $to = Http::query('date_to') ?: null;
        $fmt = Http::query('fmt', 'json');
        $rows = self::lines((int) $proj['id'], $from, $to);
        $by = [];
        foreach ($rows as $r) {
            $id = (int) $r['engagement_id'];
            if (!isset($by[$id])) $by[$id] = ['name' => $r['name'], 'employee_number' => $r['employee_number'], 'type' => $r['engagement_type'],
                'sss_number' => $r['sss_number'], 'days' => 0.0, 'gross' => 0.0, 'sss' => 0.0, 'philhealth' => 0.0, 'pagibig' => 0.0, 'tax' => 0.0, 'net' => 0.0];
            $by[$id]['days'] += (float) $r['days_worked'];
            $by[$id]['gross'] += (float) $r['gross_pay'];
            $by[$id]['sss'] += (float) $r['sss']; $by[$id]['philhealth'] += (float) $r['philhealth'];
            $by[$id]['pagibig'] += (float) $r['pagibig']; $by[$id]['tax'] += (float) $r['withholding_tax'];
            $by[$id]['net'] += (float) $r['net_pay'];
        }
        $data = array_values($by);
        $tot = fn($k) => round(array_sum(array_column($data, $k)), 2);
        $labor = (float) ($proj['labor_budget'] ?? 0);
        $usedGross = $tot('gross');
        $summary = ['labor_budget' => $labor, 'total_gross' => $usedGross, 'total_statutory' => round($tot('sss') + $tot('philhealth') + $tot('pagibig'), 2),
            'total_net' => $tot('net'), 'remaining_budget' => round($labor - $usedGross, 2), 'headcount' => count($data)];

        if ($fmt === 'csv') {
            $out = "Employee No.,Name,Type,Days,Gross,SSS,PhilHealth,Pag-IBIG,Tax,Net\n";
            foreach ($data as $d) $out .= '"' . $d['employee_number'] . '","' . $d['name'] . '","' . $d['type'] . '",' . $d['days'] . ',' . $d['gross'] . ',' . $d['sss'] . ',' . $d['philhealth'] . ',' . $d['pagibig'] . ',' . $d['tax'] . ',' . $d['net'] . "\n";
            $out .= ',,TOTAL,' . $tot('days') . ',' . $usedGross . ',' . $tot('sss') . ',' . $tot('philhealth') . ',' . $tot('pagibig') . ',' . $tot('tax') . ',' . $tot('net') . "\n";
            Http::file($out, 'text/csv', 'DOLE_' . $proj['project_code'] . '_report.csv');
        }
        if ($fmt === 'print') {
            $c = self::company($o);
            $rowsH = '';
            foreach ($data as $d) $rowsH .= '<tr><td>' . self::e($d['employee_number']) . '</td><td>' . self::e($d['name']) . '</td><td>' . self::e($d['type'])
                . "</td><td class='num'>" . self::n($d['days']) . "</td><td class='num'>" . self::n($d['gross']) . "</td><td class='num'>" . self::n($d['sss'])
                . "</td><td class='num'>" . self::n($d['philhealth']) . "</td><td class='num'>" . self::n($d['pagibig']) . "</td><td class='num'>" . self::n($d['tax']) . "</td><td class='num'>" . self::n($d['net']) . '</td></tr>';
            $body = "<div class='hd'><div class='formno'>Project Labour Report</div><div class='sub'>For submission / reference — Department of Labor and Employment (DOLE)</div></div>"
                . "<div class='meta'><div><div class='lbl'>Employer</div>" . self::e($c['name']) . "</div><div><div class='lbl'>TIN</div>" . (self::e($c['tin']) ?: '—')
                . "</div><div><div class='lbl'>Project</div>" . self::e($proj['project_name']) . ' (' . self::e($proj['project_code']) . ")</div><div><div class='lbl'>Site</div>" . (self::e($proj['site'] ?? '') ?: '—')
                . "</div><div><div class='lbl'>Duration</div>" . self::e($proj['start_date'] ?: '—') . ' → ' . self::e($proj['actual_end_date'] ?: ($proj['target_end_date'] ?: '—'))
                . "</div><div><div class='lbl'>Period covered</div>" . self::e(($from ?: 'start') . ' → ' . ($to ?: 'to date')) . '</div></div>'
                . "<div class='cards'><div class='cardx'><div class='lbl'>Labor budget</div><div class='v'>₱ " . self::n($labor) . "</div></div>"
                . "<div class='cardx'><div class='lbl'>Total labour cost</div><div class='v'>₱ " . self::n($usedGross) . "</div></div>"
                . "<div class='cardx'><div class='lbl'>Statutory (EE)</div><div class='v'>₱ " . self::n($summary['total_statutory']) . "</div></div>"
                . "<div class='cardx'><div class='lbl'>Remaining budget</div><div class='v'>₱ " . self::n($summary['remaining_budget']) . '</div></div></div>'
                . "<table><thead><tr><th>Emp. No.</th><th>Name</th><th>Type</th><th class='num'>Days</th><th class='num'>Gross</th><th class='num'>SSS</th><th class='num'>PhilHealth</th><th class='num'>Pag-IBIG</th><th class='num'>Tax</th><th class='num'>Net</th></tr></thead><tbody>$rowsH</tbody>"
                . "<tfoot><tr><td colspan='3'>Totals (" . count($data) . " workers)</td><td class='num'>" . self::n($tot('days')) . "</td><td class='num'>" . self::n($usedGross) . "</td><td class='num'>" . self::n($tot('sss'))
                . "</td><td class='num'>" . self::n($tot('philhealth')) . "</td><td class='num'>" . self::n($tot('pagibig')) . "</td><td class='num'>" . self::n($tot('tax')) . "</td><td class='num'>" . self::n($tot('net')) . '</td></tr></tfoot></table>'
                . "<div class='sig'><div><div class='line'>Prepared by</div></div><div><div class='line'>Project Manager</div></div><div><div class='line'>Authorized representative</div></div></div>"
                . "<div class='note'>System-generated project labour report. Figures come from posted project pay runs. Verify before submission to DOLE.</div>";
            self::emitHtml('DOLE Project Labour Report', $body);
        }
        Http::json(['project' => ['id' => (int) $proj['id'], 'name' => $proj['project_name'], 'code' => $proj['project_code'],
            'status' => $proj['status'], 'start_date' => $proj['start_date'], 'end_date' => $proj['actual_end_date'] ?: $proj['target_end_date']],
            'summary' => $summary, 'rows' => $data]);
    }

    /** Per-project statutory remittance summary (employee + employer shares). */
    public static function statutoryReport(array $p): void
    {
        [, $o] = Auth::org($p, 'reports.view');
        $proj = self::project($o, (int) $p['project_id']);
        $from = Http::query('date_from') ?: null; $to = Http::query('date_to') ?: null;
        $fmt = Http::query('fmt', 'json');
        $rows = self::lines((int) $proj['id'], $from, $to);
        $by = [];
        foreach ($rows as $r) {
            $id = (int) $r['engagement_id'];
            if (!isset($by[$id])) $by[$id] = ['name' => $r['name'], 'sss_number' => $r['sss_number'], 'philhealth_number' => $r['philhealth_number'],
                'pagibig_number' => $r['pagibig_number'], 'sss' => 0.0, 'philhealth' => 0.0, 'pagibig' => 0.0, 'last' => $r['period_end']];
            $by[$id]['sss'] += (float) $r['sss']; $by[$id]['philhealth'] += (float) $r['philhealth']; $by[$id]['pagibig'] += (float) $r['pagibig'];
            if ($r['period_end'] > $by[$id]['last']) $by[$id]['last'] = $r['period_end'];
        }
        $data = [];
        foreach ($by as $x) {
            $er = Payroll::employerShares($x['sss'], $x['philhealth'], $x['pagibig'], $x['last']);
            $data[] = ['name' => $x['name'], 'sss_number' => $x['sss_number'], 'philhealth_number' => $x['philhealth_number'], 'pagibig_number' => $x['pagibig_number'],
                'sss_ee' => round($x['sss'], 2), 'sss_er' => $er['sss'], 'phic_ee' => round($x['philhealth'], 2), 'phic_er' => $er['philhealth'],
                'hdmf_ee' => round($x['pagibig'], 2), 'hdmf_er' => $er['pagibig'],
                'total' => round($x['sss'] + $er['sss'] + $x['philhealth'] * 2 + $x['pagibig'] * 2, 2)];
        }
        $tot = fn($k) => round(array_sum(array_column($data, $k)), 2);
        if ($fmt === 'print') {
            $c = self::company($o);
            $rowsH = '';
            foreach ($data as $d) $rowsH .= '<tr><td>' . self::e($d['name']) . "</td><td class='num'>" . self::n($d['sss_ee']) . "</td><td class='num'>" . self::n($d['sss_er'])
                . "</td><td class='num'>" . self::n($d['phic_ee']) . "</td><td class='num'>" . self::n($d['phic_er']) . "</td><td class='num'>" . self::n($d['hdmf_ee'])
                . "</td><td class='num'>" . self::n($d['hdmf_er']) . "</td><td class='num'>" . self::n($d['total']) . '</td></tr>';
            $body = "<div class='hd'><div class='formno'>Project Statutory Summary</div><div class='sub'>SSS / PhilHealth / Pag-IBIG — per project, from posted project pay runs</div></div>"
                . "<div class='meta'><div><div class='lbl'>Employer</div>" . self::e($c['name']) . "</div><div><div class='lbl'>Project</div>" . self::e($proj['project_name']) . ' (' . self::e($proj['project_code']) . ")</div>"
                . "<div><div class='lbl'>Period covered</div>" . self::e(($from ?: 'start') . ' → ' . ($to ?: 'to date')) . '</div></div>'
                . "<table><thead><tr><th>Worker</th><th class='num'>SSS EE</th><th class='num'>SSS ER</th><th class='num'>PhilHealth EE</th><th class='num'>PhilHealth ER</th><th class='num'>Pag-IBIG EE</th><th class='num'>Pag-IBIG ER</th><th class='num'>Total</th></tr></thead><tbody>$rowsH</tbody>"
                . "<tfoot><tr><td>Totals</td><td class='num'>" . self::n($tot('sss_ee')) . "</td><td class='num'>" . self::n($tot('sss_er')) . "</td><td class='num'>" . self::n($tot('phic_ee'))
                . "</td><td class='num'>" . self::n($tot('phic_er')) . "</td><td class='num'>" . self::n($tot('hdmf_ee')) . "</td><td class='num'>" . self::n($tot('hdmf_er')) . "</td><td class='num'>" . self::n($tot('total')) . '</td></tr></tfoot></table>'
                . "<div class='note'>Employee shares are prorated by time worked and come from posted project pay runs; employer shares are computed from the effective statutory rates. Verify against the official tables before remitting.</div>";
            self::emitHtml('Project Statutory Summary', $body);
        }
        Http::json(['project_name' => $proj['project_name'], 'rows' => $data,
            'totals' => ['sss_ee' => $tot('sss_ee'), 'sss_er' => $tot('sss_er'), 'phic_ee' => $tot('phic_ee'), 'phic_er' => $tot('phic_er'),
                'hdmf_ee' => $tot('hdmf_ee'), 'hdmf_er' => $tot('hdmf_er'), 'total' => $tot('total')]]);
    }

    /** Per-project 13th month: total basic earned on the project ÷ 12, per worker. */
    public static function thirteenth(array $p): void
    {
        [, $o] = Auth::org($p, 'reports.view');
        $proj = self::project($o, (int) $p['project_id']);
        $fmt = Http::query('fmt', 'json');
        $rows = self::lines((int) $proj['id'], Http::query('date_from') ?: null, Http::query('date_to') ?: null);
        $by = [];
        foreach ($rows as $r) {
            $id = (int) $r['engagement_id'];
            if (!isset($by[$id])) $by[$id] = ['name' => $r['name'], 'employee_number' => $r['employee_number'], 'basic' => 0.0];
            $by[$id]['basic'] += (float) $r['basic_pay'];
        }
        $data = [];
        foreach ($by as $x) $data[] = ['name' => $x['name'], 'employee_number' => $x['employee_number'],
            'basic_earned' => round($x['basic'], 2), 'thirteenth_month' => round($x['basic'] / 12, 2)];
        $totBasic = round(array_sum(array_column($data, 'basic_earned')), 2);
        $tot13 = round(array_sum(array_column($data, 'thirteenth_month')), 2);
        if ($fmt === 'csv') {
            $out = "Employee No.,Name,Basic earned (project),13th month\n";
            foreach ($data as $d) $out .= '"' . $d['employee_number'] . '","' . $d['name'] . '",' . $d['basic_earned'] . ',' . $d['thirteenth_month'] . "\n";
            $out .= ',,TOTAL ' . $totBasic . ',' . $tot13 . "\n";
            Http::file($out, 'text/csv', '13thMonth_' . $proj['project_code'] . '.csv');
        }
        Http::json(['project_name' => $proj['project_name'], 'rows' => $data, 'total_basic' => $totBasic, 'total_thirteenth' => $tot13]);
    }

    /** Printable payslips for every worker in a run. */
    public static function payslips(array $p): void
    {
        [, $o] = Auth::org($p, 'payroll.view');
        $run = self::findRun($o, (int) $p['run_id']);
        $proj = self::project($o, (int) $run['project_id']);
        $c = self::company($o);
        $lines = Database::all('SELECT * FROM project_pay_lines WHERE run_id = ? ORDER BY id', [$run['id']]);
        $slips = '';
        foreach ($lines as $l) {
            $name = self::engName((int) $l['engagement_id']);
            $eng = Database::one('SELECT employee_number FROM engagements WHERE id = ?', [$l['engagement_id']]);
            $dedRows = '';
            foreach (['SSS' => $l['sss'], 'PhilHealth' => $l['philhealth'], 'Pag-IBIG' => $l['pagibig'], 'Withholding tax' => $l['withholding_tax'], 'Other' => $l['other_deduction']] as $lbl => $amt) {
                if ((float) $amt != 0) $dedRows .= "<tr><td>$lbl</td><td class='num'>" . self::n($amt) . '</td></tr>';
            }
            $slips .= "<div class='slip'><div class='shd'><div><b>" . self::e($c['name']) . "</b><div class='sub'>Payslip — project pay</div></div>"
                . "<div style='text-align:right'><div><b>" . self::e($name) . "</b></div><div class='sub'>" . self::e($eng['employee_number'] ?? '') . "</div></div></div>"
                . "<div class='meta'><div><div class='lbl'>Project</div>" . self::e($proj['project_name']) . "</div><div><div class='lbl'>Period</div>" . self::e($run['period_start']) . ' → ' . self::e($run['period_end'])
                . "</div><div><div class='lbl'>Pay date</div>" . self::e($run['pay_date'] ?: $run['period_end']) . '</div></div>'
                . "<div class='cols'><table><tbody><tr><td>Days worked</td><td class='num'>" . self::n($l['days_worked']) . "</td></tr><tr><td>Daily rate</td><td class='num'>" . self::n($l['daily_rate'])
                . "</td></tr><tr><td>Basic pay</td><td class='num'>" . self::n($l['basic_pay']) . "</td></tr><tr><td>Overtime</td><td class='num'>" . self::n($l['ot_amount'])
                . "</td></tr><tr><td>Allowance</td><td class='num'>" . self::n($l['allowance']) . "</td></tr><tr class='sum'><td>Gross pay</td><td class='num'>" . self::n($l['gross_pay']) . '</td></tr></tbody></table>'
                . "<table><tbody>" . ($dedRows ?: "<tr><td colspan='2' class='sub'>No deductions</td></tr>") . "<tr class='sum'><td>Total deductions</td><td class='num'>" . self::n($l['total_deductions']) . '</td></tr></tbody></table></div>'
                . "<div class='net'>NET PAY <span>₱ " . self::n($l['net_pay']) . '</span></div>'
                . "<div class='sig'><div><div class='line'>Received by (employee)</div></div><div><div class='line'>Prepared by</div></div></div></div>";
        }
        $css = self::reportCss()
            . '.slip{max-width:640px;margin:0 auto 20px;background:#fff;padding:20px 24px;border:1px solid #ddd;border-radius:6px;page-break-inside:avoid}'
            . '.shd{display:flex;justify-content:space-between;border-bottom:2px solid #111;padding-bottom:6px}'
            . '.cols{display:flex;gap:16px;margin-top:8px}.cols table{flex:1}.slip .sum td{font-weight:700;border-top:2px solid #333;background:#fafafa}'
            . '.net{margin-top:10px;background:#0f172a;color:#fff;border-radius:6px;padding:10px 14px;display:flex;justify-content:space-between;font-weight:700;font-size:15px}';
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><meta charset='utf-8'><title>Project payslips</title><style>body{background:#f3f4f6;margin:0;padding:24px;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#111}$css</style></head><body>"
            . "<div class='noprint' style='max-width:640px;margin:0 auto 14px'><button class='btn' onclick='window.print()'>🖨 Print / Save as PDF</button></div>$slips</body></html>";
        exit;
    }

    public static function endProject(array $p): void
    {
        [$u, $o] = Auth::org($p, 'organization.manage');
        $proj = self::project($o, (int) $p['project_id']);
        $end = trim((string) (Http::body()['actual_end_date'] ?? '')) ?: date('Y-m-d');
        $open = (int) Database::scalar("SELECT COUNT(*) FROM project_pay_runs WHERE project_id = ? AND status = 'DRAFT'", [(int) $proj['id']]);
        Database::update('projects', (int) $proj['id'], ['status' => 'COMPLETED', 'actual_end_date' => $end]);
        Audit::record('project.end', $u, ['organization_id' => $o, 'entity' => 'project', 'entity_id' => (int) $proj['id'], 'after' => ['actual_end_date' => $end]]);
        Http::json(['id' => (int) $proj['id'], 'status' => 'COMPLETED', 'actual_end_date' => $end, 'draft_runs_open' => $open]);
    }
}
