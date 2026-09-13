<?php
// Audit-trail viewer. Reads the append-only audit_logs table.
// Superadmins see everything; other holders of audit.view see only their orgs.
class AuditController
{
    public static function routes(Router $r): void
    {
        $r->get('/admin/audit', [self::class, 'index']);
        $r->get('/admin/audit/actions', [self::class, 'actions']);
        $r->delete('/admin/audit', [self::class, 'clear']);
    }

    /** WHERE clause + params limiting rows to what this user may see.
     *  Columns are aliased `a.` so the clause is reusable in joined queries. */
    private static function scope(array $u): array
    {
        if ($u['is_superadmin']) return ['1=1', []];
        $ids = $u['org_ids'];
        if (!$ids) return ['0=1', []]; // no orgs → nothing
        $in = implode(',', array_fill(0, count($ids), '?'));
        return ["a.organization_id IN ($in)", array_map('intval', $ids)];
    }

    public static function index(): void
    {
        $u = Auth::requirePerm('audit.view');
        [$scope, $params] = self::scope($u);
        $where = [$scope];

        $action = trim((string) Http::query('action', ''));
        if ($action !== '') { $where[] = 'a.action = ?'; $params[] = $action; }

        $org = (int) Http::query('org', 0);
        if ($org > 0) { self::requireOrgAccess($u, $org); $where[] = 'a.organization_id = ?'; $params[] = $org; }

        $q = trim((string) Http::query('q', ''));
        if ($q !== '') { $where[] = '(a.user_email LIKE ? OR a.entity LIKE ? OR a.entity_id LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }

        $from = trim((string) Http::query('from', ''));
        if ($from !== '') { $where[] = 'a.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
        $to = trim((string) Http::query('to', ''));
        if ($to !== '') { $where[] = 'a.created_at <= ?'; $params[] = $to . ' 23:59:59'; }

        $limit = min(max((int) Http::query('limit', 50), 1), 200);
        $offset = max((int) Http::query('offset', 0), 0);
        $w = implode(' AND ', $where);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs a WHERE $w", $params);
        $rows = Database::all(
            "SELECT a.id, a.user_email, a.organization_id, o.name organization_name, a.action, a.entity, a.entity_id,
                    a.before_json, a.after_json, a.ip_address, a.created_at
               FROM audit_logs a LEFT JOIN organizations o ON o.id = a.organization_id
              WHERE $w ORDER BY a.id DESC LIMIT $limit OFFSET $offset", $params);
        Http::json(['rows' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    public static function actions(): void
    {
        $u = Auth::requirePerm('audit.view');
        [$scope, $params] = self::scope($u);
        $rows = Database::all("SELECT DISTINCT a.action FROM audit_logs a WHERE $scope ORDER BY a.action", $params);
        Http::json(array_column($rows, 'action'));
    }

    /** Purge the audit trail — superadmin only. Optional `before` (YYYY-MM-DD)
     *  deletes only entries on/before that date; otherwise clears everything. */
    public static function clear(): void
    {
        $u = Auth::require();
        if (!$u['is_superadmin']) throw new HttpError('Only a superadmin can clear the audit trail', 403);
        $before = trim((string) (Http::body()['before'] ?? ''));
        if ($before !== '') {
            $deleted = Database::exec('DELETE FROM audit_logs WHERE created_at <= ?', [$before . ' 23:59:59']);
        } else {
            $deleted = Database::exec('DELETE FROM audit_logs', []);
        }
        // Leave a trace that the trail was cleared (and by whom).
        Audit::record('audit.cleared', $u, ['after' => ['deleted' => $deleted, 'before' => $before ?: 'ALL']]);
        Http::json(['ok' => true, 'deleted' => $deleted]);
    }

    private static function requireOrgAccess(array $u, int $orgId): void
    {
        if (!$u['is_superadmin'] && !in_array($orgId, $u['org_ids'], true)) {
            throw new HttpError('You do not have access to this organization', 403);
        }
    }
}
