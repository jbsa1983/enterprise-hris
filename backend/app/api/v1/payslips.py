"""Individual payslip access + ESS. Access allowed to org staff or the owner."""
from __future__ import annotations

from fastapi import APIRouter, Body, Depends, HTTPException, Request, Response
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_user
from app.core.security import hash_password, verify_password
from app.models.attendance import AttendanceLog
from app.models.enums import PayrollStatus
from app.models.hr import LeaveRequest
from app.models.loan import Loan
from app.models.organization import Organization
from app.models.payroll import PayrollRun, PayrollRunPerson
from app.models.payslip import Payslip
from app.models.person import Engagement, Person
from app.models.user import User
from app.payroll import payslip_service
from app.services import audit_service

# Payroll statuses an employee is allowed to self-generate a payslip from.
_FINALIZED = {PayrollStatus.APPROVED, PayrollStatus.LOCKED, PayrollStatus.BANK_FILE_GENERATED,
              PayrollStatus.PAID, PayrollStatus.CLOSED}

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


def _require_person(user: User) -> int:
    if user.person_id is None:
        raise HTTPException(status_code=404, detail="No employee record linked to your account")
    return user.person_id


def _my_engagement_ids(db: Session, person_id: int) -> list[int]:
    return [e.id for e in db.query(Engagement.id).filter(Engagement.person_id == person_id).all()]


