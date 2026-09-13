import { useCallback, useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch, apiOpen } from "@/lib/api";

type Tab = "1601c" | "0619e" | "2307" | "2316";
interface Month { year: number; month: number; label: string; }
interface Person { engagement_id: number; employee_number: string | null; name: string; type: string; }

const fmt = (n: any) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function generate(path: string, payload: any) {
  return apiOpen(path, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
}

export default function BirFormsPage() {
  const orgId = Number(useParams().orgId);
  const [tab, setTab] = useState<Tab>("1601c");
  const [months, setMonths] = useState<Month[]>([]);
  const [years, setYears] = useState<number[]>([]);
  const [err, setErr] = useState("");

  useEffect(() => {
    apiFetch<{ months: Month[]; years: number[] }>(`/organizations/${orgId}/bir/months`)
      .then((d) => { setMonths(d.months); setYears(d.years); })
      .catch((e) => setErr(e.message));
  }, [orgId]);

  const TABS: { id: Tab; label: string; sub: string }[] = [
    { id: "1601c", label: "1601-C", sub: "Compensation WT (monthly)" },
    { id: "0619e", label: "0619-E", sub: "Expanded WT (monthly)" },
    { id: "2307", label: "2307", sub: "Creditable tax certificate" },
    { id: "2316", label: "2316", sub: "Annual comp. certificate" },
  ];

  return (
    <AppShell orgId={orgId}>
      <div className="mb-4">
        <h1 className="text-lg font-semibold text-slate-900">BIR forms</h1>
        <p className="text-sm text-slate-500">Generate withholding-tax working copies from posted payroll. Review the figures, then file via eBIRForms / eFPS.</p>
      </div>
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="mb-4 flex flex-wrap gap-2">
        {TABS.map((t) => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className={`rounded-lg border px-3 py-2 text-left text-sm ${tab === t.id ? "border-geek-blue bg-geek-blue/10 text-geek-bluedark" : "border-slate-200 bg-white text-slate-600 hover:bg-slate-50"}`}>
            <div className="font-semibold">{t.label}</div>
            <div className="text-xs opacity-70">{t.sub}</div>
          </button>
        ))}
      </div>

      {months.length === 0 && !err ? (
        <div className="card text-sm text-slate-500">No posted payroll yet. Run payroll first — the figures on these forms come from posted payroll.</div>
      ) : null}

      {tab === "1601c" ? <MonthlyForm orgId={orgId} months={months} kind="1601c" /> : null}
      {tab === "0619e" ? <MonthlyForm orgId={orgId} months={months} kind="0619e" /> : null}
      {tab === "2307" ? <Form2307 orgId={orgId} years={years} /> : null}
      {tab === "2316" ? <Form2316 orgId={orgId} years={years} /> : null}
    </AppShell>
  );
}

