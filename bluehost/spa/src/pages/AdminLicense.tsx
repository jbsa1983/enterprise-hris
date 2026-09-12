import { useCallback, useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";

interface LicenseStatus {
  active: boolean; reason: string; enforced: boolean;
  customer: string | null; domain: string | null; edition: string | null; expires: string | null; host: string;
}

export default function AdminLicensePage() {
  const [st, setSt] = useState<LicenseStatus | null>(null);
  const [key, setKey] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const load = useCallback(() => { apiFetch<LicenseStatus>("/license").then(setSt).catch((e) => setErr(e.message)); }, []);
  useEffect(load, [load]);

  async function activate() {
    setErr(""); setMsg(""); setBusy(true);
    try {
      const s = await apiFetch<LicenseStatus>("/admin/license", { method: "POST", body: JSON.stringify({ key: key.trim() }) });
      setSt(s); setKey(""); setMsg(s.active ? "License activated." : "Saved, but not active — see status.");
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }
  async function deactivate() {
    if (!confirm("Remove the installed license from this installation?")) return;
    setErr(""); setMsg(""); setBusy(true);
    try { const s = await apiFetch<LicenseStatus>("/admin/license", { method: "DELETE" }); setSt(s); setMsg("License removed."); }
    catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }

  const Row = ({ k, v }: { k: string; v?: string | null }) => (
    <div className="flex justify-between gap-4 py-1.5 text-sm"><span className="text-slate-500">{k}</span><span className="font-medium text-slate-800">{v || "—"}</span></div>
  );

  return (
    <AppShell>
      <div className="mb-4">
        <h1 className="text-lg font-semibold text-slate-900">License</h1>
        <p className="text-sm text-slate-500">Activate this installation with the license key provided for your domain.</p>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="card">
          <div className="mb-3 flex items-center justify-between">
            <div className="text-sm font-medium text-slate-700">Status</div>
            {st ? (
              <span className={`badge ${st.active ? "bg-emerald-100 text-emerald-700" : "bg-amber-100 text-amber-700"}`}>
                {st.active ? "Active" : "Not activated"}
              </span>
            ) : null}
          </div>
          {st ? (
            <div className="divide-y divide-slate-100">
              <Row k="Licensed to" v={st.customer} />
              <Row k="Domain" v={st.domain} />
              <Row k="This site" v={st.host} />
              <Row k="Edition" v={st.edition} />
              <Row k="Expires" v={st.expires || "Never"} />
              <Row k="Detail" v={st.reason} />
              <Row k="Enforcement" v={st.enforced ? "On (locks app until activated)" : "Off"} />
            </div>
          ) : <p className="text-sm text-slate-400">Loading…</p>}
          {st?.domain && !st.active && st.reason?.includes("licensed to") ? (
            <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
              The key's domain doesn't match this site ({st.host}). Ask the vendor for a key issued to {st.host}.
            </p>
          ) : null}
        </div>

        <div className="card">
          <div className="mb-2 text-sm font-medium text-slate-700">Activate</div>
          <label className="mb-1 block text-xs text-slate-500">Paste your license key</label>
          <textarea className="input font-mono text-xs" rows={5} value={key} onChange={(e) => setKey(e.target.value)}
            placeholder="GEEKHRIS.xxxxxxxx.yyyyyyyy" />
          <div className="mt-3 flex gap-2">
            <button className="btn-primary" onClick={activate} disabled={busy || !key.trim()}>{busy ? "Working…" : "Activate"}</button>
            {st?.customer ? <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50" onClick={deactivate} disabled={busy}>Remove license</button> : null}
          </div>
          <p className="mt-2 text-xs text-slate-400">The key is issued for a specific domain and may include an expiry date. It only validates on the domain it was made for.</p>
        </div>
      </div>
    </AppShell>
  );
}
