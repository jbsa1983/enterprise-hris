<?php
class HrModulesController
{
    const ONBOARDING = ['Employee data', 'Government numbers', 'Bank account', 'NDA', 'Contract', 'Privacy consent',
        'Company policies', 'Orientation', 'Asset issuance', 'Account request'];
    const OFFBOARDING = ['Notice', 'Clearance', 'Asset return', 'Loan balance settlement', 'Cash advance settlement',
        'Final pay', 'COE', 'Exit interview', 'IT account deactivation', 'Final documents'];

    public static function routes(Router $r): void
    {
        $b = '/organizations/{organization_id}';
        // Performance
        $r->get("$b/performance/cycles", [self::class, 'perfCycles']);
        $r->post("$b/performance/cycles", [self::class, 'createCycle']);
        $r->get("$b/performance/reviews", [self::class, 'perfReviews']);
        $r->post("$b/performance/reviews", [self::class, 'createReview']);
        // Training
        $r->get("$b/training/courses", [self::class, 'courses']);
        $r->post("$b/training/courses", [self::class, 'createCourse']);
        $r->get("$b/training/assignments", [self::class, 'assignments']);
        $r->post("$b/training/assignments", [self::class, 'assign']);
        $r->post("$b/training/assignments/{id}/complete", [self::class, 'completeTraining']);
        // Service desk
        $r->get("$b/service-tickets", [self::class, 'tickets']);
        $r->post("$b/service-tickets", [self::class, 'createTicket']);
        $r->post("$b/service-tickets/{id}/status", [self::class, 'ticketStatus']);
        // Lifecycle
        $r->get("$b/lifecycle", [self::class, 'lifecycle']);
        $r->post("$b/lifecycle/init", [self::class, 'initLifecycle']);
        $r->post("$b/lifecycle/{id}/complete", [self::class, 'completeLifecycle']);
        // Workflow / approvals
        $r->get("$b/workflows", [self::class, 'workflows']);
        $r->post("$b/workflows", [self::class, 'createWorkflow']);
        $r->get("$b/approvals", [self::class, 'approvals']);
        $r->post("$b/approvals", [self::class, 'raiseApproval']);
        $r->post("$b/approvals/{id}/act", [self::class, 'actApproval']);
    }

    public static function perfCycles(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        Http::json(Database::all('SELECT id, name, cycle_type, status FROM performance_cycles WHERE organization_id = ?', [$o]));
    }
    public static function createCycle(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        Http::json(['id' => Database::insert('performance_cycles', ['organization_id' => $o, 'name' => $b['name'], 'cycle_type' => $b['cycle_type'] ?? 'ANNUAL', 'status' => 'OPEN'])]);
    }
    public static function perfReviews(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        $sql = 'SELECT id, engagement_id, cycle_id, self_score, supervisor_score, final_rating, status FROM performance_reviews WHERE organization_id = ?';
        $params = [$o];
        if ($c = Http::query('cycle_id')) { $sql .= ' AND cycle_id = ?'; $params[] = (int) $c; }
        Http::json(array_map(fn($r) => ['id' => (int) $r['id'], 'engagement_id' => (int) $r['engagement_id'], 'cycle_id' => (int) $r['cycle_id'],
            'self_score' => $r['self_score'] !== null ? (float) $r['self_score'] : null, 'supervisor_score' => $r['supervisor_score'] !== null ? (float) $r['supervisor_score'] : null,
            'final_rating' => $r['final_rating'] !== null ? (float) $r['final_rating'] : null, 'status' => $r['status']], Database::all($sql, $params)));
    }
    public static function createReview(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $self = $b['self_score'] ?? null; $sup = $b['supervisor_score'] ?? null;
        $final = ($self !== null && $sup !== null) ? round(((float) $self + (float) $sup) / 2, 2) : null;
        $id = Database::insert('performance_reviews', ['organization_id' => $o, 'cycle_id' => (int) $b['cycle_id'], 'engagement_id' => (int) $b['engagement_id'],
            'self_score' => $self, 'supervisor_score' => $sup, 'final_rating' => $final, 'status' => $final !== null ? 'COMPLETED' : 'DRAFT']);
        Http::json(['id' => $id, 'final_rating' => $final]);
    }

