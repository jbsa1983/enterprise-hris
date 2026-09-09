"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import AppShell from "@/components/AppShell";
import { apiFetch, apiDownload } from "@/lib/api";
import { peso } from "@/lib/format";

export default function SpecialPayPage() {
  const orgId = Number(useParams().orgId);
  const [runs, setRuns] = useState<any[]>([]);
  const [sel, setSel] = useState<any>(null);
  const [people, setPeople] = useState<any[]>([]);
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState<any>({ pay_type: "13TH_MONTH", year: new Date().getFullYear(), name: "", all: true, engagement_ids: [] as number[] });
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const loadRuns = useCallback(() => {
    apiFetch(`/organizations/${orgId}/special-pay`).then(setRuns).catch((e) => setErr(e.message));
    apiFetch(`/organizations/${orgId}/people?status=ACTIVE`).then(setPeople).catch(() => {});
  }, [orgId]);
  useEffect(loadRuns, [loadRuns]);

  function openRun(id: number) { apiFetch(`/organizations/${orgId}/special-pay/${id}`).then(setSel); }

  async function create() {
    setErr("");
    try {
      const body: any = { pay_type: form.pay_type, year: Number(form.year), name: form.name || `${form.pay_type} ${form.year}` };
      if (!form.all && form.engagement_ids.length) body.engagement_ids = form.engagement_ids;
      const r = await apiFetch<any>(`/organizations/${orgId}/special-pay`, { method: "POST", body: JSON.stringify(body) });
      setMsg(`Created — ${r.line_count} lines, total ${peso(r.total_amount)}.`); setCreating(false); loadRuns(); openRun(r.id);
    } catch (e: any) { setErr(e.message); }
  }
  async function saveLine(lineId: number, override: string, remarks: string) {
    await apiFetch(`/organizations/${orgId}/special-pay/${sel.id}/lines/${lineId}`, { method: "PUT",
      body: JSON.stringify({ override_amount: override === "" ? null : Number(override), remarks }) });
    openRun(sel.id);
  }
  async function finalize() {
    await apiFetch(`/organizations/${orgId}/special-pay/${sel.id}/finalize`, { method: "POST" });
    setMsg("Run finalized."); loadRuns(); openRun(sel.id);
  }

  return (
    <AppShell orgId={orgId}>
      <div className="mb-4 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-900">13th Month &amp; Bonuses</h1>
        <button className="btn-primary" onClick={() => setCreating(true)}>+ New Run</button>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="card lg:col-span-1 p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Runs ({runs.length})</div>
          <div className="max-h-[28rem] overflow-y-auto">
            {runs.map((r) => (
              <button key={r.id} onClick={() => openRun(r.id)} className={`block w-full border-b border-slate-50 px-4 py-2 text-left text-sm ${sel?.id === r.id ? "bg-brand-50" : "hover:bg-slate-50"}`}>
                <div className="flex justify-between"><span className="font-medium">{r.name}</span><span className="badge bg-slate-100 text-slate-600">{r.status}</span></div>
                <div className="text-xs text-slate-500">{r.pay_type.replace("_", " ")} · {r.line_count} people · {peso(r.total_amount)}</div>
              </button>
            ))}
            {runs.length === 0 ? <div className="p-4 text-sm text-slate-400">No runs yet.</div> : null}
          </div>
        </div>

        <div className="card lg:col-span-2 p-0">
          {sel ? (
            <>
              <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                <div><div className="font-medium">{sel.name}</div><div className="text-xs text-slate-500">{sel.pay_type.replace("_", " ")} · {sel.year} · total {peso(sel.total_amount)}</div></div>
                <div className="flex gap-2">
                  <button className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50" onClick={() => apiDownload(`/organizations/${orgId}/special-pay/${sel.id}/export`, `${sel.pay_type}_${sel.year}.csv`)}>Export CSV</button>
                  {sel.status !== "GENERATED" ? <button className="btn-primary !py-1.5" onClick={finalize}>Finalize</button> : <span className="badge bg-emerald-100 text-emerald-700">Generated</span>}
                </div>
              </div>
              <div className="max-h-96 overflow-y-auto">
                <table className="min-w-full text-sm">
                  <thead className="bg-slate-50"><tr className="text-left text-xs uppercase text-slate-500"><th className="px-4 py-2">Employee</th><th className="px-4 py-2 text-right">Computed</th><th className="px-4 py-2 text-right">Override</th><th className="px-4 py-2 text-right">Final</th></tr></thead>
                  <tbody className="divide-y divide-slate-100">
                    {sel.lines.map((l: any) => (
                      <LineRow key={l.line_id} line={l} editable={sel.status !== "GENERATED"} onSave={saveLine} />
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          ) : <div className="flex h-64 items-center justify-center text-sm text-slate-400">Select or create a run.</div>}
        </div>
      </div>

      {creating ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setCreating(false)}>
          <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 className="mb-4 text-base font-semibold">New Run</h2>
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div><label className="mb-1 block text-xs text-slate-500">Type</label>
                  <select className="input" value={form.pay_type} onChange={(e) => setForm({ ...form, pay_type: e.target.value })}>
                    <option value="13TH_MONTH">13th Month (auto-computed)</option><option value="BONUS">Bonus (HR enters)</option></select></div>
                <div><label className="mb-1 block text-xs text-slate-500">Year</label>
                  <input className="input" type="number" value={form.year} onChange={(e) => setForm({ ...form, year: e.target.value })} /></div>
              </div>
              <div><label className="mb-1 block text-xs text-slate-500">Name</label>
                <input className="input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder={`${form.pay_type} ${form.year}`} /></div>
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.all} onChange={(e) => setForm({ ...form, all: e.target.checked })} /> All active employees</label>
              {!form.all ? (
                <div className="max-h-40 overflow-y-auto rounded-lg border border-slate-200 p-2">
                  {people.map((p) => (
                    <label key={p.engagement_id} className="flex items-center gap-2 py-0.5 text-sm">
                      <input type="checkbox" checked={form.engagement_ids.includes(p.engagement_id)}
                        onChange={() => setForm({ ...form, engagement_ids: form.engagement_ids.includes(p.engagement_id) ? form.engagement_ids.filter((x: number) => x !== p.engagement_id) : [...form.engagement_ids, p.engagement_id] })} />
                      {p.full_name} <span className="text-xs text-slate-400">{p.employee_number}</span>
                    </label>
                  ))}
                </div>
              ) : null}
            </div>
            <div className="mt-5 flex justify-end gap-2">
              <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm" onClick={() => setCreating(false)}>Cancel</button>
              <button className="btn-primary" onClick={create}>Create &amp; Compute</button>
            </div>
          </div>
        </div>
      ) : null}
    </AppShell>
  );
}

function LineRow({ line, editable, onSave }: any) {
  const [ov, setOv] = useState(line.override_amount ?? "");
  const [rm, setRm] = useState(line.remarks ?? "");
  return (
    <tr>
      <td className="px-4 py-2">{line.name} <span className="text-xs text-slate-400">{line.employee_number}</span></td>
      <td className="px-4 py-2 text-right">{peso(line.computed_amount)}</td>
      <td className="px-4 py-2 text-right">
        {editable ? <input className="input !w-24 !py-1 text-right" type="number" value={ov} onChange={(e) => setOv(e.target.value)} onBlur={() => onSave(line.line_id, String(ov), rm)} placeholder="—" /> : (line.override_amount ?? "—")}
      </td>
      <td className="px-4 py-2 text-right font-medium">{peso(line.final_amount)}</td>
    </tr>
  );
}
