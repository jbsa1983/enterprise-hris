<?php
class AssetsController
{
    const FIELDS = ['asset_number', 'item', 'serial_number', 'is_employee_payable', 'assigned_person_id',
        'cost', 'employee_share', 'installment', 'outstanding_balance', 'condition', 'status'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}/assets';
        $r->get($b, [self::class, 'list']);
        $r->post($b, [self::class, 'create']);
        $r->put("$b/{id}", [self::class, 'update']);
        $r->delete("$b/{id}", [self::class, 'delete']);
        $r->post("$b/{id}/return", [self::class, 'markReturned']);
    }

    private static function shape(array $a): array
    {
        return ['id' => (int) $a['id'], 'asset_number' => $a['asset_number'], 'item' => $a['item'], 'serial_number' => $a['serial_number'],
            'is_employee_payable' => (int) $a['is_employee_payable'] === 1, 'assigned_person_id' => $a['assigned_person_id'] !== null ? (int) $a['assigned_person_id'] : null,
            'cost' => $a['cost'] !== null ? (float) $a['cost'] : null, 'employee_share' => $a['employee_share'] !== null ? (float) $a['employee_share'] : null,
            'installment' => $a['installment'] !== null ? (float) $a['installment'] : null,
            'outstanding_balance' => $a['outstanding_balance'] !== null ? (float) $a['outstanding_balance'] : null,
            'status' => $a['status'], 'condition' => $a['condition']];
    }

    private static function pick(array $b): array
    {
        $out = [];
        foreach (self::FIELDS as $f) if (array_key_exists($f, $b) && $b[$f] !== null && $b[$f] !== '') {
            $out[$f] = $f === 'is_employee_payable' ? (int) (bool) $b[$f] : $b[$f];
        }
        return $out;
    }

    public static function list(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        $sql = 'SELECT * FROM assets WHERE organization_id = ?'; $params = [$o];
        if ($pid = Http::query('person_id')) { $sql .= ' AND assigned_person_id = ?'; $params[] = (int) $pid; }
        Http::json(array_map([self::class, 'shape'], Database::all($sql . ' ORDER BY id DESC', $params)));
    }

    public static function create(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $data = self::pick($b);
        $data['organization_id'] = $o;
        $data['issue_date'] = date('Y-m-d');
        if (!empty($b['is_employee_payable'])) $data['outstanding_balance'] = $b['employee_share'] ?? null;
        if (empty($data['status'])) $data['status'] = 'ISSUED';
        Http::json(['id' => Database::insert('assets', $data)]);
    }

    public static function update(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit');
        $a = Database::one('SELECT id FROM assets WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$a) throw new HttpError('Asset not found', 404);
        $data = self::pick(Http::body());
        if ($data) Database::update('assets', (int) $a['id'], $data);
        Http::json(['id' => (int) $a['id']]);
    }

    public static function delete(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit');
        Database::exec('DELETE FROM assets WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        Http::json(['deleted' => (int) $p['id']]);
    }

    public static function markReturned(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $a = Database::one('SELECT id FROM assets WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$a) throw new HttpError('Asset not found', 404);
        Database::update('assets', (int) $a['id'], ['status' => 'RETURNED', 'returned_date' => date('Y-m-d'), 'condition' => $b['condition'] ?? 'GOOD']);
        Http::json(['id' => (int) $a['id'], 'status' => 'RETURNED']);
    }
}
