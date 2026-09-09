"""Projects within an organization (org-scoped)."""
from __future__ import annotations

import csv
import io
from datetime import date

from fastapi import APIRouter, Body, Depends, HTTPException, Query, Response
from sqlalchemy import func
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.budget import ProjectBudgetAllocation
from app.models.payroll import PayrollPeriod, PayrollRun, PayrollRunPerson
from app.models.person import Engagement, Person
from app.models.project import Project, ProjectAssignment
from app.schemas.common import ProjectOut


def _period_label(p: PayrollPeriod) -> str:
    return p.period_start.strftime("%Y-%m") if p and p.period_start else "—"


def _actual_by_period(db: Session, project_id: int) -> dict[str, float]:
    """Actual payroll cost (gross) charged to a project, grouped by YYYY-MM."""
    rows = (
        db.query(PayrollPeriod, func.coalesce(func.sum(PayrollRunPerson.gross_pay), 0))
        .join(PayrollRun, PayrollRun.period_id == PayrollPeriod.id)
        .join(PayrollRunPerson, PayrollRunPerson.run_id == PayrollRun.id)
        .join(Engagement, Engagement.id == PayrollRunPerson.engagement_id)
        .filter(Engagement.project_id == project_id)
        .group_by(PayrollPeriod.id)
        .all()
    )
    out: dict[str, float] = {}
    for period, total in rows:
        out[_period_label(period)] = out.get(_period_label(period), 0) + float(total or 0)
    return out

_PROJ_DATE_FIELDS = {"start_date", "target_end_date", "actual_end_date"}


def _apply_project(p: Project, data: dict) -> None:
    for f in ("project_code", "project_name", "client_id", "project_manager", "start_date",
              "target_end_date", "actual_end_date", "site", "cost_center", "status",
              "project_budget", "labor_budget"):
        if f in data and data[f] is not None:
            v = data[f]
            if f in _PROJ_DATE_FIELDS and isinstance(v, str):
                v = date.fromisoformat(v)
            setattr(p, f, v)

router = APIRouter(prefix="/organizations/{organization_id}", tags=["projects"])


@router.get("/projects", response_model=list[ProjectOut])
def list_projects(
    organization_id: int,
    _: int = Depends(require_org_access),
    db: Session = Depends(get_db),
) -> list[Project]:
    return (
        db.query(Project)
        .filter(Project.organization_id == organization_id)
        .order_by(Project.project_name)
        .all()
    )


@router.get("/projects/{project_id}/manpower")
def project_manpower(
    organization_id: int,
    project_id: int,
    _: int = Depends(require_org_access),
    db: Session = Depends(get_db),
) -> dict:
    count = (
        db.query(func.count(ProjectAssignment.id))
        .filter(ProjectAssignment.project_id == project_id)
        .scalar()
        or 0
    )
    return {"project_id": project_id, "deployed_personnel": int(count)}


@router.post("/projects", dependencies=[Depends(require_permission("organization.manage"))])
def create_project(
    organization_id: int, payload: dict = Body(...),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
) -> dict:
    if not payload.get("project_code") or not payload.get("project_name"):
        raise HTTPException(status_code=422, detail="project_code and project_name are required")
    p = Project(organization_id=organization_id, status=payload.get("status", "ACTIVE"),
                project_code=payload["project_code"], project_name=payload["project_name"])
    _apply_project(p, payload)
    db.add(p)
    db.commit()
    return {"id": p.id, "project_code": p.project_code}


@router.put("/projects/{project_id}", dependencies=[Depends(require_permission("organization.manage"))])
def update_project(
    organization_id: int, project_id: int, payload: dict = Body(...),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
) -> dict:
    p = db.get(Project, project_id)
    if not p or p.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Project not found")
    _apply_project(p, payload)
    db.commit()
    return {"id": p.id, "project_name": p.project_name, "status": p.status}


@router.delete("/projects/{project_id}", dependencies=[Depends(require_permission("organization.manage"))])
def delete_project(organization_id: int, project_id: int, _: int = Depends(require_org_access),
                   db: Session = Depends(get_db)) -> dict:
    p = db.get(Project, project_id)
    if not p or p.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Project not found")
    assigned = db.query(func.count(ProjectAssignment.id)).filter(ProjectAssignment.project_id == project_id).scalar() or 0
    if assigned:
        raise HTTPException(status_code=409, detail=f"Reassign {assigned} personnel before deleting this project")
    db.query(ProjectBudgetAllocation).filter(ProjectBudgetAllocation.project_id == project_id).delete()
    db.delete(p)
    db.commit()
    return {"deleted": project_id}


