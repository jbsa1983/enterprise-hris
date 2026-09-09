"""Minimal leave / overtime request models (drive dashboard approval counts).

Full attendance, leave accrual, and the generic approval workflow engine are
scheduled for Phase 2; these lightweight tables let the dashboards show real
pending-approval numbers today.
"""
from __future__ import annotations

from datetime import date

from sqlalchemy import Date, ForeignKey, Integer, Numeric, String
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin


class LeaveRequest(Base, TimestampMixin):
    __tablename__ = "leave_requests"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    engagement_id: Mapped[int] = mapped_column(ForeignKey("engagements.id"), index=True, nullable=False)
    leave_type: Mapped[str] = mapped_column(String(40), nullable=False)
    date_from: Mapped[date | None] = mapped_column(Date, nullable=True)
    date_to: Mapped[date | None] = mapped_column(Date, nullable=True)
    days: Mapped[float] = mapped_column(Numeric(6, 2), default=0)
    status: Mapped[str] = mapped_column(String(20), default="PENDING", index=True)


class OvertimeRequest(Base, TimestampMixin):
    __tablename__ = "overtime_requests"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    engagement_id: Mapped[int] = mapped_column(ForeignKey("engagements.id"), index=True, nullable=False)
    ot_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    hours: Mapped[float] = mapped_column(Numeric(6, 2), default=0)
    status: Mapped[str] = mapped_column(String(20), default="PENDING", index=True)
