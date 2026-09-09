"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";

export default function RecruitmentPage() {
  const orgId = Number(useParams().orgId);
  const [reqs, setReqs] = useState<any[]>([]);
  const [apps, setApps] = useState<any[]>([]);
  const [msg, setMsg] = useState("");

  const load = useCallback(() => {
    apiFetch(`/organizations/${orgId}/recruitment/requisitions`).then(setReqs).catch(() => setReqs([]));
    apiFetch(`/organizations/${orgId}/recruitment/applications`).then(setApps).catch(() => setApps([]));
  }, [orgId]);
  useEffect(load, [load]);

  async function advance(id: number) {
    await apiFetch(`/organizations/${orgId}/recruitment/applications/${id}/advance`, { method: "POST", body: JSON.stringify({}) });
    load();
  }
  async function hire(id: number) {
    const r = await apiFetch<any>(`/organizations/${orgId}/recruitment/applications/${id}/hire`, { method: "POST", body: JSON.stringify({ engagement_type: "PROBATIONARY", base_rate: 28000 }) });
    setMsg(`Hired — created person #${r.person_id}, engagement #${r.engagement_id}.`); load();
  }

  return (
    <AppShell orgId={orgId}>
      <h1 className="mb-4 text-lg font-semibold text-slate-900">Recruitment</h1>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="card p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Job Requisitions ({reqs.length})</div>
          <table className="min-w-full text-sm">
            <tbody className="divide-y divide-slate-100">
              {reqs.map((r) => (
                <tr key={r.id}><td className="px-4 py-2">{r.title}</td><td className="px-4 py-2 text-xs text-slate-500">HC {r.headcount}</td><td className="px-4 py-2"><span className="badge bg-slate-100 text-slate-600">{r.status}</span></td></tr>
              ))}
              {reqs.length === 0 ? <tr><td className="px-4 py-6 text-center text-slate-400">No requisitions.</td></tr> : null}
            </tbody>
          </table>
        </div>

        <div className="card p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Applications ({apps.length})</div>
          <table className="min-w-full text-sm">
            <tbody className="divide-y divide-slate-100">
              {apps.map((a) => (
                <tr key={a.id}>
                  <td className="px-4 py-2">{a.applicant}</td>
                  <td className="px-4 py-2"><span className="badge bg-blue-100 text-blue-700">{a.stage}</span></td>
                  <td className="px-4 py-2 text-right space-x-2">
                    {a.stage !== "HIRED" ? <button className="text-brand-700 hover:underline" onClick={() => advance(a.id)}>Advance</button> : null}
                    {a.stage === "OFFER" ? <button className="text-emerald-700 hover:underline" onClick={() => hire(a.id)}>Hire</button> : null}
                  </td>
                </tr>
              ))}
              {apps.length === 0 ? <tr><td className="px-4 py-6 text-center text-slate-400">No applications.</td></tr> : null}
            </tbody>
          </table>
        </div>
      </div>
    </AppShell>
  );
}
