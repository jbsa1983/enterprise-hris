
import { useCallback, useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import { apiFetch, apiOpen } from "@/lib/api";
import { peso } from "@/lib/format";

const TABS = ["Profile", "Payslips", "13th Month", "Leave", "Attendance", "Loans", "Contributions", "Security"];
const LOAN_TYPES = ["CASH_ADVANCE", "COMPANY_LOAN", "SALARY_LOAN", "EMERGENCY_LOAN", "TRAVEL_ADVANCE", "OTHER"];
const loanLabel = (t: string) => t.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());

export default function SelfServicePage() {
  const [tab, setTab] = useState("Profile");
  const [profile, setProfile] = useState<any>(null);
  const [payslips, setPayslips] = useState<any[]>([]);
  const [leave, setLeave] = useState<any[]>([]);
  const [att, setAtt] = useState<any[]>([]);
  const [loans, setLoans] = useState<any[]>([]);
  const [contrib, setContrib] = useState<any[]>([]);
  const [special, setSpecial] = useState<any[]>([]);
  const [leaveTypes, setLeaveTypes] = useState<any[]>([]);
  const [msg, setMsg] = useState("");
  const [err, setErr] = useState("");
  const [pw, setPw] = useState({ current_password: "", new_password: "" });
  const [leaveForm, setLeaveForm] = useState<any>({ leave_type: "", date_from: "", date_to: "", days: "" });
  const [loanForm, setLoanForm] = useState<any>({ obligation_type: "CASH_ADVANCE", principal: "", description: "" });

  const load = useCallback(() => {
    apiFetch("/me/profile").then(setProfile).catch((e) => setErr(e.message));
    apiFetch("/me/available-payslips").then(setPayslips).catch(() => {});
    apiFetch("/me/leave").then(setLeave).catch(() => {});
    apiFetch("/me/leave-types").then(setLeaveTypes).catch(() => {});
    apiFetch("/me/attendance").then(setAtt).catch(() => {});
    apiFetch("/me/loans").then(setLoans).catch(() => {});
    apiFetch("/me/special-pay").then(setSpecial).catch(() => {});
    apiFetch("/me/contributions").then(setContrib).catch(() => {});
  }, []);
  useEffect(load, [load]);

  async function submitLeave() {
    setErr(""); setMsg("");
    if (!leaveForm.date_from || !leaveForm.date_to) { setErr("Please choose the leave dates."); return; }
    try {
      await apiFetch("/me/leave", { method: "POST", body: JSON.stringify({
        leave_type: leaveForm.leave_type || "Vacation",
        date_from: leaveForm.date_from, date_to: leaveForm.date_to,
        days: leaveForm.days ? Number(leaveForm.days) : undefined,
      }) });
      setMsg("Leave request submitted for approval.");
      setLeaveForm({ leave_type: "", date_from: "", date_to: "", days: "" });
      load();
    } catch (e: any) { setErr(e.message); }
  }

  async function submitLoan() {
    setErr(""); setMsg("");
    if (!loanForm.principal || Number(loanForm.principal) <= 0) { setErr("Enter the amount you're requesting."); return; }
    try {
      await apiFetch("/me/loans", { method: "POST", body: JSON.stringify({
        obligation_type: loanForm.obligation_type, principal: Number(loanForm.principal), description: loanForm.description,
      }) });
      setMsg("Request submitted for approval.");
      setLoanForm({ obligation_type: "CASH_ADVANCE", principal: "", description: "" });
      load();
    } catch (e: any) { setErr(e.message); }
  }

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

      {tab === "13th Month" ? (
        <Section title={`13th Month & Bonuses (${special.length})`}>
          <table className="min-w-full text-sm">
            <thead><tr className="text-left text-xs uppercase text-slate-400"><th className="py-1">Name</th><th>Type</th><th>Year</th><th className="text-right">Amount</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {special.map((s, i) => (<tr key={i}><td className="py-2">{s.name}</td><td className="py-2 text-xs text-slate-500">{s.pay_type}</td><td className="py-2">{s.year}</td><td className="py-2 text-right font-medium">{peso(s.amount)}</td></tr>))}
              {special.length === 0 ? <tr><td colSpan={4} className="py-6 text-center text-slate-400">No 13th-month or bonus records yet.</td></tr> : null}
            </tbody>
          </table>
        </Section>
      ) : null}

      {tab === "Leave" ? (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <Section title="Request Leave">
            <div className="space-y-3">
              <div>
                <label className="mb-1 block text-xs text-slate-500">Leave type</label>
                {leaveTypes.length ? (
                  <select className="input" value={leaveForm.leave_type} onChange={(e) => setLeaveForm({ ...leaveForm, leave_type: e.target.value })}>
                    <option value="">— select —</option>
                    {leaveTypes.map((t) => <option key={t.id} value={t.name}>{t.name}</option>)}
                  </select>
                ) : (
                  <input className="input" placeholder="e.g. Vacation, Sick" value={leaveForm.leave_type} onChange={(e) => setLeaveForm({ ...leaveForm, leave_type: e.target.value })} />
                )}
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="mb-1 block text-xs text-slate-500">From</label>
                  <input type="date" className="input" value={leaveForm.date_from} onChange={(e) => setLeaveForm({ ...leaveForm, date_from: e.target.value })} /></div>
                <div><label className="mb-1 block text-xs text-slate-500">To</label>
                  <input type="date" className="input" value={leaveForm.date_to} onChange={(e) => setLeaveForm({ ...leaveForm, date_to: e.target.value })} /></div>
              </div>
              <div><label className="mb-1 block text-xs text-slate-500">Days (optional — auto-computed from dates)</label>
                <input type="number" min="0" step="0.5" className="input max-w-[140px]" value={leaveForm.days} onChange={(e) => setLeaveForm({ ...leaveForm, days: e.target.value })} /></div>
              <button className="btn-primary" onClick={submitLeave}>Submit for approval</button>
              <p className="text-xs text-slate-400">Your Department Head or HR will review and approve or reject the request.</p>
            </div>
          </Section>
          <Section title={`My Leave Requests (${leave.length})`}>
            <table className="min-w-full text-sm"><tbody className="divide-y divide-slate-100">
              {leave.map((l) => (<tr key={l.id}><td className="py-2">{l.leave_type}</td><td className="py-2 text-xs text-slate-500">{l.date_from} → {l.date_to}</td><td className="py-2">{l.days}d</td><td className="py-2"><span className="badge bg-slate-100 text-slate-600">{l.status}</span></td></tr>))}
              {leave.length === 0 ? <tr><td colSpan={4} className="py-6 text-center text-slate-400">No leave requests yet.</td></tr> : null}
            </tbody></table>
          </Section>
        </div>
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
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <Section title="Request a Loan / Cash Advance">
            <div className="space-y-3">
              <div><label className="mb-1 block text-xs text-slate-500">Type</label>
                <select className="input" value={loanForm.obligation_type} onChange={(e) => setLoanForm({ ...loanForm, obligation_type: e.target.value })}>
                  {LOAN_TYPES.map((t) => <option key={t} value={t}>{loanLabel(t)}</option>)}
                </select></div>
              <div><label className="mb-1 block text-xs text-slate-500">Amount requested (₱)</label>
                <input type="number" min="0" step="0.01" className="input max-w-[200px]" value={loanForm.principal} onChange={(e) => setLoanForm({ ...loanForm, principal: e.target.value })} /></div>
              <div><label className="mb-1 block text-xs text-slate-500">Reason / details</label>
                <textarea className="input" rows={2} value={loanForm.description} onChange={(e) => setLoanForm({ ...loanForm, description: e.target.value })} /></div>
              <button className="btn-primary" onClick={submitLoan}>Submit for approval</button>
              <p className="text-xs text-slate-400">Your Department Head or HR will review and approve or reject the request.</p>
            </div>
          </Section>
          <Section title={`My Loans & Advances (${loans.length})`}>
            <table className="min-w-full text-sm"><tbody className="divide-y divide-slate-100">
              {loans.map((l, i) => (<tr key={i}><td className="py-2">{l.description || loanLabel(l.type)}</td><td className="py-2 text-right">{peso(l.principal)}</td><td className="py-2 text-right font-medium">{peso(l.balance)} left</td><td className="py-2"><span className="badge bg-slate-100 text-slate-600">{l.status}</span></td></tr>))}
              {loans.length === 0 ? <tr><td colSpan={4} className="py-6 text-center text-slate-400">No loans or advances.</td></tr> : null}
            </tbody></table>
          </Section>
        </div>
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
