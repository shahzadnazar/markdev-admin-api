# UI/UX Audit — MarkDev LMS (Student Portal + Admin Portal)

Date: 2026-07-25 · Scope: `markdev-student-portal` (React 19 + Tailwind v4) and `markdev-admin-api` (Laravel 12 Blade + Alpine.js + Tailwind v4).
Method: full-codebase inspection of both frontends (layouts, routes, shared components, states, accessibility, responsiveness), every finding verified against file:line.

Severity scale: **Critical** (breaks usability/accessibility or shows wrong data) · **High** (materially hurts daily operation) · **Medium** (inconsistency/debt that compounds) · **Low** (polish).

Overall assessment: both portals already share one design language (brand blue `#0C5ABD`, tokenized type scale, shared component kits that are genuinely used) and have near-complete loading/empty/error coverage. This audit therefore focuses on the gaps: accessibility of focus/drawers, a handful of remaining marketing-style sections, status-map duplication, and missing primitives (Table, ConfirmDialog, Breadcrumbs).

---

## Part A — Student Portal (`markdev-student-portal`)

Inventory: 28 route pages, 24 `src/components/ui/*` primitives, 7 `src/components/shared/*` components, 5 layout files. Toasts (sonner) mounted globally in `App.tsx`; Radix dialogs/menus/tooltips in place.

### A1. Layout & shell

| Sev | Finding | Where |
| --- | --- | --- |
| High | Mobile nav drawer is a hand-rolled `motion.div` — no `role="dialog"`, no focus trap, no Escape | `app-shell.tsx:40-48` |
| High | No breadcrumbs anywhere; deep routes (`/courses/:id/lessons/:id`, `/quizzes/:id/results/:attemptId`, …) have no trail | shell-wide |
| Medium | No desktop sidebar collapse; fixed 280 px (`--spacing-sidebar`) always consumed | `index.css:128`, `sidebar.tsx:82-88` |
| Low | No bottom navigation on mobile; hamburger drawer is the only path to 15 destinations | `topbar.tsx:47-55` |

### A2. Pages

| Sev | Finding | Where |
| --- | --- | --- |
| High | Profile page renders `null` while loading — the only page with no loading/empty/error state | `profile-page.tsx:55` |
| Medium | Dashboard greeting oversized (40 px + marketing line "keep the momentum going") | `dashboard-page.tsx:39-51` |
| Medium | Payments hero banner has decorative sheen gradient + watermark icon (marketing pattern) | `payments-page.tsx:126-137` |
| Medium | Auth layout is a marketing splash: `p-12` gradient panel, `blur-3xl` glow orbs, benefit bullets | `auth-layout.tsx:5-25,51` |
| Medium | Two pages bypass `PageHeader` and hand-roll headers (dashboard, help-article) | `dashboard-page.tsx`, `help-article-page.tsx` |
| Low | Three competing title sizes: `headline-xl` metrics in page bodies vs `headline-md` titles | `quiz-result-page.tsx:136`, `assignment-detail-page.tsx:538`, `quiz-detail-page.tsx:250` |
| Low | Ad-hoc `mb-8` section spacing in 8 files vs `mb-5/6` elsewhere | dashboard, attendance, courses, search, quiz pages |

### A3. Shared kit gaps

Present and used: Button (primary/secondary/ghost/success/destructive/link × sm/md/lg/icon), Badge (7 variants), Alert (4 variants), Card family, Dialog, DropdownMenu, Tooltip, Tabs, Select, Pagination (`PaginationBar` with result counts), Skeleton, Spinner, EmptyState, ErrorState, FormField, StatCard, PageHeader.

| Sev | Missing | Impact |
| --- | --- | --- |
| High | `Table` primitive | raw `<table>` duplicated (`leaderboard-table.tsx:65`, `transactions-card.tsx:136`) with divergent header classes |
| High | `ConfirmDialog` | destructive actions fire with no confirmation (`bookmarks-page.tsx:203`, logout `topbar.tsx:118`) |
| Medium | Toast wrapper | `sonner` imported directly in 14 files, inline styling at `App.tsx:12-20` |
| Medium | `warning` Button variant | amber tokens exist; Badge/Alert/StatCard have warning variants, Button does not |
| Medium | `Drawer` primitive | mobile nav hand-rolled; lesson rail misuses Dialog |
| Low | `Breadcrumbs`, `StickyFormActions`, `Accordion`, `StatusBadge` registry | see A4/A1 |

