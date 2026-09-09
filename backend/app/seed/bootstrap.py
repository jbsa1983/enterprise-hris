"""Idempotent database bootstrap: create tables + seed demo data.

Runs at backend container startup (see docker-compose command). Safe to run
repeatedly — it no-ops if the database has already been seeded.

Usage:  python -m app.seed.bootstrap
"""
from __future__ import annotations

import random
import time
from datetime import date, timedelta

from sqlalchemy import text
from sqlalchemy.exc import OperationalError
from sqlalchemy.orm import Session

from app.core.config import settings
from app.core.database import Base, SessionLocal, engine
from app.core.rbac import PERMISSIONS, ROLE_PERMISSIONS
from app.core.security import hash_password

# Import model package so all tables are registered on the metadata.
import app.models  # noqa: F401
from app.models.asset import Asset
from app.models.enums import EngagementStatus, EngagementType, ObligationType, PayrollStatus
from app.models.hr import LeaveRequest, OvertimeRequest
from app.models.loan import Loan, LoanTransaction
from app.models.org_structure import Department, Position
from app.models.organization import Enterprise, Organization, OrganizationUser
from app.models.payroll import (
    PayrollPeriod,
    PayrollRun,
    PayrollRunPerson,
    StatutoryRuleSet,
)
from app.models.person import Engagement, Person
from app.models.project import Client, Project, ProjectAssignment
from app.models.storage import StorageMetric
from app.models.user import Permission, Role, User
from app.payroll import engine as payroll_engine
from app.seed import data as D

RNG = random.Random(42)
GB = 1024 ** 3


# --------------------------------------------------------------------------- #
# Infra helpers
# --------------------------------------------------------------------------- #
def wait_for_db(max_tries: int = 30, delay: float = 2.0) -> None:
    for attempt in range(1, max_tries + 1):
        try:
            with engine.connect() as conn:
                conn.execute(text("SELECT 1"))
            print(f"[seed] database reachable (attempt {attempt})")
            return
        except OperationalError:
            print(f"[seed] waiting for database... ({attempt}/{max_tries})")
            time.sleep(delay)
    raise RuntimeError("Database not reachable after retries")


def ensure_minio_bucket() -> None:
    try:
        from minio import Minio

        client = Minio(
            settings.minio_endpoint,
            access_key=settings.minio_root_user,
            secret_key=settings.minio_root_password,
            secure=settings.minio_secure,
        )
        if not client.bucket_exists(settings.minio_bucket):
            client.make_bucket(settings.minio_bucket)
            print(f"[seed] created MinIO bucket '{settings.minio_bucket}'")
        else:
            print(f"[seed] MinIO bucket '{settings.minio_bucket}' exists")
    except Exception as exc:  # noqa: BLE001 — object storage is optional for boot
        print(f"[seed] MinIO not ready / skipped: {exc}")


# --------------------------------------------------------------------------- #
# Seed steps
# --------------------------------------------------------------------------- #
def seed_rbac(db: Session) -> dict[str, Role]:
    perms: dict[str, Permission] = {}
    for code, desc in PERMISSIONS.items():
        p = Permission(code=code, description=desc)
        db.add(p)
        perms[code] = p
    db.flush()

    roles: dict[str, Role] = {}
    for role_name, codes in ROLE_PERMISSIONS.items():
        role = Role(name=role_name, is_system=True, description=f"Default role: {role_name}")
        role.permissions = [perms[c] for c in codes if c in perms]
        db.add(role)
        roles[role_name] = role
    db.flush()
    print(f"[seed] RBAC: {len(perms)} permissions, {len(roles)} roles")
    return roles


def seed_enterprise_and_orgs(db: Session) -> tuple[Enterprise, list[Organization]]:
    ent = Enterprise(name="Demo Enterprise Group", code="DEMO", description="Prototype enterprise group")
    db.add(ent)
    db.flush()

    orgs: list[Organization] = []
    for name, code in D.ORG_DEFS:
        org = Organization(
            enterprise_id=ent.id,
            name=name,
            code=code,
            legal_name=name,
            tin=f"000-{RNG.randint(100, 999)}-{RNG.randint(100, 999)}-000",
            address="Metro Manila, Philippines",
        )
        db.add(org)
        orgs.append(org)
    db.flush()
    print(f"[seed] enterprise + {len(orgs)} organizations")
    return ent, orgs


