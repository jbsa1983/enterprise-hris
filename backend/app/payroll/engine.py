"""Prototype payroll computation engine.

Deliberately kept in the service/domain layer (not in API controllers). Given an
engagement's monthly-equivalent base and the resolved statutory parameters plus
active obligations, it returns an itemized earnings/deductions breakdown and net
pay. Negative net pay is prevented (deductions are capped at gross).

This engine is Phase-3 groundwork; the seeder uses it so dashboards show real,
internally-consistent numbers.
"""
from __future__ import annotations

from datetime import date

from sqlalchemy.orm import Session

from app.models.enums import EngagementType
from app.models.person import Engagement
from app.statutory import service as stat


def monthly_equivalent(engagement: Engagement) -> float:
    """Normalize a base rate to a monthly-equivalent for statutory bases."""
    rate = float(engagement.base_rate or 0)
    basis = (engagement.salary_basis or "MONTHLY").upper()
    if basis == "DAILY":
        return rate * 22
    if basis == "HOURLY":
        return rate * 22 * 8
    return rate


def compute_line(
    db: Session,
    engagement: Engagement,
    on_date: date,
    allowance: float = 0.0,
    obligation_installments: dict[str, float] | None = None,
) -> dict:
    obligation_installments = obligation_installments or {}
    monthly = monthly_equivalent(engagement)

    is_consultant = engagement.engagement_type in EngagementType.CONSULTANT_TYPES

    earnings = {"basic": round(monthly, 2)}
    if allowance:
        earnings["allowance"] = round(allowance, 2)
    gross = sum(earnings.values())

    deductions: dict[str, float] = {}

    if is_consultant:
        # Consultants: no employee statutory contributions; apply expanded
        # withholding on the fee instead (prototype rate).
        deductions["withholding_tax_ewt"] = round(gross * 0.10, 2)
    else:
        sss_rs = stat.resolve_ruleset(db, "SSS", on_date)
        phic_rs = stat.resolve_ruleset(db, "PHIC", on_date)
        hdmf_rs = stat.resolve_ruleset(db, "HDMF", on_date)
        bir_rs = stat.resolve_ruleset(db, "BIR", on_date)

        sss = stat.compute_sss(monthly, sss_rs.parameters_json) if sss_rs else 0.0
        phic = stat.compute_philhealth(monthly, phic_rs.parameters_json) if phic_rs else 0.0
        hdmf = stat.compute_pagibig(monthly, hdmf_rs.parameters_json) if hdmf_rs else 0.0
        taxable = max(gross - (sss + phic + hdmf), 0.0)
        wtax = stat.compute_withholding(taxable, bir_rs.parameters_json) if bir_rs else 0.0

        deductions.update({"sss": sss, "philhealth": phic, "pagibig": hdmf, "withholding_tax": wtax})

    # Loan / advance installments.
    for label, amount in obligation_installments.items():
        if amount:
            deductions[label] = round(float(amount), 2)

    total_deductions = round(sum(deductions.values()), 2)
    # Prevent negative net pay: cap deductions at gross.
    if total_deductions > gross:
        total_deductions = round(gross, 2)
    net = round(gross - total_deductions, 2)

    return {
        "gross_pay": round(gross, 2),
        "total_deductions": total_deductions,
        "net_pay": net,
        "earnings": earnings,
        "deductions": deductions,
    }
