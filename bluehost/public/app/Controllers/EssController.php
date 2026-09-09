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
        $r->get('/me/attendance', [self::class, 'attendance']);
        $r->get('/me/loans', [self::class, 'loans']);
        $r->get('/me/contributions', [self::class, 'contributions']);
        $r->post('/me/password', [self::class, 'password']);
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
