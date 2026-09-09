"""Time & attendance and leave configuration (Phase 2)."""
from __future__ import annotations

from datetime import date, datetime

from sqlalchemy import Boolean, Date, DateTime, ForeignKey, Integer, Numeric, String
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin


class Shift(Base, TimestampMixin):
    __tablename__ = "shifts"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    name: Mapped[str] = mapped_column(String(80), nullable=False)
    start_time: Mapped[str] = mapped_column(String(8), default="08:00")
    end_time: Mapped[str] = mapped_column(String(8), default="17:00")
    grace_minutes: Mapped[int] = mapped_column(Integer, default=15)


class Holiday(Base, TimestampMixin):
    __tablename__ = "holidays"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int | None] = mapped_column(ForeignKey("organizations.id"), nullable=True, index=True)
    name: Mapped[str] = mapped_column(String(120), nullable=False)
    holiday_date: Mapped[date] = mapped_column(Date, nullable=False)
    holiday_type: Mapped[str] = mapped_column(String(30), default="REGULAR")  # REGULAR/SPECIAL


class AttendanceLog(Base, TimestampMixin):
    __tablename__ = "attendance_logs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    engagement_id: Mapped[int] = mapped_column(ForeignKey("engagements.id"), index=True, nullable=False)
    log_date: Mapped[date] = mapped_column(Date, nullable=False, index=True)
    time_in: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    time_out: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    hours_worked: Mapped[float] = mapped_column(Numeric(6, 2), default=0)
    late_minutes: Mapped[int] = mapped_column(Integer, default=0)
    undertime_minutes: Mapped[int] = mapped_column(Integer, default=0)
    overtime_hours: Mapped[float] = mapped_column(Numeric(6, 2), default=0)
    night_diff_hours: Mapped[float] = mapped_column(Numeric(6, 2), default=0)
    source: Mapped[str] = mapped_column(String(20), default="MANUAL")  # MANUAL/WEB/CSV/BIOMETRIC
    status: Mapped[str] = mapped_column(String(20), default="PRESENT")


class Timesheet(Base, TimestampMixin):
    __tablename__ = "timesheets"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    engagement_id: Mapped[int] = mapped_column(ForeignKey("engagements.id"), index=True, nullable=False)
    period_start: Mapped[date] = mapped_column(Date, nullable=False)
    period_end: Mapped[date] = mapped_column(Date, nullable=False)
    total_hours: Mapped[float] = mapped_column(Numeric(8, 2), default=0)
    status: Mapped[str] = mapped_column(String(20), default="DRAFT")  # DRAFT/SUBMITTED/APPROVED


class LeaveType(Base, TimestampMixin):
    __tablename__ = "leave_types"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    name: Mapped[str] = mapped_column(String(80), nullable=False)
    default_credits: Mapped[float] = mapped_column(Numeric(6, 2), default=0)
    paid: Mapped[bool] = mapped_column(Boolean, default=True)


class LeaveBalance(Base, TimestampMixin):
    __tablename__ = "leave_balances"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    engagement_id: Mapped[int] = mapped_column(ForeignKey("engagements.id"), index=True, nullable=False)
    leave_type: Mapped[str] = mapped_column(String(80), nullable=False)
    credits: Mapped[float] = mapped_column(Numeric(6, 2), default=0)
    used: Mapped[float] = mapped_column(Numeric(6, 2), default=0)
