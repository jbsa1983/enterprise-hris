"""RBAC catalog: permission codes and default role → permission mappings.

Roles/permissions are stored in the database (configurable), but this module is
the canonical seed source and keeps permission-code strings in one place.
"""
from __future__ import annotations

# --- Permission codes --------------------------------------------------------
PERMISSIONS: dict[str, str] = {
    "organization.view": "View organizations",
    "organization.manage": "Create / edit organizations",
    "employee.view": "View employees",
    "employee.create": "Create employees",
    "employee.edit": "Edit employees",
    "employee.archive": "Archive employees",
    "salary.view": "View salary information",
    "salary.edit": "Edit salary information",
    "payroll.view": "View payroll",
    "payroll.prepare": "Prepare payroll runs",
    "payroll.compute": "Compute payroll",
    "payroll.approve": "Approve payroll",
    "payroll.lock": "Lock payroll",
    "payroll.export": "Export payroll / bank files",
    "loan.view": "View loans & advances",
    "loan.create": "Create loans & advances",
    "loan.adjust": "Adjust loans & advances",
    "attendance.view": "View attendance",
    "attendance.edit": "Edit attendance",
    "attendance.approve": "Approve attendance",
    "leave.view": "View leave",
    "leave.apply": "Apply for leave",
    "leave.approve": "Approve leave",
    "documents.view": "View documents",
    "documents.upload": "Upload documents",
    "documents.confidential.view": "View confidential documents",
    "reports.view": "View reports",
    "reports.export": "Export reports",
    "storage.view": "View storage monitoring",
    "system.admin": "System administration",
    "audit.view": "View audit trail",
}

# --- Default roles -----------------------------------------------------------
ALL = list(PERMISSIONS.keys())


def _prefix(*prefixes: str) -> list[str]:
    return [p for p in ALL if any(p.startswith(pre) for pre in prefixes)]


ROLE_PERMISSIONS: dict[str, list[str]] = {
    "Super Admin": ALL,  # also flagged is_superadmin (implicit "*")
    "Enterprise Admin": ALL,
    "Company Admin": _prefix(
        "organization.view", "employee", "salary", "payroll", "loan",
        "attendance", "leave", "documents", "reports", "storage", "audit",
    ),
    "HR Director": _prefix(
        "organization.view", "employee", "salary.view", "payroll.view",
        "loan.view", "attendance", "leave", "documents", "reports",
    ),
    "HR Manager": _prefix(
        "organization.view", "employee.view", "employee.create", "employee.edit",
        "attendance", "leave", "documents.view", "documents.upload", "reports.view",
    ),
    "HR Staff": [
        "organization.view", "employee.view", "attendance.view", "leave.view",
        "leave.apply", "documents.view", "reports.view",
    ],
    "Payroll Administrator": [
        "organization.view", "employee.view", "salary.view",
        "payroll.view", "payroll.prepare", "payroll.compute", "payroll.export",
        "loan.view", "reports.view", "reports.export",
    ],
    "Payroll Approver": [
        "organization.view", "employee.view", "salary.view",
        "payroll.view", "payroll.approve", "payroll.lock", "reports.view",
    ],
    "Recruitment Officer": ["organization.view", "employee.view", "documents.view", "reports.view"],
    "Training Officer": ["organization.view", "employee.view", "reports.view"],
    "Finance": [
        "organization.view", "payroll.view", "loan.view", "reports.view",
        "reports.export", "salary.view",
    ],
    "Department Head": [
        "organization.view", "employee.view", "attendance.view", "attendance.approve",
        "leave.view", "leave.approve", "reports.view",
    ],
    "Supervisor": [
        "organization.view", "employee.view", "attendance.view", "attendance.approve",
        "leave.view", "leave.approve",
    ],
    "Employee": ["leave.view", "leave.apply", "attendance.view", "documents.view"],
    "Consultant": ["documents.view"],
    "Auditor": ["organization.view", "audit.view", "reports.view", "payroll.view", "salary.view"],
}
