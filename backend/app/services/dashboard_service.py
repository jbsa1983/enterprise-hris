"""Dashboard aggregation (enterprise-wide and single-organization).

All aggregates are constrained to the organizations the requesting user may
access — enforced here, not in the frontend.
"""
from __future__ import annotations

from datetime import date, timedelta

from sqlalchemy import func
from sqlalchemy.orm import Session

from app.models.enums import EngagementType, PayrollStatus, ProjectStatus
from app.models.hr import LeaveRequest, OvertimeRequest
from app.models.organization import Organization
from app.models.payroll import PayrollRun
from app.models.person import Engagement
from app.models.project import Project
from app.models.user import User

_ACTIVE = "ACTIVE"


def _accessible_org_ids(db: Session, user: User) -> list[int]:
    if user.is_superadmin:
        return [o.id for o in db.query(Organization.id).all()]
    return user.accessible_org_ids


def _headcount_by_type(db: Session, org_ids: list[int]) -> dict[str, int]:
    if not org_ids:
        return {}
    rows = (
        db.query(Engagement.engagement_type, func.count(Engagement.id))
        .filter(Engagement.organization_id.in_(org_ids), Engagement.status == _ACTIVE)
        .group_by(Engagement.engagement_type)
        .all()
    )
    return {t: c for t, c in rows}


def _classify(counts: dict[str, int]) -> dict[str, int]:
    consultants = sum(counts.get(t, 0) for t in EngagementType.CONSULTANT_TYPES)
    return {
        "total_active_personnel": sum(counts.values()),
        "regular": counts.get(EngagementType.REGULAR, 0),
        "probationary": counts.get(EngagementType.PROBATIONARY, 0),
        "project_based": counts.get(EngagementType.PROJECT_BASED, 0),
        "fixed_term": counts.get(EngagementType.FIXED_TERM, 0),
        "consultants": consultants,
        "by_type": counts,
    }


def _contracts_expiring(db: Session, org_ids: list[int]) -> dict[str, int]:
    today = date.today()
    out = {}
    for window in (30, 60, 90):
        horizon = today + timedelta(days=window)
        count = (
            db.query(func.count(Engagement.id))
            .filter(
                Engagement.organization_id.in_(org_ids),
                Engagement.status == _ACTIVE,
                Engagement.end_date.isnot(None),
                Engagement.end_date >= today,
                Engagement.end_date <= horizon,
            )
            .scalar()
            or 0
        )
        out[f"in_{window}_days"] = count
    return out


def enterprise_dashboard(db: Session, user: User) -> dict:
    org_ids = _accessible_org_ids(db, user)
    counts = _headcount_by_type(db, org_ids)

    active_projects = (
        db.query(func.count(Project.id))
        .filter(Project.organization_id.in_(org_ids), Project.status == ProjectStatus.ACTIVE)
        .scalar()
        if org_ids
        else 0
    ) or 0

    payroll_net = (
        db.query(func.coalesce(func.sum(PayrollRun.net_total), 0))
        .filter(PayrollRun.organization_id.in_(org_ids))
        .scalar()
        if org_ids
        else 0
    ) or 0

    pending_payroll = (
        db.query(func.count(PayrollRun.id))
        .filter(
            PayrollRun.organization_id.in_(org_ids),
            PayrollRun.status.in_([PayrollStatus.FOR_REVIEW, PayrollStatus.CALCULATED]),
        )
        .scalar()
        if org_ids
        else 0
    ) or 0

    # Per-organization personnel distribution.
    distribution = []
    for org in db.query(Organization).filter(Organization.id.in_(org_ids)).all() if org_ids else []:
        c = (
            db.query(func.count(Engagement.id))
            .filter(Engagement.organization_id == org.id, Engagement.status == _ACTIVE)
            .scalar()
            or 0
        )
        distribution.append({"organization_id": org.id, "name": org.name, "personnel": c})

    workforce_cost = (
        db.query(func.coalesce(func.sum(Engagement.base_rate), 0))
        .filter(Engagement.organization_id.in_(org_ids), Engagement.status == _ACTIVE)
        .scalar()
        if org_ids
        else 0
    ) or 0

    return {
        "organizations_count": len(org_ids),
        **_classify(counts),
        "active_projects": active_projects,
        "payroll_net_total": float(payroll_net),
        "pending_approvals": int(pending_payroll),
        "contracts_expiring": _contracts_expiring(db, org_ids) if org_ids else {},
        "employee_distribution": distribution,
        "workforce_cost_monthly": float(workforce_cost),
    }


def organization_dashboard(db: Session, org_id: int) -> dict:
    org = db.get(Organization, org_id)
    if not org:
        return {}
    org_ids = [org_id]
    counts = _headcount_by_type(db, org_ids)

    active_projects = (
        db.query(func.count(Project.id))
        .filter(Project.organization_id == org_id, Project.status == ProjectStatus.ACTIVE)
        .scalar()
        or 0
    )
    departments = (
        db.query(func.count(func.distinct(Engagement.department_id)))
        .filter(Engagement.organization_id == org_id)
        .scalar()
        or 0
    )
    latest_run = (
        db.query(PayrollRun)
        .filter(PayrollRun.organization_id == org_id)
        .order_by(PayrollRun.id.desc())
        .first()
    )
    pending_leave = (
        db.query(func.count(LeaveRequest.id))
        .filter(LeaveRequest.organization_id == org_id, LeaveRequest.status == "PENDING")
        .scalar()
        or 0
    )
    pending_ot = (
        db.query(func.count(OvertimeRequest.id))
        .filter(OvertimeRequest.organization_id == org_id, OvertimeRequest.status == "PENDING")
        .scalar()
        or 0
    )

    return {
        "organization_id": org.id,
        "organization_name": org.name,
        **_classify(counts),
        "departments": int(departments),
        "active_projects": int(active_projects),
        "payroll_period_status": latest_run.status if latest_run else "NONE",
        "gross_payroll": float(latest_run.gross_total) if latest_run else 0.0,
        "total_deductions": float(latest_run.deduction_total) if latest_run else 0.0,
        "net_payroll": float(latest_run.net_total) if latest_run else 0.0,
        "pending_leave_approvals": int(pending_leave),
        "pending_overtime_approvals": int(pending_ot),
        "contracts_expiring": _contracts_expiring(db, org_ids),
    }
