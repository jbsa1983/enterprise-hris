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

  const load = useCallback(() => {
    apiFetch(`/organizations/${orgId}/departments`).then(setDepts).catch((e) => setErr(e.message));
    apiFetch(`/organizations/${orgId}/positions`).then(setPositions).catch(() => {});
  }, [orgId]);
  useEffect(load, [load]);

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
