"""Performance, training, service desk, onboarding/offboarding (Phase 6)."""
from __future__ import annotations

from datetime import date

from sqlalchemy import Boolean, Date, ForeignKey, Integer, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin, UUIDMixin


# --- Performance -------------------------------------------------------------
class PerformanceCycle(Base, TimestampMixin):
    __tablename__ = "performance_cycles"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    name: Mapped[str] = mapped_column(String(120), nullable=False)
    cycle_type: Mapped[str] = mapped_column(String(30), default="ANNUAL")  # PROBATIONARY/ANNUAL
    period_start: Mapped[date | None] = mapped_column(Date, nullable=True)
    period_end: Mapped[date | None] = mapped_column(Date, nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="OPEN")


class PerformanceReview(Base, TimestampMixin):
    __tablename__ = "performance_reviews"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    cycle_id: Mapped[int] = mapped_column(ForeignKey("performance_cycles.id"), index=True, nullable=False)
    engagement_id: Mapped[int] = mapped_column(ForeignKey("engagements.id"), index=True, nullable=False)
    self_score: Mapped[float | None] = mapped_column(Numeric(5, 2), nullable=True)
    supervisor_score: Mapped[float | None] = mapped_column(Numeric(5, 2), nullable=True)
    final_rating: Mapped[float | None] = mapped_column(Numeric(5, 2), nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="DRAFT")
    comments: Mapped[str | None] = mapped_column(Text, nullable=True)


# --- Training ----------------------------------------------------------------
class TrainingCourse(Base, TimestampMixin):
    __tablename__ = "training_courses"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    title: Mapped[str] = mapped_column(String(150), nullable=False)
    category: Mapped[str | None] = mapped_column(String(80), nullable=True)
    provider: Mapped[str | None] = mapped_column(String(120), nullable=True)


class TrainingAssignment(Base, TimestampMixin):
    __tablename__ = "training_assignments"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    course_id: Mapped[int] = mapped_column(ForeignKey("training_courses.id"), index=True, nullable=False)
    engagement_id: Mapped[int] = mapped_column(ForeignKey("engagements.id"), index=True, nullable=False)
    status: Mapped[str] = mapped_column(String(20), default="ASSIGNED")  # ASSIGNED/COMPLETED
    completed_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    certificate_expiry: Mapped[date | None] = mapped_column(Date, nullable=True)


# --- Service desk ------------------------------------------------------------
class ServiceTicket(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "service_tickets"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    ticket_number: Mapped[str] = mapped_column(String(40), unique=True, nullable=False)
    engagement_id: Mapped[int | None] = mapped_column(ForeignKey("engagements.id"), nullable=True)
    category: Mapped[str] = mapped_column(String(60), nullable=False)  # COE / Payroll / HMO ...
    priority: Mapped[str] = mapped_column(String(20), default="NORMAL")
    assigned_hr: Mapped[str | None] = mapped_column(String(120), nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="OPEN", index=True)
    subject: Mapped[str] = mapped_column(String(200), nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)


# --- Onboarding / Offboarding ------------------------------------------------
class LifecycleChecklist(Base, TimestampMixin):
    """One checklist item for onboarding or offboarding of an engagement."""

    __tablename__ = "lifecycle_checklists"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    engagement_id: Mapped[int] = mapped_column(ForeignKey("engagements.id"), index=True, nullable=False)
    kind: Mapped[str] = mapped_column(String(20), default="ONBOARDING", index=True)  # ONBOARDING/OFFBOARDING
    item: Mapped[str] = mapped_column(String(150), nullable=False)
    completed: Mapped[bool] = mapped_column(Boolean, default=False)
    completed_date: Mapped[date | None] = mapped_column(Date, nullable=True)
