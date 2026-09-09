# Enterprise HRIS Prototype — Claude Code Build Specification

## 1. Project Objective

Build a Docker-based, multi-company Enterprise Human Resources Information System (HRIS) prototype designed for Philippine organizations.

The system must support:
- Multiple companies / organizations
- Regular employees
- Probationary employees
- Project-based employees
- Fixed-term employees
- Daily-paid / hourly-paid staff
- Part-time workers
- Individual consultants
- Corporate consultants / service providers
- OJT / trainees
- Multi-project assignments
- Philippine payroll
- BIR withholding tax
- SSS
- PhilHealth
- Pag-IBIG
- Government and company loans
- Cash advances
- Gadget / equipment installment deductions
- Employee accountability
- Configurable payroll bank export
- Payslip / payment advice generation
- Outstanding balance visibility
- Employee Self-Service (ESS)
- Manager Self-Service (MSS)
- HR administration
- Real-time storage usage
- Audit logging
- Role-based access control
- Reporting and CSV/XLSX/PDF export

This is a PROTOTYPE. Prioritize:
1. Correct architecture
2. Clean user experience
3. Modular design
4. Extensibility
5. Clear separation of data by organization
6. Payroll and obligation auditability
7. Dockerized deployment

Do not over-engineer microservices. Build a modular monolith first.

---

# 2. Recommended Technology Stack

Use this stack unless there is a strong technical reason to substitute:

## Frontend
- Next.js
- TypeScript
- Tailwind CSS
- shadcn/ui
- React Hook Form
- Zod
- TanStack Table
- Recharts

## Backend
- FastAPI
- Python 3.12+
- SQLAlchemy
- Alembic
- Pydantic

## Database
- PostgreSQL

## Supporting Services
- Redis
- Celery or RQ for background jobs
- MinIO for document storage
- Nginx reverse proxy

## Authentication
For prototype:
- JWT authentication
- Refresh tokens
- Role-Based Access Control

Design authentication so Keycloak or enterprise SSO can be integrated later.

## Reporting
- CSV
- XLSX
- PDF

## PDF Generation
Use a robust HTML-to-PDF engine such as WeasyPrint or equivalent.

## Docker
Use Docker Compose.

Expected services:

```yaml
frontend
backend
worker
postgres
redis
minio
nginx
```

Optional:
```yaml
mailhog
```

Use MailHog in development for email testing.

---

# 3. Design Principles

## 3.1 Multi-Company / Multi-Organization

The HRIS is enterprise-level.

A user may have access to:
- One organization
- Multiple organizations
- Entire enterprise group

Users MUST choose the organization they are working on before processing organization-level HR transactions.

Example:

```text
Enterprise Group
├── Company A
├── Company B
├── Company C
└── Company D
```

There must be two dashboard modes:

### Enterprise Dashboard
Shows consolidated data across all organizations the logged-in user is authorized to access.

### Organization Dashboard
Shows data only for the currently selected organization.

Every organization-specific database query must enforce:

```text
organization_id
```

Do not rely only on frontend filtering.

---

# 4. User Roles

Implement configurable RBAC.

Default prototype roles:

```text
Super Admin
Enterprise Admin
Company Admin
HR Director
HR Manager
HR Staff
Payroll Administrator
Payroll Approver
Recruitment Officer
Training Officer
Finance
Department Head
Supervisor
Employee
Consultant
Auditor
```

Example permissions:

```text
organization.view
organization.manage

employee.view
employee.create
employee.edit
employee.archive

salary.view
salary.edit

payroll.view
payroll.prepare
payroll.compute
payroll.approve
payroll.lock
payroll.export

loan.view
loan.create
loan.adjust

attendance.view
attendance.edit
attendance.approve

leave.view
leave.apply
leave.approve

documents.view
documents.upload
documents.confidential.view

reports.view
reports.export

storage.view
system.admin

audit.view
```

Sensitive permissions such as salary, payroll, disciplinary cases, and confidential files must be separate.

---

