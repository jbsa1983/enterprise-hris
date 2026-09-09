
import { useCallback, useEffect, useMemo, useState } from "react";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";

interface Perm { code: string; description?: string; }
interface Role { id: number; name: string; description?: string; is_system: boolean; permissions: string[]; user_count: number; }

export default function AdminRolesPage() {
  const [roles, setRoles] = useState<Role[]>([]);
  const [perms, setPerms] = useState<Perm[]>([]);
  const [sel, setSel] = useState<Role | null>(null);
  const [checked, setChecked] = useState<Set<string>>(new Set());
  const [name, setName] = useState("");
  const [desc, setDesc] = useState("");
  const [creating, setCreating] = useState(false);
  const [msg, setMsg] = useState("");
  const [err, setErr] = useState("");

  const load = useCallback(() => {
    apiFetch<Role[]>("/admin/roles").then((r) => { setRoles(r); }).catch((e) => setErr(e.message));
    apiFetch<Perm[]>("/admin/permissions").then(setPerms).catch(() => {});
  }, []);
  useEffect(load, [load]);

  const grouped = useMemo(() => {
    const g: Record<string, Perm[]> = {};
    for (const p of perms) {
      const k = p.code.split(".")[0];
      (g[k] = g[k] || []).push(p);
    }
    return g;
  }, [perms]);

  function selectRole(r: Role) {
    setSel(r); setCreating(false); setName(r.name); setDesc(r.description || "");
    setChecked(new Set(r.permissions)); setErr(""); setMsg("");
  }
  function startCreate() {
    setSel(null); setCreating(true); setName(""); setDesc(""); setChecked(new Set()); setErr(""); setMsg("");
  }
  function toggle(code: string) {
    const n = new Set(checked);
    n.has(code) ? n.delete(code) : n.add(code);
    setChecked(n);
  }

  async function save() {
    setErr("");
    try {
      const permissions = Array.from(checked);
      if (creating) {
        await apiFetch("/admin/roles", { method: "POST", body: JSON.stringify({ name, description: desc, permissions }) });
        setMsg("Role created.");
      } else if (sel) {
        await apiFetch(`/admin/roles/${sel.id}`, { method: "PUT", body: JSON.stringify({ name, description: desc, permissions }) });
        setMsg("Role updated.");
      }
      setCreating(false); setSel(null); load();
    } catch (e: any) { setErr(e.message); }
  }

  async function del(r: Role) {
    setErr("");
    try { await apiFetch(`/admin/roles/${r.id}`, { method: "DELETE" }); setSel(null); load(); }
    catch (e: any) { setErr(e.message); }
  }

  const editing = creating || sel;

  return (
    <AppShell>
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Roles &amp; Scopes</h1>
          <p className="text-sm text-slate-500">A role is a named set of permissions. Assign roles to users to grant scoped access.</p>
        </div>
        <button className="btn-primary" onClick={startCreate}>+ New Role</button>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="card lg:col-span-1 p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Roles ({roles.length})</div>
          <div className="max-h-[28rem] overflow-y-auto">
            {roles.map((r) => (
              <button key={r.id} onClick={() => selectRole(r)}
                className={`block w-full border-b border-slate-50 px-4 py-2 text-left text-sm ${sel?.id === r.id ? "bg-brand-50" : "hover:bg-slate-50"}`}>
                <div className="flex justify-between">
                  <span className="font-medium">{r.name}</span>
                  <span className="text-xs text-slate-400">{r.permissions.length} perms · {r.user_count} users</span>
                </div>
                {r.is_system ? <span className="text-[10px] text-slate-400">system</span> : null}
              </button>
            ))}
          </div>
        </div>

        <div className="card lg:col-span-2">
          {editing ? (
            <>
              <div className="mb-3 grid grid-cols-2 gap-3">
                <div><label className="mb-1 block text-xs text-slate-500">Role name</label>
                  <input className="input" value={name} onChange={(e) => setName(e.target.value)} /></div>
                <div><label className="mb-1 block text-xs text-slate-500">Description</label>
                  <input className="input" value={desc} onChange={(e) => setDesc(e.target.value)} /></div>
              </div>
              <div className="mb-2 text-sm font-medium text-slate-700">Permission scopes ({checked.size} selected)</div>
              <div className="max-h-80 space-y-4 overflow-y-auto pr-1">
                {Object.entries(grouped).map(([group, list]) => (
                  <div key={group}>
                    <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">{group}</div>
                    <div className="grid grid-cols-1 gap-1 sm:grid-cols-2">
                      {list.map((p) => (
                        <label key={p.code} className="flex items-center gap-2 rounded px-1 py-0.5 text-sm hover:bg-slate-50">
                          <input type="checkbox" checked={checked.has(p.code)} onChange={() => toggle(p.code)} />
                          <span className="font-mono text-xs">{p.code}</span>
                          <span className="truncate text-xs text-slate-400">{p.description}</span>
                        </label>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
              <div className="mt-4 flex justify-between">
                {sel && !sel.is_system ? <button className="text-sm text-red-600 hover:underline" onClick={() => del(sel)}>Delete role</button> : <span />}
                <button className="btn-primary" onClick={save}>Save role</button>
              </div>
            </>
          ) : (
            <div className="flex h-full items-center justify-center text-sm text-slate-400">Select a role to edit its scopes, or create a new one.</div>
          )}
        </div>
      </div>
    </AppShell>
  );
}
