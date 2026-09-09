"""Tests for historical obligation balances, bank-export validation, and the
report builder (Phase 4/5 critical logic)."""
from __future__ import annotations

from datetime import date

from app.models.loan import Loan, LoanTransaction
from app.reports import bank_export, report_builder
from app.services import obligation_service


def _loan_with_ledger(db):
    """Loan of 5,000; one 1,500 deduction dated in June."""
    loan = Loan(
        organization_id=db._org_id, person_id=db._person_id, obligation_type="CASH_ADVANCE",
        description="Cash Advance", principal=5000, total_amount=5000, amount_paid=1500, balance=3500,
        installment_amount=1500, status="ACTIVE", payroll_deductible=True,
    )
    db.add(loan)
    db.flush()
    db.add(LoanTransaction(loan_id=loan.id, entry_type="NEW_LOAN", amount=5000, balance_after=5000,
                           entry_date=date(2024, 3, 1)))
    db.add(LoanTransaction(loan_id=loan.id, entry_type="PAYROLL_DEDUCTION", amount=1500, balance_after=3500,
                           payroll_run_id=99, period_label="June 2024", entry_date=date(2024, 6, 30)))
    db.flush()
    return loan


def test_historical_obligation_balance_as_of(db):
    _loan_with_ledger(db)
    summary = obligation_service.obligation_summary_as_of(
        db, engagement_id=1, person_id=db._person_id, payroll_run_id=99, period_end=date(2024, 6, 30),
    )
    assert len(summary["rows"]) == 1
    row = summary["rows"][0]
    assert row["original_amount"] == 5000
    assert row["current_deduction"] == 1500
    assert row["total_paid"] == 1500
    assert row["remaining_balance"] == 3500
    assert row["remaining_installments"] == 3  # ceil(3500/1500)
    assert summary["total_outstanding"] == 3500


def test_obligation_balance_before_loan_existed_is_empty(db):
    _loan_with_ledger(db)
    # As of a date BEFORE the loan was granted, nothing should appear.
    summary = obligation_service.obligation_summary_as_of(
        db, engagement_id=1, person_id=db._person_id, payroll_run_id=99, period_end=date(2024, 1, 1),
    )
    assert summary["rows"] == []
    assert summary["total_outstanding"] == 0


def test_obligation_balance_excludes_future_deduction(db):
    _loan_with_ledger(db)
    # As of May (before the June deduction), nothing has been paid yet.
    summary = obligation_service.obligation_summary_as_of(
        db, engagement_id=1, person_id=db._person_id, payroll_run_id=99, period_end=date(2024, 5, 31),
    )
    row = summary["rows"][0]
    assert row["total_paid"] == 0
    assert row["remaining_balance"] == 5000


def test_bank_export_validation_flags():
    rows = [
        {"name": "Good One", "fields": {"bank_account": "1234567890", "net_pay": 10000}},
        {"name": "No Bank", "fields": {"bank_account": "", "net_pay": 5000}},
        {"name": "Bad Acct", "fields": {"bank_account": "12A", "net_pay": 5000}},
        {"name": "Dup A", "fields": {"bank_account": "5555555555", "net_pay": 5000}},
        {"name": "Dup B", "fields": {"bank_account": "5555555555", "net_pay": 5000}},
        {"name": "Zero Pay", "fields": {"bank_account": "9999999999", "net_pay": 0}},
    ]
    v = bank_export.validate(rows)
    assert v["has_critical"] is True
    assert "No Bank" in v["missing_bank"]
    assert any("Bad Acct" in x for x in v["invalid_accounts"])
    assert "5555555555" in v["duplicate_accounts"]
    assert "Zero Pay" in v["zero_or_negative"]


def test_bank_export_validation_clean():
    rows = [{"name": "A", "fields": {"bank_account": "1111111111", "net_pay": 100}},
            {"name": "B", "fields": {"bank_account": "2222222222", "net_pay": 200}}]
    v = bank_export.validate(rows)
    assert v["has_critical"] is False
    assert v["errors"] == []


def test_report_builder_filter_and_sort():
    rows = [
        {"name": "A", "base_rate": 20000, "status": "ACTIVE"},
        {"name": "B", "base_rate": 50000, "status": "ACTIVE"},
        {"name": "C", "base_rate": 30000, "status": "INACTIVE"},
    ]
    cols, out = report_builder._apply(rows, {
        "filters": [{"field": "status", "op": "eq", "value": "ACTIVE"}],
        "sort": {"field": "base_rate", "dir": "desc"},
    })
    assert [r["name"] for r in out] == ["B", "A"]
    assert "base_rate" in cols