# 5. Master Data Architecture

The central entity must be PERSON rather than EMPLOYEE.

A person can have one or multiple employment / engagement records over time.

## Person

Fields:
- id
- uuid
- first_name
- middle_name
- last_name
- suffix
- preferred_name
- birth_date
- gender
- civil_status
- email
- mobile
- address
- emergency_contact
- TIN
- SSS number
- PhilHealth number
- Pag-IBIG number
- bank details
- profile image
- status

---

# 6. Employment / Engagement Types

Create engagement types:

```text
REGULAR
PROBATIONARY
PROJECT_BASED
FIXED_TERM
DAILY_PAID
HOURLY_PAID
PART_TIME
CONSULTANT_INDIVIDUAL
CONSULTANT_COMPANY
CONTRACTOR
OJT
TRAINEE
```

Every engagement must contain:

- person_id
- organization_id
- engagement_type
- employee_number or consultant_number
- start_date
- end_date
- regularization_date
- department
- position
- supervisor
- job_grade
- salary_basis
- base_rate
- payroll_group
- cost_center
- project
- work_site
- tax_profile
- statutory_profile
- benefits_profile
- status

Possible statuses:

```text
ACTIVE
INACTIVE
ON_LEAVE
SUSPENDED
SEPARATED
COMPLETED
TERMINATED
RESIGNED
RETIRED
```

---

# 7. Organization Structure

Support:

- Enterprise
- Organizations / companies
- Branches
- Sites
- Departments
- Divisions
- Teams
- Positions
- Job grades
- Salary bands
- Cost centers
- Supervisors
- Reporting hierarchy

---

# 8. Project Management for HR

Project-based manpower is a core feature.

## Project

Fields:
- project_code
- project_name
- organization_id
- client
- project_manager
- start_date
- target_end_date
- actual_end_date
- site
- cost_center
- status
- project_budget
- labor_budget

## Employee Project Assignment

Fields:
- engagement_id
- project_id
- assignment_start
- assignment_end
- site
- supervisor
- billable
- billing_rate
- pay_rate
- shift
- remarks

An employee can move from one project to another without losing employment history.

Dashboard must show:
- Active projects
- Personnel deployed per project
- Project labor cost
- Contracts expiring
- Assignments expiring
- Manpower by project

---

# 9. Consultant Management

Consultants must NOT automatically inherit employee payroll rules.

Support:

- Individual consultant
- Company consultant
- Retainer
- Per-hour
- Per-day
- Milestone-based
- Deliverable-based
- Project-based

Consultant contract fields:

- consultant
- organization
- contract_number
- start_date
- end_date
- fee_basis
- agreed_fee
- VAT status
- withholding profile
- BIR ATC
- withholding_rate
- deliverables
- payment_schedule
- project
- cost_center
- status

Consultant payment workflow:

```text
Contract
→ Deliverable / Timesheet
→ Approval
→ Fee Computation
→ Applicable Tax Withholding
→ Net Payment
→ Payment Advice
```

---

# 10. Dashboard

## Enterprise Dashboard

Show:

- Number of organizations
- Total active personnel
- Regular employees
- Probationary employees
- Project-based employees
- Consultants
- Fixed-term employees
- Active projects
- Current payroll status
- Payroll totals
- Pending approvals
- Contracts expiring in 30 / 60 / 90 days
- Employee distribution by organization
- Workforce cost
- Storage usage
- Backup status
- Recent activity

## Organization Dashboard

Show:

- Organization name
- Employee count
- Project-based count
- Consultant count
- Departments
- Active projects
- Payroll period status
- Gross payroll
- Total deductions
- Net payroll
- Pending leave approvals
- Pending overtime approvals
- Contracts expiring
- Manpower distribution
- Recent HR activity
- Storage used by organization
- Overall system storage

Organization selector must remain visible in the main header.

---

# 11. Real-Time Storage Monitoring

Dashboard must show:

```text
Used Storage
Available Storage
Total Capacity
Percentage Used
```

