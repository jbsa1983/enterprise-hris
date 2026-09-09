"""Test fixtures: in-memory SQLite DB seeded with prototype statutory rules."""
from __future__ import annotations

from datetime import date

import pytest
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker
from sqlalchemy.pool import StaticPool

from app.core.database import Base
import app.models  # noqa: F401  (register all tables)
from app.models.organization import Enterprise, Organization
from app.models.payroll import StatutoryRuleSet
from app.models.person import Person
from app.seed.data import STATUTORY_RULESETS


@pytest.fixture()
def db():
    engine = create_engine(
        "sqlite://",
        connect_args={"check_same_thread": False},
        poolclass=StaticPool,
    )
    Base.metadata.create_all(engine)
    Session = sessionmaker(bind=engine, autoflush=False, future=True)
    session = Session()

    # Statutory rule sets (effective 2024-01-01).
    for rs in STATUTORY_RULESETS:
        session.add(
            StatutoryRuleSet(
                rule_name=rs["rule_name"],
                rule_version=rs["rule_version"],
                effective_from=date(2024, 1, 1),
                is_prototype_data=True,
                parameters_json=rs["parameters_json"],
            )
        )
    ent = Enterprise(name="Test Group", code="TST")
    session.add(ent)
    session.flush()
    org = Organization(enterprise_id=ent.id, name="Test Org", code="TSTORG")
    session.add(org)
    person = Person(first_name="Test", last_name="Person")
    session.add(person)
    session.flush()
    session._org_id = org.id  # type: ignore[attr-defined]
    session._person_id = person.id  # type: ignore[attr-defined]

    yield session
    session.close()
