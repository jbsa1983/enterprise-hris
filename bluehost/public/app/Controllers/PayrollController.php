<?php
class PayrollController
{
    const LOCKED = ['LOCKED', 'PAID', 'CLOSED', 'BANK_FILE_GENERATED'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}/payroll';
        $r->get("$b/periods", [self::class, 'listPeriods']);
        $r->post("$b/periods", [self::class, 'createPeriod']);
        $r->get("$b/runs", [self::class, 'listRuns']);
        $r->post("$b/runs", [self::class, 'createRun']);
        $r->post("$b/runs/{run_id}/compute", [self::class, 'compute']);
        $r->post("$b/runs/{run_id}/approve", [self::class, 'approve']);
        $r->post("$b/runs/{run_id}/lock", [self::class, 'lock']);
        $r->post("$b/runs/{run_id}/generate-payslips", [self::class, 'generatePayslips']);
        $r->get("$b/runs/{run_id}/payslips", [self::class, 'listPayslips']);
    }

    private static function guard(array $p, string $perm): array
    {
        $u = Auth::requirePerm($perm);
        Auth::requireOrg($u, (int) $p['organization_id']);
        return $u;
    }

    private static function getRun(int $orgId, int $runId): array
    {
        $run = Database::one('SELECT * FROM payroll_runs WHERE id = ? AND organization_id = ?', [$runId, $orgId]);
        if (!$run) throw new HttpError('Payroll run not found', 404);
        return $run;
    }

    public static function listPeriods(array $p): void
    {
        self::guard($p, 'payroll.view');
        Http::json(Database::all(
            'SELECT id, name, frequency, period_start, period_end, pay_date FROM payroll_periods
              WHERE organization_id = ? ORDER BY period_start DESC', [(int) $p['organization_id']]));
    }

    public static function createPeriod(array $p): void
    {
        self::guard($p, 'payroll.prepare');
        $b = Http::body();
        $id = Database::insert('payroll_periods', [
            'organization_id' => (int) $p['organization_id'], 'name' => $b['name'],
            'frequency' => $b['frequency'] ?? 'MONTHLY', 'period_start' => $b['period_start'],
            'period_end' => $b['period_end'], 'pay_date' => $b['pay_date'] ?? null,
        ]);
        Http::json(['id' => $id, 'name' => $b['name']]);
    }

    public static function listRuns(array $p): void
    {
        self::guard($p, 'payroll.view');
        $runs = Database::all('SELECT * FROM payroll_runs WHERE organization_id = ? ORDER BY id DESC', [(int) $p['organization_id']]);
        Http::json(array_map(function ($r) {
            $per = Database::one('SELECT name, period_end FROM payroll_periods WHERE id = ?', [$r['period_id']]);
            $lc = (int) Database::scalar('SELECT COUNT(*) FROM payroll_run_people WHERE run_id = ?', [$r['id']]);
            return [
                'id' => (int) $r['id'], 'uuid' => $r['uuid'], 'reference' => $r['reference'], 'status' => $r['status'],
                'period' => $per['name'] ?? null, 'period_end' => $per['period_end'] ?? null,
                'gross_total' => (float) $r['gross_total'], 'deduction_total' => (float) $r['deduction_total'],
                'net_total' => (float) $r['net_total'], 'line_count' => $lc,
                'rule_versions' => json_decode($r['rule_version_snapshot'] ?: '{}', true),
            ];
        }, $runs));
    }

    public static function createRun(array $p): void
    {
        $u = self::guard($p, 'payroll.prepare');
        $b = Http::body();
        $orgId = (int) $p['organization_id'];
        $period = Database::one('SELECT * FROM payroll_periods WHERE id = ? AND organization_id = ?', [(int) $b['period_id'], $orgId]);
        if (!$period) throw new HttpError('Payroll period not found', 404);
        $ref = $b['reference'] ?? ('RUN-' . $orgId . '-' . date('Ym', strtotime($period['period_start'])));
        $id = Database::insert('payroll_runs', [
            'uuid' => Util::uuid(), 'organization_id' => $orgId, 'period_id' => $period['id'],
            'reference' => $ref, 'status' => 'DRAFT',
        ]);
        Audit::record('payroll.create', $u, ['organization_id' => $orgId, 'entity' => 'payroll_run', 'entity_id' => $id]);
        Http::json(['id' => $id, 'reference' => $ref, 'status' => 'DRAFT']);
    }

    public static function compute(array $p): void
    {
        $u = self::guard($p, 'payroll.compute');
        $orgId = (int) $p['organization_id'];
        $run = self::getRun($orgId, (int) $p['run_id']);
        if (in_array($run['status'], self::LOCKED, true)) throw new HttpError('Locked payroll cannot be recomputed', 409);
        $allowance = (float) (Http::body()['allowance'] ?? 0);
        $period = Database::one('SELECT * FROM payroll_periods WHERE id = ?', [$run['period_id']]);
        $onDate = $period['period_end'];

        // Reverse prior computation of this run.
        foreach (Database::all('SELECT * FROM loan_transactions WHERE payroll_run_id = ?', [$run['id']]) as $t) {
            if ($t['entry_type'] === 'PAYROLL_DEDUCTION') {
                $loan = Database::one('SELECT * FROM loans WHERE id = ?', [$t['loan_id']]);
                if ($loan) Database::update('loans', (int) $loan['id'], [
                    'balance' => (float) $loan['balance'] + (float) $t['amount'],
                    'amount_paid' => max((float) $loan['amount_paid'] - (float) $t['amount'], 0),
                    'status' => 'ACTIVE',
                ]);
            }
            Database::exec('DELETE FROM loan_transactions WHERE id = ?', [$t['id']]);
        }
        Database::exec('DELETE FROM payroll_run_people WHERE run_id = ?', [$run['id']]);

        $engs = Database::all("SELECT * FROM engagements WHERE organization_id = ? AND status = 'ACTIVE'", [$orgId]);
        $gt = $dt = $nt = 0.0;
        foreach ($engs as $eng) {
            $loans = Database::all("SELECT * FROM loans WHERE person_id = ? AND payroll_deductible = 1 AND balance > 0 AND status = 'ACTIVE'", [$eng['person_id']]);
            $inst = []; $plan = [];
            foreach ($loans as $loan) {
                $amt = min((float) $loan['installment_amount'], (float) $loan['balance']);
                if ($amt > 0) {
                    $label = strtolower($loan['obligation_type']);
                    $inst[$label] = ($inst[$label] ?? 0) + $amt;
                    $plan[] = [$loan, $amt];
                }
            }
            $line = Payroll::computeLine($eng, $onDate, $allowance, $inst);
            Database::insert('payroll_run_people', [
                'run_id' => $run['id'], 'engagement_id' => $eng['id'], 'gross_pay' => $line['gross_pay'],
                'total_deductions' => $line['total_deductions'], 'net_pay' => $line['net_pay'],
                'earnings' => json_encode($line['earnings']), 'deductions' => json_encode($line['deductions']),
            ]);
            $gt += $line['gross_pay']; $dt += $line['total_deductions']; $nt += $line['net_pay'];
            if ($line['net_pay'] >= 0) {
                foreach ($plan as [$loan, $amt]) {
                    $newBal = (float) $loan['balance'] - $amt;
                    Database::update('loans', (int) $loan['id'], [
                        'balance' => $newBal, 'amount_paid' => (float) $loan['amount_paid'] + $amt,
                        'status' => $newBal <= 0 ? 'PAID' : 'ACTIVE',
                    ]);
                    Database::insert('loan_transactions', [
                        'loan_id' => $loan['id'], 'entry_type' => 'PAYROLL_DEDUCTION', 'amount' => $amt,
                        'balance_after' => $newBal, 'payroll_run_id' => $run['id'], 'period_label' => $period['name'],
                        'entry_date' => $onDate,
                    ]);
                }
            }
        }
        Database::update('payroll_runs', (int) $run['id'], [
            'gross_total' => round($gt, 2), 'deduction_total' => round($dt, 2), 'net_total' => round($nt, 2),
            'status' => 'CALCULATED', 'rule_version_snapshot' => json_encode(Payroll::snapshotVersions($onDate)),
        ]);
        Audit::record('payroll.compute', $u, ['organization_id' => $orgId, 'entity' => 'payroll_run', 'entity_id' => $run['id']]);
        Http::json(['id' => $run['id'], 'status' => 'CALCULATED', 'gross_total' => round($gt, 2),
            'deduction_total' => round($dt, 2), 'net_total' => round($nt, 2)]);
    }

    public static function approve(array $p): void { self::transition($p, 'payroll.approve', 'APPROVED', ['LOCKED']); }
    public static function lock(array $p): void    { self::transition($p, 'payroll.lock', 'LOCKED', ['DRAFT', 'CALCULATED', 'FOR_REVIEW']); }

    private static function transition(array $p, string $perm, string $newStatus, array $blockFrom): void
    {
        $u = self::guard($p, $perm);
        $orgId = (int) $p['organization_id'];
        $run = self::getRun($orgId, (int) $p['run_id']);
        if ($newStatus === 'LOCKED' && !in_array($run['status'], ['APPROVED', 'LOCKED'], true))
            throw new HttpError('Only approved payroll can be locked', 409);
        Database::update('payroll_runs', (int) $run['id'], ['status' => $newStatus]);
        Audit::record($perm, $u, ['organization_id' => $orgId, 'entity' => 'payroll_run', 'entity_id' => $run['id'],
            'after' => ['status' => $newStatus]]);
        Http::json(['id' => $run['id'], 'status' => $newStatus]);
    }

    public static function generatePayslips(array $p): void
    {
        $u = self::guard($p, 'payroll.export');
        $orgId = (int) $p['organization_id'];
        $run = self::getRun($orgId, (int) $p['run_id']);
        $n = PayslipService::generateForRun($run, $u['id']);
        Audit::record('payslip.generate', $u, ['organization_id' => $orgId, 'entity' => 'payroll_run',
            'entity_id' => $run['id'], 'after' => ['count' => $n]]);
        Http::json(['generated' => $n, 'run_id' => (int) $run['id']]);
    }

    public static function listPayslips(array $p): void
    {
        self::guard($p, 'payroll.view');
        $run = self::getRun((int) $p['organization_id'], (int) $p['run_id']);
        $rows = Database::all('SELECT * FROM payslips WHERE payroll_run_id = ? AND is_current = 1 ORDER BY id', [$run['id']]);
        Http::json(array_map(function ($ps) {
            $s = json_decode($ps['snapshot'], true);
            return ['uuid' => $ps['uuid'], 'document_type' => $ps['document_type'],
                'name' => $s['header']['name'] ?? null, 'employee_number' => $s['header']['employee_number'] ?? null,
                'net_pay' => (float) $ps['net_pay'], 'version' => (int) $ps['version'], 'has_pdf' => true];
        }, $rows));
    }
}
