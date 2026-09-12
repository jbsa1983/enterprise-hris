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
        $r->post("$b/{id}/reassign", [self::class, 'reassign']);
        $r->get("$b/{id}/history", [self::class, 'history']);
        $r->post("$b/return-all", [self::class, 'returnAll']);
    }

    private static function log(int $assetId, string $type, ?int $personId, ?string $condition, ?string $remarks): void
    {
        // Best-effort history; never let a missing table (pre-migration) block the action.
        try {
            Database::insert('asset_events', ['asset_id' => $assetId, 'event_type' => $type, 'person_id' => $personId,
                'item_condition' => $condition, 'remarks' => $remarks, 'event_date' => date('Y-m-d')]);
        } catch (\Throwable $e) {
        }
    }

    private static function shape(array $a): array
    {
        $pid = $a['assigned_person_id'] !== null ? (int) $a['assigned_person_id'] : null;
        return ['id' => (int) $a['id'], 'asset_number' => $a['asset_number'], 'item' => $a['item'], 'serial_number' => $a['serial_number'],
            'is_employee_payable' => (int) $a['is_employee_payable'] === 1, 'assigned_person_id' => $pid,
            'assigned_person' => $pid ? Database::scalar("SELECT CONCAT_WS(' ', first_name, last_name) FROM people WHERE id = ?", [$pid]) : null,
            'cost' => $a['cost'] !== null ? (float) $a['cost'] : null, 'employee_share' => $a['employee_share'] !== null ? (float) $a['employee_share'] : null,
            'installment' => $a['installment'] !== null ? (float) $a['installment'] : null,
            'outstanding_balance' => $a['outstanding_balance'] !== null ? (float) $a['outstanding_balance'] : null,
            'issue_date' => $a['issue_date'] ?? null, 'returned_date' => $a['returned_date'] ?? null,
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
        $id = Database::insert('assets', $data);
        if (!empty($data['assigned_person_id'])) {
            self::log($id, 'ASSIGN', (int) $data['assigned_person_id'], $data['condition'] ?? null, $b['remarks'] ?? 'Issued to employee');
        }
        Http::json(['id' => $id]);
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
        $a = Database::one('SELECT * FROM assets WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$a) throw new HttpError('Asset not found', 404);
        $cond = $b['condition'] ?? 'GOOD';
        Database::update('assets', (int) $a['id'], ['status' => 'RETURNED', 'returned_date' => date('Y-m-d'), 'condition' => $cond]);
        self::log((int) $a['id'], 'RETURN', $a['assigned_person_id'] !== null ? (int) $a['assigned_person_id'] : null, $cond, $b['remarks'] ?? null);
        Http::json(['id' => (int) $a['id'], 'status' => 'RETURNED']);
    }

    /** Reassign an asset to a different person (e.g. handed over), keeping full history. */
    public static function reassign(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $a = Database::one('SELECT * FROM assets WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$a) throw new HttpError('Asset not found', 404);
        $newPid = isset($b['assigned_person_id']) && $b['assigned_person_id'] !== '' ? (int) $b['assigned_person_id'] : null;
        Database::update('assets', (int) $a['id'], [
            'assigned_person_id' => $newPid, 'status' => $newPid ? 'ISSUED' : 'IN_STOCK', 'returned_date' => null,
            'condition' => $b['condition'] ?? $a['condition']]);
        self::log((int) $a['id'], 'REASSIGN', $newPid, $b['condition'] ?? $a['condition'], $b['remarks'] ?? null);
        Http::json(['id' => (int) $a['id'], 'assigned_person_id' => $newPid, 'status' => $newPid ? 'ISSUED' : 'IN_STOCK']);
    }

    public static function history(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        $a = Database::one('SELECT id FROM assets WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$a) throw new HttpError('Asset not found', 404);
        Http::json(array_map(fn($e) => [
            'event_type' => $e['event_type'],
            'person' => $e['person_id'] ? Database::scalar("SELECT CONCAT_WS(' ', first_name, last_name) FROM people WHERE id = ?", [$e['person_id']]) : null,
            'condition' => $e['item_condition'], 'remarks' => $e['remarks'], 'date' => $e['event_date'],
        ], Database::all('SELECT * FROM asset_events WHERE asset_id = ? ORDER BY id DESC', [(int) $a['id']])));
    }

    /** Return every asset currently issued to a person — used at separation/offboarding. */
    public static function returnAll(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $pid = (int) ($b['person_id'] ?? 0);
        if (!$pid) throw new HttpError('person_id is required', 422);
        $cond = $b['condition'] ?? 'GOOD';
        $rows = Database::all("SELECT id FROM assets WHERE organization_id = ? AND assigned_person_id = ? AND status <> 'RETURNED'", [$o, $pid]);
        foreach ($rows as $r) {
            Database::update('assets', (int) $r['id'], ['status' => 'RETURNED', 'returned_date' => date('Y-m-d'), 'condition' => $cond]);
            self::log((int) $r['id'], 'RETURN', $pid, $cond, $b['remarks'] ?? 'Returned on separation');
        }
        Http::json(['returned' => count($rows)]);
    }
}
