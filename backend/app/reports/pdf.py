"""HTML→PDF rendering for payslips and consultant payment advices (WeasyPrint).

Amounts are labeled "PHP" as text (rather than the ₱ glyph) to stay safe across
base fonts. Templates are plain corporate layouts — no heavy styling.
"""
from __future__ import annotations

from jinja2 import Environment

_env = Environment(autoescape=True)


def _money(v) -> str:
    try:
        return f"PHP {float(v):,.2f}"
    except (TypeError, ValueError):
        return "PHP 0.00"


_env.filters["money"] = _money
_env.filters["nice"] = lambda s: str(s).replace("_", " ").title() if s else ""

_BASE_CSS = """
  @page { size: A4; margin: 18mm 16mm; }
  * { font-family: 'DejaVu Sans', sans-serif; }
  body { color: #1f2937; font-size: 11px; }
  .doc { page-break-after: always; }
  .doc:last-child { page-break-after: auto; }
  h1 { font-size: 16px; margin: 0; color: #1e3a8a; }
  .sub { color: #6b7280; font-size: 10px; }
  .notice { background:#fff7ed; color:#9a3412; padding:4px 8px; font-size:9px;
            border:1px solid #fed7aa; border-radius:4px; margin-top:8px; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; }
  .kv td { padding: 2px 4px; vertical-align: top; }
  .kv td.k { color:#6b7280; width: 130px; }
  .section-title { margin: 14px 0 2px; font-size: 11px; font-weight: bold;
                   color:#374151; border-bottom: 2px solid #e5e7eb; padding-bottom:2px; }
  .amt { border:1px solid #e5e7eb; }
  .amt th, .amt td { border:1px solid #e5e7eb; padding: 4px 6px; text-align:left; }
  .amt th { background:#f3f4f6; }
  .amt td.n, .amt th.n { text-align:right; }
  .totals td { padding:4px 6px; font-weight:bold; }
  .net { font-size: 14px; color:#065f46; }
  .header-row { display:flex; justify-content:space-between; align-items:flex-start; }
"""

_PAYSLIP_TMPL = _env.from_string(
    """
<div class="doc">
  <div class="header-row">
    <div>
      <h1>{{ h.company_name }}</h1>
      <div class="sub">Payslip — {{ h.payroll_period }}
        ({{ h.period_start }} to {{ h.period_end }})</div>
    </div>
    <div class="sub" style="text-align:right">
      Version {{ s.version }}<br/>Ref: {{ s.run_reference }}
    </div>
  </div>

  <table class="kv">
    <tr><td class="k">Employee</td><td>{{ h.name }}</td>
        <td class="k">Employee No.</td><td>{{ h.employee_number or '—' }}</td></tr>
    <tr><td class="k">Department</td><td>{{ h.department or '—' }}</td>
        <td class="k">Position</td><td>{{ h.position or '—' }}</td></tr>
    <tr><td class="k">Type</td><td>{{ h.employment_type|nice }}</td>
        <td class="k">Project</td><td>{{ h.project or '—' }}</td></tr>
    <tr><td class="k">Cost Center</td><td>{{ h.cost_center or '—' }}</td>
        <td class="k">Bank</td><td>{{ h.bank_name or '—' }} {{ h.bank_account_masked }}</td></tr>
    <tr><td class="k">Pay Date</td><td>{{ h.pay_date or '—' }}</td>
        <td class="k"></td><td></td></tr>
  </table>

  <div style="display:flex; gap:14px; margin-top:8px;">
    <div style="flex:1">
      <div class="section-title">Earnings</div>
      <table class="amt">
        <tr><th>Item</th><th class="n">Amount</th></tr>
        {% for k, v in s.earnings.items() %}
        <tr><td>{{ k|nice }}</td><td class="n">{{ v|money }}</td></tr>
        {% endfor %}
        <tr class="totals"><td>Gross Pay</td><td class="n">{{ s.gross_pay|money }}</td></tr>
      </table>
    </div>
    <div style="flex:1">
      <div class="section-title">Deductions</div>
      <table class="amt">
        <tr><th>Item</th><th class="n">Amount</th></tr>
        {% for k, v in s.deductions.items() %}
        <tr><td>{{ k|nice }}</td><td class="n">{{ v|money }}</td></tr>
        {% endfor %}
        <tr class="totals"><td>Total Deductions</td><td class="n">{{ s.total_deductions|money }}</td></tr>
      </table>
    </div>
  </div>

  <table class="amt totals" style="margin-top:10px;">
    <tr><td>NET PAY</td><td class="n net">{{ s.net_pay|money }}</td></tr>
  </table>

  <div class="section-title">Outstanding Obligations (as of {{ h.period_end }})</div>
  {% if s.obligations.rows %}
  <table class="amt">
    <tr><th>Description</th><th class="n">Original</th><th class="n">This Period</th>
        <th class="n">Total Paid</th><th class="n">Remaining</th><th class="n">Inst. Left</th></tr>
    {% for o in s.obligations.rows %}
    <tr>
      <td>{{ o.description }}</td>
      <td class="n">{{ o.original_amount|money }}</td>
      <td class="n">{{ o.current_deduction|money }}</td>
      <td class="n">{{ o.total_paid|money }}</td>
      <td class="n">{{ o.remaining_balance|money }}</td>
      <td class="n">{{ o.remaining_installments }}</td>
    </tr>
    {% endfor %}
    <tr class="totals"><td colspan="4">Total Outstanding</td>
        <td class="n">{{ s.obligations.total_outstanding|money }}</td><td></td></tr>
  </table>
  {% else %}
  <div class="sub">No outstanding obligations as of this period.</div>
  {% endif %}

  <div class="notice">{{ s.prototype_notice }}</div>
</div>
"""
)

