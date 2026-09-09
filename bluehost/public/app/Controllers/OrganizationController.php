<?php
class OrganizationController
{
    public static function routes(Router $r): void
    {
        $r->get('/organizations', [self::class, 'list']);
        $r->get('/organizations/{id}', [self::class, 'get']);
    }

    public static function list(): void
    {
        $u = Auth::require();
        if ($u['is_superadmin']) {
            $rows = Database::all('SELECT * FROM organizations WHERE is_active = 1 ORDER BY name');
        } else {
            $ids = $u['org_ids'] ?: [-1];
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::all("SELECT * FROM organizations WHERE is_active = 1 AND id IN ($in) ORDER BY name", $ids);
        }
        Http::json(array_map([self::class, 'shape'], $rows));
    }

    public static function get(array $p): void
    {
        $u = Auth::require();
        $id = (int) $p['id'];
        Auth::requireOrg($u, $id);
        $o = Database::one('SELECT * FROM organizations WHERE id = ?', [$id]);
        if (!$o) Http::error('Organization not found', 404);
        Http::json(self::shape($o));
    }

    private static function shape(array $o): array
    {
        return [
            'id' => (int) $o['id'], 'uuid' => $o['uuid'], 'name' => $o['name'], 'code' => $o['code'],
            'legal_name' => $o['legal_name'], 'is_active' => (int) $o['is_active'] === 1,
        ];
    }
}
