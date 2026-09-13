import { Fragment, useCallback, useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";

interface Row {
  id: number; user_email: string | null; organization_id: number | null; organization_name: string | null;
  action: string; entity: string | null; entity_id: string | null;
  before_json: string | null; after_json: string | null; ip_address: string | null; created_at: string;
}
interface Org { id: number; name: string; }
const PAGE = 50;

export default function AdminAuditPage() {
  const [rows, setRows] = useState<Row[]>([]);
  const [total, setTotal] = useState(0);
  const [offset, setOffset] = useState(0);
  const [actions, setActions] = useState<string[]>([]);
  const [orgs, setOrgs] = useState<Org[]>([]);
  const [f, setF] = useState({ q: "", action: "", org: "", from: "", to: "" });
  const [expanded, setExpanded] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");
  const [isSuper, setIsSuper] = useState(false);

  const load = useCallback((off: number) => {
    setBusy(true); setErr("");
    const qs = new URLSearchParams({ limit: String(PAGE), offset: String(off) });
    if (f.q) qs.set("q", f.q);
    if (f.action) qs.set("action", f.action);
    if (f.org) qs.set("org", f.org);
    if (f.from) qs.set("from", f.from);
    if (f.to) qs.set("to", f.to);
    apiFetch<{ rows: Row[]; total: number }>(`/admin/audit?${qs.toString()}`)
      .then((d) => { setRows(d.rows); setTotal(d.total); setOffset(off); })
      .catch((e) => setErr(e.message))
      .finally(() => setBusy(false));
  }, [f]);

  useEffect(() => {
    apiFetch<string[]>("/admin/audit/actions").then(setActions).catch(() => {});
    apiFetch<Org[]>("/admin/organizations").then(setOrgs).catch(() => {}); // optional; needs admin
    apiFetch<any>("/auth/me").then((u) => setIsSuper(!!u.is_superadmin)).catch(() => {});
  }, []);
  useEffect(() => { load(0); }, [load]);

  async function clearLogs() {
    const msg = f.to
      ? `Delete all audit entries on or before ${f.to}? This cannot be undone.`
      : "Delete the ENTIRE audit trail? This cannot be undone.";
    if (!window.confirm(msg)) return;
    setBusy(true); setErr("");
    try {
      const r = await apiFetch<{ deleted: number }>("/admin/audit", { method: "DELETE", body: JSON.stringify({ before: f.to || "" }) });
      window.alert(`${r.deleted.toLocaleString()} entr${r.deleted === 1 ? "y" : "ies"} deleted.`);
      apiFetch<string[]>("/admin/audit/actions").then(setActions).catch(() => {});
      load(0);
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }

  const pretty = (s: string | null) => { if (!s) return null; try { return JSON.stringify(JSON.parse(s), null, 2); } catch { return s; } };
  const page = Math.floor(offset / PAGE) + 1;
  const pages = Math.max(1, Math.ceil(total / PAGE));

  return (
    <AppShell>
      <div className="mb-4 flex items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Audit trail</h1>
          <p className="text-sm text-slate-500">Every recorded action — logins, approvals, edits, uploads — with who, when, and from where.</p>
        </div>
        {isSuper ? (
          <button className="whitespace-nowrap rounded-lg border border-red-300 px-3 py-2 text-sm text-red-700 hover:bg-red-50 disabled:opacity-40"
            onClick={clearLogs} disabled={busy}>
            {f.to ? `Clear up to ${f.to}` : "Clear all logs"}
          </button>
        ) : null}
      </div>
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="card mb-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <div>
            <label className="mb-1 block text-xs text-slate-500">Search (email / entity)</label>
            <input className="input" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} placeholder="e.g. maria@…" />
          </div>
          <div>
            <label className="mb-1 block text-xs text-slate-500">Action</label>
            <select className="input" value={f.action} onChange={(e) => setF({ ...f, action: e.target.value })}>
              <option value="">All actions</option>
              {actions.map((a) => <option key={a} value={a}>{a}</option>)}
            </select>
          </div>
          {orgs.length > 0 ? (
            <div>
              <label className="mb-1 block text-xs text-slate-500">Organization</label>
              <select className="input" value={f.org} onChange={(e) => setF({ ...f, org: e.target.value })}>
                <option value="">All organizations</option>
                {orgs.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
              </select>
            </div>
          ) : null}
          <div>
            <label className="mb-1 block text-xs text-slate-500">From</label>
            <input type="date" className="input" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
          </div>
          <div>
            <label className="mb-1 block text-xs text-slate-500">To</label>
            <input type="date" className="input" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
          </div>
        </div>
      </div>

      <div className="card overflow-x-auto">
        <div className="mb-2 flex items-center justify-between text-sm text-slate-500">
          <span>{busy ? "Loading…" : `${total.toLocaleString()} event${total === 1 ? "" : "s"}`}</span>
          <span>Page {page} of {pages}</span>
        </div>
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b text-left text-xs uppercase tracking-wide text-slate-400">
              <th className="py-2 pr-3">When</th>
              <th className="py-2 pr-3">User</th>
              <th className="py-2 pr-3">Action</th>
              <th className="py-2 pr-3">Entity</th>
              <th className="py-2 pr-3">Organization</th>
              <th className="py-2 pr-3">IP</th>
              <th className="py-2"></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => {
              const b = pretty(r.before_json), a = pretty(r.after_json);
              const hasDetail = !!(b || a);
              return (
                <Fragment key={r.id}>
                  <tr className="border-b border-slate-100 align-top">
                    <td className="whitespace-nowrap py-2 pr-3 font-mono text-xs text-slate-500">{r.created_at}</td>
                    <td className="py-2 pr-3">{r.user_email || <span className="text-slate-400">—</span>}</td>
                    <td className="py-2 pr-3"><span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700">{r.action}</span></td>
                    <td className="py-2 pr-3 text-slate-600">{r.entity ? `${r.entity}${r.entity_id ? " #" + r.entity_id : ""}` : <span className="text-slate-400">—</span>}</td>
                    <td className="py-2 pr-3 text-slate-600">{r.organization_name || <span className="text-slate-400">—</span>}</td>
                    <td className="whitespace-nowrap py-2 pr-3 font-mono text-xs text-slate-400">{r.ip_address || "—"}</td>
                    <td className="py-2 text-right">{hasDetail ? (
                      <button className="text-xs text-blue-600 hover:underline" onClick={() => setExpanded(expanded === r.id ? null : r.id)}>
                        {expanded === r.id ? "hide" : "details"}
                      </button>
                    ) : null}</td>
                  </tr>
                  {expanded === r.id && hasDetail ? (
                    <tr className="border-b border-slate-100 bg-slate-50">
                      <td colSpan={7} className="px-3 py-2">
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                          {b ? <div><div className="mb-1 text-xs font-semibold text-slate-500">Before</div><pre className="overflow-x-auto rounded bg-white p-2 text-[11px] text-slate-700">{b}</pre></div> : null}
                          {a ? <div><div className="mb-1 text-xs font-semibold text-slate-500">After</div><pre className="overflow-x-auto rounded bg-white p-2 text-[11px] text-slate-700">{a}</pre></div> : null}
                        </div>
                      </td>
                    </tr>
                  ) : null}
                </Fragment>
              );
            })}
            {!busy && rows.length === 0 ? (
              <tr><td colSpan={7} className="py-8 text-center text-slate-400">No events match these filters.</td></tr>
            ) : null}
          </tbody>
        </table>
        <div className="mt-3 flex items-center justify-end gap-2">
          <button className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-40" disabled={busy || offset === 0} onClick={() => load(Math.max(0, offset - PAGE))}>← Newer</button>
          <button className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-40" disabled={busy || offset + PAGE >= total} onClick={() => load(offset + PAGE)}>Older →</button>
        </div>
      </div>
    </AppShell>
  );
}
