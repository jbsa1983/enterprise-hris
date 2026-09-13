
import { useState } from "react";
import { useRouter } from "@/lib/nav";
import { login, apiFetch } from "@/lib/api";
import GeekLogo from "@/components/GeekLogo";
import { GEEK_CYCLE } from "@/lib/geek";

export default function LoginPage() {
  const router = useRouter();
  const [mode, setMode] = useState<"signin" | "forgot">("signin");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const timedOut =
    typeof window !== "undefined" && new URLSearchParams(window.location.search).get("timeout") === "1";

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await login(email, password);
      router.replace("/dashboard");
    } catch (err: any) {
      setError(err.message || "Login failed");
    } finally {
      setBusy(false);
    }
  }

  async function onForgot(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await apiFetch("/auth/forgot", { method: "POST", body: JSON.stringify({ email }) });
      setSent(true);
    } catch (err: any) {
      // Backend always returns OK; only a network error lands here.
      setError(err.message || "Could not send the reset link. Please try again.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
      <div className="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-xl">
        <div className="flex h-1.5 w-full">
          {GEEK_CYCLE.map((c) => (
            <div key={c} className="flex-1" style={{ backgroundColor: c }} />
          ))}
        </div>
        <div className="p-8">
        <div className="mb-6">
          <GeekLogo onDark={false} subtitle="" size="lg" />
          <p className="mt-3 text-sm text-slate-500">Enterprise HRIS — Multi-company Philippine payroll &amp; HR</p>
        </div>
        {timedOut && mode === "signin" ? (
          <div className="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
            You were signed out after 3 minutes of inactivity. Please sign in again.
          </div>
        ) : null}

        {mode === "signin" ? (
          <form onSubmit={onSubmit} className="space-y-4">
            <div>
              <label className="mb-1 block text-sm font-medium text-slate-700">Email</label>
              <input
                className="input"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                autoComplete="username"
                required
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-slate-700">Password</label>
              <input
                className="input"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="current-password"
                required
              />
            </div>
            {error ? (
              <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>
            ) : null}
            <button className="btn-primary w-full" disabled={busy}>
              {busy ? "Signing in…" : "Sign in"}
            </button>
            <div className="text-center">
              <button
                type="button"
                className="text-sm font-medium text-blue-600 hover:underline"
                onClick={() => { setMode("forgot"); setError(null); setSent(false); }}
              >
                Forgot password?
              </button>
            </div>
          </form>
        ) : sent ? (
          <div className="space-y-4">
            <div className="rounded-lg bg-green-50 px-3 py-3 text-sm text-green-800">
              If an account exists for <span className="font-medium">{email}</span>, we've sent a link to reset
              your password. Please check your inbox (and spam folder). The link is valid for 1 hour.
            </div>
            <button
              type="button"
              className="btn-primary w-full"
              onClick={() => { setMode("signin"); setPassword(""); setError(null); }}
            >
              Back to sign in
            </button>
          </div>
        ) : (
          <form onSubmit={onForgot} className="space-y-4">
            <p className="text-sm text-slate-600">
              Enter your email and we'll send you a link to reset your password.
            </p>
            <div>
              <label className="mb-1 block text-sm font-medium text-slate-700">Email</label>
              <input
                className="input"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                autoComplete="username"
                required
                autoFocus
              />
            </div>
            {error ? (
              <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>
            ) : null}
            <button className="btn-primary w-full" disabled={busy}>
              {busy ? "Sending…" : "Send reset link"}
            </button>
            <div className="text-center">
              <button
                type="button"
                className="text-sm font-medium text-blue-600 hover:underline"
                onClick={() => { setMode("signin"); setError(null); }}
              >
                Back to sign in
              </button>
            </div>
          </form>
        )}
        </div>
      </div>
    </div>
  );
}
