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
        $r->post("$b/setup/copy-to", [self::class, 'copyTo']);
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

    /** Copy this org's structure (departments, positions, cost centers) to another
     *  organization. Skips items already present (by name/title/code) so it's safe
     *  to re-run, and remaps department parents and each position's department. */
    public static function copyTo(array $p): void
    {
        [$u, $src] = Auth::org($p, 'organization.manage');
        $b = Http::body();
        $tgt = (int) ($b['target_organization_id'] ?? 0);
        if (!$tgt || $tgt === $src) throw new HttpError('Choose a different destination organization', 422);
        Auth::requireOrg($u, $tgt);
        if (!Database::one('SELECT id FROM organizations WHERE id = ?', [$tgt])) throw new HttpError('Destination organization not found', 404);
        $withPos = ($b['include_positions'] ?? true) ? true : false;
        $withCc = ($b['include_cost_centers'] ?? true) ? true : false;

        // Departments — map source dept id -> target dept id (existing or newly copied).
        $existLower = []; foreach (Database::all('SELECT id, LOWER(name) n FROM departments WHERE organization_id = ?', [$tgt]) as $r) $existLower[$r['n']] = (int) $r['id'];
        $srcDepts = Database::all('SELECT * FROM departments WHERE organization_id = ?', [$src]);
        $map = []; $deptsAdded = 0;
        foreach ($srcDepts as $d) {
            $key = strtolower($d['name']);
            if (isset($existLower[$key])) { $map[(int) $d['id']] = $existLower[$key]; continue; }
            $map[(int) $d['id']] = Database::insert('departments', ['organization_id' => $tgt, 'name' => $d['name'], 'code' => $d['code'], 'parent_id' => null]);
            $deptsAdded++;
        }
        foreach ($srcDepts as $d) {
            if ($d['parent_id'] && isset($map[(int) $d['id']], $map[(int) $d['parent_id']]))
                Database::update('departments', $map[(int) $d['id']], ['parent_id' => $map[(int) $d['parent_id']]]);
        }

        $posAdded = 0;
        if ($withPos) {
            $existPos = []; foreach (Database::all('SELECT LOWER(title) t FROM positions WHERE organization_id = ?', [$tgt]) as $r) $existPos[$r['t']] = true;
            foreach (Database::all('SELECT * FROM positions WHERE organization_id = ?', [$src]) as $pos) {
                if (isset($existPos[strtolower($pos['title'])])) continue;
                $did = ($pos['department_id'] && isset($map[(int) $pos['department_id']])) ? $map[(int) $pos['department_id']] : null;
                Database::insert('positions', ['organization_id' => $tgt, 'title' => $pos['title'], 'job_grade' => $pos['job_grade'], 'department_id' => $did]);
                $posAdded++;
            }
        }

        $ccAdded = 0;
        if ($withCc) {
            $existCc = []; foreach (Database::all('SELECT LOWER(code) c FROM cost_centers WHERE organization_id = ?', [$tgt]) as $r) $existCc[$r['c']] = true;
            foreach (Database::all('SELECT * FROM cost_centers WHERE organization_id = ?', [$src]) as $cc) {
                if (isset($existCc[strtolower($cc['code'])])) continue;
                Database::insert('cost_centers', ['organization_id' => $tgt, 'code' => $cc['code'], 'name' => $cc['name']]);
                $ccAdded++;
            }
        }

        Audit::record('org.setup_copy', $u, ['organization_id' => $src, 'entity' => 'organization', 'entity_id' => $tgt,
            'after' => ['to' => $tgt, 'departments' => $deptsAdded, 'positions' => $posAdded, 'cost_centers' => $ccAdded]]);
        Http::json(['departments' => $deptsAdded, 'positions' => $posAdded, 'cost_centers' => $ccAdded]);
    }
}
