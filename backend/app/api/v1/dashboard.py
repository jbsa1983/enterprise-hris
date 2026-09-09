"""Dashboard endpoints (enterprise + per-organization)."""
from __future__ import annotations

from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_user, require_org_access
from app.models.user import User
from app.services import dashboard_service

router = APIRouter(tags=["dashboard"])


@router.get("/dashboard/enterprise")
def enterprise_dashboard(
    user: User = Depends(get_current_user),
    db: Session = Depends(get_db),
) -> dict:
    return dashboard_service.enterprise_dashboard(db, user)


@router.get("/organizations/{organization_id}/dashboard")
def organization_dashboard(
    organization_id: int,
    _: int = Depends(require_org_access),
    db: Session = Depends(get_db),
) -> dict:
    return dashboard_service.organization_dashboard(db, organization_id)
