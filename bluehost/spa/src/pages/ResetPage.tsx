
import { useState } from "react";
import { useRouter, useSearchParams } from "@/lib/nav";
import { apiFetch } from "@/lib/api";
import GeekLogo from "@/components/GeekLogo";
import { GEEK_CYCLE } from "@/lib/geek";

export default function ResetPage() {
  const router = useRouter();
  const token = useSearchParams().get("token") || "";
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (password.length < 8) {
      setError("Password must be at least 8 characters.");
      return;
    }
    if (password !== confirm) {
      setError("The two passwords don't match.");
      return;
    }
    setBusy(true);
    try {
      await apiFetch("/auth/reset", {
        method: "POST",
        body: JSON.stringify({ token, new_password: password }),
      });
      setDone(true);
    } catch (err: any) {
      setError(err.message || "Could not reset your password.");
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
            <p className="mt-3 text-sm text-slate-500">Choose a new password</p>
          </div>

          {!token ? (
            <div className="space-y-4">
              <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                This reset link is missing its token. Please request a new one from the sign-in page.
              </div>
              <button className="btn-primary w-full" onClick={() => router.replace("/login")}>
                Back to sign in
              </button>
            </div>
          ) : done ? (
            <div className="space-y-4">
              <div className="rounded-lg bg-green-50 px-3 py-3 text-sm text-green-800">
                Your password has been updated. You can now sign in with your new password.
              </div>
              <button className="btn-primary w-full" onClick={() => router.replace("/login")}>
                Go to sign in
              </button>
            </div>
          ) : (
            <form onSubmit={onSubmit} className="space-y-4">
              <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">New password</label>
                <input
                  className="input"
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="new-password"
                  required
                  autoFocus
                />
                <p className="mt-1 text-xs text-slate-400">At least 8 characters.</p>
              </div>
              <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">Confirm new password</label>
                <input
                  className="input"
                  type="password"
                  value={confirm}
                  onChange={(e) => setConfirm(e.target.value)}
                  autoComplete="new-password"
                  required
                />
              </div>
              {error ? (
                <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>
              ) : null}
              <button className="btn-primary w-full" disabled={busy}>
                {busy ? "Updating…" : "Update password"}
              </button>
              <div className="text-center">
                <button
                  type="button"
                  className="text-sm font-medium text-blue-600 hover:underline"
                  onClick={() => router.replace("/login")}
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
