
import { useCallback, useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch, apiDownload, apiOpen } from "@/lib/api";
import { peso } from "@/lib/format";

export default function ProjectsPage() {
  const orgId = Number(useParams().orgId);
  const [rows, setRows] = useState<any[]>([]);
  const [sel, setSel] = useState<any>(null);
  const [budget, setBudget] = useState<any>(null);
  const [editing, setEditing] = useState<null | "new" | any>(null);
  const [form, setForm] = useState<any>({});
  const [range, setRange] = useState({ from: "", to: "" });
  const [alloc, setAlloc] = useState({ period_label: "", amount: "" });
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const load = useCallback(() => {
    apiFetch(`/organizations/${orgId}/projects`).then(setRows).catch((e) => setErr(e.message));
  }, [orgId]);
  useEffect(load, [load]);

  const loadBudget = useCallback((projId: number) => {
    const q = new URLSearchParams();
    if (range.from) q.set("date_from", range.from);
    if (range.to) q.set("date_to", range.to);
    apiFetch(`/organizations/${orgId}/projects/${projId}/budget?${q}`).then(setBudget).catch(() => setBudget(null));
  }, [orgId, range]);

  function selectProject(p: any) { setSel(p); loadBudget(p.id); }
  useEffect(() => { if (sel) loadBudget(sel.id); }, [range]); // eslint-disable-line

  async function save() {
    setErr("");
    try {
      const body = { project_code: form.project_code, project_name: form.project_name,
        status: form.status || "ACTIVE", labor_budget: Number(form.labor_budget || 0),
        start_date: form.start_date || undefined, target_end_date: form.target_end_date || undefined };
      if (editing === "new") await apiFetch(`/organizations/${orgId}/projects`, { method: "POST", body: JSON.stringify(body) });
      else await apiFetch(`/organizations/${orgId}/projects/${editing.id}`, { method: "PUT", body: JSON.stringify(body) });
      setMsg("Project saved."); setEditing(null); load();
    } catch (e: any) { setErr(e.message); }
  }
  async function del(p: any) {
    if (!confirm(`Delete project ${p.project_name}?`)) return;
    setErr("");
    try { await apiFetch(`/organizations/${orgId}/projects/${p.id}`, { method: "DELETE" }); if (sel?.id === p.id) setSel(null); load(); }
    catch (e: any) { setErr(e.message); }
  }
  async function saveAlloc() {
    if (!sel || !alloc.period_label) return;
    await apiFetch(`/organizations/${orgId}/projects/${sel.id}/allocations`, { method: "POST", body: JSON.stringify({ period_label: alloc.period_label, amount: Number(alloc.amount || 0) }) });
    setAlloc({ period_label: "", amount: "" }); loadBudget(sel.id);
  }
  function reportUrl() {
    const q = new URLSearchParams({ fmt: "csv" });
    if (range.from) q.set("date_from", range.from);
    if (range.to) q.set("date_to", range.to);
    return `/organizations/${orgId}/projects/${sel.id}/report?${q}`;
  }

  return (
    <AppShell orgId={orgId}>
      <div className="mb-4 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-900">Projects</h1>
        <button className="btn-primary" onClick={() => { setForm({ status: "ACTIVE" }); setEditing("new"); }}>+ New Project</button>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="space-y-3 lg:col-span-1">
          {rows.map((p) => (
            <div key={p.id} className={`card cursor-pointer ${sel?.id === p.id ? "ring-2 ring-geek-blue" : ""}`} onClick={() => selectProject(p)}>
              <div className="flex items-center justify-between">
                <span className="font-mono text-xs text-slate-500">{p.project_code}</span>
                <span className="badge bg-emerald-100 text-emerald-700">{p.status}</span>
              </div>
              <div className="mt-1 font-medium text-slate-800">{p.project_name}</div>
              <div className="mt-1 text-xs text-slate-500">Labor budget: {peso(p.labor_budget)}</div>
              <div className="mt-2 flex gap-3 text-xs">
                <button className="text-brand-700 hover:underline" onClick={(e) => { e.stopPropagation(); setForm(p); setEditing(p); }}>Edit</button>
                <button className="text-red-600 hover:underline" onClick={(e) => { e.stopPropagation(); del(p); }}>Delete</button>
              </div>
            </div>
          ))}
          {rows.length === 0 ? <div className="card text-sm text-slate-400">No projects.</div> : null}
        </div>

        <div className="lg:col-span-2">
          {sel ? (
            <div className="space-y-4">
              <div className="card">
                <div className="mb-3 flex items-center justify-between">
                  <div className="font-medium text-slate-800">{sel.project_name} — Budget</div>
                  <div className="flex items-center gap-2 text-xs">
                    <span className="text-slate-500">Period</span>
                    <input className="input !py-1" type="month" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} />
                    <span>→</span>
                    <input className="input !py-1" type="month" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} />
                    <button className="btn-primary !px-3 !py-1" onClick={() => apiDownload(reportUrl(), `project_${sel.project_code}_report.csv`)}>Generate Report</button>
                  </div>
                </div>
                {budget ? (
                  <>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                      <Mini label="Labor Budget" v={peso(budget.labor_budget)} />
                      <Mini label="Allocated" v={peso(budget.allocated_total)} />
                      <Mini label="Actual Spend" v={peso(budget.actual_total)} />
                      <Mini label="Remaining" v={peso(budget.remaining_vs_budget)} accent={budget.remaining_vs_budget < 0 ? "#EA4335" : "#34A853"} />
                    </div>
                    <table className="mt-4 min-w-full text-sm">
                      <thead className="bg-slate-50"><tr className="text-left text-xs uppercase text-slate-500"><th className="px-3 py-2">Period</th><th className="px-3 py-2 text-right">Allocated</th><th className="px-3 py-2 text-right">Actual</th><th className="px-3 py-2 text-right">Variance</th></tr></thead>
                      <tbody className="divide-y divide-slate-100">
                        {budget.periods.map((r: any) => (
                          <tr key={r.period}><td className="px-3 py-1.5">{r.period}</td><td className="px-3 py-1.5 text-right">{peso(r.allocated)}</td><td className="px-3 py-1.5 text-right">{peso(r.actual)}</td>
                            <td className={`px-3 py-1.5 text-right ${r.variance < 0 ? "text-red-600" : "text-emerald-700"}`}>{peso(r.variance)}</td></tr>
                        ))}
                        {budget.periods.length === 0 ? <tr><td colSpan={4} className="px-3 py-4 text-center text-slate-400">No allocations or spend yet.</td></tr> : null}
                      </tbody>
                    </table>
                  </>
                ) : <div className="text-sm text-slate-400">Loading budget…</div>}
              </div>

              <div className="card">
                <div className="mb-2 text-sm font-medium">Set period allocation</div>
                <div className="flex items-end gap-2">
                  <div><label className="mb-1 block text-xs text-slate-500">Period (YYYY-MM)</label><input className="input !py-1" type="month" value={alloc.period_label} onChange={(e) => setAlloc({ ...alloc, period_label: e.target.value })} /></div>
                  <div><label className="mb-1 block text-xs text-slate-500">Amount</label><input className="input !py-1" type="number" value={alloc.amount} onChange={(e) => setAlloc({ ...alloc, amount: e.target.value })} /></div>
                  <button className="btn-primary !py-1.5" onClick={saveAlloc}>Save allocation</button>
                </div>
              </div>

              <ProjectPayroll orgId={orgId} project={sel} onChanged={() => { loadBudget(sel.id); load(); }} />
            </div>
          ) : <div className="card text-sm text-slate-400">Select a project to see its budget and generate reports.</div>}
        </div>
      </div>

      {editing ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setEditing(null)}>
          <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 className="mb-4 text-base font-semibold">{editing === "new" ? "New Project" : "Edit Project"}</h2>
            <div className="grid grid-cols-2 gap-3">
              <F label="Project code *" v={form.project_code} on={(x: string) => setForm({ ...form, project_code: x })} />
              <F label="Project name *" v={form.project_name} on={(x: string) => setForm({ ...form, project_name: x })} />
              <F label="Labor budget" v={form.labor_budget} on={(x: string) => setForm({ ...form, labor_budget: x })} type="number" />
              <div><label className="mb-1 block text-xs text-slate-500">Status</label>
                <select className="input" value={form.status || "ACTIVE"} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                  {["PLANNING", "ACTIVE", "ON_HOLD", "COMPLETED", "CANCELLED"].map((s) => <option key={s}>{s}</option>)}</select></div>
              <F label="Start date" v={form.start_date} on={(x: string) => setForm({ ...form, start_date: x })} type="date" />
              <F label="Target end" v={form.target_end_date} on={(x: string) => setForm({ ...form, target_end_date: x })} type="date" />
            </div>
            <div className="mt-5 flex justify-end gap-2">
              <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm" onClick={() => setEditing(null)}>Cancel</button>
              <button className="btn-primary" onClick={save}>Save</button>
            </div>
          </div>
        </div>
      ) : null}
    </AppShell>
  );
}

