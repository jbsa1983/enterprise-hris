"""Asset accountability (Phase 6). Company assets vs. employee-payable assets."""
from __future__ import annotations

from datetime import date

from fastapi import APIRouter, Body, Depends, HTTPException
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.asset import Asset

router = APIRouter(prefix="/organizations/{organization_id}/assets", tags=["assets"])


@router.get("", dependencies=[Depends(require_permission("employee.view"))])
def list_assets(
    organization_id: int, person_id: int | None = None,
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    q = db.query(Asset).filter(Asset.organization_id == organization_id)
    if person_id:
        q = q.filter(Asset.assigned_person_id == person_id)
    rows = q.order_by(Asset.id.desc()).all()
    return [
        {"id": a.id, "asset_number": a.asset_number, "item": a.item, "serial_number": a.serial_number,
         "is_employee_payable": a.is_employee_payable, "assigned_person_id": a.assigned_person_id,
         "cost": float(a.cost or 0), "employee_share": float(a.employee_share or 0),
         "installment": float(a.installment or 0), "outstanding_balance": float(a.outstanding_balance or 0),
         "status": a.status, "condition": a.condition}
        for a in rows
    ]


@router.post("", dependencies=[Depends(require_permission("employee.edit"))])
def create_asset(
    organization_id: int, payload: dict = Body(...),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    a = Asset(
        organization_id=organization_id,
        asset_number=payload["asset_number"], item=payload["item"],
        serial_number=payload.get("serial_number"),
        is_employee_payable=payload.get("is_employee_payable", False),
        assigned_person_id=payload.get("assigned_person_id"),
        issue_date=date.today(), cost=payload.get("cost"),
        employee_share=payload.get("employee_share"), installment=payload.get("installment"),
        outstanding_balance=payload.get("employee_share") if payload.get("is_employee_payable") else None,
        status="ISSUED",
    )
    db.add(a)
    db.commit()
    return {"id": a.id}


@router.put("/{asset_id}", dependencies=[Depends(require_permission("employee.edit"))])
def update_asset(
    organization_id: int, asset_id: int, payload: dict = Body(...),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    a = db.get(Asset, asset_id)
    if not a or a.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Asset not found")
    for f in ("asset_number", "item", "serial_number", "is_employee_payable", "assigned_person_id",
              "cost", "employee_share", "installment", "outstanding_balance", "condition", "status"):
        if f in payload and payload[f] is not None:
            setattr(a, f, payload[f])
    db.commit()
    return {"id": a.id, "item": a.item, "status": a.status}


@router.delete("/{asset_id}", dependencies=[Depends(require_permission("employee.edit"))])
def delete_asset(
    organization_id: int, asset_id: int,
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    a = db.get(Asset, asset_id)
    if not a or a.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Asset not found")
    db.delete(a)
    db.commit()
    return {"deleted": asset_id}


@router.post("/{asset_id}/return", dependencies=[Depends(require_permission("employee.edit"))])
def return_asset(
    organization_id: int, asset_id: int, payload: dict = Body(default={}),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    a = db.get(Asset, asset_id)
    if not a or a.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Asset not found")
    a.status = "RETURNED"
    a.returned_date = date.today()
    a.condition = payload.get("condition", "GOOD")
    db.commit()
    return {"id": a.id, "status": a.status}
