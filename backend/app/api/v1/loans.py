"""Loans, cash advances, and other employee obligations (org-scoped).

Backs the Loans & Advances tabs. Every balance change writes a ledger entry.
"""
from __future__ import annotations

from datetime import date

from fastapi import APIRouter, Body, Depends, HTTPException, Request
from sqlalchemy import func
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.loan import Loan, LoanTransaction
from app.models.person import Person
from app.models.user import User
from app.services import audit_service

router = APIRouter(prefix="/organizations/{organization_id}/loans", tags=["loans"])

_INCREASE = {"CHARGE", "INTEREST", "NEW_LOAN", "NEW_ADVANCE", "OPENING_BALANCE"}
_DECREASE = {"PAYROLL_DEDUCTION", "DIRECT_PAYMENT", "LIQUIDATION"}


def _loan_dict(db: Session, ln: Loan) -> dict:
    person = db.get(Person, ln.person_id)
    return {
        "id": ln.id, "uuid": ln.uuid, "person_id": ln.person_id,
        "person": person.full_name if person else None,
        "obligation_type": ln.obligation_type, "reference_number": ln.reference_number,
        "description": ln.description, "principal": float(ln.principal or 0),
        "interest": float(ln.interest or 0), "total_amount": float(ln.total_amount or 0),
        "amount_paid": float(ln.amount_paid or 0), "balance": float(ln.balance or 0),
        "installment_amount": float(ln.installment_amount or 0), "status": ln.status,
        "payroll_deductible": ln.payroll_deductible,
    }


@router.get("/summary", dependencies=[Depends(require_permission("loan.view"))])
def summary(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = (
        db.query(Loan.obligation_type, func.count(Loan.id), func.coalesce(func.sum(Loan.balance), 0))
        .filter(Loan.organization_id == organization_id)
        .group_by(Loan.obligation_type).all()
    )
    return [{"obligation_type": t, "count": c, "outstanding": float(b or 0)} for t, c, b in rows]


@router.get("", dependencies=[Depends(require_permission("loan.view"))])
def list_loans(organization_id: int, obligation_type: str | None = None, status: str | None = None,
               _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    q = db.query(Loan).filter(Loan.organization_id == organization_id)
    if obligation_type:
        q = q.filter(Loan.obligation_type == obligation_type)
    if status:
        q = q.filter(Loan.status == status)
    return [_loan_dict(db, ln) for ln in q.order_by(Loan.id.desc()).all()]


@router.get("/{loan_id}", dependencies=[Depends(require_permission("loan.view"))])
def get_loan(organization_id: int, loan_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    ln = db.get(Loan, loan_id)
    if not ln or ln.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Loan not found")
    txns = db.query(LoanTransaction).filter(LoanTransaction.loan_id == loan_id).order_by(LoanTransaction.id).all()
    d = _loan_dict(db, ln)
    d["ledger"] = [{"entry_type": t.entry_type, "amount": float(t.amount or 0),
                    "balance_after": float(t.balance_after or 0),
                    "period_label": t.period_label,
                    "date": t.entry_date.isoformat() if t.entry_date else None,
                    "remarks": t.remarks} for t in txns]
    return d


@router.post("", dependencies=[Depends(require_permission("loan.create"))])
def create_loan(organization_id: int, request: Request, payload: dict = Body(...),
                _: int = Depends(require_org_access),
                user: User = Depends(require_permission("loan.create")), db: Session = Depends(get_db)):
    principal = float(payload.get("principal", 0))
    interest = float(payload.get("interest", 0))
    total = principal + interest
    ln = Loan(
        organization_id=organization_id, person_id=payload["person_id"],
        engagement_id=payload.get("engagement_id"),
        obligation_type=payload.get("obligation_type", "COMPANY_LOAN"),
        reference_number=payload.get("reference_number"), description=payload.get("description"),
        principal=principal, interest=interest, total_amount=total, amount_paid=0, balance=total,
        installment_amount=float(payload.get("installment_amount", 0)),
        start_period=payload.get("start_period"), end_period=payload.get("end_period"),
        status="ACTIVE", payroll_deductible=payload.get("payroll_deductible", True),
        direct_payment_allowed=payload.get("direct_payment_allowed", False),
    )
    db.add(ln)
    db.flush()
    db.add(LoanTransaction(loan_id=ln.id, entry_type="NEW_LOAN", amount=total, balance_after=total,
                           entry_date=date.today(), remarks="Loan/advance granted"))
    audit_service.record(db, action="loan.create", user=user, organization_id=organization_id,
                         entity="loan", entity_id=ln.id, after={"type": ln.obligation_type, "amount": total},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    return {"id": ln.id}


@router.post("/{loan_id}/adjust", dependencies=[Depends(require_permission("loan.adjust"))])
def adjust_loan(organization_id: int, loan_id: int, request: Request, payload: dict = Body(...),
                _: int = Depends(require_org_access),
                user: User = Depends(require_permission("loan.adjust")), db: Session = Depends(get_db)):
    ln = db.get(Loan, loan_id)
    if not ln or ln.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Loan not found")
    entry_type = payload.get("entry_type", "DIRECT_PAYMENT").upper()
    amount = float(payload.get("amount", 0))
    if amount <= 0:
        raise HTTPException(status_code=422, detail="amount must be positive")
    if entry_type in _DECREASE:
        ln.balance = float(ln.balance or 0) - amount
        ln.amount_paid = float(ln.amount_paid or 0) + amount
    elif entry_type in _INCREASE:
        ln.balance = float(ln.balance or 0) + amount
        ln.total_amount = float(ln.total_amount or 0) + amount
    else:  # ADJUSTMENT / REVERSAL — signed via 'direction'
        ln.balance = float(ln.balance or 0) + (amount if payload.get("direction") == "increase" else -amount)
    if ln.balance <= 0:
        ln.balance = 0
        ln.status = "PAID"
    db.add(LoanTransaction(loan_id=ln.id, entry_type=entry_type, amount=amount, balance_after=ln.balance,
                           entry_date=date.today(), period_label=payload.get("period_label"),
                           remarks=payload.get("remarks")))
    audit_service.record(db, action="loan.adjust", user=user, organization_id=organization_id,
                         entity="loan", entity_id=ln.id, after={"entry": entry_type, "amount": amount,
                         "balance": float(ln.balance)},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    return {"id": ln.id, "balance": float(ln.balance), "status": ln.status}
