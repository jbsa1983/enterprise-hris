"""Generic approval-workflow engine endpoints (Phase 2) — org-scoped.

Configure multi-step workflows per organization + transaction type, raise
approval instances against any entity, and act (approve/reject) step by step.
"""
from __future__ import annotations

from fastapi import APIRouter, Body, Depends, HTTPException, Request
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.user import User
from app.models.workflow import (
    ApprovalAction,
    ApprovalInstance,
    ApprovalWorkflow,
    ApprovalWorkflowStep,
)
from app.services import audit_service

router = APIRouter(prefix="/organizations/{organization_id}", tags=["workflow"])


def _wf_dict(w: ApprovalWorkflow) -> dict:
    return {
        "id": w.id, "name": w.name, "transaction_type": w.transaction_type,
        "active": w.active, "conditions": w.conditions_json or {},
        "steps": [{"step_order": s.step_order, "name": s.name, "approver_role": s.approver_role}
                  for s in w.steps],
    }


@router.get("/workflows", dependencies=[Depends(require_permission("organization.view"))])
def list_workflows(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = db.query(ApprovalWorkflow).filter(ApprovalWorkflow.organization_id == organization_id).all()
    return [_wf_dict(w) for w in rows]


@router.post("/workflows", dependencies=[Depends(require_permission("system.admin"))])
def create_workflow(
    organization_id: int, payload: dict = Body(...),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    w = ApprovalWorkflow(
        organization_id=organization_id, name=payload["name"],
        transaction_type=payload["transaction_type"], conditions_json=payload.get("conditions"),
        active=payload.get("active", True),
    )
    db.add(w)
    db.flush()
    for i, step in enumerate(payload.get("steps", []), start=1):
        db.add(ApprovalWorkflowStep(workflow_id=w.id, step_order=step.get("step_order", i),
                                    name=step["name"], approver_role=step.get("approver_role")))
    db.commit()
    db.refresh(w)
    return _wf_dict(w)


def _match_workflow(db, organization_id, transaction_type, amount) -> ApprovalWorkflow | None:
    wfs = (
        db.query(ApprovalWorkflow)
        .filter(ApprovalWorkflow.organization_id == organization_id,
                ApprovalWorkflow.transaction_type == transaction_type,
                ApprovalWorkflow.active.is_(True))
        .all()
    )
    # Prefer a workflow whose min_amount condition is satisfied.
    best = None
    for w in wfs:
        cond = w.conditions_json or {}
        min_amt = cond.get("min_amount")
        if min_amt is None or (amount is not None and amount >= min_amt):
            best = w
    return best or (wfs[0] if wfs else None)


@router.post("/approvals", dependencies=[Depends(require_permission("organization.view"))])
def raise_approval(
    organization_id: int, payload: dict = Body(...),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    amount = payload.get("amount")
    wf = _match_workflow(db, organization_id, payload["transaction_type"], amount)
    inst = ApprovalInstance(
        organization_id=organization_id, workflow_id=wf.id if wf else None,
        transaction_type=payload["transaction_type"], entity=payload.get("entity", ""),
        entity_id=payload.get("entity_id", 0), amount=amount, current_step=1, status="PENDING",
    )
    db.add(inst)
    db.commit()
    db.refresh(inst)
    total_steps = len(wf.steps) if wf else 1
    return {"id": inst.id, "uuid": inst.uuid, "status": inst.status,
            "current_step": inst.current_step, "total_steps": total_steps}


@router.get("/approvals", dependencies=[Depends(require_permission("organization.view"))])
def list_approvals(
    organization_id: int, status: str | None = None,
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    q = db.query(ApprovalInstance).filter(ApprovalInstance.organization_id == organization_id)
    if status:
        q = q.filter(ApprovalInstance.status == status)
    rows = q.order_by(ApprovalInstance.id.desc()).limit(200).all()
    return [
        {"id": r.id, "uuid": r.uuid, "transaction_type": r.transaction_type, "entity": r.entity,
         "entity_id": r.entity_id, "amount": float(r.amount) if r.amount is not None else None,
         "current_step": r.current_step, "status": r.status,
         "actions": [{"step": a.step_order, "action": a.action, "remarks": a.remarks} for a in r.actions]}
        for r in rows
    ]


@router.post("/approvals/{instance_id}/act", dependencies=[Depends(require_permission("organization.view"))])
def act_on_approval(
    organization_id: int, instance_id: int, request: Request, payload: dict = Body(...),
    _: int = Depends(require_org_access),
    user: User = Depends(require_permission("organization.view")),
    db: Session = Depends(get_db),
):
    inst = db.get(ApprovalInstance, instance_id)
    if not inst or inst.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Approval instance not found")
    if inst.status != "PENDING":
        raise HTTPException(status_code=409, detail=f"Instance already {inst.status}")

    action = payload.get("action", "APPROVE").upper()
    if action not in ("APPROVE", "REJECT"):
        raise HTTPException(status_code=400, detail="action must be APPROVE or REJECT")

    db.add(ApprovalAction(instance_id=inst.id, step_order=inst.current_step, action=action,
                          actor_user_id=user.id, remarks=payload.get("remarks")))

    wf = db.get(ApprovalWorkflow, inst.workflow_id) if inst.workflow_id else None
    total_steps = len(wf.steps) if wf else 1
    if action == "REJECT":
        inst.status = "REJECTED"
    elif inst.current_step >= total_steps:
        inst.status = "APPROVED"
    else:
        inst.current_step += 1

    audit_service.record(db, action="approval.act", user=user, organization_id=organization_id,
                         entity="approval_instance", entity_id=inst.id,
                         after={"action": action, "status": inst.status, "step": inst.current_step},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    return {"id": inst.id, "status": inst.status, "current_step": inst.current_step, "total_steps": total_steps}