### A4. Consistency

| Sev | Finding | Where |
| --- | --- | --- |
| **Critical** | Same invoice status renders differently: `open` → "Active" in the list but "Open" in the detail dialog | `invoices-card.tsx:23` vs `invoice-dialog.tsx:15` |
| Medium | Status→badge maps duplicated in 5 files (attendance ×2, quiz, transactions, invoices, invoice-dialog) | see files above |
| Medium | Token bypass: raw Tailwind type scale (`text-base`/`text-sm`) in attendance (6×) and payments (3×) | `attendance-page.tsx:187-189`, `payments-page.tsx:191-193` |
| Medium | Seven arbitrary sizes below the token floor (`text-[9px]`–`text-[11px]`) | announcements, topbar, brand-mark, calendar, attendance |
| Medium | Chart colors re-typed as hex literals, will drift from tokens | `progress-activity-card.tsx:29-31`, `activity-chart-card.tsx:23-25,120` |
| Medium | `TooltipProvider` mounted locally in one card instead of app root | `transactions-card.tsx:184` |
| Low | Ad-hoc pills/cards instead of `<Badge>`/`<Card>` in 4 spots | payments, certificates, announcements, lesson-sidebar |

### A5. States

Coverage is strong: `ErrorState` in 23 page files, `Skeleton` in 29, `EmptyState` in 22, consistent `disabled={isPending}` + `<Spinner>` submit pattern in 10 files.

| Sev | Finding | Where |
| --- | --- | --- |
| High | Profile page: no states at all (see A2) | `profile-page.tsx:55` |
| Medium | `EmptyState`/`ErrorState`/`PageLoader` hardcode `py-16` (128 px); pages fight it with overrides; empty-state titles same size as page titles | `empty-state.tsx:18,25`, `error-state.tsx:23`, `page-loader.tsx:18` |
| Medium | Inconsistent `aria-hidden` on `Spinner` (double screen-reader announcement) | `login-page.tsx:162` vs `profile-page.tsx:261` |

### A6. Accessibility

| Sev | Finding | Where |
| --- | --- | --- |
| **Critical** | Global focus ring uses `box-shadow`, clipped by 14 `overflow-hidden` containers → keyboard focus invisible inside cards | `index.css:154-159`; e.g. `course-card.tsx:31`, `leaderboard-table.tsx:63` |
| High | Mobile drawer: no focus trap / Escape (see A1) | `app-shell.tsx:40-48` |
| Medium | Touch targets under 40 px: `size="sm"` buttons are 32 px (20 usages), pagination `size-8`, lesson-topbar 36 px | `button.tsx:25`, `pagination.tsx:54-89` |
| Medium | `aria-label` on bare `<span>` status dots (not reliably announced) | `notifications-page.tsx:230`, `announcements-page.tsx:143` |
| Medium | `DropdownMenuItem` removes its focus ring, relies on low-contrast bg tint | `dropdown-menu.tsx:37` |
| Low | Strengths to preserve: all 19 icon buttons have `aria-label`; zero placeholder-only inputs; `scope="col"` + `sr-only` captions on tables; Radix dialogs trap focus correctly | — |

### A7. Tokens & typography

`@theme` defines fonts (Hanken Grotesk / Inter / JetBrains Mono), a full M3-style color ramp incl. success/warning extensions, a 9-step paired type scale, 2 shadows, layout tokens.

| Sev | Finding |
| --- | --- |
| Medium | No `--radius-*` tokens — radii hardcoded per component (`rounded-lg` button, `rounded-2xl` card, `rounded-xl` alert) |
| Medium | No dark-mode block despite complete `on-*`/`inverse-*` sets |
| Low | No motion/duration tokens; framer timings re-declared inline in ~20 files |

