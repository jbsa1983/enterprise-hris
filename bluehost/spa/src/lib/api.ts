// API client for the PHP backend (served from the same origin at /api/v1).
const API_BASE = "/api/v1";

const ACCESS_KEY = "hris_access_token";
const REFRESH_KEY = "hris_refresh_token";

export function setTokens(access: string, refresh: string) {
  localStorage.setItem(ACCESS_KEY, access);
  localStorage.setItem(REFRESH_KEY, refresh);
}
export function clearTokens() {
  localStorage.removeItem(ACCESS_KEY);
  localStorage.removeItem(REFRESH_KEY);
}
export function getAccessToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem(ACCESS_KEY);
}

export class ApiError extends Error {
  status: number;
  constructor(status: number, message: string) {
    super(message);
    this.status = status;
  }
}

async function refreshTokens(): Promise<boolean> {
  const refresh = localStorage.getItem(REFRESH_KEY);
  if (!refresh) return false;
  const res = await fetch(`${API_BASE}/auth/refresh`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ refresh_token: refresh }),
  });
  if (!res.ok) return false;
  const data = await res.json();
  setTokens(data.access_token, data.refresh_token);
  return true;
}

// `silent: true` makes a call give up quietly on repeated auth failure instead
// of forcing a redirect to /login — used for background polls so they can never
// sign the user out.
type FetchOpts = RequestInit & { silent?: boolean };

export async function apiFetch<T = any>(path: string, options: FetchOpts = {}, attempt = 0): Promise<T> {
  const { silent, ...init } = options;
  const token = getAccessToken();
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    ...(init.headers as Record<string, string>),
  };
  if (token) headers["Authorization"] = `Bearer ${token}`;
  const res = await fetch(`${API_BASE}${path}`, { ...init, headers });

  if (res.status === 401) {
    // First failure: attempt a genuine token refresh (handles real expiry).
    if (attempt === 0) {
      const ok = await refreshTokens();
      if (ok) return apiFetch<T>(path, options, 1);
    }
    // Further 401s while the session is still valid are almost always the host
    // intermittently dropping the Authorization header — retry a few times with
    // a small backoff before concluding the session is really gone.
    if (attempt < 4) {
      await new Promise((r) => setTimeout(r, 150 * (attempt + 1)));
      return apiFetch<T>(path, options, attempt + 1);
    }
    if (!silent && typeof window !== "undefined") {
      clearTokens();
      window.location.href = "/login";
    }
    throw new ApiError(401, "Unauthorized");
  }
  if (!res.ok) {
    let detail = res.statusText;
    try {
      detail = (await res.json()).detail || detail;
    } catch {}
    throw new ApiError(res.status, detail);
  }
  if (res.status === 204) return undefined as T;
  return (await res.json()) as T;
}

async function apiBlob(path: string, options: RequestInit = {}): Promise<Blob> {
  const token = getAccessToken();
  const headers: Record<string, string> = { ...(options.headers as Record<string, string>) };
  if (token) headers["Authorization"] = `Bearer ${token}`;
  const res = await fetch(`${API_BASE}${path}`, { ...options, headers });
  if (!res.ok) throw new ApiError(res.status, res.statusText);
  return res.blob();
}

export async function apiDownload(path: string, filename: string, options: RequestInit = {}) {
  const blob = await apiBlob(path, options);
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

export async function apiOpen(path: string, options: RequestInit = {}) {
  const blob = await apiBlob(path, options);
  const url = URL.createObjectURL(blob);
  window.open(url, "_blank");
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

export async function apiUpload<T = any>(path: string, formData: FormData): Promise<T> {
  const token = getAccessToken();
  const headers: Record<string, string> = {};
  if (token) headers["Authorization"] = `Bearer ${token}`;
  const res = await fetch(`${API_BASE}${path}`, { method: "POST", headers, body: formData });
  if (!res.ok) {
    let detail = res.statusText;
    try {
      detail = (await res.json()).detail || detail;
    } catch {}
    throw new ApiError(res.status, detail);
  }
  return (await res.json()) as T;
}

export async function login(email: string, password: string) {
  const res = await fetch(`${API_BASE}/auth/login`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password }),
  });
  if (!res.ok) {
    let detail = "Login failed";
    try {
      detail = (await res.json()).detail || detail;
    } catch {}
    throw new ApiError(res.status, detail);
  }
  const data = await res.json();
  setTokens(data.access_token, data.refresh_token);
  return data;
}
