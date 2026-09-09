
import { useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch } from "@/lib/api";

export default function HrModulesPage() {
  const orgId = Number(useParams().orgId);
  const [tickets, setTickets] = useState<any[]>([]);
  const [reviews, setReviews] = useState<any[]>([]);
  const [training, setTraining] = useState<any[]>([]);
  const [approvals, setApprovals] = useState<any[]>([]);

  useEffect(() => {
    apiFetch(`/organizations/${orgId}/service-tickets`).then(setTickets).catch(() => setTickets([]));
    apiFetch(`/organizations/${orgId}/performance/reviews`).then(setReviews).catch(() => setReviews([]));
    apiFetch(`/organizations/${orgId}/training/assignments`).then(setTraining).catch(() => setTraining([]));
    apiFetch(`/organizations/${orgId}/approvals`).then(setApprovals).catch(() => setApprovals([]));
  }, [orgId]);

  const Card = ({ title, children }: { title: string; children: React.ReactNode }) => (
    <div className="card p-0">
      <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">{title}</div>
      <div className="max-h-80 overflow-y-auto">{children}</div>
    </div>
  );

  return (
    <AppShell orgId={orgId}>
      <h1 className="mb-4 text-lg font-semibold text-slate-900">HR Modules</h1>
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card title={`Service Desk (${tickets.length})`}>
          <table className="min-w-full text-sm"><tbody className="divide-y divide-slate-100">
            {tickets.map((t) => (<tr key={t.id}><td className="px-4 py-2 font-mono text-xs">{t.ticket_number}</td><td className="px-4 py-2">{t.subject}</td><td className="px-4 py-2 text-xs text-slate-500">{t.priority}</td><td className="px-4 py-2"><span className="badge bg-slate-100 text-slate-600">{t.status}</span></td></tr>))}
            {tickets.length === 0 ? <tr><td className="px-4 py-6 text-center text-slate-400">No tickets.</td></tr> : null}
          </tbody></table>
        </Card>

        <Card title={`Performance Reviews (${reviews.length})`}>
          <table className="min-w-full text-sm"><tbody className="divide-y divide-slate-100">
            {reviews.map((r) => (<tr key={r.id}><td className="px-4 py-2 text-xs text-slate-500">Eng #{r.engagement_id}</td><td className="px-4 py-2">Self {r.self_score} · Sup {r.supervisor_score}</td><td className="px-4 py-2 font-semibold">Final {r.final_rating ?? "—"}</td><td className="px-4 py-2"><span className="badge bg-slate-100 text-slate-600">{r.status}</span></td></tr>))}
            {reviews.length === 0 ? <tr><td className="px-4 py-6 text-center text-slate-400">No reviews.</td></tr> : null}
          </tbody></table>
        </Card>

        <Card title={`Training Assignments (${training.length})`}>
          <table className="min-w-full text-sm"><tbody className="divide-y divide-slate-100">
            {training.map((t) => (<tr key={t.id}><td className="px-4 py-2 text-xs text-slate-500">Course #{t.course_id}</td><td className="px-4 py-2 text-xs text-slate-500">Eng #{t.engagement_id}</td><td className="px-4 py-2"><span className="badge bg-slate-100 text-slate-600">{t.status}</span></td></tr>))}
            {training.length === 0 ? <tr><td className="px-4 py-6 text-center text-slate-400">No assignments.</td></tr> : null}
          </tbody></table>
        </Card>

        <Card title={`Approval Instances (${approvals.length})`}>
          <table className="min-w-full text-sm"><tbody className="divide-y divide-slate-100">
            {approvals.map((a) => (<tr key={a.id}><td className="px-4 py-2">{a.transaction_type}</td><td className="px-4 py-2 text-xs text-slate-500">step {a.current_step}</td><td className="px-4 py-2"><span className="badge bg-slate-100 text-slate-600">{a.status}</span></td></tr>))}
            {approvals.length === 0 ? <tr><td className="px-4 py-6 text-center text-slate-400">No approvals raised.</td></tr> : null}
          </tbody></table>
        </Card>
      </div>
    </AppShell>
  );
}
