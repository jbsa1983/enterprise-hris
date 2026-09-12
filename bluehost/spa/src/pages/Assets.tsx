
import { useCallback, useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";
import { peso } from "@/lib/format";

const EMPTY: any = { asset_number: "", item: "", serial_number: "", is_employee_payable: false,
  assigned_person_id: "", cost: "", employee_share: "", installment: "", condition: "GOOD", status: "ISSUED" };

export default function AssetsPage() {
  const orgId = Number(useParams().orgId);
  const [rows, setRows] = useState<any[]>([]);
  const [people, setPeople] = useState<any[]>([]);
  const [editing, setEditing] = useState<null | "new" | any>(null);
  const [form, setForm] = useState<any>({ ...EMPTY });
  const [action, setAction] = useState<null | { kind: "return" | "reassign"; asset: any }>(null);
  const [af, setAf] = useState<any>({ condition: "GOOD", remarks: "", assigned_person_id: "" });
  const [retAllPid, setRetAllPid] = useState("");
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const load = useCallback(() => {
    apiFetch(`/organizations/${orgId}/assets`).then(setRows).catch((e) => setErr(e.message));
    apiFetch(`/organizations/${orgId}/people`).then(setPeople).catch(() => {});
  }, [orgId]);
  useEffect(load, [load]);

  function openCreate() { setForm({ ...EMPTY }); setEditing("new"); setErr(""); }
  function openEdit(a: any) {
    setForm({ ...EMPTY, ...a, assigned_person_id: a.assigned_person_id ?? "",
      cost: a.cost ?? "", employee_share: a.employee_share ?? "", installment: a.installment ?? "" });
    setEditing(a); setErr("");
  }

  function payload() {
    const p: any = { asset_number: form.asset_number, item: form.item, serial_number: form.serial_number || null,
      is_employee_payable: !!form.is_employee_payable, condition: form.condition, status: form.status };
    if (form.assigned_person_id) p.assigned_person_id = Number(form.assigned_person_id);
    ["cost", "employee_share", "installment"].forEach((k) => { if (form[k] !== "") p[k] = Number(form[k]); });
    return p;
  }
  async function save() {
    setErr("");
    try {
      if (editing === "new") await apiFetch(`/organizations/${orgId}/assets`, { method: "POST", body: JSON.stringify(payload()) });
      else await apiFetch(`/organizations/${orgId}/assets/${editing.id}`, { method: "PUT", body: JSON.stringify(payload()) });
      setMsg("Asset saved."); setEditing(null); load();
    } catch (e: any) { setErr(e.message); }
  }
  async function remove(a: any) {
    if (!confirm(`Remove asset ${a.asset_number}?`)) return;
    await apiFetch(`/organizations/${orgId}/assets/${a.id}`, { method: "DELETE" }); load();
  }
  async function returnAll() {
    if (!retAllPid) return;
    if (!confirm("Mark ALL issued assets of this employee as returned? They stay on record as history.")) return;
    setErr("");
    try {
      const r = await apiFetch<any>(`/organizations/${orgId}/assets/return-all`, { method: "POST",
        body: JSON.stringify({ person_id: Number(retAllPid), remarks: "Returned on separation" }) });
      setMsg(`Returned ${r.returned} asset(s).`); setRetAllPid(""); load();
    } catch (e: any) { setErr(e.message); }
  }
  function openAction(kind: "return" | "reassign", asset: any) {
    setAf({ condition: asset.condition || "GOOD", remarks: "", assigned_person_id: "" });
    setAction({ kind, asset }); setErr("");
  }
  async function runAction() {
    if (!action) return;
    setErr("");
    try {
      if (action.kind === "return") {
        await apiFetch(`/organizations/${orgId}/assets/${action.asset.id}/return`, { method: "POST",
          body: JSON.stringify({ condition: af.condition, remarks: af.remarks }) });
      } else {
        await apiFetch(`/organizations/${orgId}/assets/${action.asset.id}/reassign`, { method: "POST",
          body: JSON.stringify({ assigned_person_id: af.assigned_person_id ? Number(af.assigned_person_id) : null, condition: af.condition, remarks: af.remarks }) });
      }
      setMsg(action.kind === "return" ? "Asset returned." : "Asset reassigned."); setAction(null); load();
    } catch (e: any) { setErr(e.message); }
  }

  const F = (k: string, label: string, extra: any = {}) => (
    <div><label className="mb-1 block text-xs text-slate-500">{label}</label>
      <input className="input" value={form[k] ?? ""} onChange={(e) => setForm({ ...form, [k]: e.target.value })} {...extra} /></div>
  );

  return (
    <AppShell orgId={orgId}>
      <div className="mb-4 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-900">Assets</h1>
        <button className="btn-primary" onClick={openCreate}>+ New Asset</button>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="card mb-4 flex flex-wrap items-end gap-3">
        <div className="mr-auto text-sm">
          <div className="font-medium text-slate-700">Separation — return all assets</div>
          <div className="text-xs text-slate-500">Marks every issued asset of the selected person as returned (kept on record as history).</div>
        </div>
        <select className="input max-w-xs" value={retAllPid} onChange={(e) => setRetAllPid(e.target.value)}>
          <option value="">Select employee…</option>
          {people.map((p) => <option key={p.person_id} value={p.person_id}>{p.full_name}</option>)}
        </select>
        <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-40" disabled={!retAllPid} onClick={returnAll}>Return all</button>
      </div>

      <div className="card p-0 overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50"><tr className="text-left text-xs uppercase text-slate-500">
            <th className="px-4 py-2">Asset No.</th><th className="px-4 py-2">Item</th><th className="px-4 py-2">Assigned to</th><th className="px-4 py-2">Serial</th>
            <th className="px-4 py-2">Type</th><th className="px-4 py-2 text-right">Cost</th>
            <th className="px-4 py-2 text-right">Outstanding</th><th className="px-4 py-2">Status</th><th className="px-4 py-2"></th>
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((a) => (
              <tr key={a.id} className="hover:bg-slate-50">
                <td className="px-4 py-2 font-mono text-xs">{a.asset_number}</td>
                <td className="px-4 py-2">{a.item}</td>
                <td className="px-4 py-2 text-slate-600">{a.assigned_person || "—"}</td>
                <td className="px-4 py-2 text-xs text-slate-500">{a.serial_number || "—"}</td>
                <td className="px-4 py-2">{a.is_employee_payable ? "Employee-payable" : "Company"}</td>
                <td className="px-4 py-2 text-right">{peso(a.cost)}</td>
                <td className="px-4 py-2 text-right">{a.is_employee_payable ? peso(a.outstanding_balance) : "—"}</td>
                <td className="px-4 py-2"><span className="badge bg-slate-100 text-slate-600">{a.status}</span>{a.returned_date ? <div className="text-[11px] text-slate-400">ret. {a.returned_date}</div> : null}</td>
                <td className="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                  <button className="text-brand-700 hover:underline" onClick={() => openEdit(a)}>Edit</button>
                  {a.status !== "RETURNED" ? <button className="text-slate-600 hover:underline" onClick={() => openAction("return", a)}>Return</button> : null}
                  <button className="text-slate-600 hover:underline" onClick={() => openAction("reassign", a)}>Reassign</button>
                  <button className="text-red-600 hover:underline" onClick={() => remove(a)}>Remove</button>
                </td>
              </tr>
            ))}
            {rows.length === 0 ? <tr><td colSpan={9} className="px-4 py-6 text-center text-slate-400">No assets.</td></tr> : null}
          </tbody>
        </table>
      </div>

      {editing !== null ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setEditing(null)}>
          <div className="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 className="mb-4 text-base font-semibold">{editing === "new" ? "New Asset" : "Edit Asset"}</h2>
            <div className="grid grid-cols-2 gap-3">
              {F("asset_number", "Asset number *")}{F("item", "Item *")}
              {F("serial_number", "Serial number")}
              <div><label className="mb-1 block text-xs text-slate-500">Assigned to</label>
                <select className="input" value={form.assigned_person_id} onChange={(e) => setForm({ ...form, assigned_person_id: e.target.value })}>
                  <option value="">—</option>{people.map((p) => <option key={p.person_id} value={p.person_id}>{p.full_name}</option>)}</select></div>
              {F("cost", "Cost", { type: "number" })}
              <div><label className="mb-1 block text-xs text-slate-500">Condition</label>
                <select className="input" value={form.condition} onChange={(e) => setForm({ ...form, condition: e.target.value })}>
                  {["GOOD", "FAIR", "DAMAGED", "LOST"].map((c) => <option key={c}>{c}</option>)}</select></div>
              <div><label className="mb-1 block text-xs text-slate-500">Status</label>
                <select className="input" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                  {["ISSUED", "RETURNED", "IN_STOCK", "RETIRED"].map((s) => <option key={s}>{s}</option>)}</select></div>
              <label className="col-span-2 flex items-center gap-2 text-sm">
                <input type="checkbox" checked={!!form.is_employee_payable} onChange={(e) => setForm({ ...form, is_employee_payable: e.target.checked })} />
                Employee-payable (creates a payroll receivable)
              </label>
              {form.is_employee_payable ? (<>{F("employee_share", "Employee share", { type: "number" })}{F("installment", "Installment / period", { type: "number" })}</>) : null}
            </div>
            {err ? <div className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}
            <div className="mt-5 flex justify-end gap-2">
              <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm" onClick={() => setEditing(null)}>Cancel</button>
              <button className="btn-primary" onClick={save}>Save</button>
            </div>
          </div>
        </div>
      ) : null}
      {action ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setAction(null)}>
          <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 className="mb-1 text-base font-semibold">{action.kind === "return" ? "Return Asset" : "Reassign Asset"}</h2>
            <p className="mb-4 text-xs text-slate-500">{action.asset.item} · {action.asset.asset_number}{action.asset.assigned_person ? ` · currently: ${action.asset.assigned_person}` : ""}</p>
            <div className="space-y-3">
              {action.kind === "reassign" ? (
                <div><label className="mb-1 block text-xs text-slate-500">New assignee</label>
                  <select className="input" value={af.assigned_person_id} onChange={(e) => setAf({ ...af, assigned_person_id: e.target.value })}>
                    <option value="">— unassigned (return to stock) —</option>
                    {people.map((p) => <option key={p.person_id} value={p.person_id}>{p.full_name}</option>)}
                  </select></div>
              ) : null}
              <div><label className="mb-1 block text-xs text-slate-500">Condition</label>
                <select className="input" value={af.condition} onChange={(e) => setAf({ ...af, condition: e.target.value })}>
                  {["GOOD", "FAIR", "DAMAGED", "LOST"].map((c) => <option key={c}>{c}</option>)}</select></div>
              <div><label className="mb-1 block text-xs text-slate-500">Remarks</label>
                <textarea className="input" rows={2} value={af.remarks} onChange={(e) => setAf({ ...af, remarks: e.target.value })} placeholder="e.g. returned on resignation; screen scratched" /></div>
            </div>
            {err ? <div className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}
            <div className="mt-5 flex justify-end gap-2">
              <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm" onClick={() => setAction(null)}>Cancel</button>
              <button className="btn-primary" onClick={runAction}>{action.kind === "return" ? "Confirm return" : "Reassign"}</button>
            </div>
          </div>
        </div>
      ) : null}
    </AppShell>
  );
}
