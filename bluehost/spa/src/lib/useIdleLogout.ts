import { useEffect } from "react";
import { clearTokens, getAccessToken } from "@/lib/api";

// Auto sign-out after a period of no user activity. Activity = real mouse/
// keyboard/touch input; background polling (e.g. the storage widget) does NOT
// count, so an unattended screen still times out. Shared across tabs via
// localStorage so activity in one tab keeps the others alive.
const KEY = "hris_last_activity";
const EVENTS = ["mousemove", "mousedown", "keydown", "scroll", "touchstart", "click"] as const;

export function useIdleLogout(minutes = 15) {
  useEffect(() => {
    if (!getAccessToken()) return;
    const limitMs = minutes * 60_000;

    const mark = () => {
      try {
        localStorage.setItem(KEY, String(Date.now()));
      } catch {}
    };
    mark();

    // Throttle writes so we don't touch localStorage on every mousemove.
    let last = 0;
    const onActivity = () => {
      const now = Date.now();
      if (now - last > 5_000) {
        last = now;
        mark();
      }
    };
    EVENTS.forEach((e) => window.addEventListener(e, onActivity, { passive: true }));

    const check = () => {
      let ts = 0;
      try {
        ts = Number(localStorage.getItem(KEY) || 0);
      } catch {}
      if (ts && Date.now() - ts > limitMs) {
        clearTokens();
        window.location.href = "/login?timeout=1";
      }
    };
    const iv = window.setInterval(check, 15_000);

    return () => {
      EVENTS.forEach((e) => window.removeEventListener(e, onActivity));
      window.clearInterval(iv);
    };
  }, [minutes]);
}
