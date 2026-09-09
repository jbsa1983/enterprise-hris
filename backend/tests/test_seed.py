"""Verify the production 'minimal' seed path: RBAC + a single Superadmin."""
from __future__ import annotations

from app.core.rbac import PERMISSIONS
from app.models.user import Permission, Role, User
from app.seed import bootstrap


def test_minimal_seed_creates_rbac_and_superadmin(db):
    bootstrap.seed_minimal(db)

    # All permission codes seeded.
    assert db.query(Permission).count() == len(PERMISSIONS)
    # Default roles present, including Super Admin.
    assert db.query(Role).filter(Role.name == "Super Admin").first() is not None
    # Exactly one active Superadmin user, holding the Super Admin role.
    admins = db.query(User).filter(User.is_superadmin.is_(True)).all()
    assert len(admins) == 1
    assert "Super Admin" in [r.name for r in admins[0].roles]
    # No demo organizations/people were created in minimal mode.
    from app.models.organization import Organization
    from app.models.person import Person
    # (conftest creates one test org/person; minimal seed must not add more.)
    assert db.query(Organization).count() == 1
    assert db.query(Person).count() == 1
