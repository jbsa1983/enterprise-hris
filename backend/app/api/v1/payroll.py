"""Payroll runs, lifecycle transitions, and payslip generation (org-scoped)."""
from __future__ import annotations

import io
import zipfile
from datetime import date

from fastapi import APIRouter, Body, Depends, HTTPException, Request, Response
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import get_current_user, require_org_access, require_permission
from app.models.enums import PayrollStatus
from app.models.payroll import PayrollPeriod, PayrollRun
from app.models.payslip import Payslip
from app.models.user import User
from app.payroll import payslip_service, run_service
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


# --------------------------------------------------------------------------- #
# Periods + run creation + compute
# --------------------------------------------------------------------------- #
@router.get("/periods", dependencies=[Depends(require_permission("payroll.view"))])
def list_periods(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = (
        db.query(PayrollPeriod).filter(PayrollPeriod.organization_id == organization_id)
        .order_by(PayrollPeriod.period_start.desc()).all()
    )
    return [{"id": p.id, "name": p.name, "frequency": p.frequency,
             "period_start": p.period_start.isoformat(), "period_end": p.period_end.isoformat(),
             "pay_date": p.pay_date.isoformat() if p.pay_date else None} for p in rows]


@router.post("/periods", dependencies=[Depends(require_permission("payroll.prepare"))])
def create_period(organization_id: int, payload: dict = Body(...), _: int = Depends(require_org_access),
                  db: Session = Depends(get_db)):
    p = PayrollPeriod(
        organization_id=organization_id, name=payload["name"], frequency=payload.get("frequency", "MONTHLY"),
        period_start=date.fromisoformat(payload["period_start"]), period_end=date.fromisoformat(payload["period_end"]),
        pay_date=date.fromisoformat(payload["pay_date"]) if payload.get("pay_date") else None,
    )
    db.add(p)
    db.commit()
    return {"id": p.id, "name": p.name}


@router.post("/runs", dependencies=[Depends(require_permission("payroll.prepare"))])
def create_run(organization_id: int, request: Request, payload: dict = Body(...),
               _: int = Depends(require_org_access),
               user: User = Depends(require_permission("payroll.prepare")),
               db: Session = Depends(get_db)):
    period = db.get(PayrollPeriod, payload["period_id"])
    if not period or period.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Payroll period not found")
    ref = payload.get("reference") or f"RUN-{organization_id}-{period.period_start.strftime('%Y%m')}"
    run = PayrollRun(organization_id=organization_id, period_id=period.id, reference=ref,
                     status=PayrollStatus.DRAFT)
    db.add(run)
    db.flush()
    audit_service.record(db, action="payroll.create", user=user, organization_id=organization_id,
                         entity="payroll_run", entity_id=run.id, after={"reference": ref},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    return {"id": run.id, "reference": run.reference, "status": run.status}


@router.post("/runs/{run_id}/compute")
def compute(organization_id: int, run_id: int, request: Request, payload: dict = Body(default={}),
            _: int = Depends(require_org_access),
            user: User = Depends(require_permission("payroll.compute")),
            db: Session = Depends(get_db)):
    run = _get_run(db, organization_id, run_id)
    try:
        run_service.compute_run(db, run, allowance=float(payload.get("allowance", 0)))
    except ValueError as e:
        raise HTTPException(status_code=409, detail=str(e))
    audit_service.record(db, action="payroll.compute", user=user, organization_id=organization_id,
                         entity="payroll_run", entity_id=run.id,
                         after={"gross": float(run.gross_total), "net": float(run.net_total)},
                         ip_address=request.client.host if request.client else None, commit=False)
    db.commit()
    return {"id": run.id, "status": run.status, "gross_total": float(run.gross_total),
            "deduction_total": float(run.deduction_total), "net_total": float(run.net_total)}


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