Breakdown:

- Employee documents
- Payroll reports
- Recruitment documents
- Training documents
- Database
- Backups
- Other

Also show usage per organization.

Storage thresholds:

```text
0-69%    NORMAL
70-79%   ADVISORY
80-89%   WARNING
90%+     CRITICAL
```

Implement a backend endpoint that obtains current mounted storage values.

For prototype, refresh every 30 seconds.

---

# 12. Employee Master / Digital 201 File

Employee profile tabs:

1. Personal
2. Employment
3. Compensation
4. Government IDs
5. Bank Information
6. Attendance
7. Leave
8. Payroll
9. Loans & Advances
10. Assets / Gadgets
11. Projects
12. Training
13. Performance
14. Documents
15. Disciplinary
16. History
17. Audit Trail

Documents stored in MinIO.

Support:
- Upload
- Download
- Category
- Expiry
- Confidential classification
- Retention
- Access log

---

# 13. Time & Attendance

Support:

- Time-in
- Time-out
- Shifts
- Rest days
- Holidays
- Grace period
- Late
- Undertime
- Overtime
- Night differential
- Official business
- Field assignment
- Attendance correction
- Timesheet approval

Attendance sources:
- Manual
- Web
- CSV import
- API-ready for biometric integration

---

# 14. Leave Management

Support configurable leave types:

- Vacation
- Sick
- Emergency
- Maternity
- Paternity
- Solo parent
- Bereavement
- Birthday
- Company-specific leave

Features:
- Accrual
- Credits
- Carry-over
- Expiry
- Conversion
- Approval workflow
- Leave calendar

---

# 15. Approval Workflow Engine

Build a generic approval workflow component.

Example:

```text
Request
→ Supervisor
→ Department Head
→ HR
→ Completed
```

Workflow must be configurable by:
- organization
- transaction type
- department
- amount
- employee type
- project

Supported transaction types include:

- Leave
- OT
- Attendance correction
- Payroll
- Cash advance
- Loan
- Expense
- Recruitment request
- Salary adjustment
- Offboarding

---

# 16. Philippine Payroll Engine

This is a major module.

Support payroll frequencies:

```text
MONTHLY
SEMI_MONTHLY
WEEKLY
DAILY
HOURLY
MILESTONE
RETAINER
```

## Earnings

- Basic salary
- Daily rate
- Hourly rate
- Overtime
- Night differential
- Holiday pay
- Rest day pay
- Allowances
- Bonuses
- Commission
- Incentives
- Reimbursements
- Retroactive adjustments
- Other taxable compensation
- Non-taxable compensation

## Deductions

- BIR withholding
- SSS
- PhilHealth
- Pag-IBIG
- SSS loan
- Pag-IBIG loan
- Company loan
- Salary loan
- Cash advance
- Gadget installments
- Cooperative loan
- Insurance
- Employee accountability
- Other deductions

---

# 17. Statutory Rules Engine

DO NOT hard-code government values inside payroll calculation logic.

Create effective-dated tables for:

```text
BIR
SSS
PhilHealth
Pag-IBIG
EC
Holiday rules
Minimum wage
Night differential
13th month
```

Example:

```text
rule_name
effective_from
effective_to
rule_version
parameters_json
created_by
created_at
```

Historical payroll must preserve the rule version used.

Once payroll is approved and locked, historical calculations must not change when future contribution rules are updated.

---

# 18. Payroll Lifecycle

Payroll statuses:

```text
DRAFT
CALCULATED
FOR_REVIEW
APPROVED
LOCKED
BANK_FILE_GENERATED
PAID
CLOSED
VOID
```

Workflow:

```text
Create Payroll Run
→ Load Eligible Personnel
→ Load Attendance
→ Compute Earnings
→ Compute Statutory Deductions
→ Apply Loans / Advances
→ Apply Adjustments
→ Calculate Net Pay
→ Validate
→ Review
→ Approve
→ Lock
→ Generate Bank File
→ Generate Payslips
→ Mark Paid
→ Close
```

