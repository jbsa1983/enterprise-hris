
import { useCallback, useEffect, useRef, useState } from "react";
import { useParams, useSearchParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch, apiDownload, apiUpload } from "@/lib/api";
import { peso, statusColor } from "@/lib/format";
import type { EngagementRow } from "@/lib/types";

// --- 201-file documents (defined at module scope so typing in the upload
//     fields never remounts the component / loses focus) --------------------
const DOC_CATEGORIES = ["Contract", "Government ID", "Resume / CV", "Certificate",
  "Clearance", "Payroll / BIR form", "Medical", "Photo", "Other"];

// Cached once per session so opening a person doesn't refetch permissions.
let _permsCache: { perms: string[]; superadmin: boolean } | null = null;

function humanSize(n: number): string {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`;
  return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

interface EmpDoc { id: number; category: string | null; title: string; file_name: string; size_bytes: number; created_at: string; }

function DocumentsSection({ orgId, engagementId }: { orgId: number; engagementId: number }) {
  const [docs, setDocs] = useState<EmpDoc[]>([]);
  const [title, setTitle] = useState("");
  const [category, setCategory] = useState(DOC_CATEGORIES[0]);
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");
  const [canUpload, setCanUpload] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const reload = useCallback(() => {
    apiFetch<EmpDoc[]>(`/organizations/${orgId}/people/${engagementId}/documents`).then(setDocs).catch(() => {});
  }, [orgId, engagementId]);

  useEffect(() => {
    reload();
    const apply = (c: { perms: string[]; superadmin: boolean }) => setCanUpload(c.superadmin || c.perms.includes("documents.upload"));
    if (_permsCache) apply(_permsCache);
    else apiFetch<any>("/auth/me").then((u) => { _permsCache = { perms: u.permissions || [], superadmin: !!u.is_superadmin }; apply(_permsCache); }).catch(() => {});
  }, [reload]);

  async function upload() {
    if (!file) { setErr("Choose a file first."); return; }
    setBusy(true); setErr("");
    try {
      const fd = new FormData();
      fd.append("file", file);
      fd.append("title", title || file.name);
      fd.append("category", category);
      await apiUpload(`/organizations/${orgId}/people/${engagementId}/documents`, fd);
      setTitle(""); setFile(null); if (fileRef.current) fileRef.current.value = "";
      reload();
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }
  async function remove(id: number) {
    if (!window.confirm("Delete this document permanently?")) return;
    setErr("");
    try { await apiFetch(`/organizations/${orgId}/documents/${id}`, { method: "DELETE" }); reload(); }
    catch (e: any) { setErr(e.message); }
  }

  return (
    <div>
      <div className="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">201 File — Documents</div>
      {docs.length === 0 ? (
        <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-400">No documents uploaded yet.</p>
      ) : (
        <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
          {docs.map((d) => (
            <li key={d.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
              <div className="min-w-0">
                <div className="truncate font-medium text-slate-800">{d.title}</div>
                <div className="text-xs text-slate-400">
                  {d.category ? <span className="mr-2 rounded bg-slate-100 px-1.5 py-0.5">{d.category}</span> : null}
                  <span className="font-mono">{d.file_name}</span> · {humanSize(d.size_bytes)} · {d.created_at?.slice(0, 10)}
                </div>
              </div>
              <div className="flex flex-shrink-0 gap-3">
                <button className="text-brand-700 hover:underline" onClick={() => apiDownload(`/organizations/${orgId}/documents/${d.id}/download`, d.file_name)}>Download</button>
                {canUpload ? <button className="text-red-600 hover:underline" onClick={() => remove(d.id)}>Delete</button> : null}
              </div>
            </li>
          ))}
        </ul>
      )}
      {canUpload ? (
        <div className="mt-3 rounded-lg border border-dashed border-slate-300 p-3">
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            <div>
              <label className="mb-1 block text-xs text-slate-500">Category</label>
              <select className="input" value={category} onChange={(e) => setCategory(e.target.value)}>
                {DOC_CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
            <div className="sm:col-span-2">
              <label className="mb-1 block text-xs text-slate-500">Title (optional)</label>
              <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="e.g. Employment contract 2026" />
            </div>
          </div>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <input ref={fileRef} type="file" className="text-sm"
              accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.doc,.docx,.xls,.xlsx,.txt"
              onChange={(e) => setFile(e.target.files?.[0] || null)} />
            <button className="btn-primary" onClick={upload} disabled={busy || !file}>{busy ? "Uploading…" : "Upload"}</button>
          </div>
          <p className="mt-1 text-xs text-slate-400">Max 10 MB per file. Allowed: PDF, images, Word, Excel, text.</p>
          {err ? <div className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}
        </div>
      ) : err ? <div className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}
    </div>
  );
}

const ENG_TYPES = ["REGULAR", "PROBATIONARY", "PROJECT_BASED", "FIXED_TERM", "DAILY_PAID",
  "HOURLY_PAID", "PART_TIME", "CONSULTANT_INDIVIDUAL", "CONSULTANT_COMPANY", "CONTRACTOR", "OJT", "TRAINEE"];

const EMPTY: any = {
  first_name: "", last_name: "", middle_name: "", email: "", mobile: "",
  tin: "", sss_number: "", philhealth_number: "", pagibig_number: "",
  bank_name: "", bank_account_number: "", bank_account_name: "",
  engagement_type: "REGULAR", employee_number: "", salary_basis: "MONTHLY", base_rate: "",
  department_id: "", position_id: "", start_date: "",
};

export default function PeoplePage() {
  const orgId = Number(useParams().orgId);
  const type = useSearchParams().get("type") || "people";
  const [rows, setRows] = useState<EngagementRow[]>([]);
  const [depts, setDepts] = useState<any[]>([]);
  const [positions, setPositions] = useState<any[]>([]);
  const [q, setQ] = useState("");
  const [editing, setEditing] = useState<null | "new" | number>(null); // engagement_id when editing
  const [form, setForm] = useState<any>({ ...EMPTY });
  const [err, setErr] = useState("");
  const [msg, setMsg] = useState("");

  const endpoint = type === "employees" ? "employees" : type === "consultants" ? "consultants" : "people";
  const title = type === "employees" ? "Employees" : type === "consultants" ? "Consultants" : "People";

  const load = useCallback(() => {
    apiFetch<EngagementRow[]>(`/organizations/${orgId}/${endpoint}`).then(setRows).catch((e) => setErr(e.message));
    apiFetch(`/organizations/${orgId}/departments`).then(setDepts).catch(() => {});
    apiFetch(`/organizations/${orgId}/positions`).then(setPositions).catch(() => {});
  }, [orgId, endpoint]);
  useEffect(load, [load]);

  function openCreate() { setForm({ ...EMPTY }); setEditing("new"); setErr(""); }
  async function openEdit(engagementId: number) {
    setErr("");
    const d: any = await apiFetch(`/organizations/${orgId}/people/${engagementId}`);
    setForm({
      ...EMPTY, ...d.person,
      engagement_type: d.engagement.engagement_type || "REGULAR",
      employee_number: d.engagement.employee_number || "",
      salary_basis: d.engagement.salary_basis || "MONTHLY",
      base_rate: d.engagement.base_rate ?? "",
      department_id: d.engagement.department_id ?? "",
      position_id: d.engagement.position_id ?? "",
      start_date: d.engagement.start_date || "",
    });
    setEditing(engagementId);
  }

  function personPayload() {
    const p: any = {};
    ["first_name", "last_name", "middle_name", "email", "mobile", "tin", "sss_number",
     "philhealth_number", "pagibig_number", "bank_name", "bank_account_number", "bank_account_name"]
      .forEach((k) => { if (form[k] !== "" && form[k] != null) p[k] = form[k]; });
    return p;
  }
  function engagementPayload() {
    const e: any = {
      engagement_type: form.engagement_type, salary_basis: form.salary_basis,
    };
    if (form.employee_number) e.employee_number = form.employee_number;
    if (form.base_rate !== "") e.base_rate = Number(form.base_rate);
    if (form.department_id) e.department_id = Number(form.department_id);
    if (form.position_id) e.position_id = Number(form.position_id);
    if (form.start_date) e.start_date = form.start_date;
    return e;
  }

  async function save() {
    setErr("");
    try {
      if (editing === "new") {
        await apiFetch(`/organizations/${orgId}/people/create`, {
          method: "POST", body: JSON.stringify({ ...personPayload(), engagement: engagementPayload() }),
        });
        setMsg("Person added.");
      } else if (typeof editing === "number") {
        await apiFetch(`/organizations/${orgId}/people/${editing}`, {
          method: "PUT", body: JSON.stringify({ person: personPayload(), engagement: engagementPayload() }),
        });
        setMsg("Person updated.");
      }
      setEditing(null); load();
    } catch (e: any) { setErr(e.message); }
  }

  async function archive(engagementId: number) {
    if (!confirm("Archive (separate) this engagement?")) return;
    await apiFetch(`/organizations/${orgId}/people/${engagementId}/archive`, { method: "POST", body: JSON.stringify({ status: "SEPARATED" }) });
    load();
  }

  const filtered = rows.filter((r) =>
    r.full_name.toLowerCase().includes(q.toLowerCase()) ||
    (r.employee_number || "").toLowerCase().includes(q.toLowerCase()));

  const F = (k: string, label: string, extra: any = {}) => (
    <div><label className="mb-1 block text-xs text-slate-500">{label}</label>
      <input className="input" value={form[k] ?? ""} onChange={(e) => setForm({ ...form, [k]: e.target.value })} {...extra} /></div>
  );

  async function onImport(e: React.ChangeEvent<HTMLInputElement>) {
    const f = e.target.files?.[0];
    if (!f) return;
    setErr(""); setMsg("Uploading…");
    try {
      const fd = new FormData(); fd.append("file", f);
      const r = await apiUpload<any>(`/organizations/${orgId}/people/import`, fd);
      const issues = r.error_count ? ` · ${r.error_count} issue(s): ${(r.errors || []).slice(0, 3).join("; ")}` : "";
      setMsg(`Imported ${r.imported}, skipped ${r.skipped}${issues}`);
      load();
    } catch (er: any) { setErr(er.message); setMsg(""); }
    finally { e.target.value = ""; }
  }

  return (
    <AppShell orgId={orgId}>
      <div className="mb-4 flex items-center justify-between">
        <div><h1 className="text-lg font-semibold text-slate-900">{title}</h1>
          <p className="text-sm text-slate-500">{filtered.length} record(s)</p></div>
        <div className="flex flex-wrap gap-2">
          <input className="input max-w-xs" placeholder="Search…" value={q} onChange={(e) => setQ(e.target.value)} />
          <button className="whitespace-nowrap rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
            onClick={() => apiDownload(`/organizations/${orgId}/people/template`, "employees_template.csv")}>Template</button>
          <label className="cursor-pointer whitespace-nowrap rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
            Import CSV
            <input type="file" accept=".csv" className="hidden" onChange={onImport} />
          </label>
          <button className="btn-primary whitespace-nowrap" onClick={openCreate}>+ Add Person</button>
        </div>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="card p-0 overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50"><tr className="text-left text-xs uppercase text-slate-500">
            <th className="px-4 py-3">Emp. No.</th><th className="px-4 py-3">Name</th><th className="px-4 py-3">Type</th>
            <th className="px-4 py-3">Status</th><th className="px-4 py-3 text-right">Base Rate</th><th className="px-4 py-3"></th>
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {filtered.map((r) => (
              <tr key={r.engagement_id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-mono text-xs text-slate-600">{r.employee_number || "—"}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{r.full_name}</td>
                <td className="px-4 py-3 text-slate-600">{r.engagement_type}</td>
                <td className="px-4 py-3"><span className={`badge ${statusColor(r.status)}`}>{r.status}</span></td>
                <td className="px-4 py-3 text-right">{peso(r.base_rate)}</td>
                <td className="px-4 py-3 text-right space-x-2">
                  <button className="text-brand-700 hover:underline" onClick={() => openEdit(r.engagement_id)}>Edit</button>
                  {r.status === "ACTIVE" ? <button className="text-red-600 hover:underline" onClick={() => archive(r.engagement_id)}>Archive</button> : null}
                </td>
              </tr>
            ))}
            {filtered.length === 0 ? <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-400">No records.</td></tr> : null}
          </tbody>
        </table>
      </div>

      {editing !== null ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setEditing(null)}>
          <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 className="mb-4 text-base font-semibold">{editing === "new" ? "Add Person" : "Edit Person"}</h2>
            <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Personal</div>
            <div className="grid grid-cols-2 gap-3">
              {F("first_name", "First name *")}{F("last_name", "Last name *")}
              {F("middle_name", "Middle name")}{F("email", "Email")}
              {F("mobile", "Mobile")}{F("tin", "TIN")}
              {F("sss_number", "SSS No.")}{F("philhealth_number", "PhilHealth No.")}
              {F("pagibig_number", "Pag-IBIG No.")}{F("bank_name", "Bank")}
              {F("bank_account_number", "Bank account no.")}{F("bank_account_name", "Account name")}
            </div>
            <div className="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">Engagement</div>
            <div className="grid grid-cols-2 gap-3">
              <div><label className="mb-1 block text-xs text-slate-500">Type</label>
                <select className="input" value={form.engagement_type} onChange={(e) => setForm({ ...form, engagement_type: e.target.value })}>
                  {ENG_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                </select></div>
              {F("employee_number", "Employee / contract no.")}
              <div><label className="mb-1 block text-xs text-slate-500">Salary basis</label>
                <select className="input" value={form.salary_basis} onChange={(e) => setForm({ ...form, salary_basis: e.target.value })}>
                  {["MONTHLY", "DAILY", "HOURLY"].map((t) => <option key={t} value={t}>{t}</option>)}
                </select></div>
              {F("base_rate", "Base rate", { type: "number" })}
              <div><label className="mb-1 block text-xs text-slate-500">Department</label>
                <select className="input" value={form.department_id} onChange={(e) => setForm({ ...form, department_id: e.target.value })}>
                  <option value="">—</option>{depts.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </select></div>
              <div><label className="mb-1 block text-xs text-slate-500">Position</label>
                <select className="input" value={form.position_id} onChange={(e) => setForm({ ...form, position_id: e.target.value })}>
                  <option value="">—</option>{positions.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
                </select></div>
              {F("start_date", "Start date", { type: "date" })}
            </div>
            {typeof editing === "number" ? <DocumentsSection orgId={orgId} engagementId={editing} /> : null}
            {err ? <div className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}
            <div className="mt-5 flex justify-end gap-2">
              <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm" onClick={() => setEditing(null)}>Cancel</button>
              <button className="btn-primary" onClick={save}>Save</button>
            </div>
          </div>
        </div>
      ) : null}
    </AppShell>
  );
}
