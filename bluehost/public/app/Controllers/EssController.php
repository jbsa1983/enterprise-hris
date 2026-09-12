<?php
// Employee Self-Service — each employee sees only their own records.
class EssController
{
    const FINALIZED = ['APPROVED', 'LOCKED', 'BANK_FILE_GENERATED', 'PAID', 'CLOSED'];

    public static function routes(Router $r): void
    {
        $r->get('/me/profile', [self::class, 'profile']);
        $r->get('/me/available-payslips', [self::class, 'availablePayslips']);
        $r->post('/me/payslips/generate', [self::class, 'generatePayslip']);
        $r->get('/me/leave', [self::class, 'leave']);
        $r->get('/me/leave-types', [self::class, 'leaveTypes']);
        $r->post('/me/leave', [self::class, 'requestLeave']);
        $r->get('/me/attendance', [self::class, 'attendance']);
        $r->get('/me/overtime', [self::class, 'overtime']);
        $r->post('/me/overtime', [self::class, 'requestOvertime']);
        $r->get('/me/loans', [self::class, 'loans']);
        $r->post('/me/loans', [self::class, 'requestLoan']);
        $r->get('/me/special-pay', [self::class, 'specialPay']);
        $r->get('/me/contributions', [self::class, 'contributions']);
        $r->post('/me/password', [self::class, 'password']);
    }

    /** The engagement leave/loan requests are filed against — active preferred, else most recent. */
    private static function primaryEngagement(int $personId): ?array
    {
        $rows = Database::all(
            "SELECT * FROM engagements WHERE person_id = ? ORDER BY (status = 'ACTIVE') DESC, id DESC LIMIT 1",
            [$personId]);
        return $rows[0] ?? null;
    }

    private static function personId(array $u): int
    {
        if ($u['person_id'] === null) throw new HttpError('No employee record linked to your account', 404);
        return $u['person_id'];
    }

    private static function engIds(int $personId): array
    {
        return array_map('intval', array_column(
            Database::all('SELECT id FROM engagements WHERE person_id = ?', [$personId]), 'id'));
    }

    public static function profile(): void
    {
        $u = Auth::require();
        $pid = self::personId($u);
        $person = Database::one('SELECT * FROM people WHERE id = ?', [$pid]);
        $engs = Database::all('SELECT * FROM engagements WHERE person_id = ?', [$pid]);
        $name = trim(implode(' ', array_filter([$person['first_name'], $person['middle_name'], $person['last_name'], $person['suffix']])));
        Http::json([
            'name' => $name, 'email' => $person['email'], 'mobile' => $person['mobile'], 'address' => $person['address'],
            'government_ids' => ['tin' => $person['tin'], 'sss' => $person['sss_number'],
                'philhealth' => $person['philhealth_number'], 'pagibig' => $person['pagibig_number']],
            'bank' => ['name' => $person['bank_name'], 'account_masked' => Util::maskAccount($person['bank_account_number'])],
            'engagements' => array_map(function ($e) {
                $org = Database::scalar('SELECT name FROM organizations WHERE id = ?', [$e['organization_id']]);
                return ['id' => (int) $e['id'], 'organization_id' => (int) $e['organization_id'], 'organization' => $org,
                    'employee_number' => $e['employee_number'], 'engagement_type' => $e['engagement_type'],
                    'status' => $e['status'], 'base_rate' => $e['base_rate'] !== null ? (float) $e['base_rate'] : null];
            }, $engs),
        ]);
    }