---

# 19. Loans, Cash Advances & Employee Receivables

Build a full obligation ledger.

Types:

```text
SSS_LOAN
PAGIBIG_LOAN
COMPANY_LOAN
SALARY_LOAN
CASH_ADVANCE
TRAVEL_ADVANCE
EMERGENCY_LOAN
GADGET_INSTALLMENT
EQUIPMENT_INSTALLMENT
COOPERATIVE_LOAN
OTHER
```

Fields:

- person
- engagement
- organization
- obligation_type
- reference_number
- description
- principal
- interest
- total_amount
- amount_paid
- balance
- installment_amount
- start_period
- end_period
- status
- payroll_deductible
- direct_payment_allowed

Ledger entries:

```text
OPENING_BALANCE
NEW_LOAN
NEW_ADVANCE
INTEREST
CHARGE
PAYROLL_DEDUCTION
DIRECT_PAYMENT
LIQUIDATION
ADJUSTMENT
REVERSAL
CLOSING_BALANCE
```

Every payroll must preserve the exact balance as of that payroll period.

---

# 20. Gadget / Asset Management

Differentiate:

## Company Asset Accountability
No employee receivable.

Examples:
- Laptop
- Mobile phone
- SIM
- Access card
- PPE
- Tool
- Vehicle
- Uniform

## Employee-Payable Asset
Creates payroll receivable.

Examples:
- Gadget installment
- Device purchase
- Employee share

Fields:
- asset number
- item
- serial number
- issue date
- cost
- employee share
- installment
- outstanding balance
- returned date
- condition

---

# 21. Payslip & Payment Advice

Support generation for any selected period.

## Employee Payslip

Include:

### Header
- Company logo
- Company name
- Payroll period
- Employee name
- Employee number
- Department
- Position
- Employment type
- Project
- Cost center
- masked bank account

### Earnings
- Basic
- OT
- Holiday
- ND
- Allowances
- Commission
- Bonus
- Adjustments
- Gross pay

### Deductions
- BIR
- SSS
- PhilHealth
- Pag-IBIG
- Loans
- Advances
- Gadget deductions
- Other deductions

### Net Pay

### Outstanding Obligations Summary

Show as of selected payroll period:

| Description | Original Amount | Current Deduction | Total Paid | Remaining Balance |
|---|---:|---:|---:|---:|

Examples:
- Cash Advance
- Company Loan
- SSS Loan
- Pag-IBIG Loan
- Laptop Installment
- Mobile Phone
- Other employee receivable

Also show:
- Total outstanding balance
- Remaining installments where applicable

Important:
The balance must be historical as of the selected payroll period, NOT the current live balance.

## Consultant Payment Advice

Show:
- Consultant
- Contract
- Period
- Fee
- Expenses
- Gross amount
- applicable withholding
- deductions
- net payment
- outstanding consultant advances where applicable

---

# 22. Payslip Generation

Allow generation by:

- Organization
- Payroll period
- Person
- Department
- Project
- Payroll group
- Engagement type

Output:

```text
Individual PDF
Bulk PDF
ZIP of individual PDFs
ESS View
Email delivery
```

Keep issued payslips immutable after payroll lock.

If correction is required:
- create payroll adjustment
- generate revised payslip version
- preserve old version in audit history

---

# 23. Payroll Bank File Generator

This is a major required feature.

The system must allow payroll administrators to create custom bank upload templates.

## Bank Template

Fields:

- template_name
- bank_name
- template_version
- file_type
- delimiter
- encoding
- header_required
- footer_required
- date_format
- decimal_places
- filename_pattern
- active

Supported file formats:

```text
CSV
TXT
XLSX
FIXED_WIDTH
```

---

# 24. Configurable Bank Column Builder

Allow user to choose:

- columns
- order
- custom header name
- field mapping
- default value
- formatting
- padding
- required
- validation rules

Example:

