"""Per-period project budget allocations (planned spend).

Actual spend is derived from payroll; these rows hold the *planned* allocation
per period so the UI can show planned-vs-actual variance.
"""
from __future__ import annotations

from sqlalchemy import ForeignKey, Integer, Numeric, String, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin


class ProjectBudgetAllocation(Base, TimestampMixin):
    __tablename__ = "project_budget_allocations"
    __table_args__ = (UniqueConstraint("project_id", "period_label", name="uq_project_period"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    project_id: Mapped[int] = mapped_column(ForeignKey("projects.id"), index=True, nullable=False)
    period_label: Mapped[str] = mapped_column(String(20), nullable=False)  # e.g. "2026-09"
    amount: Mapped[float] = mapped_column(Numeric(16, 2), default=0)