function Mini({ label, v, accent }: { label: string; v: string; accent?: string }) {
  return <div className="rounded-lg border border-slate-200 p-3"><div className="text-[11px] uppercase tracking-wide text-slate-500">{label}</div><div className="mt-1 text-lg font-semibold" style={accent ? { color: accent } : {}}>{v}</div></div>;
}
function F({ label, v, on, type = "text" }: any) {
  return <div><label className="mb-1 block text-xs text-slate-500">{label}</label><input className="input" type={type} value={v ?? ""} onChange={(e) => on(e.target.value)} /></div>;
}

// Short helpers for a Monday→Sunday week and an N-day span from today, so the PM can
// spin up a weekly / 3-day run without hand-picking dates.
function isoAdd(d: string, days: number) { const t = new Date(d + "T00:00:00"); t.setDate(t.getDate() + days); return t.toISOString().slice(0, 10); }
function todayIso() { return new Date().toISOString().slice(0, 10); }

// Construction/project payroll: short-cycle daily-wage runs for one project.
function ProjectPayroll({ orgId, project, onChanged }: { orgId: number; project: any; onChanged: () => void }) {
  const [runs, setRuns] = useState<any[]>([]);
  const [run, setRun] = useState<any>(null); // selected run with lines
  const [creating, setCreating] = useState(false);
  const [nr, setNr] = useState<any>({ period_start: todayIso(), period_end: isoAdd(todayIso(), 6), pay_date: "" });
  const [isSuper, setIsSuper] = useState(false);
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const loadRuns = useCallback(() => {
    apiFetch(`/organizations/${orgId}/projects/${project.id}/pay-runs`).then(setRuns).catch((e) => setErr(e.message));
  }, [orgId, project.id]);
  useEffect(() => { loadRuns(); setRun(null); }, [loadRuns]);
  useEffect(() => { apiFetch<any>("/auth/me").then((u) => setIsSuper(!!u.is_superadmin)).catch(() => {}); }, []);

  function openRun(id: number) { apiFetch(`/organizations/${orgId}/project-pay-runs/${id}`).then(setRun).catch((e) => setErr(e.message)); }

  async function createRun() {
    setErr("");
    if (!nr.period_start || !nr.period_end) { setErr("Enter a start and end date."); return; }
    try {
      const r = await apiFetch<any>(`/organizations/${orgId}/projects/${project.id}/pay-runs`, { method: "POST",
        body: JSON.stringify({ period_start: nr.period_start, period_end: nr.period_end, pay_date: nr.pay_date || undefined }) });
      setMsg(`Run created with ${r.line_count} worker(s).`); setCreating(false); loadRuns(); openRun(r.id);
    } catch (e: any) { setErr(e.message); }
  }
  async function saveLine(lineId: number, patch: any) {
    const r = await apiFetch<any>(`/organizations/${orgId}/project-pay-runs/${run.id}/lines/${lineId}`, { method: "PUT", body: JSON.stringify(patch) });
    setRun((cur: any) => ({ ...cur, gross_total: r.totals.gross_total, statutory_total: r.totals.statutory_total, net_total: r.totals.net_total,
      lines: cur.lines.map((l: any) => (l.line_id === lineId ? { ...l, ...patch, basic_pay: r.basic_pay, gross_pay: r.gross_pay, net_pay: r.net_pay, sss: r.sss, philhealth: r.philhealth, pagibig: r.pagibig, withholding_tax: r.withholding_tax, loan_deduction: r.loan_deduction, total_deductions: r.total_deductions } : l)) }));
    onChanged();
  }
  async function act(path: string, method = "POST") {
    setErr("");
    try { await apiFetch(`/organizations/${orgId}/project-pay-runs/${run.id}/${path}`, { method }); openRun(run.id); loadRuns(); onChanged(); }
    catch (e: any) { setErr(e.message); }
  }
  async function deleteRun() {
    if (!confirm("Permanently delete this pay run and its lines?")) return;
    try { await apiFetch(`/organizations/${orgId}/project-pay-runs/${run.id}`, { method: "DELETE" }); setRun(null); loadRuns(); onChanged(); }
    catch (e: any) { setErr(e.message); }
  }
  async function endProject() {
    if (!confirm(`Mark project "${project.project_name}" as COMPLETED and set its end date to today?`)) return;
    try { await apiFetch(`/organizations/${orgId}/projects/${project.id}/end`, { method: "POST", body: JSON.stringify({}) }); setMsg("Project ended."); onChanged(); }
    catch (e: any) { setErr(e.message); }
  }
  const rep = (kind: string, fmt: string) => {
    const base = `/organizations/${orgId}/projects/${project.id}`;
    if (kind === "dole") return fmt === "csv" ? apiDownload(`${base}/dole-report?fmt=csv`, `DOLE_${project.project_code}.csv`) : apiOpen(`${base}/dole-report?fmt=print`);
    if (kind === "statutory") return apiOpen(`${base}/statutory-report?fmt=print`);
    if (kind === "13th") return apiDownload(`${base}/thirteenth-month?fmt=csv`, `13thMonth_${project.project_code}.csv`);
  };

  const editable = run && run.status !== "APPROVED";
  return (
    <div className="card">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div className="text-sm font-medium">Project payroll <span className="text-xs font-normal text-slate-400">— daily-wage site workers, weekly / short-cycle</span></div>
        <div className="flex flex-wrap gap-2">
          <button className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50" onClick={() => rep("dole", "print")}>DOLE labour report</button>
          <button className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50" onClick={() => rep("statutory", "print")}>Statutory summary</button>
          <button className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50" onClick={() => rep("13th", "csv")}>13th month (CSV)</button>
          <button className="rounded-lg border border-red-200 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50" onClick={endProject}>End project</button>
          <button className="btn-primary !py-1.5 !text-xs" onClick={() => { setNr({ period_start: todayIso(), period_end: isoAdd(todayIso(), 6), pay_date: "" }); setCreating(true); }}>+ New pay run</button>
        </div>
      </div>
      {msg ? <div className="mb-2 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-2 rounded-lg bg-red-50 px-3 py-1.5 text-xs text-red-700">{err}</div> : null}

      {creating ? (
        <div className="mb-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
          <div className="mb-2 flex flex-wrap items-end gap-2">
            <div><label className="mb-1 block text-[11px] text-slate-500">Period start</label><input className="input !py-1" type="date" value={nr.period_start} onChange={(e) => setNr({ ...nr, period_start: e.target.value })} /></div>
            <div><label className="mb-1 block text-[11px] text-slate-500">Period end</label><input className="input !py-1" type="date" value={nr.period_end} onChange={(e) => setNr({ ...nr, period_end: e.target.value })} /></div>
            <div><label className="mb-1 block text-[11px] text-slate-500">Pay date</label><input className="input !py-1" type="date" value={nr.pay_date} onChange={(e) => setNr({ ...nr, pay_date: e.target.value })} /></div>
            <div className="flex gap-1">
              <button type="button" className="rounded-lg border border-slate-300 px-2 py-1 text-[11px] hover:bg-white" onClick={() => setNr({ ...nr, period_end: isoAdd(nr.period_start, 6) })}>Week</button>
              <button type="button" className="rounded-lg border border-slate-300 px-2 py-1 text-[11px] hover:bg-white" onClick={() => setNr({ ...nr, period_end: isoAdd(nr.period_start, 2) })}>3 days</button>
            </div>
            <button className="btn-primary !py-1.5" onClick={createRun}>Create run</button>
            <button className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm" onClick={() => setCreating(false)}>Cancel</button>
          </div>
          <p className="text-[11px] text-slate-400">Picks up this project's active site workers (Project Workers list). Enter each worker's days after creating.</p>
        </div>
      ) : null}

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
        <div className="lg:col-span-1">
          <div className="mb-1 text-xs font-medium text-slate-500">Pay runs ({runs.length})</div>
          <div className="max-h-72 space-y-1 overflow-y-auto">
            {runs.map((r) => (
              <button key={r.id} onClick={() => openRun(r.id)} className={`block w-full rounded-lg border px-3 py-2 text-left text-xs ${run?.id === r.id ? "border-geek-blue bg-geek-blue/10" : "border-slate-200 hover:bg-slate-50"}`}>
                <div className="flex justify-between"><span className="font-medium">{r.period_start} → {r.period_end}</span><span className={`badge ${r.status === "APPROVED" ? "bg-emerald-100 text-emerald-700" : "bg-slate-100 text-slate-600"}`}>{r.status}</span></div>
                <div className="text-slate-500">{r.line_count} worker(s) · net {peso(r.net_total)}</div>
              </button>
            ))}
            {runs.length === 0 ? <div className="text-xs text-slate-400">No pay runs yet.</div> : null}
          </div>
        </div>

        <div className="lg:col-span-2">
          {run ? (
            <>
              <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <div className="text-xs text-slate-500">{run.reference} · gross {peso(run.gross_total)} · statutory {peso(run.statutory_total)} · net <span className="font-semibold text-slate-700">{peso(run.net_total)}</span></div>
                <div className="flex gap-2">
                  <button className="rounded-lg border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50" onClick={() => apiOpen(`/organizations/${orgId}/project-pay-runs/${run.id}/payslips`)}>Payslips</button>
                  {editable ? <button className="rounded-lg border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50" onClick={() => act("recompute")}>Recompute</button> : null}
                  {editable ? <button className="btn-primary !py-1 !text-xs" onClick={() => act("approve", "POST")}>Approve</button>
                    : <button className="rounded-lg border border-amber-300 px-2.5 py-1 text-xs text-amber-700 hover:bg-amber-50" onClick={() => act("reopen", "POST")}>Reopen</button>}
                  {isSuper ? <button className="rounded-lg border border-red-200 px-2.5 py-1 text-xs text-red-600 hover:bg-red-50" onClick={deleteRun}>Delete</button> : null}
                </div>
              </div>
              <div className="overflow-x-auto">
                <table className="min-w-full text-xs">
                  <thead className="bg-slate-50"><tr className="text-left uppercase text-slate-500">
                    <th className="px-2 py-1.5">Worker</th><th className="px-2 py-1.5 text-right">Rate/day</th><th className="px-2 py-1.5 text-right">Days</th>
                    <th className="px-2 py-1.5 text-right">OT</th><th className="px-2 py-1.5 text-right">Allow.</th><th className="px-2 py-1.5 text-right">Other ded.</th>
                    <th className="px-2 py-1.5 text-right">Gross</th><th className="px-2 py-1.5 text-right">Statutory</th><th className="px-2 py-1.5 text-right">Tax</th>
                    <th className="px-2 py-1.5 text-right">Loan</th><th className="px-2 py-1.5 text-right">Net</th><th className="px-2 py-1.5"></th>
                  </tr></thead>
                  <tbody className="divide-y divide-slate-100">
                    {run.lines.map((l: any) => <PayLine key={l.line_id} line={l} editable={editable} onSave={saveLine} onSlip={() => apiOpen(`/organizations/${orgId}/project-pay-runs/${run.id}/payslips?line=${l.line_id}`)} />)}
                    {run.lines.length === 0 ? <tr><td colSpan={12} className="px-2 py-4 text-center text-slate-400">No workers — assign site workers to this project first.</td></tr> : null}
                  </tbody>
                </table>
              </div>
              <p className="mt-2 text-[11px] text-slate-400">Pay = daily rate × days worked (+ OT + allowance). SSS/PhilHealth/Pag-IBIG are prorated by time worked and tagged to this project. The <strong>Loan</strong> column is each worker's cash-advance / loan installment (auto — shown as an estimate, actually deducted from their balance on <strong>Approve</strong>; reopening returns it). {editable ? "Type days per worker; totals update on blur. Use the per-row Payslip to print/send one worker." : "Approved — reopen to edit."}</p>
            </>
          ) : <div className="flex h-40 items-center justify-center text-xs text-slate-400">Select or create a pay run.</div>}
        </div>
      </div>
    </div>
  );
}

