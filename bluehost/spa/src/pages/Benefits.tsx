import { useCallback, useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";
import { peso } from "@/lib/format";

// Suggestions only — HR can type any benefit type a given organization offers.
const BENEFIT_TYPES = ["HMO / Health", "Life Insurance", "Dental", "Accident", "Retirement",
  "Allowance", "Rice Subsidy", "Meal Allowance", "Transportation", "Educational Assistance", "Other"];
const EMPTY: any = { person_id: "", benefit_type: "", provider: "", policy_number: "", coverage_amount: "",
  start_date: "", end_date: "", status: "ACTIVE", remarks: "", beneficiaries: [] };

export default function BenefitsPage() {
  const orgId = Number(useParams().orgId);
  const [rows, setRows] = useState<any[]>([]);
  const [people, setPeople] = useState<any[]>([]);
  const [filterPid, setFilterPid] = useState("");
  const [editing, setEditing] = useState<null | "new" | any>(null);
  const [form, setForm] = useState<any>({ ...EMPTY });
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const load = useCallback(() => {
    const url = filterPid ? `/organizations/${orgId}/benefits?person_id=${filterPid}` : `/organizations/${orgId}/benefits`;
    apiFetch(url).then(setRows).catch((e) => setErr(e.message));
    apiFetch(`/organizations/${orgId}/people`).then(setPeople).catch(() => {});
  }, [orgId, filterPid]);
  useEffect(load, [load]);

  function openCreate() { setForm({ ...EMPTY, beneficiaries: [] }); setEditing("new"); setErr(""); }
  function openEdit(b: any) {
    setForm({ ...EMPTY, ...b, person_id: b.person_id, coverage_amount: b.coverage_amount ?? "",
      start_date: b.start_date ?? "", end_date: b.end_date ?? "", beneficiaries: (b.beneficiaries || []).map((x: any) => ({ ...x })) });
    setEditing(b); setErr("");
  }

  function setBen(i: number, k: string, v: string) {
    const list = [...form.beneficiaries]; list[i] = { ...list[i], [k]: v }; setForm({ ...form, beneficiaries: list });
  }
  function addBen() { setForm({ ...form, beneficiaries: [...form.beneficiaries, { name: "", relationship: "", share_percent: "", contact: "" }] }); }
  function delBen(i: number) { setForm({ ...form, beneficiaries: form.beneficiaries.filter((_: any, j: number) => j !== i) }); }

  function payload() {
    return {
      person_id: Number(form.person_id), benefit_type: form.benefit_type, provider: form.provider || null,
      policy_number: form.policy_number || null, coverage_amount: form.coverage_amount === "" ? null : Number(form.coverage_amount),
      start_date: form.start_date || null, end_date: form.end_date || null, status: form.status, remarks: form.remarks || null,
      beneficiaries: form.beneficiaries
        .filter((b: any) => (b.name || "").trim())
        .map((b: any) => ({ name: b.name, relationship: b.relationship || null, share_percent: b.share_percent === "" ? null : Number(b.share_percent), contact: b.contact || null })),
    };
  }
  async function save() {
    setErr("");
    if (editing === "new" && !form.person_id) { setErr("Choose an employee."); return; }
    if (!(form.benefit_type || "").trim()) { setErr("Enter a benefit type."); return; }
    try {
      if (editing === "new") await apiFetch(`/organizations/${orgId}/benefits`, { method: "POST", body: JSON.stringify(payload()) });
      else await apiFetch(`/organizations/${orgId}/benefits/${editing.id}`, { method: "PUT", body: JSON.stringify(payload()) });
      setMsg("Benefit saved."); setEditing(null); load();
    } catch (e: any) { setErr(e.message); }
  }
  async function remove(b: any) {
    if (!confirm(`Remove this ${b.benefit_type} benefit for ${b.person}?`)) return;
    await apiFetch(`/organizations/${orgId}/benefits/${b.id}`, { method: "DELETE" }); load();
  }

  const F = (k: string, label: string, extra: any = {}) => (
    <div><label className="mb-1 block text-xs text-slate-500">{label}</label>
      <input className="input" value={form[k] ?? ""} onChange={(e) => setForm({ ...form, [k]: e.target.value })} {...extra} /></div>
  );

  return (
    <AppShell orgId={orgId}>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="text-lg font-semibold text-slate-900">Benefits</h1>
          <p className="text-sm text-slate-500">Health coverage and other benefits, with beneficiaries.</p></div>
        <div className="flex items-center gap-2">
          <select className="input max-w-xs" value={filterPid} onChange={(e) => setFilterPid(e.target.value)}>
            <option value="">All employees</option>
            {people.map((p) => <option key={p.person_id} value={p.person_id}>{p.full_name}</option>)}
          </select>
          <button className="btn-primary whitespace-nowrap" onClick={openCreate}>+ New Benefit</button>
        </div>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="card p-0 overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50"><tr className="text-left text-xs uppercase text-slate-500">
            <th className="px-4 py-2">Employee</th><th className="px-4 py-2">Type</th><th className="px-4 py-2">Provider</th>
            <th className="px-4 py-2 text-right">Coverage</th><th className="px-4 py-2">Beneficiaries</th><th className="px-4 py-2">Status</th><th className="px-4 py-2"></th>
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((b) => (
              <tr key={b.id} className="hover:bg-slate-50 align-top">
                <td className="px-4 py-2 font-medium text-slate-800">{b.person}</td>
                <td className="px-4 py-2">{b.benefit_type}</td>
                <td className="px-4 py-2 text-slate-600">{b.provider || "—"}{b.policy_number ? <div className="text-xs text-slate-400">#{b.policy_number}</div> : null}</td>
                <td className="px-4 py-2 text-right">{b.coverage_amount != null ? peso(b.coverage_amount) : "—"}</td>
                <td className="px-4 py-2 text-xs text-slate-600">
                  {b.beneficiaries?.length ? b.beneficiaries.map((x: any, i: number) => (
                    <div key={i}>{x.name}{x.relationship ? ` (${x.relationship})` : ""}{x.share_percent != null ? ` — ${x.share_percent}%` : ""}</div>
                  )) : <span className="text-slate-400">—</span>}
                </td>
                <td className="px-4 py-2"><span className="badge bg-slate-100 text-slate-600">{b.status}</span></td>
                <td className="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                  <button className="text-brand-700 hover:underline" onClick={() => openEdit(b)}>Edit</button>
                  <button className="text-red-600 hover:underline" onClick={() => remove(b)}>Remove</button>
                </td>
              </tr>
            ))}
            {rows.length === 0 ? <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-400">No benefits recorded.</td></tr> : null}
          </tbody>
        </table>
      </div>

      {editing !== null ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setEditing(null)}>
          <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 className="mb-4 text-base font-semibold">{editing === "new" ? "New Benefit" : `Edit Benefit — ${(editing as any).person}`}</h2>
            <div className="grid grid-cols-2 gap-3">
              <div><label className="mb-1 block text-xs text-slate-500">Employee *</label>
                {editing === "new" ? (
                  <select className="input" value={form.person_id} onChange={(e) => setForm({ ...form, person_id: e.target.value })}>
                    <option value="">Select…</option>{people.map((p) => <option key={p.person_id} value={p.person_id}>{p.full_name}</option>)}
                  </select>
                ) : <input className="input bg-slate-50" value={(editing as any).person} disabled />}
              </div>
              <div><label className="mb-1 block text-xs text-slate-500">Benefit type *</label>
                <input className="input" list="benefit-types" placeholder="e.g. HMO / Health — or type your own" value={form.benefit_type} onChange={(e) => setForm({ ...form, benefit_type: e.target.value })} />
                <datalist id="benefit-types">{BENEFIT_TYPES.map((t) => <option key={t} value={t} />)}</datalist></div>
              {F("provider", "Provider")}
              {F("policy_number", "Policy number")}
              {F("coverage_amount", "Coverage amount", { type: "number" })}
              <div><label className="mb-1 block text-xs text-slate-500">Status</label>
                <select className="input" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                  {["ACTIVE", "SUSPENDED", "ENDED"].map((s) => <option key={s}>{s}</option>)}</select></div>
              {F("start_date", "Start date", { type: "date" })}
              {F("end_date", "End date", { type: "date" })}
              <div className="col-span-2">{F("remarks", "Remarks")}</div>
            </div>

            <div className="mt-5">
              <div className="mb-2 flex items-center justify-between">
                <div className="text-sm font-medium text-slate-700">Beneficiaries</div>
                <button className="text-sm text-brand-700 hover:underline" onClick={addBen}>+ Add beneficiary</button>
              </div>
              {form.beneficiaries.length === 0 ? <p className="text-xs text-slate-400">None yet.</p> : (
                <div className="space-y-2">
                  {form.beneficiaries.map((b: any, i: number) => (
                    <div key={i} className="grid grid-cols-12 gap-2">
                      <input className="input col-span-4" placeholder="Full name" value={b.name} onChange={(e) => setBen(i, "name", e.target.value)} />
                      <input className="input col-span-3" placeholder="Relationship" value={b.relationship} onChange={(e) => setBen(i, "relationship", e.target.value)} />
                      <input className="input col-span-2" type="number" placeholder="% share" value={b.share_percent} onChange={(e) => setBen(i, "share_percent", e.target.value)} />
                      <input className="input col-span-2" placeholder="Contact" value={b.contact} onChange={(e) => setBen(i, "contact", e.target.value)} />
                      <button className="col-span-1 text-red-600 hover:underline" onClick={() => delBen(i)}>✕</button>
                    </div>
                  ))}
                </div>
              )}
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
