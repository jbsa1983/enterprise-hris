"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { apiFetch, clearTokens, getAccessToken } from "@/lib/api";
import type { CurrentUser } from "@/lib/types";
import GeekLogo from "@/components/GeekLogo";

const NAV = [
  { label: "Enterprise Dashboard", href: (o?: number) => "/dashboard" },
  { label: "Organization Dashboard", href: (o?: number) => (o ? `/o/${o}/dashboard` : "/dashboard") },
  { label: "People", href: (o?: number) => (o ? `/o/${o}/people` : "#") },
  { label: "Consultants", href: (o?: number) => (o ? `/o/${o}/people?type=consultants` : "#") },
  { label: "Projects", href: (o?: number) => (o ? `/o/${o}/projects` : "#") },
  { label: "Payroll", href: (o?: number) => (o ? `/o/${o}/payroll` : "#") },
  { label: "Leave & Attendance", href: (o?: number) => (o ? `/o/${o}/leave` : "#") },
  { label: "Assets", href: (o?: number) => (o ? `/o/${o}/assets` : "#") },
  { label: "Recruitment", href: (o?: number) => (o ? `/o/${o}/recruitment` : "#") },
  { label: "HR Modules", href: (o?: number) => (o ? `/o/${o}/hr` : "#") },
  { label: "Reports", href: (o?: number) => (o ? `/o/${o}/reports` : "#") },
  { label: "My Self-Service", href: (o?: number) => "/me" },
];

const ADMIN_NAV = [
  { label: "Users", href: "/admin/users" },
  { label: "Roles & Scopes", href: "/admin/roles" },
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
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!getAccessToken()) {
      router.replace("/login");
      return;
    }
    apiFetch<CurrentUser>("/auth/me")
      .then((u) => setUser(u))
      .catch(() => router.replace("/login"))
      .finally(() => setLoading(false));
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
          {NAV.map((item) => {
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
              {user.organizations.map((o) => (
                <option key={o.organization_id} value={o.organization_id}>
                  {o.name}
                </option>
              ))}
            </select>
          </div>
          <div className="flex items-center gap-4">
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
