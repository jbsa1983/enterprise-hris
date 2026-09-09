# Enterprise HRIS Prototype

A Docker-based, **multi-company Philippine HRIS** prototype: enterprise/organization
tenancy, people & engagements, project manpower, a config-driven Philippine
payroll engine, obligation ledger, RBAC, audit logging, and real-time storage
monitoring.

> ⚠️ **Prototype disclaimer.** Seeded statutory values (BIR / SSS / PhilHealth /
> Pag-IBIG) are **illustrative placeholders**, not authoritative rates. This
> prototype is **not** payroll-compliant until production rules are independently
> reviewed and validated. All seed names and account numbers are fictional.

---

## Quick start

```bash
# 1. (Optional) review environment defaults
cp .env.example .env    # already created for you; edit if desired

# 2. Build and start everything
docker compose up --build

# 3. Open the app
#    App (via nginx):     http://localhost:8080
#    API docs (Swagger):  http://localhost:8080/docs
#    MinIO console:       http://localhost:9011   (minioadmin / minioadmin)
#    MailHog:             http://localhost:8026
```

> **Host ports** are remapped to avoid clashing with other local stacks:
> Postgres `55432`, Redis `6380`, MinIO `9010`/`9011`, MailHog `1026`/`8026`,
> backend `8000`, app `8080`. Containers still talk to each other over the
> Docker network on their standard internal ports, so nothing else changes.

First boot seeds the demo database automatically (idempotent — safe to re-run).

### Demo credentials

| Role         | Email                      | Password   |
|--------------|----------------------------|------------|
| Super Admin  | `admin@demo-hris.local`    | `Admin123!` |
| Org user     | `hr.exg@demo-hris.local`   | `Demo123!` |
| Payroll admin| `payroll.exg@demo-hris.local` | `Demo123!` |

Per-org users exist for each organization code (`exg`, `ess`, `glb`, `kyr`) and
role prefix (`hr`, `payroll`, `approver`, `auditor`) — e.g. `approver.kyr@demo-hris.local`
— all with password `Demo123!`. Org users only see the organization(s) they are
granted; org scoping is enforced on the backend, not in the UI.

---

## Running in production (clean, admin-operated system)

The demo boot fills the database with sample companies and people. For a **real
deployment** you want a clean system that the Superadmin builds out.

1. **Set strong secrets** in `.env`:
   ```
   JWT_SECRET_KEY=<64+ random chars>      # python -c "import secrets;print(secrets.token_urlsafe(64))"
   POSTGRES_PASSWORD=<strong>
   MINIO_ROOT_PASSWORD=<strong>
   DEFAULT_ADMIN_EMAIL=you@company.com     # your first Superadmin
   DEFAULT_ADMIN_PASSWORD=<strong>
   SEED_MODE=minimal                        # RBAC + one Superadmin, NO demo data
   ```
2. **Start with the production overrides** (multi-worker backend, built frontend, no hot-reload):
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
   ```
3. **Log in as the Superadmin** and build your organization from the Administration area
   (see below). On a dedicated server, point nginx at ports 80/443 and terminate TLS there.

`SEED_MODE=minimal` seeds only the permission catalog, the default roles, and the
one Superadmin — nothing else. Everything after that is created through the UI.

## Backups & restore

Your data lives in Docker volumes (Postgres + MinIO), **not** in git. Back it up:

```bash
scripts/backup.sh                        # → ~/hris-backups/<timestamp>/
```

Each backup contains a compressed PostgreSQL dump (`postgres_hris.dump`, restorable
with `pg_restore`), the MinIO documents (`minio_data.tar.gz`), a `manifest.txt`, and
`SHA256SUMS`. Old backups are pruned after `RETENTION_DAYS` (default 14). Override
with `BACKUP_DIR` / `RETENTION_DAYS` env vars.

**Schedule it** (daily 02:00):

```bash
scripts/install-backup-schedule.sh        # macOS: launchd agent · Linux: prints a cron line
```

**Restore** a backup (overwrites current data; stops the app while it works):

```bash
scripts/restore.sh ~/hris-backups/<timestamp>
```

Keep at least one copy **off this machine** (a backup on the same disk doesn't
survive disk loss) — sync `~/hris-backups` to external/cloud storage. Note: backups
contain live data but **not** `.env`; store your secrets separately and securely.

## Administration & access control (Superadmin)

The **Superadmin** runs the system from the sidebar's **Administration** section:

- **Users** (`/admin/users`) — create / edit users, set an initial password or reset one,
  toggle active/superadmin, and assign **roles** and **organization access**. A user only
  sees the organizations they're granted; every org-scoped request re-checks this on the backend.
- **Roles & Scopes** (`/admin/roles`) — a role is a named set of **permissions**. Edit any
  role's permission matrix, create new roles, or delete custom ones. Assigning a role to a
  user grants exactly those permissions (the "scopes and limitations"). Guardrails prevent
  deleting system roles in use and removing the last active Superadmin.

Everything is **editable through the app**: add/edit/archive **people** and their engagements,
create **organizations**, **departments**, **positions**, **projects**; create **payroll
periods**, **runs**, and **compute** them (idempotent — recomputing never double-counts);
edit **statutory rules** (effective-dated). Non-Superadmins are limited to what their roles allow.

> Payroll-compliance note: the statutory rates ship as clearly-marked illustrative values.
> Before running real payroll, edit the effective-dated statutory rules (Admin → statutory
> rules API/UI) with figures validated by your payroll/accounting team. This software is not
> certified payroll-compliant.

## Architecture

Modular **monolith** (not microservices), matching the spec:

```
┌─────────┐    ┌──────────────┐    ┌────────────┐
│ nginx   │──▶ │ frontend     │    │ backend    │──▶ postgres
│ :8080   │    │ Next.js :3000│    │ FastAPI    │──▶ redis
│         │──▶ │              │    │ :8000      │──▶ minio
└─────────┘    └──────────────┘    └────────────┘
                                    ┌────────────┐
                                    │ worker      │ (Celery, backend image)
                                    └────────────┘
