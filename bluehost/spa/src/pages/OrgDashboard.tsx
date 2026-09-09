
import { useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import StatCard from "@/components/StatCard";
import StorageWidget from "@/components/StorageWidget";
import { apiFetch } from "@/lib/api";
import { peso, num } from "@/lib/format";

interface OrgDash {
  organization_id: number;
  organization_name: string;
  total_active_personnel: number;
  regular: number;
  project_based: number;
  consultants: number;
  departments: number;
  active_projects: number;
  payroll_period_status: string;
  gross_payroll: number;
  total_deductions: number;
  net_payroll: number;
  pending_leave_approvals: number;
  pending_overtime_approvals: number;
  contracts_expiring: Record<string, number>;
}

export default function OrgDashboardPage() {
  const params = useParams();
  const orgId = Number(params.orgId);
  const [data, setData] = useState<OrgDash | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiFetch<OrgDash>(`/organizations/${orgId}/dashboard`)
      .then(setData)
      .catch((e) => setError(e.message));
  }, [orgId]);

  return (
    <AppShell orgId={orgId}>
      <div className="mb-5">
        <h1 className="text-lg font-semibold text-slate-900">
          {data?.organization_name ?? "Organization"} — Dashboard
        </h1>
        <p className="text-sm text-slate-500">
          Data is scoped to this organization and enforced on the backend.
        </p>
      </div>

      {error ? (
        <div className="card text-sm text-red-600">{error}</div>
      ) : !data ? (
        <div className="card text-sm text-slate-400">Loading…</div>
      ) : (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard label="Active Personnel" value={num(data.total_active_personnel)} href={`/o/${orgId}/people`} />
            <StatCard label="Project-Based" value={num(data.project_based)} href={`/o/${orgId}/people`} />
            <StatCard label="Consultants" value={num(data.consultants)} href={`/o/${orgId}/people?type=consultants`} />
            <StatCard label="Departments" value={num(data.departments)} href={`/o/${orgId}/people`} />
            <StatCard label="Active Projects" value={num(data.active_projects)} href={`/o/${orgId}/projects`} />
            <StatCard
              label="Payroll Status"
              value={<span className="text-base">{data.payroll_period_status}</span>}
              href={`/o/${orgId}/payroll`}
            />
            <StatCard label="Pending Leave" value={num(data.pending_leave_approvals)} href={`/o/${orgId}/leave`} />
            <StatCard label="Pending OT" value={num(data.pending_overtime_approvals)} href={`/o/${orgId}/leave`} />
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div className="card lg:col-span-2">
              <div className="stat-label mb-3">Latest Payroll Run</div>
              <div className="grid grid-cols-3 gap-4">
                <StatCard label="Gross Payroll" value={peso(data.gross_payroll)} href={`/o/${orgId}/payroll`} />
                <StatCard label="Total Deductions" value={peso(data.total_deductions)} href={`/o/${orgId}/payroll`} />
                <StatCard label="Net Payroll" value={peso(data.net_payroll)} href={`/o/${orgId}/payroll`} />
              </div>
              <div className="mt-5 border-t border-slate-100 pt-4">
                <div className="stat-label mb-2">Contracts Expiring</div>
                <div className="grid grid-cols-3 gap-4">
                  <StatCard label="≤ 30 days" value={num(data.contracts_expiring?.in_30_days ?? 0)} />
                  <StatCard label="≤ 60 days" value={num(data.contracts_expiring?.in_60_days ?? 0)} />
                  <StatCard label="≤ 90 days" value={num(data.contracts_expiring?.in_90_days ?? 0)} />
                </div>
              </div>
            </div>
            <StorageWidget />
          </div>
        </div>
      )}
    </AppShell>
  );
}
