"""Audit trail read endpoint."""
from __future__ import annotations

from fastapi import APIRouter, Depends, Query
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_permission
from app.models.audit import AuditLog
from app.schemas.common import AuditLogOut

router = APIRouter(prefix="/audit", tags=["audit"])


@router.get("", response_model=list[AuditLogOut], dependencies=[Depends(require_permission("audit.view"))])
def list_audit(
    organization_id: int | None = Query(None),
    limit: int = Query(100, le=500),
    db: Session = Depends(get_db),
) -> list[AuditLog]:
    q = db.query(AuditLog)
    if organization_id is not None:
        q = q.filter(AuditLog.organization_id == organization_id)
    return q.order_by(AuditLog.id.desc()).limit(limit).all()
