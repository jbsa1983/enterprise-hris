
import { useCallback, useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";
import { peso } from "@/lib/format";

const TYPES = ["SSS_LOAN", "PAGIBIG_LOAN", "COMPANY_LOAN", "SALARY_LOAN", "CASH_ADVANCE",
  "TRAVEL_ADVANCE", "EMERGENCY_LOAN", "GADGET_INSTALLMENT", "EQUIPMENT_INSTALLMENT", "COOPERATIVE_LOAN", "OTHER"];
const label = (t: string) => t.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());

export default function LoansPage() {
  const orgId = Number(useParams().orgId);
  const [summary, setSummary] = useState<any[]>([]);
  const [tab, setTab] = useState<string>("ALL");
  const [rows, setRows] = useState<any[]>([]);
  const [people, setPeople] = useState<any[]>([]);
  const [modal, setModal] = useState<null | "new" | { adjust: any }>(null);
  const [form, setForm] = useState<any>({});
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const load = useCallback(() => {
    apiFetch(`/organizations/${orgId}/loans/summary`).then(setSummary).catch(() => {});
    const url = tab === "ALL" ? `/organizations/${orgId}/loans` : `/organizations/${orgId}/loans?obligation_type=${tab}`;
    apiFetch(url).then(setRows).catch((e) => setErr(e.message));
    apiFetch(`/organizations/${orgId}/people`).then(setPeople).catch(() => {});
  }, [orgId, tab]);
  useEffect(load, [load]);

  const totalOut = rows.reduce((s, r) => s + (r.balance || 0), 0);

  async function createLoan() {
    setErr("");
    try {
      await apiFetch(`/organizations/${orgId}/loans`, { method: "POST", body: JSON.stringify({
        person_id: Number(form.person_id), obligation_type: form.obligation_type || "COMPANY_LOAN",
        description: form.description, principal: Number(form.principal || 0),
        interest: Number(form.interest || 0), installment_amount: Number(form.installment_amount || 0),
      }) });
      setMsg("Obligation created."); setModal(null); setForm({}); load();
    } catch (e: any) { setErr(e.message); }
  }
  async function adjust(loan: any) {
    setErr("");
    try {
      await apiFetch(`/organizations/${orgId}/loans/${loan.id}/adjust`, { method: "POST", body: JSON.stringify({
        entry_type: form.entry_type || "DIRECT_PAYMENT", amount: Number(form.amount || 0), remarks: form.remarks,
      }) });
      setMsg("Ledger updated."); setModal(null); setForm({}); load();
    } catch (e: any) { setErr(e.message); }
  }

  async function decide(loan: any, decision: string) {
    setErr("");
    try {
      await apiFetch(`/organizations/${orgId}/loans/${loan.id}/decision`, { method: "POST", body: JSON.stringify({ decision }) });
      setMsg(decision === "APPROVED" ? "Request approved." : "Request rejected."); load();
    } catch (e: any) { setErr(e.message); }
  }

  const countFor = (t: string) => summary.find((s) => s.obligation_type === t)?.count || 0;

  return (
    <AppShell orgId={orgId}>
      <div className="mb-4 flex items-center justify-between">
        <div><h1 className="text-lg font-semibold text-slate-900">Loans &amp; Advances</h1>
          <p className="text-sm text-slate-500">{rows.length} record(s) · {peso(totalOut)} outstanding</p></div>
        <button className="btn-primary" onClick={() => { setForm({ obligation_type: tab === "ALL" ? "COMPANY_LOAN" : tab }); setModal("new"); }}>+ New</button>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="mb-4 flex flex-wrap gap-1 border-b border-slate-200">
        {["ALL", ...TYPES.filter((t) => t === "ALL" || countFor(t) > 0)].map((t) => (
          <button key={t} onClick={() => setTab(t)}
            className={`rounded-t-lg px-3 py-2 text-sm ${tab === t ? "border-b-2 border-geek-blue font-medium text-geek-blue" : "text-slate-500 hover:text-slate-700"}`}>
            {t === "ALL" ? "All" : label(t)} {t !== "ALL" ? <span className="text-xs text-slate-400">({countFor(t)})</span> : null}
          </button>
        ))}
      </div>

      <div className="card p-0 overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50"><tr className="text-left text-xs uppercase text-slate-500">
            <th className="px-4 py-2">Person</th><th className="px-4 py-2">Type</th><th className="px-4 py-2">Description</th>
            <th className="px-4 py-2 text-right">Principal</th><th className="px-4 py-2 text-right">Balance</th>
            <th className="px-4 py-2 text-right">Installment</th><th className="px-4 py-2">Status</th><th className="px-4 py-2"></th>
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((r) => (
              <tr key={r.id} className="hover:bg-slate-50">
                <td className="px-4 py-2">{r.person}</td><td className="px-4 py-2 text-xs">{label(r.obligation_type)}</td>
                <td className="px-4 py-2 text-slate-600">{r.description || "—"}</td>
                <td className="px-4 py-2 text-right">{peso(r.principal)}</td>
                <td className="px-4 py-2 text-right font-medium">{peso(r.balance)}</td>
                <td className="px-4 py-2 text-right">{peso(r.installment_amount)}</td>
                <td className="px-4 py-2"><span className={`badge ${r.status === "PENDING" ? "bg-amber-100 text-amber-700" : "bg-slate-100 text-slate-600"}`}>{r.status}</span></td>
                <td className="px-4 py-2 text-right whitespace-nowrap">
                  {r.status === "PENDING" ? (
                    <span className="space-x-3">
                      <button className="text-emerald-700 hover:underline" onClick={() => decide(r, "APPROVED")}>Approve</button>
                      <button className="text-red-600 hover:underline" onClick={() => decide(r, "REJECTED")}>Reject</button>
                    </span>
                  ) : r.balance > 0 ? (
                    <button className="text-brand-700 hover:underline" onClick={() => { setForm({ entry_type: "DIRECT_PAYMENT" }); setModal({ adjust: r }); }}>Adjust</button>
                  ) : null}
                </td>
              </tr>
            ))}
            {rows.length === 0 ? <tr><td colSpan={8} className="px-4 py-6 text-center text-slate-400">No records.</td></tr> : null}
          </tbody>
        </table>
      </div>

      {modal === "new" ? (
        <Modal title="New Loan / Advance" onClose={() => setModal(null)} onSave={createLoan}>
          <Field label="Person"><select className="input" value={form.person_id || ""} onChange={(e) => setForm({ ...form, person_id: e.target.value })}>
            <option value="">Select…</option>{people.map((p) => <option key={p.person_id} value={p.person_id}>{p.full_name}</option>)}</select></Field>
          <Field label="Type"><select className="input" value={form.obligation_type} onChange={(e) => setForm({ ...form, obligation_type: e.target.value })}>
            {TYPES.map((t) => <option key={t} value={t}>{label(t)}</option>)}</select></Field>
          <Field label="Description"><input className="input" value={form.description || ""} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
          <Field label="Principal"><input className="input" type="number" value={form.principal || ""} onChange={(e) => setForm({ ...form, principal: e.target.value })} /></Field>
          <Field label="Installment / period"><input className="input" type="number" value={form.installment_amount || ""} onChange={(e) => setForm({ ...form, installment_amount: e.target.value })} /></Field>
        </Modal>
      ) : null}
      {modal && typeof modal === "object" ? (
        <Modal title={`Adjust — ${modal.adjust.person}`} onClose={() => setModal(null)} onSave={() => adjust(modal.adjust)}>
          <p className="text-xs text-slate-500">Current balance: {peso(modal.adjust.balance)}</p>
          <Field label="Entry type"><select className="input" value={form.entry_type} onChange={(e) => setForm({ ...form, entry_type: e.target.value })}>
            {["DIRECT_PAYMENT", "LIQUIDATION", "CHARGE", "INTEREST", "ADJUSTMENT"].map((t) => <option key={t} value={t}>{label(t)}</option>)}</select></Field>
          <Field label="Amount"><input className="input" type="number" value={form.amount || ""} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></Field>
          <Field label="Remarks"><input className="input" value={form.remarks || ""} onChange={(e) => setForm({ ...form, remarks: e.target.value })} /></Field>
        </Modal>
      ) : null}
    </AppShell>
  );
}

function Modal({ title, children, onClose, onSave }: any) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <h2 className="mb-4 text-base font-semibold">{title}</h2>
        <div className="space-y-3">{children}</div>
        <div className="mt-5 flex justify-end gap-2">
          <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm" onClick={onClose}>Cancel</button>
          <button className="btn-primary" onClick={onSave}>Save</button>
        </div>
      </div>
    </div>
  );
}
function Field({ label, children }: any) {
  return <div><label className="mb-1 block text-xs text-slate-500">{label}</label>{children}</div>;
}