// ---------- 1601-C and 0619-E share a monthly per-payee worksheet ----------
interface MLine {
  engagement_id: number; employee_number: string | null; name: string; tin: string;
  taxable?: number; withholding_tax?: number; income_payment?: number; ewt?: number; _inc: boolean;
}
function MonthlyForm({ orgId, months, kind }: { orgId: number; months: Month[]; kind: "1601c" | "0619e" }) {
  const [sel, setSel] = useState("");
  const [lines, setLines] = useState<MLine[]>([]);
  const [meta, setMeta] = useState<{ month_name: string; year: number } | null>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");
  const isComp = kind === "1601c";

  const load = useCallback((value: string) => {
    setSel(value); setErr(""); setLines([]); setMeta(null);
    if (!value) return;
    const [year, month] = value.split("-");
    setBusy(true);
    apiFetch<any>(`/organizations/${orgId}/bir/${kind}?year=${year}&month=${month}`)
      .then((d) => { setLines((d.lines || []).map((l: any) => ({ ...l, _inc: true }))); setMeta({ month_name: d.month_name, year: d.year }); })
      .catch((e) => setErr(e.message))
      .finally(() => setBusy(false));
  }, [orgId, kind]);

  const upd = (i: number, patch: Partial<MLine>) => setLines((ls) => ls.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));
  const included = lines.filter((l) => l._inc);
  const totBase = included.reduce((s, l) => s + Number((isComp ? l.taxable : l.income_payment) || 0), 0);
  const totTax = included.reduce((s, l) => s + Number((isComp ? l.withholding_tax : l.ewt) || 0), 0);

  function onGenerate() {
    if (!meta) return;
    const payload = isComp
      ? { month_name: meta.month_name, year: meta.year, lines: included.map((l) => ({ employee_number: l.employee_number, name: l.name, tin: l.tin, taxable: l.taxable, withholding_tax: l.withholding_tax })) }
      : { month_name: meta.month_name, year: meta.year, lines: included.map((l) => ({ name: l.name, tin: l.tin, income_payment: l.income_payment, ewt: l.ewt })) };
    generate(`/organizations/${orgId}/bir/${kind}/generate`, payload).catch((e) => setErr(e.message));
  }

  return (
    <div className="card">
      <div className="mb-3 flex flex-wrap items-end gap-3">
        <div>
          <label className="mb-1 block text-xs text-slate-500">Return month</label>
          <select className="input min-w-[200px]" value={sel} onChange={(e) => load(e.target.value)}>
            <option value="">Choose a month…</option>
            {months.map((m) => <option key={`${m.year}-${m.month}`} value={`${m.year}-${m.month}`}>{m.label}</option>)}
          </select>
        </div>
        <button className="btn-primary" disabled={!meta || included.length === 0} onClick={onGenerate}>Generate {isComp ? "1601-C" : "0619-E"}</button>
      </div>
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}
      {busy ? <p className="text-sm text-slate-400">Loading…</p> : null}

      {meta ? (
        <div className="overflow-x-auto">
          <p className="mb-2 text-xs text-slate-500">Uncheck anyone who shouldn't be included; amounts are editable before you generate.</p>
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="py-2 pr-2"></th>
                <th className="py-2 pr-2">{isComp ? "Employee" : "Payee"}</th>
                <th className="py-2 pr-2">TIN</th>
                <th className="py-2 pr-2 text-right">{isComp ? "Taxable comp." : "Income payment"}</th>
                <th className="py-2 pr-2 text-right">{isComp ? "Tax withheld" : "EWT withheld"}</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((l, i) => (
                <tr key={l.engagement_id} className={`border-b border-slate-100 ${l._inc ? "" : "opacity-40"}`}>
                  <td className="py-1 pr-2"><input type="checkbox" checked={l._inc} onChange={(e) => upd(i, { _inc: e.target.checked })} /></td>
                  <td className="py-1 pr-2"><span className="font-mono text-xs text-slate-400">{l.employee_number || "—"}</span> {l.name}</td>
                  <td className="py-1 pr-2 font-mono text-xs text-slate-500">{l.tin || "—"}</td>
                  <td className="py-1 pr-2 text-right">
                    <input className="input w-32 text-right" type="number" step="0.01" value={isComp ? l.taxable ?? 0 : l.income_payment ?? 0}
                      onChange={(e) => upd(i, isComp ? { taxable: parseFloat(e.target.value) || 0 } : { income_payment: parseFloat(e.target.value) || 0 })} />
                  </td>
                  <td className="py-1 pr-2 text-right">
                    <input className="input w-32 text-right" type="number" step="0.01" value={isComp ? l.withholding_tax ?? 0 : l.ewt ?? 0}
                      onChange={(e) => upd(i, isComp ? { withholding_tax: parseFloat(e.target.value) || 0 } : { ewt: parseFloat(e.target.value) || 0 })} />
                  </td>
                </tr>
              ))}
              {lines.length === 0 ? <tr><td colSpan={5} className="py-6 text-center text-slate-400">No {isComp ? "employees" : "consultants"} with withholding in this month.</td></tr> : null}
            </tbody>
            <tfoot>
              <tr className="font-semibold">
                <td></td><td className="py-2 pr-2">{included.length} included</td><td></td>
                <td className="py-2 pr-2 text-right">₱ {fmt(totBase)}</td>
                <td className="py-2 pr-2 text-right">₱ {fmt(totTax)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      ) : null}
    </div>
  );
}

