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

### RBAC changes ship as migrations

`RolePermissionSeeder` is the single definition of the permission matrix, but
`php artisan migrate` does not run seeders — so before this, a fresh checkout came
up with whatever grants its database happened to have. That is how one machine
showed Settings to admins and another hid it.

Editing the matrix in the seeder is therefore only half the change. Add a
migration that invokes it, so the command everyone already runs after a pull
applies it and the migrations table records that it has:

```php
public function up(): void
{
    (new RolePermissionSeeder())->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}
```

See `2026_09_03_120000_sync_role_permissions.php`. The seeder stays the source of
truth; the migration only decides *when* every database picks the change up.

Note that the seeder uses `syncPermissions`, which replaces a role's grants
outright. Roles hand-edited in the admin's Roles & Permissions screen are reset
to the matrix when such a migration runs.

### After pulling a change to the admin UI

`public/build` is gitignored, and Tailwind only emits the utility classes it can
see in the source at build time. Pulling a Blade change therefore brings the
markup but not the CSS it needs, and a control styled with classes the old build
never generated renders unstyled — a checkbox that toggles nothing visible reads
as a control that does not work at all. Run the build after any pull that touches
`resources/`:

```
npm install   # only when package.json changed
npm run build # or: npm run dev, which rebuilds as you edit
```

### Local dev on Windows

`php artisan serve` runs the PHP **CLI**, where OPcache is off by default. Composer's
`files` autoloader pulls in 120 files on every request — 80 of them from
`thecodingmachine/safe`, which dompdf's CSS parser depends on — so without OPcache
the server re-reads and re-compiles all of them each time. Measured on this repo:

| | first request | steady state |
| --- | --- | --- |
| OPcache off | 3.87 s | 18 ms |
| OPcache on | 0.34 s | 19 ms |

Steady state looks fine on Linux because the OS page cache makes the re-reads cheap.
On Windows it is not cheap: Defender re-scans each file on every open, so the cost is
paid on every request and can exceed PHP's 30-second limit. That surfaces as
`Maximum execution time of 30 seconds exceeded` pointing at an arbitrary file — a
Blade view, a vendor trait, an autoloader stub — because PHP reports a timeout
wherever it happens to be, not where the time went.

Find the php.ini that `artisan serve` actually uses — `php --ini`, and read the
`Loaded Configuration File` line. It is often a standalone PHP install rather than
the one bundled with XAMPP, since XAMPP's Apache and the CLI can be different builds.

Stock php.ini ships an `[opcache]` section with every line commented out, and no
`zend_extension` line at all, so the settings below are ignored until the extension
is loaded. Uncomment and edit in place rather than appending a second `[opcache]`
block — duplicate keys later in the file win.

```ini
zend_extension=opcache          ; missing by default; without it the rest is ignored
opcache.enable=1
opcache.enable_cli=1            ; artisan serve is CLI, where the default is 0
opcache.memory_consumption=192
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1   ; keep these two so edits still apply instantly
opcache.revalidate_freq=0
```

Then exclude the project folder and `php.exe` from Windows Defender real-time
scanning (Settings → Virus & threat protection → Exclusions).

Verify with `php -r "var_dump(opcache_get_status(false)['opcache_enabled']);"` — it
should print `true`.

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
- **Late control & arrival times** — Settings define the academy **day start** (default 09:00) and a **grace window** (default 15 min). A biometric punch after start + grace is automatically marked *late*, and the punch time is stored as the record's **arrival time** (`daily_attendance_records.arrived_at`) and shown next to the status; staff can also type an arrival time when marking manually.
- **Filters & reports** — date, status, optional course filter and search all combine; the loaded register prints to a branded **PDF**. Each student name opens a **per-student attendance page** (today / yesterday / this week / this month / all time / **custom from → to** + status filter) with its own compact overview and PDF report. "Marked by" always shows the staff member's role (or *Biometric*).
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