def seed_users(db: Session, roles: dict[str, Role], orgs: list[Organization]) -> None:
    # Super admin — access to everything.
    admin = User(
        email=settings.default_admin_email.lower(),
        full_name="System Administrator",
        hashed_password=hash_password(settings.default_admin_password),
        is_superadmin=True,
    )
    admin.roles = [roles["Super Admin"]]
    db.add(admin)
    db.flush()
    for org in orgs:
        db.add(OrganizationUser(organization_id=org.id, user_id=admin.id, is_primary=(org is orgs[0])))

    # Per-org demo users (password: Demo123!) demonstrating org scoping.
    # (email_prefix, role_name) — prefixes are unique per org.
    demo_pw = hash_password("Demo123!")
    role_users = [
        ("hr", "HR Manager"),
        ("payroll", "Payroll Administrator"),
        ("approver", "Payroll Approver"),
        ("auditor", "Auditor"),
    ]
    for org in orgs:
        for prefix, role_name in role_users:
            email = f"{prefix}.{org.code.lower()}@demo-hris.local"
            u = User(email=email, full_name=f"{role_name} — {org.code}", hashed_password=demo_pw)
            u.roles = [roles[role_name]]
            db.add(u)
            db.flush()
            db.add(OrganizationUser(organization_id=org.id, user_id=u.id, is_primary=True))
    db.flush()
    print("[seed] users (1 super admin + per-org demo users)")


def seed_structure(db: Session, orgs: list[Organization]) -> dict[int, dict]:
    """Returns {org_id: {'departments': [...], 'positions': [...]}}."""
    structure: dict[int, dict] = {}
    for org in orgs:
        depts = []
        for name in D.DEPARTMENTS:
            d = Department(organization_id=org.id, name=name, code=name[:3].upper())
            db.add(d)
            depts.append(d)
        db.flush()
        positions = []
        for title, grade in D.POSITIONS:
            p = Position(organization_id=org.id, title=title, job_grade=grade, department_id=RNG.choice(depts).id)
            db.add(p)
            positions.append(p)
        db.flush()
        structure[org.id] = {"departments": depts, "positions": positions}
    print("[seed] departments + positions per organization")
    return structure


def seed_statutory(db: Session) -> None:
    eff = date(2024, 1, 1)
    for rs in D.STATUTORY_RULESETS:
        db.add(
            StatutoryRuleSet(
                rule_name=rs["rule_name"],
                rule_version=rs["rule_version"],
                effective_from=eff,
                effective_to=None,
                is_prototype_data=True,
                parameters_json=rs["parameters_json"],
                notes=rs["notes"],
            )
        )
    db.flush()
    print("[seed] statutory rule sets (PROTOTYPE values)")


def _make_person(db: Session, idx: int) -> Person:
    fn = RNG.choice(D.FIRST_NAMES)
    ln = RNG.choice(D.LAST_NAMES)
    p = Person(
        first_name=fn,
        last_name=ln,
        middle_name=RNG.choice(D.LAST_NAMES),
        gender=RNG.choice(["Male", "Female"]),
        civil_status=RNG.choice(["Single", "Married"]),
        email=f"{fn.lower()}.{ln.lower().replace(' ', '')}{idx}@example.com",
        mobile=f"+639{RNG.randint(100000000, 999999999)}",
        address="Metro Manila, Philippines",
        tin=f"{RNG.randint(100, 999)}-{RNG.randint(100, 999)}-{RNG.randint(100, 999)}-000",
        sss_number=f"{RNG.randint(10, 99)}-{RNG.randint(1000000, 9999999)}-{RNG.randint(0, 9)}",
        philhealth_number=f"{RNG.randint(10, 99)}-{RNG.randint(100000000, 999999999)}-{RNG.randint(0, 9)}",
        pagibig_number=f"{RNG.randint(1000, 9999)}-{RNG.randint(1000, 9999)}-{RNG.randint(1000, 9999)}",
        bank_name=RNG.choice(["BDO", "BPI", "Metrobank", "UnionBank", "Landbank"]),
        bank_account_number=str(RNG.randint(10 ** 9, 10 ** 10 - 1)),
        bank_account_name=f"{fn} {ln}",
        status="ACTIVE",
    )
    db.add(p)
    return p


