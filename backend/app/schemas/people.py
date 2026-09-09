from __future__ import annotations

from typing import Optional

from pydantic import BaseModel


class PersonFields(BaseModel):
    # Optional so the same shape serves partial edits; create_person enforces
    # that first_name and last_name are present.
    first_name: Optional[str] = None
    last_name: Optional[str] = None
    middle_name: Optional[str] = None
    suffix: Optional[str] = None
    preferred_name: Optional[str] = None
    birth_date: Optional[str] = None
    gender: Optional[str] = None
    civil_status: Optional[str] = None
    email: Optional[str] = None
    mobile: Optional[str] = None
    address: Optional[str] = None
    emergency_contact: Optional[str] = None
    tin: Optional[str] = None
    sss_number: Optional[str] = None
    philhealth_number: Optional[str] = None
    pagibig_number: Optional[str] = None
    bank_name: Optional[str] = None
    bank_account_number: Optional[str] = None
    bank_account_name: Optional[str] = None


class EngagementFields(BaseModel):
    engagement_type: str = "REGULAR"
    employee_number: Optional[str] = None
    start_date: Optional[str] = None
    end_date: Optional[str] = None
    regularization_date: Optional[str] = None
    department_id: Optional[int] = None
    position_id: Optional[int] = None
    job_grade: Optional[str] = None
    salary_basis: Optional[str] = "MONTHLY"
    base_rate: Optional[float] = None
    payroll_group: Optional[str] = None
    cost_center: Optional[str] = None
    project_id: Optional[int] = None
    work_site: Optional[str] = None
    status: Optional[str] = "ACTIVE"


class PersonCreate(PersonFields):
    engagement: EngagementFields


class PersonUpdate(BaseModel):
    person: Optional[PersonFields] = None
    engagement: Optional[EngagementFields] = None


class PersonDetail(BaseModel):
    engagement_id: int
    person_id: int
    person: dict
    engagement: dict
