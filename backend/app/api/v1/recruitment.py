"""Recruitment (Phase 6): requisitions, applicants, applications, hiring."""
from __future__ import annotations

from datetime import date

from fastapi import APIRouter, Body, Depends, HTTPException
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.enums import EngagementType
from app.models.person import Engagement, Person
from app.models.recruitment import Applicant, JobApplication, JobRequisition

router = APIRouter(prefix="/organizations/{organization_id}/recruitment", tags=["recruitment"])
_STAGES = ["NEW", "SCREENING", "INTERVIEW", "OFFER", "HIRED", "REJECTED"]


@router.get("/requisitions", dependencies=[Depends(require_permission("employee.view"))])
def list_requisitions(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = db.query(JobRequisition).filter(JobRequisition.organization_id == organization_id).all()
    return [{"id": r.id, "uuid": r.uuid, "title": r.title, "headcount": r.headcount, "status": r.status}
            for r in rows]


@router.post("/requisitions", dependencies=[Depends(require_permission("employee.edit"))])
def create_requisition(
    organization_id: int, payload: dict = Body(...),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    r = JobRequisition(organization_id=organization_id, title=payload["title"],
                       department_id=payload.get("department_id"), project_id=payload.get("project_id"),
                       headcount=payload.get("headcount", 1), status="OPEN")
    db.add(r)
    db.commit()
    return {"id": r.id}


@router.get("/applicants", dependencies=[Depends(require_permission("employee.view"))])
def list_applicants(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = db.query(Applicant).filter(Applicant.organization_id == organization_id).all()
    return [{"id": a.id, "uuid": a.uuid, "name": f"{a.first_name} {a.last_name}", "email": a.email,
             "hired_person_id": a.hired_person_id} for a in rows]


@router.post("/applicants", dependencies=[Depends(require_permission("employee.edit"))])
def create_applicant(
    organization_id: int, payload: dict = Body(...),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    a = Applicant(organization_id=organization_id, first_name=payload["first_name"],
                  last_name=payload["last_name"], email=payload.get("email"), mobile=payload.get("mobile"))
    db.add(a)
    db.flush()
    application = None
    if payload.get("requisition_id"):
        app = JobApplication(organization_id=organization_id, requisition_id=payload["requisition_id"],
                             applicant_id=a.id, stage="NEW")
        db.add(app)
        db.flush()
        application = app.id
    db.commit()
    return {"id": a.id, "application_id": application}


@router.get("/applications", dependencies=[Depends(require_permission("employee.view"))])
def list_applications(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = (
        db.query(JobApplication, Applicant)
        .join(Applicant, Applicant.id == JobApplication.applicant_id)
        .filter(JobApplication.organization_id == organization_id)
        .all()
    )
    return [{"id": app.id, "applicant": f"{a.first_name} {a.last_name}", "requisition_id": app.requisition_id,
             "stage": app.stage} for app, a in rows]


@router.post("/applications/{application_id}/advance", dependencies=[Depends(require_permission("employee.edit"))])
def advance_application(
    organization_id: int, application_id: int, payload: dict = Body(default={}),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    app = db.get(JobApplication, application_id)
    if not app or app.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Application not found")
    target = payload.get("stage")
    if target:
        if target not in _STAGES:
            raise HTTPException(status_code=400, detail=f"stage must be one of {_STAGES}")
        app.stage = target
    else:
        idx = _STAGES.index(app.stage) if app.stage in _STAGES else 0
        app.stage = _STAGES[min(idx + 1, len(_STAGES) - 2)]  # advance, stop before REJECTED
    db.commit()
    return {"id": app.id, "stage": app.stage}


@router.post("/applications/{application_id}/hire", dependencies=[Depends(require_permission("employee.create"))])
def hire_applicant(
    organization_id: int, application_id: int, payload: dict = Body(default={}),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    """Convert an applicant into a Person + Engagement (preserving history)."""
    app = db.get(JobApplication, application_id)
    if not app or app.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Application not found")
    applicant = db.get(Applicant, app.applicant_id)

    person = Person(first_name=applicant.first_name, last_name=applicant.last_name,
                    email=applicant.email, mobile=applicant.mobile, status="ACTIVE")
    db.add(person)
    db.flush()
    eng = Engagement(
        person_id=person.id, organization_id=organization_id,
        engagement_type=payload.get("engagement_type", EngagementType.PROBATIONARY),
        employee_number=payload.get("employee_number", f"NEW-{person.id}"),
        start_date=date.today(), salary_basis="MONTHLY", base_rate=payload.get("base_rate", 25000),
        status="ACTIVE",
    )
    db.add(eng)
    applicant.hired_person_id = person.id
    app.stage = "HIRED"
    db.commit()
    return {"person_id": person.id, "engagement_id": eng.id, "stage": app.stage}
