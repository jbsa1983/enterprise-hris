"""Payslips and consultant payment advices.

One table backs both document types (`document_type`). Each row stores an
immutable `snapshot` of everything shown on the document — including obligation
balances *as of that payroll period* — so re-rendering never reflects later
changes. Corrections create a new `version`; the prior version is retained
(is_current=False) for audit history.
"""
from __future__ import annotations

from sqlalchemy import Boolean, ForeignKey, Integer, JSON, Numeric, String
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin, UUIDMixin


class DocumentType:
    PAYSLIP = "PAYSLIP"
    PAYMENT_ADVICE = "PAYMENT_ADVICE"


class Payslip(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "payslips"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    payroll_run_id: Mapped[int] = mapped_column(ForeignKey("payroll_runs.id"), index=True, nullable=False)
    engagement_id: Mapped[int] = mapped_column(ForeignKey("engagements.id"), index=True, nullable=False)
    person_id: Mapped[int] = mapped_column(ForeignKey("people.id"), index=True, nullable=False)

    document_type: Mapped[str] = mapped_column(String(20), default=DocumentType.PAYSLIP)
    version: Mapped[int] = mapped_column(Integer, default=1)
    is_current: Mapped[bool] = mapped_column(Boolean, default=True, index=True)
    status: Mapped[str] = mapped_column(String(20), default="ISSUED")  # ISSUED / SUPERSEDED

    net_pay: Mapped[float] = mapped_column(Numeric(14, 2), default=0)  # mirror for quick listing
    snapshot: Mapped[dict] = mapped_column(JSON, nullable=False)
    pdf_object_key: Mapped[str | None] = mapped_column(String(255), nullable=True)
    generated_by: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
