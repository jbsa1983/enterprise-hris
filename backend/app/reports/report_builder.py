"""Generic report builder.

A dataset is a named row source (list of flat dicts) with a column list. The
builder applies column selection, row filters, sorting, and a limit, then
previews or exports to CSV / XLSX / PDF. Report configurations can be saved as
templates (see ReportTemplate model).
"""
from __future__ import annotations

import csv
import io

from sqlalchemy import func
from sqlalchemy.orm import Session

from app.models.enums import EngagementType
from app.models.loan import Loan
from app.models.payroll import PayrollRun, PayrollRunPerson
from app.models.person import Engagement, Person
from app.models.project import Project


# --- dataset providers -------------------------------------------------------
def _employees(db: Session, org_id: int) -> list[dict]:
    rows = (
        db.query(Engagement, Person)
        .join(Person, Person.id == Engagement.person_id)
        .filter(Engagement.organization_id == org_id,
                Engagement.engagement_type.notin_(EngagementType.CONSULTANT_TYPES))
        .all()
    )
    return [
        {
            "employee_number": e.employee_number, "name": p.full_name,
            "engagement_type": e.engagement_type, "status": e.status,
            "base_rate": float(e.base_rate or 0), "salary_basis": e.salary_basis,
            "start_date": e.start_date.isoformat() if e.start_date else None,
        }
        for e, p in rows
    ]


def _consultants(db: Session, org_id: int) -> list[dict]:
    rows = (
        db.query(Engagement, Person)
        .join(Person, Person.id == Engagement.person_id)
        .filter(Engagement.organization_id == org_id,
                Engagement.engagement_type.in_(EngagementType.CONSULTANT_TYPES))
        .all()
    )
    return [
        {"contract_number": e.employee_number, "name": p.full_name,
         "type": e.engagement_type, "fee": float(e.base_rate or 0), "status": e.status}
        for e, p in rows
    ]


def _payroll(db: Session, org_id: int) -> list[dict]:
    rows = (
        db.query(PayrollRunPerson, Engagement, Person, PayrollRun)
        .join(Engagement, Engagement.id == PayrollRunPerson.engagement_id)
        .join(Person, Person.id == Engagement.person_id)
        .join(PayrollRun, PayrollRun.id == PayrollRunPerson.run_id)
        .filter(PayrollRun.organization_id == org_id)
        .all()
    )
    return [
        {"run": r.reference, "employee_number": e.employee_number, "name": p.full_name,
         "gross_pay": float(l.gross_pay or 0), "total_deductions": float(l.total_deductions or 0),
         "net_pay": float(l.net_pay or 0)}
        for l, e, p, r in rows
    ]


def _loans(db: Session, org_id: int) -> list[dict]:
    rows = (
        db.query(Loan, Person)
        .join(Person, Person.id == Loan.person_id)
        .filter(Loan.organization_id == org_id)
        .all()
    )
    return [
        {"name": p.full_name, "type": ln.obligation_type, "reference": ln.reference_number,
         "principal": float(ln.principal or 0), "balance": float(ln.balance or 0),
         "installment": float(ln.installment_amount or 0), "status": ln.status}
        for ln, p in rows
    ]


def _projects(db: Session, org_id: int) -> list[dict]:
    rows = db.query(Project).filter(Project.organization_id == org_id).all()
    return [
        {"project_code": p.project_code, "project_name": p.project_name, "status": p.status,
         "labor_budget": float(p.labor_budget or 0),
         "start_date": p.start_date.isoformat() if p.start_date else None}
        for p in rows
    ]


def _statutory(db: Session, org_id: int) -> list[dict]:
    """Statutory contribution totals per employee from payroll deductions."""
    rows = (
        db.query(PayrollRunPerson, Engagement, Person)
        .join(Engagement, Engagement.id == PayrollRunPerson.engagement_id)
        .join(Person, Person.id == Engagement.person_id)
        .join(PayrollRun, PayrollRun.id == PayrollRunPerson.run_id)
        .filter(PayrollRun.organization_id == org_id)
        .all()
    )
    out = []
    for l, e, p in rows:
        d = l.deductions or {}
        out.append({
            "employee_number": e.employee_number, "name": p.full_name,
            "sss": float(d.get("sss", 0)), "philhealth": float(d.get("philhealth", 0)),
            "pagibig": float(d.get("pagibig", 0)), "withholding_tax": float(d.get("withholding_tax", 0)),
        })
    return out


def _payroll_by_period(db: Session, org_id: int) -> list[dict]:
    from app.models.payroll import PayrollPeriod

    rows = (
        db.query(PayrollPeriod.name,
                 func.coalesce(func.sum(PayrollRunPerson.gross_pay), 0),
                 func.coalesce(func.sum(PayrollRunPerson.total_deductions), 0),
                 func.coalesce(func.sum(PayrollRunPerson.net_pay), 0),
                 func.count(PayrollRunPerson.id))
        .join(PayrollRun, PayrollRun.period_id == PayrollPeriod.id)
        .join(PayrollRunPerson, PayrollRunPerson.run_id == PayrollRun.id)
        .filter(PayrollPeriod.organization_id == org_id)
        .group_by(PayrollPeriod.id, PayrollPeriod.name)
        .all()
    )
    return [{"period": name, "headcount": int(hc), "gross": float(g), "deductions": float(d), "net": float(n)}
            for name, g, d, n, hc in rows]