```text
System Field      Output Header
bank_account  →   ACCOUNT_NO
full_name     →   ACCOUNT_NAME
net_pay       →   AMOUNT
payroll_date  →   VALUE_DATE
employee_no   →   REFERENCE
```

Use drag-and-drop column ordering.

---

# 25. Configurable Payroll Export Rows

Allow filtering by:

- organization
- payroll period
- branch
- department
- project
- cost center
- payroll group
- engagement type
- bank
- employment status
- selected personnel
- approved payroll only

Before generation show:

- employee count
- total amount
- validation errors
- missing bank accounts
- duplicate accounts
- invalid account numbers
- zero or negative net pay

Critical validation errors should block final bank file generation unless excluded by an authorized user.

---

# 26. Payroll Export Preview

Show table before download.

Example:

| Account No. | Account Name | Amount | Reference |
|---|---|---:|---|

Also show:

```text
Employee Count
Total Payroll Amount
Excluded Personnel
Validation Errors
```

---

# 27. Saved Bank Templates

Allow reusable templates such as:

```text
BDO Payroll Corporate
BPI Payroll
Metrobank Payroll
UnionBank Payroll
Security Bank Payroll
RCBC Payroll
Landbank Payroll
Custom
```

Do not assume the exact bank format.

The administrator must be able to configure the required layout.

Use versioning.

Historical payroll export must retain:
- template used
- template version
- generated file hash
- generated by
- generated timestamp

---

# 28. Generic Report Builder

Create a reusable report builder.

User can choose:

1. Dataset
2. Columns
3. Row filters
4. Sort
5. Group
6. Date range
7. Output format

Datasets:

- Employees
- Consultants
- Payroll
- Attendance
- Leave
- Loans
- Advances
- Assets
- Projects
- Recruitment
- Training
- Performance
- Statutory contributions

Exports:
- CSV
- XLSX
- PDF

Save report templates.

---

# 29. Recruitment

Prototype module should support:

- Manpower requisition
- Position
- Department
- Project requirement
- Applicant
- Resume
- Screening
- Interview
- Offer
- Hiring
- Convert applicant to employee / engagement

---

# 30. Onboarding

Checklist:

- Employee data
- IDs
- Government numbers
- Bank account
- NDA
- Contract
- Privacy consent
- Company policies
- Orientation
- Asset issuance
- Account request
- Training requirements

---

# 31. Performance

Support:

- KPI
- KRA
- Objectives
- Competencies
- Probationary evaluation
- Annual appraisal
- Self-assessment
- Supervisor rating
- Final rating
- Performance improvement plan

---

# 32. Training

Prototype:
- Course catalog
- Training assignment
- Training history
- Certificate
- Expiration
- Skills / competency tracking

Make API-ready for external LMS.

---

# 33. ESS — Employee Self-Service

Employee dashboard:

- Profile
- Attendance
- Leave
- OT
- Payslips
- Loan balances
- Cash advance balances
- Gadget installment balances
- Government contributions
- Benefits
- Training
- Documents
- Requests

Employee can only see their own data unless given additional roles.

---

# 34. Consultant Portal

Consultants can see:

- Contract
- Project
- Deliverables
- Approved fees
- Payment advice
- Withholding details
- Advances
- Outstanding balances
- Documents

---

# 35. MSS — Manager Self-Service

Manager dashboard:

- Team
- Attendance
- Leave approvals
- OT approvals
- Team contracts
- Project assignments
- Performance
- Training
- Headcount
- Team cost where permitted

---

# 36. HR Service Desk

Requests:

- Certificate of Employment
- Payroll concern
- Payslip concern
- Leave concern
- HMO
- Data correction
- ID replacement
- HR question
- Bank account update
- Government ID update

Fields:
- ticket number
- category
- priority
- assigned HR
- SLA
- status
- comments
- attachments

---

# 37. Offboarding

Support:

- Resignation
- End of project
- Contract expiration
- Termination
- Retirement

Checklist:

- Notice
- Clearance
- Asset return
- Loan balance
- Cash advance
- Final pay
- COE
- Exit interview
- IT account deactivation
- Final documents