### A8. Responsive

| Sev | Finding | Where |
| --- | --- | --- |
| Medium | Payments hero `<dl class="flex gap-8">` with two currency headlines squeezes/overflows at ~360 px | `payments-page.tsx:167-183` |
| Medium | Leaderboard is the only list with no mobile fallback (`min-w-[36rem]` + horizontal scroll) | `leaderboard-table.tsx:64-65` |
| Low | Attendance register is a CSS-grid pseudo-table with `aria-hidden` header and per-breakpoint duplicated badges | `attendance-page.tsx:371-478` |
| Low | Courses/assignments/quizzes handle mobile well (responsive filters, scrollable tab strips) | — |

### A9. Tables, lists, pagination

| Sev | Finding | Where |
| --- | --- | --- |
| Medium | Three renderings for the same job: real table / grid pseudo-table / card lists | leaderboard, attendance, everything else |
| Medium | Result counts duplicated: `PaginationBar` renders "Showing X–Y of N" AND four pages hand-roll a second count | `courses-page.tsx:244`, `assignments-page.tsx:170`, `quizzes-page.tsx:174`, `attendance-page.tsx:360` |
| Medium | `PaginationBar` returns `null` when single page → total count disappears | `pagination.tsx:39` |
| Low | Pagination otherwise a strength: 1 component, 11 usages, `aria-current`, labelled prev/next | — |

---

## Part B — Admin Portal (`markdev-admin-api`)

Inventory: 52 admin screens, 24 shared Blade components, 138-line `app.css` token/utility layer. Confirm dialogs are mostly specific and interpolated; Cancel is never red; Edit/View are neutral — good baselines.

### B1. Shell & layout

| Sev | Finding | Where |
| --- | --- | --- |
| High | No breadcrumbs; topbar shows only a mono page title; deep pages rely on inconsistent ad-hoc Back buttons | `components/admin/layout.blade.php:46` |
| Medium | Mobile drawer: no Escape close, no focus trap, doesn't close on navigation | `layout.blade.php:26-32` |
| Medium | Notification/user dropdowns lack `aria-expanded` / `role="menu"` / focus return | `layout.blade.php:52-115` |
| Medium | Uncached DB queries in the layout on every render (notifications, maintenance flag, pending-fee badge) | `layout.blade.php:4-5,51`, `sidebar.blade.php:79` |
| Low | Sidebar fixed 280 px, no collapse; user identity duplicated sidebar + topbar | `sidebar.blade.php` |

### B2. Component inventory & gaps

Present: btn (5×3), badge (6), card, table (+footer slot), filter-bar, page-header, empty-state, confirm-form, modal, 47-glyph icon set, stat-widget, form/{input,select,textarea,label,error,toggle}, summary-strip, doc-field, vendor pagination.

| Sev | Missing | Impact |
| --- | --- | --- |
| High | Toast system | flash is one hard-coded block, single message, success/error only, drops the 2nd message, no pause-on-hover (`layout.blade.php:143-172`) |
| High | Row overflow (three-dot) menu + ellipsis icon | rows carry up to 4 visible icon actions with no menu option |
| High | Alert/banner component | inline banners hand-rolled 3 different ways |
| High | Icon-button component | row actions written 2 ways; the raw `rounded-lg p-2` variant has **no focus ring at all** |
| Medium | Tabs component | 5 hand-rolled segmented controls with 2 divergent active styles |
| Medium | Tooltip, drawer, skeleton, sticky form actions, form section, bulk-action bar, status-badge resolver, result-count partial | see B4–B8 |

### B3. Screens

45 pages use `x-page-header`; **7 use a newer hand-rolled compact header** (attendance daily/student, enrollments/create, billing plans index/show, payment-methods index/form) — h1 28 px vs 24 px, margin 32 px vs 20 px. Standardize on the compact pattern via a `compact` prop.

