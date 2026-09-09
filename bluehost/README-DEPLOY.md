# GEEK Group HRIS — Bluehost / Zoom Hosting (PHP + MySQL) build

A shared-hosting build of the HRIS: **plain PHP 8 + PDO** API and a **static
front-end**, no Node/Python/Docker/Redis. Runs on Bluehost/Zoom standard shared
hosting (PHP, MySQL, phpMyAdmin, SSL, File Manager, SSH, cron).

> This is a separate build from the Dockerized version (which stays in the repo
> root). Same features and UI; different runtime so it fits shared hosting.

## What's in here

```
bluehost/
├── db/
│   ├── schema.sql        # MySQL schema (import via phpMyAdmin)
│   └── seed.php          # RBAC + Superadmin + statutory rules (+ optional demo)
├── public/               # ← upload the CONTENTS of this folder to public_html
│   ├── index.html        # front-end (foundation page; full React SPA drops in here)
│   ├── .htaccess         # API routing + SPA fallback + HTTPS
│   ├── api/index.php     # PHP API front controller
│   ├── app/              # PHP source (protected by app/.htaccess)
│   │   ├── settings.sample.php   # copy to settings.php and edit
│   │   └── ...
│   └── storage/          # documents/PDFs (protected)
└── docker-compose.test.yml  # LOCAL testing only (not used on the host)
```

## Deploy on Bluehost / Zoom (cPanel)

1. **Create the database** — cPanel → *MySQL Databases*: create a database and a
   user, add the user to the database with **All Privileges**. Note the DB name,
   user, and password (they'll be prefixed, e.g. `cpaneluser_hris`).

2. **Set PHP 8.1+** — cPanel → *MultiPHP Manager* → select your domain → PHP 8.1
   or 8.2.

3. **Import the schema** — cPanel → *phpMyAdmin* → select your DB → *Import* →
   upload `db/schema.sql` → Go. (Or via SSH: `mysql -u USER -p DBNAME < db/schema.sql`.)

4. **Upload the app** — put the **contents of `public/`** into `public_html`
   (File Manager → Upload/extract a zip, or `git clone` via SSH then move files).
   Put the `db/` folder inside `public_html/db` too (it's protected by its
   `.htaccess`), so you can run the seeder.

5. **Configure secrets** — in `public_html/app/`, copy `settings.sample.php` to
   **`settings.php`** and edit:
   ```php
   'db_host' => 'localhost',
   'db_name' => 'cpaneluser_hris',
   'db_user' => 'cpaneluser_hris',
   'db_pass' => 'YOUR_DB_PASSWORD',
   'jwt_secret' => 'PASTE_A_LONG_RANDOM_STRING',   // php -r "echo bin2hex(random_bytes(48));"
   ```

6. **Seed the first Superadmin** (SSH):
   ```bash
   cd ~/public_html
   DEFAULT_ADMIN_EMAIL=you@company.com DEFAULT_ADMIN_PASSWORD='StrongPass!23' \
     php db/seed.php --minimal
   ```
   `--minimal` = RBAC + one Superadmin + statutory rules (clean start). Omit
   `--minimal` to also load a small demo dataset. Delete `public_html/db` after
   seeding if you prefer.

7. **Enable SSL** — cPanel → *SSL/TLS Status* → run **AutoSSL** (Let's Encrypt).
   Then edit `public_html/.htaccess` and **uncomment the HTTPS redirect** block.

8. **Open your domain** — sign in with the Superadmin you seeded.

### Upload limits (attendance CSV, documents)
Create `public_html/.user.ini`:
```
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 120
```

### Scheduled jobs (cron)
cPanel → *Cron Jobs*. Examples (adjust paths):
```
# nightly database backup
0 2 * * *  mysqldump -u USER -pPASS DBNAME > ~/backups/hris_$(date +\%F).sql
# (future) batch payslip / report generation
```

## Local testing (optional, on your Mac with Docker)
```bash
cd bluehost
docker compose -f docker-compose.test.yml up -d
docker compose -f docker-compose.test.yml exec -T mysql mysql -uhris -phris hris < db/schema.sql
docker compose -f docker-compose.test.yml exec -T php php /app/db/seed.php
# open http://localhost:8090   (admin@demo-hris.local / Admin123!)
docker compose -f docker-compose.test.yml down    # stop
```

## Security notes
- `app/` and `storage/` are denied direct web access via `.htaccess`.
- `settings.php` holds secrets and is git-ignored — never commit it.
- Passwords use PHP `password_hash` (bcrypt); tokens are HS256 JWT (`hash_hmac`).

## Status & roadmap
**Working now (tested on PHP 8 + MySQL 8):**
- Auth (`/auth/login|refresh|me`), RBAC + org-scoping, audit logging
- Enterprise & organization dashboards, organizations, people, storage monitoring
- **Payroll**: periods, runs, **compute** (idempotent, statutory + loan ledger),
  approve/lock
- **Payslips**: generate (versioned), list, and a **printable payslip** with
  historical obligation balances (open it and use the browser's *Save as PDF*)
- **Employee Self-Service** (`/me/*`): profile, payslips (self-generate + print),
  leave, attendance, loans, contributions, change password
- **Admin**: users, roles & permission scopes, **bulk-provision employee logins**,
  organizations
- **Full React SPA** front-end (the same GEEK UI as the Docker build) — static
  files talking to the PHP API. Pages whose backend is ported (dashboards, people,
  payroll, payslips, ESS, admin) are fully live; the rest render and light up as
  their controllers are ported.

**Porting next:** bank export, loans/projects-budget/attendance-import/13th-month/
recruitment/assets/reports controllers (the SPA pages already exist and call them).

### Rebuilding the SPA (optional; only if you change the UI source in `spa/`)
```bash
cd spa && npm install && npm run build   # outputs spa/dist
cd .. && ./package.sh                     # re-bundles the ZIP
```
The deployable ZIP already contains the built SPA — you don't need Node on the host.

> Payslips are delivered as a **printable HTML page** (Save-as-PDF) in this shared-
> hosting build. A true binary-PDF path (mPDF via cron for bulk) can be added.
