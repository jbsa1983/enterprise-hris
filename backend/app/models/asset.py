"""Company assets and employee-payable asset installments."""
from __future__ import annotations

from datetime import date

from sqlalchemy import Boolean, Date, ForeignKey, Integer, Numeric, String
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin


class Asset(Base, TimestampMixin):
    __tablename__ = "assets"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    asset_number: Mapped[str] = mapped_column(String(80), nullable=False)
    item: Mapped[str] = mapped_column(String(150), nullable=False)
    serial_number: Mapped[str | None] = mapped_column(String(120), nullable=True)
    # True when the asset creates a payroll receivable (installment purchase).
    is_employee_payable: Mapped[bool] = mapped_column(Boolean, default=False)

    assigned_person_id: Mapped[int | None] = mapped_column(ForeignKey("people.id"), nullable=True)
    issue_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    cost: Mapped[float | None] = mapped_column(Numeric(14, 2), nullable=True)
    employee_share: Mapped[float | None] = mapped_column(Numeric(14, 2), nullable=True)
    installment: Mapped[float | None] = mapped_column(Numeric(14, 2), nullable=True)
    outstanding_balance: Mapped[float | None] = mapped_column(Numeric(14, 2), nullable=True)
    returned_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    condition: Mapped[str | None] = mapped_column(String(50), nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="ISSUED")