    public static function courses(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        Http::json(Database::all('SELECT id, title, category, provider FROM training_courses WHERE organization_id = ?', [$o]));
    }
    public static function createCourse(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        Http::json(['id' => Database::insert('training_courses', ['organization_id' => $o, 'title' => $b['title'], 'category' => $b['category'] ?? null, 'provider' => $b['provider'] ?? null])]);
    }
    public static function assignments(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        Http::json(array_map(fn($t) => ['id' => (int) $t['id'], 'course_id' => (int) $t['course_id'], 'engagement_id' => (int) $t['engagement_id'], 'status' => $t['status'], 'completed_date' => $t['completed_date']],
            Database::all('SELECT * FROM training_assignments WHERE organization_id = ?', [$o])));
    }
    public static function assign(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        Http::json(['id' => Database::insert('training_assignments', ['organization_id' => $o, 'course_id' => (int) $b['course_id'], 'engagement_id' => (int) $b['engagement_id'], 'status' => 'ASSIGNED'])]);
    }
    public static function completeTraining(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit');
        $t = Database::one('SELECT id FROM training_assignments WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$t) throw new HttpError('Assignment not found', 404);
        Database::update('training_assignments', (int) $t['id'], ['status' => 'COMPLETED', 'completed_date' => date('Y-m-d')]);
        Http::json(['id' => (int) $t['id'], 'status' => 'COMPLETED']);
    }

    public static function tickets(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        $sql = 'SELECT id, ticket_number, category, priority, status, subject, assigned_hr FROM service_tickets WHERE organization_id = ?';
        $params = [$o];
        if ($s = Http::query('status')) { $sql .= ' AND status = ?'; $params[] = $s; }
        Http::json(Database::all($sql . ' ORDER BY id DESC', $params));
    }
    public static function createTicket(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view'); $b = Http::body();
        $n = (int) Database::scalar('SELECT COUNT(*) FROM service_tickets WHERE organization_id = ?', [$o]);
        $num = 'TKT-' . $o . '-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
        $id = Database::insert('service_tickets', ['uuid' => Util::uuid(), 'organization_id' => $o, 'ticket_number' => $num,
            'engagement_id' => $b['engagement_id'] ?? null, 'category' => $b['category'] ?? 'HR question', 'priority' => $b['priority'] ?? 'NORMAL',
            'subject' => $b['subject'], 'description' => $b['description'] ?? null, 'status' => 'OPEN']);
        Http::json(['id' => $id, 'ticket_number' => $num]);
    }
    public static function ticketStatus(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $t = Database::one('SELECT id FROM service_tickets WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$t) throw new HttpError('Ticket not found', 404);
        Database::update('service_tickets', (int) $t['id'], ['status' => $b['status'] ?? 'IN_PROGRESS', 'assigned_hr' => $b['assigned_hr'] ?? null]);
        Http::json(['id' => (int) $t['id'], 'status' => $b['status'] ?? 'IN_PROGRESS']);
    }

    public static function lifecycle(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        $kind = strtoupper(Http::query('kind', 'ONBOARDING'));
        Http::json(array_map(fn($c) => ['id' => (int) $c['id'], 'item' => $c['item'], 'completed' => (int) $c['completed'] === 1, 'completed_date' => $c['completed_date']],
            Database::all('SELECT * FROM lifecycle_checklists WHERE organization_id = ? AND engagement_id = ? AND kind = ?', [$o, (int) Http::query('engagement_id'), $kind])));
    }
    public static function initLifecycle(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $kind = strtoupper($b['kind'] ?? 'ONBOARDING');
        $items = $kind === 'ONBOARDING' ? self::ONBOARDING : self::OFFBOARDING;
        foreach ($items as $item) Database::insert('lifecycle_checklists', ['organization_id' => $o, 'engagement_id' => (int) $b['engagement_id'], 'kind' => $kind, 'item' => $item, 'completed' => 0]);
        Http::json(['engagement_id' => (int) $b['engagement_id'], 'kind' => $kind, 'items' => count($items)]);
    }
    public static function completeLifecycle(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit');
        $c = Database::one('SELECT id FROM lifecycle_checklists WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$c) throw new HttpError('Checklist item not found', 404);
        Database::update('lifecycle_checklists', (int) $c['id'], ['completed' => 1, 'completed_date' => date('Y-m-d')]);
        Http::json(['id' => (int) $c['id'], 'completed' => true]);
    }

    public static function workflows(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.view');
        Http::json(array_map(function ($w) {
            $steps = Database::all('SELECT step_order, name, approver_role FROM approval_workflow_steps WHERE workflow_id = ? ORDER BY step_order', [$w['id']]);
            return ['id' => (int) $w['id'], 'name' => $w['name'], 'transaction_type' => $w['transaction_type'], 'active' => (int) $w['active'] === 1,
                'conditions' => json_decode($w['conditions_json'] ?: '{}', true), 'steps' => $steps];
        }, Database::all('SELECT * FROM approval_workflows WHERE organization_id = ?', [$o])));
    }
    public static function createWorkflow(array $p): void
    {
        [, $o] = Auth::org($p, 'system.admin'); $b = Http::body();
        $id = Database::insert('approval_workflows', ['organization_id' => $o, 'name' => $b['name'], 'transaction_type' => $b['transaction_type'],
            'conditions_json' => isset($b['conditions']) ? json_encode($b['conditions']) : null, 'active' => 1]);
        foreach ($b['steps'] ?? [] as $i => $s) Database::insert('approval_workflow_steps', ['workflow_id' => $id, 'step_order' => $s['step_order'] ?? ($i + 1), 'name' => $s['name'], 'approver_role' => $s['approver_role'] ?? null]);
        Http::json(['id' => $id]);
    }
    public static function approvals(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.view');
        $sql = 'SELECT * FROM approval_instances WHERE organization_id = ?'; $params = [$o];
        if ($s = Http::query('status')) { $sql .= ' AND status = ?'; $params[] = $s; }
        Http::json(array_map(function ($r) {
            $actions = Database::all('SELECT step_order, action, remarks FROM approval_actions WHERE instance_id = ?', [$r['id']]);
            return ['id' => (int) $r['id'], 'uuid' => $r['uuid'], 'transaction_type' => $r['transaction_type'], 'entity' => $r['entity'],
                'entity_id' => (int) $r['entity_id'], 'amount' => $r['amount'] !== null ? (float) $r['amount'] : null,
                'current_step' => (int) $r['current_step'], 'status' => $r['status'],
                'actions' => array_map(fn($a) => ['step' => (int) $a['step_order'], 'action' => $a['action'], 'remarks' => $a['remarks']], $actions)];
        }, Database::all($sql . ' ORDER BY id DESC LIMIT 200', $params)));
    }
    private static function matchWorkflow(int $orgId, string $type, ?float $amount): ?array
    {
        $wfs = Database::all('SELECT * FROM approval_workflows WHERE organization_id = ? AND transaction_type = ? AND active = 1', [$orgId, $type]);
        $best = null;
        foreach ($wfs as $w) { $cond = json_decode($w['conditions_json'] ?: '{}', true); $min = $cond['min_amount'] ?? null; if ($min === null || ($amount !== null && $amount >= $min)) $best = $w; }
        return $best ?: ($wfs[0] ?? null);
    }
    public static function raiseApproval(array $p): void
    {
        [, $o] = Auth::org($p, 'organization.view'); $b = Http::body();
        $amount = isset($b['amount']) ? (float) $b['amount'] : null;
        $wf = self::matchWorkflow($o, $b['transaction_type'], $amount);
        $id = Database::insert('approval_instances', ['uuid' => Util::uuid(), 'organization_id' => $o, 'workflow_id' => $wf['id'] ?? null,
            'transaction_type' => $b['transaction_type'], 'entity' => $b['entity'] ?? '', 'entity_id' => (int) ($b['entity_id'] ?? 0), 'amount' => $amount, 'current_step' => 1, 'status' => 'PENDING']);
        $total = $wf ? (int) Database::scalar('SELECT COUNT(*) FROM approval_workflow_steps WHERE workflow_id = ?', [$wf['id']]) : 1;
        Http::json(['id' => $id, 'status' => 'PENDING', 'current_step' => 1, 'total_steps' => $total]);
    }
    public static function actApproval(array $p): void
    {
        [$u, $o] = Auth::org($p, 'organization.view'); $b = Http::body();
        $inst = Database::one('SELECT * FROM approval_instances WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$inst) throw new HttpError('Approval instance not found', 404);
        if ($inst['status'] !== 'PENDING') throw new HttpError("Instance already {$inst['status']}", 409);
        $action = strtoupper($b['action'] ?? 'APPROVE');
        if (!in_array($action, ['APPROVE', 'REJECT'], true)) throw new HttpError('action must be APPROVE or REJECT', 422);
        Database::insert('approval_actions', ['instance_id' => $inst['id'], 'step_order' => (int) $inst['current_step'], 'action' => $action, 'actor_user_id' => $u['id'], 'remarks' => $b['remarks'] ?? null]);
        $total = $inst['workflow_id'] ? (int) Database::scalar('SELECT COUNT(*) FROM approval_workflow_steps WHERE workflow_id = ?', [$inst['workflow_id']]) : 1;
        $status = $inst['status']; $step = (int) $inst['current_step'];
        if ($action === 'REJECT') $status = 'REJECTED';
        elseif ($step >= $total) $status = 'APPROVED';
        else $step++;
        Database::update('approval_instances', (int) $inst['id'], ['status' => $status, 'current_step' => $step]);
        Http::json(['id' => (int) $inst['id'], 'status' => $status, 'current_step' => $step, 'total_steps' => $total]);
    }
}
