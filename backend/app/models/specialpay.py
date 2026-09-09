"""13th-month pay and bonus runs (HR-driven, bulk).

A run targets a set of engagements for a year. For 13TH_MONTH, a computed amount
is derived (total basic earned in the year / 12); HR may override per line.
For BONUS, HR enters amounts. Finalizing marks the run GENERATED.
"""
from __future__ import annotations

from sqlalchemy import ForeignKey, Integer, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import TimestampMixin, UUIDMixin


class SpecialPayType:
    THIRTEENTH_MONTH = "13TH_MONTH"
    BONUS = "BONUS"


class SpecialPayRun(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "special_pay_runs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    pay_type: Mapped[str] = mapped_column(String(20), nullable=False)  # 13TH_MONTH / BONUS
    name: Mapped[str] = mapped_column(String(120), nullable=False)
    year: Mapped[int] = mapped_column(Integer, nullable=False)
    status: Mapped[str] = mapped_column(String(20), default="DRAFT")  # DRAFT / GENERATED
    total_amount: Mapped[float] = mapped_column(Numeric(16, 2), default=0)

    lines: Mapped[list["SpecialPayLine"]] = relationship(back_populates="run")


class SpecialPayLine(Base, TimestampMixin):
    __tablename__ = "special_pay_lines"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    run_id: Mapped[int] = mapped_column(ForeignKey("special_pay_runs.id"), index=True, nullable=False)
    engagement_id: Mapped[int] = mapped_column(ForeignKey("engagements.id"), index=True, nullable=False)
    computed_amount: Mapped[float] = mapped_column(Numeric(14, 2), default=0)
    override_amount: Mapped[float | None] = mapped_column(Numeric(14, 2), nullable=True)
    remarks: Mapped[str | None] = mapped_column(Text, nullable=True)

    run: Mapped["SpecialPayRun"] = relationship(back_populates="lines")

    @property
    def final_amount(self) -> float:
        return float(self.override_amount if self.override_amount is not None else self.computed_amount or 0)
