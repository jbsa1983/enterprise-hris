"""Generic report builder endpoints + saved report templates (org-scoped)."""
from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, Query, Response
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.deps import require_org_access, require_permission
from app.models.reports import ReportTemplate
from app.models.user import User
from app.reports import report_builder
from app.schemas.exports import ReportRequest, ReportTemplateIn

router = APIRouter(prefix="/organizations/{organization_id}/reports", tags=["reports"])


@router.get("/datasets", dependencies=[Depends(require_permission("reports.view"))])
def datasets(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    out = []
    for name in report_builder.DATASETS:
        out.append({"name": name, "columns": report_builder.dataset_columns(name, db, organization_id)})
    return out


@router.post("/preview", dependencies=[Depends(require_permission("reports.view"))])
def preview(
    organization_id: int, payload: ReportRequest,
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    if payload.dataset not in report_builder.DATASETS:
        raise HTTPException(status_code=400, detail="Unknown dataset")
    return report_builder.preview(db, organization_id, payload.dataset, payload.config)


@router.post("/export", dependencies=[Depends(require_permission("reports.export"))])
def export(
    organization_id: int, payload: ReportRequest,
    fmt: str = Query("csv", pattern="^(csv|xlsx|pdf)$"),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
) -> Response:
    if payload.dataset not in report_builder.DATASETS:
        raise HTTPException(status_code=400, detail="Unknown dataset")
    result = report_builder.export(db, organization_id, payload.dataset, payload.config, fmt)
    fname = f"{payload.dataset}_report.{result['ext']}"
    return Response(
        content=result["data"], media_type=result["content_type"],
        headers={"Content-Disposition": f'attachment; filename="{fname}"'},
    )


@router.get("/templates", dependencies=[Depends(require_permission("reports.view"))])
def list_templates(organization_id: int, _: int = Depends(require_org_access), db: Session = Depends(get_db)):
    rows = db.query(ReportTemplate).filter(
        (ReportTemplate.organization_id == organization_id) | (ReportTemplate.organization_id.is_(None))
    ).all()
    return [{"uuid": r.uuid, "name": r.name, "dataset": r.dataset, "config": r.config_json} for r in rows]


@router.post("/templates", dependencies=[Depends(require_permission("reports.view"))])
def save_template(
    organization_id: int, payload: ReportTemplateIn,
    user: User = Depends(require_permission("reports.view")),
    _: int = Depends(require_org_access), db: Session = Depends(get_db),
):
    t = ReportTemplate(organization_id=organization_id, name=payload.name, dataset=payload.dataset,
                       config_json=payload.config, created_by=user.id)
    db.add(t)
    db.commit()
    db.refresh(t)
    return {"uuid": t.uuid, "name": t.name, "dataset": t.dataset, "config": t.config_json}
