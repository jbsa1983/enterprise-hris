"""Payroll runs, lifecycle transitions, and payslip generation (org-scoped)."""
from __future__ import annotations

import io
import zipfile

from fastapi import APIRouter, Depends, HTTPException, Request, Response
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_user, require_org_access, require_permission
from app.models.enums import PayrollStatus
from app.models.payroll import PayrollRun
from app.models.payslip import Payslip
from app.models.user import User
from app.payroll import payslip_service
from app.services import audit_service

router = APIRouter(prefix="/organizations/{organization_id}/payroll", tags=["payroll"])


def _get_run(db: Session, organization_id: int, run_id: int) -> PayrollRun:
    run = db.get(PayrollRun, run_id)
    if not run or run.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Payroll run not found")
    return run


@router.get("/runs", dependencies=[Depends(require_permission("payroll.view"))])
def list_runs(
    organization_id: int,
    _: int = Depends(require_org_access),
    db: Session = Depends(get_db),
) -> list[dict]:
    runs = (
        db.query(PayrollRun)
        .filter(PayrollRun.organization_id == organization_id)
        .order_by(PayrollRun.id.desc())
        .all()
    )
    out = []
    for r in runs:
        out.append(
            {
                "id": r.id,
                "uuid": r.uuid,
                "reference": r.reference,
                "status": r.status,
                "period": r.period.name if r.period else None,
                "period_end": r.period.period_end.isoformat() if r.period else None,
                "gross_total": float(r.gross_total or 0),
                "deduction_total": float(r.deduction_total or 0),
                "net_total": float(r.net_total or 0),
                "line_count": len(r.lines),
                "rule_versions": r.rule_version_snapshot or {},
            }
        )
    return out


def _transition(db, run, request, user, new_status, action):
    before = run.status
    run.status = new_status
    audit_service.record(
        db, action=action, user=user, organization_id=run.organization_id,
        entity="payroll_run", entity_id=run.id, before={"status": before},
        after={"status": new_status},
        ip_address=request.client.host if request.client else None, commit=False,
    )
    db.commit()
    return {"id": run.id, "status": run.status}


@router.post("/runs/{run_id}/approve")
def approve_run(
    organization_id: int, run_id: int, request: Request,
    _: int = Depends(require_org_access),
    user: User = Depends(require_permission("payroll.approve")),
    db: Session = Depends(get_db),
) -> dict:
    run = _get_run(db, organization_id, run_id)
    if run.status == PayrollStatus.LOCKED:
        raise HTTPException(status_code=409, detail="Locked payroll cannot be re-approved")
    return _transition(db, run, request, user, PayrollStatus.APPROVED, "payroll.approve")


@router.post("/runs/{run_id}/lock")
def lock_run(
    organization_id: int, run_id: int, request: Request,
    _: int = Depends(require_org_access),
    user: User = Depends(require_permission("payroll.lock")),
    db: Session = Depends(get_db),
) -> dict:
    run = _get_run(db, organization_id, run_id)
    if run.status not in (PayrollStatus.APPROVED, PayrollStatus.LOCKED):
        raise HTTPException(status_code=409, detail="Only approved payroll can be locked")
    return _transition(db, run, request, user, PayrollStatus.LOCKED, "payroll.lock")


@router.post("/runs/{run_id}/generate-payslips")
def generate_payslips(
    organization_id: int, run_id: int, request: Request,
    _: int = Depends(require_org_access),
    user: User = Depends(require_permission("payroll.export")),
    db: Session = Depends(get_db),
) -> dict:
    run = _get_run(db, organization_id, run_id)
    created = payslip_service.generate_for_run(db, run, user_id=user.id)
    audit_service.record(
        db, action="payslip.generate", user=user, organization_id=organization_id,
        entity="payroll_run", entity_id=run.id, after={"count": len(created)},
        ip_address=request.client.host if request.client else None, commit=False,
    )
    db.commit()
    return {"generated": len(created), "run_id": run.id}


@router.get("/runs/{run_id}/payslips", dependencies=[Depends(require_permission("payroll.view"))])
def list_payslips(
    organization_id: int, run_id: int,
    _: int = Depends(require_org_access),
    db: Session = Depends(get_db),
) -> list[dict]:
    _get_run(db, organization_id, run_id)
    rows = (
        db.query(Payslip)
        .filter(Payslip.payroll_run_id == run_id, Payslip.is_current.is_(True))
        .order_by(Payslip.id)
        .all()
    )
    return [
        {
            "uuid": p.uuid,
            "document_type": p.document_type,
            "name": p.snapshot.get("header", {}).get("name"),
            "employee_number": p.snapshot.get("header", {}).get("employee_number"),
            "net_pay": float(p.net_pay or 0),
            "version": p.version,
            "has_pdf": bool(p.pdf_object_key),
        }
        for p in rows
    ]


@router.get("/runs/{run_id}/payslips.pdf", dependencies=[Depends(require_permission("payroll.view"))])
def bulk_pdf(
    organization_id: int, run_id: int,
    _: int = Depends(require_org_access),
    db: Session = Depends(get_db),
) -> Response:
    from app.reports import pdf as pdf_renderer

    _get_run(db, organization_id, run_id)
    rows = (
        db.query(Payslip)
        .filter(Payslip.payroll_run_id == run_id, Payslip.is_current.is_(True))
        .order_by(Payslip.id)
        .all()
    )
    if not rows:
        raise HTTPException(status_code=404, detail="No payslips generated for this run")
    data = pdf_renderer.render_bulk([p.snapshot for p in rows])
    return Response(
        content=data,
        media_type="application/pdf",
        headers={"Content-Disposition": f'inline; filename="payslips_run_{run_id}.pdf"'},
    )


@router.get("/runs/{run_id}/payslips.zip", dependencies=[Depends(require_permission("payroll.view"))])
def bulk_zip(
    organization_id: int, run_id: int,
    _: int = Depends(require_org_access),
    db: Session = Depends(get_db),
) -> Response:
    _get_run(db, organization_id, run_id)
    rows = (
        db.query(Payslip)
        .filter(Payslip.payroll_run_id == run_id, Payslip.is_current.is_(True))
        .order_by(Payslip.id)
        .all()
    )
    if not rows:
        raise HTTPException(status_code=404, detail="No payslips generated for this run")

    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        for p in rows:
            pdf_bytes = payslip_service.get_pdf_bytes(db, p)
            emp = (p.snapshot.get("header", {}).get("employee_number") or p.uuid)
            zf.writestr(f"{emp}.pdf", pdf_bytes)
    buf.seek(0)
    return Response(
        content=buf.read(),
        media_type="application/zip",
        headers={"Content-Disposition": f'attachment; filename="payslips_run_{run_id}.zip"'},
    )
