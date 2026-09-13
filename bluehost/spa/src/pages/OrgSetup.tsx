import { useCallback, useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";

export default function OrgSetup() {
  const orgId = Number(useParams().orgId);
  const [depts, setDepts] = useState<any[]>([]);
  const [positions, setPositions] = useState<any[]>([]);
  const [dForm, setDForm] = useState({ name: "", code: "" });
  const [pForm, setPForm] = useState<any>({ title: "", job_grade: "", department_id: "" });
  const [err, setErr] = useState("");
  const [orgs, setOrgs] = useState<{ id: number; name: string }[]>([]);
  const [copyTgt, setCopyTgt] = useState("");
  const [incPos, setIncPos] = useState(true);
  const [incCc, setIncCc] = useState(true);
  const [incLt, setIncLt] = useState(true);
  const [incBt, setIncBt] = useState(true);
  const [copyMsg, setCopyMsg] = useState("");
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    apiFetch(`/organizations/${orgId}/departments`).then(setDepts).catch((e) => setErr(e.message));
    apiFetch(`/organizations/${orgId}/positions`).then(setPositions).catch(() => {});
    apiFetch<{ id: number; name: string }[]>("/organizations").then(setOrgs).catch(() => {});
  }, [orgId]);
  useEffect(load, [load]);

  async function copySetup() {
    if (!copyTgt) return;
    const name = orgs.find((o) => o.id === Number(copyTgt))?.name || "the selected organization";
    if (!window.confirm(`Copy this organization's setup to ${name}? Existing items there are kept (duplicates skipped).`)) return;
    setBusy(true); setErr(""); setCopyMsg("");
    try {
      const r = await apiFetch<{ departments: number; positions: number; cost_centers: number; leave_types: number; benefit_types: number }>(`/organizations/${orgId}/setup/copy-to`, {
        method: "POST", body: JSON.stringify({ target_organization_id: Number(copyTgt), include_positions: incPos, include_cost_centers: incCc, include_leave_types: incLt, include_benefit_types: incBt }),
      });
      setCopyMsg(`Copied to ${name}: ${r.departments} department(s), ${r.positions} position(s), ${r.cost_centers} cost center(s), ${r.leave_types} leave type(s), ${r.benefit_types} benefit type(s).`);
      setCopyTgt("");
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }

  async function addDept() {
    if (!dForm.name) return;
    try { await apiFetch(`/organizations/${orgId}/departments`, { method: "POST", body: JSON.stringify(dForm) }); setDForm({ name: "", code: "" }); load(); }
    catch (e: any) { setErr(e.message); }
  }
  async function delDept(id: number) {
    if (!confirm("Delete this department?")) return;
    await apiFetch(`/organizations/${orgId}/departments/${id}`, { method: "DELETE" }); load();
  }
  async function addPos() {
    if (!pForm.title) return;
    const body: any = { title: pForm.title, job_grade: pForm.job_grade };
    if (pForm.department_id) body.department_id = Number(pForm.department_id);
    try { await apiFetch(`/organizations/${orgId}/positions`, { method: "POST", body: JSON.stringify(body) }); setPForm({ title: "", job_grade: "", department_id: "" }); load(); }
    catch (e: any) { setErr(e.message); }
  }
  async function delPos(id: number) {
    if (!confirm("Delete this position?")) return;
    await apiFetch(`/organizations/${orgId}/positions/${id}`, { method: "DELETE" }); load();
  }

  const deptName = (id: number) => depts.find((d) => d.id === id)?.name || "—";

  return (
    <AppShell orgId={orgId}>
      <h1 className="mb-1 text-lg font-semibold text-slate-900">Organization Setup</h1>
      <p className="mb-4 text-sm text-slate-500">Define departments and positions before adding people.</p>
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}
      {copyMsg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{copyMsg}</div> : null}

      {orgs.filter((o) => o.id !== orgId).length > 0 ? (
        <div className="card mb-6 border-l-4 border-geek-blue">
          <div className="mb-1 text-sm font-medium text-slate-700">Copy this setup to another organization</div>
          <p className="mb-3 text-sm text-slate-500">Reuse this company's setup elsewhere instead of re-entering it. Items already there are skipped, so it's safe to run again.</p>
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
            <select className="input max-w-[240px]" value={copyTgt} onChange={(e) => setCopyTgt(e.target.value)}>
              <option value="">Copy to…</option>
              {orgs.filter((o) => o.id !== orgId).map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </select>
            <label className="flex items-center gap-1.5 text-sm text-slate-600"><input type="checkbox" checked disabled /> Departments</label>
            <label className="flex items-center gap-1.5 text-sm text-slate-600"><input type="checkbox" checked={incPos} onChange={(e) => setIncPos(e.target.checked)} /> Positions</label>
            <label className="flex items-center gap-1.5 text-sm text-slate-600"><input type="checkbox" checked={incCc} onChange={(e) => setIncCc(e.target.checked)} /> Cost centers</label>
            <label className="flex items-center gap-1.5 text-sm text-slate-600"><input type="checkbox" checked={incLt} onChange={(e) => setIncLt(e.target.checked)} /> Leave types</label>
            <label className="flex items-center gap-1.5 text-sm text-slate-600"><input type="checkbox" checked={incBt} onChange={(e) => setIncBt(e.target.checked)} /> Benefit types</label>
            <button className="btn-primary" disabled={busy || !copyTgt} onClick={copySetup}>{busy ? "Copying…" : "Copy setup"}</button>
          </div>
        </div>
      ) : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Departments */}
        <div className="card p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Departments ({depts.length})</div>
          <div className="p-4">
            <div className="mb-3 flex gap-2">
              <input className="input" placeholder="Department name" value={dForm.name} onChange={(e) => setDForm({ ...dForm, name: e.target.value })} />
              <input className="input max-w-[100px]" placeholder="Code" value={dForm.code} onChange={(e) => setDForm({ ...dForm, code: e.target.value })} />
              <button className="btn-primary whitespace-nowrap" onClick={addDept}>Add</button>
            </div>
            <table className="min-w-full text-sm">
              <tbody className="divide-y divide-slate-100">
                {depts.map((d) => (
                  <tr key={d.id}><td className="py-2">{d.name}</td><td className="py-2 text-xs text-slate-500">{d.code || "—"}</td>
                    <td className="py-2 text-right"><button className="text-red-600 hover:underline" onClick={() => delDept(d.id)}>Delete</button></td></tr>
                ))}
                {depts.length === 0 ? <tr><td className="py-4 text-center text-slate-400">No departments yet.</td></tr> : null}
              </tbody>
            </table>
          </div>
        </div>

        {/* Positions */}
        <div className="card p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Positions ({positions.length})</div>
          <div className="p-4">
            <div className="mb-3 grid grid-cols-2 gap-2">
              <input className="input" placeholder="Position title" value={pForm.title} onChange={(e) => setPForm({ ...pForm, title: e.target.value })} />
              <input className="input" placeholder="Job grade" value={pForm.job_grade} onChange={(e) => setPForm({ ...pForm, job_grade: e.target.value })} />
              <select className="input" value={pForm.department_id} onChange={(e) => setPForm({ ...pForm, department_id: e.target.value })}>
                <option value="">— department —</option>
                {depts.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
              </select>
              <button className="btn-primary" onClick={addPos}>Add</button>
            </div>
            <table className="min-w-full text-sm">
              <tbody className="divide-y divide-slate-100">
                {positions.map((p) => (
                  <tr key={p.id}><td className="py-2">{p.title}</td><td className="py-2 text-xs text-slate-500">{p.job_grade || "—"}</td>
                    <td className="py-2 text-xs text-slate-500">{p.department_id ? deptName(p.department_id) : "—"}</td>
                    <td className="py-2 text-right"><button className="text-red-600 hover:underline" onClick={() => delPos(p.id)}>Delete</button></td></tr>
                ))}
                {positions.length === 0 ? <tr><td className="py-4 text-center text-slate-400">No positions yet.</td></tr> : null}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AppShell>
  );
}
