import { useCallback, useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";
import { peso } from "@/lib/format";

const LOAN_TYPES = ["COMPANY_LOAN", "CASH_ADVANCE", "SSS_LOAN", "PAGIBIG_LOAN", "SALARY_LOAN",
  "TRAVEL_ADVANCE", "EMERGENCY_LOAN", "GADGET_INSTALLMENT", "EQUIPMENT_INSTALLMENT", "COOPERATIVE_LOAN", "OTHER"];
const BENEFIT_TYPES = ["HMO / Health", "Life Insurance", "Dental", "Accident", "Retirement"];
const label = (t: string) => t.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());

interface Person { engagement_id: number; person_id: number; full_name: string; engagement_type: string; }

export default function OpeningBalancesPage() {
  const orgId = Number(useParams().orgId);
  const [people, setPeople] = useState<Person[]>([]);
  const [idx, setIdx] = useState(-1);
  const [types, setTypes] = useState<any[]>([]);
  const [leaveDraft, setLeaveDraft] = useState<Record<string, { credits: string; used: string }>>({});
  const [loans, setLoans] = useState<any[]>([]);
  const [benefits, setBenefits] = useState<any[]>([]);
  const [loanForm, setLoanForm] = useState<any>({ obligation_type: "COMPANY_LOAN" });
  const [benForm, setBenForm] = useState<any>({});
  const [msg, setMsg] = useState(""); const [err, setErr] = useState(""); const [busy, setBusy] = useState(false);

  const cur = idx >= 0 && idx < people.length ? people[idx] : null;

  useEffect(() => {
    apiFetch<Person[]>(`/organizations/${orgId}/people`).then(setPeople).catch((e) => setErr(e.message));
    apiFetch<any[]>(`/organizations/${orgId}/leave-types`).then(setTypes).catch(() => {});
  }, [orgId]);

  const loadPerson = useCallback((person: Person | null) => {
    setMsg(""); setErr(""); setLoanForm({ obligation_type: "COMPANY_LOAN" }); setBenForm({});
    if (!person) { setLeaveDraft({}); setLoans([]); setBenefits([]); return; }
    apiFetch<any[]>(`/organizations/${orgId}/leave-balances?engagement_id=${person.engagement_id}`).then((bal) => {
      const byType: Record<string, any> = {}; bal.forEach((b) => (byType[b.leave_type] = b));
      const map: Record<string, { credits: string; used: string }> = {};
      types.forEach((t) => { const b = byType[t.name]; map[t.name] = { credits: String(b?.credits ?? t.default_credits ?? 0), used: String(b?.used ?? 0) }; });
      bal.forEach((b) => { if (!map[b.leave_type]) map[b.leave_type] = { credits: String(b.credits), used: String(b.used) }; });
      setLeaveDraft(map);
    }).catch(() => setLeaveDraft({}));
    apiFetch<any[]>(`/organizations/${orgId}/loans`).then((all) => setLoans(all.filter((l) => l.person_id === person.person_id))).catch(() => setLoans([]));
    apiFetch<any[]>(`/organizations/${orgId}/benefits?person_id=${person.person_id}`).then(setBenefits).catch(() => setBenefits([]));
  }, [orgId, types]);

  useEffect(() => { loadPerson(cur); /* eslint-disable-next-line */ }, [idx, loadPerson]);

  const reloadLoans = () => cur && apiFetch<any[]>(`/organizations/${orgId}/loans`).then((all) => setLoans(all.filter((l) => l.person_id === cur.person_id))).catch(() => {});
  const reloadBenefits = () => cur && apiFetch<any[]>(`/organizations/${orgId}/benefits?person_id=${cur.person_id}`).then(setBenefits).catch(() => {});

  async function saveLeave() {
    if (!cur) return; setBusy(true); setErr(""); setMsg("");
    try {
      for (const [leave_type, v] of Object.entries(leaveDraft)) {
        await apiFetch(`/organizations/${orgId}/leave-balances`, { method: "POST", body: JSON.stringify({ engagement_id: cur.engagement_id, leave_type, credits: Number(v.credits || 0), used: Number(v.used || 0) }) });
      }
      setMsg("Leave credits saved.");
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }
  async function addLoan() {
    if (!cur || !loanForm.principal) return; setBusy(true); setErr(""); setMsg("");
    try {
      await apiFetch(`/organizations/${orgId}/loans`, { method: "POST", body: JSON.stringify({
        person_id: cur.person_id, engagement_id: cur.engagement_id, obligation_type: loanForm.obligation_type || "COMPANY_LOAN",
        description: loanForm.description, principal: Number(loanForm.principal || 0), installment_amount: Number(loanForm.installment_amount || 0),
        amount_paid: Number(loanForm.amount_paid || 0), start_date: loanForm.start_date || undefined,
      }) });
      setLoanForm({ obligation_type: "COMPANY_LOAN" }); setMsg("Loan / advance added."); reloadLoans();
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }
  async function addBenefit() {
    if (!cur || !benForm.benefit_type) return; setBusy(true); setErr(""); setMsg("");
    try {
      await apiFetch(`/organizations/${orgId}/benefits`, { method: "POST", body: JSON.stringify({
        person_id: cur.person_id, benefit_type: benForm.benefit_type, provider: benForm.provider, policy_number: benForm.policy_number,
        coverage_amount: benForm.coverage_amount ? Number(benForm.coverage_amount) : null, start_date: benForm.start_date || undefined, status: "ACTIVE",
      }) });
      setBenForm({}); setMsg("Benefit added."); reloadBenefits();
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }

  const setLeave = (type: string, k: "credits" | "used", val: string) =>
    setLeaveDraft((d) => ({ ...d, [type]: { ...d[type], [k]: val } }));

  return (
    <AppShell orgId={orgId}>
      <div className="mb-4">
        <h1 className="text-lg font-semibold text-slate-900">Opening balances</h1>
        <p className="text-sm text-slate-500">One-time go-live setup. Walk through each person and enter their starting leave credits, existing loans/advances, and current benefits — the system continues from these automatically.</p>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="card mb-4 flex flex-wrap items-center gap-3">
        <select className="input max-w-xs" value={idx} onChange={(e) => setIdx(Number(e.target.value))}>
          <option value={-1}>Select a person…</option>
          {people.map((p, i) => <option key={p.engagement_id} value={i}>{p.full_name} · {label(p.engagement_type)}</option>)}
        </select>
        {cur ? <span className="text-sm text-slate-500">{idx + 1} of {people.length}</span> : null}
        <div className="ml-auto flex gap-2">
          <button className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-40" disabled={idx <= 0} onClick={() => setIdx((i) => Math.max(0, i - 1))}>← Prev</button>
          <button className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-40" disabled={idx < 0 || idx >= people.length - 1} onClick={() => setIdx((i) => Math.min(people.length - 1, i + 1))}>Next →</button>
        </div>
      </div>

      {!cur ? (
        <div className="card text-sm text-slate-500">Pick a person to begin. Tip: use <strong>Next →</strong> to move through your whole roster.</div>
      ) : (
        <>
          {/* Leave */}
          <div className="card mb-4">
            <div className="mb-1 text-sm font-medium text-slate-700">Leave credits</div>
            <p className="mb-3 text-xs text-slate-500">Set the entitlement (Credits) and the days already taken this year (Used). Remaining is what carries into the system.</p>
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead><tr className="text-left text-xs uppercase text-slate-400"><th className="py-1">Leave type</th><th className="text-right">Credits</th><th className="text-right">Used</th><th className="text-right">Remaining</th></tr></thead>
                <tbody className="divide-y divide-slate-100">
                  {Object.entries(leaveDraft).map(([type, v]) => (
                    <tr key={type}>
                      <td className="py-2">{type}</td>
                      <td className="py-2 text-right"><input type="number" className="input max-w-[90px] text-right" value={v.credits} onChange={(e) => setLeave(type, "credits", e.target.value)} /></td>
                      <td className="py-2 text-right"><input type="number" className="input max-w-[90px] text-right" value={v.used} onChange={(e) => setLeave(type, "used", e.target.value)} /></td>
                      <td className="py-2 text-right font-medium">{(Number(v.credits || 0) - Number(v.used || 0)).toFixed(2)}</td>
                    </tr>
                  ))}
                  {Object.keys(leaveDraft).length === 0 ? <tr><td colSpan={4} className="py-4 text-center text-slate-400">Define leave types first (Leave &amp; Attendance).</td></tr> : null}
                </tbody>
              </table>
            </div>
            {Object.keys(leaveDraft).length > 0 ? <div className="mt-3"><button className="btn-primary" onClick={saveLeave} disabled={busy}>{busy ? "Saving…" : "Save leave credits"}</button></div> : null}
          </div>

          {/* Loans */}
          <div className="card mb-4">
            <div className="mb-1 text-sm font-medium text-slate-700">Loans &amp; cash advances</div>
            {loans.length > 0 ? (
              <div className="mb-3 overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead><tr className="text-left text-xs uppercase text-slate-400"><th className="py-1">Type</th><th>Description</th><th className="text-right">Original</th><th className="text-right">Paid</th><th className="text-right">Balance</th><th>Start</th></tr></thead>
                  <tbody className="divide-y divide-slate-100">
                    {loans.map((l) => (
                      <tr key={l.id}><td className="py-2">{label(l.obligation_type)}</td><td className="text-slate-600">{l.description || "—"}</td>
                        <td className="text-right">{peso(l.total_amount ?? l.principal)}</td><td className="text-right">{peso(l.amount_paid)}</td>
                        <td className="text-right font-medium">{peso(l.balance)}</td><td className="text-slate-500">{l.start_period || "—"}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : <p className="mb-3 text-xs text-slate-400">No loans or advances recorded for this person yet.</p>}
            <div className="rounded-lg border border-dashed border-slate-300 p-3">
              <div className="mb-2 text-xs font-medium text-slate-600">Add an existing loan / advance</div>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <div><label className="mb-1 block text-xs text-slate-500">Type</label>
                  <select className="input" value={loanForm.obligation_type} onChange={(e) => setLoanForm({ ...loanForm, obligation_type: e.target.value })}>{LOAN_TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}</select></div>
                <div className="sm:col-span-2"><label className="mb-1 block text-xs text-slate-500">Description</label><input className="input" value={loanForm.description || ""} onChange={(e) => setLoanForm({ ...loanForm, description: e.target.value })} /></div>
                <div><label className="mb-1 block text-xs text-slate-500">Original principal</label><input className="input" type="number" value={loanForm.principal || ""} onChange={(e) => setLoanForm({ ...loanForm, principal: e.target.value })} /></div>
                <div><label className="mb-1 block text-xs text-slate-500">Already paid (opening)</label><input className="input" type="number" placeholder="0" value={loanForm.amount_paid || ""} onChange={(e) => setLoanForm({ ...loanForm, amount_paid: e.target.value })} /></div>
                <div><label className="mb-1 block text-xs text-slate-500">Installment / period</label><input className="input" type="number" value={loanForm.installment_amount || ""} onChange={(e) => setLoanForm({ ...loanForm, installment_amount: e.target.value })} /></div>
                <div><label className="mb-1 block text-xs text-slate-500">Original start date</label><input className="input" type="date" value={loanForm.start_date || ""} onChange={(e) => setLoanForm({ ...loanForm, start_date: e.target.value })} /></div>
              </div>
              <div className="mt-2"><button className="btn-primary" onClick={addLoan} disabled={busy || !loanForm.principal}>Add loan</button></div>
            </div>
          </div>

          {/* Benefits */}
          <div className="card mb-4">
            <div className="mb-1 text-sm font-medium text-slate-700">Benefits</div>
            {benefits.length > 0 ? (
              <div className="mb-3 overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead><tr className="text-left text-xs uppercase text-slate-400"><th className="py-1">Type</th><th>Provider</th><th>Policy #</th><th className="text-right">Coverage</th><th>Start</th></tr></thead>
                  <tbody className="divide-y divide-slate-100">
                    {benefits.map((b) => (
                      <tr key={b.id}><td className="py-2">{b.benefit_type}</td><td className="text-slate-600">{b.provider || "—"}</td><td className="text-slate-500">{b.policy_number || "—"}</td>
                        <td className="text-right">{b.coverage_amount != null ? peso(b.coverage_amount) : "—"}</td><td className="text-slate-500">{b.start_date || "—"}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : <p className="mb-3 text-xs text-slate-400">No benefits recorded for this person yet.</p>}
            <div className="rounded-lg border border-dashed border-slate-300 p-3">
              <div className="mb-2 text-xs font-medium text-slate-600">Add an existing benefit</div>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <div><label className="mb-1 block text-xs text-slate-500">Type</label>
                  <input className="input" list="ob-benefit-types" value={benForm.benefit_type || ""} onChange={(e) => setBenForm({ ...benForm, benefit_type: e.target.value })} />
                  <datalist id="ob-benefit-types">{BENEFIT_TYPES.map((t) => <option key={t} value={t} />)}</datalist></div>
                <div><label className="mb-1 block text-xs text-slate-500">Provider</label><input className="input" value={benForm.provider || ""} onChange={(e) => setBenForm({ ...benForm, provider: e.target.value })} /></div>
                <div><label className="mb-1 block text-xs text-slate-500">Policy #</label><input className="input" value={benForm.policy_number || ""} onChange={(e) => setBenForm({ ...benForm, policy_number: e.target.value })} /></div>
                <div><label className="mb-1 block text-xs text-slate-500">Coverage amount</label><input className="input" type="number" value={benForm.coverage_amount || ""} onChange={(e) => setBenForm({ ...benForm, coverage_amount: e.target.value })} /></div>
                <div><label className="mb-1 block text-xs text-slate-500">Start date</label><input className="input" type="date" value={benForm.start_date || ""} onChange={(e) => setBenForm({ ...benForm, start_date: e.target.value })} /></div>
              </div>
              <div className="mt-2"><button className="btn-primary" onClick={addBenefit} disabled={busy || !benForm.benefit_type}>Add benefit</button>
                <span className="ml-2 text-xs text-slate-400">Beneficiaries can be added later on the Benefits page.</span></div>
            </div>
          </div>

          <div className="mb-8 flex items-center justify-end gap-3">
            {idx >= people.length - 1 ? <span className="text-sm text-slate-500">Last person on the roster.</span> : null}
            <button className="btn-primary" disabled={busy} onClick={async () => { if (Object.keys(leaveDraft).length) await saveLeave(); if (idx < people.length - 1) { setIdx((i) => i + 1); window.scrollTo(0, 0); } }}>
              {idx >= people.length - 1 ? "Save leave credits" : "Save & next person →"}
            </button>
          </div>
        </>
      )}
    </AppShell>
  );
}
