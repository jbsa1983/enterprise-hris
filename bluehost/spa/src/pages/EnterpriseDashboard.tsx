
import { useEffect, useState } from "react";
import Link from "@/lib/Link";
import AppShell from "@/components/AppShell";
import StatCard from "@/components/StatCard";
import StorageWidget from "@/components/StorageWidget";
import { apiFetch } from "@/lib/api";
import { peso, num } from "@/lib/format";
import { geekColor } from "@/lib/geek";

interface EnterpriseDash {
  organizations_count: number;
  total_active_personnel: number;
  regular: number;
  probationary: number;
  project_based: number;
  fixed_term: number;
  consultants: number;
  active_projects: number;
  payroll_net_total: number;
  pending_approvals: number;
  contracts_expiring: Record<string, number>;
  employee_distribution: { organization_id: number; name: string; personnel: number }[];
  workforce_cost_monthly: number;
}

export default function EnterpriseDashboardPage() {
  const [data, setData] = useState<EnterpriseDash | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiFetch<EnterpriseDash>("/dashboard/enterprise")
      .then(setData)
      .catch((e) => setError(e.message));
  }, []);

  return (
    <AppShell>
      <div className="mb-5">
        <h1 className="text-lg font-semibold text-slate-900">Enterprise Dashboard</h1>
        <p className="text-sm text-slate-500">
          Consolidated view across all organizations you can access.
        </p>
      </div>

      {error ? (
        <div className="card text-sm text-red-600">{error}</div>
      ) : !data ? (
        <div className="card text-sm text-slate-400">Loading…</div>
      ) : (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard label="Organizations" value={num(data.organizations_count)} />
            <StatCard label="Total Active Personnel" value={num(data.total_active_personnel)} />
            <StatCard label="Active Projects" value={num(data.active_projects)} />
            <StatCard label="Pending Approvals" value={num(data.pending_approvals)} />
            <StatCard label="Regular" value={num(data.regular)} accent="#EA4335" />
            <StatCard label="Probationary" value={num(data.probationary)} accent="#F9AB00" />
            <StatCard label="Project-Based" value={num(data.project_based)} accent="#34A853" />
            <StatCard label="Consultants" value={num(data.consultants)} accent="#4285F4" />
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div className="card lg:col-span-2">
              <div className="stat-label mb-3">Employee Distribution by Organization</div>
              <div className="space-y-3">
                {data.employee_distribution.map((o) => {
                  const max = Math.max(
                    1,
                    ...data.employee_distribution.map((x) => x.personnel)
                  );
                  return (
                    <div key={o.organization_id}>
                      <div className="mb-1 flex justify-between text-sm">
                        <Link
                          href={`/o/${o.organization_id}/dashboard`}
                          className="text-brand-700 hover:underline"
                        >
                          {o.name}
                        </Link>
                        <span className="text-slate-500">{o.personnel}</span>
                      </div>
                      <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                        <div
                          className="h-full rounded-full"
                          style={{ width: `${(o.personnel / max) * 100}%`, backgroundColor: geekColor(data.employee_distribution.indexOf(o)) }}
                        />
                      </div>
                    </div>
                  );
                })}
              </div>
              <div className="mt-5 grid grid-cols-2 gap-4 border-t border-slate-100 pt-4 sm:grid-cols-4">
                <StatCard label="Payroll Net (all runs)" value={peso(data.payroll_net_total)} />
                <StatCard label="Workforce Cost / mo" value={peso(data.workforce_cost_monthly)} />
                <StatCard
                  label="Expiring ≤30d"
                  value={num(data.contracts_expiring?.in_30_days ?? 0)}
                />
                <StatCard
                  label="Expiring ≤90d"
                  value={num(data.contracts_expiring?.in_90_days ?? 0)}
                />
              </div>
            </div>

            <StorageWidget />
          </div>
        </div>
      )}
    </AppShell>
  );
}
