
import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/api";
import { storageStatusColor, fmtSize } from "@/lib/format";
import type { StorageOverview } from "@/lib/types";

export default function StorageWidget() {
  const [data, setData] = useState<StorageOverview | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    async function load() {
      try {
        const d = await apiFetch<StorageOverview>("/system/storage");
        if (active) {
          setData(d);
          setError(null);
        }
      } catch (e: any) {
        if (active) setError(e.message || "Failed to load storage");
      }
    }
    load();
    // Refresh every 30 seconds (real-time storage monitoring).
    const interval = setInterval(load, 30_000);
    return () => {
      active = false;
      clearInterval(interval);
    };
  }, []);

  if (error) {
    // Not everyone has storage.view — hide the card entirely rather than showing
    // a permission error to employees.
    return null;
  }
  if (!data) {
    return <div className="card text-sm text-slate-400">Loading storage…</div>;
  }

  return (
    <div className="card">
      <div className="flex items-center justify-between">
        <div className="stat-label">Real-Time Storage</div>
        <span className={`badge ${storageStatusColor(data.status)}`}>{data.status}</span>
      </div>
      <div className="mt-3">
        <div className="flex items-end justify-between">
          <div className="stat-value">{data.percent_used}%</div>
          <div className="text-xs text-slate-500">
            {fmtSize(data.used_bytes)} / {fmtSize(data.total_bytes)}
          </div>
        </div>
        <div className="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-slate-200">
          <div
            className={`h-full rounded-full ${
              data.status === "CRITICAL"
                ? "bg-red-500"
                : data.status === "WARNING"
                  ? "bg-orange-500"
                  : data.status === "ADVISORY"
                    ? "bg-yellow-500"
                    : "bg-emerald-500"
            }`}
            style={{ width: `${Math.min(data.percent_used, 100)}%` }}
          />
        </div>
      </div>
      <div className="mt-4 space-y-1">
        {Object.entries(data.breakdown_bytes).map(([k, v]) => (
          <div key={k} className="flex justify-between text-xs text-slate-500">
            <span className="capitalize">{k.replace(/_/g, " ")}</span>
            <span>{fmtSize(v)}</span>
          </div>
        ))}
      </div>
      <div className="mt-3 text-[11px] text-slate-400">
        Auto-refresh every {data.refresh_seconds}s
        {data.capacity_basis === "server-disk"
          ? " · capacity = shared server disk (set storage_quota_gb for your plan)"
          : " · capacity = your plan limit"}
      </div>
    </div>
  );
}
