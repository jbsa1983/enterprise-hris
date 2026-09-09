"use client";

import { useCallback, useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";
import { statusColor } from "@/lib/format";

interface Role { id: number; name: string; }
interface Org { id: number; name: string; }
interface AdminUser {
  id: number; email: string; full_name: string; is_active: boolean; is_superadmin: boolean;
  roles: string[]; organizations: { organization_id: number; name: string }[];
}

const EMPTY = { email: "", full_name: "", password: "", is_superadmin: false, is_active: true,
  role_ids: [] as number[], organization_ids: [] as number[] };

export default function AdminUsersPage() {
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [orgs, setOrgs] = useState<Org[]>([]);
  const [editing, setEditing] = useState<AdminUser | "new" | null>(null);
  const [form, setForm] = useState({ ...EMPTY });
  const [msg, setMsg] = useState("");
  const [err, setErr] = useState("");
  const [prov, setProv] = useState<{ open: boolean; password: string; result: any }>({ open: false, password: "", result: null });

  async function runProvision() {
    setErr("");
    try {
      const body: any = {};
      if (prov.password) body.default_password = prov.password;
      const r = await apiFetch<any>("/admin/provision-ess", { method: "POST", body: JSON.stringify(body) });
      setProv({ ...prov, result: r }); load();
    } catch (e: any) { setErr(e.message); }
  }
  function downloadCreds(creds: any[]) {
    const rows = [["Name", "Login Email", "Temporary Password"], ...creds.map((c) => [c.name, c.email, c.temp_password])];
    const csv = rows.map((r) => r.map((x) => `"${String(x).replace(/"/g, '""')}"`).join(",")).join("\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
    const a = document.createElement("a"); a.href = url; a.download = "employee_logins.csv"; a.click(); URL.revokeObjectURL(url);
  }

  const load = useCallback(() => {
    apiFetch<AdminUser[]>("/admin/users").then(setUsers).catch((e) => setErr(e.message));
    apiFetch<Role[]>("/admin/roles").then(setRoles).catch(() => {});
    apiFetch<Org[]>("/admin/organizations").then(setOrgs).catch(() => {});
  }, []);
  useEffect(load, [load]);

  function openCreate() {
    setForm({ ...EMPTY }); setEditing("new"); setErr("");
  }
  function openEdit(u: AdminUser) {
    setForm({
      email: u.email, full_name: u.full_name, password: "", is_superadmin: u.is_superadmin,
      is_active: u.is_active,
      role_ids: roles.filter((r) => u.roles.includes(r.name)).map((r) => r.id),
      organization_ids: u.organizations.map((o) => o.organization_id),
    });
    setEditing(u); setErr("");
  }
  function toggle(list: number[], id: number) {
    return list.includes(id) ? list.filter((x) => x !== id) : [...list, id];
  }

  async function save() {
    setErr("");
    try {
      if (editing === "new") {
        await apiFetch("/admin/users", { method: "POST", body: JSON.stringify(form) });
        setMsg("User created.");
      } else if (editing) {
        const body: any = {
          full_name: form.full_name, email: form.email, is_active: form.is_active,
          is_superadmin: form.is_superadmin, role_ids: form.role_ids, organization_ids: form.organization_ids,
        };
        await apiFetch(`/admin/users/${editing.id}`, { method: "PUT", body: JSON.stringify(body) });
        if (form.password) await apiFetch(`/admin/users/${editing.id}/password`, { method: "POST", body: JSON.stringify({ password: form.password }) });
        setMsg("User updated.");
      }
      setEditing(null); load();
    } catch (e: any) { setErr(e.message); }
  }

  async function deactivate(u: AdminUser) {
    setErr("");
    try { await apiFetch(`/admin/users/${u.id}/deactivate`, { method: "POST" }); load(); }
    catch (e: any) { setErr(e.message); }
  }

  return (
    <AppShell>
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Users</h1>
          <p className="text-sm text-slate-500">Superadmin manages all accounts, roles, and organization access.</p>
        </div>
        <div className="flex gap-2">
          <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50" onClick={() => { setProv({ open: true, password: "", result: null }); }}>
            Provision Employee Logins
          </button>
          <button className="btn-primary" onClick={openCreate}>+ New User</button>
        </div>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="card p-0 overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50"><tr className="text-left text-xs uppercase text-slate-500">
            <th className="px-4 py-2">Email</th><th className="px-4 py-2">Name</th><th className="px-4 py-2">Roles</th>
            <th className="px-4 py-2">Organizations</th><th className="px-4 py-2">Status</th><th className="px-4 py-2"></th>
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {users.map((u) => (
              <tr key={u.id} className="hover:bg-slate-50">
                <td className="px-4 py-2">{u.email}{u.is_superadmin ? <span className="ml-2 badge bg-amber-100 text-amber-700">SUPERADMIN</span> : null}</td>
                <td className="px-4 py-2">{u.full_name}</td>
                <td className="px-4 py-2 text-xs text-slate-600">{u.roles.join(", ") || "—"}</td>
                <td className="px-4 py-2 text-xs text-slate-500">{u.organizations.map((o) => o.name).join(", ") || "—"}</td>
                <td className="px-4 py-2"><span className={`badge ${u.is_active ? statusColor("ACTIVE") : "bg-slate-200 text-slate-500"}`}>{u.is_active ? "Active" : "Inactive"}</span></td>
                <td className="px-4 py-2 text-right space-x-2">
                  <button className="text-brand-700 hover:underline" onClick={() => openEdit(u)}>Edit</button>
                  {u.is_active ? <button className="text-red-600 hover:underline" onClick={() => deactivate(u)}>Deactivate</button> : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {editing ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setEditing(null)}>
          <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 className="mb-4 text-base font-semibold">{editing === "new" ? "New User" : `Edit ${editing.email}`}</h2>
            <div className="space-y-3">
              <div><label className="mb-1 block text-sm text-slate-600">Email</label>
                <input className="input" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></div>
              <div><label className="mb-1 block text-sm text-slate-600">Full name</label>
                <input className="input" value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} /></div>
              <div><label className="mb-1 block text-sm text-slate-600">{editing === "new" ? "Password" : "Reset password (optional)"}</label>
                <input className="input" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder="min 8 characters" /></div>
              <div className="flex gap-6">
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} /> Active</label>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_superadmin} onChange={(e) => setForm({ ...form, is_superadmin: e.target.checked })} /> Superadmin (all access)</label>
              </div>
              <div>
                <label className="mb-1 block text-sm text-slate-600">Roles (permission scopes)</label>
                <div className="flex max-h-28 flex-wrap gap-2 overflow-y-auto rounded-lg border border-slate-200 p-2">
                  {roles.map((r) => (
                    <label key={r.id} className={`cursor-pointer rounded-full px-2 py-0.5 text-xs ${form.role_ids.includes(r.id) ? "bg-brand-600 text-white" : "bg-slate-100 text-slate-600"}`}>
                      <input type="checkbox" className="hidden" checked={form.role_ids.includes(r.id)} onChange={() => setForm({ ...form, role_ids: toggle(form.role_ids, r.id) })} />{r.name}
                    </label>
                  ))}
                </div>
              </div>
              <div>
                <label className="mb-1 block text-sm text-slate-600">Organization access</label>
                <div className="flex max-h-24 flex-wrap gap-2 overflow-y-auto rounded-lg border border-slate-200 p-2">
                  {orgs.map((o) => (
                    <label key={o.id} className={`cursor-pointer rounded-full px-2 py-0.5 text-xs ${form.organization_ids.includes(o.id) ? "bg-emerald-600 text-white" : "bg-slate-100 text-slate-600"}`}>
                      <input type="checkbox" className="hidden" checked={form.organization_ids.includes(o.id)} onChange={() => setForm({ ...form, organization_ids: toggle(form.organization_ids, o.id) })} />{o.name}
                    </label>
                  ))}
                </div>
              </div>
            </div>
            {err ? <div className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}
            <div className="mt-5 flex justify-end gap-2">
              <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm" onClick={() => setEditing(null)}>Cancel</button>
              <button className="btn-primary" onClick={save}>Save</button>
            </div>
          </div>
        </div>
      ) : null}
      {prov.open ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setProv({ open: false, password: "", result: null })}>
          <div className="w-full max-w-2xl rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 className="mb-2 text-base font-semibold">Provision Employee Logins</h2>
            {!prov.result ? (
              <>
                <p className="mb-3 text-sm text-slate-500">
                  Creates a self-service login for every active employee who doesn't have one, linked to their record with the Employee role.
                </p>
                <label className="mb-1 block text-xs text-slate-500">Shared temporary password (optional)</label>
                <input className="input max-w-xs" type="text" value={prov.password}
                  onChange={(e) => setProv({ ...prov, password: e.target.value })} placeholder="leave blank to auto-generate per user" />
                <p className="mt-1 text-xs text-slate-400">Leave blank to generate a unique password per employee (you'll get a CSV to distribute). Employees can change it in My Self-Service → Security.</p>
                <div className="mt-5 flex justify-end gap-2">
                  <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm" onClick={() => setProv({ open: false, password: "", result: null })}>Cancel</button>
                  <button className="btn-primary" onClick={runProvision}>Run</button>
                </div>
              </>
            ) : (
              <>
                <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                  Created {prov.result.created} login(s), skipped {prov.result.skipped} (already had accounts).
                </div>
                {prov.result.credentials?.length ? (
                  <>
                    <div className="mb-2 flex items-center justify-between">
                      <span className="text-sm font-medium">New credentials ({prov.result.credentials.length})</span>
                      <button className="btn-primary !px-3 !py-1 text-xs" onClick={() => downloadCreds(prov.result.credentials)}>Download CSV</button>
                    </div>
                    <div className="max-h-64 overflow-y-auto rounded-lg border border-slate-200">
                      <table className="min-w-full text-xs"><thead className="bg-slate-50"><tr className="text-left text-slate-500"><th className="px-3 py-2">Name</th><th className="px-3 py-2">Login</th><th className="px-3 py-2">Temp Password</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                          {prov.result.credentials.map((c: any, i: number) => (
                            <tr key={i}><td className="px-3 py-1.5">{c.name}</td><td className="px-3 py-1.5 font-mono">{c.email}</td><td className="px-3 py-1.5 font-mono">{c.temp_password}</td></tr>
                          ))}
                        </tbody></table>
                    </div>
                    <p className="mt-2 text-xs text-amber-700">Download and distribute these now — the passwords are shown only once here.</p>
                  </>
                ) : <p className="text-sm text-slate-500">No new accounts were needed.</p>}
                <div className="mt-5 flex justify-end">
                  <button className="btn-primary" onClick={() => setProv({ open: false, password: "", result: null })}>Done</button>
                </div>
              </>
            )}
          </div>
        </div>
      ) : null}
    </AppShell>
  );
}
