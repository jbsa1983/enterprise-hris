"use client";

import { useEffect, useState } from "react";
import AppShell from "@/components/AppShell";
import { apiFetch, apiOpen } from "@/lib/api";
import { peso } from "@/lib/format";

export default function SelfServicePage() {
  const [slips, setSlips] = useState<any[]>([]);
  const [engs, setEngs] = useState<any[]>([]);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    Promise.all([
      apiFetch("/me/payslips").then(setSlips).catch(() => setSlips([])),
      apiFetch("/me/engagements").then(setEngs).catch(() => setEngs([])),
    ]).finally(() => setLoaded(true));
  }, []);

  return (
    <AppShell>
      <h1 className="mb-1 text-lg font-semibold text-slate-900">My Self-Service</h1>
      <p className="mb-4 text-sm text-slate-500">You can only see your own records. (Sign in as employee.exg@demo-hris.local / Demo123! to see ESS data.)</p>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="card p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">My Payslips ({slips.length})</div>
          <table className="min-w-full text-sm">
            <tbody className="divide-y divide-slate-100">
              {slips.map((p) => (
                <tr key={p.uuid} className="hover:bg-slate-50">
                  <td className="px-4 py-2">{p.period}</td>
                  <td className="px-4 py-2 text-xs text-slate-500">{p.document_type === "PAYMENT_ADVICE" ? "Payment Advice" : "Payslip"}</td>
                  <td className="px-4 py-2 text-right">{peso(p.net_pay)}</td>
                  <td className="px-4 py-2 text-right"><button className="text-brand-700 hover:underline" onClick={() => apiOpen(`/payslips/${p.uuid}/pdf`)}>View PDF</button></td>
                </tr>
              ))}
              {loaded && slips.length === 0 ? <tr><td colSpan={4} className="px-4 py-6 text-center text-slate-400">No payslips for your account.</td></tr> : null}
            </tbody>
          </table>
        </div>

        <div className="card p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">My Engagements ({engs.length})</div>
          <table className="min-w-full text-sm">
            <tbody className="divide-y divide-slate-100">
              {engs.map((e) => (
                <tr key={e.id}><td className="px-4 py-2 font-mono text-xs">{e.employee_number}</td><td className="px-4 py-2">{e.engagement_type}</td><td className="px-4 py-2"><span className="badge bg-slate-100 text-slate-600">{e.status}</span></td></tr>
              ))}
              {loaded && engs.length === 0 ? <tr><td colSpan={3} className="px-4 py-6 text-center text-slate-400">No engagements linked.</td></tr> : null}
            </tbody>
          </table>
        </div>
      </div>
    </AppShell>
  );
}
