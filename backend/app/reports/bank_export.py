"""Configurable payroll bank-file generation.

An administrator defines a template (bank, file type, delimiter, columns with
field mapping/formatting/padding). This module resolves each payroll line into
the mapped columns, validates the data, previews it, and renders CSV/TXT/XLSX/
fixed-width output. Every generated file is hashed and recorded for audit.
"""
from __future__ import annotations

import csv
import hashlib
import io
from datetime import datetime

from sqlalchemy.orm import Session

from app.models.bank import BankExportTemplate
from app.models.organization import Organization
from app.models.payroll import PayrollRun, PayrollRunPerson
from app.models.person import Engagement, Person

# System fields a template column may map to.
SYSTEM_FIELDS = [
    "bank_account", "account_name", "full_name", "net_pay", "amount",
    "employee_number", "reference", "payroll_date", "bank_name",
    "department", "cost_center", "engagement_type",
]


def _resolve_fields(run: PayrollRun, line: PayrollRunPerson, eng: Engagement, person: Person) -> dict:
    period = run.period
    dept = eng.department.name if eng.department else ""
    pay_date = period.pay_date or period.period_end
    net = float(line.net_pay or 0)
    return {
        "bank_account": person.bank_account_number or "",
        "account_name": person.bank_account_name or person.full_name,
        "full_name": person.full_name,
        "net_pay": net,
        "amount": net,
        "employee_number": eng.employee_number or "",
        "reference": eng.employee_number or "",
        "payroll_date": pay_date,
        "bank_name": person.bank_name or "",
        "department": dept,
        "cost_center": eng.cost_center or "",
        "engagement_type": eng.engagement_type,
    }


def _apply_format(value, column, template: BankExportTemplate) -> str:
    fmt = (column.formatting or "").lower()
    if value is None or value == "":
        value = column.default_value or ""
    if fmt == "amount":
        try:
            value = f"{float(value):.{template.decimal_places}f}"
        except (TypeError, ValueError):
            value = "0"
    elif fmt == "amount_cents":
        try:
            value = str(int(round(float(value) * 100)))
        except (TypeError, ValueError):
            value = "0"
    elif fmt == "date":
        if hasattr(value, "strftime"):
            value = value.strftime(template.date_format)
        else:
            value = str(value)
    elif fmt == "upper":
        value = str(value).upper()
    else:
        value = str(value)

    # Padding: "left:width:fill" (right-align) or "right:width:fill" (left-align).
    if column.padding:
        try:
            side, width, fill = (column.padding.split(":") + ["0"])[:3]
            width = int(width)
            fill = fill or " "
            value = value.rjust(width, fill) if side == "left" else value.ljust(width, fill)
        except Exception:  # noqa: BLE001 — bad padding spec is non-fatal
            pass
    return value


def _select_lines(db: Session, run: PayrollRun, filters: dict | None):
    filters = filters or {}
    q = (
        db.query(PayrollRunPerson, Engagement, Person)
        .join(Engagement, Engagement.id == PayrollRunPerson.engagement_id)
        .join(Person, Person.id == Engagement.person_id)
        .filter(PayrollRunPerson.run_id == run.id)
    )
    if filters.get("engagement_type"):
        q = q.filter(Engagement.engagement_type == filters["engagement_type"])
    if filters.get("department_id"):
        q = q.filter(Engagement.department_id == filters["department_id"])
    if filters.get("engagement_ids"):
        q = q.filter(Engagement.id.in_(filters["engagement_ids"]))
    if filters.get("min_amount") is not None:
        q = q.filter(PayrollRunPerson.net_pay >= filters["min_amount"])
    return q.order_by(Person.last_name).all()


