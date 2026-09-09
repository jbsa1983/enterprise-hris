"""Performance, training, service desk, onboarding/offboarding (Phase 6)."""
from __future__ import annotations

from datetime import date

from fastapi import APIRouter, Body, Depends, HTTPException
from sqlalchemy import func
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.hrmodules import (
    LifecycleChecklist,
    PerformanceCycle,
    PerformanceReview,
    ServiceTicket,
    TrainingAssignment,
    TrainingCourse,
)

router = APIRouter(prefix="/organizations/{organization_id}", tags=["hr-modules"])

ONBOARDING_ITEMS = [
    "Employee data", "Government numbers", "Bank account", "NDA", "Contract",
    "Privacy consent", "Company policies", "Orientation", "Asset issuance", "Account request",
]
OFFBOARDING_ITEMS = [
    "Notice", "Clearance", "Asset return", "Loan balance settlement", "Cash advance settlement",
    "Final pay", "COE", "Exit interview", "IT account deactivation", "Final documents",
]


# --- Performance -------------------------------------------------------------
@router.get("/performance/cycles", dependencies=[Depends(require_permission("employee.view"))])
def perf_cycles(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = db.query(PerformanceCycle).filter(PerformanceCycle.organization_id == organization_id).all()
    return [{"id": c.id, "name": c.name, "cycle_type": c.cycle_type, "status": c.status} for c in rows]


@router.post("/performance/cycles", dependencies=[Depends(require_permission("employee.edit"))])
def create_cycle(organization_id: int, payload: dict = Body(...),
                 _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    c = PerformanceCycle(organization_id=organization_id, name=payload["name"],
                         cycle_type=payload.get("cycle_type", "ANNUAL"), status="OPEN")
    db.add(c)
    db.commit()
    return {"id": c.id}


@router.get("/performance/reviews", dependencies=[Depends(require_permission("employee.view"))])
def perf_reviews(organization_id: int, cycle_id: int | None = None,
                 _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    q = db.query(PerformanceReview).filter(PerformanceReview.organization_id == organization_id)
    if cycle_id:
        q = q.filter(PerformanceReview.cycle_id == cycle_id)
    rows = q.all()
    return [{"id": r.id, "engagement_id": r.engagement_id, "cycle_id": r.cycle_id,
             "self_score": float(r.self_score) if r.self_score is not None else None,
             "supervisor_score": float(r.supervisor_score) if r.supervisor_score is not None else None,
             "final_rating": float(r.final_rating) if r.final_rating is not None else None,
             "status": r.status} for r in rows]


@router.post("/performance/reviews", dependencies=[Depends(require_permission("employee.edit"))])
def create_review(organization_id: int, payload: dict = Body(...),
                  _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    r = PerformanceReview(organization_id=organization_id, cycle_id=payload["cycle_id"],
                          engagement_id=payload["engagement_id"],
                          self_score=payload.get("self_score"), supervisor_score=payload.get("supervisor_score"),
                          status="DRAFT")
    if r.self_score is not None and r.supervisor_score is not None:
        r.final_rating = round((float(r.self_score) + float(r.supervisor_score)) / 2, 2)
        r.status = "COMPLETED"
    db.add(r)
    db.commit()
    return {"id": r.id, "final_rating": float(r.final_rating) if r.final_rating is not None else None}


# --- Training ----------------------------------------------------------------
@router.get("/training/courses", dependencies=[Depends(require_permission("employee.view"))])
def courses(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = db.query(TrainingCourse).filter(TrainingCourse.organization_id == organization_id).all()
    return [{"id": c.id, "title": c.title, "category": c.category, "provider": c.provider} for c in rows]


@router.post("/training/courses", dependencies=[Depends(require_permission("employee.edit"))])
def create_course(organization_id: int, payload: dict = Body(...),
                  _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    c = TrainingCourse(organization_id=organization_id, title=payload["title"],
                       category=payload.get("category"), provider=payload.get("provider"))
    db.add(c)
    db.commit()
    return {"id": c.id}


@router.get("/training/assignments", dependencies=[Depends(require_permission("employee.view"))])
def assignments(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = db.query(TrainingAssignment).filter(TrainingAssignment.organization_id == organization_id).all()
    return [{"id": t.id, "course_id": t.course_id, "engagement_id": t.engagement_id, "status": t.status,
             "completed_date": t.completed_date.isoformat() if t.completed_date else None} for t in rows]


@router.post("/training/assignments", dependencies=[Depends(require_permission("employee.edit"))])
def assign_training(organization_id: int, payload: dict = Body(...),
                    _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    t = TrainingAssignment(organization_id=organization_id, course_id=payload["course_id"],
                           engagement_id=payload["engagement_id"], status="ASSIGNED")
    db.add(t)
    db.commit()
    return {"id": t.id}


@router.post("/training/assignments/{assignment_id}/complete",
             dependencies=[Depends(require_permission("employee.edit"))])
def complete_training(organization_id: int, assignment_id: int,
                      _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    t = db.get(TrainingAssignment, assignment_id)
    if not t or t.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Assignment not found")
    t.status = "COMPLETED"
    t.completed_date = date.today()
    db.commit()
    return {"id": t.id, "status": t.status}


# --- Service desk ------------------------------------------------------------
@router.get("/service-tickets", dependencies=[Depends(require_permission("employee.view"))])
def tickets(organization_id: int, status: str | None = None,
            _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    q = db.query(ServiceTicket).filter(ServiceTicket.organization_id == organization_id)
    if status:
        q = q.filter(ServiceTicket.status == status)
    rows = q.order_by(ServiceTicket.id.desc()).all()
    return [{"id": t.id, "ticket_number": t.ticket_number, "category": t.category, "priority": t.priority,
             "status": t.status, "subject": t.subject, "assigned_hr": t.assigned_hr} for t in rows]


@router.post("/service-tickets", dependencies=[Depends(require_permission("employee.view"))])
def create_ticket(organization_id: int, payload: dict = Body(...),
                  _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    count = db.query(func.count(ServiceTicket.id)).filter(
        ServiceTicket.organization_id == organization_id).scalar() or 0
    t = ServiceTicket(organization_id=organization_id, ticket_number=f"TKT-{organization_id}-{count + 1:04d}",
                      engagement_id=payload.get("engagement_id"), category=payload.get("category", "HR question"),
                      priority=payload.get("priority", "NORMAL"), subject=payload["subject"],
                      description=payload.get("description"), status="OPEN")
    db.add(t)
    db.commit()
    return {"id": t.id, "ticket_number": t.ticket_number}


@router.post("/service-tickets/{ticket_id}/status", dependencies=[Depends(require_permission("employee.edit"))])
def set_ticket_status(organization_id: int, ticket_id: int, payload: dict = Body(...),
                      _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    t = db.get(ServiceTicket, ticket_id)
    if not t or t.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Ticket not found")
    t.status = payload.get("status", "IN_PROGRESS")
    t.assigned_hr = payload.get("assigned_hr", t.assigned_hr)
    db.commit()
    return {"id": t.id, "status": t.status}


# --- Onboarding / Offboarding ------------------------------------------------
@router.get("/lifecycle", dependencies=[Depends(require_permission("employee.view"))])
def lifecycle(organization_id: int, engagement_id: int, kind: str = "ONBOARDING",
              _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = db.query(LifecycleChecklist).filter(
        LifecycleChecklist.organization_id == organization_id,
        LifecycleChecklist.engagement_id == engagement_id,
        LifecycleChecklist.kind == kind.upper()).all()
    return [{"id": r.id, "item": r.item, "completed": r.completed,
             "completed_date": r.completed_date.isoformat() if r.completed_date else None} for r in rows]


@router.post("/lifecycle/init", dependencies=[Depends(require_permission("employee.edit"))])
def init_lifecycle(organization_id: int, payload: dict = Body(...),
                   _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    """Create a standard onboarding or offboarding checklist for an engagement."""
    kind = payload.get("kind", "ONBOARDING").upper()
    items = ONBOARDING_ITEMS if kind == "ONBOARDING" else OFFBOARDING_ITEMS
    created = []
    for item in items:
        c = LifecycleChecklist(organization_id=organization_id, engagement_id=payload["engagement_id"],
                               kind=kind, item=item, completed=False)
        db.add(c)
        created.append(item)
    db.commit()
    return {"engagement_id": payload["engagement_id"], "kind": kind, "items": len(created)}


@router.post("/lifecycle/{item_id}/complete", dependencies=[Depends(require_permission("employee.edit"))])
def complete_lifecycle(organization_id: int, item_id: int,
                       _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    c = db.get(LifecycleChecklist, item_id)
    if not c or c.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Checklist item not found")
    c.completed = True
    c.completed_date = date.today()
    db.commit()
    return {"id": c.id, "completed": True}