```

### Services (`docker-compose.yml`)

| Service    | Purpose                                   | Port(s)        |
|------------|-------------------------------------------|----------------|
| `nginx`    | Reverse proxy (entrypoint)                | 8080           |
| `frontend` | Next.js + TypeScript + Tailwind           | 3000 (internal)|
| `backend`  | FastAPI + SQLAlchemy + Pydantic           | 8000           |
| `worker`   | Celery background jobs                     | —              |
| `postgres` | PostgreSQL 16                             | 55432→5432     |
| `redis`    | Cache / Celery broker                     | 6380→6379      |
| `minio`    | S3-compatible document storage            | 9010 / 9011    |
| `mailhog`  | Dev email capture                         | 1026 / 8026    |

### Repository layout

```
enterprise-hris/
├── backend/
│   ├── app/
│   │   ├── api/v1/        # REST routers (auth, orgs, people, dashboard, storage, audit)
│   │   ├── core/          # config, database, security, deps (org scoping + RBAC), rbac catalog
│   │   ├── models/        # SQLAlchemy models (person-centric master data)
│   │   ├── schemas/       # Pydantic I/O models
│   │   ├── services/      # dashboard / storage / audit services
│   │   ├── repositories/  # data-access base
│   │   ├── payroll/       # payroll computation engine
│   │   ├── statutory/     # effective-dated statutory rule resolution
│   │   ├── reports/ storage/ audit/   # reserved for later phases
│   │   ├── seed/          # idempotent bootstrap + fictional demo data
│   │   └── worker.py      # Celery app
│   ├── migrations/        # Alembic (scaffolded)
│   └── tests/             # payroll + statutory unit tests
├── frontend/              # Next.js App Router (login, dashboards, people, projects)
├── nginx/                 # reverse proxy config
├── worker/                # notes (runs from backend image)
├── docker-compose.yml
├── .env.example
└── README.md
```

---

## What's implemented (Phases 1–6)

**Foundation & core (Phase 1–2)**
- ✅ **Dockerized** stack — one command boots the whole system.
- ✅ **Auth** — JWT access + refresh tokens, bcrypt hashing, `/auth/login`, `/auth/refresh`, `/auth/me`.
- ✅ **RBAC** — 16 default roles, granular permission codes, permission-guarded endpoints.
- ✅ **Multi-company tenancy** — enterprise → organizations, per-user org grants, centralized org scoping (`require_org_access`).
- ✅ **Person-centric master data** — people + multiple engagements (12 engagement types).
- ✅ **Org structure, projects & manpower** — departments, positions, cost centers, projects, assignments.
- ✅ **Consultants** — individual & company, EWT (not employee statutory) in payroll.
- ✅ **Enterprise + Organization dashboards** — classifications, projects, payroll totals, expiring contracts, pending approvals, distribution.
- ✅ **Real-time storage monitoring** — live disk usage, category breakdown, NORMAL/ADVISORY/WARNING/CRITICAL thresholds, 30s auto-refresh, per-org usage.
- ✅ **Attendance & leave** — attendance logs, leave types/balances, leave & overtime requests with approve/reject.
- ✅ **Generic approval-workflow engine** — configurable multi-step workflows per org + transaction type, instances, step-by-step actions.
- ✅ **Audit logging** — append-only writer + read API on all sensitive actions.

**Payroll & payslips (Phase 3–4)**
- ✅ **Config-driven Philippine payroll engine** — effective-dated statutory rule sets (no hard-coded rates), SSS/PhilHealth/Pag-IBIG/withholding, loan/advance/gadget deductions, negative-net-pay prevention, rule-version pinning per run.
- ✅ **Obligation ledger** — loans, cash advances, gadget installments with ledger transactions.
- ✅ **Payroll lifecycle** — approve / lock transitions (locked payroll is immutable).
- ✅ **Payslips & consultant payment advices** — **PDF generation via WeasyPrint**, stored in MinIO; individual PDF, **bulk combined PDF**, and **ZIP of individual PDFs**.
- ✅ **Historical obligation balances** — payslips show outstanding balances *as of the payroll period* (reconstructed from the ledger), not the live balance.
- ✅ **Payslip versioning** — regeneration supersedes prior versions; old versions retained for audit.

**Bank export & reports (Phase 5)**
- ✅ **Configurable bank-export builder** — templates (bank, file type, delimiter, encoding), configurable columns (field mapping, output header, formatting, padding, order), CSV / TXT / XLSX / fixed-width output, template versioning.
- ✅ **Export preview + validation** — employee count, total, missing/invalid/duplicate accounts, zero/negative net pay; critical errors block generation unless an authorized user overrides.
- ✅ **Export audit** — every generated file records template + version, SHA-256 hash, row count, total, who/when.
- ✅ **Generic report builder** — 6 datasets (employees, consultants, payroll, loans, projects, statutory contributions), column selection, filters, sort; export to **CSV / XLSX / PDF**; saved report templates.

**Additional HR (Phase 6)**
- ✅ **Recruitment** — requisitions, applicants, applications, stage advancement, **convert applicant → person + engagement**.
- ✅ **Performance** — cycles, reviews (self/supervisor/final rating).
- ✅ **Training** — courses, assignments, completion.
- ✅ **Service desk** — tickets with categories, priority, status.
- ✅ **Onboarding / offboarding** — standard checklists with completion tracking.
- ✅ **Assets** — company vs. employee-payable, issuance/return.

**Self-service**
- ✅ **ESS** — employees see only their own payslips & engagements.
- ✅ **MSS / HR** surfaced through org-scoped module pages.

**Seed & tests**
- ✅ **Seed data** — Demo Enterprise Group, 4 organizations, ~50 employees, 15 project-based, 5 consultants, 5 projects, obligations, assets, payroll periods + computed runs, bank templates, leave/attendance, workflows, service tickets, performance, training, ESS users, and payslip records.
- ✅ **Tests** — 16 unit tests covering payroll computation, statutory resolution, **historical obligation balances**, **bank-export validation**, and the report builder.

## Known limitations / not covered

- Statutory values are **illustrative prototype data**, not authoritative rates.
- Digital 201 document upload UI, biometric attendance ingestion, email delivery
  of payslips (MailHog is wired for dev), and external integrations (SSS/BIR/bank
  host-to-host, SSO/LDAP) are future work — see `spec.md` §52.
- Schema is created via `create_all`; Alembic is scaffolded for when it stabilizes.

---

## Running tests

```bash
# inside the backend container
docker compose exec backend pytest