// ---------- 2307 : per-payee creditable-tax certificate --------------------
function Form2307({ orgId, years }: { orgId: number; years: number[] }) {
  const [consultants, setConsultants] = useState<Person[]>([]);
  const [eng, setEng] = useState("");
  const [year, setYear] = useState("");
  const [rate, setRate] = useState("10");
  const [nature, setNature] = useState("Professional fees");
  const [atc, setAtc] = useState("WI010");
  const [data, setData] = useState<any>(null);
  const [items, setItems] = useState<{ month: number; label: string; income: number; _inc: boolean }[]>([]);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");

  useEffect(() => { apiFetch<Person[]>(`/organizations/${orgId}/bir/people?type=consultants`).then(setConsultants).catch((e) => setErr(e.message)); }, [orgId]);

  function loadData(e = eng, y = year) {
    setErr(""); setData(null); setItems([]);
    if (!e || !y) return;
    setBusy(true);
    apiFetch<any>(`/organizations/${orgId}/bir/2307?engagement_id=${e}&year=${y}`)
      .then((d) => { setData(d); setItems((d.months || []).filter((m: any) => m.income > 0).map((m: any) => ({ month: m.month, label: `${m.month_name} ${d.year}`, income: m.income, _inc: true }))); })
      .catch((er) => setErr(er.message)).finally(() => setBusy(false));
  }

  const r = parseFloat(rate) || 0;
  const included = items.filter((i) => i._inc);
  const totInc = included.reduce((s, i) => s + Number(i.income || 0), 0);
  const totTax = totInc * r / 100;

  function onGenerate() {
    if (!data) return;
    generate(`/organizations/${orgId}/bir/2307/generate`, {
      payee: data.payee, rate: r, nature, atc,
      items: included.map((i) => ({ label: i.label, income: i.income })),
    }).catch((e) => setErr(e.message));
  }

  return (
    <div className="card">
      <div className="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-4">
        <div>
          <label className="mb-1 block text-xs text-slate-500">Consultant / payee</label>
          <select className="input" value={eng} onChange={(e) => { setEng(e.target.value); loadData(e.target.value, year); }}>
            <option value="">Choose…</option>
            {consultants.map((c) => <option key={c.engagement_id} value={c.engagement_id}>{c.name}</option>)}
          </select>
        </div>
        <div>
          <label className="mb-1 block text-xs text-slate-500">Year</label>
          <select className="input" value={year} onChange={(e) => { setYear(e.target.value); loadData(eng, e.target.value); }}>
            <option value="">Choose…</option>
            {years.map((y) => <option key={y} value={y}>{y}</option>)}
          </select>
        </div>
        <div>
          <label className="mb-1 block text-xs text-slate-500">EWT rate</label>
          <select className="input" value={rate} onChange={(e) => setRate(e.target.value)}>
            <option value="5">5% (WI010)</option>
            <option value="10">10% (WI011)</option>
          </select>
        </div>
        <div>
          <label className="mb-1 block text-xs text-slate-500">ATC</label>
          <input className="input" value={atc} onChange={(e) => setAtc(e.target.value)} />
        </div>
      </div>
      <div className="mb-3">
        <label className="mb-1 block text-xs text-slate-500">Nature of income payment</label>
        <input className="input max-w-md" value={nature} onChange={(e) => setNature(e.target.value)} />
      </div>
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}
      {busy ? <p className="text-sm text-slate-400">Loading…</p> : null}

      {data ? (
        <div className="overflow-x-auto">
          <p className="mb-2 text-xs text-slate-500">Payee: <b>{data.payee.name}</b> · TIN {data.payee.tin || "—"}. Choose the months to certify (e.g. a quarter). Tax is computed at {r}%.</p>
          <table className="w-full text-sm">
            <thead><tr className="border-b text-left text-xs uppercase tracking-wide text-slate-400">
              <th className="py-2 pr-2"></th><th className="py-2 pr-2">Month</th><th className="py-2 pr-2 text-right">Income payment</th><th className="py-2 pr-2 text-right">Tax @ {r}%</th>
            </tr></thead>
            <tbody>
              {items.map((it, i) => (
                <tr key={it.month} className={`border-b border-slate-100 ${it._inc ? "" : "opacity-40"}`}>
                  <td className="py-1 pr-2"><input type="checkbox" checked={it._inc} onChange={(e) => setItems((xs) => xs.map((x, idx) => idx === i ? { ...x, _inc: e.target.checked } : x))} /></td>
                  <td className="py-1 pr-2">{it.label}</td>
                  <td className="py-1 pr-2 text-right">
                    <input className="input w-32 text-right" type="number" step="0.01" value={it.income}
                      onChange={(e) => setItems((xs) => xs.map((x, idx) => idx === i ? { ...x, income: parseFloat(e.target.value) || 0 } : x))} />
                  </td>
                  <td className="py-1 pr-2 text-right">₱ {fmt(it.income * r / 100)}</td>
                </tr>
              ))}
              {items.length === 0 ? <tr><td colSpan={4} className="py-6 text-center text-slate-400">No income payments for this payee in {year}.</td></tr> : null}
            </tbody>
            <tfoot><tr className="font-semibold">
              <td></td><td className="py-2 pr-2">Totals</td>
              <td className="py-2 pr-2 text-right">₱ {fmt(totInc)}</td><td className="py-2 pr-2 text-right">₱ {fmt(totTax)}</td>
            </tr></tfoot>
          </table>
          <div className="mt-3"><button className="btn-primary" disabled={included.length === 0} onClick={onGenerate}>Generate 2307</button></div>
        </div>
      ) : null}
    </div>
  );
}

