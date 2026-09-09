"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";
import { peso } from "@/lib/format";

const ENG_TYPES = ["REGULAR", "PROBATIONARY", "PROJECT_BASED", "FIXED_TERM", "CONTRACTOR", "OJT", "TRAINEE"];
const EMPTY: any = { title: "", headcount: 1, job_description: "", placement_type: "OFFICE",
  project_id: "", employment_type: "PROBATIONARY", target_start_date: "", target_end_date: "", budget: "" };

export default function RecruitmentPage() {
  const orgId = Number(useParams().orgId);
  const [reqs, setReqs] = useState<any[]>([]);
  const [apps, setApps] = useState<any[]>([]);
  const [projects, setProjects] = useState<any[]>([]);
  const [editing, setEditing] = useState<null | "new" | any>(null);
  const [form, setForm] = useState<any>({ ...EMPTY });
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const load = useCallback(() => {
    apiFetch(`/organizations/${orgId}/recruitment/requisitions`).then(setReqs).catch(() => setReqs([]));
    apiFetch(`/organizations/${orgId}/recruitment/applications`).then(setApps).catch(() => setApps([]));
    apiFetch(`/organizations/${orgId}/projects`).then(setProjects).catch(() => {});
  }, [orgId]);
  useEffect(load, [load]);

  function openCreate() { setForm({ ...EMPTY }); setEditing("new"); setErr(""); }
  function openEdit(r: any) {
    setForm({ ...EMPTY, ...r, project_id: r.project_id ?? "", budget: r.budget ?? "",
      target_start_date: r.target_start_date || "", target_end_date: r.target_end_date || "" });
    setEditing(r); setErr("");
  }
  function payload() {
    const p: any = { title: form.title, headcount: Number(form.headcount || 1),
      job_description: form.job_description, placement_type: form.placement_type,
      employment_type: form.employment_type };
    if (form.placement_type === "PROJECT" && form.project_id) p.project_id = Number(form.project_id);
    if (form.budget !== "") p.budget = Number(form.budget);
    if (form.target_start_date) p.target_start_date = form.target_start_date;
    if (form.target_end_date) p.target_end_date = form.target_end_date;
    if (form.status) p.status = form.status;
    return p;
  }
  async function save() {
    setErr("");
    try {
      if (editing === "new") await apiFetch(`/organizations/${orgId}/recruitment/requisitions`, { method: "POST", body: JSON.stringify(payload()) });
      else await apiFetch(`/organizations/${orgId}/recruitment/requisitions/${editing.id}`, { method: "PUT", body: JSON.stringify(payload()) });
      setMsg("Requisition saved."); setEditing(null); load();
    } catch (e: any) { setErr(e.message); }
  }
  async function del(r: any) {
    if (!confirm(`Delete requisition "${r.title}"?`)) return;
    await apiFetch(`/organizations/${orgId}/recruitment/requisitions/${r.id}`, { method: "DELETE" }); load();
  }
  async function advance(id: number) { await apiFetch(`/organizations/${orgId}/recruitment/applications/${id}/advance`, { method: "POST", body: JSON.stringify({}) }); load(); }
  async function hire(id: number) {
    const r = await apiFetch<any>(`/organizations/${orgId}/recruitment/applications/${id}/hire`, { method: "POST", body: JSON.stringify({ engagement_type: "PROBATIONARY", base_rate: 28000 }) });
    setMsg(`Hired — person #${r.person_id}, engagement #${r.engagement_id}.`); load();
  }

  return (
    <AppShell orgId={orgId}>
      <div className="mb-4 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-900">Recruitment</h1>
        <button className="btn-primary" onClick={openCreate}>+ New Requisition</button>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="mb-6 card p-0">
        <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Job Requisitions ({reqs.length})</div>
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead className="bg-slate-50"><tr className="text-left text-xs uppercase text-slate-500">
              <th className="px-4 py-2">Title</th><th className="px-4 py-2 text-center">Needed</th><th className="px-4 py-2">Placement</th>
              <th className="px-4 py-2">Timeline</th><th className="px-4 py-2 text-right">Budget/mo</th><th className="px-4 py-2">Status</th><th className="px-4 py-2"></th>
            </tr></thead>
            <tbody className="divide-y divide-slate-100">
              {reqs.map((r) => (
                <tr key={r.id} className="hover:bg-slate-50 align-top">
                  <td className="px-4 py-2"><div className="font-medium">{r.title}</div><div className="text-xs text-slate-500 line-clamp-2 max-w-xs">{r.job_description}</div></td>
                  <td className="px-4 py-2 text-center">{r.headcount}</td>
                  <td className="px-4 py-2 text-xs">{r.placement_type === "PROJECT" ? `Project: ${r.project_name || "—"}` : "Office / Org"}<div className="text-slate-400">{r.employment_type}</div></td>
                  <td className="px-4 py-2 text-xs text-slate-500">{r.target_start_date || "—"}{r.target_end_date ? ` → ${r.target_end_date}` : ""}</td>
                  <td className="px-4 py-2 text-right">{peso(r.budget)}</td>
                  <td className="px-4 py-2"><span className="badge bg-slate-100 text-slate-600">{r.status}</span></td>
                  <td className="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                    <button className="text-brand-700 hover:underline" onClick={() => openEdit(r)}>Edit</button>
                    <button className="text-red-600 hover:underline" onClick={() => del(r)}>Delete</button>
                  </td>
                </tr>
              ))}
              {reqs.length === 0 ? <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">No requisitions.</td></tr> : null}
            </tbody>
          </table>
        </div>
      </div>

      <div className="card p-0">
        <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Applications ({apps.length})</div>
        <table className="min-w-full text-sm"><tbody className="divide-y divide-slate-100">
          {apps.map((a) => (
            <tr key={a.id}><td className="px-4 py-2">{a.applicant}</td><td className="px-4 py-2"><span className="badge bg-blue-100 text-blue-700">{a.stage}</span></td>
              <td className="px-4 py-2 text-right space-x-2">
                {a.stage !== "HIRED" ? <button className="text-brand-700 hover:underline" onClick={() => advance(a.id)}>Advance</button> : null}
                {a.stage === "OFFER" ? <button className="text-emerald-700 hover:underline" onClick={() => hire(a.id)}>Hire</button> : null}
              </td></tr>
          ))}
          {apps.length === 0 ? <tr><td className="px-4 py-6 text-center text-slate-400">No applications.</td></tr> : null}
        </tbody></table>
      </div>

      {editing !== null ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setEditing(null)}>
          <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 className="mb-4 text-base font-semibold">{editing === "new" ? "New Requisition" : "Edit Requisition"}</h2>
            <div className="grid grid-cols-2 gap-3">
              <div className="col-span-2"><label className="mb-1 block text-xs text-slate-500">Position title *</label>
                <input className="input" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></div>
              <div><label className="mb-1 block text-xs text-slate-500">Staff needed (headcount)</label>
                <input className="input" type="number" min={1} value={form.headcount} onChange={(e) => setForm({ ...form, headcount: e.target.value })} /></div>
              <div><label className="mb-1 block text-xs text-slate-500">Employment type</label>
                <select className="input" value={form.employment_type} onChange={(e) => setForm({ ...form, employment_type: e.target.value })}>
                  {ENG_TYPES.map((t) => <option key={t}>{t}</option>)}</select></div>
              <div><label className="mb-1 block text-xs text-slate-500">For</label>
                <select className="input" value={form.placement_type} onChange={(e) => setForm({ ...form, placement_type: e.target.value })}>
                  <option value="OFFICE">Office / Organization worker</option><option value="PROJECT">A project</option></select></div>
              {form.placement_type === "PROJECT" ? (
                <div><label className="mb-1 block text-xs text-slate-500">Project</label>
                  <select className="input" value={form.project_id} onChange={(e) => setForm({ ...form, project_id: e.target.value })}>
                    <option value="">Select…</option>{projects.map((p) => <option key={p.id} value={p.id}>{p.project_name}</option>)}</select></div>
              ) : <div />}
              <div><label className="mb-1 block text-xs text-slate-500">Needed by (start)</label>
                <input className="input" type="date" value={form.target_start_date} onChange={(e) => setForm({ ...form, target_start_date: e.target.value })} /></div>
              <div><label className="mb-1 block text-xs text-slate-500">End (if fixed-term)</label>
                <input className="input" type="date" value={form.target_end_date} onChange={(e) => setForm({ ...form, target_end_date: e.target.value })} /></div>
              <div><label className="mb-1 block text-xs text-slate-500">Monthly salary budget</label>
                <input className="input" type="number" value={form.budget} onChange={(e) => setForm({ ...form, budget: e.target.value })} /></div>
              {editing !== "new" ? (
                <div><label className="mb-1 block text-xs text-slate-500">Status</label>
                  <select className="input" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                    {["OPEN", "ON_HOLD", "FILLED", "CLOSED"].map((s) => <option key={s}>{s}</option>)}</select></div>
              ) : <div />}
              <div className="col-span-2"><label className="mb-1 block text-xs text-slate-500">Job description</label>
                <textarea className="input min-h-[80px]" value={form.job_description} onChange={(e) => setForm({ ...form, job_description: e.target.value })} /></div>
            </div>
            {err ? <div className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}
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
