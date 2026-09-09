"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";
import { peso } from "@/lib/format";

interface ProjectRow {
  id: number;
  project_code: string;
  project_name: string;
  status: string;
  start_date: string | null;
  target_end_date: string | null;
  labor_budget: number | null;
}

export default function ProjectsPage() {
  const params = useParams();
  const orgId = Number(params.orgId);
  const [rows, setRows] = useState<ProjectRow[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiFetch<ProjectRow[]>(`/organizations/${orgId}/projects`)
      .then(setRows)
      .catch((e) => setError(e.message));
  }, [orgId]);

  return (
    <AppShell orgId={orgId}>
      <div className="mb-5">
        <h1 className="text-lg font-semibold text-slate-900">Projects</h1>
        <p className="text-sm text-slate-500">{rows.length} project(s)</p>
      </div>
      {error ? (
        <div className="card text-sm text-red-600">{error}</div>
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {rows.map((p) => (
            <div key={p.id} className="card">
              <div className="flex items-center justify-between">
                <span className="font-mono text-xs text-slate-500">{p.project_code}</span>
                <span className="badge bg-emerald-100 text-emerald-700">{p.status}</span>
              </div>
              <div className="mt-2 font-medium text-slate-800">{p.project_name}</div>
              <div className="mt-3 text-xs text-slate-500">
                {p.start_date || "—"} → {p.target_end_date || "—"}
              </div>
              <div className="mt-1 text-sm text-slate-700">
                Labor budget: {peso(p.labor_budget)}
              </div>
            </div>
          ))}
          {rows.length === 0 ? (
            <div className="card text-sm text-slate-400">No projects.</div>
          ) : null}
        </div>
      )}
    </AppShell>
  );
}
