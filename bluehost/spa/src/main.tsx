import React from "react";
import ReactDOM from "react-dom/client";
import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import "./index.css";

import { getAccessToken } from "@/lib/api";
import LoginPage from "@/pages/LoginPage";
import ResetPage from "@/pages/ResetPage";
import EnterpriseDashboard from "@/pages/EnterpriseDashboard";
import OrgDashboard from "@/pages/OrgDashboard";
import People from "@/pages/People";
import Projects from "@/pages/Projects";
import Payroll from "@/pages/Payroll";
import SpecialPay from "@/pages/SpecialPay";
import Loans from "@/pages/Loans";
import Leave from "@/pages/Leave";
import Assets from "@/pages/Assets";
import Benefits from "@/pages/Benefits";
import Recruitment from "@/pages/Recruitment";
import HrModules from "@/pages/HrModules";
import Reports from "@/pages/Reports";
import BirForms from "@/pages/BirForms";
import SelfService from "@/pages/SelfService";
import OrgSetup from "@/pages/OrgSetup";
import AdminOrganizations from "@/pages/AdminOrganizations";
import AdminBranding from "@/pages/AdminBranding";
import AdminLicense from "@/pages/AdminLicense";
import AdminBackup from "@/pages/AdminBackup";
import AdminNotifications from "@/pages/AdminNotifications";
import AdminUsers from "@/pages/AdminUsers";
import AdminRoles from "@/pages/AdminRoles";
import AdminAudit from "@/pages/AdminAudit";

function Home() {
  return <Navigate to={getAccessToken() ? "/dashboard" : "/login"} replace />;
}

ReactDOM.createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Home />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/reset" element={<ResetPage />} />
        <Route path="/dashboard" element={<EnterpriseDashboard />} />
        <Route path="/o/:orgId/dashboard" element={<OrgDashboard />} />
        <Route path="/o/:orgId/people" element={<People />} />
        <Route path="/o/:orgId/projects" element={<Projects />} />
        <Route path="/o/:orgId/payroll" element={<Payroll />} />
        <Route path="/o/:orgId/special-pay" element={<SpecialPay />} />
        <Route path="/o/:orgId/loans" element={<Loans />} />
        <Route path="/o/:orgId/leave" element={<Leave />} />
        <Route path="/o/:orgId/assets" element={<Assets />} />
        <Route path="/o/:orgId/benefits" element={<Benefits />} />
        <Route path="/o/:orgId/recruitment" element={<Recruitment />} />
        <Route path="/o/:orgId/hr" element={<HrModules />} />
        <Route path="/o/:orgId/reports" element={<Reports />} />
        <Route path="/o/:orgId/bir" element={<BirForms />} />
        <Route path="/o/:orgId/setup" element={<OrgSetup />} />
        <Route path="/me" element={<SelfService />} />
        <Route path="/admin/organizations" element={<AdminOrganizations />} />
        <Route path="/admin/branding" element={<AdminBranding />} />
        <Route path="/admin/license" element={<AdminLicense />} />
        <Route path="/admin/backup" element={<AdminBackup />} />
        <Route path="/admin/notifications" element={<AdminNotifications />} />
        <Route path="/admin/users" element={<AdminUsers />} />
        <Route path="/admin/roles" element={<AdminRoles />} />
        <Route path="/admin/audit" element={<AdminAudit />} />
        <Route path="*" element={<Home />} />
      </Routes>
    </BrowserRouter>
  </React.StrictMode>
);