# or locally (needs Python 3.12 + backend/requirements.txt)
cd backend && pytest
```

Tests cover: monthly / semi-monthly / daily-paid / project-based / consultant
computation, employees with loans / cash advances / gadget installments, multiple
deductions, **negative net-pay prevention**, statutory rule-version resolution,
**historical obligation balance as of a period**, **bank-export validation**
(missing / invalid / duplicate accounts, zero-net-pay), and report-builder
filter/sort.

---

## Key design decisions

- **Statutory rules are data, not code.** `statutory_rule_sets` rows are
  effective-dated and versioned; payroll runs pin the version they used so
  historical results never change when rules are updated. See
  `backend/app/statutory/service.py`.
- **Org scoping is centralized** in `backend/app/core/deps.py::require_org_access`
  and applied to every org-scoped endpoint — never relying on frontend filtering.
- **Service layer** keeps payroll formulas out of controllers
  (`backend/app/payroll/engine.py`).
- **Schema bootstrap.** The prototype creates tables via SQLAlchemy `create_all`
  at startup for reliability; Alembic is scaffolded in `backend/migrations/` for
  when the schema stabilizes.

---

## Troubleshooting

- **Ports already in use** (8080, 5432, 9000, 9001, 8025): stop the conflicting
  service or change the host port mapping in `docker-compose.yml`.
- **Re-seed from scratch:** `docker compose down -v` (drops volumes), then
  `docker compose up --build`.
- **Backend logs:** `docker compose logs -f backend`.
