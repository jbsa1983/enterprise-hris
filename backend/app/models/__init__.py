"""Import all models so SQLAlchemy metadata is fully populated.

Any module that needs `Base.metadata` (migrations, create_all, seeding) should
import this package first.
"""
from app.models.asset import Asset
from app.models.attendance import (
    AttendanceLog,
    Holiday,
    LeaveBalance,
    LeaveType,
    Shift,
    Timesheet,
)
from app.models.audit import AuditLog
from app.models.bank import BankExportColumn, BankExportRun, BankExportTemplate
from app.models.enums import (
    EngagementStatus,
    EngagementType,
    ObligationType,
    PayrollFrequency,
    PayrollStatus,
    ProjectStatus,
)
from app.models.hr import LeaveRequest, OvertimeRequest
from app.models.hrmodules import (
    LifecycleChecklist,
    PerformanceCycle,
    PerformanceReview,
    ServiceTicket,
    TrainingAssignment,
    TrainingCourse,
)
from app.models.loan import Loan, LoanTransaction
from app.models.org_structure import CostCenter, Department, Position
from app.models.organization import Enterprise, Organization, OrganizationUser
from app.models.payroll import (
    PayrollPeriod,
    PayrollRun,
    PayrollRunPerson,
    StatutoryRuleSet,
)
from app.models.payslip import DocumentType, Payslip
from app.models.person import Engagement, Person
from app.models.project import Client, Project, ProjectAssignment
from app.models.recruitment import Applicant, Interview, JobApplication, JobRequisition
from app.models.reports import ReportTemplate
from app.models.storage import StorageMetric
from app.models.user import Permission, Role, User
from app.models.workflow import (
    ApprovalAction,
    ApprovalInstance,
    ApprovalWorkflow,
    ApprovalWorkflowStep,
)

__all__ = [
    "Asset",
    "AuditLog",
    "Client",
    "CostCenter",
    "Department",
    "Engagement",
    "EngagementStatus",
    "EngagementType",
    "Enterprise",
    "LeaveRequest",
    "Loan",
    "LoanTransaction",
    "ObligationType",
    "Organization",
    "OrganizationUser",
    "OvertimeRequest",
    "PayrollFrequency",
    "PayrollPeriod",
    "PayrollRun",
    "PayrollRunPerson",
    "PayrollStatus",
    "Permission",
    "Person",
    "Position",
    "Project",
    "ProjectAssignment",
    "ProjectStatus",
    "Role",
    "StatutoryRuleSet",
    "StorageMetric",
    "User",
]