---

# 38. Audit Trail

Audit all sensitive actions.

Fields:

```text
timestamp
user
organization
action
entity
entity_id
before
after
ip_address
user_agent
```

Audit examples:

- Salary changed
- Bank details changed
- Payroll recomputed
- Payroll approved
- Payroll locked
- Loan adjusted
- Bank export generated
- Payslip generated
- Payslip viewed
- Document downloaded
- Permission changed

---

# 39. Data Security

Prototype requirements:

- bcrypt / Argon2 password hashing
- JWT expiry
- refresh tokens
- CSRF considerations
- API validation
- SQL injection prevention
- file type validation
- upload size limits
- tenant filtering
- audit trail
- secret management using `.env`
- no credentials committed to Git

Future-ready:
- MFA
- Keycloak
- SSO
- LDAP
- Azure AD / Entra ID

---

# 40. Data Privacy

Provide:

- Privacy Notice page
- Data processing notice
- Consent record where applicable
- Data retention fields
- Access log
- Sensitive document flag
- Data export
- Archive
- Deactivation

Do not permanently delete employee historical payroll data through normal UI.

Use archive / inactive status.

---

# 41. Database Entity List

Minimum entities:

```text
users
roles
permissions
user_roles
role_permissions

enterprises
organizations
organization_users

people
engagements
employment_history

departments
divisions
teams
positions
job_grades
salary_bands
cost_centers
branches
sites

clients
projects
project_assignments

payroll_groups
payroll_periods
payroll_runs
payroll_run_people
payroll_earnings
payroll_deductions
payroll_adjustments
payroll_rule_versions

statutory_rule_sets
statutory_rules

loans
loan_transactions
cash_advances
advance_transactions

assets
asset_assignments
asset_receivables

attendance_logs
timesheets
shifts
holidays
overtime_requests

leave_types
leave_balances
leave_requests

bank_accounts
bank_export_templates
bank_export_columns
bank_export_runs

payslips
payment_advices

documents
document_access_logs

applicants
job_requisitions
job_applications
interviews

training_courses
training_assignments
training_records
certificates

performance_cycles
performance_reviews
performance_scores

approval_workflows
approval_workflow_steps
approval_instances
approval_actions

service_tickets

notifications

storage_metrics
storage_alerts

audit_logs
```

---

# 42. API Design

Use REST API.

Base path:

```text
/api/v1
```

Examples:

```text
POST   /auth/login
POST   /auth/refresh
GET    /me

GET    /organizations
POST   /organizations
GET    /organizations/{id}

GET    /people
POST   /people
GET    /people/{id}

GET    /engagements
POST   /engagements

GET    /projects
POST   /projects

GET    /payroll/periods
POST   /payroll/runs
POST   /payroll/runs/{id}/calculate
POST   /payroll/runs/{id}/approve
POST   /payroll/runs/{id}/lock

GET    /payroll/runs/{id}/payslips
POST   /payroll/runs/{id}/generate-payslips

GET    /loans
POST   /loans

GET    /bank-templates
POST   /bank-templates
POST   /payroll/runs/{id}/bank-export/preview
POST   /payroll/runs/{id}/bank-export/generate

GET    /reports
POST   /reports/preview
POST   /reports/export

GET    /system/storage
```

All organization-level endpoints must verify:
- authenticated user
- user has access to organization
- permission
- organization context

---

# 43. Frontend Pages

## Authentication
```text
/login
```

## Enterprise
```text
/dashboard
/organizations
```

## Organization
```text
/o/{organization_id}/dashboard
/o/{organization_id}/people
/o/{organization_id}/employees
/o/{organization_id}/consultants
/o/{organization_id}/projects
/o/{organization_id}/attendance
/o/{organization_id}/leave
/o/{organization_id}/payroll
/o/{organization_id}/loans
/o/{organization_id}/assets
/o/{organization_id}/recruitment
/o/{organization_id}/training
/o/{organization_id}/performance
/o/{organization_id}/reports
/o/{organization_id}/documents
/o/{organization_id}/settings
```

