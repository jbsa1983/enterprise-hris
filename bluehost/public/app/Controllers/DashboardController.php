<?php
class DashboardController
{
    const CONSULTANT_TYPES = ['CONSULTANT_INDIVIDUAL', 'CONSULTANT_COMPANY'];

    public static function routes(Router $r): void
    {
        $r->get('/dashboard/enterprise', [self::class, 'enterprise']);
        $r->get('/organizations/{id}/dashboard', [self::class, 'organization']);
    }

    private static function inClause(array $ids): array
    {
        if (!$ids) return ['(-1)', []];
        return ['(' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    private static function headcountByType(array $orgIds): array
    {
        [$in, $params] = self::inClause($orgIds);
        $rows = Database::all(
            "SELECT engagement_type, COUNT(*) c FROM engagements
              WHERE organization_id IN $in AND status = 'ACTIVE' GROUP BY engagement_type", $params);
        $out = [];
        foreach ($rows as $r) $out[$r['engagement_type']] = (int) $r['c'];
        return $out;
    }

    private static function classify(array $counts): array
    {
        $consultants = 0;
        foreach (self::CONSULTANT_TYPES as $t) $consultants += $counts[$t] ?? 0;
        return [
            'total_active_personnel' => array_sum($counts),
            'regular' => $counts['REGULAR'] ?? 0,
            'probationary' => $counts['PROBATIONARY'] ?? 0,
            'project_based' => $counts['PROJECT_BASED'] ?? 0,
            'fixed_term' => $counts['FIXED_TERM'] ?? 0,
            'consultants' => $consultants,
            'by_type' => $counts,
        ];
    }

    private static function contractsExpiring(array $orgIds): array
    {
        [$in, $params] = self::inClause($orgIds);
        $out = [];
        foreach ([30, 60, 90] as $w) {
            $out["in_{$w}_days"] = (int) Database::scalar(
                "SELECT COUNT(*) FROM engagements
                  WHERE organization_id IN $in AND status = 'ACTIVE' AND end_date IS NOT NULL
                    AND end_date >= CURDATE() AND end_date <= DATE_ADD(CURDATE(), INTERVAL $w DAY)", $params);
        }
        return $out;
    }

    public static function enterprise(): void
    {
        $u = Auth::require();
        $orgIds = Auth::accessibleOrgIds($u);
        [$in, $params] = self::inClause($orgIds);
        $counts = self::headcountByType($orgIds);

        $activeProjects = (int) Database::scalar(
            "SELECT COUNT(*) FROM projects WHERE organization_id IN $in AND status = 'ACTIVE'", $params);
        $payrollNet = (float) Database::scalar(
            "SELECT COALESCE(SUM(net_total),0) FROM payroll_runs WHERE organization_id IN $in", $params);
        $pending = (int) Database::scalar(
            "SELECT COUNT(*) FROM payroll_runs WHERE organization_id IN $in AND status IN ('FOR_REVIEW','CALCULATED')", $params);
        $workforceCost = (float) Database::scalar(
            "SELECT COALESCE(SUM(base_rate),0) FROM engagements WHERE organization_id IN $in AND status = 'ACTIVE'", $params);

        $distribution = [];
        if ($orgIds) {
            foreach (Database::all("SELECT id, name FROM organizations WHERE id IN $in", $params) as $o) {
                $distribution[] = [
                    'organization_id' => (int) $o['id'], 'name' => $o['name'],
                    'personnel' => (int) Database::scalar(
                        "SELECT COUNT(*) FROM engagements WHERE organization_id = ? AND status='ACTIVE'", [$o['id']]),
                ];
            }
        }

        Http::json(array_merge([
            'organizations_count' => count($orgIds),
        ], self::classify($counts), [
            'active_projects' => $activeProjects,
            'payroll_net_total' => $payrollNet,
            'pending_approvals' => $pending,
            'contracts_expiring' => $orgIds ? self::contractsExpiring($orgIds) : new stdClass(),
            'employee_distribution' => $distribution,
            'workforce_cost_monthly' => $workforceCost,
        ]));
    }

    public static function organization(array $p): void
    {
        $u = Auth::require();
        $id = (int) $p['id'];
        Auth::requireOrg($u, $id);
        $org = Database::one('SELECT * FROM organizations WHERE id = ?', [$id]);
        if (!$org) Http::error('Organization not found', 404);
        $counts = self::headcountByType([$id]);
        $latest = Database::one('SELECT * FROM payroll_runs WHERE organization_id = ? ORDER BY id DESC LIMIT 1', [$id]);

        Http::json(array_merge([
            'organization_id' => (int) $org['id'], 'organization_name' => $org['name'],
        ], self::classify($counts), [
            'departments' => (int) Database::scalar(
                'SELECT COUNT(DISTINCT department_id) FROM engagements WHERE organization_id = ?', [$id]),
            'active_projects' => (int) Database::scalar(
                "SELECT COUNT(*) FROM projects WHERE organization_id = ? AND status='ACTIVE'", [$id]),
            'payroll_period_status' => $latest['status'] ?? 'NONE',
            'gross_payroll' => (float) ($latest['gross_total'] ?? 0),
            'total_deductions' => (float) ($latest['deduction_total'] ?? 0),
            'net_payroll' => (float) ($latest['net_total'] ?? 0),
            'pending_leave_approvals' => (int) Database::scalar(
                "SELECT COUNT(*) FROM leave_requests WHERE organization_id = ? AND status='PENDING'", [$id]),
            'pending_overtime_approvals' => (int) Database::scalar(
                "SELECT COUNT(*) FROM overtime_requests WHERE organization_id = ? AND status='PENDING'", [$id]),
            'contracts_expiring' => self::contractsExpiring([$id]),
        ]));
    }
}