def seed_people(db: Session, orgs: list[Organization], structure: dict[int, dict]) -> list[Engagement]:
    """Create ~50 employees, 15 project-based, 5 consultants across orgs."""
    engagements: list[Engagement] = []
    counter = 0
    today = date.today()

    def add_engagement(org, etype, salary_basis, base_rate, emp_no, **extra):
        nonlocal counter
        counter += 1
        person = _make_person(db, counter)
        db.flush()
        st = structure[org.id]
        eng = Engagement(
            person_id=person.id,
            organization_id=org.id,
            engagement_type=etype,
            employee_number=emp_no,
            start_date=today - timedelta(days=RNG.randint(60, 1500)),
            department_id=RNG.choice(st["departments"]).id,
            position_id=RNG.choice(st["positions"]).id,
            salary_basis=salary_basis,
            base_rate=base_rate,
            payroll_group=RNG.choice(["MONTHLY-A", "MONTHLY-B"]),
            cost_center=f"CC-{RNG.randint(100, 199)}",
            status=EngagementStatus.ACTIVE,
            **extra,
        )
        db.add(eng)
        db.flush()
        engagements.append(eng)
        return eng

    # 50 regular/probationary/fixed-term employees (monthly).
    emp_types = (
        [EngagementType.REGULAR] * 30
        + [EngagementType.PROBATIONARY] * 12
        + [EngagementType.FIXED_TERM] * 8
    )
    for n in range(50):
        org = orgs[n % len(orgs)]
        etype = emp_types[n]
        base = RNG.choice([22000, 26000, 30000, 38000, 45000, 55000, 70000])
        end = None
        if etype == EngagementType.FIXED_TERM:
            end = today + timedelta(days=RNG.choice([20, 45, 75, 200]))  # some expiring in 30/60/90
        add_engagement(org, etype, "MONTHLY", base, f"EMP-{1000 + n}", end_date=end)

    # 15 project-based employees (daily-paid).
    for n in range(15):
        org = orgs[n % len(orgs)]
        end = today + timedelta(days=RNG.choice([25, 50, 85, 150]))
        add_engagement(
            org, EngagementType.PROJECT_BASED, "DAILY", RNG.choice([610, 700, 850, 1000]),
            f"PRJ-{2000 + n}", end_date=end,
        )

    # 5 consultants.
    for n in range(5):
        org = orgs[n % len(orgs)]
        ctype = EngagementType.CONSULTANT_INDIVIDUAL if n % 2 == 0 else EngagementType.CONSULTANT_COMPANY
        add_engagement(
            org, ctype, "MONTHLY", RNG.choice([80000, 120000, 150000]),
            f"CON-{3000 + n}", end_date=today + timedelta(days=RNG.choice([40, 90, 180])),
        )

    print(f"[seed] {len(engagements)} engagements (employees + project-based + consultants)")
    return engagements


def seed_projects(db: Session, orgs: list[Organization], engagements: list[Engagement]) -> list[Project]:
    projects: list[Project] = []
    today = date.today()
    for i, (code, name) in enumerate(D.PROJECT_DEFS):
        org = orgs[i % len(orgs)]
        client = Client(organization_id=org.id, name=f"Client {i + 1}")
        db.add(client)
        db.flush()
        proj = Project(
            organization_id=org.id,
            project_code=code,
            project_name=name,
            client_id=client.id,
            project_manager="Project Manager " + str(i + 1),
            start_date=today - timedelta(days=RNG.randint(100, 400)),
            target_end_date=today + timedelta(days=RNG.randint(60, 300)),
            site=RNG.choice(["Makati", "Quezon City", "Cebu", "Davao"]),
            status="ACTIVE",
            project_budget=RNG.randint(5, 30) * 1_000_000,
            labor_budget=RNG.randint(2, 12) * 1_000_000,
        )
        db.add(proj)
        db.flush()
        projects.append(proj)

    # Assign project-based engagements to projects within the same org.
    pb = [e for e in engagements if e.engagement_type == EngagementType.PROJECT_BASED]
    for eng in pb:
        candidates = [p for p in projects if p.organization_id == eng.organization_id] or projects
        proj = RNG.choice(candidates)
        eng.project_id = proj.id
        db.add(
            ProjectAssignment(
                engagement_id=eng.id,
                project_id=proj.id,
                assignment_start=eng.start_date,
                assignment_end=eng.end_date,
                site=proj.site,
                billable=True,
                billing_rate=float(eng.base_rate or 0) * 1.6,
                pay_rate=float(eng.base_rate or 0),
                shift="Day",
            )
        )
    db.flush()
    print(f"[seed] {len(projects)} projects + assignments")
    return projects


