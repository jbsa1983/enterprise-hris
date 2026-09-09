
import { useEffect, useState } from "react";
import { useParams } from "@/lib/nav";
import AppShell from "@/components/AppShell";
import { apiFetch, apiDownload } from "@/lib/api";

interface Dataset { name: string; columns: string[]; }

export default function ReportsPage() {
  const orgId = Number(useParams().orgId);
  const [datasets, setDatasets] = useState<Dataset[]>([]);
  const [dataset, setDataset] = useState<string>("");
  const [preview, setPreview] = useState<any>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    apiFetch<Dataset[]>(`/organizations/${orgId}/reports/datasets`).then((d) => {
      setDatasets(d); setDataset(d[0]?.name || "");
    });
  }, [orgId]);

  async function runPreview(ds: string) {
    setBusy(true);
    try {
      const p = await apiFetch(`/organizations/${orgId}/reports/preview`, {
        method: "POST", body: JSON.stringify({ dataset: ds, config: {} }),
      });
      setPreview(p);
    } finally { setBusy(false); }
  }

  useEffect(() => { if (dataset) runPreview(dataset); }, [dataset]); // eslint-disable-line

  function exportAs(fmt: string) {
    apiDownload(`/organizations/${orgId}/reports/export?fmt=${fmt}`, `${dataset}_report.${fmt}`, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ dataset, config: {} }),
    });
  }

  return (
    <AppShell orgId={orgId}>
      <h1 className="mb-4 text-lg font-semibold text-slate-900">Report Builder</h1>
      <div className="card mb-4">
        <div className="flex flex-wrap items-center gap-3">
          <div>
            <label className="mr-2 text-sm text-slate-600">Dataset</label>
            <select className="input inline-block w-auto" value={dataset} onChange={(e) => setDataset(e.target.value)}>
              {datasets.map((d) => <option key={d.name} value={d.name}>{d.name.replace(/_/g, " ")}</option>)}
            </select>
          </div>
          <div className="ml-auto flex gap-2">
            <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50" onClick={() => exportAs("csv")}>Export CSV</button>
            <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50" onClick={() => exportAs("xlsx")}>Export XLSX</button>
            <button className="btn-primary" onClick={() => exportAs("pdf")}>Export PDF</button>
          </div>
        </div>
      </div>

      <div className="card p-0">
        {busy ? <div className="p-4 text-sm text-slate-400">Loading…</div> : preview ? (
          <div className="overflow-x-auto">
            <div className="border-b border-slate-100 px-4 py-2 text-xs text-slate-500">{preview.row_count} rows</div>
            <table className="min-w-full text-sm">
              <thead className="bg-slate-50">
                <tr>{preview.columns.map((c: string) => <th key={c} className="px-3 py-2 text-left text-xs uppercase tracking-wide text-slate-500">{c.replace(/_/g, " ")}</th>)}</tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {preview.rows.slice(0, 25).map((r: any, i: number) => (
                  <tr key={i} className="hover:bg-slate-50">
                    {preview.columns.map((c: string) => <td key={c} className="px-3 py-2 text-slate-700">{String(r[c] ?? "")}</td>)}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : <div className="p-4 text-sm text-slate-400">No data.</div>}
      </div>
    </AppShell>
  );
}
