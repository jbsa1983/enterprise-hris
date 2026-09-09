"""Recruitment: requisitions, applicants, applications, interviews (Phase 6)."""
from __future__ import annotations

from datetime import date

from sqlalchemy import Date, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin, UUIDMixin


class JobRequisition(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "job_requisitions"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    title: Mapped[str] = mapped_column(String(150), nullable=False)
    department_id: Mapped[int | None] = mapped_column(ForeignKey("departments.id"), nullable=True)
    project_id: Mapped[int | None] = mapped_column(ForeignKey("projects.id"), nullable=True)
    headcount: Mapped[int] = mapped_column(Integer, default=1)
    status: Mapped[str] = mapped_column(String(20), default="OPEN", index=True)  # OPEN/ON_HOLD/FILLED/CLOSED


class Applicant(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "applicants"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    first_name: Mapped[str] = mapped_column(String(100), nullable=False)
    last_name: Mapped[str] = mapped_column(String(100), nullable=False)
    email: Mapped[str | None] = mapped_column(String(255), nullable=True)
    mobile: Mapped[str | None] = mapped_column(String(50), nullable=True)
    resume_object_key: Mapped[str | None] = mapped_column(String(255), nullable=True)
    # If hired, links to the person created from this applicant.
    hired_person_id: Mapped[int | None] = mapped_column(ForeignKey("people.id"), nullable=True)


class JobApplication(Base, TimestampMixin):
    __tablename__ = "job_applications"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    requisition_id: Mapped[int] = mapped_column(ForeignKey("job_requisitions.id"), index=True, nullable=False)
    applicant_id: Mapped[int] = mapped_column(ForeignKey("applicants.id"), index=True, nullable=False)
    # NEW / SCREENING / INTERVIEW / OFFER / HIRED / REJECTED
    stage: Mapped[str] = mapped_column(String(20), default="NEW", index=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)


class Interview(Base, TimestampMixin):
    __tablename__ = "interviews"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    application_id: Mapped[int] = mapped_column(ForeignKey("job_applications.id"), index=True, nullable=False)
    scheduled_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    interviewer: Mapped[str | None] = mapped_column(String(150), nullable=True)
    result: Mapped[str | None] = mapped_column(String(20), nullable=True)  # PASS/FAIL/PENDING
    remarks: Mapped[str | None] = mapped_column(Text, nullable=True)
