"""Configurable payroll bank-export templates, columns, and generated runs.

Templates are versioned; each generated file records the template + version, a
content hash, who generated it and when — so historical exports are auditable
and reproducible.
"""
from __future__ import annotations

from sqlalchemy import Boolean, ForeignKey, Integer, JSON, Numeric, String
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import TimestampMixin, UUIDMixin


class BankExportTemplate(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "bank_export_templates"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    template_name: Mapped[str] = mapped_column(String(120), nullable=False)
    bank_name: Mapped[str] = mapped_column(String(120), nullable=False)
    template_version: Mapped[int] = mapped_column(Integer, default=1)
    file_type: Mapped[str] = mapped_column(String(20), default="CSV")  # CSV/TXT/XLSX/FIXED_WIDTH
    delimiter: Mapped[str] = mapped_column(String(4), default=",")
    encoding: Mapped[str] = mapped_column(String(20), default="utf-8")
    header_required: Mapped[bool] = mapped_column(Boolean, default=True)
    footer_required: Mapped[bool] = mapped_column(Boolean, default=False)
    date_format: Mapped[str] = mapped_column(String(30), default="%Y-%m-%d")
    decimal_places: Mapped[int] = mapped_column(Integer, default=2)
    filename_pattern: Mapped[str] = mapped_column(String(120), default="{bank}_{org}_{date}")
    active: Mapped[bool] = mapped_column(Boolean, default=True)

    columns: Mapped[list["BankExportColumn"]] = relationship(
        back_populates="template", order_by="BankExportColumn.order_index", lazy="selectin"
    )


class BankExportColumn(Base, TimestampMixin):
    __tablename__ = "bank_export_columns"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    template_id: Mapped[int] = mapped_column(ForeignKey("bank_export_templates.id"), index=True, nullable=False)
    order_index: Mapped[int] = mapped_column(Integer, default=0)
    system_field: Mapped[str] = mapped_column(String(60), nullable=False)  # e.g. bank_account, net_pay
    output_header: Mapped[str] = mapped_column(String(60), nullable=False)
    default_value: Mapped[str | None] = mapped_column(String(120), nullable=True)
    formatting: Mapped[str | None] = mapped_column(String(60), nullable=True)  # e.g. upper, amount, date
    padding: Mapped[str | None] = mapped_column(String(30), nullable=True)     # e.g. "left:10:0"
    required: Mapped[bool] = mapped_column(Boolean, default=False)

    template: Mapped["BankExportTemplate"] = relationship(back_populates="columns")


class BankExportRun(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "bank_export_runs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    payroll_run_id: Mapped[int] = mapped_column(ForeignKey("payroll_runs.id"), index=True, nullable=False)
    template_id: Mapped[int] = mapped_column(ForeignKey("bank_export_templates.id"), nullable=False)
    template_version: Mapped[int] = mapped_column(Integer, nullable=False)
    file_name: Mapped[str] = mapped_column(String(160), nullable=False)
    file_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    row_count: Mapped[int] = mapped_column(Integer, default=0)
    total_amount: Mapped[float] = mapped_column(Numeric(16, 2), default=0)
    object_key: Mapped[str | None] = mapped_column(String(255), nullable=True)
    filters_json: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    generated_by: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
