"use client";

import { useEffect, useState } from "react";
import { useParams, useSearchParams } from "next/navigation";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";
import { peso } from "@/lib/format";
import { statusColor } from "@/lib/format";
import type { EngagementRow } from "@/lib/types";

export default function PeoplePage() {
  const params = useParams();
  const orgId = Number(params.orgId);
  const searchParams = useSearchParams();
  const type = searchParams.get("type") || "people";

  const [rows, setRows] = useState<EngagementRow[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [q, setQ] = useState("");

  const endpoint =
    type === "employees"
      ? "employees"
      : type === "consultants"
        ? "consultants"
        : "people";
  const title =
    type === "employees" ? "Employees" : type === "consultants" ? "Consultants" : "People";

  useEffect(() => {
    apiFetch<EngagementRow[]>(`/organizations/${orgId}/${endpoint}`)
      .then(setRows)
      .catch((e) => setError(e.message));
  }, [orgId, endpoint]);

  const filtered = rows.filter(
    (r) =>
      r.full_name.toLowerCase().includes(q.toLowerCase()) ||
      (r.employee_number || "").toLowerCase().includes(q.toLowerCase())
  );

  return (
    <AppShell orgId={orgId}>
      <div className="mb-5 flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">{title}</h1>
          <p className="text-sm text-slate-500">{filtered.length} record(s)</p>
        </div>
        <input
          className="input max-w-xs"
          placeholder="Search name or number…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
      </div>

      {error ? (
        <div className="card text-sm text-red-600">{error}</div>
      ) : (
        <div className="card overflow-x-auto p-0">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <thead className="bg-slate-50">
              <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                <th className="px-4 py-3">Employee No.</th>
                <th className="px-4 py-3">Name</th>
                <th className="px-4 py-3">Type</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3 text-right">Base Rate</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {filtered.map((r) => (
                <tr key={r.engagement_id} className="hover:bg-slate-50">
                  <td className="px-4 py-3 font-mono text-xs text-slate-600">
                    {r.employee_number || "—"}
                  </td>
                  <td className="px-4 py-3 font-medium text-slate-800">{r.full_name}</td>
                  <td className="px-4 py-3 text-slate-600">{r.engagement_type}</td>
                  <td className="px-4 py-3">
                    <span className={`badge ${statusColor(r.status)}`}>{r.status}</span>
                  </td>
                  <td className="px-4 py-3 text-right text-slate-700">{peso(r.base_rate)}</td>
                </tr>
              ))}
              {filtered.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-4 py-8 text-center text-slate-400">
                    No records.
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>
      )}
    </AppShell>
  );
}
