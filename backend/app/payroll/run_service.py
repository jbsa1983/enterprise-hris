"""Payroll run orchestration: load eligible personnel, compute, and post the
obligation ledger. Idempotent — recomputing an unlocked run first reverses its
prior results so numbers never double-count.
"""
from __future__ import annotations

from datetime import date

from sqlalchemy.orm import Session

from app.models.enums import EngagementStatus, PayrollStatus
from app.models.loan import Loan, LoanTransaction
from app.models.payroll import PayrollRun, PayrollRunPerson
from app.models.person import Engagement
from app.payroll import engine as payroll_engine
from app.statutory import service as stat

_LOCKED = {PayrollStatus.LOCKED, PayrollStatus.PAID, PayrollStatus.CLOSED, PayrollStatus.BANK_FILE_GENERATED}


def _reverse_prior(db: Session, run: PayrollRun) -> None:
    """Undo a previous computation of this run (restore loan balances, drop lines)."""
    txns = db.query(LoanTransaction).filter(LoanTransaction.payroll_run_id == run.id).all()
    for t in txns:
        loan = db.get(Loan, t.loan_id)
        if loan and t.entry_type == "PAYROLL_DEDUCTION":
            loan.balance = float(loan.balance or 0) + float(t.amount or 0)
            loan.amount_paid = max(float(loan.amount_paid or 0) - float(t.amount or 0), 0)
        db.delete(t)
    db.query(PayrollRunPerson).filter(PayrollRunPerson.run_id == run.id).delete()
    db.flush()


def compute_run(db: Session, run: PayrollRun, allowance: float = 0.0) -> PayrollRun:
    if run.status in _LOCKED:
        raise ValueError("Locked payroll cannot be recomputed")

    _reverse_prior(db, run)
    period = run.period
    run.rule_version_snapshot = stat.snapshot_versions(db, period.period_end)

    engagements = (
        db.query(Engagement)
        .filter(Engagement.organization_id == run.organization_id,
                Engagement.status == EngagementStatus.ACTIVE)
        .all()
    )

    gross_total = ded_total = net_total = 0.0
    for eng in engagements:
        loans = (
            db.query(Loan)
            .filter(Loan.person_id == eng.person_id, Loan.payroll_deductible.is_(True),
                    Loan.balance > 0, Loan.status == "ACTIVE")
            .all()
        )
        installments: dict[str, float] = {}
        pay_plan: list[tuple[Loan, float]] = []
        for loan in loans:
            amt = min(float(loan.installment_amount or 0), float(loan.balance or 0))
            if amt > 0:
                installments[loan.obligation_type.lower()] = installments.get(loan.obligation_type.lower(), 0) + amt
                pay_plan.append((loan, amt))

        line = payroll_engine.compute_line(db, eng, period.period_end, allowance=allowance,
                                           obligation_installments=installments)
        db.add(PayrollRunPerson(
            run_id=run.id, engagement_id=eng.id, gross_pay=line["gross_pay"],
            total_deductions=line["total_deductions"], net_pay=line["net_pay"],
            earnings=line["earnings"], deductions=line["deductions"],
        ))
        gross_total += line["gross_pay"]
        ded_total += line["total_deductions"]
        net_total += line["net_pay"]

        # Post ledger deductions (only if the net actually covered them).
        if line["net_pay"] >= 0:
            for loan, amt in pay_plan:
                loan.amount_paid = float(loan.amount_paid or 0) + amt
                loan.balance = float(loan.balance or 0) - amt
                if loan.balance <= 0:
                    loan.status = "PAID"
                db.add(LoanTransaction(
                    loan_id=loan.id, entry_type="PAYROLL_DEDUCTION", amount=amt,
                    balance_after=loan.balance, payroll_run_id=run.id, period_label=period.name,
                    entry_date=period.period_end,
                ))

    run.gross_total = round(gross_total, 2)
    run.deduction_total = round(ded_total, 2)
    run.net_total = round(net_total, 2)
    run.status = PayrollStatus.CALCULATED
    db.flush()
    return run
