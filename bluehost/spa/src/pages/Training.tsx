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
  const [selEmp, setSelEmp] = useState<Set<number>>(new Set());
  const [selCourse, setSelCourse] = useState<Set<number>>(new Set());
  const [q, setQ] = useState("");
  const [due, setDue] = useState("");
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");

  const load = useCallback(() => {
    apiFetch(`/organizations/${orgId}/training/courses`).then(setCourses).catch(() => {});
    apiFetch(`/organizations/${orgId}/training/assignments`).then(setRows).catch(() => {});
    apiFetch(`/organizations/${orgId}/people`).then(setPeople).catch(() => {});
  }, [orgId]);
  useEffect(load, [load]);

  const filteredPeople = people.filter((p) => p.full_name.toLowerCase().includes(q.toLowerCase()));
  const toggle = (set: Set<number>, id: number) => { const n = new Set(set); n.has(id) ? n.delete(id) : n.add(id); return n; };

  async function addCourse() {
    if (!course.title) return;
    setErr(""); setMsg("");
    try { await apiFetch(`/organizations/${orgId}/training/courses`, { method: "POST", body: JSON.stringify({ ...course, points: Number(course.points || 0) }) }); setCourse({ title: "", category: "", provider: "", points: "" }); setMsg("Course added."); load(); }
    catch (e: any) { setErr(e.message); }
  }
  async function assign() {
    if (!selEmp.size || !selCourse.size) { setErr("Select at least one employee and one course."); return; }
    setErr(""); setMsg("");
    try {
      const r = await apiFetch<{ created: number }>(`/organizations/${orgId}/training/assign-bulk`, { method: "POST", body: JSON.stringify({ engagement_ids: [...selEmp], course_ids: [...selCourse], due_date: due || undefined }) });
      setMsg(`Assigned — created ${r.created} training record(s). Employees have been notified.`);
      setSelEmp(new Set()); setSelCourse(new Set()); setDue(""); load();
    } catch (e: any) { setErr(e.message); }
  }
  async function markComplete(t: any) {
    if (!window.confirm(`Mark "${t.course_title}" as completed for ${t.employee}?`)) return;
    await apiFetch(`/organizations/${orgId}/training/assignments/${t.id}/complete`, { method: "POST", body: JSON.stringify({}) }); load();
  }
  async function reopen(t: any) { await apiFetch(`/organizations/${orgId}/training/assignments/${t.id}/reopen`, { method: "POST" }); load(); }
  async function verify(t: any) { await apiFetch(`/organizations/${orgId}/training/assignments/${t.id}/verify`, { method: "POST" }); setMsg(`Approved ${t.points} points for ${t.employee}.`); load(); }
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
        <p className="text-sm text-slate-500">Pick employees and course(s), set a deadline, and assign. Staff see them in My Self-Service and submit a certificate to complete.</p>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="mb-4 grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Employees */}
        <div className="card">
          <div className="mb-2 flex items-center justify-between">
            <div className="text-sm font-medium text-slate-700">1 · Choose employees</div>
            <button className="text-xs text-blue-600 hover:underline" onClick={() => setSelEmp(selEmp.size === filteredPeople.length && filteredPeople.length > 0 ? new Set() : new Set(filteredPeople.map((p) => p.engagement_id)))}>
              {selEmp.size === filteredPeople.length && filteredPeople.length > 0 ? "Clear all" : "Select all"}
            </button>
          </div>
          <input className="input mb-2" placeholder="Search employees…" value={q} onChange={(e) => setQ(e.target.value)} />
          <div className="max-h-60 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
            {filteredPeople.map((p) => (
              <label key={p.engagement_id} className="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm hover:bg-slate-50">
                <input type="checkbox" checked={selEmp.has(p.engagement_id)} onChange={() => setSelEmp(toggle(selEmp, p.engagement_id))} />
                <span>{p.full_name} <span className="text-xs text-slate-400">· {p.engagement_type}</span></span>
              </label>
            ))}
            {filteredPeople.length === 0 ? <p className="px-3 py-3 text-sm text-slate-400">No people match.</p> : null}
          </div>
          <p className="mt-1 text-xs text-slate-400">{selEmp.size} selected</p>
        </div>

        {/* Courses */}
        <div className="card">
          <div className="mb-2 text-sm font-medium text-slate-700">2 · Choose course(s) <span className="text-xs font-normal text-slate-400">({courses.length} available)</span></div>
          <div className="max-h-44 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
            {courses.map((c) => (
              <label key={c.id} className="flex cursor-pointer items-center justify-between gap-2 px-3 py-1.5 text-sm hover:bg-slate-50">
                <span className="flex items-center gap-2"><input type="checkbox" checked={selCourse.has(c.id)} onChange={() => setSelCourse(toggle(selCourse, c.id))} /> {c.title}{c.provider ? <span className="text-xs text-slate-400"> · {c.provider}</span> : null}</span>
                {Number(c.points) > 0 ? <span className="text-xs text-slate-400">{c.points} pts</span> : null}
              </label>
            ))}
            {courses.length === 0 ? <p className="px-3 py-3 text-sm text-slate-400">No courses yet — add one below.</p> : null}
          </div>
          <p className="mt-1 mb-2 text-xs text-slate-400">{selCourse.size} selected</p>
          <div className="rounded-lg border border-dashed border-slate-300 p-2">
            <div className="mb-1 text-xs font-medium text-slate-500">Add a course</div>
            <input className="input mb-2" placeholder="Course title" value={course.title} onChange={(e) => setCourse({ ...course, title: e.target.value })} />
            <div className="grid grid-cols-3 gap-2">
              <input className="input" placeholder="Category" value={course.category} onChange={(e) => setCourse({ ...course, category: e.target.value })} />
              <input className="input" placeholder="Provider" value={course.provider} onChange={(e) => setCourse({ ...course, provider: e.target.value })} />
              <input className="input" type="number" placeholder="Points" value={course.points} onChange={(e) => setCourse({ ...course, points: e.target.value })} />
            </div>
            <button className="mt-2 rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50" onClick={addCourse}>+ Add course</button>
          </div>
        </div>
      </div>

      {/* Assign bar */}
      <div className="card mb-6 flex flex-wrap items-end gap-4">
        <div>
          <label className="mb-1 block text-xs text-slate-500">3 · Due date (timeline, optional)</label>
          <input type="date" className="input max-w-[180px]" value={due} onChange={(e) => setDue(e.target.value)} />
        </div>
        <button className="btn-primary" disabled={!selEmp.size || !selCourse.size} onClick={assign}>
          Assign {selCourse.size || ""} course{selCourse.size === 1 ? "" : "s"} to {selEmp.size || ""} employee{selEmp.size === 1 ? "" : "s"}
        </button>
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
                <td className="px-4 py-2 text-right">{Number(t.points) > 0 ? <>{t.points}{t.source === "SELF" && !t.verified ? <span className="text-xs text-amber-600"> ⏳</span> : null}</> : "—"}</td>
                <td className="px-4 py-2 text-slate-600">{t.due_date || "—"}</td>
                <td className="px-4 py-2">{statusBadge(t)}{t.source === "SELF" && !t.verified ? <span className="ml-1 badge bg-amber-100 text-amber-700">Points pending</span> : null}{t.completed_date ? <span className="ml-1 text-xs text-slate-400">{t.completed_date}</span> : null}</td>
                <td className="px-4 py-2">{t.has_certificate ? <button className="text-brand-700 hover:underline" onClick={() => apiOpen(`/organizations/${orgId}/training/assignments/${t.id}/certificate`)}>View</button> : <span className="text-slate-300">—</span>}</td>
                <td className="px-4 py-2 text-right space-x-2">
                  {t.source === "SELF" && !t.verified ? <button className="text-emerald-700 hover:underline" onClick={() => verify(t)}>Approve pts</button> : null}
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
