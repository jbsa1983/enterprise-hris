import Link from "@/lib/Link";

export default function StatCard({
  label,
  value,
  hint,
  accent,
  href,
}: {
  label: string;
  value: React.ReactNode;
  hint?: string;
  accent?: string; // optional GEEK brand color for a left accent bar
  href?: string; // when set, the card links to the related module
}) {
  const inner = (
    <>
      {accent ? (
        <span className="absolute left-0 top-0 h-full w-1" style={{ backgroundColor: accent }} />
      ) : null}
      <div className="stat-label">{label}</div>
      <div className="stat-value mt-1">{value}</div>
      {hint ? <div className="mt-1 text-xs text-slate-400">{hint}</div> : null}
      {href ? <div className="mt-1 text-[11px] text-brand-700">View →</div> : null}
    </>
  );
  if (href) {
    return (
      <Link href={href} className="card relative block overflow-hidden transition hover:shadow-md hover:ring-1 hover:ring-brand-600/30">
        {inner}
      </Link>
    );
  }
  return <div className="card relative overflow-hidden">{inner}</div>;
}
