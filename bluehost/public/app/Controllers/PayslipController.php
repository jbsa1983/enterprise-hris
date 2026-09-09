<?php
class PayslipController
{
    public static function routes(Router $r): void
    {
        $r->get('/payslips/{uuid}', [self::class, 'get']);
        $r->get('/payslips/{uuid}/pdf', [self::class, 'pdf']);
    }

    private static function loadOr403(string $uuid): array
    {
        $u = Auth::require();
        $ps = Database::one('SELECT * FROM payslips WHERE uuid = ?', [$uuid]);
        if (!$ps) throw new HttpError('Payslip not found', 404);
        $allowed = $u['is_superadmin']
            || in_array((int) $ps['organization_id'], $u['org_ids'], true)
            || ($u['person_id'] !== null && (int) $ps['person_id'] === $u['person_id']);
        if (!$allowed) throw new HttpError('Not authorized to view this payslip', 403);
        return [$u, $ps];
    }

    public static function get(array $p): void
    {
        [, $ps] = self::loadOr403($p['uuid']);
        Http::json([
            'uuid' => $ps['uuid'], 'document_type' => $ps['document_type'], 'version' => (int) $ps['version'],
            'is_current' => (int) $ps['is_current'] === 1, 'status' => $ps['status'],
            'snapshot' => json_decode($ps['snapshot'], true),
        ]);
    }

    public static function pdf(array $p): void
    {
        [$u, $ps] = self::loadOr403($p['uuid']);
        Audit::record('payslip.view', $u, ['organization_id' => $ps['organization_id'], 'entity' => 'payslip', 'entity_id' => $ps['uuid']]);
        // Shared-hosting build: printable HTML (use the browser's Save-as-PDF).
        header('Content-Type: text/html; charset=utf-8');
        echo PayslipService::renderHtml(json_decode($ps['snapshot'], true));
        exit;
    }
}