    public static function availablePayslips(): void
    {
        $u = Auth::require();
        $eng = self::engIds(self::personId($u));
        if (!$eng) { Http::json([]); }
        $in = implode(',', array_fill(0, count($eng), '?'));
        $fin = implode(',', array_fill(0, count(self::FINALIZED), '?'));
        $rows = Database::all(
            "SELECT prp.engagement_id, pr.id run_id, pr.reference, pr.status, prp.net_pay, pp.name period
               FROM payroll_run_people prp
               JOIN payroll_runs pr ON pr.id = prp.run_id
               JOIN payroll_periods pp ON pp.id = pr.period_id
              WHERE prp.engagement_id IN ($in) AND pr.status IN ($fin)
              ORDER BY pr.id DESC", array_merge($eng, self::FINALIZED));
        Http::json(array_map(function ($r) {
            $ex = Database::one('SELECT uuid FROM payslips WHERE payroll_run_id = ? AND engagement_id = ? AND is_current = 1',
                [$r['run_id'], $r['engagement_id']]);
            return ['run_id' => (int) $r['run_id'], 'engagement_id' => (int) $r['engagement_id'],
                'period' => $r['period'], 'reference' => $r['reference'], 'net_pay' => (float) $r['net_pay'],
                'payslip_uuid' => $ex['uuid'] ?? null];
        }, $rows));
    }

    public static function generatePayslip(): void
    {
        $u = Auth::require();
        $pid = self::personId($u);
        $run = Database::one('SELECT * FROM payroll_runs WHERE id = ?', [(int) (Http::body()['run_id'] ?? 0)]);
        if (!$run) throw new HttpError('Payroll run not found', 404);
        if (!in_array($run['status'], self::FINALIZED, true)) throw new HttpError('Payslip not yet available for this period', 409);
        $eng = self::engIds($pid);
        $in = implode(',', array_fill(0, count($eng), '?'));
        $line = Database::one("SELECT * FROM payroll_run_people WHERE run_id = ? AND engagement_id IN ($in)",
            array_merge([$run['id']], $eng));
        if (!$line) throw new HttpError('You have no payroll record in this run', 404);
        $ps = PayslipService::generateForEngagement($run, (int) $line['engagement_id'], $u['id']);
        Audit::record('payslip.self_generate', $u, ['organization_id' => $run['organization_id'], 'entity' => 'payslip', 'entity_id' => $ps['uuid']]);
        $snap = json_decode($ps['snapshot'], true);
        Http::json(['uuid' => $ps['uuid'], 'period' => $snap['header']['payroll_period'] ?? null]);
    }

    public static function leave(): void
    {
        $u = Auth::require();
        $eng = self::engIds(self::personId($u)) ?: [-1];
        $in = implode(',', array_fill(0, count($eng), '?'));
        Http::json(Database::all("SELECT id, leave_type, date_from, date_to, days, status FROM leave_requests
            WHERE engagement_id IN ($in) ORDER BY id DESC", $eng));
    }

    public static function attendance(): void
    {
        $u = Auth::require();
        $eng = self::engIds(self::personId($u)) ?: [-1];
        $in = implode(',', array_fill(0, count($eng), '?'));
        Http::json(Database::all("SELECT log_date, hours_worked, late_minutes, overtime_hours, status
            FROM attendance_logs WHERE engagement_id IN ($in) ORDER BY log_date DESC LIMIT 60", $eng));
    }

    public static function loans(): void
    {
        $u = Auth::require();
        $pid = self::personId($u);
        Http::json(array_map(fn($l) => [
            'type' => $l['obligation_type'], 'reference' => $l['reference_number'], 'description' => $l['description'],
            'principal' => (float) $l['principal'], 'balance' => (float) $l['balance'],
            'installment' => (float) $l['installment_amount'], 'status' => $l['status'],
        ], Database::all('SELECT * FROM loans WHERE person_id = ? ORDER BY id DESC', [$pid])));
    }

    public static function contributions(): void
    {
        $u = Auth::require();
        $eng = self::engIds(self::personId($u)) ?: [-1];
        $in = implode(',', array_fill(0, count($eng), '?'));
        $rows = Database::all("SELECT prp.deductions, pp.name period
            FROM payroll_run_people prp JOIN payroll_runs pr ON pr.id = prp.run_id
            JOIN payroll_periods pp ON pp.id = pr.period_id
            WHERE prp.engagement_id IN ($in) ORDER BY pr.id DESC", $eng);
        Http::json(array_map(function ($r) {
            $d = json_decode($r['deductions'] ?: '{}', true);
            return ['period' => $r['period'], 'sss' => (float) ($d['sss'] ?? 0), 'philhealth' => (float) ($d['philhealth'] ?? 0),
                'pagibig' => (float) ($d['pagibig'] ?? 0), 'withholding_tax' => (float) ($d['withholding_tax'] ?? 0)];
        }, $rows));
    }

    /** The person's own overtime requests (employees and consultants alike). */
    public static function overtime(): void
    {
        $u = Auth::require();
        $eng = self::engIds(self::personId($u)) ?: [-1];
        $in = implode(',', array_fill(0, count($eng), '?'));
        Http::json(Database::all("SELECT id, ot_date, hours, status FROM overtime_requests
            WHERE engagement_id IN ($in) ORDER BY id DESC", $eng));
    }

    /** File an own overtime request (routed to Dept Head / HR for approval). */
    public static function requestOvertime(): void
    {
        $u = Auth::require();
        $eng = self::primaryEngagement(self::personId($u));
        if (!$eng) throw new HttpError('You have no active engagement to file overtime against', 409);
        $b = Http::body();
        $hours = (float) ($b['hours'] ?? 0);
        if ($hours <= 0) throw new HttpError('Hours must be greater than zero', 422);
        $id = Database::insert('overtime_requests', [
            'organization_id' => (int) $eng['organization_id'], 'engagement_id' => (int) $eng['id'],
            'ot_date' => ($b['ot_date'] ?? '') ?: null, 'hours' => $hours, 'status' => 'PENDING']);
        Audit::record('overtime.self_apply', $u, ['organization_id' => (int) $eng['organization_id'], 'entity' => 'overtime_request', 'entity_id' => $id]);
        Http::json(['id' => $id, 'status' => 'PENDING']);
    }

    /** Leave types available in the employee's organization (for the request form). */
    public static function leaveTypes(): void
    {
        $u = Auth::require();
        $eng = self::primaryEngagement(self::personId($u));
        if (!$eng) { Http::json([]); return; }
        Http::json(array_map(fn($t) => ['id' => (int) $t['id'], 'name' => $t['name']],
            Database::all('SELECT id, name FROM leave_types WHERE organization_id = ? ORDER BY name', [(int) $eng['organization_id']])));
    }

    /** Employee files their own leave request (routed to Dept Head / HR for approval). */
    public static function requestLeave(): void
    {
        $u = Auth::require();
        $eng = self::primaryEngagement(self::personId($u));
        if (!$eng) throw new HttpError('You have no active engagement to file leave against', 409);
        $b = Http::body();
        $days = (float) ($b['days'] ?? 0);
        if ($days <= 0 && !empty($b['date_from']) && !empty($b['date_to'])) {
            $d1 = strtotime((string) $b['date_from']); $d2 = strtotime((string) $b['date_to']);
            if ($d1 && $d2 && $d2 >= $d1) $days = floor(($d2 - $d1) / 86400) + 1;
        }
        if ($days <= 0) $days = 1;
        $id = Database::insert('leave_requests', [
            'organization_id' => (int) $eng['organization_id'], 'engagement_id' => (int) $eng['id'],
            'leave_type' => trim((string) ($b['leave_type'] ?? 'Vacation')) ?: 'Vacation',
            'date_from' => ($b['date_from'] ?? '') ?: null, 'date_to' => ($b['date_to'] ?? '') ?: null,
            'days' => $days, 'status' => 'PENDING']);
        Audit::record('leave.self_apply', $u, ['organization_id' => (int) $eng['organization_id'], 'entity' => 'leave_request', 'entity_id' => $id]);
        Http::json(['id' => $id, 'status' => 'PENDING']);
    }

    /** Employee requests their own loan / cash advance (routed to Dept Head / HR for approval). */
    public static function requestLoan(): void
    {
        $u = Auth::require();
        $pid = self::personId($u);
        $eng = self::primaryEngagement($pid);
        if (!$eng) throw new HttpError('You have no active engagement to request against', 409);
        $b = Http::body();
        $principal = (float) ($b['principal'] ?? 0);
        if ($principal <= 0) throw new HttpError('Amount must be greater than zero', 422);
        $id = Database::insert('loans', [
            'uuid' => Util::uuid(), 'organization_id' => (int) $eng['organization_id'], 'person_id' => $pid,
            'engagement_id' => (int) $eng['id'], 'obligation_type' => (string) ($b['obligation_type'] ?? 'CASH_ADVANCE'),
            'description' => $b['description'] ?? null, 'principal' => $principal, 'interest' => 0,
            'total_amount' => $principal, 'amount_paid' => 0, 'balance' => $principal,
            'installment_amount' => (float) ($b['installment_amount'] ?? 0), 'status' => 'PENDING',
            'payroll_deductible' => 1]);
        Audit::record('loan.self_request', $u, ['organization_id' => (int) $eng['organization_id'], 'entity' => 'loan', 'entity_id' => $id]);
        Http::json(['id' => $id, 'status' => 'PENDING']);
    }

    /** The employee's own 13th-month & bonus amounts from finalized special-pay runs. */
    public static function specialPay(): void
    {
        $u = Auth::require();
        $eng = self::engIds(self::personId($u)) ?: [-1];
        $in = implode(',', array_fill(0, count($eng), '?'));
        $rows = Database::all(
            "SELECT r.name, r.pay_type, r.year, r.status, l.computed_amount, l.override_amount
               FROM special_pay_lines l JOIN special_pay_runs r ON r.id = l.run_id
              WHERE l.engagement_id IN ($in) AND r.status <> 'DRAFT'
              ORDER BY r.year DESC, r.id DESC", $eng);
        Http::json(array_map(fn($r) => [
            'name' => $r['name'], 'pay_type' => $r['pay_type'], 'year' => (int) $r['year'], 'status' => $r['status'],
            'amount' => (float) ($r['override_amount'] ?? $r['computed_amount']),
        ], $rows));
    }

    public static function password(): void
    {
        $u = Auth::require();
        $b = Http::body();
        $row = Database::one('SELECT hashed_password FROM users WHERE id = ?', [$u['id']]);
        if (!password_verify($b['current_password'] ?? '', $row['hashed_password'])) throw new HttpError('Current password is incorrect', 403);
        if (strlen($b['new_password'] ?? '') < 8) throw new HttpError('New password must be at least 8 characters', 422);
        Database::update('users', $u['id'], ['hashed_password' => password_hash($b['new_password'], PASSWORD_BCRYPT)]);
        Http::json(['ok' => true]);
    }
}
