"""Seed data for Phase 2/5/6 modules + ESS user linkage.

Called from bootstrap after the core data is in place. All fictional.
"""
from __future__ import annotations

import random
from datetime import date, timedelta

from sqlalchemy.orm import Session

from app.core.security import hash_password
from app.models.attendance import AttendanceLog, LeaveBalance, LeaveType
from app.models.bank import BankExportColumn, BankExportTemplate
from app.models.enums import EngagementType
from app.models.hrmodules import (
    LifecycleChecklist,
    PerformanceCycle,
    PerformanceReview,
    ServiceTicket,
    TrainingAssignment,
    TrainingCourse,
)
from app.models.organization import Organization, OrganizationUser
from app.models.payroll import PayrollRun
from app.models.person import Engagement
from app.models.user import Role, User
from app.models.workflow import ApprovalWorkflow, ApprovalWorkflowStep
from app.payroll import payslip_service

RNG = random.Random(7)


def _emp_engagements(engagements, org_id):
    return [
        e for e in engagements
        if e.organization_id == org_id and e.engagement_type not in EngagementType.CONSULTANT_TYPES
    ]


def seed_bank_templates(db: Session, orgs: list[Organization]) -> None:
    cols = [
        ("bank_account", "ACCOUNT_NO", None, None),
        ("account_name", "ACCOUNT_NAME", "upper", None),
        ("net_pay", "AMOUNT", "amount", None),
        ("employee_number", "REFERENCE", None, None),
        ("payroll_date", "VALUE_DATE", "date", None),
    ]
    for org in orgs:
        t = BankExportTemplate(
            organization_id=org.id, template_name="BDO Payroll Corporate", bank_name="BDO",
            file_type="CSV", delimiter=",", header_required=True, date_format="%m/%d/%Y",
            decimal_places=2, filename_pattern="{bank}_{org}_{date}", template_version=1,
        )
        db.add(t)
        db.flush()
        for i, (sf, hdr, fmt, pad) in enumerate(cols):
            db.add(BankExportColumn(template_id=t.id, order_index=i, system_field=sf,
                                    output_header=hdr, formatting=fmt, padding=pad, required=(sf == "bank_account")))
    db.flush()
    print("[seed] bank export templates (BDO CSV per org)")


def seed_leave_and_attendance(db: Session, orgs: list[Organization], engagements) -> None:
    for org in orgs:
        for name, credits in [("Vacation", 15), ("Sick", 15), ("Emergency", 5)]:
            db.add(LeaveType(organization_id=org.id, name=name, default_credits=credits, paid=True))
        emps = _emp_engagements(engagements, org.id)
        for eng in emps:
            db.add(LeaveBalance(organization_id=org.id, engagement_id=eng.id, leave_type="Vacation",
                                credits=15, used=RNG.choice([0, 1, 2, 3])))
        # attendance for a sample
        for eng in emps[:8]:
            for d in range(3):
                log_date = date.today() - timedelta(days=d + 1)
                db.add(AttendanceLog(organization_id=org.id, engagement_id=eng.id, log_date=log_date,
                                     hours_worked=8, late_minutes=RNG.choice([0, 0, 0, 10, 25]),
                                     overtime_hours=RNG.choice([0, 0, 1, 2]), source="WEB", status="PRESENT"))
    db.flush()
    print("[seed] leave types/balances + attendance logs")


def seed_workflows(db: Session, orgs: list[Organization]) -> None:
    for org in orgs:
        leave_wf = ApprovalWorkflow(organization_id=org.id, name="Leave Approval",
                                    transaction_type="leave", active=True)
        db.add(leave_wf)
        db.flush()
        db.add(ApprovalWorkflowStep(workflow_id=leave_wf.id, step_order=1, name="Supervisor",
                                    approver_role="Supervisor"))
        db.add(ApprovalWorkflowStep(workflow_id=leave_wf.id, step_order=2, name="HR", approver_role="HR Manager"))

        ca_wf = ApprovalWorkflow(organization_id=org.id, name="Cash Advance Approval",
                                 transaction_type="cash_advance", conditions_json={"min_amount": 5000}, active=True)
        db.add(ca_wf)
        db.flush()
        for i, (nm, role) in enumerate([("Supervisor", "Supervisor"), ("Department Head", "Department Head"),
                                        ("Finance", "Finance")], start=1):
            db.add(ApprovalWorkflowStep(workflow_id=ca_wf.id, step_order=i, name=nm, approver_role=role))
    db.flush()
    print("[seed] approval workflows (leave, cash advance)")


