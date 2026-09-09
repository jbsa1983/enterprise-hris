"""Individual payslip access + ESS. Access allowed to org staff or the owner."""
from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, Request, Response
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_user
from app.models.payslip import Payslip
from app.models.person import Engagement
from app.models.user import User
from app.payroll import payslip_service
from app.services import audit_service

router = APIRouter(tags=["payslips"])


def _payslip_or_403(db: Session, uuid: str, user: User) -> Payslip:
    p = db.query(Payslip).filter(Payslip.uuid == uuid).first()
    if not p:
        raise HTTPException(status_code=404, detail="Payslip not found")
    allowed = (
        user.is_superadmin
        or p.organization_id in user.accessible_org_ids
        or (user.person_id is not None and p.person_id == user.person_id)
    )
    if not allowed:
        raise HTTPException(status_code=403, detail="Not authorized to view this payslip")
    return p


@router.get("/payslips/{uuid}")
def get_payslip(uuid: str, user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> dict:
    p = _payslip_or_403(db, uuid, user)
    return {
        "uuid": p.uuid,
        "document_type": p.document_type,
        "version": p.version,
        "is_current": p.is_current,
        "status": p.status,
        "snapshot": p.snapshot,
    }


@router.get("/payslips/{uuid}/pdf")
def get_payslip_pdf(
    uuid: str, request: Request,
    user: User = Depends(get_current_user), db: Session = Depends(get_db),
) -> Response:
    p = _payslip_or_403(db, uuid, user)
    data = payslip_service.get_pdf_bytes(db, p)
    audit_service.record(
        db, action="payslip.view", user=user, organization_id=p.organization_id,
        entity="payslip", entity_id=p.uuid,
        ip_address=request.client.host if request.client else None,
    )
    label = "payment_advice" if p.document_type == "PAYMENT_ADVICE" else "payslip"
    return Response(
        content=data,
        media_type="application/pdf",
        headers={"Content-Disposition": f'inline; filename="{label}_{uuid}.pdf"'},
    )


# --- ESS (self-service) ------------------------------------------------------
ess_router = APIRouter(prefix="/me", tags=["ess"])


@ess_router.get("/payslips")
def my_payslips(user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> list[dict]:
    if user.person_id is None:
        return []
    rows = (
        db.query(Payslip)
        .filter(Payslip.person_id == user.person_id, Payslip.is_current.is_(True))
        .order_by(Payslip.id.desc())
        .all()
    )
    return [
        {
            "uuid": p.uuid,
            "document_type": p.document_type,
            "period": p.snapshot.get("header", {}).get("payroll_period"),
            "net_pay": float(p.net_pay or 0),
            "has_pdf": bool(p.pdf_object_key),
        }
        for p in rows
    ]


@ess_router.get("/engagements")
def my_engagements(user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> list[dict]:
    if user.person_id is None:
        return []
    engs = db.query(Engagement).filter(Engagement.person_id == user.person_id).all()
    return [
        {
            "id": e.id,
            "organization_id": e.organization_id,
            "engagement_type": e.engagement_type,
            "employee_number": e.employee_number,
            "status": e.status,
        }
        for e in engs
    ]
