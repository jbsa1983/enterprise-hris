"""Bank-export templates, preview, generation, and history (org-scoped)."""
from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, Request, Response
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.bank import BankExportColumn, BankExportRun, BankExportTemplate
from app.models.payroll import PayrollRun
from app.models.user import User
from app.reports import bank_export
from app.schemas.exports import BankExportRequest, BankTemplateIn
from app.services import audit_service
from app.storage import documents

router = APIRouter(prefix="/organizations/{organization_id}", tags=["bank-export"])


def _template_dict(t: BankExportTemplate) -> dict:
    return {
        "id": t.id, "uuid": t.uuid, "template_name": t.template_name, "bank_name": t.bank_name,
        "template_version": t.template_version, "file_type": t.file_type, "delimiter": t.delimiter,
        "header_required": t.header_required, "date_format": t.date_format,
        "decimal_places": t.decimal_places, "filename_pattern": t.filename_pattern, "active": t.active,
        "columns": [
            {"system_field": c.system_field, "output_header": c.output_header, "order_index": c.order_index,
             "default_value": c.default_value, "formatting": c.formatting, "padding": c.padding,
             "required": c.required}
            for c in t.columns
        ],
    }


@router.get("/bank-templates", dependencies=[Depends(require_permission("payroll.view"))])
def list_templates(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    ts = db.query(BankExportTemplate).filter(BankExportTemplate.organization_id == organization_id).all()
    return [_template_dict(t) for t in ts]


@router.get("/bank-fields", dependencies=[Depends(require_permission("payroll.view"))])
def list_fields(organization_id: int, _: int = Depends(require_org_access)):
    return {"system_fields": bank_export.SYSTEM_FIELDS,
            "formats": ["", "amount", "amount_cents", "date", "upper"],
            "file_types": ["CSV", "TXT", "XLSX", "FIXED_WIDTH"]}


@router.post("/bank-templates", dependencies=[Depends(require_permission("payroll.export"))])
def create_template(
    organization_id: int, payload: BankTemplateIn,
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    t = BankExportTemplate(
        organization_id=organization_id, template_name=payload.template_name, bank_name=payload.bank_name,
        file_type=payload.file_type, delimiter=payload.delimiter, encoding=payload.encoding,
        header_required=payload.header_required, footer_required=payload.footer_required,
        date_format=payload.date_format, decimal_places=payload.decimal_places,
        filename_pattern=payload.filename_pattern, template_version=1,
    )
    db.add(t)
    db.flush()
    for c in payload.columns:
        db.add(BankExportColumn(template_id=t.id, **c.model_dump()))
    db.commit()
    db.refresh(t)
    return _template_dict(t)


@router.put("/bank-templates/{template_id}", dependencies=[Depends(require_permission("payroll.export"))])
def update_template(
    organization_id: int, template_id: int, payload: BankTemplateIn,
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    t = db.get(BankExportTemplate, template_id)
    if not t or t.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Template not found")
    for field in ("template_name", "bank_name", "file_type", "delimiter", "encoding",
                  "header_required", "footer_required", "date_format", "decimal_places", "filename_pattern"):
        setattr(t, field, getattr(payload, field))
    t.template_version += 1  # versioning on edit
    db.query(BankExportColumn).filter(BankExportColumn.template_id == t.id).delete()
    for c in payload.columns:
        db.add(BankExportColumn(template_id=t.id, **c.model_dump()))
    db.commit()
    db.refresh(t)
    return _template_dict(t)


def _get_run(db, organization_id, run_id) -> PayrollRun:
    run = db.get(PayrollRun, run_id)
    if not run or run.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Payroll run not found")
    return run


@router.post("/payroll/runs/{run_id}/bank-export/preview", dependencies=[Depends(require_permission("payroll.view"))])
def preview_export(
    organization_id: int, run_id: int, payload: BankExportRequest,
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    run = _get_run(db, organization_id, run_id)
    template = db.get(BankExportTemplate, payload.template_id)
    if not template or template.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Template not found")
    return bank_export.preview(db, run, template, payload.filters)


@router.post("/payroll/runs/{run_id}/bank-export/generate")
def generate_export(
    organization_id: int, run_id: int, payload: BankExportRequest, request: Request,
    _: int = Depends(require_org_access),
    user: User = Depends(require_permission("payroll.export")),
    db: Session = Depends(get_db),
) -> Response:
    run = _get_run(db, organization_id, run_id)
    template = db.get(BankExportTemplate, payload.template_id)
    if not template or template.organization_id != organization_id:
        raise HTTPException(status_code=404, detail="Template not found")

    # Critical validation blocks generation unless an authorized user overrides.
    pv = bank_export.preview(db, run, template, payload.filters)
    if pv["validation"]["has_critical"] and not payload.allow_with_errors:
        raise HTTPException(
            status_code=422,
            detail={"message": "Validation errors block generation", "validation": pv["validation"]},
        )

    result = bank_export.generate(db, run, template, payload.filters)
    key = f"bank_exports/{run.uuid}_{template.id}_{result['file_hash'][:8]}.{result['file_name'].split('.')[-1]}"
    try:
        documents.put_bytes(key, result["data"], content_type=result["content_type"])
    except Exception as exc:  # noqa: BLE001
        print(f"[bank-export] store skipped: {exc}")
        key = None

    export_run = BankExportRun(
        organization_id=organization_id, payroll_run_id=run.id, template_id=template.id,
        template_version=template.template_version, file_name=result["file_name"],
        file_hash=result["file_hash"], row_count=result["row_count"], total_amount=result["total_amount"],
        object_key=key, filters_json=payload.filters, generated_by=user.id,
    )
    db.add(export_run)
    audit_service.record(
        db, action="payroll.bank_export", user=user, organization_id=organization_id,
        entity="payroll_run", entity_id=run.id,
        after={"file": result["file_name"], "hash": result["file_hash"], "rows": result["row_count"]},
        ip_address=request.client.host if request.client else None, commit=False,
    )
    db.commit()

    return Response(
        content=result["data"], media_type=result["content_type"],
        headers={"Content-Disposition": f'attachment; filename="{result["file_name"]}"'},
    )


@router.get("/bank-export-runs", dependencies=[Depends(require_permission("payroll.view"))])
def list_export_runs(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = (
        db.query(BankExportRun)
        .filter(BankExportRun.organization_id == organization_id)
        .order_by(BankExportRun.id.desc())
        .all()
    )
    return [
        {"uuid": r.uuid, "file_name": r.file_name, "file_hash": r.file_hash, "row_count": r.row_count,
         "total_amount": float(r.total_amount or 0), "template_version": r.template_version,
         "generated_at": r.created_at.isoformat()}
        for r in rows
    ]
