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

## Instructor workspace

Instructors log into the same `/admin` panel but see a classroom-scoped workspace (`RestrictsToInstructor` trait — every course-linked query is limited to courses where they are the assigned instructor):

| Area | Instructor access |
| --- | --- |
| Dashboard | Own-classroom variant: my courses/students, grading queue, scoped attendance %, schedule |
| Courses / curriculum | Full builder (modules, lessons, quiz questions) for **own courses only**; new courses auto-assign them as instructor |
| Enrollments | Read-only "my students" list for their courses |
| Assignments & quizzes | Create, edit, grade — own courses only |
| Attendance | Mark & review sheets for own courses |
| Announcements | Post/edit/delete to **one of their own courses** — never academy-wide |
| Media library | View & upload |
| Users, billing, reports, settings, biometric devices, audit logs | No access |

Admins manage the faculty from **Admin → People → Instructors**: directory with active/inactive filters, per-instructor profile (courses, students, pending grading, upcoming schedule), and quick add via the user form with the role pre-selected.

## Student management

Students are managed in a dedicated module (**Admin → People → Students**), not the Users screen — Users now covers staff only:

- **Registration form** mirroring the printed MarkDev admission form: personal information (father name, DOB, gender, address, CNIC, guardian contact, qualification, applied course), emergency contact, office-use section (joining date, fees, reference), and the terms & conditions block. Each admission gets a sequential `MD-<year>-0001` registration number.
- **Documents** — profile picture, CNIC/B-Form copy and last degree/certificate (JPG/PNG/WEBP/PDF, **max 1 MB each**), with live client-side previews on upload and inline previews on the student profile. The profile picture doubles as the account avatar in the panel and the student portal.
- **Directory** — search by name/email/reg #/CNIC, active/inactive tabs, course filter, cohort stats.
- **Optional on registration**: enroll into a course right away and split the total fee into a monthly installment plan (reuses the billing engine's due-day / grace / daily-fine logic).
- A portal account is created automatically; leave the password blank to auto-generate one (shown once in the success message).

## Daily attendance register

**Admin → Learning → Daily Attendance** tracks every active student once per day (separate from per-course Class Attendance):

- Each row starts **Not marked** with a green **Mark** button — pick Present / Late / Absent / Leave plus optional remarks. Marking stores the exact date-time and who marked it.
- Once marked, the row switches to **View** and **Update**. Corrections require the **attendance security PIN** (stored hashed; set in System → Settings, demo seed uses `1234`) and a **compulsory written reason** — all corrections are stamped (by whom, when, why), shown in the View dialog and recorded in the audit log. Wrong PINs are rate-limited (5/minute).
- **Mark remaining present** fills in everyone still unmarked for the day; biometric punches auto-fill the daily register too (first punch = present/late, never overwriting staff entries).
- Gated by the `attendance.daily` permission (super admin, admin, manager — not instructors).
- Students see their own history on the portal's Attendance page via `GET /api/v1/attendance/daily`.

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
