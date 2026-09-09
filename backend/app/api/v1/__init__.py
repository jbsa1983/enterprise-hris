"""Aggregate all v1 routers under a single APIRouter."""
from fastapi import APIRouter

from app.api.v1 import (
    admin,
    assets,
    attendance,
    audit,
    auth,
    bank,
    dashboard,
    hrmodules,
    orgadmin,
    organizations,
    payroll,
    payslips,
    people,
    projects,
    recruitment,
    reports,
    storage,
    workflow,
)

api_router = APIRouter()
# Core
api_router.include_router(auth.router)
api_router.include_router(admin.router)
api_router.include_router(organizations.router)
api_router.include_router(orgadmin.router)
api_router.include_router(dashboard.router)
api_router.include_router(people.router)
api_router.include_router(projects.router)
# Payroll & payslips (Phase 3–4)
api_router.include_router(payroll.router)
api_router.include_router(payslips.router)
api_router.include_router(payslips.ess_router)
# Bank export & reports (Phase 5)
api_router.include_router(bank.router)
api_router.include_router(reports.router)
# Workforce (Phase 2)
api_router.include_router(attendance.router)
api_router.include_router(assets.router)
api_router.include_router(workflow.router)
# Additional HR (Phase 6)
api_router.include_router(recruitment.router)
api_router.include_router(hrmodules.router)
# System
api_router.include_router(storage.router)
api_router.include_router(audit.router)
