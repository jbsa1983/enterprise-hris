<?php
class OrgStructureController
{
    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}';
        $r->get("$b/departments", [self::class, 'listDepartments']);
        $r->post("$b/departments", [self::class, 'createDepartment']);
        $r->put("$b/departments/{id}", [self::class, 'updateDepartment']);
        $r->delete("$b/departments/{id}", [self::class, 'deleteDepartment']);
        $r->get("$b/positions", [self::class, 'listPositions']);
        $r->post("$b/positions", [self::class, 'createPosition']);
        $r->put("$b/positions/{id}", [self::class, 'updatePosition']);
        $r->delete("$b/positions/{id}", [self::class, 'deletePosition']);
        $r->get("$b/cost-centers", [self::class, 'listCostCenters']);
        $r->post("$b/cost-centers", [self::class, 'createCostCenter']);
    }

    public static function listDepartments(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.view');
        Http::json(Database::all('SELECT id, name, code, parent_id FROM departments WHERE organization_id = ? ORDER BY name', [$o]));
    }
    public static function createDepartment(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.manage'); $b = Http::body();
        Http::json(['id' => Database::insert('departments', ['organization_id' => $o, 'name' => $b['name'], 'code' => $b['code'] ?? null, 'parent_id' => $b['parent_id'] ?? null])]);
    }
    public static function updateDepartment(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.manage');
        $d = Database::one('SELECT * FROM departments WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$d) throw new HttpError('Department not found', 404);
        $b = Http::body(); $u = [];
        foreach (['name', 'code', 'parent_id'] as $f) if (isset($b[$f])) $u[$f] = $b[$f];
        if ($u) Database::update('departments', (int) $d['id'], $u);
        Http::json(['id' => (int) $d['id']]);
    }
    public static function deleteDepartment(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.manage');
        Database::exec('DELETE FROM departments WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        Http::json(['deleted' => (int) $p['id']]);
    }

    public static function listPositions(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.view');
        Http::json(Database::all('SELECT id, title, job_grade, department_id FROM positions WHERE organization_id = ? ORDER BY title', [$o]));
    }
    public static function createPosition(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.manage'); $b = Http::body();
        Http::json(['id' => Database::insert('positions', ['organization_id' => $o, 'title' => $b['title'], 'job_grade' => $b['job_grade'] ?? null, 'department_id' => $b['department_id'] ?? null])]);
    }
    public static function updatePosition(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.manage');
        $x = Database::one('SELECT * FROM positions WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$x) throw new HttpError('Position not found', 404);
        $b = Http::body(); $u = [];
        foreach (['title', 'job_grade', 'department_id'] as $f) if (isset($b[$f])) $u[$f] = $b[$f];
        if ($u) Database::update('positions', (int) $x['id'], $u);
        Http::json(['id' => (int) $x['id']]);
    }
    public static function deletePosition(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.manage');
        Database::exec('DELETE FROM positions WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        Http::json(['deleted' => (int) $p['id']]);
    }

    public static function listCostCenters(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.view');
        Http::json(Database::all('SELECT id, code, name FROM cost_centers WHERE organization_id = ?', [$o]));
    }
    public static function createCostCenter(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.manage'); $b = Http::body();
        Http::json(['id' => Database::insert('cost_centers', ['organization_id' => $o, 'code' => $b['code'], 'name' => $b['name']])]);
    }
}
