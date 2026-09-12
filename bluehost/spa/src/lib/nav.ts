// Shims so components written for Next's `next/navigation` work under react-router.
import { useMemo } from "react";
import { useNavigate, useLocation, useParams as rrUseParams, useSearchParams as rrUseSearchParams } from "react-router-dom";

export function useRouter() {
  const navigate = useNavigate();
  // Memoized so the returned object is stable across renders — otherwise any
  // effect that lists `router` as a dependency would re-run on every render.
  return useMemo(
    () => ({
      push: (url: string) => navigate(url),
      replace: (url: string) => navigate(url, { replace: true }),
      back: () => navigate(-1),
    }),
    [navigate],
  );
}

export function usePathname(): string {
  return useLocation().pathname;
}

export const useParams = rrUseParams as <T = Record<string, string>>() => T;

// Next's useSearchParams() returns a ReadonlyURLSearchParams with .get();
// react-router's first tuple element is a URLSearchParams (also has .get()).
export function useSearchParams(): URLSearchParams {
  const [sp] = rrUseSearchParams();
  return sp;
}
