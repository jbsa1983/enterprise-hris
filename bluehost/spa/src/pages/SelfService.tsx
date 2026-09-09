
import { useCallback, useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import { apiFetch, apiOpen } from "@/lib/api";
import { peso } from "@/lib/format";

const TABS = ["Profile", "Payslips", "Leave", "Attendance", "Loans", "Contributions", "Security"];

export default function SelfServicePage() {
  const [tab, setTab] = useState("Profile");
  const [profile, setProfile] = useState<any>(null);
  const [payslips, setPayslips] = useState<any[]>([]);
  const [leave, setLeave] = useState<any[]>([]);
  const [att, setAtt] = useState<any[]>([]);
  const [loans, setLoans] = useState<any[]>([]);
  const [contrib, setContrib] = useState<any[]>([]);
  const [msg, setMsg] = useState("");
  const [err, setErr] = useState("");
  const [pw, setPw] = useState({ current_password: "", new_password: "" });

  const load = useCallback(() => {
    apiFetch("/me/profile").then(setProfile).catch((e) => setErr(e.message));
    apiFetch("/me/available-payslips").then(setPayslips).catch(() => {});
    apiFetch("/me/leave").then(setLeave).catch(() => {});
    apiFetch("/me/attendance").then(setAtt).catch(() => {});
    apiFetch("/me/loans").then(setLoans).catch(() => {});
    apiFetch("/me/contributions").then(setContrib).catch(() => {});
  }, []);
  useEffect(load, [load]);

  async function generateAndView(runId: number, existingUuid: string | null) {
    setErr(""); setMsg("");
    try {
      let uuid = existingUuid;
      if (!uuid) {
        const r = await apiFetch<any>("/me/payslips/generate", { method: "POST", body: JSON.stringify({ run_id: runId }) });
        uuid = r.uuid; load();
      }
      apiOpen(`/payslips/${uuid}/pdf`);
    } catch (e: any) { setErr(e.message); }
  }

  async function changePassword() {
    setErr(""); setMsg("");
    try {
      await apiFetch("/me/password", { method: "POST", body: JSON.stringify(pw) });
      setMsg("Password changed."); setPw({ current_password: "", new_password: "" });
    } catch (e: any) { setErr(e.message); }
  }

  const Section = ({ title, children }: { title: string; children: React.ReactNode }) => (
    <div className="card p-0">
      <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">{title}</div>
      <div className="p-4">{children}</div>
    </div>
  );

  return (
    <AppShell>
      <h1 className="mb-1 text-lg font-semibold text-slate-900">My Self-Service</h1>
      <p className="mb-4 text-sm text-slate-500">Your own records only. {profile ? `Signed in as ${profile.name}.` : ""}</p>

      <div className="mb-4 flex flex-wrap gap-1 border-b border-slate-200">
        {TABS.map((t) => (
          <button key={t} onClick={() => setTab(t)}
            className={`rounded-t-lg px-4 py-2 text-sm ${tab === t ? "border-b-2 border-geek-blue font-medium text-geek-blue" : "text-slate-500 hover:text-slate-700"}`}>
            {t}
          </button>
        ))}
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      {tab === "Profile" && profile ? (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <Section title="Personal">
            <dl className="space-y-2 text-sm">
              <Row k="Name" v={profile.name} /><Row k="Email" v={profile.email} />
              <Row k="Mobile" v={profile.mobile} /><Row k="Address" v={profile.address} />
              <Row k="Bank" v={`${profile.bank?.name || "—"} ${profile.bank?.account_masked || ""}`} />
            </dl>
          </Section>
          <Section title="Government IDs">
            <dl className="space-y-2 text-sm">
              <Row k="TIN" v={profile.government_ids?.tin} /><Row k="SSS" v={profile.government_ids?.sss} />
              <Row k="PhilHealth" v={profile.government_ids?.philhealth} /><Row k="Pag-IBIG" v={profile.government_ids?.pagibig} />
            </dl>
          </Section>
          <Section title="Employment">
            <table className="min-w-full text-sm"><tbody className="divide-y divide-slate-100">
              {profile.engagements?.map((e: any) => (
                <tr key={e.id}><td className="py-2 font-mono text-xs">{e.employee_number}</td><td className="py-2">{e.organization}</td><td className="py-2">{e.engagement_type}</td><td className="py-2 text-right">{peso(e.base_rate)}</td></tr>
              ))}
            </tbody></table>
          </Section>
        </div>
      ) : null}

      {tab === "Payslips" ? (
        <Section title={`Payslips (${payslips.length})`}>
          <table className="min-w-full text-sm"><tbody className="divide-y divide-slate-100">
            {payslips.map((p) => (
              <tr key={p.run_id}>
                <td className="py-2">{p.period}</td><td className="py-2 text-xs text-slate-500">{p.reference}</td>
                <td className="py-2 text-right">{peso(p.net_pay)}</td>
                <td className="py-2 text-right">
                  <button className="btn-primary !px-3 !py-1 text-xs" onClick={() => generateAndView(p.run_id, p.payslip_uuid)}>
                    {p.payslip_uuid ? "View / Download PDF" : "Generate PDF"}
                  </button>
                </td>
              </tr>
            ))}
            {payslips.length === 0 ? <tr><td className="py-6 text-center text-slate-400">No finalized payslips yet.</td></tr> : null}
          </tbody></table>
        </Section>
      ) : null}

      {tab === "Leave" ? (
        <Section title={`Leave (${leave.length})`}>
          <table className="min-w-full text-sm"><tbody className="divide-y divide-slate-100">
            {leave.map((l) => (<tr key={l.id}><td className="py-2">{l.leave_type}</td><td className="py-2 text-xs text-slate-500">{l.date_from} → {l.date_to}</td><td className="py-2">{l.days}d</td><td className="py-2"><span className="badge bg-slate-100 text-slate-600">{l.status}</span></td></tr>))}
            {leave.length === 0 ? <tr><td className="py-6 text-center text-slate-400">No leave records.</td></tr> : null}
          </tbody></table>
        </Section>
      ) : null}

      {tab === "Attendance" ? (
        <Section title={`Recent Attendance (${att.length})`}>
          <table className="min-w-full text-sm"><thead><tr className="text-left text-xs uppercase text-slate-400"><th className="py-1">Date</th><th>Hours</th><th>Late</th><th>OT</th><th>Status</th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {att.map((a, i) => (<tr key={i}><td className="py-2">{a.log_date}</td><td>{a.hours_worked}</td><td>{a.late_minutes}m</td><td>{a.overtime_hours}h</td><td>{a.status}</td></tr>))}
            {att.length === 0 ? <tr><td className="py-6 text-center text-slate-400">No attendance logs.</td></tr> : null}
          </tbody></table>
        </Section>
      ) : null}

      {tab === "Loans" ? (
        <Section title={`Loans & Advances (${loans.length})`}>
          <table className="min-w-full text-sm"><tbody className="divide-y divide-slate-100">
            {loans.map((l, i) => (<tr key={i}><td className="py-2">{l.description || l.type}</td><td className="py-2 text-right">{peso(l.principal)}</td><td className="py-2 text-right font-medium">{peso(l.balance)} left</td><td className="py-2"><span className="badge bg-slate-100 text-slate-600">{l.status}</span></td></tr>))}
            {loans.length === 0 ? <tr><td className="py-6 text-center text-slate-400">No loans or advances.</td></tr> : null}
          </tbody></table>
        </Section>
      ) : null}

      {tab === "Contributions" ? (
        <Section title="Government Contributions">
          <table className="min-w-full text-sm"><thead><tr className="text-left text-xs uppercase text-slate-400"><th className="py-1">Period</th><th className="text-right">SSS</th><th className="text-right">PhilHealth</th><th className="text-right">Pag-IBIG</th><th className="text-right">W/Tax</th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {contrib.map((c, i) => (<tr key={i}><td className="py-2">{c.period}</td><td className="py-2 text-right">{peso(c.sss)}</td><td className="py-2 text-right">{peso(c.philhealth)}</td><td className="py-2 text-right">{peso(c.pagibig)}</td><td className="py-2 text-right">{peso(c.withholding_tax)}</td></tr>))}
            {contrib.length === 0 ? <tr><td className="py-6 text-center text-slate-400">No contributions recorded.</td></tr> : null}
          </tbody></table>
        </Section>
      ) : null}

      {tab === "Security" ? (
        <Section title="Change Password">
          <div className="max-w-sm space-y-3">
            <div><label className="mb-1 block text-xs text-slate-500">Current password</label>
              <input className="input" type="password" value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} /></div>
            <div><label className="mb-1 block text-xs text-slate-500">New password (min 8)</label>
              <input className="input" type="password" value={pw.new_password} onChange={(e) => setPw({ ...pw, new_password: e.target.value })} /></div>
            <button className="btn-primary" onClick={changePassword}>Update password</button>
          </div>
        </Section>
      ) : null}
    </AppShell>
  );
}

function Row({ k, v }: { k: string; v?: string }) {
  return (
    <div className="flex justify-between"><dt className="text-slate-500">{k}</dt><dd className="font-medium text-slate-800">{v || "—"}</dd></div>
  );
}
