"""Person (master data) and Engagement (employment / consultancy record).

The central entity is PERSON, not EMPLOYEE. A person can hold many engagements
over time and across organizations without losing history.
"""
from __future__ import annotations

from datetime import date

from sqlalchemy import Boolean, Date, ForeignKey, Integer, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import TimestampMixin, UUIDMixin


class Person(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "people"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    first_name: Mapped[str] = mapped_column(String(100), nullable=False)
    middle_name: Mapped[str | None] = mapped_column(String(100), nullable=True)
    last_name: Mapped[str] = mapped_column(String(100), nullable=False)
    suffix: Mapped[str | None] = mapped_column(String(20), nullable=True)
    preferred_name: Mapped[str | None] = mapped_column(String(100), nullable=True)
    birth_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    gender: Mapped[str | None] = mapped_column(String(20), nullable=True)
    civil_status: Mapped[str | None] = mapped_column(String(20), nullable=True)
    email: Mapped[str | None] = mapped_column(String(255), nullable=True)
    mobile: Mapped[str | None] = mapped_column(String(50), nullable=True)
    address: Mapped[str | None] = mapped_column(Text, nullable=True)
    emergency_contact: Mapped[str | None] = mapped_column(String(255), nullable=True)

    # Government IDs (Philippine statutory)
    tin: Mapped[str | None] = mapped_column(String(50), nullable=True)
    sss_number: Mapped[str | None] = mapped_column(String(50), nullable=True)
    philhealth_number: Mapped[str | None] = mapped_column(String(50), nullable=True)
    pagibig_number: Mapped[str | None] = mapped_column(String(50), nullable=True)

    # Bank
    bank_name: Mapped[str | None] = mapped_column(String(100), nullable=True)
    bank_account_number: Mapped[str | None] = mapped_column(String(50), nullable=True)
    bank_account_name: Mapped[str | None] = mapped_column(String(255), nullable=True)

    profile_image_key: Mapped[str | None] = mapped_column(String(255), nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="ACTIVE")

    engagements: Mapped[list["Engagement"]] = relationship(back_populates="person")

    @property
    def full_name(self) -> str:
        parts = [self.first_name, self.middle_name, self.last_name, self.suffix]
        return " ".join(p for p in parts if p)


class Engagement(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "engagements"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    person_id: Mapped[int] = mapped_column(ForeignKey("people.id"), index=True, nullable=False)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)

    engagement_type: Mapped[str] = mapped_column(String(40), nullable=False)
    employee_number: Mapped[str | None] = mapped_column(String(50), index=True, nullable=True)

    start_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    end_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    regularization_date: Mapped[date | None] = mapped_column(Date, nullable=True)

    department_id: Mapped[int | None] = mapped_column(ForeignKey("departments.id"), nullable=True)
    position_id: Mapped[int | None] = mapped_column(ForeignKey("positions.id"), nullable=True)
    supervisor_id: Mapped[int | None] = mapped_column(ForeignKey("engagements.id"), nullable=True)

    job_grade: Mapped[str | None] = mapped_column(String(50), nullable=True)
    salary_basis: Mapped[str | None] = mapped_column(String(40), nullable=True)  # MONTHLY/DAILY/HOURLY
    base_rate: Mapped[float | None] = mapped_column(Numeric(14, 2), nullable=True)
    payroll_group: Mapped[str | None] = mapped_column(String(50), nullable=True)
    cost_center: Mapped[str | None] = mapped_column(String(50), nullable=True)
    project_id: Mapped[int | None] = mapped_column(ForeignKey("projects.id"), nullable=True)
    work_site: Mapped[str | None] = mapped_column(String(100), nullable=True)

    tax_profile: Mapped[str | None] = mapped_column(String(50), nullable=True)
    statutory_profile: Mapped[str | None] = mapped_column(String(50), nullable=True)
    benefits_profile: Mapped[str | None] = mapped_column(String(50), nullable=True)

    status: Mapped[str] = mapped_column(String(20), default="ACTIVE", index=True)

    person: Mapped["Person"] = relationship(back_populates="engagements")
    department: Mapped["Department"] = relationship()  # noqa: F821
    position: Mapped["Position"] = relationship()  # noqa: F821