def seed_hr_modules(db: Session, orgs: list[Organization], engagements) -> None:
    for org in orgs:
        emps = _emp_engagements(engagements, org.id)
        # Service tickets
        for i, cat in enumerate(["Certificate of Employment", "Payroll concern", "HMO"]):
            db.add(ServiceTicket(organization_id=org.id, ticket_number=f"TKT-{org.id}-{i + 1:04d}",
                                 engagement_id=emps[i].id if i < len(emps) else None, category=cat,
                                 priority=RNG.choice(["NORMAL", "HIGH"]), status="OPEN",
                                 subject=f"{cat} request"))
        # Performance
        cycle = PerformanceCycle(organization_id=org.id, name=f"{date.today().year} Annual Review",
                                 cycle_type="ANNUAL", status="OPEN")
        db.add(cycle)
        db.flush()
        for eng in emps[:5]:
            ss, sup = RNG.choice([3.5, 4.0, 4.5]), RNG.choice([3.0, 4.0, 5.0])
            db.add(PerformanceReview(organization_id=org.id, cycle_id=cycle.id, engagement_id=eng.id,
                                     self_score=ss, supervisor_score=sup,
                                     final_rating=round((ss + sup) / 2, 2), status="COMPLETED"))
        # Training
        course = TrainingCourse(organization_id=org.id, title="Data Privacy Act Orientation",
                                category="Compliance", provider="Internal")
        db.add(course)
        db.flush()
        for eng in emps[:6]:
            db.add(TrainingAssignment(organization_id=org.id, course_id=course.id, engagement_id=eng.id,
                                      status=RNG.choice(["ASSIGNED", "COMPLETED"])))
        # Onboarding checklist for a couple of probationary
        probs = [e for e in emps if e.engagement_type == EngagementType.PROBATIONARY][:2]
        for eng in probs:
            for item in ["Employee data", "Government numbers", "Bank account", "NDA", "Contract", "Orientation"]:
                db.add(LifecycleChecklist(organization_id=org.id, engagement_id=eng.id, kind="ONBOARDING",
                                          item=item, completed=RNG.choice([True, False])))
    db.flush()
    print("[seed] service tickets, performance, training, onboarding")


def seed_ess_users(db: Session, orgs: list[Organization], engagements, roles: dict[str, Role]) -> None:
    demo_pw = hash_password("Demo123!")
    for org in orgs:
        emps = _emp_engagements(engagements, org.id)
        if not emps:
            continue
        eng = emps[0]
        email = f"employee.{org.code.lower()}@demo-hris.local"
        u = User(email=email, full_name=f"ESS Employee — {org.code}", hashed_password=demo_pw,
                 person_id=eng.person_id)
        u.roles = [roles["Employee"]]
        db.add(u)
        db.flush()
        db.add(OrganizationUser(organization_id=org.id, user_id=u.id, is_primary=True))
    db.flush()
    print("[seed] ESS demo users (employee.<org>@demo-hris.local, linked to a person)")


def seed_payslips(db: Session, orgs: list[Organization]) -> None:
    """Generate payslip records for the latest run per org (PDFs on demand)."""
    total = 0
    for org in orgs:
        run = (
            db.query(PayrollRun).filter(PayrollRun.organization_id == org.id)
            .order_by(PayrollRun.id.desc()).first()
        )
        if run:
            created = payslip_service.generate_for_run(db, run, render_pdf=False)
            total += len(created)
    db.flush()
    print(f"[seed] {total} payslip/payment-advice records (PDF rendered on demand)")


def seed_all(db: Session, orgs: list[Organization], engagements, roles: dict[str, Role]) -> None:
    seed_bank_templates(db, orgs)
    seed_leave_and_attendance(db, orgs, engagements)
    seed_workflows(db, orgs)
    seed_hr_modules(db, orgs, engagements)
    seed_ess_users(db, orgs, engagements, roles)
    seed_payslips(db, orgs)
