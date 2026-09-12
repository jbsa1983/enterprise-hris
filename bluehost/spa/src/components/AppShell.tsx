
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
  { label: "Backup & Migration", href: "/admin/backup" },
  { label: "Notifications", href: "/admin/notifications" },
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
  const [notif, setNotif] = useState<{ unread: number; items: any[] }>({ unread: 0, items: [] });
  const [notifOpen, setNotifOpen] = useState(false);
  const [loading, setLoading] = useState(true);

  useIdleLogout(IDLE_MINUTES);

  useEffect(() => {
    if (!getAccessToken()) return;
    const loadNotif = () =>
      apiFetch<{ unread: number; items: any[] }>("/me/notifications", { silent: true }).then(setNotif).catch(() => {});
    loadNotif();
    const iv = window.setInterval(loadNotif, 60_000);
    return () => window.clearInterval(iv);
  }, []);

  async function openNotif() {
    const willOpen = !notifOpen;
    setNotifOpen(willOpen);
    if (willOpen && notif.unread > 0) {
      try { await apiFetch("/me/notifications/read", { method: "POST", silent: true }); } catch { /* ignore */ }
      setNotif((n) => ({ unread: 0, items: n.items.map((i) => ({ ...i, is_read: true })) }));
    }
  }

  useEffect(() => {
    if (!getAccessToken()) {
      router.replace("/login");
      return;
    }
    apiFetch<CurrentUser>("/auth/me")
      .then((u) => {
        setUser(u);
        const canOrg = u.is_superadmin || (u.permissions || []).includes("organization.view");
        const orgs = u.organizations || [];
        if (!canOrg && /\/dashboard$/.test(pathname)) {
          // Plain employees have no use for the dashboards — send them to self-service.
          router.replace("/me");
        } else if (canOrg && !u.is_superadmin && pathname === "/dashboard" && orgs.length === 1) {
          // Assigned to exactly one organization → go straight into it.
          router.replace(`/o/${orgs[0].organization_id}/dashboard`);
        }
      })
      .catch(() => router.replace("/login"))
      .finally(() => setLoading(false));
    // Selector shows every organization the user can access (all of them for a
    // Superadmin), independent of explicit memberships.
    apiFetch<{ id: number; name: string }[]>("/organizations", { silent: true })
      .then(setOrgs)
      .catch(() => setOrgs([]));
    apiFetch<{ active: boolean; enforced: boolean; reason: string }>("/license", { silent: true })
      .then(setLicense)
      .catch(() => setLicense(null));
    // Run once when the shell mounts (not on every render) — router/pathname are
    // captured intentionally.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

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
            <div className="relative">
              <button onClick={openNotif} aria-label="Notifications" className="relative flex text-slate-500 hover:text-geek-blue">
                <span className="text-lg leading-none">🔔</span>
                {notif.unread > 0 ? (
                  <span className="absolute -right-1.5 -top-1.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white">
                    {notif.unread > 9 ? "9+" : notif.unread}
                  </span>
                ) : null}
              </button>
              {notifOpen ? (
                <>
                  <div className="fixed inset-0 z-40" onClick={() => setNotifOpen(false)} />
                  <div className="absolute right-0 z-50 mt-2 w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                    <div className="border-b border-slate-100 px-4 py-2 text-sm font-medium text-slate-700">Notifications</div>
                    <div className="max-h-96 overflow-y-auto">
                      {notif.items.length === 0 ? (
                        <div className="px-4 py-8 text-center text-sm text-slate-400">Nothing yet.</div>
                      ) : (
                        notif.items.map((i) => (
                          <button
                            key={i.id}
                            onClick={() => { setNotifOpen(false); if (i.link) router.push(i.link); }}
                            className={`block w-full border-b border-slate-50 px-4 py-3 text-left hover:bg-slate-50 ${i.is_read ? "" : "bg-blue-50/40"}`}
                          >
                            <div className="text-sm font-medium text-slate-800">{i.title}</div>
                            {i.body ? <div className="mt-0.5 text-xs text-slate-500">{i.body}</div> : null}
                            <div className="mt-1 text-[10px] text-slate-400">{i.created_at}</div>
                          </button>
                        ))
                      )}
                    </div>
                  </div>
                </>
              ) : null}
            </div>
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