_ADVICE_TMPL = _env.from_string(
    """
<div class="doc">
  <div class="header-row">
    <div>
      <h1>{{ h.company_name }}</h1>
      <div class="sub">Consultant Payment Advice — {{ h.payroll_period }}
        ({{ h.period_start }} to {{ h.period_end }})</div>
    </div>
    <div class="sub" style="text-align:right">
      Version {{ s.version }}<br/>Ref: {{ s.run_reference }}
    </div>
  </div>

  <table class="kv">
    <tr><td class="k">Consultant</td><td>{{ h.name }}</td>
        <td class="k">Contract No.</td><td>{{ h.employee_number or '—' }}</td></tr>
    <tr><td class="k">Type</td><td>{{ h.employment_type|nice }}</td>
        <td class="k">Project</td><td>{{ h.project or '—' }}</td></tr>
    <tr><td class="k">Cost Center</td><td>{{ h.cost_center or '—' }}</td>
        <td class="k">Bank</td><td>{{ h.bank_name or '—' }} {{ h.bank_account_masked }}</td></tr>
  </table>

  <div class="section-title">Fee & Deductions</div>
  <table class="amt">
    <tr><th>Item</th><th class="n">Amount</th></tr>
    {% for k, v in s.earnings.items() %}
    <tr><td>{{ k|nice }}</td><td class="n">{{ v|money }}</td></tr>
    {% endfor %}
    <tr class="totals"><td>Gross Amount</td><td class="n">{{ s.gross_pay|money }}</td></tr>
    {% for k, v in s.deductions.items() %}
    <tr><td>{{ k|nice }}</td><td class="n">({{ v|money }})</td></tr>
    {% endfor %}
    <tr class="totals"><td>Total Deductions</td><td class="n">{{ s.total_deductions|money }}</td></tr>
  </table>

  <table class="amt totals" style="margin-top:10px;">
    <tr><td>NET PAYMENT</td><td class="n net">{{ s.net_pay|money }}</td></tr>
  </table>

  {% if s.obligations.rows %}
  <div class="section-title">Outstanding Advances (as of {{ h.period_end }})</div>
  <table class="amt">
    <tr><th>Description</th><th class="n">Original</th><th class="n">Remaining</th></tr>
    {% for o in s.obligations.rows %}
    <tr><td>{{ o.description }}</td><td class="n">{{ o.original_amount|money }}</td>
        <td class="n">{{ o.remaining_balance|money }}</td></tr>
    {% endfor %}
  </table>
  {% endif %}

  <div class="notice">{{ s.prototype_notice }}</div>
</div>
"""
)


def _render_html(snapshots: list[dict]) -> str:
    from app.models.payslip import DocumentType

    body = []
    for s in snapshots:
        tmpl = _ADVICE_TMPL if s.get("document_type") == DocumentType.PAYMENT_ADVICE else _PAYSLIP_TMPL
        body.append(tmpl.render(s=s, h=s["header"]))
    return f"<html><head><style>{_BASE_CSS}</style></head><body>{''.join(body)}</body></html>"


def render_document(snapshot: dict) -> bytes:
    """Render a single payslip / payment advice to PDF bytes."""
    from weasyprint import HTML

    return HTML(string=_render_html([snapshot])).write_pdf()


def render_bulk(snapshots: list[dict]) -> bytes:
    """Render many documents into one combined PDF (one per page)."""
    from weasyprint import HTML

    return HTML(string=_render_html(snapshots)).write_pdf()
