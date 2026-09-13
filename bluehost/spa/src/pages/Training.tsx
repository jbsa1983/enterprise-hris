import { useCallback, useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch, apiOpen } from "@/lib/api";

const today = () => new Date().toISOString().slice(0, 10);

export default function TrainingPage() {
  const orgId = Number(useParams().orgId);
  const [courses, setCourses] = useState<any[]>([]);
  const [people, setPeople] = useState<any[]>([]);
  const [rows, setRows] = useState<any[]>([]);
  const [course, setCourse] = useState({ title: "", category: "", provider: "", points: "" });
  const [assignForm, setAssignForm] = useState<any>({ engagement_id: "", course_id: "", due_date: "" });
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const load = useCallback(() => {
    apiFetch(`/organizations/${orgId}/training/courses`).then(setCourses).catch(() => {});
    apiFetch(`/organizations/${orgId}/training/assignments`).then(setRows).catch(() => {});
    apiFetch(`/organizations/${orgId}/people`).then(setPeople).catch(() => {});
  }, [orgId]);
  useEffect(load, [load]);

  async function addCourse() {
    if (!course.title) return;
    setErr(""); setMsg("");
    try { await apiFetch(`/organizations/${orgId}/training/courses`, { method: "POST", body: JSON.stringify({ ...course, points: Number(course.points || 0) }) }); setCourse({ title: "", category: "", provider: "", points: "" }); setMsg("Course added."); load(); }
    catch (e: any) { setErr(e.message); }
  }
  async function assign() {
    if (!assignForm.engagement_id || !assignForm.course_id) { setErr("Pick an employee and a course."); return; }
    setErr(""); setMsg("");
    try {
      await apiFetch(`/organizations/${orgId}/training/assignments`, { method: "POST", body: JSON.stringify({
        engagement_id: Number(assignForm.engagement_id), course_id: Number(assignForm.course_id), due_date: assignForm.due_date || undefined,
      }) });
      setAssignForm({ engagement_id: "", course_id: "", due_date: "" }); setMsg("Training assigned — the employee has been notified."); load();
    } catch (e: any) { setErr(e.message); }
  }
  async function markComplete(t: any) {
    if (!window.confirm(`Mark "${t.course_title}" as completed for ${t.employee}?`)) return;
    await apiFetch(`/organizations/${orgId}/training/assignments/${t.id}/complete`, { method: "POST", body: JSON.stringify({}) }); load();
  }
  async function reopen(t: any) {
    await apiFetch(`/organizations/${orgId}/training/assignments/${t.id}/reopen`, { method: "POST" }); load();
  }
  async function removeAssignment(t: any) {
    if (!window.confirm(`Delete this training record for ${t.employee}? This can't be undone.`)) return;
    await apiFetch(`/organizations/${orgId}/training/assignments/${t.id}`, { method: "DELETE" }); load();
  }

  function statusBadge(t: any) {
    if (t.status === "COMPLETED") return <span className="badge bg-emerald-100 text-emerald-700">Completed</span>;
    if (t.due_date && t.due_date < today()) return <span className="badge bg-red-100 text-red-700">Overdue</span>;
    return <span className="badge bg-amber-100 text-amber-700">Assigned</span>;
  }

  return (
    <AppShell orgId={orgId}>
      <div className="mb-4">
        <h1 className="text-lg font-semibold text-slate-900">Training</h1>
        <p className="text-sm text-slate-500">Assign courses with a deadline. Staff see them in My Self-Service and submit a certificate to complete.</p>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        {/* Assign */}
        <div className="card lg:col-span-2">
          <div className="mb-3 text-sm font-medium text-slate-700">Assign a training</div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div className="sm:col-span-2">
              <label className="mb-1 block text-xs text-slate-500">Employee</label>
              <select className="input" value={assignForm.engagement_id} onChange={(e) => setAssignForm({ ...assignForm, engagement_id: e.target.value })}>
                <option value="">Select…</option>
                {people.map((p) => <option key={p.engagement_id} value={p.engagement_id}>{p.full_name}</option>)}
              </select>
            </div>
            <div className="sm:col-span-2">
              <label className="mb-1 block text-xs text-slate-500">Course</label>
              <select className="input" value={assignForm.course_id} onChange={(e) => setAssignForm({ ...assignForm, course_id: e.target.value })}>
                <option value="">Select…</option>
                {courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
              </select>
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-500">Due date (timeline)</label>
              <input type="date" className="input" value={assignForm.due_date} onChange={(e) => setAssignForm({ ...assignForm, due_date: e.target.value })} />
            </div>
            <div className="flex items-end"><button className="btn-primary" onClick={assign}>Assign</button></div>
          </div>
        </div>
        {/* Courses */}
        <div className="card">
          <div className="mb-3 text-sm font-medium text-slate-700">Courses ({courses.length})</div>
          <div className="mb-3 max-h-40 overflow-y-auto text-sm">
            {courses.map((c) => <div key={c.id} className="border-b border-slate-100 py-1.5"><span className="font-medium">{c.title}</span>{c.provider ? <span className="text-slate-400"> · {c.provider}</span> : null}{Number(c.points) > 0 ? <span className="text-xs text-slate-400"> · {c.points} pts</span> : null}</div>)}
            {courses.length === 0 ? <p className="text-slate-400">No courses yet.</p> : null}
          </div>
          <div className="grid gap-2">
            <input className="input" placeholder="Course title" value={course.title} onChange={(e) => setCourse({ ...course, title: e.target.value })} />
            <div className="grid grid-cols-3 gap-2">
              <input className="input" placeholder="Category" value={course.category} onChange={(e) => setCourse({ ...course, category: e.target.value })} />
              <input className="input" placeholder="Provider" value={course.provider} onChange={(e) => setCourse({ ...course, provider: e.target.value })} />
              <input className="input" type="number" placeholder="Points" value={course.points} onChange={(e) => setCourse({ ...course, points: e.target.value })} />
            </div>
            <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50" onClick={addCourse}>+ Add course</button>
          </div>
        </div>
      </div>

      <div className="card p-0 overflow-x-auto">
        <div className="border-b border-slate-100 px-4 py-3 text-sm font-medium">Assignments ({rows.length})</div>
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50"><tr className="text-left text-xs uppercase text-slate-500">
            <th className="px-4 py-3">Employee</th><th className="px-4 py-3">Course</th><th className="px-4 py-3 text-right">Pts</th><th className="px-4 py-3">Due</th>
            <th className="px-4 py-3">Status</th><th className="px-4 py-3">Certificate</th><th className="px-4 py-3"></th>
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((t) => (
              <tr key={t.id}>
                <td className="px-4 py-2">{t.employee}</td>
                <td className="px-4 py-2">{t.course_title}{t.provider ? <span className="text-xs text-slate-400"> · {t.provider}</span> : null}{t.source === "SELF" ? <span className="ml-1 badge bg-slate-100 text-slate-500">Self-added</span> : null}</td>
                <td className="px-4 py-2 text-right">{Number(t.points) > 0 ? t.points : "—"}</td>
                <td className="px-4 py-2 text-slate-600">{t.due_date || "—"}</td>
                <td className="px-4 py-2">{statusBadge(t)}{t.completed_date ? <span className="ml-1 text-xs text-slate-400">{t.completed_date}</span> : null}</td>
                <td className="px-4 py-2">{t.has_certificate ? <button className="text-brand-700 hover:underline" onClick={() => apiOpen(`/organizations/${orgId}/training/assignments/${t.id}/certificate`)}>View</button> : <span className="text-slate-300">—</span>}</td>
                <td className="px-4 py-2 text-right space-x-2">
                  {t.status !== "COMPLETED" ? <button className="text-emerald-700 hover:underline" onClick={() => markComplete(t)}>Complete</button>
                    : <button className="text-slate-500 hover:underline" onClick={() => reopen(t)}>Reopen</button>}
                  <button className="text-red-600 hover:underline" onClick={() => removeAssignment(t)}>Delete</button>
                </td>
              </tr>
            ))}
            {rows.length === 0 ? <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-400">No training assigned yet.</td></tr> : null}
          </tbody>
        </table>
      </div>
    </AppShell>
  );
}