# --- Budget: per-period allocation (planned) + payroll spend (actual) --------
@router.get("/projects/{project_id}/allocations", dependencies=[Depends(require_permission("organization.view"))])
def list_allocations(organization_id: int, project_id: int, _: int = Depends(require_org_access),
                     db: Session = Depends(get_db)):
    rows = db.query(ProjectBudgetAllocation).filter(ProjectBudgetAllocation.project_id == project_id).all()
    return [{"id": a.id, "period_label": a.period_label, "amount": float(a.amount or 0)} for a in rows]


@router.post("/projects/{project_id}/allocations", dependencies=[Depends(require_permission("organization.manage"))])
def set_allocation(organization_id: int, project_id: int, payload: dict = Body(...),
                   _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    p = db.get(Project, project_id)
    if not p or p.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Project not found")
    period = payload["period_label"]
    existing = (db.query(ProjectBudgetAllocation)
                .filter(ProjectBudgetAllocation.project_id == project_id,
                        ProjectBudgetAllocation.period_label == period).first())
    if existing:
        existing.amount = float(payload.get("amount", 0))
    else:
        db.add(ProjectBudgetAllocation(organization_id=organization_id, project_id=project_id,
                                       period_label=period, amount=float(payload.get("amount", 0))))
    db.commit()
    return {"ok": True}


@router.get("/projects/{project_id}/budget", dependencies=[Depends(require_permission("organization.view"))])
def project_budget(organization_id: int, project_id: int,
                   date_from: str | None = Query(None), date_to: str | None = Query(None),
                   _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    p = db.get(Project, project_id)
    if not p or p.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Project not found")
    allocations = {a.period_label: float(a.amount or 0)
                   for a in db.query(ProjectBudgetAllocation).filter(ProjectBudgetAllocation.project_id == project_id).all()}
    actual = _actual_by_period(db, project_id)
    periods = sorted(set(allocations) | set(actual))
    if date_from:
        periods = [p_ for p_ in periods if p_ >= date_from]
    if date_to:
        periods = [p_ for p_ in periods if p_ <= date_to]
    rows = [{"period": pl, "allocated": allocations.get(pl, 0.0), "actual": actual.get(pl, 0.0),
             "variance": round(allocations.get(pl, 0.0) - actual.get(pl, 0.0), 2)} for pl in periods]
    allocated_total = sum(r["allocated"] for r in rows)
    actual_total = sum(r["actual"] for r in rows)
    labor_budget = float(p.labor_budget or 0)
    return {
        "project_name": p.project_name, "labor_budget": labor_budget,
        "allocated_total": round(allocated_total, 2), "actual_total": round(actual_total, 2),
        "remaining_vs_budget": round(labor_budget - actual_total, 2),
        "remaining_vs_allocated": round(allocated_total - actual_total, 2),
        "periods": rows,
    }


@router.get("/projects/{project_id}/report", dependencies=[Depends(require_permission("reports.view"))])
def project_report(organization_id: int, project_id: int,
                   date_from: str | None = Query(None), date_to: str | None = Query(None),
                   fmt: str = Query("json", pattern="^(json|csv)$"),
                   _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    p = db.get(Project, project_id)
    if not p or p.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Project not found")

    rows = (
        db.query(Person, Engagement, PayrollPeriod, func.coalesce(func.sum(PayrollRunPerson.gross_pay), 0))
        .join(Engagement, Engagement.person_id == Person.id)
        .join(PayrollRunPerson, PayrollRunPerson.engagement_id == Engagement.id)
        .join(PayrollRun, PayrollRun.id == PayrollRunPerson.run_id)
        .join(PayrollPeriod, PayrollPeriod.id == PayrollRun.period_id)
        .filter(Engagement.project_id == project_id)
        .group_by(Person.id, Engagement.id, PayrollPeriod.id)
        .all()
    )
    data = []
    for person, eng, period, total in rows:
        pl = _period_label(period)
        if date_from and pl < date_from:
            continue
        if date_to and pl > date_to:
            continue
        data.append({"employee_number": eng.employee_number, "name": person.full_name,
                     "period": pl, "cost": float(total or 0)})
    total_cost = round(sum(r["cost"] for r in data), 2)

    if fmt == "csv":
        buf = io.StringIO()
        w = csv.writer(buf, lineterminator="\n")
        w.writerow(["Employee No.", "Name", "Period", "Cost"])
        for r in data:
            w.writerow([r["employee_number"], r["name"], r["period"], r["cost"]])
        w.writerow([])
        w.writerow(["", "", "TOTAL", total_cost])
        return Response(content=buf.getvalue().encode("utf-8"), media_type="text/csv",
                        headers={"Content-Disposition": f'attachment; filename="project_{p.project_code}_report.csv"'})

    return {"project_name": p.project_name, "project_code": p.project_code, "rows": data,
            "total_cost": total_cost, "headcount": len({r["name"] for r in data})}
