"""Payroll engine + statutory computation tests (prototype values)."""
from __future__ import annotations

from datetime import date

from app.models.enums import EngagementType
from app.models.person import Engagement
from app.payroll import engine as payroll_engine
from app.statutory import service as stat

ON = date(2024, 6, 30)


def _engagement(db, etype, salary_basis, base_rate):
    eng = Engagement(
        person_id=db._person_id,
        organization_id=db._org_id,
        engagement_type=etype,
        salary_basis=salary_basis,
        base_rate=base_rate,
        status="ACTIVE",
    )
    db.add(eng)
    db.flush()
    return eng


def test_monthly_employee_deductions(db):
    eng = _engagement(db, EngagementType.REGULAR, "MONTHLY", 30000)
    line = payroll_engine.compute_line(db, eng, ON, allowance=2000)
    assert line["gross_pay"] == 32000
    d = line["deductions"]
    assert d["sss"] == 30000 * 0.045
    assert d["philhealth"] == 30000 * 0.025
    assert d["pagibig"] == 200  # capped
    assert line["net_pay"] == round(line["gross_pay"] - line["total_deductions"], 2)


def test_semi_monthly_still_computes(db):
    eng = _engagement(db, EngagementType.PROBATIONARY, "MONTHLY", 26000)
    line = payroll_engine.compute_line(db, eng, ON, allowance=0)
    assert line["gross_pay"] == 26000
    assert line["net_pay"] > 0


def test_daily_paid_employee(db):
    eng = _engagement(db, EngagementType.DAILY_PAID, "DAILY", 700)
    line = payroll_engine.compute_line(db, eng, ON)
    # Daily rate is normalized to a monthly-equivalent (rate * 22 working days)
    # for both the statutory bases and the basic earning.
    assert line["gross_pay"] == 700 * 22
    assert "sss" in line["deductions"]
    assert line["net_pay"] <= line["gross_pay"]


def test_project_based_employee(db):
    eng = _engagement(db, EngagementType.PROJECT_BASED, "DAILY", 1000)
    line = payroll_engine.compute_line(db, eng, ON)
    assert line["net_pay"] <= line["gross_pay"]


def test_consultant_has_ewt_not_statutory(db):
    eng = _engagement(db, EngagementType.CONSULTANT_INDIVIDUAL, "MONTHLY", 120000)
    line = payroll_engine.compute_line(db, eng, ON)
    assert "withholding_tax_ewt" in line["deductions"]
    assert "sss" not in line["deductions"]
    assert line["deductions"]["withholding_tax_ewt"] == round(120000 * 0.10, 2)


def test_employee_with_loan(db):
    eng = _engagement(db, EngagementType.REGULAR, "MONTHLY", 40000)
    line = payroll_engine.compute_line(db, eng, ON, obligation_installments={"company_loan": 2500})
    assert line["deductions"]["company_loan"] == 2500


def test_employee_with_cash_advance_and_gadget(db):
    eng = _engagement(db, EngagementType.REGULAR, "MONTHLY", 45000)
    line = payroll_engine.compute_line(
        db, eng, ON,
        obligation_installments={"cash_advance": 2000, "gadget_installment": 3750},
    )
    assert line["deductions"]["cash_advance"] == 2000
    assert line["deductions"]["gadget_installment"] == 3750


def test_negative_net_pay_prevented(db):
    eng = _engagement(db, EngagementType.REGULAR, "MONTHLY", 20000)
    # Absurdly large obligation must not push net below zero.
    line = payroll_engine.compute_line(db, eng, ON, obligation_installments={"company_loan": 999999})
    assert line["net_pay"] >= 0
    assert line["total_deductions"] <= line["gross_pay"]


def test_statutory_rule_version_resolves(db):
    rs = stat.resolve_ruleset(db, "SSS", ON)
    assert rs is not None
    assert rs.rule_version == "PROTO-2024.1"
    snap = stat.snapshot_versions(db, ON)
    assert snap["SSS"] == "PROTO-2024.1"
    assert set(snap.keys()) == {"SSS", "PHIC", "HDMF", "BIR"}


def test_withholding_bracket_progression(db):
    # Low taxable → zero tax; high taxable → positive tax.
    brackets = {"brackets": [[0, 0, 0], [20833, 0, 0.15]]}
    low = stat.compute_withholding(15000, brackets)
    high = stat.compute_withholding(50000, brackets)
    assert low == 0
    assert high > 0
