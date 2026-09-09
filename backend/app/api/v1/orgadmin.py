"""Departments, positions, and cost centers CRUD (org-scoped)."""
from __future__ import annotations

from fastapi import APIRouter, Body, Depends, HTTPException
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.org_structure import CostCenter, Department, Position

router = APIRouter(prefix="/organizations/{organization_id}", tags=["org-structure"])
_MANAGE = "organization.manage"


# --- Departments -------------------------------------------------------------
@router.get("/departments", dependencies=[Depends(require_permission("organization.view"))])
def list_departments(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = db.query(Department).filter(Department.organization_id == organization_id).order_by(Department.name).all()
    return [{"id": d.id, "name": d.name, "code": d.code, "parent_id": d.parent_id} for d in rows]


@router.post("/departments", dependencies=[Depends(require_permission(_MANAGE))])
def create_department(organization_id: int, payload: dict = Body(...), _: int = Depends(require_org_access),
                      db: Session = Depends(get_db)):
    d = Department(organization_id=organization_id, name=payload["name"], code=payload.get("code"),
                   parent_id=payload.get("parent_id"))
    db.add(d)
    db.commit()
    return {"id": d.id}


@router.put("/departments/{dept_id}", dependencies=[Depends(require_permission(_MANAGE))])
def update_department(organization_id: int, dept_id: int, payload: dict = Body(...),
                      _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    d = db.get(Department, dept_id)
    if not d or d.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Department not found")
    for f in ("name", "code", "parent_id"):
        if f in payload and payload[f] is not None:
            setattr(d, f, payload[f])
    db.commit()
    return {"id": d.id, "name": d.name}


@router.delete("/departments/{dept_id}", dependencies=[Depends(require_permission(_MANAGE))])
def delete_department(organization_id: int, dept_id: int, _: int = Depends(require_org_access),
                      db: Session = Depends(get_db)):
    d = db.get(Department, dept_id)
    if not d or d.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Department not found")
    db.delete(d)
    db.commit()
    return {"deleted": dept_id}


# --- Positions ---------------------------------------------------------------
@router.get("/positions", dependencies=[Depends(require_permission("organization.view"))])
def list_positions(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = db.query(Position).filter(Position.organization_id == organization_id).order_by(Position.title).all()
    return [{"id": p.id, "title": p.title, "job_grade": p.job_grade, "department_id": p.department_id} for p in rows]


@router.post("/positions", dependencies=[Depends(require_permission(_MANAGE))])
def create_position(organization_id: int, payload: dict = Body(...), _: int = Depends(require_org_access),
                    db: Session = Depends(get_db)):
    p = Position(organization_id=organization_id, title=payload["title"], job_grade=payload.get("job_grade"),
                 department_id=payload.get("department_id"))
    db.add(p)
    db.commit()
    return {"id": p.id}


@router.put("/positions/{pos_id}", dependencies=[Depends(require_permission(_MANAGE))])
def update_position(organization_id: int, pos_id: int, payload: dict = Body(...),
                    _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    p = db.get(Position, pos_id)
    if not p or p.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Position not found")
    for f in ("title", "job_grade", "department_id"):
        if f in payload and payload[f] is not None:
            setattr(p, f, payload[f])
    db.commit()
    return {"id": p.id, "title": p.title}


@router.delete("/positions/{pos_id}", dependencies=[Depends(require_permission(_MANAGE))])
def delete_position(organization_id: int, pos_id: int, _: int = Depends(require_org_access),
                    db: Session = Depends(get_db)):
    p = db.get(Position, pos_id)
    if not p or p.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Position not found")
    db.delete(p)
    db.commit()
    return {"deleted": pos_id}


# --- Cost centers ------------------------------------------------------------
@router.get("/cost-centers", dependencies=[Depends(require_permission("organization.view"))])
def list_cost_centers(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = db.query(CostCenter).filter(CostCenter.organization_id == organization_id).all()
    return [{"id": c.id, "code": c.code, "name": c.name} for c in rows]


@router.post("/cost-centers", dependencies=[Depends(require_permission(_MANAGE))])
def create_cost_center(organization_id: int, payload: dict = Body(...), _: int = Depends(require_org_access),
                       db: Session = Depends(get_db)):
    c = CostCenter(organization_id=organization_id, code=payload["code"], name=payload["name"])
    db.add(c)
    db.commit()
    return {"id": c.id}
