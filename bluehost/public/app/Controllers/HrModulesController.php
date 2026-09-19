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
        $r->get("$b/performance/templates", [self::class, 'perfTemplates']);
        $r->post("$b/performance/templates", [self::class, 'createPerfTemplate']);
        $r->put("$b/performance/templates/{id}", [self::class, 'updatePerfTemplate']);
        $r->delete("$b/performance/templates/{id}", [self::class, 'deletePerfTemplate']);
        $r->get("$b/performance/cycles", [self::class, 'perfCycles']);
        $r->post("$b/performance/cycles", [self::class, 'createCycle']);
        $r->get("$b/performance/reviews", [self::class, 'perfReviews']);
        $r->post("$b/performance/reviews", [self::class, 'createReview']);
        $r->put("$b/performance/reviews/{id}", [self::class, 'updateReview']);
        $r->post("$b/performance/reviews/{id}/submit", [self::class, 'submitReview']);
        // Training
        $r->get("$b/training/courses", [self::class, 'courses']);
        $r->post("$b/training/courses", [self::class, 'createCourse']);
        $r->get("$b/training/assignments", [self::class, 'assignments']);
        $r->post("$b/training/assignments", [self::class, 'assign']);
        $r->post("$b/training/assign-bulk", [self::class, 'assignBulk']);
        $r->post("$b/training/assignments/{id}/complete", [self::class, 'completeTraining']);
        $r->post("$b/training/assignments/{id}/reopen", [self::class, 'reopenTraining']);
        $r->post("$b/training/assignments/{id}/verify", [self::class, 'verifyTraining']);
        $r->delete("$b/training/assignments/{id}", [self::class, 'deleteAssignment']);
        $r->get("$b/training/assignments/{id}/certificate", [self::class, 'assignmentCertificate']);
        // Service desk
        $r->get("$b/service-tickets", [self::class, 'tickets']);
        $r->post("$b/service-tickets", [self::class, 'createTicket']);
        $r->get("$b/service-tickets/{id}", [self::class, 'ticket']);
        $r->post("$b/service-tickets/{id}/comments", [self::class, 'ticketComment']);
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

    private static function templateOut(array $t): array
    {
        $sections = Database::all('SELECT * FROM performance_template_sections WHERE template_id=? ORDER BY sort_order,id', [$t['id']]);
        foreach ($sections as &$s) {
            $s['id']=(int)$s['id']; $s['sort_order']=(int)$s['sort_order'];
            $s['items']=array_map(fn($i)=>['id'=>(int)$i['id'],'title'=>$i['title'],'description'=>$i['description'],
                'weight'=>(float)$i['weight'],'sort_order'=>(int)$i['sort_order'],'employee_rates'=>(bool)$i['employee_rates'],
                'supervisor_rates'=>(bool)$i['supervisor_rates']], Database::all('SELECT * FROM performance_template_items WHERE section_id=? ORDER BY sort_order,id',[$s['id']]));
        }
        return ['id'=>(int)$t['id'],'name'=>$t['name'],'description'=>$t['description'],'rating_min'=>(float)$t['rating_min'],
            'rating_max'=>(float)$t['rating_max'],'active'=>(bool)$t['active'],'sections'=>$sections];
    }
    public static function perfTemplates(array $p): void
    {
        [, $o]=Auth::org($p,'performance.view');
        Http::json(array_map([self::class,'templateOut'],Database::all('SELECT * FROM performance_templates WHERE organization_id=? ORDER BY active DESC,name',[$o])));
    }
    private static function saveTemplate(int $o, array $b, ?int $id=null): int
    {
        $name=trim((string)($b['name']??'')); $min=(float)($b['rating_min']??1); $max=(float)($b['rating_max']??5);
        if($name==='') throw new HttpError('Template name is required',422);
        if($min<0||$max<=$min) throw new HttpError('Rating maximum must be greater than the minimum',422);
        $sections=$b['sections']??[]; $total=0; $count=0;
        foreach($sections as $s) foreach($s['items']??[] as $i){if(isset($i['employee_rates'])&&!$i['employee_rates']&&isset($i['supervisor_rates'])&&!$i['supervisor_rates'])throw new HttpError('Every criterion must be rated by the employee, supervisor, or both',422);$total+=(float)($i['weight']??0);$count++;}
        if($count===0) throw new HttpError('Add at least one review criterion',422);
        if(abs($total-100)>0.01) throw new HttpError('Criteria weights must total exactly 100%',422);
        $pdo=Database::pdo(); $pdo->beginTransaction();
        try {
            $data=['organization_id'=>$o,'name'=>$name,'description'=>$b['description']??null,'rating_min'=>$min,'rating_max'=>$max,'active'=>!isset($b['active'])||$b['active']?1:0,'updated_at'=>date('Y-m-d H:i:s')];
            if($id){Database::update('performance_templates',$id,$data);$old=Database::all('SELECT id FROM performance_template_sections WHERE template_id=?',[$id]);foreach($old as $s)Database::exec('DELETE FROM performance_template_items WHERE section_id=?',[$s['id']]);Database::exec('DELETE FROM performance_template_sections WHERE template_id=?',[$id]);}
            else $id=Database::insert('performance_templates',$data);
            foreach($sections as $si=>$s){$title=trim((string)($s['title']??''));if($title==='')throw new HttpError('Every section needs a title',422);$sid=Database::insert('performance_template_sections',['template_id'=>$id,'title'=>$title,'description'=>$s['description']??null,'sort_order'=>$si+1]);foreach($s['items']??[] as $ii=>$i){$it=trim((string)($i['title']??''));if($it==='')throw new HttpError('Every criterion needs a title',422);Database::insert('performance_template_items',['section_id'=>$sid,'title'=>$it,'description'=>$i['description']??null,'weight'=>(float)$i['weight'],'sort_order'=>$ii+1,'employee_rates'=>!isset($i['employee_rates'])||$i['employee_rates']?1:0,'supervisor_rates'=>!isset($i['supervisor_rates'])||$i['supervisor_rates']?1:0]);}}
            $pdo->commit(); return $id;
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public static function createPerfTemplate(array $p): void { [$u,$o]=Auth::org($p,'performance.manage');$id=self::saveTemplate($o,Http::body());Audit::record('performance.template_create',$u,['organization_id'=>$o,'entity'=>'performance_template','entity_id'=>$id]);Http::json(self::templateOut(Database::one('SELECT * FROM performance_templates WHERE id=?',[$id]))); }
    public static function updatePerfTemplate(array $p): void { [$u,$o]=Auth::org($p,'performance.manage');$t=Database::one('SELECT * FROM performance_templates WHERE id=? AND organization_id=?',[(int)$p['id'],$o]);if(!$t)throw new HttpError('Template not found',404);$id=self::saveTemplate($o,Http::body(),(int)$t['id']);Audit::record('performance.template_update',$u,['organization_id'=>$o,'entity'=>'performance_template','entity_id'=>$id]);Http::json(self::templateOut(Database::one('SELECT * FROM performance_templates WHERE id=?',[$id]))); }
    public static function deletePerfTemplate(array $p): void { [$u,$o]=Auth::org($p,'performance.manage');$t=Database::one('SELECT * FROM performance_templates WHERE id=? AND organization_id=?',[(int)$p['id'],$o]);if(!$t)throw new HttpError('Template not found',404);Database::update('performance_templates',(int)$t['id'],['active'=>0,'updated_at'=>date('Y-m-d H:i:s')]);Audit::record('performance.template_archive',$u,['organization_id'=>$o,'entity'=>'performance_template','entity_id'=>(int)$t['id']]);Http::json(['archived'=>(int)$t['id']]); }
    public static function reviewItemOut(array $i): array { return ['id'=>(int)$i['id'],'section_title'=>$i['section_title'],'title'=>$i['item_title'],'description'=>$i['item_description'],'weight'=>(float)$i['weight'],'employee_rates'=>(bool)$i['employee_rates'],'supervisor_rates'=>(bool)$i['supervisor_rates'],'self_score'=>$i['self_score']!==null?(float)$i['self_score']:null,'supervisor_score'=>$i['supervisor_score']!==null?(float)$i['supervisor_score']:null,'employee_comment'=>$i['employee_comment'],'supervisor_comment'=>$i['supervisor_comment']]; }
    public static function weightedScore(int $reviewId,string $scoreCol,string $flagCol): ?float { $rows=Database::all("SELECT weight,$scoreCol score FROM performance_review_items WHERE review_id=? AND $flagCol=1",[$reviewId]);if(!$rows)return null;$sum=0;$weights=0;foreach($rows as $x){if($x['score']===null)return null;$w=(float)$x['weight'];$sum+=(float)$x['score']*$w;$weights+=$w;}return $weights>0?round($sum/$weights,2):null; }

    public static function perfCycles(array $p): void
    {
        [, $o] = Auth::org($p, 'performance.view');
        Http::json(Database::all('SELECT pc.id,pc.template_id,pc.name,pc.cycle_type,pc.period_start,pc.period_end,pc.status,pt.name template_name FROM performance_cycles pc LEFT JOIN performance_templates pt ON pt.id=pc.template_id WHERE pc.organization_id=? ORDER BY pc.id DESC', [$o]));
    }
    public static function createCycle(array $p): void
    {
        [$u, $o] = Auth::org($p, 'performance.manage'); $b = Http::body();
        if (trim((string) ($b['name'] ?? '')) === '') throw new HttpError('Cycle name is required', 422);
        $templateId=(int)($b['template_id']??0); if(!$templateId||!Database::one('SELECT id FROM performance_templates WHERE id=? AND organization_id=? AND active=1',[$templateId,$o])) throw new HttpError('Choose an active review template',422);
        $id = Database::insert('performance_cycles', ['organization_id' => $o, 'template_id'=>$templateId, 'name' => trim($b['name']),
            'cycle_type' => $b['cycle_type'] ?? 'ANNUAL', 'period_start' => $b['period_start'] ?? null,
            'period_end' => $b['period_end'] ?? null, 'status' => 'OPEN']);
        Audit::record('performance.cycle_create', $u, ['organization_id' => $o, 'entity' => 'performance_cycle', 'entity_id' => $id]);
        Http::json(['id' => $id]);
    }
    public static function perfReviews(array $p): void
    {
        [, $o] = Auth::org($p, 'performance.view');
        $sql = "SELECT pr.*, pc.name cycle_name, e.employee_number,
                       CONCAT_WS(' ', pe.first_name, pe.last_name) employee,
                       ai.id approval_id, ai.current_step approval_step, ai.status approval_status
                  FROM performance_reviews pr
                  JOIN performance_cycles pc ON pc.id=pr.cycle_id
                  JOIN engagements e ON e.id=pr.engagement_id
                  JOIN people pe ON pe.id=e.person_id
                  LEFT JOIN approval_instances ai ON ai.entity='performance_review' AND ai.entity_id=pr.id
                 WHERE pr.organization_id = ?";
        $params = [$o];
        if ($c = Http::query('cycle_id')) { $sql .= ' AND cycle_id = ?'; $params[] = (int) $c; }
        Http::json(array_map(function($r){$items=Database::all('SELECT * FROM performance_review_items WHERE review_id=? ORDER BY sort_order,id',[$r['id']]);return ['id' => (int) $r['id'], 'engagement_id' => (int) $r['engagement_id'], 'cycle_id' => (int) $r['cycle_id'],
            'employee' => $r['employee'], 'employee_number' => $r['employee_number'], 'cycle_name' => $r['cycle_name'],
            'self_score' => $r['self_score'] !== null ? (float) $r['self_score'] : null, 'supervisor_score' => $r['supervisor_score'] !== null ? (float) $r['supervisor_score'] : null,
            'final_rating' => $r['final_rating'] !== null ? (float) $r['final_rating'] : null, 'status' => $r['status'],
            'rating_min'=>(float)$r['rating_min'],'rating_max'=>(float)$r['rating_max'],
            'employee_comments' => $r['employee_comments'], 'supervisor_comments' => $r['supervisor_comments'], 'hr_comments' => $r['hr_comments'],
            'items'=>array_map([self::class,'reviewItemOut'],$items),'approval_id' => $r['approval_id'] !== null ? (int) $r['approval_id'] : null,
            'approval_step' => $r['approval_step'] !== null ? (int) $r['approval_step'] : null, 'approval_status' => $r['approval_status']];}, Database::all($sql . ' ORDER BY pr.id DESC', $params)));
    }
    public static function createReview(array $p): void
    {
        [$u, $o] = Auth::org($p, 'performance.manage'); $b = Http::body();
        $eng = Database::one('SELECT id FROM engagements WHERE id=? AND organization_id=?', [(int) ($b['engagement_id'] ?? 0), $o]);
        $cycle = Database::one("SELECT pc.id,pc.template_id,pt.rating_min,pt.rating_max FROM performance_cycles pc JOIN performance_templates pt ON pt.id=pc.template_id WHERE pc.id=? AND pc.organization_id=? AND pc.status='OPEN'", [(int) ($b['cycle_id'] ?? 0), $o]);
        if (!$eng || !$cycle) throw new HttpError('Choose a valid employee and open review cycle', 422);
        if (Database::one('SELECT id FROM performance_reviews WHERE cycle_id=? AND engagement_id=?', [(int) $b['cycle_id'], (int) $b['engagement_id']]))
            throw new HttpError('This employee already has a review in that cycle', 409);
        $items=Database::all('SELECT i.*,s.title section_title,s.sort_order section_order FROM performance_template_items i JOIN performance_template_sections s ON s.id=i.section_id WHERE s.template_id=? ORDER BY s.sort_order,i.sort_order,i.id',[$cycle['template_id']]);
        if(!$items) throw new HttpError('The selected cycle template has no criteria',422);
        $self = $b['self_score'] ?? null; $sup = $b['supervisor_score'] ?? null;
        $final = ($self !== null && $sup !== null) ? round(((float) $self + (float) $sup) / 2, 2) : null;
        $id = Database::insert('performance_reviews', ['organization_id' => $o, 'cycle_id' => (int) $b['cycle_id'], 'engagement_id' => (int) $b['engagement_id'], 'rating_min'=>$cycle['rating_min'],'rating_max'=>$cycle['rating_max'],
            'self_score' => $self, 'supervisor_score' => $sup, 'final_rating' => $final,
            'supervisor_comments' => $b['supervisor_comments'] ?? null, 'status' => 'DRAFT']);
        foreach($items as $n=>$i) Database::insert('performance_review_items',['review_id'=>$id,'template_item_id'=>$i['id'],'section_title'=>$i['section_title'],'item_title'=>$i['title'],'item_description'=>$i['description'],'weight'=>$i['weight'],'sort_order'=>$n+1,'employee_rates'=>$i['employee_rates'],'supervisor_rates'=>$i['supervisor_rates']]);
        Audit::record('performance.review_create', $u, ['organization_id' => $o, 'entity' => 'performance_review', 'entity_id' => $id]);
        $person = Database::scalar('SELECT person_id FROM engagements WHERE id=?', [(int) $b['engagement_id']]);
        if ($person) Notify::toPerson((int) $person, 'performance.created', 'Performance review started', 'A performance review is ready for your self-assessment.', '/me');
        Http::json(['id' => $id, 'final_rating' => $final]);
    }

    public static function updateReview(array $p): void
    {
        [$u, $o] = Auth::org($p, 'performance.manage'); $b = Http::body();
        $r = Database::one('SELECT * FROM performance_reviews WHERE id=? AND organization_id=?', [(int) $p['id'], $o]);
        if (!$r) throw new HttpError('Review not found', 404);
        if (in_array($r['status'], ['PENDING_APPROVAL','APPROVED','ACKNOWLEDGED'], true)) throw new HttpError('Reopen or reject the approval before editing this review', 409);
        $data = [];
        if(isset($b['ratings'])&&is_array($b['ratings'])) foreach($b['ratings'] as $x){$item=Database::one('SELECT * FROM performance_review_items WHERE id=? AND review_id=?',[(int)($x['id']??0),$r['id']]);if(!$item||!(int)$item['supervisor_rates'])continue;$score=(float)($x['score']??0);if($score<(float)$r['rating_min']||$score>(float)$r['rating_max'])throw new HttpError("Every supervisor rating must be between {$r['rating_min']} and {$r['rating_max']}",422);Database::update('performance_review_items',(int)$item['id'],['supervisor_score'=>$score,'supervisor_comment'=>trim((string)($x['comment']??''))]);}
        foreach (['supervisor_score','supervisor_comments','hr_comments'] as $f) if (array_key_exists($f, $b)) $data[$f] = $b[$f] === '' ? null : $b[$f];
        $self = self::weightedScore((int)$r['id'],'self_score','employee_rates') ?? (array_key_exists('self_score', $b) ? $b['self_score'] : $r['self_score']);
        $sup = self::weightedScore((int)$r['id'],'supervisor_score','supervisor_rates') ?? (array_key_exists('supervisor_score', $b) ? $b['supervisor_score'] : $r['supervisor_score']);
        if($self!==null)$data['self_score']=$self;if($sup!==null)$data['supervisor_score']=$sup;
        if ($self !== null && $sup !== null) $data['final_rating'] = round(((float) $self + (float) $sup) / 2, 2);
        Database::update('performance_reviews', (int) $r['id'], $data);
        Audit::record('performance.review_update', $u, ['organization_id' => $o, 'entity' => 'performance_review', 'entity_id' => (int) $r['id'], 'after' => $data]);
        Http::json(['id' => (int) $r['id'], 'updated' => true]);
    }

    public static function submitReview(array $p): void
    {
        [$u, $o] = Auth::org($p, 'performance.manage');
        $r = Database::one('SELECT * FROM performance_reviews WHERE id=? AND organization_id=?', [(int) $p['id'], $o]);
        if (!$r) throw new HttpError('Review not found', 404);
        $missing=(int)Database::scalar('SELECT COUNT(*) FROM performance_review_items WHERE review_id=? AND ((employee_rates=1 AND self_score IS NULL) OR (supervisor_rates=1 AND supervisor_score IS NULL))',[$r['id']]);
        if ($missing>0 || $r['self_score'] === null || $r['supervisor_score'] === null) throw new HttpError('Complete every required employee and supervisor criterion before submission', 422);
        $existing = Database::one("SELECT id,status FROM approval_instances WHERE entity='performance_review' AND entity_id=? AND status='PENDING'", [$r['id']]);
        if ($existing) throw new HttpError('This review is already awaiting approval', 409);
        $wf = self::matchWorkflow($o, 'PERFORMANCE_REVIEW', null);
        $id = Database::insert('approval_instances', ['uuid' => Util::uuid(), 'organization_id' => $o, 'workflow_id' => $wf['id'] ?? null,
            'transaction_type' => 'PERFORMANCE_REVIEW', 'entity' => 'performance_review', 'entity_id' => (int) $r['id'],
            'current_step' => 1, 'status' => 'PENDING', 'requested_by_user_id' => $u['id']]);
        Database::update('performance_reviews', (int) $r['id'], ['status' => 'PENDING_APPROVAL', 'submitted_at' => date('Y-m-d H:i:s')]);
        Notify::toApprovers($o, 'approval.act', 'approval.performance', 'Performance review awaiting approval', 'A completed performance review is ready for approval.', '/o/' . $o . '/hr?tab=approvals');
        Audit::record('performance.review_submit', $u, ['organization_id' => $o, 'entity' => 'performance_review', 'entity_id' => (int) $r['id']]);
        Http::json(['id' => (int) $r['id'], 'approval_id' => $id, 'status' => 'PENDING_APPROVAL']);
    }

    public static function courses(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        Http::json(Database::all('SELECT id, title, category, provider, points FROM training_courses WHERE organization_id = ?', [$o]));
    }
    public static function createCourse(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        Http::json(['id' => Database::insert('training_courses', ['organization_id' => $o, 'title' => $b['title'],
            'category' => $b['category'] ?? null, 'provider' => $b['provider'] ?? null, 'points' => (float) ($b['points'] ?? 0)])]);
    }
    /** Certificate storage dir (deny-all; served only through PHP). */
    public static function trainingDir(): string { return dirname(dirname(__DIR__)) . '/storage/training'; }

    public static function assignments(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        $rows = Database::all(
            "SELECT ta.*, COALESCE(tc.title, ta.self_title) course_title, COALESCE(tc.provider, ta.self_provider) provider, tc.category,
                    CONCAT_WS(' ', pe.first_name, pe.last_name) employee, e.employee_number
               FROM training_assignments ta
               LEFT JOIN training_courses tc ON tc.id = ta.course_id
               JOIN engagements e ON e.id = ta.engagement_id
               JOIN people pe ON pe.id = e.person_id
              WHERE ta.organization_id = ? ORDER BY (ta.status = 'COMPLETED'), ta.due_date IS NULL, ta.due_date, ta.id DESC", [$o]);
        Http::json(array_map(fn($t) => [
            'id' => (int) $t['id'], 'course_id' => $t['course_id'] !== null ? (int) $t['course_id'] : null,
            'engagement_id' => (int) $t['engagement_id'], 'source' => $t['source'] ?? 'ASSIGNED',
            'course_title' => $t['course_title'], 'provider' => $t['provider'], 'category' => $t['category'],
            'points' => (float) ($t['points'] ?? 0), 'verified' => (int) ($t['verified'] ?? 1) === 1,
            'employee' => $t['employee'], 'employee_number' => $t['employee_number'],
            'status' => $t['status'], 'due_date' => $t['due_date'], 'completed_date' => $t['completed_date'],
            'completion_note' => $t['completion_note'],
            'has_certificate' => !empty($t['certificate_object_key']), 'certificate_filename' => $t['certificate_filename'],
        ], $rows));
    }
    public static function assign(array $p): void
    {
        [$u, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $courseId = (int) $b['course_id']; $engId = (int) $b['engagement_id'];
        $due = trim((string) ($b['due_date'] ?? '')) ?: null;
        $course = Database::one('SELECT title, points FROM training_courses WHERE id = ? AND organization_id = ?', [$courseId, $o]);
        $points = $course ? (float) $course['points'] : 0;
        $id = Database::insert('training_assignments', ['organization_id' => $o, 'course_id' => $courseId, 'engagement_id' => $engId,
            'source' => 'ASSIGNED', 'points' => $points, 'status' => 'ASSIGNED', 'due_date' => $due]);
        // Notify the assigned employee (in-app / email / Telegram).
        $person = Database::scalar('SELECT person_id FROM engagements WHERE id = ?', [$engId]);
        if ($person) {
            $title = $course['title'] ?? 'a course';
            $by = $due ? " Please complete it by $due." : '';
            Notify::toPerson((int) $person, 'training.assigned', 'New training assigned',
                "You've been assigned the training \"$title\".$by", '/me');
        }
        Audit::record('training.assign', $u, ['organization_id' => $o, 'entity' => 'training_assignment', 'entity_id' => $id, 'after' => ['course_id' => $courseId, 'engagement_id' => $engId, 'due_date' => $due]]);
        Http::json(['id' => $id]);
    }
    /** Assign one or more courses to one or more employees in a single action. */
    public static function assignBulk(array $p): void
    {
        [$u, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $engIds = array_values(array_unique(array_map('intval', (array) ($b['engagement_ids'] ?? []))));
        $courseIds = array_values(array_unique(array_map('intval', (array) ($b['course_ids'] ?? []))));
        $due = trim((string) ($b['due_date'] ?? '')) ?: null;
        if (!$engIds || !$courseIds) throw new HttpError('Select at least one employee and one course', 422);

        $created = 0; $perEng = [];
        foreach ($courseIds as $cid) {
            $course = Database::one('SELECT points FROM training_courses WHERE id = ? AND organization_id = ?', [$cid, $o]);
            if (!$course) continue;
            foreach ($engIds as $eid) {
                if (!Database::one('SELECT id FROM engagements WHERE id = ? AND organization_id = ?', [$eid, $o])) continue;
                // Skip if the same course is already open (not completed) for this employee.
                if (Database::one("SELECT id FROM training_assignments WHERE organization_id = ? AND engagement_id = ? AND course_id = ? AND status <> 'COMPLETED'", [$o, $eid, $cid])) continue;
                Database::insert('training_assignments', ['organization_id' => $o, 'course_id' => $cid, 'engagement_id' => $eid,
                    'source' => 'ASSIGNED', 'points' => (float) $course['points'], 'status' => 'ASSIGNED', 'due_date' => $due]);
                $created++; $perEng[$eid] = ($perEng[$eid] ?? 0) + 1;
            }
        }
        foreach ($perEng as $eid => $n) {
            $person = Database::scalar('SELECT person_id FROM engagements WHERE id = ?', [$eid]);
            if ($person) {
                $by = $due ? " Please complete by $due." : '';
                Notify::toPerson((int) $person, 'training.assigned', 'New training assigned',
                    "You've been assigned $n new training" . ($n === 1 ? '' : 's') . ".$by", '/me');
            }
        }
        Audit::record('training.assign_bulk', $u, ['organization_id' => $o, 'entity' => 'training_assignment',
            'after' => ['created' => $created, 'employees' => count($perEng), 'courses' => count($courseIds)]]);
        Http::json(['created' => $created]);
    }
    /** Approve a self-added training so its points count toward the employee's total. */
    public static function verifyTraining(array $p): void
    {
        [$u, $o] = Auth::org($p, 'employee.edit');
        $t = Database::one('SELECT id, engagement_id FROM training_assignments WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$t) throw new HttpError('Assignment not found', 404);
        Database::update('training_assignments', (int) $t['id'], ['verified' => 1]);
        $person = Database::scalar('SELECT person_id FROM engagements WHERE id = ?', [(int) $t['engagement_id']]);
        if ($person) Notify::toPerson((int) $person, 'training.verified', 'Training approved', 'Your self-added training was approved — the points now count.', '/me');
        Audit::record('training.verify', $u, ['organization_id' => $o, 'entity' => 'training_assignment', 'entity_id' => (int) $t['id']]);
        Http::json(['id' => (int) $t['id'], 'verified' => true]);
    }
    public static function deleteAssignment(array $p): void
    {
        [$u, $o] = Auth::org($p, 'employee.edit');
        $t = Database::one('SELECT * FROM training_assignments WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$t) throw new HttpError('Assignment not found', 404);
        if (!empty($t['certificate_object_key'])) { $f = self::trainingDir() . '/' . $t['certificate_object_key']; if (is_file($f)) @unlink($f); }
        Database::exec('DELETE FROM training_assignments WHERE id = ?', [(int) $t['id']]);
        Audit::record('training.delete', $u, ['organization_id' => $o, 'entity' => 'training_assignment', 'entity_id' => (int) $t['id']]);
        Http::json(['ok' => true]);
    }
    public static function completeTraining(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit'); $b = Http::body();
        $t = Database::one('SELECT id FROM training_assignments WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$t) throw new HttpError('Assignment not found', 404);
        $data = ['status' => 'COMPLETED', 'completed_date' => date('Y-m-d')];
        if (isset($b['completion_note'])) $data['completion_note'] = substr((string) $b['completion_note'], 0, 255);
        Database::update('training_assignments', (int) $t['id'], $data);
        Http::json(['id' => (int) $t['id'], 'status' => 'COMPLETED']);
    }
    public static function reopenTraining(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.edit');
        $t = Database::one('SELECT id FROM training_assignments WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$t) throw new HttpError('Assignment not found', 404);
        Database::update('training_assignments', (int) $t['id'], ['status' => 'ASSIGNED', 'completed_date' => null]);
        Http::json(['id' => (int) $t['id'], 'status' => 'ASSIGNED']);
    }
    public static function assignmentCertificate(array $p): void
    {
        [, $o] = Auth::org($p, 'employee.view');
        $t = Database::one('SELECT certificate_object_key, certificate_filename FROM training_assignments WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$t || empty($t['certificate_object_key'])) throw new HttpError('No certificate on file', 404);
        $path = self::trainingDir() . '/' . $t['certificate_object_key'];
        if (!is_file($path)) throw new HttpError('Certificate file is missing', 404);
        $ext = strtolower(pathinfo($t['certificate_filename'], PATHINFO_EXTENSION));
        $inline = in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif'], true);
        Http::file(file_get_contents($path), 'application/octet-stream', $t['certificate_filename'] ?: 'certificate', $inline);
    }

    public static function tickets(array $p): void
    {
        [, $o] = Auth::org($p, 'service_desk.view');
        $sql = "SELECT st.id, st.ticket_number, st.category, st.priority, st.status, st.subject, st.assigned_hr,
                       st.engagement_id, st.created_at, st.updated_at, st.resolved_at,
                       CONCAT_WS(' ', pe.first_name, pe.last_name) employee,
                       au.full_name assigned_to
                  FROM service_tickets st
                  LEFT JOIN engagements e ON e.id=st.engagement_id
                  LEFT JOIN people pe ON pe.id=e.person_id
                  LEFT JOIN users au ON au.id=st.assigned_user_id
                 WHERE st.organization_id = ?";
        $params = [$o];
        if ($s = Http::query('status')) { $sql .= ' AND status = ?'; $params[] = $s; }
        Http::json(Database::all($sql . ' ORDER BY st.id DESC', $params));
    }
    public static function createTicket(array $p): void
    {
        [$u, $o] = Auth::org($p, 'service_desk.view'); $b = Http::body();
        if (trim((string) ($b['subject'] ?? '')) === '') throw new HttpError('Subject is required', 422);
        if (!empty($b['engagement_id']) && !Database::one('SELECT id FROM engagements WHERE id=? AND organization_id=?', [(int) $b['engagement_id'], $o])) throw new HttpError('Employee not found', 404);
        $n = (int) Database::scalar('SELECT COUNT(*) FROM service_tickets WHERE organization_id = ?', [$o]);
        $num = 'TKT-' . $o . '-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
        $id = Database::insert('service_tickets', ['uuid' => Util::uuid(), 'organization_id' => $o, 'ticket_number' => $num,
            'engagement_id' => $b['engagement_id'] ?? null, 'category' => $b['category'] ?? 'HR question', 'priority' => $b['priority'] ?? 'NORMAL',
            'subject' => trim($b['subject']), 'description' => $b['description'] ?? null, 'status' => 'OPEN',
            'created_by_user_id' => $u['id'], 'updated_at' => date('Y-m-d H:i:s')]);
        Audit::record('service_desk.create', $u, ['organization_id' => $o, 'entity' => 'service_ticket', 'entity_id' => $id]);
        Notify::toApprovers($o, 'service_desk.manage', 'service_desk.new', "New service ticket $num", trim($b['subject']), '/o/' . $o . '/hr?tab=service');
        Http::json(['id' => $id, 'ticket_number' => $num]);
    }
    public static function ticket(array $p): void
    {
        [, $o] = Auth::org($p, 'service_desk.view');
        $t = Database::one("SELECT st.*, CONCAT_WS(' ',pe.first_name,pe.last_name) employee, au.full_name assigned_to
              FROM service_tickets st LEFT JOIN engagements e ON e.id=st.engagement_id LEFT JOIN people pe ON pe.id=e.person_id
              LEFT JOIN users au ON au.id=st.assigned_user_id WHERE st.id=? AND st.organization_id=?", [(int) $p['id'], $o]);
        if (!$t) throw new HttpError('Ticket not found', 404);
        $t['comments'] = Database::all("SELECT c.id,c.body,c.is_internal,c.created_at,u.full_name author
              FROM service_ticket_comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.ticket_id=? ORDER BY c.id", [$t['id']]);
        Http::json($t);
    }
    public static function ticketComment(array $p): void
    {
        [$u, $o] = Auth::org($p, 'service_desk.view'); $b = Http::body();
        $t = Database::one('SELECT * FROM service_tickets WHERE id=? AND organization_id=?', [(int) $p['id'], $o]);
        if (!$t) throw new HttpError('Ticket not found', 404);
        $body = trim((string) ($b['body'] ?? '')); if ($body === '') throw new HttpError('Comment is required', 422);
        $internal = !empty($b['is_internal']) && Auth::has($u, 'service_desk.manage');
        $id = Database::insert('service_ticket_comments', ['ticket_id' => $t['id'], 'user_id' => $u['id'], 'body' => $body, 'is_internal' => $internal ? 1 : 0]);
        Database::update('service_tickets', (int) $t['id'], ['updated_at' => date('Y-m-d H:i:s')]);
        if (!$internal && !empty($t['engagement_id'])) { $person=Database::scalar('SELECT person_id FROM engagements WHERE id=?', [$t['engagement_id']]); if ($person) Notify::toPerson((int)$person, 'service_desk.reply', 'Service ticket updated', "Ticket {$t['ticket_number']} has a new reply.", '/me'); }
        Audit::record('service_desk.comment', $u, ['organization_id' => $o, 'entity' => 'service_ticket', 'entity_id' => (int) $t['id']]);
        Http::json(['id' => $id]);
    }
    public static function ticketStatus(array $p): void
    {
        [$u, $o] = Auth::org($p, 'service_desk.manage'); $b = Http::body();
        $t = Database::one('SELECT * FROM service_tickets WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$t) throw new HttpError('Ticket not found', 404);
        $status = strtoupper($b['status'] ?? 'IN_PROGRESS');
        if (!in_array($status, ['OPEN','IN_PROGRESS','WAITING_EMPLOYEE','RESOLVED','CLOSED'], true)) throw new HttpError('Invalid ticket status', 422);
        $data = ['status' => $status, 'assigned_hr' => $b['assigned_hr'] ?? null,
            'assigned_user_id' => !empty($b['assigned_user_id']) ? (int) $b['assigned_user_id'] : null,
            'resolution' => $b['resolution'] ?? $t['resolution'], 'updated_at' => date('Y-m-d H:i:s')];
        if ($status === 'RESOLVED') $data['resolved_at'] = date('Y-m-d H:i:s');
        if ($status === 'CLOSED') $data['closed_at'] = date('Y-m-d H:i:s');
        Database::update('service_tickets', (int) $t['id'], $data);
        if (!empty($t['engagement_id'])) { $person=Database::scalar('SELECT person_id FROM engagements WHERE id=?', [$t['engagement_id']]); if ($person) Notify::toPerson((int)$person, 'service_desk.status', "Ticket {$t['ticket_number']} — $status", $data['resolution'] ?: 'Your service ticket status changed.', '/me'); }
        Audit::record('service_desk.status', $u, ['organization_id' => $o, 'entity' => 'service_ticket', 'entity_id' => (int) $t['id'], 'after' => $data]);
        Http::json(['id' => (int) $t['id'], 'status' => $status]);
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
        [, $o] = Auth::org($p, 'approval.view');
        Http::json(array_map(function ($w) {
            $steps = Database::all('SELECT step_order, name, approver_role FROM approval_workflow_steps WHERE workflow_id = ? ORDER BY step_order', [$w['id']]);
            return ['id' => (int) $w['id'], 'name' => $w['name'], 'transaction_type' => $w['transaction_type'], 'active' => (int) $w['active'] === 1,
                'conditions' => json_decode($w['conditions_json'] ?: '{}', true), 'steps' => $steps];
        }, Database::all('SELECT * FROM approval_workflows WHERE organization_id = ?', [$o])));
    }
    public static function createWorkflow(array $p): void
    {
        [$u, $o] = Auth::org($p, 'approval.manage'); $b = Http::body();
        if (trim((string) ($b['name'] ?? '')) === '' || trim((string) ($b['transaction_type'] ?? '')) === '') throw new HttpError('Workflow name and transaction type are required', 422);
        $id = Database::insert('approval_workflows', ['organization_id' => $o, 'name' => $b['name'], 'transaction_type' => $b['transaction_type'],
            'conditions_json' => isset($b['conditions']) ? json_encode($b['conditions']) : null, 'active' => 1]);
        foreach ($b['steps'] ?? [] as $i => $s) Database::insert('approval_workflow_steps', ['workflow_id' => $id, 'step_order' => $s['step_order'] ?? ($i + 1), 'name' => $s['name'], 'approver_role' => $s['approver_role'] ?? null]);
        Audit::record('approval.workflow_create', $u, ['organization_id' => $o, 'entity' => 'approval_workflow', 'entity_id' => $id]);
        Http::json(['id' => $id]);
    }
    public static function approvals(array $p): void
    {
        [, $o] = Auth::org($p, 'approval.view');
        $sql = "SELECT ai.*, w.name workflow_name,
                  (SELECT approver_role FROM approval_workflow_steps s WHERE s.workflow_id=ai.workflow_id AND s.step_order=ai.current_step LIMIT 1) required_role,
                  CASE WHEN ai.entity='performance_review' THEN (SELECT CONCAT_WS(' ',pe.first_name,pe.last_name) FROM performance_reviews pr JOIN engagements e ON e.id=pr.engagement_id JOIN people pe ON pe.id=e.person_id WHERE pr.id=ai.entity_id) ELSE NULL END entity_label
                FROM approval_instances ai LEFT JOIN approval_workflows w ON w.id=ai.workflow_id WHERE ai.organization_id = ?"; $params = [$o];
        if ($s = Http::query('status')) { $sql .= ' AND status = ?'; $params[] = $s; }
        Http::json(array_map(function ($r) {
            $actions = Database::all('SELECT step_order, action, remarks FROM approval_actions WHERE instance_id = ?', [$r['id']]);
            return ['id' => (int) $r['id'], 'uuid' => $r['uuid'], 'transaction_type' => $r['transaction_type'], 'entity' => $r['entity'],
                'entity_id' => (int) $r['entity_id'], 'amount' => $r['amount'] !== null ? (float) $r['amount'] : null,
                'workflow_name' => $r['workflow_name'], 'required_role' => $r['required_role'], 'entity_label' => $r['entity_label'],
                'current_step' => (int) $r['current_step'], 'status' => $r['status'], 'created_at' => $r['created_at'],
                'actions' => array_map(fn($a) => ['step' => (int) $a['step_order'], 'action' => $a['action'], 'remarks' => $a['remarks']], $actions)];
        }, Database::all($sql . ' ORDER BY ai.id DESC LIMIT 200', $params)));
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
        [$u, $o] = Auth::org($p, 'approval.manage'); $b = Http::body();
        $amount = isset($b['amount']) ? (float) $b['amount'] : null;
        $wf = self::matchWorkflow($o, $b['transaction_type'], $amount);
        $id = Database::insert('approval_instances', ['uuid' => Util::uuid(), 'organization_id' => $o, 'workflow_id' => $wf['id'] ?? null,
            'transaction_type' => $b['transaction_type'], 'entity' => $b['entity'] ?? '', 'entity_id' => (int) ($b['entity_id'] ?? 0), 'amount' => $amount, 'current_step' => 1, 'status' => 'PENDING', 'requested_by_user_id' => $u['id']]);
        $total = $wf ? (int) Database::scalar('SELECT COUNT(*) FROM approval_workflow_steps WHERE workflow_id = ?', [$wf['id']]) : 1;
        Audit::record('approval.raise', $u, ['organization_id' => $o, 'entity' => 'approval_instance', 'entity_id' => $id]);
        Http::json(['id' => $id, 'status' => 'PENDING', 'current_step' => 1, 'total_steps' => $total]);
    }
    public static function actApproval(array $p): void
    {
        [$u, $o] = Auth::org($p, 'approval.act'); $b = Http::body();
        $inst = Database::one('SELECT * FROM approval_instances WHERE id = ? AND organization_id = ?', [(int) $p['id'], $o]);
        if (!$inst) throw new HttpError('Approval instance not found', 404);
        if ($inst['status'] !== 'PENDING') throw new HttpError("Instance already {$inst['status']}", 409);
        $requiredRole = $inst['workflow_id'] ? Database::scalar('SELECT approver_role FROM approval_workflow_steps WHERE workflow_id=? AND step_order=?', [$inst['workflow_id'], $inst['current_step']]) : null;
        if (!$u['is_superadmin'] && $requiredRole && !in_array($requiredRole, $u['roles'], true)) throw new HttpError("This step requires the $requiredRole role", 403);
        $action = strtoupper($b['action'] ?? 'APPROVE');
        if (!in_array($action, ['APPROVE', 'REJECT'], true)) throw new HttpError('action must be APPROVE or REJECT', 422);
        Database::insert('approval_actions', ['instance_id' => $inst['id'], 'step_order' => (int) $inst['current_step'], 'action' => $action, 'actor_user_id' => $u['id'], 'remarks' => $b['remarks'] ?? null]);
        $total = $inst['workflow_id'] ? (int) Database::scalar('SELECT COUNT(*) FROM approval_workflow_steps WHERE workflow_id = ?', [$inst['workflow_id']]) : 1;
        $status = $inst['status']; $step = (int) $inst['current_step'];
        if ($action === 'REJECT') $status = 'REJECTED';
        elseif ($step >= $total) $status = 'APPROVED';
        else $step++;
        $idata = ['status' => $status, 'current_step' => $step];
        if ($status !== 'PENDING') $idata['completed_at'] = date('Y-m-d H:i:s');
        Database::update('approval_instances', (int) $inst['id'], $idata);
        if ($inst['entity'] === 'performance_review') {
            $review = Database::one('SELECT pr.*, e.person_id FROM performance_reviews pr JOIN engagements e ON e.id=pr.engagement_id WHERE pr.id=? AND pr.organization_id=?', [(int) $inst['entity_id'], $o]);
            if ($review && $status === 'APPROVED') {
                Database::update('performance_reviews', (int) $review['id'], ['status' => 'APPROVED', 'approved_by_user_id' => $u['id'], 'approved_at' => date('Y-m-d H:i:s')]);
                Notify::toPerson((int) $review['person_id'], 'performance.approved', 'Performance review approved', 'Your performance review has been approved. Please review and acknowledge it in My Self-Service.', '/me');
            } elseif ($review && $status === 'REJECTED') {
                Database::update('performance_reviews', (int) $review['id'], ['status' => 'REJECTED']);
            }
        }
        Audit::record('approval.' . strtolower($action), $u, ['organization_id' => $o, 'entity' => 'approval_instance', 'entity_id' => (int) $inst['id'], 'after' => ['status' => $status, 'step' => $step, 'remarks' => $b['remarks'] ?? null]]);
        Http::json(['id' => (int) $inst['id'], 'status' => $status, 'current_step' => $step, 'total_steps' => $total]);
    }
}
