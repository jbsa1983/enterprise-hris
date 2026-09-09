import { GEEK } from "@/lib/geek";

// The GEEK Group mark: a 2x2 grid of brand-colored tiles + the "GEEK" wordmark.
export default function GeekLogo({
  onDark = true,
  subtitle = "Enterprise HRIS",
  size = "md",
}: {
  onDark?: boolean;
  subtitle?: string;
  size?: "sm" | "md" | "lg";
}) {
  const letters: [string, string][] = [
    ["G", GEEK.red],
    ["E", GEEK.amber],
    ["E", GEEK.green],
    ["K", GEEK.blue],
  ];
  const tiles = [GEEK.red, GEEK.blue, GEEK.green, GEEK.amber];
  const wordSize = size === "lg" ? "text-3xl" : size === "sm" ? "text-lg" : "text-2xl";
  const tile = size === "lg" ? "h-4 w-4" : "h-3 w-3";

  return (
    <div className="flex items-center gap-2.5">
      <div className="grid grid-cols-2 gap-[3px]">
        {tiles.map((c, i) => (
          <span key={i} className={`${tile} rounded-[3px]`} style={{ backgroundColor: c }} />
        ))}
      </div>
      <div className="leading-none">
        <div className={`flex items-baseline font-extrabold tracking-tight ${wordSize}`}>
          {letters.map(([ch, color], i) => (
            <span key={i} style={{ color }}>{ch}</span>
          ))}
          <span className={`ml-1.5 text-[11px] font-bold tracking-[0.2em] ${onDark ? "text-slate-300" : "text-slate-500"}`}>
            GROUP
          </span>
        </div>
        {subtitle ? (
          <div className={`mt-1 text-[11px] ${onDark ? "text-slate-400" : "text-slate-500"}`}>{subtitle}</div>
        ) : null}
      </div>
    </div>
  );
}
