"""Storage metric snapshots (dashboard uses live values; these persist history)."""
from __future__ import annotations

from sqlalchemy import ForeignKey, Integer, Numeric, String
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin


class StorageMetric(Base, TimestampMixin):
    __tablename__ = "storage_metrics"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int | None] = mapped_column(ForeignKey("organizations.id"), nullable=True, index=True)
    category: Mapped[str] = mapped_column(String(50), nullable=False)  # documents/payroll/database/backup/...
    bytes_used: Mapped[float] = mapped_column(Numeric(20, 0), default=0)
