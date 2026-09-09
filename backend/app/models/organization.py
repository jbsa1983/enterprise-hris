"""Enterprise / organization tenancy models.

Tenant isolation is enforced by `organization_id` on all org-scoped rows and by
the `OrganizationUser` access grants that gate which orgs a user may act on.
"""
from __future__ import annotations

from sqlalchemy import Boolean, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import TimestampMixin, UUIDMixin


class Enterprise(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "enterprises"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    code: Mapped[str] = mapped_column(String(50), unique=True, nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)

    organizations: Mapped[list["Organization"]] = relationship(back_populates="enterprise")


class Organization(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "organizations"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    enterprise_id: Mapped[int] = mapped_column(ForeignKey("enterprises.id"), index=True, nullable=False)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    code: Mapped[str] = mapped_column(String(50), unique=True, nullable=False)
    legal_name: Mapped[str | None] = mapped_column(String(255), nullable=True)
    tin: Mapped[str | None] = mapped_column(String(50), nullable=True)
    address: Mapped[str | None] = mapped_column(Text, nullable=True)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)

    enterprise: Mapped["Enterprise"] = relationship(back_populates="organizations")
    members: Mapped[list["OrganizationUser"]] = relationship(back_populates="organization")


class OrganizationUser(Base, TimestampMixin):
    """Grants a user access to an organization. Absence = no access."""

    __tablename__ = "organization_users"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), index=True, nullable=False)
    is_primary: Mapped[bool] = mapped_column(Boolean, default=False)

    organization: Mapped["Organization"] = relationship(back_populates="members")
    user: Mapped["User"] = relationship(back_populates="org_memberships")  # noqa: F821
