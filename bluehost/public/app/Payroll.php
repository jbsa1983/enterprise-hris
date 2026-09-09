<?php
// Payroll computation — PHP port of the Docker build's statutory service + engine.
// Statutory values come from the effective-dated statutory_rule_sets table.

class Payroll
{
    const CONSULTANT_TYPES = ['CONSULTANT_INDIVIDUAL', 'CONSULTANT_COMPANY'];

    public static function resolveRule(string $name, string $onDate): ?array
    {
        $row = Database::one(
            "SELECT parameters_json FROM statutory_rule_sets
              WHERE rule_name = ? AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC LIMIT 1", [$name, $onDate, $onDate]);
        return $row ? json_decode($row['parameters_json'], true) : null;
    }

    public static function snapshotVersions(string $onDate): array
    {
        $out = [];
        foreach (['SSS', 'PHIC', 'HDMF', 'BIR'] as $n) {
            $r = Database::one(
                "SELECT rule_version FROM statutory_rule_sets
                  WHERE rule_name = ? AND effective_from <= ?
                    AND (effective_to IS NULL OR effective_to >= ?)
                  ORDER BY effective_from DESC LIMIT 1", [$n, $onDate, $onDate]);
            if ($r) $out[$n] = $r['rule_version'];
        }
        return $out;
    }

    private static function r2($x): float { return round((float) $x + 1e-9, 2); }

    public static function monthlyEquivalent(array $eng): float
    {
        $rate = (float) ($eng['base_rate'] ?? 0);
        $basis = strtoupper($eng['salary_basis'] ?? 'MONTHLY');
        if ($basis === 'DAILY') return $rate * 22;
        if ($basis === 'HOURLY') return $rate * 22 * 8;
        return $rate;
    }

    public static function withholding(float $taxable, array $params): float
    {
        $tax = 0.0;
        foreach ($params['brackets'] ?? [] as [$lo, $base, $rate]) {
            if ($taxable > $lo) $tax = $base + ($taxable - $lo) * $rate;
        }
        return self::r2(max($tax, 0));
    }

    /** Compute one payroll line. Returns [gross_pay, total_deductions, net_pay, earnings, deductions]. */
    public static function computeLine(array $eng, string $onDate, float $allowance = 0.0, array $installments = []): array
    {
        $monthly = self::monthlyEquivalent($eng);
        $isConsultant = in_array($eng['engagement_type'], self::CONSULTANT_TYPES, true);

        $earnings = ['basic' => self::r2($monthly)];
        if ($allowance) $earnings['allowance'] = self::r2($allowance);
        $gross = array_sum($earnings);

        $deductions = [];
        if ($isConsultant) {
            $deductions['withholding_tax_ewt'] = self::r2($gross * 0.10);
        } else {
            $sss = self::resolveRule('SSS', $onDate);
            $phic = self::resolveRule('PHIC', $onDate);
            $hdmf = self::resolveRule('HDMF', $onDate);
            $bir = self::resolveRule('BIR', $onDate);
            $s = $sss ? self::r2(min($monthly, $sss['msc_cap']) * $sss['employee_rate']) : 0;
            $ph = $phic ? self::r2(max(min($monthly, $phic['salary_cap']), $phic['floor']) * $phic['employee_rate']) : 0;
            $hd = $hdmf ? self::r2(min($monthly * $hdmf['employee_rate'], $hdmf['contribution_cap'])) : 0;
            $taxable = max($gross - ($s + $ph + $hd), 0);
            $wt = $bir ? self::withholding($taxable, $bir) : 0;
            $deductions = ['sss' => $s, 'philhealth' => $ph, 'pagibig' => $hd, 'withholding_tax' => $wt];
        }
        foreach ($installments as $label => $amt) {
            if ($amt) $deductions[$label] = self::r2($amt);
        }

        $totalDed = self::r2(array_sum($deductions));
        if ($totalDed > $gross) $totalDed = self::r2($gross); // no negative net
        $net = self::r2($gross - $totalDed);

        return [
            'gross_pay' => self::r2($gross), 'total_deductions' => $totalDed, 'net_pay' => $net,
            'earnings' => $earnings, 'deductions' => $deductions,
        ];
    }
}