def validate(rows: list[dict]) -> dict:
    """Data-quality checks over resolved field rows (before formatting)."""
    missing_bank, invalid_accounts, zero_or_negative = [], [], []
    seen: dict[str, int] = {}
    for r in rows:
        acct = str(r["fields"]["bank_account"]).strip()
        name = r["name"]
        if not acct:
            missing_bank.append(name)
        else:
            if not acct.isdigit() or len(acct) < 6:
                invalid_accounts.append(f"{name} ({acct})")
            seen[acct] = seen.get(acct, 0) + 1
        if float(r["fields"]["net_pay"]) <= 0:
            zero_or_negative.append(name)
    duplicates = [a for a, c in seen.items() if c > 1]
    errors = []
    if missing_bank:
        errors.append(f"{len(missing_bank)} missing bank account(s)")
    if invalid_accounts:
        errors.append(f"{len(invalid_accounts)} invalid account number(s)")
    if duplicates:
        errors.append(f"{len(duplicates)} duplicate account(s)")
    if zero_or_negative:
        errors.append(f"{len(zero_or_negative)} zero/negative net pay")
    return {
        "errors": errors,
        "missing_bank": missing_bank,
        "invalid_accounts": invalid_accounts,
        "duplicate_accounts": duplicates,
        "zero_or_negative": zero_or_negative,
        "has_critical": bool(missing_bank or invalid_accounts or duplicates or zero_or_negative),
    }


def build_rows(db: Session, run: PayrollRun, template: BankExportTemplate, filters: dict | None):
    resolved = []
    for line, eng, person in _select_lines(db, run, filters):
        fields = _resolve_fields(run, line, eng, person)
        resolved.append({"name": person.full_name, "fields": fields})
    return resolved


def preview(db: Session, run: PayrollRun, template: BankExportTemplate, filters: dict | None) -> dict:
    resolved = build_rows(db, run, template, filters)
    headers = [c.output_header for c in template.columns]
    table = []
    total = 0.0
    for r in resolved:
        row = [_apply_format(r["fields"].get(c.system_field), c, template) for c in template.columns]
        table.append(row)
        total += float(r["fields"]["net_pay"])
    return {
        "headers": headers,
        "rows": table[:100],  # cap preview
        "employee_count": len(resolved),
        "total_amount": round(total, 2),
        "validation": validate(resolved),
        "template": {"name": template.template_name, "bank": template.bank_name,
                     "version": template.template_version, "file_type": template.file_type},
    }


def _render_file(template: BankExportTemplate, headers: list[str], rows: list[list[str]]) -> bytes:
    ft = template.file_type.upper()
    if ft == "XLSX":
        from openpyxl import Workbook

        wb = Workbook()
        ws = wb.active
        ws.title = "BankExport"
        if template.header_required:
            ws.append(headers)
        for row in rows:
            ws.append(row)
        buf = io.BytesIO()
        wb.save(buf)
        return buf.getvalue()

    if ft == "FIXED_WIDTH":
        lines = []
        if template.header_required:
            lines.append("".join(headers))
        for row in rows:
            lines.append("".join(row))
        return ("\n".join(lines) + "\n").encode(template.encoding)

    # CSV / TXT
    delim = template.delimiter or ","
    buf = io.StringIO()
    writer = csv.writer(buf, delimiter=delim, lineterminator="\n")
    if template.header_required:
        writer.writerow(headers)
    writer.writerows(rows)
    return buf.getvalue().encode(template.encoding)


def generate(db: Session, run: PayrollRun, template: BankExportTemplate, filters: dict | None) -> dict:
    resolved = build_rows(db, run, template, filters)
    headers = [c.output_header for c in template.columns]
    rows, total = [], 0.0
    for r in resolved:
        rows.append([_apply_format(r["fields"].get(c.system_field), c, template) for c in template.columns])
        total += float(r["fields"]["net_pay"])

    data = _render_file(template, headers, rows)
    file_hash = hashlib.sha256(data).hexdigest()
    ext = {"XLSX": "xlsx", "FIXED_WIDTH": "txt", "TXT": "txt"}.get(template.file_type.upper(), "csv")
    date_str = datetime.now().strftime("%Y%m%d")
    org = db.get(Organization, run.organization_id)
    org_code = org.code if org else "ORG"
    file_name = (
        template.filename_pattern.format(bank=template.bank_name.replace(" ", ""), org=org_code, date=date_str)
        + f".{ext}"
    )
    return {
        "data": data,
        "file_name": file_name,
        "file_hash": file_hash,
        "row_count": len(rows),
        "total_amount": round(total, 2),
        "content_type": (
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            if ext == "xlsx" else "text/plain"
        ),
    }
