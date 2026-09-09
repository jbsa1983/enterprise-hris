"""Audit logging service. Append-only — audit rows are never updated/deleted."""
from __future__ import annotations

from typing import Any

from sqlalchemy.orm import Session

from app.models.audit import AuditLog
from app.models.user import User


def record(
    db: Session,
    *,
    action: str,
    user: User | None = None,
    organization_id: int | None = None,
    entity: str | None = None,
    entity_id: str | int | None = None,
    before: dict[str, Any] | None = None,
    after: dict[str, Any] | None = None,
    ip_address: str | None = None,
    user_agent: str | None = None,
    commit: bool = True,
) -> AuditLog:
    log = AuditLog(
        user_id=user.id if user else None,
        user_email=user.email if user else None,
        organization_id=organization_id,
        action=action,
        entity=entity,
        entity_id=str(entity_id) if entity_id is not None else None,
        before=before,
        after=after,
        ip_address=ip_address,
        user_agent=user_agent,
    )
    db.add(log)
    if commit:
        db.commit()
    return log
