"""Projects within an organization (org-scoped)."""
from __future__ import annotations

from fastapi import APIRouter, Depends
from sqlalchemy import func
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access
from app.models.project import Project, ProjectAssignment
from app.schemas.common import ProjectOut

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
