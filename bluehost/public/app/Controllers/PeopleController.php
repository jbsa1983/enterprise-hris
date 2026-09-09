<?php
class PeopleController
{
    const CONSULTANT_TYPES = ['CONSULTANT_INDIVIDUAL', 'CONSULTANT_COMPANY'];

    public static function routes(Router $r): void
    {
        $r->get('/organizations/{organization_id}/people', [self::class, 'people']);
        $r->get('/organizations/{organization_id}/employees', [self::class, 'employees']);
        $r->get('/organizations/{organization_id}/consultants', [self::class, 'consultants']);
    }

    private static function rows(int $orgId, ?string $mode): array
    {
        $sql = "SELECT e.id eng_id, e.person_id, e.engagement_type, e.employee_number, e.status,
                       e.base_rate, e.start_date, e.end_date,
                       p.first_name, p.middle_name, p.last_name, p.suffix
                  FROM engagements e JOIN people p ON p.id = e.person_id
                 WHERE e.organization_id = ?";
        $params = [$orgId];
        if ($mode === 'employees') {
            $in = implode(',', array_fill(0, count(self::CONSULTANT_TYPES), '?'));
            $sql .= " AND e.engagement_type NOT IN ($in)";
            $params = array_merge($params, self::CONSULTANT_TYPES);
        } elseif ($mode === 'consultants') {
            $in = implode(',', array_fill(0, count(self::CONSULTANT_TYPES), '?'));
            $sql .= " AND e.engagement_type IN ($in)";
            $params = array_merge($params, self::CONSULTANT_TYPES);
        }
        $sql .= ' ORDER BY p.last_name';
        return array_map(function ($r) {
            $name = trim(implode(' ', array_filter([$r['first_name'], $r['middle_name'], $r['last_name'], $r['suffix']])));
            return [
                'engagement_id' => (int) $r['eng_id'], 'person_id' => (int) $r['person_id'],
                'full_name' => $name, 'engagement_type' => $r['engagement_type'],
                'employee_number' => $r['employee_number'], 'status' => $r['status'],
                'base_rate' => $r['base_rate'] !== null ? (float) $r['base_rate'] : null,
                'start_date' => $r['start_date'], 'end_date' => $r['end_date'],
            ];
        }, Database::all($sql, $params));
    }

    private static function guard(array $p): int
    {
        $u = Auth::requirePerm('employee.view');
        $id = (int) $p['organization_id'];
        Auth::requireOrg($u, $id);
        return $id;
    }

    public static function people(array $p): void { Http::json(self::rows(self::guard($p), null)); }
    public static function employees(array $p): void { Http::json(self::rows(self::guard($p), 'employees')); }
    public static function consultants(array $p): void { Http::json(self::rows(self::guard($p), 'consultants')); }
}
