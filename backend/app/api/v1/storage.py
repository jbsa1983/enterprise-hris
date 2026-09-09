"""Real-time storage monitoring endpoint."""
from __future__ import annotations

from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_permission
from app.services import storage_service

router = APIRouter(prefix="/system", tags=["storage"])


@router.get("/storage", dependencies=[Depends(require_permission("storage.view"))])
def storage_overview(db: Session = Depends(get_db)) -> dict:
    overview = storage_service.get_storage_overview(db)
    overview["by_organization"] = storage_service.get_storage_by_organization(db)
    return overview
