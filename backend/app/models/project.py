"""Clients, projects, and project assignments (project-based manpower)."""
from __future__ import annotations

from datetime import date

from sqlalchemy import Boolean, Date, ForeignKey, Integer, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import TimestampMixin, UUIDMixin


class Client(Base, TimestampMixin):
    __tablename__ = "clients"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    name: Mapped[str] = mapped_column(String(255), nullable=False)


class Project(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "projects"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    project_code: Mapped[str] = mapped_column(String(50), nullable=False)
    project_name: Mapped[str] = mapped_column(String(255), nullable=False)
    client_id: Mapped[int | None] = mapped_column(ForeignKey("clients.id"), nullable=True)
    project_manager: Mapped[str | None] = mapped_column(String(255), nullable=True)
    start_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    target_end_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    actual_end_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    site: Mapped[str | None] = mapped_column(String(150), nullable=True)
    cost_center: Mapped[str | None] = mapped_column(String(50), nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="ACTIVE", index=True)
    project_budget: Mapped[float | None] = mapped_column(Numeric(16, 2), nullable=True)
    labor_budget: Mapped[float | None] = mapped_column(Numeric(16, 2), nullable=True)

    assignments: Mapped[list["ProjectAssignment"]] = relationship(back_populates="project")


class ProjectAssignment(Base, TimestampMixin):
    __tablename__ = "project_assignments"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    engagement_id: Mapped[int] = mapped_column(ForeignKey("engagements.id"), index=True, nullable=False)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id"), index=True, nullable=False)
    assignment_start: Mapped[date | None] = mapped_column(Date, nullable=True)
    assignment_end: Mapped[date | None] = mapped_column(Date, nullable=True)
    site: Mapped[str | None] = mapped_column(String(150), nullable=True)
    supervisor: Mapped[str | None] = mapped_column(String(255), nullable=True)
    billable: Mapped[bool] = mapped_column(Boolean, default=False)
    billing_rate: Mapped[float | None] = mapped_column(Numeric(14, 2), nullable=True)
    pay_rate: Mapped[float | None] = mapped_column(Numeric(14, 2), nullable=True)
    shift: Mapped[str | None] = mapped_column(String(50), nullable=True)
    remarks: Mapped[str | None] = mapped_column(Text, nullable=True)

    project: Mapped["Project"] = relationship(back_populates="assignments")
