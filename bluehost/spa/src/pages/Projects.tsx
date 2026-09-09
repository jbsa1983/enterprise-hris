
import { useCallback, useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch, apiDownload } from "@/lib/api";
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
