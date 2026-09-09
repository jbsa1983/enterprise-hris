"""Payroll periods, runs, per-person results, and the effective-dated
statutory rule store.

Design note: statutory values (BIR/SSS/PhilHealth/Pag-IBIG) are NOT hard-coded
in calculation logic. They live in `StatutoryRuleSet` / `StatutoryRule` rows
that are effective-dated and versioned. A locked payroll run pins the exact
rule version it used, so historical results never shift when rules change.
"""
from __future__ import annotations

from datetime import date

from sqlalchemy import Date, ForeignKey, Integer, JSON, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import TimestampMixin, UUIDMixin
from app.models.enums import PayrollStatus


class PayrollPeriod(Base, TimestampMixin):
    __tablename__ = "payroll_periods"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    name: Mapped[str] = mapped_column(String(100), nullable=False)
    frequency: Mapped[str] = mapped_column(String(20), nullable=False)
    period_start: Mapped[date] = mapped_column(Date, nullable=False)
    period_end: Mapped[date] = mapped_column(Date, nullable=False)
    pay_date: Mapped[date | None] = mapped_column(Date, nullable=True)

    runs: Mapped[list["PayrollRun"]] = relationship(back_populates="period")


class PayrollRun(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "payroll_runs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    period_id: Mapped[int] = mapped_column(ForeignKey("payroll_periods.id"), index=True, nullable=False)
    reference: Mapped[str] = mapped_column(String(80), nullable=False)
    status: Mapped[str] = mapped_column(String(30), default=PayrollStatus.DRAFT, index=True)
    # Snapshot of the statutory rule versions used (pinned at calculation time).
    rule_version_snapshot: Mapped[dict | None] = mapped_column(JSON, nullable=True)

    gross_total: Mapped[float] = mapped_column(Numeric(16, 2), default=0)
    deduction_total: Mapped[float] = mapped_column(Numeric(16, 2), default=0)
    net_total: Mapped[float] = mapped_column(Numeric(16, 2), default=0)

    period: Mapped["PayrollPeriod"] = relationship(back_populates="runs")
    lines: Mapped[list["PayrollRunPerson"]] = relationship(back_populates="run")


class PayrollRunPerson(Base, TimestampMixin):
    __tablename__ = "payroll_run_people"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    run_id: Mapped[int] = mapped_column(ForeignKey("payroll_runs.id"), index=True, nullable=False)
    engagement_id: Mapped[int] = mapped_column(ForeignKey("engagements.id"), index=True, nullable=False)

    gross_pay: Mapped[float] = mapped_column(Numeric(14, 2), default=0)
    total_deductions: Mapped[float] = mapped_column(Numeric(14, 2), default=0)
    net_pay: Mapped[float] = mapped_column(Numeric(14, 2), default=0)
    # Line-item breakdowns kept as JSON for prototype flexibility.
    earnings: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    deductions: Mapped[dict | None] = mapped_column(JSON, nullable=True)

    run: Mapped["PayrollRun"] = relationship(back_populates="lines")


class StatutoryRuleSet(Base, TimestampMixin):
    """A named, effective-dated bundle of statutory parameters (e.g. 'SSS')."""

    __tablename__ = "statutory_rule_sets"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    rule_name: Mapped[str] = mapped_column(String(50), index=True, nullable=False)  # BIR/SSS/PHIC/HDMF
    rule_version: Mapped[str] = mapped_column(String(50), nullable=False)
    effective_from: Mapped[date] = mapped_column(Date, nullable=False)
    effective_to: Mapped[date | None] = mapped_column(Date, nullable=True)
    # Prototype flag — these seeded values are illustrative, NOT authoritative.
    is_prototype_data: Mapped[bool] = mapped_column(default=True)
    parameters_json: Mapped[dict] = mapped_column(JSON, nullable=False)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
