<?php
// Historical obligation balance — balance as of a payroll period (from the ledger).

class Obligation
{
    const PAID = ['PAYROLL_DEDUCTION', 'DIRECT_PAYMENT', 'LIQUIDATION'];

    public static function summaryAsOf(int $engagementId, int $personId, int $runId, string $periodEnd): array
    {
        $loans = Database::all(
            'SELECT * FROM loans WHERE person_id = ? AND (engagement_id = ? OR engagement_id IS NULL)',
            [$personId, $engagementId]);
        $rows = [];
        $totalOut = 0.0;
        $totalCur = 0.0;
        foreach ($loans as $loan) {
            $txns = Database::all('SELECT * FROM loan_transactions WHERE loan_id = ? ORDER BY id', [$loan['id']]);
            $originDates = array_filter(array_map(fn($t) => $t['entry_date'], $txns));
            if ($originDates && min($originDates) > $periodEnd) continue;

            $original = (float) $loan['total_amount'];
            $paid = 0.0; $cur = 0.0;
            foreach ($txns as $t) {
                if (in_array($t['entry_type'], self::PAID, true) && (!$t['entry_date'] || $t['entry_date'] <= $periodEnd)) {
                    $paid += (float) $t['amount'];
                }
                if ($t['entry_type'] === 'PAYROLL_DEDUCTION' && (int) $t['payroll_run_id'] === $runId) {
                    $cur += (float) $t['amount'];
                }
            }
            $remaining = round(max($original - $paid, 0), 2);
            $inst = (float) $loan['installment_amount'];
            $left = ($inst > 0 && $remaining > 0) ? (int) ceil($remaining / $inst) : 0;
            $rows[] = [
                'description' => $loan['description'] ?: $loan['obligation_type'],
                'obligation_type' => $loan['obligation_type'], 'reference_number' => $loan['reference_number'],
                'original_amount' => round($original, 2), 'current_deduction' => round($cur, 2),
                'total_paid' => round($paid, 2), 'remaining_balance' => $remaining, 'remaining_installments' => $left,
            ];
            $totalOut += $remaining; $totalCur += $cur;
        }
        return ['rows' => $rows, 'total_outstanding' => round($totalOut, 2), 'total_current_deduction' => round($totalCur, 2)];
    }
}
