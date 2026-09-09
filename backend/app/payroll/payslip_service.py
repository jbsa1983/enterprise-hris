"""Payslip / payment-advice generation, versioning, and PDF rendering.

Generation snapshots everything the document shows (including historical
obligation balances) into an immutable JSON blob, renders a PDF via WeasyPrint,
stores it in MinIO, and supersedes any prior version for the same run+engagement.
"""
from __future__ import annotations

from datetime import datetime, timezone

from sqlalchemy.orm import Session

from app.models.enums import EngagementType
from app.models.organization import Organization
from app.models.payroll import PayrollRun, PayrollRunPerson
from app.models.payslip import DocumentType, Payslip
from app.models.person import Engagement, Person
from app.models.project import Project
from app.reports import pdf as pdf_renderer
from app.services import obligation_service
from app.storage import documents


def _mask_account(acct: str | None) -> str:
    if not acct:
        return "—"
    tail = acct[-4:]
    return f"{'*' * max(len(acct) - 4, 0)}{tail}"


def build_snapshot(db: Session, run: PayrollRun, line: PayrollRunPerson) -> dict:
    engagement: Engagement = db.get(Engagement, line.engagement_id)
    person: Person = db.get(Person, engagement.person_id)
    org: Organization = db.get(Organization, run.organization_id)
    period = run.period

    dept = engagement.department.name if engagement.department else None
    position = engagement.position.title if engagement.position else None
    project = None
    if engagement.project_id:
        p = db.get(Project, engagement.project_id)
        project = p.project_name if p else None

    is_consultant = engagement.engagement_type in EngagementType.CONSULTANT_TYPES
    doc_type = DocumentType.PAYMENT_ADVICE if is_consultant else DocumentType.PAYSLIP

    obligations = obligation_service.obligation_summary_as_of(
        db,
        engagement_id=engagement.id,
        person_id=person.id,
        payroll_run_id=run.id,
        period_end=period.period_end,
    )

    header = {
        "company_name": org.name if org else "",
        "organization_code": org.code if org else "",
        "payroll_period": period.name,
        "period_start": period.period_start.isoformat(),
        "period_end": period.period_end.isoformat(),
        "pay_date": period.pay_date.isoformat() if period.pay_date else None,
        "name": person.full_name,
        "employee_number": engagement.employee_number,
        "department": dept,
        "position": position,
        "employment_type": engagement.engagement_type,
        "project": project,
        "cost_center": engagement.cost_center,
        "bank_account_masked": _mask_account(person.bank_account_number),
        "bank_name": person.bank_name,
    }

    return {
        "document_type": doc_type,
        "header": header,
        "earnings": line.earnings or {},
        "gross_pay": float(line.gross_pay or 0),
        "deductions": line.deductions or {},
        "total_deductions": float(line.total_deductions or 0),
        "net_pay": float(line.net_pay or 0),
        "obligations": obligations,
        "run_reference": run.reference,
        "rule_versions": run.rule_version_snapshot or {},
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "prototype_notice": (
            "PROTOTYPE — statutory values are illustrative, not authoritative."
        ),
    }


def _pdf_key(payslip_uuid: str) -> str:
    return f"payslips/{payslip_uuid}.pdf"


def generate_for_run(
    db: Session, run: PayrollRun, user_id: int | None = None, render_pdf: bool = True
) -> list[Payslip]:
    """(Re)generate documents for every line in the run.

    Existing current documents for the same engagement are superseded and a new
    version is created — old versions are preserved for audit. When `render_pdf`
    is False, records are created without pre-rendering PDFs (used by the seeder;
    PDFs are then rendered on demand from the immutable snapshot).
    """
    lines = db.query(PayrollRunPerson).filter(PayrollRunPerson.run_id == run.id).all()
    created: list[Payslip] = []

    for line in lines:
        prior = (
            db.query(Payslip)
            .filter(
                Payslip.payroll_run_id == run.id,
                Payslip.engagement_id == line.engagement_id,
                Payslip.is_current.is_(True),
            )
            .first()
        )
        version = 1
        if prior:
            prior.is_current = False
            prior.status = "SUPERSEDED"
            version = prior.version + 1

        snapshot = build_snapshot(db, run, line)
        engagement = db.get(Engagement, line.engagement_id)
        snapshot["version"] = version

        payslip = Payslip(
            organization_id=run.organization_id,
            payroll_run_id=run.id,
            engagement_id=line.engagement_id,
            person_id=engagement.person_id,
            document_type=snapshot["document_type"],
            version=version,
            is_current=True,
            status="ISSUED",
            net_pay=line.net_pay or 0,
            snapshot=snapshot,
            generated_by=user_id,
        )
        db.add(payslip)
        db.flush()  # assign uuid

        # Render + store the PDF (non-fatal if object storage is unavailable).
        if render_pdf:
            try:
                pdf_bytes = pdf_renderer.render_document(snapshot)
                key = _pdf_key(payslip.uuid)
                documents.put_bytes(key, pdf_bytes, content_type="application/pdf")
                payslip.pdf_object_key = key
            except Exception as exc:  # noqa: BLE001
                print(f"[payslip] PDF store skipped for {payslip.uuid}: {exc}")

        created.append(payslip)

    db.flush()
    return created


def generate_for_engagement(
    db: Session, run: PayrollRun, engagement_id: int, user_id: int | None = None
) -> Payslip:
    """Create (or return the current) payslip for ONE engagement in a run.

    Used by employee self-service — an employee generates their own payslip from
    a finalized run. Numbers come from the official PayrollRunPerson line.
    """
    existing = (
        db.query(Payslip)
        .filter(Payslip.payroll_run_id == run.id, Payslip.engagement_id == engagement_id,
                Payslip.is_current.is_(True))
        .first()
    )
    if existing:
        return existing

    line = (
        db.query(PayrollRunPerson)
        .filter(PayrollRunPerson.run_id == run.id, PayrollRunPerson.engagement_id == engagement_id)
        .first()
    )
    if not line:
        raise ValueError("No payroll line for this engagement in the run")

    snapshot = build_snapshot(db, run, line)
    snapshot["version"] = 1
    engagement = db.get(Engagement, engagement_id)
    payslip = Payslip(
        organization_id=run.organization_id, payroll_run_id=run.id, engagement_id=engagement_id,
        person_id=engagement.person_id, document_type=snapshot["document_type"], version=1,
        is_current=True, status="ISSUED", net_pay=line.net_pay or 0, snapshot=snapshot,
        generated_by=user_id,
    )
    db.add(payslip)
    db.flush()
    return payslip


def get_pdf_bytes(db: Session, payslip: Payslip) -> bytes:
    """Return the PDF, from MinIO if stored else re-rendered from the snapshot."""
    if payslip.pdf_object_key:
        data = documents.get_bytes(payslip.pdf_object_key)
        if data:
            return data
    return pdf_renderer.render_document(payslip.snapshot)