| Sev | Finding | Where |
| --- | --- | --- |
| High | 4 index pages have **no pagination**: roles (`@foreach`, also no empty state), media grid, payment-methods, attendance register | `roles/index:20`, `media/index:34`, `payment-methods/index:19`, `attendance/index:55` |
| High | Modals rendered **inside `<tbody>`** (invalid HTML, 1 modal per row) | `audit-logs/index:97`, `biometric/devices:114` |
| High | Audit-log rows are click-only — no keyboard path to the detail modal | `audit-logs/index:69` |
| Medium | Large forms inside `max-w-md` modals (enroll popup ≈ 8 fields + preview; attendance update) | `enrollments/create:163-258`, `attendance/daily:287-352` |
| Medium | Dashboard chart SVG duplicated in 2 files with hard-coded hex | `dashboard/index:53-69`, `dashboard/instructor:49-64` |
| Medium | Reports page: no filters, no date range | `reports/index` |
| Low | Settings: long single form, `border-t` sections only, no sticky save | `settings/edit` |

### B4. Consistency

| Sev | Finding | Detail |
| --- | --- | --- |
| High | `text-label-md` is **undefined** — 6 headings render at inherited size | `students/form:165,182`, `students/show:132,152,177`, `instructors/show:95` |
| Medium | 281 arbitrary Tailwind values form an unofficial type scale (`text-[11px]`×85, `text-[10px]`×45, `text-[13px]`×40) — not tokens | app-wide |
| Medium | Status→badge maps inlined 15+ times (invoice map duplicated verbatim, transaction map ×2, attendance ×4, course ×2) | billing/attendance/courses views |
| Medium | 8 pages hand-roll GET filter forms (h-9 fields) instead of `x-filter-bar` (≈42 px fields) — height + label drift | students, instructors, attendance, enrollments, plans, submissions |
| Medium | Summary-chip markup re-hand-rolled twice despite `summary-strip` component existing | `plans/index:43-58`, `plans/show:59-84` |
| Medium | Non-legitimate inline styles: `white-space: nowrap` ×11, static `max-width` ×6, avatar `width/height` ×4 (build-proof workarounds — convert once CSS build is reliable) | various |
| Medium | Dead v3 `tailwind.config.js` contradicts `app.css` (declares Figtree; pipeline reads Inter) | repo root |
| Low | Dead `.prose-simple` CSS block; `.check`/`.scroll-thin` hard-code token hexes; duplicate icon paths (`arrow-down` ≡ `chevron-down`) | `app.css:90-104,68,110,120` |

### B5. Buttons & actions

| Sev | Finding | Where |
| --- | --- | --- |
| Medium | No `success` btn variant — Approve renders blue vs Reject red (should be green vs red) | `btn.blade.php:12-18`, `billing/submissions` |
| Medium | Rows with 3–4 visible icon actions and no overflow menu | courses, quizzes, users (trashed), help, biometric |
| High | **Zero duplicate-submit protection** anywhere — double-clicking Save double-posts (incl. financial records) | all forms |
| Low | Good: all 28 Cancel buttons are ghost; destructive = red; Edit/View neutral | — |

### B6. Forms

| Sev | Finding | Where |
| --- | --- | --- |
| High | `required` renders no asterisk anywhere (label component has no required prop) — ~15 required fields on the student form are indistinguishable | `form/label.blade.php` |
| High | No validation error summary / scroll-to-first-error on any form (errors can sit off-screen) | e.g. `students/form` (4 cards) |
| High | No sticky action bar on any long form | `students/form:196`, `settings/edit:59` |
| Medium | 4 competing section-header styles across big forms (blue bars / eyebrow / border-t / none) | students, courses, settings, others flat |
| Medium | Field-height drift: `.field` ≈42 px vs `h-9` overrides ×9 vs `h-[42px]` label hacks ×2 | filter bars |
| Low | Two file-upload experiences (rich doc-field vs raw input) | `courses/form:68` |

### B7. Tables

| Sev | Finding | Where |
| --- | --- | --- |
| High | Numeric/currency columns left-aligned — 51 `font-mono` numeric cells never set `text-right` | plans, quizzes, courses, roles… |
| Medium | 7 headers right-aligned over left-aligned cells | categories, roles, courses, quizzes |
| Medium | Result count only renders when `hasPages()`; 6 pages hand-roll a duplicate "Showing X–Y of Z"; 18 paginated pages show no count on single-page results | vendor partial |
| Medium | No sticky headers; no bulk selection anywhere | app-wide |
| Low | Good: `.th/.td/.row` centralized, uniform ~48 px rows, hover-only striping | `app.css:72-82` |

