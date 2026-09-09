"""Projects within an organization (org-scoped)."""
from __future__ import annotations

from datetime import date

from fastapi import APIRouter, Body, Depends, HTTPException
from sqlalchemy import func
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.project import Project, ProjectAssignment
from app.schemas.common import ProjectOut

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
