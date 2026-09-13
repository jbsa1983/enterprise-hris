import { useCallback, useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";

interface TgStatus { configured: boolean; bot_username: string | null; webhook_set: boolean; webhook_error?: string | null; webhook_url?: string | null; }
interface DigestCfg { enabled: boolean; time: string; last_run: string | null; cron_command: string; }
interface MailCfg { transport: "mail" | "smtp"; host: string; port: number; secure: "tls" | "ssl" | "none"; username: string; from_email: string; from_name: string; has_password: boolean; }

export default function AdminNotificationsPage() {
  const [st, setSt] = useState<TgStatus | null>(null);
  const [token, setToken] = useState("");
  const [dg, setDg] = useState<DigestCfg | null>(null);
  const [dgForm, setDgForm] = useState({ enabled: false, time: "08:00" });
  const [mail, setMail] = useState<MailCfg | null>(null);
  const [mf, setMf] = useState<MailCfg>({ transport: "mail", host: "", port: 587, secure: "tls", username: "", from_email: "", from_name: "GEEK HRIS", has_password: false });
  const [mailPw, setMailPw] = useState("");
  const [testTo, setTestTo] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const load = useCallback(() => {
    apiFetch<TgStatus>("/admin/telegram").then(setSt).catch((e) => setErr(e.message));
    apiFetch<DigestCfg>("/admin/digest").then((d) => { setDg(d); setDgForm({ enabled: d.enabled, time: d.time }); }).catch(() => {});
    apiFetch<MailCfg>("/admin/mail").then((m) => { setMail(m); setMf(m); }).catch(() => {});
  }, []);
  useEffect(load, [load]);

  async function saveMail() {
    setErr(""); setMsg(""); setBusy(true);
    try {
      const payload: any = { transport: mf.transport, host: mf.host, port: mf.port, secure: mf.secure, username: mf.username, from_email: mf.from_email, from_name: mf.from_name };
      if (mailPw !== "") payload.password = mailPw;
      const m = await apiFetch<MailCfg>("/admin/mail", { method: "POST", body: JSON.stringify(payload) });
      setMail(m); setMf(m); setMailPw(""); setMsg("Email settings saved.");
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }
  async function testMail() {
    setErr(""); setMsg("Sending test email…"); setBusy(true);
    try {
      const payload: any = { transport: mf.transport, host: mf.host, port: mf.port, secure: mf.secure, username: mf.username, from_email: mf.from_email, from_name: mf.from_name, to: testTo.trim() };
      if (mailPw !== "") payload.password = mailPw;
      const r = await apiFetch<any>("/admin/mail/test", { method: "POST", body: JSON.stringify(payload) });
      setMail((m) => (m ? { ...m, ...mf, has_password: m.has_password || mailPw !== "" } : m)); setMailPw("");
      setMsg(`Test email sent to ${r.to}. Check the inbox (and spam folder).`);
    } catch (e: any) { setErr(e.message); setMsg(""); } finally { setBusy(false); }
  }

  async function saveDigest() {
    setErr(""); setMsg(""); setBusy(true);
    try { const d = await apiFetch<DigestCfg>("/admin/digest", { method: "POST", body: JSON.stringify(dgForm) }); setDg(d); setMsg("Digest schedule saved."); }
    catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }
  async function sendDigestNow() {
    setErr(""); setMsg("Sending digest…"); setBusy(true);
    try { const r = await apiFetch<any>("/admin/digest/run-now", { method: "POST" }); setMsg(r.result || "Digest sent."); }
    catch (e: any) { setErr(e.message); setMsg(""); } finally { setBusy(false); }
  }
  function copyCron() { if (dg) navigator.clipboard?.writeText(dg.cron_command).then(() => setMsg("Cron command copied.")).catch(() => {}); }

  async function save() {
    setErr(""); setMsg(""); setBusy(true);
    try {
      const s = await apiFetch<TgStatus>("/admin/telegram", { method: "POST", body: JSON.stringify({ bot_token: token.trim() }) });
      setSt(s); setToken(""); setMsg(s.webhook_set ? "Connected to Telegram." : "Token saved, but the webhook couldn't be registered — see the error below.");
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }
  async function retryWebhook() {
    setErr(""); setMsg(""); setBusy(true);
    try {
      const s = await apiFetch<TgStatus>("/admin/telegram/webhook", { method: "POST" });
      setSt(s); setMsg(s.webhook_set ? "Webhook registered ✔" : "Still couldn't register the webhook — see the error.");
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }

  const smtp = mf.transport === "smtp";

  return (
    <AppShell>
      <div className="mb-4">
        <h1 className="text-lg font-semibold text-slate-900">Notifications</h1>
        <p className="text-sm text-slate-500">Email delivery, phone alerts via Telegram, and the daily digest of pending approvals.</p>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      {/* Email delivery (SMTP) */}
      <div className="card mb-6">
        <div className="mb-3 flex items-center justify-between">
          <div className="text-sm font-medium text-slate-700">Email delivery</div>
          {mail ? <span className={`badge ${smtp && mail.host ? "bg-emerald-100 text-emerald-700" : "bg-slate-200 text-slate-500"}`}>{smtp && mail.host ? "SMTP configured" : "Server default"}</span> : null}
        </div>
        <p className="mb-4 text-sm text-slate-500">
          Used for password-reset links, approval alerts and the daily digest. For reliable delivery, use your own SMTP mailbox
          (for example <span className="font-mono">notification@hris.exssi.com</span>). The password is stored on your server only — it's never shown here again once saved.
        </p>

        <div className="mb-4 flex flex-wrap gap-4 text-sm">
          <label className="flex items-center gap-2"><input type="radio" name="transport" checked={!smtp} onChange={() => setMf({ ...mf, transport: "mail" })} /> Server default (PHP mail)</label>
          <label className="flex items-center gap-2"><input type="radio" name="transport" checked={smtp} onChange={() => setMf({ ...mf, transport: "smtp" })} /> SMTP mailbox (recommended)</label>
        </div>

        {smtp ? (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-xs text-slate-500">SMTP host</label>
              <input className="input" value={mf.host} onChange={(e) => setMf({ ...mf, host: e.target.value })} placeholder="mail.hris.exssi.com" />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1 block text-xs text-slate-500">Port</label>
                <input className="input" type="number" value={mf.port} onChange={(e) => setMf({ ...mf, port: parseInt(e.target.value || "0", 10) })} placeholder="587" />
              </div>
              <div>
                <label className="mb-1 block text-xs text-slate-500">Encryption</label>
                <select className="input" value={mf.secure} onChange={(e) => setMf({ ...mf, secure: e.target.value as MailCfg["secure"] })}>
                  <option value="tls">STARTTLS (587)</option>
                  <option value="ssl">SSL / TLS (465)</option>
                  <option value="none">None (25)</option>
                </select>
              </div>
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-500">Username (full email)</label>
              <input className="input" value={mf.username} onChange={(e) => setMf({ ...mf, username: e.target.value })} placeholder="notification@hris.exssi.com" autoComplete="off" />
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-500">Password {mail?.has_password ? <span className="text-slate-400">(leave blank to keep current)</span> : null}</label>
              <input className="input" type="password" value={mailPw} onChange={(e) => setMailPw(e.target.value)} placeholder={mail?.has_password ? "••••••••" : "Mailbox password"} autoComplete="new-password" />
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-500">From address</label>
              <input className="input" value={mf.from_email} onChange={(e) => setMf({ ...mf, from_email: e.target.value })} placeholder="notification@hris.exssi.com" />
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-500">From name</label>
              <input className="input" value={mf.from_name} onChange={(e) => setMf({ ...mf, from_name: e.target.value })} placeholder="GEEK HRIS" />
            </div>
          </div>
        ) : (
          <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
            Emails will be sent through the hosting server's built-in mailer. This often works, but messages may be marked as spam or blocked. Switch to SMTP for reliable delivery.
          </p>
        )}

        <div className="mt-4 flex flex-wrap items-end gap-3">
          <button className="btn-primary" onClick={saveMail} disabled={busy}>{busy ? "Saving…" : "Save email settings"}</button>
          <div>
            <label className="mb-1 block text-xs text-slate-500">Send a test to</label>
            <input className="input max-w-[220px]" value={testTo} onChange={(e) => setTestTo(e.target.value)} placeholder="you@example.com" />
          </div>
          <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50" onClick={testMail} disabled={busy}>Send test</button>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="card">
          <div className="mb-3 flex items-center justify-between">
            <div className="text-sm font-medium text-slate-700">Telegram bot connection</div>
            {st ? <span className={`badge ${st.configured && st.webhook_set ? "bg-emerald-100 text-emerald-700" : "bg-amber-100 text-amber-700"}`}>
              {st.configured ? (st.webhook_set ? "Connected" : "Token set · webhook off") : "Not set up"}
            </span> : null}
          </div>
          {st?.configured ? (
            <p className="mb-3 text-sm text-slate-600">Bot: <span className="font-mono">@{st.bot_username}</span></p>
          ) : null}
          {st?.configured && !st.webhook_set ? (
            <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
              Webhook not registered — messages from staff won't reach the app.{st.webhook_error ? <> Telegram said: <b>{st.webhook_error}</b></> : null}
              <div className="mt-2"><button className="rounded border border-red-300 px-2 py-1 font-medium hover:bg-red-100" onClick={retryWebhook} disabled={busy}>Re-register webhook</button></div>
            </div>
          ) : null}
          {st?.configured && st.webhook_set ? (
            <div className="mb-3 flex items-center gap-3">
              <span className="badge bg-emerald-100 text-emerald-700">Webhook active</span>
              <button className="text-xs text-slate-500 hover:underline" onClick={retryWebhook} disabled={busy}>re-register</button>
            </div>
          ) : null}
          <label className="mb-1 block text-xs text-slate-500">Bot token (from @BotFather)</label>
          <input className="input font-mono text-xs" value={token} onChange={(e) => setToken(e.target.value)} placeholder="123456789:AA..." />
          <div className="mt-3">
            <button className="btn-primary" onClick={save} disabled={busy || !token.trim()}>{busy ? "Connecting…" : st?.configured ? "Update token" : "Connect bot"}</button>
          </div>
          <p className="mt-2 text-xs text-slate-400">Saving verifies the token and registers the webhook automatically (needs HTTPS).</p>
        </div>

        <div className="card">
          <div className="mb-2 text-sm font-medium text-slate-700">How to set up Telegram (2 minutes)</div>
          <ol className="ml-4 list-decimal space-y-2 text-sm text-slate-600">
            <li>In Telegram, open <span className="font-mono">@BotFather</span> and send <span className="font-mono">/newbot</span>.</li>
            <li>Give it a name and a username (must end in <span className="font-mono">bot</span>, e.g. <span className="font-mono">geekhris_bot</span>).</li>
            <li>BotFather replies with a <strong>token</strong> like <span className="font-mono">123456789:AA…</span> — paste it here and <strong>Connect</strong>.</li>
            <li>Tell your staff to open <strong>My Self-Service → Security → Connect Telegram</strong> and tap the link once.</li>
          </ol>
          <p className="mt-3 text-xs text-slate-400">It's free. Only people who connect will receive Telegram alerts; everyone still gets email + the in-app bell.</p>
        </div>
      </div>

      {/* Daily digest */}
      <div className="card mt-6">
        <div className="mb-3 flex items-center justify-between">
          <div className="text-sm font-medium text-slate-700">Daily digest of pending approvals</div>
          {dg ? <span className={`badge ${dg.enabled ? "bg-emerald-100 text-emerald-700" : "bg-slate-200 text-slate-500"}`}>{dg.enabled ? "On" : "Off"}</span> : null}
        </div>
        <p className="mb-3 text-sm text-slate-500">Once a day, each org's approvers get an email + Telegram summary of everything still pending — only when there's something to act on.</p>
        <div className="flex flex-wrap items-end gap-4">
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={dgForm.enabled} onChange={(e) => setDgForm({ ...dgForm, enabled: e.target.checked })} /> Enable daily digest</label>
          <div><label className="mb-1 block text-xs text-slate-500">Send time</label>
            <input type="time" className="input max-w-[140px]" value={dgForm.time} onChange={(e) => setDgForm({ ...dgForm, time: e.target.value })} /></div>
          <button className="btn-primary" onClick={saveDigest} disabled={busy}>{busy ? "Saving…" : "Save"}</button>
          <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50" onClick={sendDigestNow} disabled={busy}>Send now (test)</button>
        </div>
        <p className="mt-2 text-xs text-slate-500">{dg?.last_run ? <>Last sent: <strong>{dg.last_run}</strong>.</> : "Not sent yet."}</p>
        <div className="mt-3 rounded-lg bg-amber-50 px-3 py-3 text-xs text-amber-800">
          <div className="mb-1 font-semibold">One-time setup — add this cron job in cPanel → Cron Jobs (runs hourly; the app decides when to send):</div>
          <div className="flex items-center gap-2">
            <code className="block flex-1 overflow-x-auto rounded bg-white/70 px-2 py-1 font-mono text-[11px] text-slate-700">{dg?.cron_command || "…"}</code>
            <button className="rounded border border-amber-300 px-2 py-1 hover:bg-amber-100" onClick={copyCron}>Copy</button>
          </div>
        </div>
      </div>
    </AppShell>
  );
}
