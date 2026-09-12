
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
  const [types, setTypes] = useState<any[]>([]);
  const [people, setPeople] = useState<any[]>([]);
  const [newType, setNewType] = useState({ name: "", default_credits: "" });
  const [selEng, setSelEng] = useState("");
  const [balances, setBalances] = useState<any[]>([]);
  const [creditMsg, setCreditMsg] = useState("");

  const load = useCallback(() => {
    apiFetch(`/organizations/${orgId}/leave`).then(setLeave).catch(() => setLeave([]));
    apiFetch(`/organizations/${orgId}/overtime`).then(setOt).catch(() => setOt([]));
    apiFetch(`/organizations/${orgId}/attendance`).then(setAtt).catch(() => setAtt([]));
    apiFetch(`/organizations/${orgId}/leave-types`).then(setTypes).catch(() => setTypes([]));
    apiFetch(`/organizations/${orgId}/people`).then(setPeople).catch(() => {});
  }, [orgId]);
  useEffect(load, [load]);

  const loadBalances = useCallback((eng: string) => {
    setSelEng(eng); setCreditMsg("");
    if (eng) apiFetch(`/organizations/${orgId}/leave-balances?engagement_id=${eng}`).then(setBalances).catch(() => setBalances([]));
    else setBalances([]);
  }, [orgId]);

  async function addType() {
    if (!newType.name) return;
    await apiFetch(`/organizations/${orgId}/leave-types`, { method: "POST", body: JSON.stringify({ name: newType.name, default_credits: Number(newType.default_credits || 0) }) });
    setNewType({ name: "", default_credits: "" }); load();
  }
  async function saveTypeCredits(t: any, credits: string) {
    await apiFetch(`/organizations/${orgId}/leave-types/${t.id}`, { method: "PUT", body: JSON.stringify({ default_credits: Number(credits || 0) }) }); load();
  }
  async function delType(t: any) {
    if (!confirm(`Delete leave type "${t.name}"?`)) return;
    await apiFetch(`/organizations/${orgId}/leave-types/${t.id}`, { method: "DELETE" }); load();
  }
  async function saveBalance(leaveType: string, credits: string) {
    await apiFetch(`/organizations/${orgId}/leave-balances`, { method: "POST", body: JSON.stringify({ engagement_id: Number(selEng), leave_type: leaveType, credits: Number(credits || 0) }) });
    setCreditMsg("Saved."); loadBalances(selEng);
  }

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

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="card p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Leave Types &amp; Default Credits</div>
          <div className="p-4">
            <div className="mb-3 flex gap-2">
              <input className="input" placeholder="Type name (e.g. Vacation)" value={newType.name} onChange={(e) => setNewType({ ...newType, name: e.target.value })} />
              <input className="input max-w-[110px]" type="number" placeholder="Credits" value={newType.default_credits} onChange={(e) => setNewType({ ...newType, default_credits: e.target.value })} />
              <button className="btn-primary whitespace-nowrap" onClick={addType}>Add</button>
            </div>
            <table className="min-w-full text-sm"><tbody className="divide-y divide-slate-100">
              {types.map((t) => (
                <tr key={t.id}>
                  <td className="py-2">{t.name}</td>
                  <td className="py-2 text-right"><input key={`${t.id}-${t.default_credits}`} type="number" className="input max-w-[90px]" defaultValue={t.default_credits} onBlur={(e) => saveTypeCredits(t, e.target.value)} /></td>
                  <td className="py-2 text-right"><button className="text-red-600 hover:underline" onClick={() => delType(t)}>Delete</button></td>
                </tr>
              ))}
              {types.length === 0 ? <tr><td colSpan={3} className="py-4 text-center text-slate-400">No leave types yet — add Vacation, Sick, Emergency, Birthday…</td></tr> : null}
            </tbody></table>
            <p className="mt-2 text-[11px] text-slate-400">Default credits apply to everyone unless overridden per employee. Approved leave auto-deducts from the balance.</p>
          </div>
        </div>

        <div className="card p-0">
          <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Per-Employee Leave Credits</div>
          <div className="p-4">
            <select className="input mb-3" value={selEng} onChange={(e) => loadBalances(e.target.value)}>
              <option value="">Select employee…</option>
              {people.map((p) => <option key={p.engagement_id} value={p.engagement_id}>{p.full_name}</option>)}
            </select>
            {creditMsg ? <div className="mb-2 text-xs text-emerald-700">{creditMsg}</div> : null}
            {selEng ? (
              <table className="min-w-full text-sm">
                <thead><tr className="text-left text-xs uppercase text-slate-400"><th className="py-1">Type</th><th className="text-right">Credits</th><th className="text-right">Used</th><th className="text-right">Remaining</th></tr></thead>
                <tbody className="divide-y divide-slate-100">
                  {balances.map((b) => (
                    <tr key={b.leave_type}>
                      <td className="py-2">{b.leave_type}</td>
                      <td className="py-2 text-right"><input key={`${b.leave_type}-${b.credits}`} type="number" className="input max-w-[90px]" defaultValue={b.credits} onBlur={(e) => saveBalance(b.leave_type, e.target.value)} /></td>
                      <td className="py-2 text-right">{b.used}</td>
                      <td className="py-2 text-right font-medium">{b.remaining}</td>
                    </tr>
                  ))}
                  {balances.length === 0 ? <tr><td colSpan={4} className="py-4 text-center text-slate-400">Define leave types first.</td></tr> : null}
                </tbody>
              </table>
            ) : <p className="text-xs text-slate-400">Pick an employee to view and set their credits. Blank uses the type default.</p>}
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
