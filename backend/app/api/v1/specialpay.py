"""13th-month pay and bonus runs (org-scoped, HR-driven, bulk).

13th-month is auto-computed (total basic earned in the year / 12) with per-line
HR override. Bonuses start at zero for HR to fill in. Target all active employees
or a chosen subset.
"""
from __future__ import annotations

import csv
import io

from fastapi import APIRouter, Body, Depends, HTTPException, Response
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.enums import EngagementType
from app.models.payroll import PayrollPeriod, PayrollRun, PayrollRunPerson
from app.models.person import Engagement, Person
from app.models.specialpay import SpecialPayLine, SpecialPayRun, SpecialPayType

router = APIRouter(prefix="/organizations/{organization_id}/special-pay", tags=["special-pay"])


def _basic_earned_in_year(db: Session, engagement_id: int, year: int) -> float:
    lines = (
        db.query(PayrollRunPerson)
        .join(PayrollRun, PayrollRun.id == PayrollRunPerson.run_id)
        .join(PayrollPeriod, PayrollPeriod.id == PayrollRun.period_id)
        .filter(PayrollRunPerson.engagement_id == engagement_id,
                PayrollPeriod.period_start.isnot(None))
        .all()
    )
    total = 0.0
    for ln in lines:
        period = ln.run.period if ln.run else None
        if period and period.period_start and period.period_start.year == year:
            total += float((ln.earnings or {}).get("basic", 0))
    return total


@router.get("", dependencies=[Depends(require_permission("payroll.view"))])
def list_runs(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = (db.query(SpecialPayRun).filter(SpecialPayRun.organization_id == organization_id)
            .order_by(SpecialPayRun.id.desc()).all())
    return [{"id": r.id, "uuid": r.uuid, "pay_type": r.pay_type, "name": r.name, "year": r.year,
             "status": r.status, "total_amount": float(r.total_amount or 0),
             "line_count": len(r.lines)} for r in rows]


@router.post("", dependencies=[Depends(require_permission("payroll.compute"))])
def create_run(organization_id: int, payload: dict = Body(...), _: int = Depends(require_org_access),
               db: Session = Depends(get_db)):
    pay_type = payload.get("pay_type", SpecialPayType.THIRTEENTH_MONTH).upper()
    if pay_type not in (SpecialPayType.THIRTEENTH_MONTH, SpecialPayType.BONUS):
        raise HTTPException(status_code=422, detail="pay_type must be 13TH_MONTH or BONUS")
    year = int(payload.get("year"))
    run = SpecialPayRun(organization_id=organization_id, pay_type=pay_type,
                        name=payload.get("name", f"{pay_type} {year}"), year=year, status="DRAFT")
    db.add(run)
    db.flush()

    # Target engagements: explicit list, or ALL active employees (non-consultant).
    eng_ids = payload.get("engagement_ids")
    q = db.query(Engagement).filter(Engagement.organization_id == organization_id,
                                    Engagement.status == "ACTIVE",
                                    Engagement.engagement_type.notin_(EngagementType.CONSULTANT_TYPES))
    if eng_ids:
        q = q.filter(Engagement.id.in_(eng_ids))
    total = 0.0
    for e in q.all():
        if pay_type == SpecialPayType.THIRTEENTH_MONTH:
            computed = round(_basic_earned_in_year(db, e.id, year) / 12, 2)
        else:
            computed = 0.0
        db.add(SpecialPayLine(run_id=run.id, engagement_id=e.id, computed_amount=computed))
        total += computed
    run.total_amount = round(total, 2)
    db.commit()
    db.refresh(run)
    return {"id": run.id, "line_count": len(run.lines), "total_amount": float(run.total_amount)}


@router.get("/{run_id}", dependencies=[Depends(require_permission("payroll.view"))])
def get_run(organization_id: int, run_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    run = db.get(SpecialPayRun, run_id)
    if not run or run.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Run not found")
    lines = []
    for ln in db.query(SpecialPayLine).filter(SpecialPayLine.run_id == run_id).all():
        eng = db.get(Engagement, ln.engagement_id)
        person = db.get(Person, eng.person_id) if eng else None
        lines.append({"line_id": ln.id, "engagement_id": ln.engagement_id,
                      "employee_number": eng.employee_number if eng else None,
                      "name": person.full_name if person else None,
                      "computed_amount": float(ln.computed_amount or 0),
                      "override_amount": float(ln.override_amount) if ln.override_amount is not None else None,
                      "final_amount": ln.final_amount, "remarks": ln.remarks})
    return {"id": run.id, "pay_type": run.pay_type, "name": run.name, "year": run.year,
            "status": run.status, "total_amount": float(run.total_amount or 0), "lines": lines}


def _recompute_total(db: Session, run: SpecialPayRun) -> None:
    run.total_amount = round(sum(ln.final_amount for ln in
                                 db.query(SpecialPayLine).filter(SpecialPayLine.run_id == run.id).all()), 2)


@router.put("/{run_id}/lines/{line_id}", dependencies=[Depends(require_permission("payroll.compute"))])
def override_line(organization_id: int, run_id: int, line_id: int, payload: dict = Body(...),
                  _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    run = db.get(SpecialPayRun, run_id)
    if not run or run.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Run not found")
    if run.status == "GENERATED":
        raise HTTPException(status_code=409, detail="Run already generated")
    ln = db.get(SpecialPayLine, line_id)
    if not ln or ln.run_id != run_id:
        raise HTTPException(status_code=404, detail="Line not found")
    if "override_amount" in payload:
        ln.override_amount = None if payload["override_amount"] is None else float(payload["override_amount"])
    if "remarks" in payload:
        ln.remarks = payload["remarks"]
    _recompute_total(db, run)
    db.commit()
    return {"line_id": ln.id, "final_amount": ln.final_amount, "run_total": float(run.total_amount)}


@router.post("/{run_id}/finalize", dependencies=[Depends(require_permission("payroll.approve"))])
def finalize(organization_id: int, run_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    run = db.get(SpecialPayRun, run_id)
    if not run or run.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Run not found")
    _recompute_total(db, run)
    run.status = "GENERATED"
    db.commit()
    return {"id": run.id, "status": run.status, "total_amount": float(run.total_amount)}


@router.get("/{run_id}/export", dependencies=[Depends(require_permission("reports.view"))])
def export_run(organization_id: int, run_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    run = db.get(SpecialPayRun, run_id)
    if not run or run.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Run not found")
    buf = io.StringIO()
    w = csv.writer(buf, lineterminator="\n")
    w.writerow(["Employee No.", "Name", "Computed", "Override", "Final", "Remarks"])
    total = 0.0
    for ln in db.query(SpecialPayLine).filter(SpecialPayLine.run_id == run_id).all():
        eng = db.get(Engagement, ln.engagement_id)
        person = db.get(Person, eng.person_id) if eng else None
        w.writerow([eng.employee_number if eng else "", person.full_name if person else "",
                    float(ln.computed_amount or 0),
                    float(ln.override_amount) if ln.override_amount is not None else "",
                    ln.final_amount, ln.remarks or ""])
        total += ln.final_amount
    w.writerow([]); w.writerow(["", "", "", "TOTAL", round(total, 2), ""])
    return Response(content=buf.getvalue().encode("utf-8"), media_type="text/csv",
                    headers={"Content-Disposition": f'attachment; filename="{run.pay_type}_{run.year}.csv"'})
