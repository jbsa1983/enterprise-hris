import { useEffect, useRef, useState } from "react";
import { apiBlob } from "@/lib/api";

// Small in-memory cache of fetched object URLs, keyed by endpoint, so lists don't
// refetch the same photo and switching pages is instant.
const cache = new Map<string, string>();

function initials(name?: string) {
  const parts = (name || "").trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return "?";
  const a = parts[0][0] || "";
  const b = parts.length > 1 ? parts[parts.length - 1][0] || "" : "";
  return (a + b).toUpperCase();
}

// Deterministic, muted colour from the name so each person keeps the same placeholder.
function colorFor(name?: string) {
  let h = 0;
  const s = name || "?";
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) % 360;
  return `hsl(${h} 45% 62%)`;
}

const SIZES: Record<string, number> = { sm: 32, md: 44, lg: 96 };

/**
 * Round avatar. Pass `src` (an authenticated image endpoint) when a photo exists — it's
 * fetched with the auth token and shown; otherwise (or on error) it falls back to the
 * person's coloured initials. `has` gates the fetch so we don't hit 404s for no-photo rows.
 */
export default function Avatar({ name, src, has = true, size = "md", refreshKey }:
  { name?: string; src?: string; has?: boolean; size?: "sm" | "md" | "lg"; refreshKey?: number }) {
  const px = SIZES[size] ?? 44;
  const [url, setUrl] = useState<string | null>(src && has ? cache.get(src) ?? null : null);
  const [failed, setFailed] = useState(false);
  const own = useRef<string | null>(null);

  useEffect(() => {
    setFailed(false);
    if (!src || !has) { setUrl(null); return; }
    // Bust the cache when refreshKey changes (e.g. right after an upload).
    const cached = cache.get(src);
    if (cached && !refreshKey) { setUrl(cached); return; }
    let alive = true;
    apiBlob(src)
      .then((b) => {
        const u = URL.createObjectURL(b);
        cache.set(src, u);
        if (own.current && own.current !== u) URL.revokeObjectURL(own.current);
        own.current = u;
        if (alive) setUrl(u);
      })
      .catch(() => alive && setFailed(true));
    return () => { alive = false; };
  }, [src, has, refreshKey]);

  const style: React.CSSProperties = { width: px, height: px, minWidth: px, borderRadius: "50%", objectFit: "cover" };
  if (url && !failed) return <img src={url} alt={name || "photo"} style={style} className="border border-slate-200 bg-slate-100" />;
  return (
    <div style={{ ...style, background: colorFor(name), fontSize: px * 0.4 }}
      className="flex select-none items-center justify-center font-semibold text-white" aria-label={name}>
      {initials(name)}
    </div>
  );
}
