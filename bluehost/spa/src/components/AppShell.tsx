
import Link from "@/lib/Link";
import { usePathname, useRouter } from "@/lib/nav";
import { useEffect, useState } from "react";
import { apiFetch, clearTokens, getAccessToken } from "@/lib/api";
import { useIdleLogout } from "@/lib/useIdleLogout";
import type { CurrentUser } from "@/lib/types";
import GeekLogo from "@/components/GeekLogo";

// Sign out after this many minutes of no user activity.
const IDLE_MINUTES = 15;

// `perm` is the permission required to see the tab. Items without a perm are
// visible to everyone (Superadmins always see all). Management tabs are gated on
// management-level permissions so a plain Employee (who only holds self-service
// permissions like leave.view) does not see the org-wide admin screens.
const NAV: { label: string; perm?: string; href: (o?: number) => string }[] = [
  { label: "Enterprise Dashboard", perm: "organization.view", href: () => "/dashboard" },
  { label: "Organization Dashboard", perm: "organization.view", href: (o) => (o ? `/o/${o}/dashboard` : "/dashboard") },
  { label: "People", perm: "employee.view", href: (o) => (o ? `/o/${o}/people` : "#") },
  { label: "Consultants", perm: "employee.view", href: (o) => (o ? `/o/${o}/people?type=consultants` : "#") },
  { label: "Projects", perm: "employee.view", href: (o) => (o ? `/o/${o}/projects` : "#") },
  { label: "Payroll", perm: "payroll.view", href: (o) => (o ? `/o/${o}/payroll` : "#") },
  { label: "13th Month & Bonuses", perm: "payroll.view", href: (o) => (o ? `/o/${o}/special-pay` : "#") },
  { label: "Loans & Advances", perm: "loan.view", href: (o) => (o ? `/o/${o}/loans` : "#") },
  { label: "Leave & Attendance", perm: "leave.approve", href: (o) => (o ? `/o/${o}/leave` : "#") },
  { label: "Assets", perm: "employee.view", href: (o) => (o ? `/o/${o}/assets` : "#") },
  { label: "Benefits", perm: "employee.view", href: (o) => (o ? `/o/${o}/benefits` : "#") },
  { label: "Recruitment", perm: "employee.view", href: (o) => (o ? `/o/${o}/recruitment` : "#") },
  { label: "HR Modules", perm: "employee.view", href: (o) => (o ? `/o/${o}/hr` : "#") },
  { label: "Reports", perm: "reports.view", href: (o) => (o ? `/o/${o}/reports` : "#") },
  { label: "Org Setup", perm: "organization.view", href: (o) => (o ? `/o/${o}/setup` : "#") },
  { label: "My Self-Service", href: () => "/me" },
];

const ADMIN_NAV = [
  { label: "Organizations", href: "/admin/organizations" },
  { label: "Users", href: "/admin/users" },
  { label: "Roles & Scopes", href: "/admin/roles" },
  { label: "Branding", href: "/admin/branding" },
  { label: "License", href: "/admin/license" },
];

