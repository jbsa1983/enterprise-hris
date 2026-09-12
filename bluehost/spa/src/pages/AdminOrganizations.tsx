import { useCallback, useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import Link from "@/lib/Link";
import { apiFetch } from "@/lib/api";
import { statusColor } from "@/lib/format";

interface Org {
  id: number; name: string; code: string; legal_name?: string; tin?: string; address?: string; is_active: boolean;
}
const EMPTY: any = { name: "", code: "", legal_name: "", tin: "", address: "", is_active: true };

export default function AdminOrganizations() {
  const [rows, setRows] = useState<Org[]>([]);
  const [editing, setEditing] = useState<null | "new" | Org>(null);
  const [form, setForm] = useState<any>({ ...EMPTY });
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const load = useCallback(() => {
    apiFetch<Org[]>("/admin/organizations").then(setRows).catch((e) => setErr(e.message));
  }, []);
  useEffect(load, [load]);

  function openCreate() { setForm({ ...EMPTY }); setEditing("new"); setErr(""); }
  function openEdit(o: Org) { setForm({ ...EMPTY, ...o }); setEditing(o); setErr(""); }

  async function save() {
    setErr("");
    try {
      if (editing === "new") {
        await apiFetch("/admin/organizations", { method: "POST", body: JSON.stringify(form) });
        setMsg("Organization created.");
      } else if (editing) {
        const body = { name: form.name, legal_name: form.legal_name, tin: form.tin, address: form.address, is_active: form.is_active };
        await apiFetch(`/admin/organizations/${editing.id}`, { method: "PUT", body: JSON.stringify(body) });
        setMsg("Organization updated.");
      }
      setEditing(null); load();
    } catch (e: any) { setErr(e.message); }
  }

  const F = (k: string, label: string) => (
    <div><label className="mb-1 block text-xs text-slate-500">{label}</label>
      <input className="input" value={form[k] ?? ""} onChange={(e) => setForm({ ...form, [k]: e.target.value })} /></div>
  );

  return (
    <AppShell>
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Organizations</h1>
          <p className="text-sm text-slate-500">Create and manage the companies in your enterprise group.</p>
        </div>
        <button className="btn-primary" onClick={openCreate}>+ New Organization</button>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="card p-0 overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50"><tr className="text-left text-xs uppercase text-slate-500">
            <th className="px-4 py-2">Code</th><th className="px-4 py-2">Name</th><th className="px-4 py-2">Legal name</th>
            <th className="px-4 py-2">Status</th><th className="px-4 py-2"></th>
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((o) => (
              <tr key={o.id} className="hover:bg-slate-50">
                <td className="px-4 py-2 font-mono text-xs">{o.code}</td>
                <td className="px-4 py-2 font-medium text-slate-800">{o.name}</td>
                <td className="px-4 py-2 text-slate-600">{o.legal_name || "—"}</td>
                <td className="px-4 py-2"><span className={`badge ${o.is_active ? statusColor("ACTIVE") : "bg-slate-200 text-slate-500"}`}>{o.is_active ? "Active" : "Inactive"}</span></td>
                <td className="px-4 py-2 text-right space-x-3 whitespace-nowrap">
                  <button className="text-brand-700 hover:underline" onClick={() => openEdit(o)}>Edit</button>
                  <Link href={`/o/${o.id}/setup`} className="text-brand-700 hover:underline">Setup</Link>
                  <Link href={`/o/${o.id}/dashboard`} className="text-brand-700 hover:underline">Open</Link>
                </td>
              </tr>
            ))}
            {rows.length === 0 ? <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-400">No organizations yet — click “New Organization”.</td></tr> : null}
          </tbody>
        </table>
      </div>

      {editing ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setEditing(null)}>
          <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 className="mb-4 text-base font-semibold">{editing === "new" ? "New Organization" : `Edit ${(editing as Org).name}`}</h2>
            <div className="grid grid-cols-2 gap-3">
              {F("name", "Name *")}
              {editing === "new"
                ? F("code", "Code * (short, unique)")
                : <div><label className="mb-1 block text-xs text-slate-500">Code</label><input className="input bg-slate-50" value={form.code} disabled /></div>}
              {F("legal_name", "Legal name")}
              {F("tin", "TIN")}
              <div className="col-span-2">{F("address", "Address")}</div>
              {editing !== "new" ? (
                <label className="col-span-2 flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={!!form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} /> Active
                </label>
              ) : null}
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
