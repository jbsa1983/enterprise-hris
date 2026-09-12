// Global branding (custom logo + app name), fetched once and cached. The
// /branding endpoint is public so the login page can brand itself too.
export interface Branding {
  app_name: string;
  logo_url: string | null;
}

const DEFAULT: Branding = { app_name: "GEEK Group", logo_url: null };
const KEY = "hris_branding";

let cache: Branding | null = null;
let inflight: Promise<Branding> | null = null;

/** Instant value for first paint (localStorage), falling back to the default. */
export function cachedBranding(): Branding {
  if (cache) return cache;
  try {
    const s = localStorage.getItem(KEY);
    if (s) return { ...DEFAULT, ...JSON.parse(s) };
  } catch {}
  return DEFAULT;
}

export function loadBranding(): Promise<Branding> {
  if (cache) return Promise.resolve(cache);
  if (!inflight) {
    inflight = fetch("/api/v1/branding")
      .then((r) => r.json())
      .then((b: Branding) => {
        cache = { ...DEFAULT, ...b };
        try { localStorage.setItem(KEY, JSON.stringify(cache)); } catch {}
        return cache;
      })
      .catch(() => DEFAULT);
  }
  return inflight;
}

/** Update the cache after an admin change so logos refresh without a reload. */
export function setBrandingCache(b: Branding) {
  cache = { ...DEFAULT, ...b };
  try { localStorage.setItem(KEY, JSON.stringify(cache)); } catch {}
}
