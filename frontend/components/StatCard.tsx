export default function StatCard({
  label,
  value,
  hint,
  accent,
}: {
  label: string;
  value: React.ReactNode;
  hint?: string;
  accent?: string; // optional GEEK brand color for a left accent bar
}) {
  return (
    <div className="card relative overflow-hidden">
      {accent ? (
        <span className="absolute left-0 top-0 h-full w-1" style={{ backgroundColor: accent }} />
      ) : null}
      <div className="stat-label">{label}</div>
      <div className="stat-value mt-1">{value}</div>
      {hint ? <div className="mt-1 text-xs text-slate-400">{hint}</div> : null}
    </div>
  );
}