def seed_obligations(db: Session, engagements: list[Engagement]) -> dict[int, dict[str, float]]:
    """Create loans / advances / gadget installments; return per-engagement
    installment map used by payroll deduction."""
    installments: dict[int, dict[str, float]] = {}
    employees = [e for e in engagements if e.engagement_type not in EngagementType.CONSULTANT_TYPES]

    def add_loan(eng, otype, desc, principal, installment, label):
        loan = Loan(
            organization_id=eng.organization_id,
            person_id=eng.person_id,
            engagement_id=eng.id,
            obligation_type=otype,
            reference_number=f"{otype[:3]}-{RNG.randint(10000, 99999)}",
            description=desc,
            principal=principal,
            total_amount=principal,
            amount_paid=0,
            balance=principal,
            installment_amount=installment,
            status="ACTIVE",
            payroll_deductible=True,
        )
        db.add(loan)
        db.flush()
        db.add(
            LoanTransaction(
                loan_id=loan.id,
                entry_type="NEW_LOAN",
                amount=principal,
                balance_after=principal,
                entry_date=date.today() - timedelta(days=90),
                remarks="Opening balance",
            )
        )
        installments.setdefault(eng.id, {})[label] = installment
        return loan

    # ~40% get a company loan, ~30% a cash advance, ~25% a gadget installment.
    for eng in employees:
        if RNG.random() < 0.40:
            add_loan(eng, ObligationType.COMPANY_LOAN, "Company Loan", RNG.choice([20000, 30000, 50000]),
                     RNG.choice([2000, 2500, 3000]), "company_loan")
        if RNG.random() < 0.30:
            add_loan(eng, ObligationType.CASH_ADVANCE, "Cash Advance", RNG.choice([5000, 8000, 10000]),
                     RNG.choice([1000, 1500, 2000]), "cash_advance")
        if RNG.random() < 0.25:
            add_loan(eng, ObligationType.GADGET_INSTALLMENT, "Laptop Installment", 45000, 3750, "gadget_installment")
        if RNG.random() < 0.20:
            add_loan(eng, ObligationType.SSS_LOAN, "SSS Salary Loan", 15000, 1250, "sss_loan")

    db.flush()
    print(f"[seed] obligations for {len(installments)} engagements")
    return installments


def seed_assets(db: Session, engagements: list[Engagement]) -> None:
    items = ["Laptop", "Mobile Phone", "Access Card", "Headset", "Monitor"]
    sample = RNG.sample(engagements, min(20, len(engagements)))
    for i, eng in enumerate(sample):
        db.add(
            Asset(
                organization_id=eng.organization_id,
                asset_number=f"AST-{5000 + i}",
                item=RNG.choice(items),
                serial_number=f"SN{RNG.randint(100000, 999999)}",
                is_employee_payable=False,
                assigned_person_id=eng.person_id,
                issue_date=date.today() - timedelta(days=RNG.randint(30, 400)),
                cost=RNG.choice([15000, 25000, 45000]),
                status="ISSUED",
            )
        )
    db.flush()
    print(f"[seed] {len(sample)} company assets assigned")


def seed_hr_requests(db: Session, engagements: list[Engagement]) -> None:
    sample = RNG.sample(engagements, min(25, len(engagements)))
    for eng in sample:
        if RNG.random() < 0.6:
            db.add(
                LeaveRequest(
                    organization_id=eng.organization_id,
                    engagement_id=eng.id,
                    leave_type=RNG.choice(["Vacation", "Sick", "Emergency"]),
                    date_from=date.today() + timedelta(days=RNG.randint(1, 20)),
                    date_to=date.today() + timedelta(days=RNG.randint(21, 25)),
                    days=RNG.choice([1, 2, 3]),
                    status="PENDING",
                )
            )
        if RNG.random() < 0.4:
            db.add(
                OvertimeRequest(
                    organization_id=eng.organization_id,
                    engagement_id=eng.id,
                    ot_date=date.today() - timedelta(days=RNG.randint(1, 10)),
                    hours=RNG.choice([2, 3, 4]),
                    status="PENDING",
                )
            )
    db.flush()
    print("[seed] pending leave / overtime requests")


