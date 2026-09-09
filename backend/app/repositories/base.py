"""Generic repository base for data-access separation.

Services depend on repositories rather than issuing queries inline, keeping org
scoping and query construction in one place. Extend per-entity as phases grow.
"""
from __future__ import annotations

from typing import Generic, TypeVar

from sqlalchemy.orm import Session

from app.core.database import Base

ModelT = TypeVar("ModelT", bound=Base)


class BaseRepository(Generic[ModelT]):
    model: type[ModelT]

    def __init__(self, db: Session):
        self.db = db

    def get(self, id_: int) -> ModelT | None:
        return self.db.get(self.model, id_)

    def list(self, limit: int = 100, offset: int = 0) -> list[ModelT]:
        return self.db.query(self.model).offset(offset).limit(limit).all()

    def add(self, obj: ModelT) -> ModelT:
        self.db.add(obj)
        self.db.flush()
        return obj
