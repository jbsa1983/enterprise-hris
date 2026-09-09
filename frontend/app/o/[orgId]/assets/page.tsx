"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";
import { peso } from "@/lib/format";

export default function AssetsPage() {
  const orgId = Number(useParams().orgId);
  const [rows, setRows] = useState<any[]>([]);
  useEffect(() => { apiFetch(`/organizations/${orgId}/assets`).then(setRows).catch(() => setRows([])); }, [orgId]);

  return (
    <AppShell orgId={orgId}>
      <h1 className="mb-4 text-lg font-semibold text-slate-900">Assets</h1>
      <div className="card p-0 overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50"><tr className="text-left text-xs uppercase text-slate-500">
            <th className="px-4 py-2">Asset No.</th><th className="px-4 py-2">Item</th><th className="px-4 py-2">Serial</th>
            <th className="px-4 py-2">Payable?</th><th className="px-4 py-2 text-right">Cost</th>
            <th className="px-4 py-2 text-right">Outstanding</th><th className="px-4 py-2">Status</th>
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((a) => (
              <tr key={a.id} className="hover:bg-slate-50">
                <td className="px-4 py-2 font-mono text-xs">{a.asset_number}</td>
                <td className="px-4 py-2">{a.item}</td>
                <td className="px-4 py-2 text-xs text-slate-500">{a.serial_number || "—"}</td>
                <td className="px-4 py-2">{a.is_employee_payable ? "Employee-payable" : "Company"}</td>
                <td className="px-4 py-2 text-right">{peso(a.cost)}</td>
                <td className="px-4 py-2 text-right">{a.is_employee_payable ? peso(a.outstanding_balance) : "—"}</td>
                <td className="px-4 py-2"><span className="badge bg-slate-100 text-slate-600">{a.status}</span></td>
              </tr>
            ))}
            {rows.length === 0 ? <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">No assets.</td></tr> : null}
          </tbody>
        </table>
      </div>
    </AppShell>
  );
}
