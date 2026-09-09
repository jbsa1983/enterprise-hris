<?php
class LoansController
{
    const INCREASE = ['CHARGE', 'INTEREST', 'NEW_LOAN', 'NEW_ADVANCE', 'OPENING_BALANCE'];
    const DECREASE = ['PAYROLL_DEDUCTION', 'DIRECT_PAYMENT', 'LIQUIDATION'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}/loans';
        $r->get("$b/summary", [self::class, 'summary']);
        $r->get($b, [self::class, 'list']);
        $r->get("$b/{id}", [self::class, 'get']);
        $r->post($b, [self::class, 'create']);
        $r->post("$b/{id}/adjust", [self::class, 'adjust']);
    }

    private static function shape(array $l): array
    {
        $person = Database::scalar("SELECT CONCAT_WS(' ', first_name, last_name) FROM people WHERE id = ?", [$l['person_id']]);
        return ['id' => (int) $l['id'], 'uuid' => $l['uuid'], 'person_id' => (int) $l['person_id'], 'person' => $person,
            'obligation_type' => $l['obligation_type'], 'reference_number' => $l['reference_number'], 'description' => $l['description'],
            'principal' => (float) $l['principal'], 'interest' => (float) $l['interest'], 'total_amount' => (float) $l['total_amount'],
            'amount_paid' => (float) $l['amount_paid'], 'balance' => (float) $l['balance'], 'installment_amount' => (float) $l['installment_amount'],
            'status' => $l['status'], 'payroll_deductible' => (int) $l['payroll_deductible'] === 1];
    }

    public static function summary(array $p): void
    {
        [, $o] = Auth::org($p, 'loan.view');
        Http::json(array_map(fn($r) => ['obligation_type' => $r['obligation_type'], 'count' => (int) $r['c'], 'outstanding' => (float) $r['b']],
            Database::all('SELECT obligation_type, COUNT(*) c, COALESCE(SUM(balance),0) b FROM loans WHERE organization_id = ? GROUP BY obligation_type', [$o])));
    }

    public static function list(array $p): void
    {
        [, $o] = Auth::org($p, 'loan.view');
        $sql = 'SELECT * FROM loans WHERE organization_id = ?'; $params = [$o];
        if ($t = Http::query('obligation_type')) { $sql .= ' AND obligation_type = ?'; $params[] = $t; }
        if ($s = Http::query('status')) { $sql .= ' AND status = ?'; $params[] = $s; }
        Http::json(array_map([self::class, 'shape'], Database::all($sql . ' ORDER BY id DESC', $params)));
    }

    public static function get(array $p): void
    {
        [, $o] = Auth::org($p, 'loan.view');
        $l = Database::one('SELECT * FROM loans WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$l) throw new HttpError('Loan not found', 404);
        $d = self::shape($l);
        $d['ledger'] = array_map(fn($t) => ['entry_type' => $t['entry_type'], 'amount' => (float) $t['amount'],
            'balance_after' => (float) $t['balance_after'], 'period_label' => $t['period_label'], 'date' => $t['entry_date'], 'remarks' => $t['remarks']],
            Database::all('SELECT * FROM loan_transactions WHERE loan_id = ? ORDER BY id', [$l['id']]));
        Http::json($d);
    }

    public static function create(array $p): void
    {
        [$u, $o] = Auth::org($p, 'loan.create'); $b = Http::body();
        $principal = (float) ($b['principal'] ?? 0); $interest = (float) ($b['interest'] ?? 0); $total = $principal + $interest;
        $id = Database::insert('loans', ['uuid' => Util::uuid(), 'organization_id' => $o, 'person_id' => (int) $b['person_id'],
            'engagement_id' => $b['engagement_id'] ?? null, 'obligation_type' => $b['obligation_type'] ?? 'COMPANY_LOAN',
            'reference_number' => $b['reference_number'] ?? null, 'description' => $b['description'] ?? null,
            'principal' => $principal, 'interest' => $interest, 'total_amount' => $total, 'amount_paid' => 0, 'balance' => $total,
            'installment_amount' => (float) ($b['installment_amount'] ?? 0), 'status' => 'ACTIVE',
            'payroll_deductible' => isset($b['payroll_deductible']) ? (int) (bool) $b['payroll_deductible'] : 1]);
        Database::insert('loan_transactions', ['loan_id' => $id, 'entry_type' => 'NEW_LOAN', 'amount' => $total, 'balance_after' => $total, 'entry_date' => date('Y-m-d'), 'remarks' => 'Loan/advance granted']);
        Audit::record('loan.create', $u, ['organization_id' => $o, 'entity' => 'loan', 'entity_id' => $id]);
        Http::json(['id' => $id]);
    }

    public static function adjust(array $p): void
    {
        [$u, $o] = Auth::org($p, 'loan.adjust'); $b = Http::body();
        $l = Database::one('SELECT * FROM loans WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$l) throw new HttpError('Loan not found', 404);
        $type = strtoupper($b['entry_type'] ?? 'DIRECT_PAYMENT'); $amt = (float) ($b['amount'] ?? 0);
        if ($amt <= 0) throw new HttpError('amount must be positive', 422);
        $bal = (float) $l['balance']; $paid = (float) $l['amount_paid']; $total = (float) $l['total_amount'];
        if (in_array($type, self::DECREASE, true)) { $bal -= $amt; $paid += $amt; }
        elseif (in_array($type, self::INCREASE, true)) { $bal += $amt; $total += $amt; }
        else { $bal += (($b['direction'] ?? '') === 'increase') ? $amt : -$amt; }
        $status = $l['status'];
        if ($bal <= 0) { $bal = 0; $status = 'PAID'; }
        Database::update('loans', (int) $l['id'], ['balance' => $bal, 'amount_paid' => $paid, 'total_amount' => $total, 'status' => $status]);
        Database::insert('loan_transactions', ['loan_id' => $l['id'], 'entry_type' => $type, 'amount' => $amt, 'balance_after' => $bal, 'entry_date' => date('Y-m-d'), 'period_label' => $b['period_label'] ?? null, 'remarks' => $b['remarks'] ?? null]);
        Audit::record('loan.adjust', $u, ['organization_id' => $o, 'entity' => 'loan', 'entity_id' => $l['id']]);
        Http::json(['id' => (int) $l['id'], 'balance' => $bal, 'status' => $status]);
    }
}