@ess_router.get("/profile")
def my_profile(user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> dict:
    pid = _require_person(user)
    person = db.get(Person, pid)
    engs = db.query(Engagement).filter(Engagement.person_id == pid).all()
    return {
        "name": person.full_name,
        "email": person.email,
        "mobile": person.mobile,
        "address": person.address,
        "government_ids": {"tin": person.tin, "sss": person.sss_number,
                           "philhealth": person.philhealth_number, "pagibig": person.pagibig_number},
        "bank": {"name": person.bank_name,
                 "account_masked": ("*" * 6 + person.bank_account_number[-4:]) if person.bank_account_number else None},
        "engagements": [
            {"id": e.id, "organization_id": e.organization_id,
             "organization": (db.get(Organization, e.organization_id).name if e.organization_id else None),
             "employee_number": e.employee_number, "engagement_type": e.engagement_type,
             "status": e.status, "base_rate": float(e.base_rate) if e.base_rate is not None else None}
            for e in engs
        ],
    }


@ess_router.get("/available-payslips")
def my_available_payslips(user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> list[dict]:
    """Finalized payroll runs where the employee has a computed line."""
    pid = _require_person(user)
    eng_ids = _my_engagement_ids(db, pid)
    if not eng_ids:
        return []
    rows = (
        db.query(PayrollRunPerson, PayrollRun)
        .join(PayrollRun, PayrollRun.id == PayrollRunPerson.run_id)
        .filter(PayrollRunPerson.engagement_id.in_(eng_ids), PayrollRun.status.in_(list(_FINALIZED)))
        .order_by(PayrollRun.id.desc())
        .all()
    )
    out = []
    for line, run in rows:
        existing = (
            db.query(Payslip)
            .filter(Payslip.payroll_run_id == run.id, Payslip.engagement_id == line.engagement_id,
                    Payslip.is_current.is_(True))
            .first()
        )
        out.append({
            "run_id": run.id, "engagement_id": line.engagement_id,
            "period": run.period.name if run.period else None, "reference": run.reference,
            "net_pay": float(line.net_pay or 0),
            "payslip_uuid": existing.uuid if existing else None,
        })
    return out


@ess_router.post("/payslips/generate")
def generate_my_payslip(request: Request, payload: dict = Body(...),
                        user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> dict:
    """Employee self-generates their own payslip for a finalized run."""
    pid = _require_person(user)
    run = db.get(PayrollRun, payload.get("run_id"))
    if not run:
        raise HTTPException(status_code=404, detail="Payroll run not found")
    if run.status not in _FINALIZED:
        raise HTTPException(status_code=409, detail="Payslip not yet available for this period")
    # Find the employee's own line in this run.
    eng_ids = _my_engagement_ids(db, pid)
    line = (
        db.query(PayrollRunPerson)
        .filter(PayrollRunPerson.run_id == run.id, PayrollRunPerson.engagement_id.in_(eng_ids))
        .first()
    )
    if not line:
        raise HTTPException(status_code=404, detail="You have no payroll record in this run")
    payslip = payslip_service.generate_for_engagement(db, run, line.engagement_id, user_id=user.id)
    audit_service.record(db, action="payslip.self_generate", user=user, organization_id=run.organization_id,
                         entity="payslip", entity_id=payslip.uuid,
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    return {"uuid": payslip.uuid, "period": payslip.snapshot.get("header", {}).get("payroll_period")}


@ess_router.get("/leave")
def my_leave(user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> list[dict]:
    pid = _require_person(user)
    eng_ids = _my_engagement_ids(db, pid) or [-1]
    rows = db.query(LeaveRequest).filter(LeaveRequest.engagement_id.in_(eng_ids)).order_by(LeaveRequest.id.desc()).all()
    return [{"id": r.id, "leave_type": r.leave_type,
             "date_from": r.date_from.isoformat() if r.date_from else None,
             "date_to": r.date_to.isoformat() if r.date_to else None,
             "days": float(r.days or 0), "status": r.status} for r in rows]


@ess_router.get("/attendance")
def my_attendance(user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> list[dict]:
    pid = _require_person(user)
    eng_ids = _my_engagement_ids(db, pid) or [-1]
    rows = (db.query(AttendanceLog).filter(AttendanceLog.engagement_id.in_(eng_ids))
            .order_by(AttendanceLog.log_date.desc()).limit(60).all())
    return [{"log_date": r.log_date.isoformat(), "hours_worked": float(r.hours_worked or 0),
             "late_minutes": r.late_minutes, "overtime_hours": float(r.overtime_hours or 0),
             "status": r.status} for r in rows]


@ess_router.get("/loans")
def my_loans(user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> list[dict]:
    pid = _require_person(user)
    rows = db.query(Loan).filter(Loan.person_id == pid).order_by(Loan.id.desc()).all()
    return [{"type": ln.obligation_type, "reference": ln.reference_number, "description": ln.description,
             "principal": float(ln.principal or 0), "balance": float(ln.balance or 0),
             "installment": float(ln.installment_amount or 0), "status": ln.status} for ln in rows]


@ess_router.get("/contributions")
def my_contributions(user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> list[dict]:
    """Government contributions taken per finalized payroll run (from payslip lines)."""
    pid = _require_person(user)
    eng_ids = _my_engagement_ids(db, pid) or [-1]
    rows = (
        db.query(PayrollRunPerson, PayrollRun)
        .join(PayrollRun, PayrollRun.id == PayrollRunPerson.run_id)
        .filter(PayrollRunPerson.engagement_id.in_(eng_ids))
        .order_by(PayrollRun.id.desc()).all()
    )
    out = []
    for line, run in rows:
        d = line.deductions or {}
        out.append({"period": run.period.name if run.period else None,
                    "sss": float(d.get("sss", 0)), "philhealth": float(d.get("philhealth", 0)),
                    "pagibig": float(d.get("pagibig", 0)), "withholding_tax": float(d.get("withholding_tax", 0))})
    return out


@ess_router.post("/password")
def change_my_password(payload: dict = Body(...), user: User = Depends(get_current_user),
                       db: Session = Depends(get_db)) -> dict:
    """Employee changes their own password (requires the current one)."""
    current = payload.get("current_password", "")
    new = payload.get("new_password", "")
    if not verify_password(current, user.hashed_password):
        raise HTTPException(status_code=403, detail="Current password is incorrect")
    if len(new) < 8:
        raise HTTPException(status_code=422, detail="New password must be at least 8 characters")
    user.hashed_password = hash_password(new)
    db.commit()
    return {"ok": True}