---

# 44. UI / UX Requirements

Use a modern enterprise admin interface.

Left navigation:

```text
Dashboard
People
Organization
Projects
Attendance
Leave
Payroll
Loans & Advances
Assets
Recruitment
Performance
Training
Reports
Service Desk
Administration
```

Top bar:

```text
Organization Selector
Global Search
Notifications
User Menu
```

Use:
- responsive cards
- clean tables
- filters
- saved views
- modal or drawer forms
- status badges
- confirmation dialogs

Do not use excessive gradients or animations.

Target:
- professional
- corporate
- government-ready
- desktop-first but responsive

---

# 45. Prototype Seed Data

Create realistic demo data.

Enterprise:

```text
Demo Enterprise Group
```

Organizations:

```text
Exigent Corporation
Expedia Solutions Specialist Inc.
GreatnessLab
Kyrios Solutions Inc.
```

Create at least:

- 50 employees
- 15 project-based employees
- 5 consultants
- 5 projects
- 5 departments
- 3 payroll periods
- sample payroll
- sample loans
- cash advances
- gadget installments
- sample payslips
- bank export templates

Use fictional employee names and account numbers.

Do not use real personal data.

---

# 46. Payroll Prototype Validation

Create automated unit tests for:

- monthly employee
- semi-monthly employee
- daily paid employee
- project-based employee
- consultant
- employee with loan
- employee with cash advance
- employee with gadget installment
- multiple deductions
- negative net pay prevention
- payroll lock
- historical obligation balance
- bank export validation

The exact statutory amounts for government contributions should live in seeded rule tables and be clearly marked as configurable prototype data.

Do NOT present prototype seeded statutory values as authoritative production rates.

---

# 47. Development Phases

## Phase 1 — Foundation

Build:

- Docker Compose
- database
- authentication
- RBAC
- enterprise
- organizations
- organization selector
- people
- engagements
- organization dashboard
- storage monitoring
- audit logging

Acceptance:
User can log in, select an organization and see only authorized organization data.

---

## Phase 2 — Workforce

Build:

- employee profile
- consultant profile
- departments
- positions
- projects
- project assignments
- attendance
- leave
- approval workflow

---

## Phase 3 — Payroll Core

Build:

- payroll period
- payroll run
- earnings
- deductions
- statutory engine
- loans
- cash advances
- gadget deductions
- payroll review
- payroll approval
- payroll lock

---

## Phase 4 — Payslips

Build:

- employee payslip
- consultant payment advice
- obligation balance summary
- PDF
- batch generation
- ESS viewing
- versioning

---

## Phase 5 — Bank Export & Reports

Build:

- bank templates
- column mapper
- row filters
- preview
- validation
- CSV/TXT/XLSX
- report builder
- saved report templates

---

## Phase 6 — Additional HR

Build:

- recruitment
- onboarding
- performance
- training
- assets
- service desk
- offboarding

---

# 48. Prototype Definition of Done

The prototype is considered successful when:

1. Docker Compose starts the whole application.
2. User can log in.
3. User can access enterprise dashboard.
4. Enterprise dashboard displays:
   - number of companies
   - total personnel
   - employee classifications
   - active projects
   - payroll summary
   - real-time storage usage
5. User can select an organization.
6. Organization context is enforced on backend.
7. User can manage:
   - employees
   - project-based employees
   - consultants
8. User can assign staff to projects.
9. Payroll run can be created.
10. Payroll can compute sample earnings/deductions.
11. Loan / cash advance / gadget deductions work.
12. Payroll can be approved and locked.
13. Payslip can be generated for a selected period.
14. Payslip shows outstanding obligation balances as of that period.
15. Consultant payment advice can be generated.
16. Custom bank export template can be configured.
17. Columns can be reordered.
18. Rows can be filtered.
19. Preview validates payroll data.
20. CSV can be generated.
21. Generic report builder can export CSV/XLSX.
22. Storage dashboard updates automatically.
23. Audit trail records sensitive transactions.

