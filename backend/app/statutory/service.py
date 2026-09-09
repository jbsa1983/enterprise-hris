"""Statutory rule resolution + contribution computation.

IMPORTANT (prototype disclaimer):
The parameter values seeded for BIR / SSS / PhilHealth / Pag-IBIG are
ILLUSTRATIVE placeholders for demonstrating the engine. They are NOT
authoritative Philippine statutory rates and must be reviewed and replaced with
validated production tables before any real payroll use.

Rules are effective-dated. `resolve_ruleset` returns the version in force for a
given date; a payroll run pins the versions it used so historical results are
reproducible.
"""
from __future__ import annotations

from datetime import date

from sqlalchemy import or_
from sqlalchemy.orm import Session

from app.models.payroll import StatutoryRuleSet


def resolve_ruleset(db: Session, rule_name: str, on_date: date) -> StatutoryRuleSet | None:
    return (
        db.query(StatutoryRuleSet)
        .filter(
            StatutoryRuleSet.rule_name == rule_name,
            StatutoryRuleSet.effective_from <= on_date,
            or_(StatutoryRuleSet.effective_to.is_(None), StatutoryRuleSet.effective_to >= on_date),
        )
        .order_by(StatutoryRuleSet.effective_from.desc())
        .first()
    )


def _round2(x: float) -> float:
    return round(float(x) + 1e-9, 2)


def compute_sss(monthly_base: float, params: dict) -> float:
    msc = min(monthly_base, params.get("msc_cap", 30000))
    return _round2(msc * params.get("employee_rate", 0.045))


def compute_philhealth(monthly_base: float, params: dict) -> float:
    base = min(monthly_base, params.get("salary_cap", 100000))
    base = max(base, params.get("floor", 10000))
    return _round2(base * params.get("employee_rate", 0.025))


def compute_pagibig(monthly_base: float, params: dict) -> float:
    contrib = monthly_base * params.get("employee_rate", 0.02)
    return _round2(min(contrib, params.get("contribution_cap", 200)))


def compute_withholding(taxable: float, params: dict) -> float:
    """Graduated monthly withholding from a bracket table [[lower, base, rate], ...]."""
    brackets = params.get("brackets", [])
    tax = 0.0
    for lower, base_tax, rate in brackets:
        if taxable > lower:
            tax = base_tax + (taxable - lower) * rate
    return _round2(max(tax, 0.0))


def snapshot_versions(db: Session, on_date: date) -> dict:
    """Capture the {rule_name: version} in force — pinned onto a payroll run."""
    out: dict[str, str] = {}
    for name in ("SSS", "PHIC", "HDMF", "BIR"):
        rs = resolve_ruleset(db, name, on_date)
        if rs:
            out[name] = rs.rule_version
    return out
