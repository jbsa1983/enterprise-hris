import { useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch, apiOpen } from "@/lib/api";

type Tab = "sss" | "philhealth" | "pagibig" | "mp2";
interface Month { year: number; month: number; label: string; }

const fmt = (n: any) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
function generate(orgId: number, form: string, payload: any) {
  return apiOpen(`/organizations/${orgId}/agency/generate`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ form, ...payload }) });
}

const TABS: { id: Tab; label: string; sub: string; form: string; ec: boolean; mp2: boolean; idLabel: string; nameLabel: string; unit: string }[] = [
  { id: "sss", label: "SSS R-3", sub: "Contribution collection list", form: "r3", ec: true, mp2: false, idLabel: "SSS no.", nameLabel: "Employee", unit: "members" },
  { id: "philhealth", label: "PhilHealth RF-1", sub: "Employer remittance report", form: "rf1", ec: false, mp2: false, idLabel: "PhilHealth no.", nameLabel: "Employee", unit: "members" },
  { id: "pagibig", label: "Pag-IBIG MCRF", sub: "Compulsory contribution remittance", form: "mcrf", ec: false, mp2: false, idLabel: "Pag-IBIG MID", nameLabel: "Employee", unit: "members" },
  { id: "mp2", label: "Pag-IBIG MP2", sub: "MP2 savings — employee + employer", form: "mp2", ec: false, mp2: false, idLabel: "MP2 account no.", nameLabel: "Member", unit: "accounts" },
];

export default function AgencyFormsPage() {
  const orgId = Number(useParams().orgId);
  const [months, setMonths] = useState<Month[]>([]);
  const [sel, setSel] = useState("");
  const [data, setData] = useState<any>(null);
  const [tab, setTab] = useState<Tab>("sss");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");

  useEffect(() => {
    apiFetch<{ months: Month[] }>(`/organizations/${orgId}/agency/months`).then((d) => setMonths(d.months)).catch((e) => setErr(e.message));
  }, [orgId]);

  function loadMonth(value: string) {
    setSel(value); setErr(""); setData(null);
    if (!value) return;
    const [year, month] = value.split("-");
    setBusy(true);
    apiFetch<any>(`/organizations/${orgId}/agency/data?year=${year}&month=${month}`).then(setData).catch((e) => setErr(e.message)).finally(() => setBusy(false));
  }

  const cur = TABS.find((t) => t.id === tab)!;
  const lines: any[] = data ? data[tab] || [] : [];
  const totals = data ? data.totals[tab] : null;

  function onGenerate() {
    if (!data) return;
    generate(orgId, cur.form, { month_name: data.month_name, year: data.year, lines }).catch((e) => setErr(e.message));
  }

  return (
    <AppShell orgId={orgId}>
      <div className="mb-4">
        <h1 className="text-lg font-semibold text-slate-900">Statutory remittance forms</h1>
        <p className="text-sm text-slate-500">SSS R-3, PhilHealth RF-1, Pag-IBIG MCRF and Pag-IBIG MP2, generated from posted payroll. Employee shares come from payroll; employer shares are computed from the statutory rates (MP2 employer share comes from each MP2 account).</p>
      </div>
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="mb-4 flex flex-wrap gap-2">
        {TABS.map((t) => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className={`rounded-lg border px-3 py-2 text-left text-sm ${tab === t.id ? "border-geek-blue bg-geek-blue/10 text-geek-bluedark" : "border-slate-200 bg-white text-slate-600 hover:bg-slate-50"}`}>
            <div className="font-semibold">{t.label}</div><div className="text-xs opacity-70">{t.sub}</div>
          </button>
        ))}
      </div>

      <div className="card">
        <div className="mb-3 flex flex-wrap items-end gap-3">
          <div>
            <label className="mb-1 block text-xs text-slate-500">Contribution month</label>
            <select className="input min-w-[200px]" value={sel} onChange={(e) => loadMonth(e.target.value)}>
              <option value="">Choose a month…</option>
              {months.map((m) => <option key={`${m.year}-${m.month}`} value={`${m.year}-${m.month}`}>{m.label}</option>)}
            </select>
          </div>
          <button className="btn-primary" disabled={!data || lines.length === 0} onClick={onGenerate}>Generate {cur.label}</button>
        </div>
        {months.length === 0 && !err ? <p className="text-sm text-slate-400">No posted payroll yet — the figures come from posted payroll.</p> : null}
        {busy ? <p className="text-sm text-slate-400">Loading…</p> : null}

        {data ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="py-2 pr-2">{cur.idLabel}</th><th className="py-2 pr-2">{cur.nameLabel}</th>
                  <th className="py-2 pr-2 text-right">Employee</th><th className="py-2 pr-2 text-right">Employer</th>
                  {cur.ec ? <th className="py-2 pr-2 text-right">EC</th> : null}
                  <th className="py-2 pr-2 text-right">Total</th>
                  {cur.mp2 ? <th className="py-2 pr-2 text-right">MP2</th> : null}
                </tr>
              </thead>
              <tbody>
                {lines.map((l, i) => (
                  <tr key={i} className="border-b border-slate-100">
                    <td className="py-1.5 pr-2 font-mono text-xs text-slate-500">{l.id_number || "—"}</td>
                    <td className="py-1.5 pr-2">{l.name}</td>
                    <td className="py-1.5 pr-2 text-right">{fmt(l.ee)}</td>
                    <td className="py-1.5 pr-2 text-right">{fmt(l.er)}</td>
                    {cur.ec ? <td className="py-1.5 pr-2 text-right">{fmt(l.ec)}</td> : null}
                    <td className="py-1.5 pr-2 text-right font-medium">{fmt(l.total)}</td>
                    {cur.mp2 ? <td className="py-1.5 pr-2 text-right">{fmt(l.mp2)}</td> : null}
                  </tr>
                ))}
                {lines.length === 0 ? <tr><td colSpan={cur.ec || cur.mp2 ? 6 : 5} className="py-6 text-center text-slate-400">No contributions for this month.</td></tr> : null}
              </tbody>
              {totals ? (
                <tfoot>
                  <tr className="font-semibold">
                    <td className="py-2 pr-2" colSpan={2}>{totals.count} {cur.unit}</td>
                    <td className="py-2 pr-2 text-right">₱ {fmt(totals.ee)}</td>
                    <td className="py-2 pr-2 text-right">₱ {fmt(totals.er)}</td>
                    {cur.ec ? <td className="py-2 pr-2 text-right">₱ {fmt(totals.ec)}</td> : null}
                    <td className="py-2 pr-2 text-right">₱ {fmt(totals.total)}</td>
                    {cur.mp2 ? <td className="py-2 pr-2 text-right">₱ {fmt(totals.mp2)}</td> : null}
                  </tr>
                </tfoot>
              ) : null}
            </table>
            <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Working copy — verify against the official SSS/PhilHealth/Pag-IBIG tables (incl. SSS EC &amp; WISP) before filing.</p>
          </div>
        ) : null}
      </div>
    </AppShell>
  );
}
