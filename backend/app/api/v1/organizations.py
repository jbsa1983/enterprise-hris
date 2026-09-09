"""Organization listing (scoped to the user's accessible orgs)."""
from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_user, require_org_access
from app.models.organization import Organization
from app.models.user import User
from app.schemas.common import OrganizationOut

router = APIRouter(prefix="/organizations", tags=["organizations"])


@router.get("", response_model=list[OrganizationOut])
def list_organizations(
    user: User = Depends(get_current_user),
    db: Session = Depends(get_db),
) -> list[Organization]:
    q = db.query(Organization).filter(Organization.is_active.is_(True))
    if not user.is_superadmin:
        ids = user.accessible_org_ids or [-1]
        q = q.filter(Organization.id.in_(ids))
    return q.order_by(Organization.name).all()


@router.get("/{organization_id}", response_model=OrganizationOut)
def get_organization(
    organization_id: int,
    _: int = Depends(require_org_access),
    db: Session = Depends(get_db),
) -> Organization:
    org = db.get(Organization, organization_id)
    if not org:
        raise HTTPException(status_code=404, detail="Organization not found")
    return org
