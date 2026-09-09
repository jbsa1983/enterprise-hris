
import { useCallback, useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch, apiDownload, apiOpen } from "@/lib/api";
import { peso } from "@/lib/format";

interface Run {
  id: number; reference: string; status: string; period: string;
  gross_total: number; deduction_total: number; net_total: number; line_count: number;
}
interface Payslip {
  uuid: string; document_type: string; name: string; employee_number: string;
  net_pay: number; version: number; has_pdf: boolean;
}
interface Template { id: number; template_name: string; bank_name: string; file_type: string; }

export default function PayrollPage() {
  const orgId = Number(useParams().orgId);
  const [runs, setRuns] = useState<Run[]>([]);
  const [sel, setSel] = useState<Run | null>(null);
  const [slips, setSlips] = useState<Payslip[]>([]);
  const [templates, setTemplates] = useState<Template[]>([]);
  const [tplId, setTplId] = useState<number | null>(null);
  const [preview, setPreview] = useState<any>(null);
  const [msg, setMsg] = useState<string>("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    apiFetch<Run[]>(`/organizations/${orgId}/payroll/runs`).then((r) => { setRuns(r); setSel(r[0] || null); });
    apiFetch<Template[]>(`/organizations/${orgId}/bank-templates`).then((t) => { setTemplates(t); setTplId(t[0]?.id ?? null); });
  }, [orgId]);

  const loadSlips = useCallback((runId: number) => {
    apiFetch<Payslip[]>(`/organizations/${orgId}/payroll/runs/${runId}/payslips`).then(setSlips).catch(() => setSlips([]));
  }, [orgId]);

  useEffect(() => { if (sel) loadSlips(sel.id); }, [sel, loadSlips]);

  async function generate() {
    if (!sel) return;
    setBusy(true); setMsg("");
    try {
      const r = await apiFetch<{ generated: number }>(`/organizations/${orgId}/payroll/runs/${sel.id}/generate-payslips`, { method: "POST" });
      setMsg(`Generated ${r.generated} payslips.`); loadSlips(sel.id);
    } catch (e: any) { setMsg(e.message); } finally { setBusy(false); }
  }

  async function runPreview() {
    if (!sel || !tplId) return;
    setBusy(true); setMsg("");
    try {
      const p = await apiFetch(`/organizations/${orgId}/payroll/runs/${sel.id}/bank-export/preview`, {
        method: "POST", body: JSON.stringify({ template_id: tplId }),
      });
      setPreview(p);
    } catch (e: any) { setMsg(e.message); } finally { setBusy(false); }
  }

  async function generateBank() {
    if (!sel || !tplId) return;
    setBusy(true); setMsg("");
    try {
      await apiDownload(`/organizations/${orgId}/payroll/runs/${sel.id}/bank-export/generate`,
        `bank_export_run_${sel.id}.csv`,
        { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ template_id: tplId, allow_with_errors: true }) });
      setMsg("Bank file downloaded.");
    } catch (e: any) { setMsg(e.message); } finally { setBusy(false); }
  }

  return (
    <AppShell orgId={orgId}>
      <h1 className="mb-4 text-lg font-semibold text-slate-900">Payroll</h1>
      {msg ? <div className="mb-3 rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-800">{msg}</div> : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {/* Runs list */}
        <div className="card lg:col-span-1">
          <div className="stat-label mb-2">Payroll Runs</div>
          <div className="space-y-2">
            {runs.map((r) => (
              <button key={r.id} onClick={() => setSel(r)}
                className={`w-full rounded-lg border p-3 text-left text-sm ${sel?.id === r.id ? "border-brand-600 bg-brand-50" : "border-slate-200 hover:bg-slate-50"}`}>
                <div className="flex justify-between">
                  <span className="font-medium">{r.reference}</span>
                  <span className="badge bg-slate-100 text-slate-600">{r.status}</span>
                </div>
                <div className="mt-1 text-xs text-slate-500">{r.period} · {r.line_count} employees · net {peso(r.net_total)}</div>
              </button>
            ))}
            {runs.length === 0 ? <div className="text-sm text-slate-400">No runs.</div> : null}
          </div>
        </div>

        {/* Selected run detail */}
        <div className="lg:col-span-2 space-y-6">
          {sel ? (
            <>
              <div className="card">
                <div className="flex items-center justify-between">
                  <div>
                    <div className="font-medium text-slate-800">{sel.reference}</div>
                    <div className="text-xs text-slate-500">{sel.period} · {sel.status}</div>
                  </div>
                  <div className="text-right text-sm">
                    <div>Gross {peso(sel.gross_total)}</div>
                    <div>Net <span className="font-semibold text-emerald-700">{peso(sel.net_total)}</span></div>
                  </div>
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                  <button className="btn-primary" onClick={generate} disabled={busy}>Generate Payslips</button>
                  <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                    onClick={() => apiOpen(`/organizations/${orgId}/payroll/runs/${sel.id}/payslips.pdf`)} disabled={!slips.length}>
                    View All (PDF)
                  </button>
                  <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                    onClick={() => apiDownload(`/organizations/${orgId}/payroll/runs/${sel.id}/payslips.zip`, `payslips_run_${sel.id}.zip`)} disabled={!slips.length}>
                    Download ZIP
                  </button>
                </div>
              </div>

              {/* Payslips */}
              <div className="card p-0">
                <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium text-slate-700">
                  Payslips ({slips.length})
                </div>
                <div className="max-h-72 overflow-y-auto">
                  <table className="min-w-full text-sm">
                    <tbody className="divide-y divide-slate-100">
                      {slips.map((p) => (
                        <tr key={p.uuid} className="hover:bg-slate-50">
                          <td className="px-4 py-2 font-mono text-xs text-slate-500">{p.employee_number}</td>
                          <td className="px-4 py-2">{p.name}</td>
                          <td className="px-4 py-2 text-xs text-slate-500">{p.document_type === "PAYMENT_ADVICE" ? "Advice" : "Payslip"} v{p.version}</td>
                          <td className="px-4 py-2 text-right">{peso(p.net_pay)}</td>
                          <td className="px-4 py-2 text-right">
                            <button className="text-brand-700 hover:underline" onClick={() => apiOpen(`/payslips/${p.uuid}/pdf`)}>View PDF</button>
                          </td>
                        </tr>
                      ))}
                      {slips.length === 0 ? <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">No payslips yet — click Generate Payslips.</td></tr> : null}
                    </tbody>
                  </table>
                </div>
              </div>

              {/* Bank export */}
              <div className="card">
                <div className="stat-label mb-2">Bank Export</div>
                <div className="flex flex-wrap items-center gap-2">
                  <select className="input max-w-xs" value={tplId ?? ""} onChange={(e) => setTplId(Number(e.target.value))}>
                    {templates.map((t) => <option key={t.id} value={t.id}>{t.template_name} ({t.bank_name}/{t.file_type})</option>)}
                  </select>
                  <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50" onClick={runPreview} disabled={busy || !tplId}>Preview</button>
                  <button className="btn-primary" onClick={generateBank} disabled={busy || !tplId}>Generate &amp; Download</button>
                </div>
                {preview ? (
                  <div className="mt-3">
                    <div className="text-sm text-slate-600">
                      {preview.employee_count} employees · total {peso(preview.total_amount)}
                      {preview.validation.errors.length ? (
                        <span className="ml-2 text-red-600">⚠ {preview.validation.errors.join("; ")}</span>
                      ) : <span className="ml-2 text-emerald-600">✓ no validation errors</span>}
                    </div>
                    <div className="mt-2 overflow-x-auto">
                      <table className="min-w-full text-xs">
                        <thead className="bg-slate-50"><tr>{preview.headers.map((h: string) => <th key={h} className="px-2 py-1 text-left">{h}</th>)}</tr></thead>
                        <tbody>
                          {preview.rows.slice(0, 6).map((row: string[], i: number) => (
                            <tr key={i} className="border-t border-slate-100">{row.map((c, j) => <td key={j} className="px-2 py-1 font-mono">{c}</td>)}</tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                ) : null}
              </div>
            </>
          ) : <div className="card text-sm text-slate-400">Select a payroll run.</div>}
        </div>
      </div>
    </AppShell>
  );
}