// ---------- 2316 : annual compensation certificate -------------------------
function Form2316({ orgId, years }: { orgId: number; years: number[] }) {
  const [employees, setEmployees] = useState<Person[]>([]);
  const [eng, setEng] = useState("");
  const [year, setYear] = useState("");
  const [d, setD] = useState<any>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");

  useEffect(() => { apiFetch<Person[]>(`/organizations/${orgId}/bir/people?type=employees`).then(setEmployees).catch((e) => setErr(e.message)); }, [orgId]);

  function loadData(e = eng, y = year) {
    setErr(""); setD(null);
    if (!e || !y) return;
    setBusy(true);
    apiFetch<any>(`/organizations/${orgId}/bir/2316?engagement_id=${e}&year=${y}`)
      .then(setD).catch((er) => setErr(er.message)).finally(() => setBusy(false));
  }

  const setNum = (k: string, v: string) => setD((cur: any) => {
    const next = { ...cur, [k]: parseFloat(v) || 0 };
    const contrib = Number(next.sss || 0) + Number(next.philhealth || 0) + Number(next.pagibig || 0);
    next.total_contributions = Math.round(contrib * 100) / 100;
    next.taxable = Math.round((Number(next.gross || 0) - contrib) * 100) / 100;
    return next;
  });

  const NumRow = (label: string, key: string) => (
    <div className="flex items-center justify-between gap-3 border-b border-slate-100 py-1.5">
      <span className="text-sm text-slate-600">{label}</span>
      <input className="input w-40 text-right" type="number" step="0.01" value={d?.[key] ?? 0} onChange={(e) => setNum(key, e.target.value)} />
    </div>
  );

  return (
    <div className="card">
      <div className="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div>
          <label className="mb-1 block text-xs text-slate-500">Employee</label>
          <select className="input" value={eng} onChange={(e) => { setEng(e.target.value); loadData(e.target.value, year); }}>
            <option value="">Choose…</option>
            {employees.map((c) => <option key={c.engagement_id} value={c.engagement_id}>{c.name}</option>)}
          </select>
        </div>
        <div>
          <label className="mb-1 block text-xs text-slate-500">Year</label>
          <select className="input" value={year} onChange={(e) => { setYear(e.target.value); loadData(eng, e.target.value); }}>
            <option value="">Choose…</option>
            {years.map((y) => <option key={y} value={y}>{y}</option>)}
          </select>
        </div>
      </div>
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}
      {busy ? <p className="text-sm text-slate-400">Loading…</p> : null}

      {d ? (
        <div className="max-w-xl">
          <p className="mb-2 text-xs text-slate-500">Employee: <b>{d.employee.name}</b> · TIN {d.employee.tin || "—"}. Figures are editable before you generate.</p>
          {NumRow("Gross compensation income", "gross")}
          {NumRow("SSS contributions", "sss")}
          {NumRow("PhilHealth contributions", "philhealth")}
          {NumRow("Pag-IBIG contributions", "pagibig")}
          <div className="flex items-center justify-between gap-3 border-b border-slate-100 py-1.5 text-slate-500">
            <span className="text-sm">Taxable compensation (auto)</span><span className="w-40 text-right font-semibold">₱ {fmt(d.taxable)}</span>
          </div>
          {NumRow("Tax withheld for the year", "withholding_tax")}
          <div className="mt-3"><button className="btn-primary" onClick={() => generate(`/organizations/${orgId}/bir/2316/generate`, d).catch((e) => setErr(e.message))}>Generate 2316</button></div>
        </div>
      ) : null}
    </div>
  );
}