export default function AppShell({
  children,
  orgId,
}: {
  children: React.ReactNode;
  orgId?: number;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [orgs, setOrgs] = useState<{ id: number; name: string }[]>([]);
  const [license, setLicense] = useState<{ active: boolean; enforced: boolean; reason: string } | null>(null);
  const [loading, setLoading] = useState(true);

  useIdleLogout(IDLE_MINUTES);

  useEffect(() => {
    if (!getAccessToken()) {
      router.replace("/login");
      return;
    }
    apiFetch<CurrentUser>("/auth/me")
      .then((u) => {
        setUser(u);
        // Plain employees (no org-wide access) have no use for the dashboards —
        // send them straight to their self-service area.
        const canOrg = u.is_superadmin || (u.permissions || []).includes("organization.view");
        if (!canOrg && /\/dashboard$/.test(pathname)) router.replace("/me");
      })
      .catch(() => router.replace("/login"))
      .finally(() => setLoading(false));
    // Selector shows every organization the user can access (all of them for a
    // Superadmin), independent of explicit memberships.
    apiFetch<{ id: number; name: string }[]>("/organizations")
      .then(setOrgs)
      .catch(() => setOrgs([]));
    apiFetch<{ active: boolean; enforced: boolean; reason: string }>("/license")
      .then(setLicense)
      .catch(() => setLicense(null));
  }, [router]);

  function logout() {
    clearTokens();
    router.replace("/login");
  }

  function onOrgChange(e: React.ChangeEvent<HTMLSelectElement>) {
    const id = e.target.value;
    if (id) router.push(`/o/${id}/dashboard`);
  }

  if (loading) {
    return (
      <div className="flex h-screen items-center justify-center text-slate-500">
        Loading…
      </div>
    );
  }
  if (!user) return null;

  const isAdmin = user.is_superadmin || (user.permissions || []).includes("system.admin");

  return (
    <div className="flex min-h-screen">
      {/* Sidebar */}
      <aside className="hidden w-60 flex-shrink-0 flex-col bg-brand-900 text-slate-100 md:flex">
        <div className="border-b border-white/10 px-5 py-4">
          <GeekLogo onDark subtitle="Enterprise HRIS" />
        </div>
        <nav className="flex-1 space-y-1 px-3 py-4">
          {NAV.filter((item) => !item.perm || user.is_superadmin || (user.permissions || []).includes(item.perm)).map((item) => {
            const href = item.href(orgId);
            const disabled = href === "#";
            const active = pathname === href.split("?")[0];
            return (
              <Link
                key={item.label}
                href={disabled ? "#" : href}
                className={`block rounded-lg px-3 py-2 text-sm ${
                  active
                    ? "bg-geek-blue/25 font-medium text-white ring-1 ring-geek-blue/40"
                    : disabled
                      ? "cursor-not-allowed text-slate-500"
                      : "text-slate-200 hover:bg-white/10"
                }`}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>
        <a
          href="/manual.html"
          target="_blank"
          rel="noopener noreferrer"
          className="mx-3 mb-1 flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-200 hover:bg-white/10"
        >
          <span aria-hidden="true">📘</span> Help &amp; User Guide
        </a>
        {isAdmin ? (
          <div className="border-t border-white/10 px-3 py-3">
            <div className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
              Administration
            </div>
            {ADMIN_NAV.map((item) => {
              const active = pathname === item.href;
              return (
                <Link key={item.label} href={item.href}
                  className={`block rounded-lg px-3 py-2 text-sm ${active ? "bg-geek-blue/25 font-medium text-white ring-1 ring-geek-blue/40" : "text-slate-200 hover:bg-white/10"}`}>
                  {item.label}
                </Link>
              );
            })}
          </div>
        ) : null}
        <div className="border-t border-white/10 px-5 py-3 text-[11px] text-slate-400">
          GEEK Group · Enterprise HRIS
        </div>
      </aside>

      {/* Main */}
      <div className="flex flex-1 flex-col">
        {license && !license.active && (license.enforced || isAdmin) ? (
          <div className="flex flex-wrap items-center justify-between gap-2 bg-amber-500 px-6 py-2 text-sm text-white">
            <span>⚠ This installation is not activated{license.reason ? ` — ${license.reason}` : ""}.</span>
            {isAdmin ? (
              <Link href="/admin/license" className="rounded bg-white/20 px-3 py-1 font-medium hover:bg-white/30">Activate license →</Link>
            ) : (
              <span className="opacity-90">Please contact your administrator.</span>
            )}
          </div>
        ) : null}
        {/* Top bar */}
        <header className="flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-6 py-3">
          <div className="flex items-center gap-3">
            <label className="text-xs font-medium text-slate-500">Organization</label>
            <select
              className="input max-w-xs"
              value={orgId ?? ""}
              onChange={onOrgChange}
            >
              <option value="">Select organization…</option>
              {orgs.map((o) => (
                <option key={o.id} value={o.id}>
                  {o.name}
                </option>
              ))}
            </select>
          </div>
          <div className="flex items-center gap-4">
            <a
              href="/manual.html"
              target="_blank"
              rel="noopener noreferrer"
              className="text-sm text-slate-500 hover:text-geek-blue"
            >
              Help
            </a>
            <div className="text-right">
              <div className="text-sm font-medium text-slate-800">{user.full_name}</div>
              <div className="text-[11px] text-slate-500">{user.roles.join(", ") || "—"}</div>
            </div>
            <button onClick={logout} className="text-sm text-brand-700 hover:underline">
              Sign out
            </button>
          </div>
        </header>

        <main className="flex-1 overflow-y-auto p-6">{children}</main>
      </div>
    </div>
  );
}
