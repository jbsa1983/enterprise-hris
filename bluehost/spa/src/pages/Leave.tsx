
import { useCallback, useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch, apiDownload, apiUpload } from "@/lib/api";
import { statusColor } from "@/lib/format";

export default function LeavePage() {
  const orgId = Number(useParams().orgId);
  const [leave, setLeave] = useState<any[]>([]);
  const [ot, setOt] = useState<any[]>([]);
  const [att, setAtt] = useState<any[]>([]);
  const [importMsg, setImportMsg] = useState("");

  const load = useCallback(() => {
    apiFetch(`/organizations/${orgId}/leave`).then(setLeave).catch(() => setLeave([]));
    apiFetch(`/organizations/${orgId}/overtime`).then(setOt).catch(() => setOt([]));
    apiFetch(`/organizations/${orgId}/attendance`).then(setAtt).catch(() => setAtt([]));
  }, [orgId]);
  useEffect(load, [load]);

  async function decideLeave(id: number, decision: string) {
    await apiFetch(`/organizations/${orgId}/leave/${id}/decision`, { method: "POST", body: JSON.stringify({ decision }) });
    load();
  }
  async function decideOt(id: number, decision: string) {
    await apiFetch(`/organizations/${orgId}/overtime/${id}/decision`, { method: "POST", body: JSON.stringify({ decision }) });
    load();
  }

  return (
    <AppShell orgId={orgId}>
      <h1 className="mb-4 text-lg font-semibold text-slate-900">Leave &amp; Attendance</h1>
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="card p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Leave Requests</div>
          <div className="max-h-96 overflow-y-auto">
            <table className="min-w-full text-sm">
              <tbody className="divide-y divide-slate-100">
                {leave.map((l) => (
                  <tr key={l.id}>
                    <td className="px-4 py-2">{l.leave_type}</td>
                    <td className="px-4 py-2 text-xs text-slate-500">{l.date_from} → {l.date_to} ({l.days}d)</td>
                    <td className="px-4 py-2"><span className={`badge ${statusColor(l.status)}`}>{l.status}</span></td>
                    <td className="px-4 py-2 text-right">
                      {l.status === "PENDING" ? (
                        <span className="space-x-2">
                          <button className="text-emerald-700 hover:underline" onClick={() => decideLeave(l.id, "APPROVED")}>Approve</button>
                          <button className="text-red-600 hover:underline" onClick={() => decideLeave(l.id, "REJECTED")}>Reject</button>
                        </span>
                      ) : null}
                    </td>
                  </tr>
                ))}
                {leave.length === 0 ? <tr><td className="px-4 py-6 text-center text-slate-400">No leave requests.</td></tr> : null}
              </tbody>
            </table>
          </div>
        </div>

        <div className="card p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Overtime Requests</div>
          <div className="max-h-96 overflow-y-auto">
            <table className="min-w-full text-sm">
              <tbody className="divide-y divide-slate-100">
                {ot.map((o) => (
                  <tr key={o.id}>
                    <td className="px-4 py-2 text-xs text-slate-500">{o.ot_date}</td>
                    <td className="px-4 py-2">{o.hours}h</td>
                    <td className="px-4 py-2"><span className={`badge ${statusColor(o.status)}`}>{o.status}</span></td>
                    <td className="px-4 py-2 text-right">
                      {o.status === "PENDING" ? (
                        <span className="space-x-2">
                          <button className="text-emerald-700 hover:underline" onClick={() => decideOt(o.id, "APPROVED")}>Approve</button>
                          <button className="text-red-600 hover:underline" onClick={() => decideOt(o.id, "REJECTED")}>Reject</button>
                        </span>
                      ) : null}
                    </td>
                  </tr>
                ))}
                {ot.length === 0 ? <tr><td className="px-4 py-6 text-center text-slate-400">No overtime requests.</td></tr> : null}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div className="card mt-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div className="text-sm font-medium text-slate-700">Import Time Logs</div>
            <div className="text-xs text-slate-500">Upload monthly biometric/timekeeping logs (CSV or Excel). Match is by employee number.</div>
          </div>
          <div className="flex items-center gap-2">
            <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
              onClick={() => apiDownload(`/organizations/${orgId}/attendance/template`, "attendance_template.csv")}>
              Download template
            </button>
            <label className="btn-primary cursor-pointer">
              Upload file
              <input type="file" accept=".csv,.xlsx" className="hidden" onChange={async (e) => {
                const f = e.target.files?.[0]; if (!f) return;
                setImportMsg("Uploading…");
                try {
                  const fd = new FormData(); fd.append("file", f);
                  const r = await apiUpload<any>(`/organizations/${orgId}/attendance/import`, fd);
                  setImportMsg(`Imported ${r.imported}, updated ${r.updated}${r.error_count ? `, ${r.error_count} error(s)` : ""}.`);
                  load(); e.target.value = "";
                } catch (er: any) { setImportMsg(er.message); }
              }} />
            </label>
          </div>
        </div>
        {importMsg ? <div className="mt-2 text-sm text-slate-600">{importMsg}</div> : null}
        <div className="mt-2 text-[11px] text-slate-400">
          Format: <code>employee_number, log_date (YYYY-MM-DD), time_in, time_out, hours_worked, late_minutes, overtime_hours, status</code>.
          Biometric devices/APIs can also POST to <code>/api/v1/organizations/{orgId}/attendance/device</code>.
        </div>
      </div>

      <div className="card mt-6 p-0">
        <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Recent Attendance ({att.length})</div>
        <div className="max-h-72 overflow-y-auto">
          <table className="min-w-full text-sm">
            <thead className="bg-slate-50"><tr className="text-left text-xs uppercase text-slate-500">
              <th className="px-4 py-2">Date</th><th className="px-4 py-2">Hours</th><th className="px-4 py-2">Late (min)</th><th className="px-4 py-2">OT (h)</th><th className="px-4 py-2">Source</th>
            </tr></thead>
            <tbody className="divide-y divide-slate-100">
              {att.slice(0, 30).map((a) => (
                <tr key={a.id}><td className="px-4 py-2">{a.log_date}</td><td className="px-4 py-2">{a.hours_worked}</td><td className="px-4 py-2">{a.late_minutes}</td><td className="px-4 py-2">{a.overtime_hours}</td><td className="px-4 py-2 text-xs text-slate-500">{a.source}</td></tr>
              ))}
              {att.length === 0 ? <tr><td className="px-4 py-6 text-center text-slate-400">No attendance logs.</td></tr> : null}
            </tbody>
          </table>
        </div>
      </div>
    </AppShell>
  );
}