function PayLine({ line, editable, onSave, onSlip }: any) {
  const [d, setD] = useState(String(line.days_worked ?? ""));
  const [ot, setOt] = useState(String(line.ot_amount ?? ""));
  const [al, setAl] = useState(String(line.allowance ?? ""));
  const [od, setOd] = useState(String(line.other_deduction ?? ""));
  useEffect(() => { setD(String(line.days_worked ?? "")); setOt(String(line.ot_amount ?? "")); setAl(String(line.allowance ?? "")); setOd(String(line.other_deduction ?? "")); }, [line.line_id]); // eslint-disable-line
  const save = () => onSave(line.line_id, { days_worked: Number(d || 0), ot_amount: Number(ot || 0), allowance: Number(al || 0), other_deduction: Number(od || 0) });
  const cell = (v: string, set: (x: string) => void) => <input className="input !w-16 !py-0.5 text-right text-xs" type="number" value={v} onChange={(e) => set(e.target.value)} onBlur={save} disabled={!editable} />;
  return (
    <tr>
      <td className="px-2 py-1">{line.name} <span className="text-slate-400">{line.employee_number || ""}</span></td>
      <td className="px-2 py-1 text-right">{peso(line.daily_rate)}</td>
      <td className="px-2 py-1 text-right">{cell(d, setD)}</td>
      <td className="px-2 py-1 text-right">{cell(ot, setOt)}</td>
      <td className="px-2 py-1 text-right">{cell(al, setAl)}</td>
      <td className="px-2 py-1 text-right">{cell(od, setOd)}</td>
      <td className="px-2 py-1 text-right font-medium">{peso(line.gross_pay)}</td>
      <td className="px-2 py-1 text-right">{peso((line.sss || 0) + (line.philhealth || 0) + (line.pagibig || 0))}</td>
      <td className="px-2 py-1 text-right">{peso(line.withholding_tax)}</td>
      <td className="px-2 py-1 text-right">{peso(line.loan_deduction)}</td>
      <td className="px-2 py-1 text-right font-semibold">{peso(line.net_pay)}</td>
      <td className="px-2 py-1 text-right"><button className="text-brand-700 hover:underline" onClick={onSlip}>Payslip</button></td>
    </tr>
  );
}