---

# 49. Claude Code Instructions

You are Claude Code acting as the lead software architect and senior full-stack developer.

Follow these rules:

1. Read this entire specification before coding.
2. Create a clean project structure first.
3. Create `README.md`.
4. Create `docker-compose.yml`.
5. Create `.env.example`.
6. Create database schema and migrations.
7. Seed demo data.
8. Implement features in phases.
9. Commit architecture that remains extensible.
10. Do not place all backend logic in one file.
11. Do not place payroll formulas directly inside controllers.
12. Use service-layer architecture.
13. Use repository/data access separation where practical.
14. Keep organization scoping centralized.
15. Write tests for critical payroll and security logic.
16. Use clear comments for Philippine payroll rule assumptions.
17. Treat statutory rules as effective-dated configuration.
18. Never silently modify locked payroll.
19. Maintain immutable audit history.
20. Validate every bank export before generation.
21. Use fictional seed data only.
22. Do not claim the prototype is legally payroll-compliant until production rules have been independently reviewed and validated.

---

# 50. Recommended Repository Structure

```text
enterprise-hris/
│
├── frontend/
│   ├── app/
│   ├── components/
│   ├── features/
│   ├── hooks/
│   ├── lib/
│   ├── services/
│   └── types/
│
├── backend/
│   ├── app/
│   │   ├── api/
│   │   ├── auth/
│   │   ├── core/
│   │   ├── models/
│   │   ├── schemas/
│   │   ├── repositories/
│   │   ├── services/
│   │   ├── payroll/
│   │   ├── statutory/
│   │   ├── reports/
│   │   ├── storage/
│   │   └── audit/
│   ├── migrations/
│   └── tests/
│
├── worker/
│
├── nginx/
│
├── scripts/
│
├── docs/
│
├── docker-compose.yml
├── .env.example
├── README.md
└── spec.md
```

---

# 51. Initial Claude Code Execution Task

After reading this specification:

## Step 1
Generate the project scaffold.

## Step 2
Create:
- Docker Compose
- PostgreSQL
- Redis
- MinIO
- Backend
- Frontend
- Worker
- Nginx

## Step 3
Implement:
- authentication
- RBAC
- enterprise
- organizations
- organization selector
- dashboard
- storage monitoring

## Step 4
Seed:
- Demo Enterprise Group
- 4 organizations
- users and roles
- demo personnel
- projects

## Step 5
Verify:
- containers start
- frontend loads
- backend health endpoint works
- database migrations run
- login works
- organization scoping works
- dashboard works
- storage endpoint works

Then continue sequentially through the development phases.

At the end of each phase:
1. run tests
2. fix failures
3. update README
4. document incomplete items
5. proceed to next phase

Do not stop after generating only a mock UI.
The prototype must have a functioning backend, database, authentication, payroll data model, report generation, and Docker deployment.

---

# 52. Future Enhancements

Design so these can be added later without major rewrite:

- Direct SSS integration
- Direct PhilHealth integration
- Direct Pag-IBIG integration
- BIR reporting integration
- Bank API / host-to-host payroll transfer
- LDAP
- Active Directory
- Microsoft Entra ID
- MFA
- Biometric devices
- Mobile application
- GPS attendance
- LMS integration
- Accounting / ERP integration
- AI HR assistant
- Payroll anomaly detection
- Employee turnover analytics
- OCR document processing
- Electronic signatures
- Certificate generation
- Multi-country payroll

---

# 53. Final Product Vision

The target product is not merely an employee database.

It should evolve into:

```text
ENTERPRISE HUMAN CAPITAL
+
WORKFORCE MANAGEMENT
+
PROJECT MANPOWER MANAGEMENT
+
PHILIPPINE PAYROLL
+
EMPLOYEE FINANCIAL OBLIGATION TRACKING
+
HR ANALYTICS
+
REPORTING
```

The prototype architecture must support this future direction.
