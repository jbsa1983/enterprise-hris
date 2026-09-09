"""Historical obligation balances.

Spec requirement (§21): a payslip's outstanding-obligations summary must show the
balance *as of the selected payroll period*, NOT the current live balance. We
reconstruct it from the immutable ledger (`loan_transactions`) by counting only
entries dated on/before the period end.
"""
from __future__ import annotations

import math
from datetime import date

from sqlalchemy.orm import Session

from app.models.loan import Loan, LoanTransaction

_PAID_ENTRY_TYPES = ("PAYROLL_DEDUCTION", "DIRECT_PAYMENT", "LIQUIDATION")


def obligation_summary_as_of(
    db: Session,
    *,
    engagement_id: int,
    person_id: int,
    payroll_run_id: int,
    period_end: date,
) -> dict:
    """Return {rows:[...], total_outstanding, total_current_deduction}."""
    loans = (
        db.query(Loan)
        .filter(Loan.person_id == person_id)
        .filter((Loan.engagement_id == engagement_id) | (Loan.engagement_id.is_(None)))
        .all()
    )

    rows: list[dict] = []
    total_outstanding = 0.0
    total_current = 0.0

    for loan in loans:
        txns = (
            db.query(LoanTransaction)
            .filter(LoanTransaction.loan_id == loan.id)
            .order_by(LoanTransaction.id)
            .all()
        )
        # Only obligations that existed on/before the period.
        origin_dates = [t.entry_date for t in txns if t.entry_date is not None]
        if origin_dates and min(origin_dates) > period_end:
            continue

        original = float(loan.total_amount or 0)
        total_paid_as_of = sum(
            float(t.amount or 0)
            for t in txns
            if t.entry_type in _PAID_ENTRY_TYPES and (t.entry_date is None or t.entry_date <= period_end)
        )
        current_deduction = sum(
            float(t.amount or 0)
            for t in txns
            if t.entry_type == "PAYROLL_DEDUCTION" and t.payroll_run_id == payroll_run_id
        )
        remaining = round(max(original - total_paid_as_of, 0.0), 2)
        installment = float(loan.installment_amount or 0)
        remaining_installments = math.ceil(remaining / installment) if installment > 0 and remaining > 0 else 0

        rows.append(
            {
                "description": loan.description or loan.obligation_type,
                "obligation_type": loan.obligation_type,
                "reference_number": loan.reference_number,
                "original_amount": round(original, 2),
                "current_deduction": round(current_deduction, 2),
                "total_paid": round(total_paid_as_of, 2),
                "remaining_balance": remaining,
                "remaining_installments": remaining_installments,
            }
        )
        total_outstanding += remaining
        total_current += current_deduction

    return {
        "rows": rows,
        "total_outstanding": round(total_outstanding, 2),
        "total_current_deduction": round(total_current, 2),
    }
