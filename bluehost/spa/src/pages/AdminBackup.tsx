import { useState } from "react";
import AppShell from "@/components/AppShell";
import { apiDownload } from "@/lib/api";

export default function AdminBackupPage() {
  const [busy, setBusy] = useState<null | "full" | "db">(null);
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const stamp = () => new Date().toISOString().slice(0, 10);

  async function download(scope: "full" | "db") {
    setErr(""); setMsg(scope === "full" ? "Building full backup… this can take a moment." : "Exporting database…");
    setBusy(scope);
    try {
      const name = scope === "full" ? `geek-hris-backup-${stamp()}.zip` : `geek-hris-database-${stamp()}.zip`;
      await apiDownload(`/admin/backup?scope=${scope}`, name);
      setMsg("Backup downloaded.");
    } catch (e: any) { setErr(e.message); setMsg(""); }
    finally { setBusy(null); }
  }

  return (
    <AppShell>
      <div className="mb-4">
        <h1 className="text-lg font-semibold text-slate-900">Backup &amp; Migration</h1>
        <p className="text-sm text-slate-500">Download a complete snapshot of this installation — for safekeeping or to move to a new host.</p>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="card">
          <div className="mb-1 text-sm font-medium text-slate-700">Full backup &amp; migration package</div>
          <p className="mb-4 text-sm text-slate-500">
            One ZIP with your <strong>database</strong>, all <strong>files &amp; uploads</strong>, and a <strong>MIGRATION.txt</strong> guide.
            Extract it on a new host, import the database, and set your DB details — same steps as a fresh install.
          </p>
          <button className="btn-primary" onClick={() => download("full")} disabled={busy !== null}>
            {busy === "full" ? "Preparing…" : "Download full backup"}
          </button>
          <p className="mt-3 text-xs text-slate-400">Excludes your <code>settings.php</code> and license file (secrets/host-specific) — you set those on the new host.</p>
        </div>

        <div className="card">
          <div className="mb-1 text-sm font-medium text-slate-700">Database only</div>
          <p className="mb-4 text-sm text-slate-500">
            A ZIP containing just <strong>database.sql</strong> — a quick, small snapshot of all your data.
            Ideal for frequent backups; restore by importing it into your database.
          </p>
          <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50" onClick={() => download("db")} disabled={busy !== null}>
            {busy === "db" ? "Preparing…" : "Download database"}
          </button>
        </div>
      </div>

      <div className="mt-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <strong>Tip:</strong> keep backups off the server (download and store them somewhere safe). On a large site, generating a full backup may take a little while — leave the tab open until the download starts.
      </div>
    </AppShell>
  );
}