def seed_payroll(
    db: Session,
    orgs: list[Organization],
    engagements: list[Engagement],
    installments: dict[int, dict[str, float]],
) -> None:
    """Create 3 monthly periods per org and a computed run for the latest."""
    from app.statutory import service as stat

    today = date.today()
    for org in orgs:
        periods: list[PayrollPeriod] = []
        for m in range(3):
            ref_month = (today.month - (2 - m) - 1) % 12 + 1
            year = today.year if (today.month - (2 - m)) > 0 else today.year - 1
            start = date(year, ref_month, 1)
            # crude month end
            end = (date(year, ref_month % 12 + 1, 1) - timedelta(days=1)) if ref_month != 12 else date(year, 12, 31)
            period = PayrollPeriod(
                organization_id=org.id,
                name=f"{start.strftime('%B %Y')}",
                frequency="MONTHLY",
                period_start=start,
                period_end=end,
                pay_date=end,
            )
            db.add(period)
            periods.append(period)
        db.flush()

        latest = periods[-1]
        run = PayrollRun(
            organization_id=org.id,
            period_id=latest.id,
            reference=f"RUN-{org.code}-{latest.period_start.strftime('%Y%m')}",
            status=PayrollStatus.APPROVED,
            rule_version_snapshot=stat.snapshot_versions(db, latest.period_end),
        )
        db.add(run)
        db.flush()

        org_engagements = [e for e in engagements if e.organization_id == org.id and e.status == "ACTIVE"]
        gross_total = ded_total = net_total = 0.0
        for eng in org_engagements:
            allowance = 2000 if eng.engagement_type not in EngagementType.CONSULTANT_TYPES else 0
            line = payroll_engine.compute_line(
                db, eng, latest.period_end,
                allowance=allowance,
                obligation_installments=installments.get(eng.id, {}),
            )
            db.add(
                PayrollRunPerson(
                    run_id=run.id,
                    engagement_id=eng.id,
                    gross_pay=line["gross_pay"],
                    total_deductions=line["total_deductions"],
                    net_pay=line["net_pay"],
                    earnings=line["earnings"],
                    deductions=line["deductions"],
                )
            )
            gross_total += line["gross_pay"]
            ded_total += line["total_deductions"]
            net_total += line["net_pay"]

            # Record payroll deduction ledger entries against obligations.
            for label, amount in installments.get(eng.id, {}).items():
                loan = (
                    db.query(Loan)
                    .filter(Loan.engagement_id == eng.id, Loan.balance > 0)
                    .order_by(Loan.id)
                    .first()
                )
                if loan and amount:
                    pay = min(float(amount), float(loan.balance))
                    loan.amount_paid = float(loan.amount_paid) + pay
                    loan.balance = float(loan.balance) - pay
                    db.add(
                        LoanTransaction(
                            loan_id=loan.id,
                            entry_type="PAYROLL_DEDUCTION",
                            amount=pay,
                            balance_after=loan.balance,
                            payroll_run_id=run.id,
                            period_label=latest.name,
                            entry_date=latest.period_end,
                        )
                    )

        run.gross_total = round(gross_total, 2)
        run.deduction_total = round(ded_total, 2)
        run.net_total = round(net_total, 2)
        db.flush()
    print("[seed] payroll periods + computed runs per organization")


def seed_storage_metrics(db: Session, orgs: list[Organization]) -> None:
    categories = {
        "employee_documents": 6.5,
        "payroll_reports": 3.2,
        "recruitment_documents": 1.1,
        "training_documents": 0.8,
        "database": 4.0,
        "backups": 2.4,
        "other": 0.6,
    }
    for cat, gb in categories.items():
        db.add(StorageMetric(organization_id=None, category=cat, bytes_used=gb * GB))
    for org in orgs:
        db.add(StorageMetric(organization_id=org.id, category="employee_documents",
                             bytes_used=RNG.uniform(0.5, 3.0) * GB))
    db.flush()
    print("[seed] storage metrics")


# --------------------------------------------------------------------------- #
# Orchestration
# --------------------------------------------------------------------------- #
def already_seeded(db: Session) -> bool:
    return db.query(User).count() > 0


def run() -> None:
    wait_for_db()
    print("[seed] creating tables (if not present)...")
    Base.metadata.create_all(bind=engine)

    db = SessionLocal()
    try:
        if already_seeded(db):
            print("[seed] database already seeded — skipping demo data.")
        else:
            print("[seed] seeding demo data...")
            roles = seed_rbac(db)
            _, orgs = seed_enterprise_and_orgs(db)
            seed_users(db, roles, orgs)
            structure = seed_structure(db, orgs)
            seed_statutory(db)
            engagements = seed_people(db, orgs, structure)
            seed_projects(db, orgs, engagements)
            installments = seed_obligations(db, engagements)
            seed_assets(db, engagements)
            seed_hr_requests(db, engagements)
            seed_payroll(db, orgs, engagements, installments)
            seed_storage_metrics(db, orgs)
            # Phase 2/5/6 modules + ESS users + payslip records.
            from app.seed import extra

            extra.seed_all(db, orgs, engagements, roles)
            db.commit()
            print("[seed] demo data committed.")
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()

    ensure_minio_bucket()
    print("[seed] bootstrap complete.")


if __name__ == "__main__":
    run()