def _payroll_by_project(db: Session, org_id: int) -> list[dict]:
    rows = (
        db.query(Project.project_code, Project.project_name,
                 func.coalesce(func.sum(PayrollRunPerson.gross_pay), 0),
                 func.count(func.distinct(Engagement.id)))
        .join(Engagement, Engagement.project_id == Project.id)
        .join(PayrollRunPerson, PayrollRunPerson.engagement_id == Engagement.id)
        .filter(Project.organization_id == org_id)
        .group_by(Project.id, Project.project_code, Project.project_name)
        .all()
    )
    return [{"project_code": c, "project_name": n, "headcount": int(hc), "total_cost": float(cost)}
            for c, n, cost, hc in rows]


DATASETS = {
    "employees": _employees,
    "consultants": _consultants,
    "payroll": _payroll,
    "payroll_by_period": _payroll_by_period,
    "payroll_by_project": _payroll_by_project,
    "loans": _loans,
    "projects": _projects,
    "statutory_contributions": _statutory,
}


def dataset_columns(dataset: str, db: Session, org_id: int) -> list[str]:
    rows = DATASETS[dataset](db, org_id)
    return list(rows[0].keys()) if rows else []


def _apply(rows: list[dict], config: dict) -> tuple[list[str], list[dict]]:
    filters = config.get("filters", [])
    for f in filters:
        field, op, val = f.get("field"), f.get("op", "eq"), f.get("value")
        if op == "eq":
            rows = [r for r in rows if str(r.get(field)) == str(val)]
        elif op == "contains":
            rows = [r for r in rows if str(val).lower() in str(r.get(field, "")).lower()]
        elif op == "gte":
            rows = [r for r in rows if _num(r.get(field)) >= _num(val)]
        elif op == "lte":
            rows = [r for r in rows if _num(r.get(field)) <= _num(val)]
    sort = config.get("sort")
    if sort and sort.get("field"):
        rows = sorted(rows, key=lambda r: (r.get(sort["field"]) is None, r.get(sort["field"])),
                      reverse=(sort.get("dir") == "desc"))
    columns = config.get("columns") or (list(rows[0].keys()) if rows else [])
    limit = config.get("limit")
    if limit:
        rows = rows[: int(limit)]
    return columns, rows


def _num(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return 0.0


def build(db: Session, org_id: int, dataset: str, config: dict) -> tuple[list[str], list[dict]]:
    if dataset not in DATASETS:
        raise ValueError(f"Unknown dataset: {dataset}")
    rows = DATASETS[dataset](db, org_id)
    return _apply(rows, config)


def preview(db: Session, org_id: int, dataset: str, config: dict) -> dict:
    columns, rows = build(db, org_id, dataset, config)
    return {"columns": columns, "rows": rows[:100], "row_count": len(rows)}


def export(db: Session, org_id: int, dataset: str, config: dict, fmt: str) -> dict:
    columns, rows = build(db, org_id, dataset, config)
    fmt = fmt.lower()
    if fmt == "csv":
        buf = io.StringIO()
        w = csv.writer(buf, lineterminator="\n")
        w.writerow(columns)
        for r in rows:
            w.writerow([r.get(c, "") for c in columns])
        return {"data": buf.getvalue().encode("utf-8"), "content_type": "text/csv", "ext": "csv"}
    if fmt == "xlsx":
        from openpyxl import Workbook

        wb = Workbook()
        ws = wb.active
        ws.title = dataset[:31]
        ws.append(columns)
        for r in rows:
            ws.append([r.get(c, "") for c in columns])
        buf = io.BytesIO()
        wb.save(buf)
        return {"data": buf.getvalue(),
                "content_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                "ext": "xlsx"}
    if fmt == "pdf":
        from weasyprint import HTML

        th = "".join(f"<th>{c}</th>" for c in columns)
        trs = "".join(
            "<tr>" + "".join(f"<td>{r.get(c, '')}</td>" for c in columns) + "</tr>" for r in rows
        )
        html = f"""<html><head><style>
          @page {{ size: A4 landscape; margin: 12mm; }}
          body {{ font-family: 'DejaVu Sans'; font-size:9px; }}
          h2 {{ color:#1e3a8a; }} table {{ border-collapse:collapse; width:100%; }}
          th,td {{ border:1px solid #d1d5db; padding:3px 5px; text-align:left; }}
          th {{ background:#f3f4f6; }}
        </style></head><body>
          <h2>{dataset.replace('_',' ').title()} Report</h2>
          <table><thead><tr>{th}</tr></thead><tbody>{trs}</tbody></table>
        </body></html>"""
        return {"data": HTML(string=html).write_pdf(), "content_type": "application/pdf", "ext": "pdf"}
    raise ValueError(f"Unsupported format: {fmt}")