### B8. Feedback & accessibility

| Sev | Finding | Where |
| --- | --- | --- |
| **Critical** | ~40 raw icon buttons/links have **no focus style** (only `.field` has any focus rule) and 9+ have **no accessible name**; every `confirm-form` trigger across 21 files is unlabelled by default | `app.css`, `confirm-form.blade.php:15`, categories/quizzes/users/courses/roles/announcements/help/invoices/instructors |
| Medium | Touch targets: btn-sm ≈26 px, raw icon buttons 32 px, media grid 22 px | `btn.blade.php:7` |
| Medium | Color-only encodings: progress bars without labels/`role="progressbar"`, attendance pills, summary dots | enrollments, plans, attendance |
| Medium | Hand-rolled filter fields with no `<label>`/`id` (the sr-only pattern exists but isn't applied everywhere) | `students/index:42,48`, others |
| Medium | Flash auto-dismisses at 5 s, no pause-on-hover; `session('warning')`/`status` never rendered in admin | `layout.blade.php:143-172` |
| Low | Good: specific confirm messages; PIN error surfacing; branded role-aware 403 | — |

---

## Classification summary & remediation plan

**Counts:** Critical 3 · High 21 · Medium 38 · Low 22.

### Critical (fix first)
1. Portal: focus ring clipped by `overflow-hidden` (keyboard focus invisible) — `index.css:154`.
2. Portal: invoice status naming mismatch list vs detail — `invoices-card.tsx:23` / `invoice-dialog.tsx:15`.
3. Admin: no focus style + no accessible name on ~40 row actions — `app.css` + 10 views.

### Shared components to CREATE
Portal: `Table`, `ConfirmDialog`, `Breadcrumbs`, `Drawer` (nav), Toast wrapper, `warning` Button variant, `StatusBadge` registry, `StickyFormActions`.
Admin: toast service, row overflow menu (+ellipsis icon), alert banner, icon-button, tabs, form-section, sticky form actions, error summary, status-badge resolver, result-count partial, tooltip, breadcrumbs.

### Components to REFACTOR
Portal: app-shell mobile drawer → Radix Dialog; EmptyState/ErrorState/PageLoader density; PaginationBar single-page count; chart colors → CSS vars; TooltipProvider → root.
Admin: `btn` (+success variant, submit-once), `form/label` (+required), `confirm-form` (labels/focus/modal base), `page-header` (+compact), vendor pagination (always show count), `x-filter-bar` variants, modal consolidation (3 systems → 1).

### Screens needing MAJOR restructuring
Admin: `roles/index` (pagination+empty state), `media/index` (pagination), `attendance/index` (pagination/sticky header), `audit-logs/index` (single keyboard-accessible modal), `biometric/devices` (modal out of tbody), `reports/index` (filters). Portal: none — visual cleanup only.

### Screens needing only VISUAL cleanup
Portal: dashboard (heading), payments (hero decoration), auth layout (splash), leaderboard (mobile), attendance (semantic table), profile (states). Admin: the 7 compact-header pages (standardize via component), forms (sections/asterisks), tables (numeric alignment).

### Implementation order (per brief)
1. **Foundations** — focus rings, tokens (`text-label-md`, radius/motion), button variants + submit-once, required asterisks, status registries, alert/toast primitives.
2. **Global layout** — breadcrumbs, drawer a11y, header standardization, dropdown a11y.
3. **Critical admin screens** — pagination gaps, tbody modals, numeric alignment, form sections, error summaries.
4. **Critical student screens** — dashboard/payments/auth de-marketing, profile states, leaderboard mobile.
5. **UI states** — result counts, sticky headers, skeletons (admin), empty states.
6. **Responsive & a11y QA** — keyboard pass, touch targets, long-content tests.

