import { useCallback, useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import { apiDownload, apiFetch } from "@/lib/api";

interface Sched {
  enabled: boolean; time: string; retain: number; last_run: string | null;
  dir: string; cron_command: string;
  backups: { name: string; size: number; date: string }[];
}

function human(bytes: number) {
  if (!bytes) return "0 B";
  const u = ["B", "KB", "MB", "GB"]; let i = 0, v = bytes;
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
  return `${v.toFixed(i ? 1 : 0)} ${u[i]}`;
}

export default function AdminBackupPage() {
  const [busy, setBusy] = useState<null | "full" | "db" | "now" | "save">(null);
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");
  const [sched, setSched] = useState<Sched | null>(null);
  const [form, setForm] = useState({ enabled: false, time: "00:00", retain: 14 });

  const load = useCallback(() => {
    apiFetch<Sched>("/admin/backup/schedule").then((s) => {
      setSched(s); setForm({ enabled: s.enabled, time: s.time, retain: s.retain });
    }).catch((e) => setErr(e.message));
  }, []);
  useEffect(load, [load]);

  const stamp = () => new Date().toISOString().slice(0, 10);

  async function download(scope: "full" | "db") {
    setErr(""); setMsg(scope === "full" ? "Building full backup… this can take a moment." : "Exporting database…"); setBusy(scope);
    try {
      await apiDownload(`/admin/backup?scope=${scope}`, `geek-hris-${scope === "db" ? "database" : "backup"}-${stamp()}.zip`);
      setMsg("Backup downloaded.");
    } catch (e: any) { setErr(e.message); setMsg(""); } finally { setBusy(null); }
  }
  async function saveSchedule() {
    setErr(""); setMsg(""); setBusy("save");
    try { const s = await apiFetch<Sched>("/admin/backup/schedule", { method: "POST", body: JSON.stringify(form) }); setSched(s); setMsg("Schedule saved."); }
    catch (e: any) { setErr(e.message); } finally { setBusy(null); }
  }
  async function runNow() {
    setErr(""); setMsg("Running a backup now…"); setBusy("now");
    try { const r = await apiFetch<any>("/admin/backup/run-now", { method: "POST" }); setMsg(`Backup created: ${r.file}`); load(); }
    catch (e: any) { setErr(e.message); setMsg(""); } finally { setBusy(null); }
  }
  function copyCron() { if (sched) navigator.clipboard?.writeText(sched.cron_command).then(() => setMsg("Cron command copied.")).catch(() => {}); }

  return (
    <AppShell>
      <div className="mb-4">
        <h1 className="text-lg font-semibold text-slate-900">Backup &amp; Migration</h1>
        <p className="text-sm text-slate-500">Download a complete snapshot, schedule automatic backups, or move to a new host.</p>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="card">
          <div className="mb-1 text-sm font-medium text-slate-700">Full backup &amp; migration package</div>
          <p className="mb-4 text-sm text-slate-500">One ZIP with your <strong>database</strong>, all <strong>files &amp; uploads</strong>, and a <strong>MIGRATION.txt</strong> guide — extract it on a new host to move.</p>
          <button className="btn-primary" onClick={() => download("full")} disabled={busy !== null}>{busy === "full" ? "Preparing…" : "Download full backup"}</button>
          <p className="mt-3 text-xs text-slate-400">Excludes <code>settings.php</code> and license (host-specific).</p>
        </div>
        <div className="card">
          <div className="mb-1 text-sm font-medium text-slate-700">Database only</div>
          <p className="mb-4 text-sm text-slate-500">A ZIP with just <strong>database.sql</strong> — a quick, small snapshot of all your data.</p>
          <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50" onClick={() => download("db")} disabled={busy !== null}>{busy === "db" ? "Preparing…" : "Download database"}</button>
        </div>
      </div>

      {/* Scheduled backups */}
      <div className="card mt-6">
        <div className="mb-3 flex items-center justify-between">
          <div className="text-sm font-medium text-slate-700">Scheduled automatic backup</div>
          {sched ? <span className={`badge ${sched.enabled ? "bg-emerald-100 text-emerald-700" : "bg-slate-200 text-slate-500"}`}>{sched.enabled ? "On" : "Off"}</span> : null}
        </div>
        <div className="flex flex-wrap items-end gap-4">
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.enabled} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} /> Enable daily backup</label>
          <div><label className="mb-1 block text-xs text-slate-500">Time of day</label>
            <input type="time" className="input max-w-[140px]" value={form.time} onChange={(e) => setForm({ ...form, time: e.target.value })} /></div>
          <div><label className="mb-1 block text-xs text-slate-500">Keep (days)</label>
            <input type="number" min="1" className="input max-w-[100px]" value={form.retain} onChange={(e) => setForm({ ...form, retain: Number(e.target.value) })} /></div>
          <button className="btn-primary" onClick={saveSchedule} disabled={busy !== null}>{busy === "save" ? "Saving…" : "Save schedule"}</button>
          <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50" onClick={runNow} disabled={busy !== null}>{busy === "now" ? "Running…" : "Run backup now"}</button>
        </div>
        <p className="mt-2 text-xs text-slate-500">Backs up once per day at or after the chosen time (e.g. <strong>00:00</strong> for midnight). Older backups beyond the retention are removed. {sched?.last_run ? <>Last run: <strong>{sched.last_run}</strong>.</> : "Not run yet."}</p>

        <div className="mt-4 rounded-lg bg-amber-50 px-3 py-3 text-xs text-amber-800">
          <div className="mb-1 font-semibold">One-time setup — add this cron job in cPanel → Cron Jobs (runs hourly; the app decides when to actually back up):</div>
          <div className="flex items-center gap-2">
            <code className="block flex-1 overflow-x-auto rounded bg-white/70 px-2 py-1 font-mono text-[11px] text-slate-700">{sched?.cron_command || "…"}</code>
            <button className="rounded border border-amber-300 px-2 py-1 hover:bg-amber-100" onClick={copyCron}>Copy</button>
          </div>
          <div className="mt-1">If that PHP path doesn't work on your host, use <code>php</code> or the path cPanel shows for your PHP version. Backups are stored privately at <code>{sched?.dir || "…/geek-hris-backups"}</code> (above your web root).</div>
        </div>
      </div>

      {/* Stored backups */}
      {sched && sched.backups.length ? (
        <div className="card mt-6 p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Stored backups ({sched.backups.length})</div>
          <table className="min-w-full text-sm">
            <tbody className="divide-y divide-slate-100">
              {sched.backups.map((b) => (
                <tr key={b.name}>
                  <td className="px-4 py-2 font-mono text-xs">{b.name}</td>
                  <td className="px-4 py-2 text-xs text-slate-500">{b.date}</td>
                  <td className="px-4 py-2 text-xs text-slate-500">{human(b.size)}</td>
                  <td className="px-4 py-2 text-right">
                    <button className="text-brand-700 hover:underline" onClick={() => apiDownload(`/admin/backup/file?name=${encodeURIComponent(b.name)}`, b.name)}>Download</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      <div className="mt-6 text-xs text-slate-400">Tip: keep backups off the server too — download important ones and store them somewhere safe.</div>
    </AppShell>
  );
}
