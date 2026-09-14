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

    /** Fraction of a month a pay period covers (semi-monthly = 0.5, weekly ≈ 0.23). */
    public static function periodFactor(string $frequency): float
    {
        switch (strtoupper($frequency)) {
            case 'SEMI_MONTHLY': case 'SEMIMONTHLY': return 0.5;
            case 'BI_WEEKLY': case 'BIWEEKLY': return 24.0 / 52.0;
            case 'WEEKLY': return 12.0 / 52.0;
            case 'DAILY': return 1.0 / 22.0;
            default: return 1.0; // MONTHLY
        }
    }

    /** Scale monthly BIR withholding brackets to a shorter pay period. */
    private static function scaleBrackets(array $params, float $factor): array
    {
        if ($factor > 0.999 && $factor < 1.001) return $params;
        $params['brackets'] = array_map(fn($b) => [(float) $b[0] * $factor, (float) $b[1] * $factor, (float) $b[2]], $params['brackets'] ?? []);
        return $params;
    }

    /** Compute one construction/project daily-wage line for a short pay cycle
     *  (weekly / every few days). Pay = daily rate x days worked (+ OT + allowance).
     *  Statutory is prorated by time worked (days/22 of a month) and tagged to the
     *  project; withholding tax uses the same time-scaled brackets. */
    public static function computeProjectLine(float $dailyRate, float $daysWorked, string $onDate,
        float $otAmount = 0.0, float $allowance = 0.0, float $otherDeduction = 0.0, bool $withStatutory = true): array
    {
        $basic = self::r2($dailyRate * $daysWorked);
        $ot = self::r2($otAmount);
        $allow = self::r2($allowance);
        $gross = self::r2($basic + $ot + $allow);
        $monthlyEquiv = $dailyRate * 22.0;
        // Time-based proration: a full month of work is ~22 days = the full monthly
        // contribution; a week is a fraction of that. Capped at one month per run.
        $factor = $monthlyEquiv > 0 ? min($daysWorked / 22.0, 1.0) : 0.0;

        $sss = $ph = $hd = $wt = 0.0;
        if ($withStatutory && $factor > 0) {
            $sssR = self::resolveRule('SSS', $onDate);
            $phicR = self::resolveRule('PHIC', $onDate);
            $hdmfR = self::resolveRule('HDMF', $onDate);
            $birR = self::resolveRule('BIR', $onDate);
            $sMonthly = $sssR ? max(min($monthlyEquiv, $sssR['msc_cap']), $sssR['msc_floor'] ?? 0) * $sssR['employee_rate'] : 0;
            $phMonthly = $phicR ? max(min($monthlyEquiv, $phicR['salary_cap']), $phicR['floor']) * $phicR['employee_rate'] : 0;
            $hdMonthly = $hdmfR ? min($monthlyEquiv * $hdmfR['employee_rate'], $hdmfR['contribution_cap']) : 0;
            $sss = self::r2($sMonthly * $factor);
            $ph = self::r2($phMonthly * $factor);
            $hd = self::r2($hdMonthly * $factor);
            $taxable = max($gross - ($sss + $ph + $hd), 0);
            $wt = $birR ? self::withholding($taxable, self::scaleBrackets($birR, $factor)) : 0;
        }
        $other = self::r2($otherDeduction);
        $totalDed = self::r2($sss + $ph + $hd + $wt + $other);
        if ($totalDed > $gross) $totalDed = $gross;
        $net = self::r2($gross - $totalDed);
        return ['basic_pay' => $basic, 'ot_amount' => $ot, 'allowance' => $allow, 'gross_pay' => $gross,
            'sss' => $sss, 'philhealth' => $ph, 'pagibig' => $hd, 'withholding_tax' => $wt,
            'other_deduction' => $other, 'statutory' => self::r2($sss + $ph + $hd),
            'total_deductions' => $totalDed, 'net_pay' => $net];
    }

    /** Daily rate implied by an engagement's base rate and salary basis. */
    public static function dailyRate(array $eng): float
    {
        $rate = (float) ($eng['base_rate'] ?? 0);
        switch (strtoupper($eng['salary_basis'] ?? 'DAILY')) {
            case 'DAILY': return $rate;
            case 'HOURLY': return $rate * 8;
            default: return $rate / 22.0; // MONTHLY → per-day
        }
    }

    /** Employer statutory shares for a set of employee contributions, for per-project
     *  remittance summaries (SSS uses the effective ER/EE ratio; PhilHealth & Pag-IBIG match). */
    public static function employerShares(float $sssEe, float $phicEe, float $hdmfEe, string $onDate): array
    {
        $sssR = self::resolveRule('SSS', $onDate) ?: [];
        $eeRate = (float) ($sssR['employee_rate'] ?? 0.05);
        $erRate = (float) ($sssR['employer_rate'] ?? ($eeRate * 2));
        $ratio = $eeRate > 0 ? $erRate / $eeRate : 2.0;
        return ['sss' => self::r2($sssEe * $ratio), 'philhealth' => self::r2($phicEe), 'pagibig' => self::r2($hdmfEe)];
    }

    /** Compute one payroll line. $factor prorates a monthly rate to the pay period
     *  (e.g. 0.5 for a semi-monthly run). Returns [gross_pay, total_deductions, net_pay, earnings, deductions]. */
    public static function computeLine(array $eng, string $onDate, float $allowance = 0.0, array $installments = [], float $factor = 1.0): array
    {
        $monthly = self::monthlyEquivalent($eng);
        $isConsultant = in_array($eng['engagement_type'], self::CONSULTANT_TYPES, true);

        // Basic pay is prorated to the pay period; the base rate itself is monthly.
        $earnings = ['basic' => self::r2($monthly * $factor)];
        if ($allowance) $earnings['allowance'] = self::r2($allowance);
        $gross = self::r2(array_sum($earnings));

        $deductions = [];
        if ($isConsultant) {
            // Per-consultant EWT rate (5% or 10%); defaults to 10% when unset.
            $rate = ($eng['ewt_rate'] ?? null) !== null ? (float) $eng['ewt_rate'] : 10.0;
            $deductions['withholding_tax_ewt'] = self::r2($gross * $rate / 100);
        } else {
            $sss = self::resolveRule('SSS', $onDate);
            $phic = self::resolveRule('PHIC', $onDate);
            $hdmf = self::resolveRule('HDMF', $onDate);
            $bir = self::resolveRule('BIR', $onDate);
            // Monthly statutory contributions, prorated to the period (they reconcile
            // to the full monthly amount across a month's cutoffs).
            $sMonthly = $sss ? max(min($monthly, $sss['msc_cap']), $sss['msc_floor'] ?? 0) * $sss['employee_rate'] : 0;
            $phMonthly = $phic ? max(min($monthly, $phic['salary_cap']), $phic['floor']) * $phic['employee_rate'] : 0;
            $hdMonthly = $hdmf ? min($monthly * $hdmf['employee_rate'], $hdmf['contribution_cap']) : 0;
            $s = self::r2($sMonthly * $factor);
            $ph = self::r2($phMonthly * $factor);
            $hd = self::r2($hdMonthly * $factor);
            $taxable = max($gross - ($s + $ph + $hd), 0);
            $wt = $bir ? self::withholding($taxable, self::scaleBrackets($bir, $factor)) : 0;
            $deductions = ['sss' => $s, 'philhealth' => $ph, 'pagibig' => $hd, 'withholding_tax' => $wt];
            // Voluntary Pag-IBIG additional to the compulsory account — fixed monthly amount,
            // prorated to the period, added after tax (doesn't reduce taxable income).
            $extra = self::r2(((float) ($eng['hdmf_extra'] ?? 0)) * $factor);
            if ($extra > 0) $deductions['pagibig_extra'] = $extra;
        }
        // Pag-IBIG MP2 employee share — the member's own MP2 savings, collected by the
        // employer to remit. Applies to employees AND consultants. Sum of employee_share
        // across the person's MP2 accounts (mp2_ee_total), falling back to the legacy
        // single field. The employer MP2 share is the employer's cost and is not deducted
        // here — it appears only on the MP2 remittance form.
        $mp2ee = ($eng['mp2_ee_total'] ?? null) !== null ? (float) $eng['mp2_ee_total'] : (float) ($eng['hdmf_mp2'] ?? 0);
        $mp2 = self::r2($mp2ee * $factor);
        if ($mp2 > 0) $deductions['pagibig_mp2'] = $mp2;
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
