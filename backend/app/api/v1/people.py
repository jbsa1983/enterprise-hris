"""People / engagements within an organization (org-scoped)."""
from __future__ import annotations

from fastapi import APIRouter, Depends, Query
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.enums import EngagementType
from app.models.person import Engagement, Person
from app.schemas.common import EngagementRow

router = APIRouter(prefix="/organizations/{organization_id}", tags=["people"])


def _rows(query) -> list[EngagementRow]:
    out: list[EngagementRow] = []
    for eng, person in query:
        out.append(
            EngagementRow(
                engagement_id=eng.id,
                person_id=person.id,
                full_name=person.full_name,
                engagement_type=eng.engagement_type,
                employee_number=eng.employee_number,
                status=eng.status,
                base_rate=float(eng.base_rate) if eng.base_rate is not None else None,
                start_date=eng.start_date,
                end_date=eng.end_date,
            )
        )
    return out


@router.get("/people", response_model=list[EngagementRow], dependencies=[Depends(require_permission("employee.view"))])
def list_people(
    organization_id: int,
    _: int = Depends(require_org_access),
    engagement_type: str | None = Query(None),
    status: str | None = Query(None),
    db: Session = Depends(get_db),
) -> list[EngagementRow]:
    q = (
        db.query(Engagement, Person)
        .join(Person, Person.id == Engagement.person_id)
        .filter(Engagement.organization_id == organization_id)
    )
    if engagement_type:
        q = q.filter(Engagement.engagement_type == engagement_type)
    if status:
        q = q.filter(Engagement.status == status)
    return _rows(q.order_by(Person.last_name).all())


@router.get(
    "/employees",
    response_model=list[EngagementRow],
    dependencies=[Depends(require_permission("employee.view"))],
)
def list_employees(
    organization_id: int,
    _: int = Depends(require_org_access),
    db: Session = Depends(get_db),
) -> list[EngagementRow]:
    """Engagements that are NOT consultants."""
    q = (
        db.query(Engagement, Person)
        .join(Person, Person.id == Engagement.person_id)
        .filter(
            Engagement.organization_id == organization_id,
            Engagement.engagement_type.notin_(EngagementType.CONSULTANT_TYPES),
        )
    )
    return _rows(q.order_by(Person.last_name).all())


@router.get(
    "/consultants",
    response_model=list[EngagementRow],
    dependencies=[Depends(require_permission("employee.view"))],
)
def list_consultants(
    organization_id: int,
    _: int = Depends(require_org_access),
    db: Session = Depends(get_db),
) -> list[EngagementRow]:
    q = (
        db.query(Engagement, Person)
        .join(Person, Person.id == Engagement.person_id)
        .filter(
            Engagement.organization_id == organization_id,
            Engagement.engagement_type.in_(EngagementType.CONSULTANT_TYPES),
        )
    )
    return _rows(q.order_by(Person.last_name).all())
