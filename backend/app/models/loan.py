"""Loans, cash advances, and the obligation ledger.

Every payroll deduction against an obligation writes a ledger entry so the
balance *as of any past payroll period* can be reconstructed for payslips.
"""
from __future__ import annotations

from datetime import date

from sqlalchemy import Boolean, Date, ForeignKey, Integer, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import TimestampMixin, UUIDMixin


class Loan(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "loans"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    person_id: Mapped[int] = mapped_column(ForeignKey("people.id"), index=True, nullable=False)
    engagement_id: Mapped[int | None] = mapped_column(ForeignKey("engagements.id"), nullable=True)

    obligation_type: Mapped[str] = mapped_column(String(40), nullable=False)
    reference_number: Mapped[str | None] = mapped_column(String(80), nullable=True)
    description: Mapped[str | None] = mapped_column(String(255), nullable=True)

    principal: Mapped[float] = mapped_column(Numeric(14, 2), default=0)
    interest: Mapped[float] = mapped_column(Numeric(14, 2), default=0)
    total_amount: Mapped[float] = mapped_column(Numeric(14, 2), default=0)
    amount_paid: Mapped[float] = mapped_column(Numeric(14, 2), default=0)
    balance: Mapped[float] = mapped_column(Numeric(14, 2), default=0)
    installment_amount: Mapped[float] = mapped_column(Numeric(14, 2), default=0)

    start_period: Mapped[str | None] = mapped_column(String(20), nullable=True)
    end_period: Mapped[str | None] = mapped_column(String(20), nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="ACTIVE", index=True)
    payroll_deductible: Mapped[bool] = mapped_column(Boolean, default=True)
    direct_payment_allowed: Mapped[bool] = mapped_column(Boolean, default=False)

    transactions: Mapped[list["LoanTransaction"]] = relationship(back_populates="loan")


class LoanTransaction(Base, TimestampMixin):
    __tablename__ = "loan_transactions"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    loan_id: Mapped[int] = mapped_column(ForeignKey("loans.id"), index=True, nullable=False)
    entry_type: Mapped[str] = mapped_column(String(30), nullable=False)  # ledger entry type
    amount: Mapped[float] = mapped_column(Numeric(14, 2), default=0)
    balance_after: Mapped[float] = mapped_column(Numeric(14, 2), default=0)
    payroll_run_id: Mapped[int | None] = mapped_column(ForeignKey("payroll_runs.id"), nullable=True)
    period_label: Mapped[str | None] = mapped_column(String(30), nullable=True)
    entry_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    remarks: Mapped[str | None] = mapped_column(Text, nullable=True)

    loan: Mapped["Loan"] = relationship(back_populates="transactions")
