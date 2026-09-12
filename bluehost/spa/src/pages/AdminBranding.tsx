import { useEffect, useRef, useState } from "react";
import AppShell from "@/components/AppShell";
import { apiFetch, apiUpload } from "@/lib/api";
import { setBrandingCache, type Branding } from "@/lib/branding";

export default function AdminBrandingPage() {
  const [brand, setBrand] = useState<Branding>({ app_name: "GEEK Group", logo_url: null });
  const [appName, setAppName] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState(""); const [err, setErr] = useState("");
  const fileRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    apiFetch<Branding>("/branding").then((b) => { setBrand(b); setAppName(b.app_name); }).catch(() => {});
  }, []);

  function pick(f: File | null) {
    setErr("");
    if (preview) URL.revokeObjectURL(preview);
    setFile(f);
    setPreview(f ? URL.createObjectURL(f) : null);
  }

  async function save() {
    setErr(""); setMsg(""); setBusy(true);
    try {
      const fd = new FormData();
      fd.append("app_name", appName);
      if (file) fd.append("logo", file);
      const b = await apiUpload<Branding>("/admin/branding", fd);
      setBrandingCache(b); setMsg("Branding saved.");
      setTimeout(() => window.location.reload(), 600);
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }

  async function removeLogo() {
    if (!confirm("Remove the custom logo and restore the default GEEK logo?")) return;
    setErr(""); setMsg(""); setBusy(true);
    try {
      const b = await apiFetch<Branding>("/admin/branding/logo", { method: "DELETE" });
      setBrandingCache(b); pick(null); setMsg("Reverted to the default logo.");
      setTimeout(() => window.location.reload(), 600);
    } catch (e: any) { setErr(e.message); } finally { setBusy(false); }
  }

  return (
    <AppShell>
      <div className="mb-4">
        <h1 className="text-lg font-semibold text-slate-900">Branding</h1>
        <p className="text-sm text-slate-500">Replace the GEEK logo with your own. Shown on the sidebar and the sign-in page.</p>
      </div>
      {msg ? <div className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{msg}</div> : null}
      {err ? <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{err}</div> : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="card">
          <div className="mb-3 text-sm font-medium text-slate-700">Logo</div>
          <div className="mb-4 flex items-center gap-4">
            <div className="flex h-20 w-40 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 p-2">
              {preview ? (
                <img src={preview} alt="New logo preview" className="max-h-16 max-w-full object-contain" />
              ) : brand.logo_url ? (
                <img src={brand.logo_url} alt={brand.app_name} className="max-h-16 max-w-full object-contain" />
              ) : (
                <span className="text-xs text-slate-400">Default GEEK logo</span>
              )}
            </div>
            <div className="text-xs text-slate-500">
              {preview ? "New logo — click Save to apply." : brand.logo_url ? "Current custom logo." : "No custom logo set."}
            </div>
          </div>
          <input ref={fileRef} type="file" accept=".png,.jpg,.jpeg,.webp,.gif,.svg,image/*" className="hidden"
            onChange={(e) => pick(e.target.files?.[0] ?? null)} />
          <div className="flex flex-wrap gap-2">
            <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50" onClick={() => fileRef.current?.click()}>
              Choose image…
            </button>
            {brand.logo_url ? (
              <button className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-red-600 hover:bg-red-50" onClick={removeLogo} disabled={busy}>
                Remove custom logo
              </button>
            ) : null}
          </div>
          <p className="mt-2 text-xs text-slate-400">
            <strong>Recommended:</strong> a transparent <strong>PNG</strong>, wide format, about <strong>400 × 120 px</strong>
            (2–3× that for sharp high-resolution screens). PNG, JPG, WEBP, GIF, or SVG · up to 2 MB.
            Light or white artwork shows best on the dark sidebar.
          </p>
        </div>

        <div className="card">
          <div className="mb-3 text-sm font-medium text-slate-700">App name</div>
          <input className="input" value={appName} onChange={(e) => setAppName(e.target.value)} placeholder="GEEK Group" />
          <p className="mt-2 text-xs text-slate-400">Used as the logo's alt text and around the app.</p>
          <div className="mt-6">
            <button className="btn-primary" onClick={save} disabled={busy}>{busy ? "Saving…" : "Save branding"}</button>
          </div>
        </div>
      </div>
    </AppShell>
  );
}
