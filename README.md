# MarkDev — Admin & API

**Learn • Build • Grow**

The Laravel backend of the MarkDev Learning Management System: a premium Blade + Alpine admin portal and the versioned REST API (`/api/v1/*`) that powers the React student portal (`markdev-student-portal`).

## Tech stack

| Concern | Choice |
| --- | --- |
| Framework | Laravel 12 (PHP 8.4) |
| Database | MySQL (SQLite for local/testing) |
| Admin UI | Blade + TailwindCSS v4 + Alpine.js |
| Auth | Laravel Breeze (web) + Sanctum bearer tokens (API) |
| RBAC | spatie/laravel-permission — fully database-driven |
| Files | spatie/laravel-medialibrary + public disk |
| Exports | maatwebsite/excel (CSV/XLSX) |
| PDFs | barryvdh/laravel-dompdf (certificates, invoices) |
| Ops | Queues, Scheduler, spatie/laravel-backup |

## Getting started

```bash
composer install
cp .env.example .env          # point DB_* at MySQL (or set DB_CONNECTION=sqlite)
php artisan key:generate
php artisan migrate --seed    # RBAC matrix + demo data
php artisan storage:link
npm install && npm run build
composer run dev              # serves app + queue + logs + vite
```

Seeded accounts (password: `password`):

| Role | Email |
| --- | --- |
| Super Admin | superadmin@markdev.test |
| Admin | admin@markdev.test |
| Manager | manager@markdev.test |
| Instructor | instructor@markdev.test |
| Student | student@markdev.test |

Background workers: `php artisan queue:work` and `php artisan schedule:work` (backups, invoice past-due sweeps, token pruning).

## Architecture

- **`routes/api.php`** — versioned student-facing REST API under `/api/v1`, Sanctum-authenticated. The contract is defined by the portal repo's `docs/API.md` and TypeScript types; API Resources here serialize to exactly those shapes.
- **`routes/web.php`** — Breeze auth + the admin portal (`/admin/*`), gated by role/permission middleware. Students cannot access the panel.
- **RBAC** — roles `super-admin`, `admin`, `manager`, `instructor`, `student` with a `module.action` permission matrix seeded in `RolePermissionSeeder`. Nothing is hardcoded: gates check permission names only, and Super Admin passes every gate via `Gate::before`.
- **Audit trail** — `App\Support\AuditLogger` + the `Auditable` model trait record every create/update/delete/restore/force-delete with old/new values, plus login/logout/failed-login events, IP, browser, OS, device, URL and HTTP method. Only Super Admin and Admin can view/export the log.
- **Soft deletes** — all important models; restores and force deletes are audit-logged.

## Biometric attendance devices

Fingerprint/face terminals (ZKTeco, ESSL, Hikvision, or any bridge software) can mark attendance automatically:

1. **Register the device** in Admin → Learning → Biometric. You get a one-time `X-Device-Key`. Each device maps to a course and an optional session start + late-grace window.
2. **Give students a biometric id** (Admin → Users → edit) matching the id enrolled on the terminal.
3. **Push punches** to the ingestion endpoint (single or batches up to 500):

```http
POST /api/v1/biometric/punches
X-Device-Key: mdk_…
Content-Type: application/json

{"punches": [{"biometric_id": "1001", "punched_at": "2026-07-16 17:05:00", "direction": "in"}]}
```

The first punch of the day becomes a `present` or `late` attendance record (never downgrading manual entries); replays are deduplicated; unknown ids are stored as *unmatched* and can be reprocessed after enrolling the student. Offline devices are covered by a **CSV import** (`biometric_id, punched_at[, direction]`) on the punch-log page. Every raw punch is kept for audit.

## The frontend

The student experience lives in [`markdev-student-portal`](https://github.com/shahzadnazar/markdev-student-portal) (React 19). Point its `VITE_API_URL` at this app's URL.
