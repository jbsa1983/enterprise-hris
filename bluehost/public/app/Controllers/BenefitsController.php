<?php
// Employee benefits (health coverage, life, etc.) and their beneficiaries.
class BenefitsController
{
    const FIELDS = ['benefit_type', 'provider', 'policy_number', 'coverage_amount', 'start_date', 'end_date', 'status', 'remarks'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}/benefits';
        $r->get($b, [self::class, 'list']);
        $r->post($b, [self::class, 'create']);
        $r->put("$b/{id}", [self::class, 'update']);
        $r->delete("$b/{id}", [self::class, 'delete']);
    }

    public static function shape(array $x): array
    {
        return [
            'id' => (int) $x['id'], 'uuid' => $x['uuid'], 'person_id' => (int) $x['person_id'],
            'person' => Database::scalar("SELECT CONCAT_WS(' ', first_name, last_name) FROM people WHERE id = ?", [$x['person_id']]),
            'engagement_id' => $x['engagement_id'] !== null ? (int) $x['engagement_id'] : null,
            'benefit_type' => $x['benefit_type'], 'provider' => $x['provider'], 'policy_number' => $x['policy_number'],
            'coverage_amount' => $x['coverage_amount'] !== null ? (float) $x['coverage_amount'] : null,
            'start_date' => $x['start_date'], 'end_date' => $x['end_date'], 'status' => $x['status'], 'remarks' => $x['remarks'],
            'beneficiaries' => array_map(fn($be) => [
                'id' => (int) $be['id'], 'name' => $be['name'], 'relationship' => $be['relationship'],
                'share_percent' => $be['share_percent'] !== null ? (float) $be['share_percent'] : null, 'contact' => $be['contact'],
            ], Database::all('SELECT * FROM benefit_beneficiaries WHERE benefit_id = ? ORDER BY id', [$x['id']])),
        ];
    }

    private static function pick(array $b): array
    {
        $out = [];
        foreach (self::FIELDS as $f) if (array_key_exists($f, $b)) {
            $v = $b[$f];
            $out[$f] = ($v === '' ? null : $v);
        }
        return $out;
    }

    private static function saveBeneficiaries(int $benefitId, array $list): void
    {
        Database::exec('DELETE FROM benefit_beneficiaries WHERE benefit_id = ?', [$benefitId]);
        foreach ($list as $be) {
            $name = trim((string) ($be['name'] ?? ''));
            if ($name === '') continue;
            Database::insert('benefit_beneficiaries', [
                'benefit_id' => $benefitId, 'name' => $name, 'relationship' => $be['relationship'] ?? null,
                'share_percent' => ($be['share_percent'] ?? '') === '' ? null : (float) $be['share_percent'],
                'contact' => $be['contact'] ?? null]);
        }
    }

    public static function list(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        $sql = 'SELECT * FROM benefits WHERE organization_id = ?'; $params = [$o];
        if ($pid = Http::query('person_id')) { $sql .= ' AND person_id = ?'; $params[] = (int) $pid; }
        Http::json(array_map([self::class, 'shape'], Database::all($sql . ' ORDER BY id DESC', $params)));
    }

    public static function create(array $p): void
    {
        [$u, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        if (empty($b['person_id'])) throw new HttpError('person_id is required', 422);
        if (empty($b['benefit_type'])) throw new HttpError('benefit_type is required', 422);
        $data = self::pick($b);
        $data['uuid'] = Util::uuid();
        $data['organization_id'] = $o;
        $data['person_id'] = (int) $b['person_id'];
        $data['engagement_id'] = !empty($b['engagement_id']) ? (int) $b['engagement_id'] : null;
        if (empty($data['status'])) $data['status'] = 'ACTIVE';
        $id = Database::insert('benefits', $data);
        self::saveBeneficiaries($id, $b['beneficiaries'] ?? []);
        Audit::record('benefit.create', $u, ['organization_id' => $o, 'entity' => 'benefit', 'entity_id' => $id]);
        Http::json(['id' => $id]);
    }

    public static function update(array $p): void
    {
        [$u, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $row = Database::one('SELECT id FROM benefits WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$row) throw new HttpError('Benefit not found', 404);
        $data = self::pick($b);
        if ($data) Database::update('benefits', (int) $row['id'], $data);
        if (array_key_exists('beneficiaries', $b)) self::saveBeneficiaries((int) $row['id'], $b['beneficiaries'] ?? []);
        Audit::record('benefit.update', $u, ['organization_id' => $o, 'entity' => 'benefit', 'entity_id' => (int) $row['id']]);
        Http::json(['id' => (int) $row['id']]);
    }

    public static function delete(array $p): void
    {
        [$u, $o] = Auth::org($p, 'employee.edit');
        $row = Database::one('SELECT id FROM benefits WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$row) throw new HttpError('Benefit not found', 404);
        Database::exec('DELETE FROM benefit_beneficiaries WHERE benefit_id = ?', [(int) $row['id']]);
        Database::exec('DELETE FROM benefits WHERE id = ?', [(int) $row['id']]);
        Audit::record('benefit.delete', $u, ['organization_id' => $o, 'entity' => 'benefit', 'entity_id' => (int) $row['id']]);
        Http::json(['deleted' => (int) $row['id']]);
    }
}
