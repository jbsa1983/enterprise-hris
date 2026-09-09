"""People / engagements within an organization (org-scoped)."""
from __future__ import annotations

from datetime import date

from fastapi import APIRouter, Body, Depends, HTTPException, Query, Request
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.enums import EngagementType
from app.models.person import Engagement, Person
from app.models.user import User
from app.schemas.common import EngagementRow
from app.schemas.people import EngagementFields, PersonCreate, PersonFields, PersonUpdate
from app.services import audit_service

_PERSON_FIELDS = list(PersonFields.model_fields.keys())
_ENG_FIELDS = list(EngagementFields.model_fields.keys())
_DATE_FIELDS = {"birth_date", "start_date", "end_date", "regularization_date"}


def _parse(value, field):
    if field in _DATE_FIELDS and isinstance(value, str) and value:
        return date.fromisoformat(value)
    return value


def _apply_person(person: Person, data: dict) -> None:
    for f in _PERSON_FIELDS:
        if f in data and data[f] is not None:
            setattr(person, f, _parse(data[f], f))


def _apply_engagement(eng: Engagement, data: dict) -> None:
    for f in _ENG_FIELDS:
        if f in data and data[f] is not None:
            setattr(eng, f, _parse(data[f], f))

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


# --------------------------------------------------------------------------- #
# Create / read / update / archive (full editability)
# --------------------------------------------------------------------------- #
def _detail(person: Person, eng: Engagement) -> dict:
    return {
        "engagement_id": eng.id,
        "person_id": person.id,
        "person": {f: getattr(person, f) for f in _PERSON_FIELDS},
        "engagement": {
            f: (getattr(eng, f).isoformat() if isinstance(getattr(eng, f), date) else getattr(eng, f))
            for f in _ENG_FIELDS
        },
    }


@router.post("/people/create", dependencies=[Depends(require_permission("employee.create"))])
def create_person(
    organization_id: int, payload: PersonCreate, request: Request,
    _: int = Depends(require_org_access),
    user: User = Depends(require_permission("employee.create")),
    db: Session = Depends(get_db),
) -> dict:
    data = payload.model_dump()
    eng_data = data.pop("engagement")
    person = Person(status="ACTIVE")
    _apply_person(person, data)
    if not person.first_name or not person.last_name:
        raise HTTPException(status_code=422, detail="first_name and last_name are required")
    db.add(person)
    db.flush()
    eng = Engagement(person_id=person.id, organization_id=organization_id)
    _apply_engagement(eng, eng_data)
    db.add(eng)
    db.flush()
    audit_service.record(db, action="employee.create", user=user, organization_id=organization_id,
                         entity="person", entity_id=person.id, after={"name": person.full_name},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    return {"person_id": person.id, "engagement_id": eng.id}


@router.get("/people/{engagement_id}", dependencies=[Depends(require_permission("employee.view"))])
def get_person(
    organization_id: int, engagement_id: int,
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
) -> dict:
    eng = db.get(Engagement, engagement_id)
    if not eng or eng.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Engagement not found")
    person = db.get(Person, eng.person_id)
    return _detail(person, eng)


@router.put("/people/{engagement_id}", dependencies=[Depends(require_permission("employee.edit"))])
def update_person(
    organization_id: int, engagement_id: int, request: Request, payload: PersonUpdate,
    _: int = Depends(require_org_access),
    user: User = Depends(require_permission("employee.edit")),
    db: Session = Depends(get_db),
) -> dict:
    eng = db.get(Engagement, engagement_id)
    if not eng or eng.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Engagement not found")
    person = db.get(Person, eng.person_id)
    if payload.person:
        _apply_person(person, payload.person.model_dump(exclude_none=True))
    if payload.engagement:
        _apply_engagement(eng, payload.engagement.model_dump(exclude_none=True))
    audit_service.record(db, action="employee.edit", user=user, organization_id=organization_id,
                         entity="engagement", entity_id=eng.id,
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    return _detail(person, eng)


@router.post("/people/{engagement_id}/archive", dependencies=[Depends(require_permission("employee.archive"))])
def archive_person(
    organization_id: int, engagement_id: int, request: Request, payload: dict = Body(default={}),
    _: int = Depends(require_org_access),
    user: User = Depends(require_permission("employee.archive")),
    db: Session = Depends(get_db),
) -> dict:
    eng = db.get(Engagement, engagement_id)
    if not eng or eng.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Engagement not found")
    eng.status = payload.get("status", "SEPARATED")
    audit_service.record(db, action="employee.archive", user=user, organization_id=organization_id,
                         entity="engagement", entity_id=eng.id, after={"status": eng.status},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    return {"engagement_id": eng.id, "status": eng.status}


@router.post("/engagements", dependencies=[Depends(require_permission("employee.create"))])
def add_engagement(
    organization_id: int, payload: dict = Body(...),
    _: int = Depends(require_org_access),
    db: Session = Depends(get_db),
) -> dict:
    """Add another engagement to an existing person (preserves history)."""
    person_id = payload.get("person_id")
    if not person_id or not db.get(Person, person_id):
        raise HTTPException(status_code=404, detail="Person not found")
    eng = Engagement(person_id=person_id, organization_id=organization_id)
    _apply_engagement(eng, payload)
    db.add(eng)
    db.commit()
    return {"engagement_id": eng.id}
