import { useCallback, useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";

interface TgStatus { configured: boolean; bot_username: string | null; webhook_set: boolean; }

export default function AdminNotificationsPage() {
  const [st, setSt] = useState<TgStatus | null>(null);
  const [token, setToken] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const load = useCallback(() => { apiFetch<TgStatus>("/admin/telegram").then(setSt).catch((e) => setErr(e.message)); }, []);
  useEffect(load, [load]);

  async function save() {
    setErr(""); setMsg(""); setBusy(true);
    try {
      const s = await apiFetch<TgStatus>("/admin/telegram", { method: "POST", body: JSON.stringify({ bot_token: token.trim() }) });
      setSt(s); setToken(""); setMsg(s.webhook_set ? "Connected to Telegram." : "Saved, but the webhook couldn't be set — check that your site is on HTTPS.");
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }

  return (
    <AppShell>
      <div className="mb-4">
        <h1 className="text-lg font-semibold text-slate-900">Notifications — Telegram</h1>
        <p className="text-sm text-slate-500">Send approval alerts to staff phones via a free Telegram bot.</p>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="card">
          <div className="mb-3 flex items-center justify-between">
            <div className="text-sm font-medium text-slate-700">Bot connection</div>
            {st ? <span className={`badge ${st.configured && st.webhook_set ? "bg-emerald-100 text-emerald-700" : "bg-amber-100 text-amber-700"}`}>
              {st.configured ? (st.webhook_set ? "Connected" : "Token set · webhook off") : "Not set up"}
            </span> : null}
          </div>
          {st?.configured ? (
            <p className="mb-3 text-sm text-slate-600">Bot: <span className="font-mono">@{st.bot_username}</span></p>
          ) : null}
          <label className="mb-1 block text-xs text-slate-500">Bot token (from @BotFather)</label>
          <input className="input font-mono text-xs" value={token} onChange={(e) => setToken(e.target.value)} placeholder="123456789:AA..." />
          <div className="mt-3">
            <button className="btn-primary" onClick={save} disabled={busy || !token.trim()}>{busy ? "Connecting…" : st?.configured ? "Update token" : "Connect bot"}</button>
          </div>
          <p className="mt-2 text-xs text-slate-400">Saving verifies the token and registers the webhook automatically.</p>
        </div>

        <div className="card">
          <div className="mb-2 text-sm font-medium text-slate-700">How to set it up (2 minutes)</div>
          <ol className="ml-4 list-decimal space-y-2 text-sm text-slate-600">
            <li>In Telegram, open <span className="font-mono">@BotFather</span> and send <span className="font-mono">/newbot</span>.</li>
            <li>Give it a name and a username (must end in <span className="font-mono">bot</span>, e.g. <span className="font-mono">geekhris_bot</span>).</li>
            <li>BotFather replies with a <strong>token</strong> like <span className="font-mono">123456789:AA…</span> — paste it here and <strong>Connect</strong>.</li>
            <li>Tell your staff to open <strong>My Self-Service → Security → Connect Telegram</strong> and tap the link once.</li>
          </ol>
          <p className="mt-3 text-xs text-slate-400">It's free. Only people who connect will receive Telegram alerts; everyone still gets email + the in-app bell.</p>
        </div>
      </div>
    </AppShell>
  );
}
