"""Shared model mixins and column helpers."""
from __future__ import annotations

import uuid as uuid_lib
from datetime import datetime, timezone

from sqlalchemy import DateTime, String, func
from sqlalchemy.orm import Mapped, mapped_column


def _uuid() -> str:
    return str(uuid_lib.uuid4())


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


class TimestampMixin:
    """created_at / updated_at columns."""

    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )


class UUIDMixin:
    """Public-facing opaque identifier, distinct from the integer PK."""

    uuid: Mapped[str] = mapped_column(String(36), default=_uuid, unique=True, index=True, nullable=False)
