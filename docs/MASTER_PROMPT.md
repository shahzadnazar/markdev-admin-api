# MarkDev LMS — Master Prompt

> The original project brief (from `MarkDev_LMS_Master_Prompt_for_Claude.docx`) that both applications were built from. Kept here as the project's source-of-truth requirements document.

## Master prompt

You are a Principal Software Architect, Senior Full Stack Engineer, Senior UI/UX Designer, and Product Architect.

Read all attached Markdown (.md) files first. Treat them as the SINGLE SOURCE OF TRUTH for the project. Never ignore the design system, typography, colors, spacing, UX philosophy, layouts, or components.

## Project

Build a complete production-ready Learning Management System (LMS) for:

- **Brand:** MarkDev
- **Tagline:** Learn • Build • Grow

Create TWO applications sharing ONE Laravel backend.

### Application 1

React 19 · Vite · TypeScript · TailwindCSS v4 · Shadcn UI · React Router · TanStack Query · React Hook Form · Zod · Framer Motion · Recharts · Axios

### Application 2

Laravel 12 · PHP 8.4 · MySQL · Blade · TailwindCSS · AlpineJS · Laravel Sanctum · Laravel Breeze (Auth) · Spatie Laravel Permission · Queues · Scheduler · Laravel Excel · Media Library · Backup

Laravel is the only backend and exposes versioned REST APIs (`/api/v1/*`). React must consume ONLY Laravel APIs.

## Authentication

Login · Logout · Remember Me · Forgot Password · Reset Password · Change Password · Profile · Sanctum authentication · Protected routes · Role & Permission checks

## Role hierarchy

**Super Admin** — Full ownership · Manage Admins · Manage Roles & Permissions · System Settings · Backup · Maintenance · Audit Logs

**Admin (Multiple)** — Unlimited admins · Manage Managers · Manage Instructors · Manage Students · Courses · Lessons · Quizzes · Assignments · Attendance · Certificates · Reports · Audit Logs

**Manager** — Manage Students · Manage Instructors · Courses · Enrollments · Reports · Attendance · Announcements

**Instructor** — Own Courses · Lessons · Modules · Videos · Resources · Assignments · Quizzes · Attendance · Student Progress

**Student** — Dashboard · Courses · Lessons · Videos · Assignments · Quizzes · Certificates · Attendance · Notifications · Profile

Never hardcode permissions. Use spatie/laravel-permission. Everything must be database driven.

## Database

Design a normalized database for: Users, Roles, Permissions, Courses, Categories, Modules, Lessons, Videos, Resources, Assignments, Assignment Submissions, Quizzes, Questions, Answers, Enrollments, Attendance, Certificates, Notifications, Activity Logs, Audit Logs, Media, Settings, Student Progress, Bookmarks, Comments, Discussions, Payments (future), Invoices.

## Audit log system

Create enterprise audit logging. Only Super Admin and Admin can access.

**Track:** Login · Logout · Failed Login · Password Reset · User CRUD · Role changes · Permission changes · Course CRUD · Lesson CRUD · Assignment CRUD · Quiz CRUD · Attendance · Certificates · Settings · Media · Imports · Exports · Errors · System Events

**Store:** user, role, action, module, record id, old/new values (JSON), IP, browser, OS, device, URL, HTTP method, timestamp.

**Support:** Search, Filters, Date Range, Export CSV/Excel, Pagination.

## Soft delete

Use SoftDeletes for important models. Support Restore and Force Delete. Every restore and force delete must create an Audit Log.

## React student portal

Build first. No mock data. Use TanStack Query. Repository pattern. Reusable hooks. Modern premium UI.

**Features:** Dashboard, Courses, Course Details, Lesson Player, Video Player, Assignments, Quizzes, Certificates, Attendance, Progress, Leaderboard, Announcements, Notifications, Bookmarks, Search, Calendar, Profile, Settings, Help Center.

## Laravel admin

Build after React. Premium UI matching React. No AdminLTE. No Filament. Inspired by Linear, Stripe, Notion, Vercel, Supabase.

**Features:** Dashboard, Analytics, Users, Managers, Instructors, Students, Courses, Lessons, Assignments, Quiz Builder, Certificates, Attendance, Announcements, Media Library, Roles, Permissions, Audit Logs, Reports, Settings, Notifications.

**Admin dashboard widgets:** Students, Instructors, Courses, Lessons, Assignments Pending, Quiz Attempts, Attendance, Recent Enrollments, Recent Activities, Latest Audit Logs, Server Health, Queue Status, Backup Status, Storage Usage.

## Design

Follow the uploaded markdown files exactly. Replace Lumina branding with MarkDev. Maintain premium SaaS feel. Responsive. Accessible. Minimal. Heavy whitespace. Micro-interactions. Framer Motion. Soft gradients. Elegant shadows.

## Project structure

- `markdev-student-portal/` — React frontend
- `markdev-admin-api/` — Laravel application (Blade Admin, REST API, Sanctum, RBAC, Audit Logs, Notifications, Reports, Scheduler, Queue, Media Library)

## Output order

1. Analyze all markdown files.
2. Summarize requirements.
3. Design architecture.
4. Database schema.
5. Laravel migrations/models.
6. REST APIs.
7. React Student Portal.
8. Laravel Admin Portal.
9. Testing.
10. Optimization.
11. Security.
12. Deployment.

Always explain every generated file before generating code. Never use placeholder code. Never skip files. If context fills, automatically continue until the entire project is complete.
