from __future__ import annotations

from datetime import date, datetime
from typing import Optional

from pydantic import BaseModel, ConfigDict


class ORMModel(BaseModel):
    model_config = ConfigDict(from_attributes=True)


class OrganizationOut(ORMModel):
    id: int
    uuid: str
    name: str
    code: str
    legal_name: Optional[str] = None
    is_active: bool


class PersonOut(ORMModel):
    id: int
    uuid: str
    first_name: str
    last_name: str
    email: Optional[str] = None
    mobile: Optional[str] = None
    status: str


class EngagementOut(ORMModel):
    id: int
    uuid: str
    person_id: int
    organization_id: int
    engagement_type: str
    employee_number: Optional[str] = None
    start_date: Optional[date] = None
    end_date: Optional[date] = None
    job_grade: Optional[str] = None
    salary_basis: Optional[str] = None
    base_rate: Optional[float] = None
    status: str


class EngagementRow(BaseModel):
    """Flattened person + engagement row for list views."""

    engagement_id: int
    person_id: int
    full_name: str
    engagement_type: str
    employee_number: Optional[str] = None
    status: str
    base_rate: Optional[float] = None
    start_date: Optional[date] = None
    end_date: Optional[date] = None


class ProjectOut(ORMModel):
    id: int
    uuid: str
    organization_id: int
    project_code: str
    project_name: str
    status: str
    start_date: Optional[date] = None
    target_end_date: Optional[date] = None
    labor_budget: Optional[float] = None


class AuditLogOut(ORMModel):
    id: int
    user_email: Optional[str] = None
    organization_id: Optional[int] = None
    action: str
    entity: Optional[str] = None
    entity_id: Optional[str] = None
    created_at: datetime
