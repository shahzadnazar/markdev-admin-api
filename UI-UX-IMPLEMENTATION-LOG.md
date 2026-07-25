# UI/UX Implementation Log — MarkDev LMS

Running checkpoint record for the phased implementation approved on top of `UI-UX-AUDIT.md` / `DESIGN-SYSTEM.md`. Each checkpoint records scope, files, validation, and commit.

---

## Checkpoint 0 — Audit + Phase 1 foundations (previous session)

- Delivered `UI-UX-AUDIT.md` (3 Critical / 21 High / 38 Medium / 22 Low) and `DESIGN-SYSTEM.md`.
- Foundations shipped: outline-based focus rings (both apps), Radix-dialog mobile drawer, shared billing status registry (fixed "Active"/"Open" mismatch), warning button variant (portal), submit-once guard + required asterisks + success confirm variant + 9 labelled row actions (admin), `text-label-md` token defined, dead CSS removed.
- Validation: portal tsc+build clean; admin 195/195 tests; browser checks (focus outline compiled, drawer role/Escape) green.
- Commits: portal `Design-system foundations (Phase 1, student portal)`, admin `Design-system foundations (Phase 1, admin portal)`.

## Checkpoint 1 — Approved brand palette migration (Phase 2 entry)

**Directive:** replace `#0C5ABD` with the approved palette (`primary-600 #124389` main, `700 #0F376F` hover, `500 #1D5AA6` bright, `100 #DCE9F7` container, `50 #EEF4FB` canvas, accent `#1FBBEB`). Token-level migration only — no per-screen edits.

**Changed (portal):** `src/index.css` `@theme` primary ramp + surface-ice + shadows + brand gradient; `App.tsx` toast shadow → `var(--shadow-elevated)`; new `lib/css-token.ts` so the two chart files read `--color-primary` at runtime instead of hex literals (closes audit A4 chart-drift finding).
**Changed (admin):** `resources/css/app.css` `@theme` (primary/deep/surface-ice/shadows + new `--color-primary-bright`, `--color-accent`); inline-SVG dashboard charts ×2, brand-mark gradient, and the two DomPDF stylesheets updated to the new hex (PDFs cannot read CSS vars); dead v3 `tailwind.config.js` deleted (unreferenced; contradicted the v4 CSS pipeline).

**WCAG verification (computed):**
| Pair | Ratio |
| --- | --- |
| `#124389` on white / white on `#124389` | 9.59:1 ✓ |
| white on `#0F376F` (hover) | 11.69:1 ✓ |
| `#1D5AA6` on white | 6.85:1 ✓ |
| body text on `#EEF4FB` canvas | 15.44:1 ✓ |
| `#0F376F` on `#DCE9F7` container | 9.49:1 ✓ |
| accent `#1FBBEB` on white | 2.24:1 — **decorative use only**, never filled buttons with white text (matches directive) |

No screen required rejecting the approved colour; focus rings, hover states and disabled states all derive from the tokens and inherit the new values.

**Validation:** portal build ✓ · admin build ✓ · admin tests 195/195 ✓.

## Checkpoint 2 — Phase 2: layout & navigation

**Admin** (`fae6c87 ui: rebuild admin shell and page headers`):
- `x-page-header` rewritten as the single compact standard: breadcrumb `crumbs` array (label ⇒ url|null), 24 px title, 13 px description, optional `meta`/actions slots. Converted the stragglers (billing plans/payment-methods, enrollments/create, attendance daily/student) and added crumbs to every detail screen (students/instructors/courses/invoices show, quiz builder, lesson editor).
- Collapsible sidebar: desktop toggle (double-chevron, dynamic `aria-label`), collapsed width 76 px, labels/badges/section headers hidden, icons centered, per-item `title` tooltips; state persisted in `localStorage` (`mdv.sidebar.collapsed`) and restored on load.
- Dropdown a11y: notification + user menus got `aria-expanded`/`aria-haspopup`, and `Escape` now returns focus to the trigger.

**Portal** (`21d2368 ui: rebuild portal page headers with breadcrumbs and portal-scale type`):
- `PageHeader` gained `crumbs` (react-router `Link`s, `aria-current="page"`), replacing the decorative eyebrow on deep pages (course, assignment, quiz, quiz result, help article).
- Oversized `headline-xl/lg` titles brought down to `headline-md` (24 px) on detail screens; dialog titles reduced likewise.
- Auth screens rebuilt as a centered card (brand mark + "Student portal" + white card + help line) — the marketing split-panel with gradient orbs and feature bullets is gone.

**Validation:** portal `tsc -b` + build ✓ · admin tests 195/195 ✓ · browser check of collapse persistence and crumbs ✓.

## Checkpoint 3 — Phase 3: tables, forms, feedback

**Admin** (`44884c1 ui: optimize admin tables, forms and feedback`):
- Pagination: roles (25/page), media library (24/page), and later payment methods (25/page, in `6e6437f`); the shared paginator view now always prints "N–M of T" even on single-page results, and duplicated hand-written count lines were removed from six list footers.
- Numeric alignment: `.td-num` utility (`text-align:right; font-variant-numeric:tabular-nums`) applied to money/count columns on plans, invoices, transactions, quizzes, roles, courses, categories; matching `th` right-aligned. *Design decision:* the class-attendance sheet is deliberately **not** paginated — it is a bulk-marking register where staff mark a whole class in one pass.
- Forms: new `x-form.section`, `x-form.errors-summary` (top-of-form list linking each field) and sticky `x-form.actions` (Cancel + submit, always visible); applied to users, settings, students, announcements, help articles, assignments, payment methods, courses, roles, plans. Required fields marked with a visible asterisk + `sr-only` "(required)"; submit buttons disable after first click.
- Modals out of `<tbody>`: the biometric device edit dialogs (real invalid HTML — a `<div>` inside `<tbody>`) moved below the table. *Validity note:* the audit-log per-row `<template>` elements were **kept** — `<template>` is a script-supporting element and is valid inside `<tbody>`; the per-row Alpine teleports remain listed as performance debt, not a correctness bug.
- Flash messages: layout now renders stacked success/warning/error flashes with icons, dismiss buttons, auto-hide with pause-on-hover, and `role`/`aria-live` semantics.
- Audit-log rows made keyboard-operable (`tabindex=0`, `role=button`, Enter/Space).

**Validation:** admin tests 195/195 ✓ · view spot-checks in browser ✓.

## Checkpoint 4 — Phase 4: de-marketing & dashboards

**Portal** (`7ab22a2 ui: simplify student portal workflows (de-marketing pass)`):
- Dashboard greeting reduced to 24 px with a task-focused line ("Your classes, deadlines and progress at a glance."); payments hero decoration removed; leaderboard gets a stacked mobile list (`md:hidden`) instead of a squeezed table; profile no longer returns `null` while loading (PageLoader); empty/error/loader blocks tightened to portal scale.

**Admin** (`6e6437f ui: surface attention-required work on the admin dashboard`):
- Dashboard now opens with an **Attention required** card — pending fee verifications, past-due installments (defaulters), ungraded submissions, and students unmarked in today's register — each row a link to the exact screen where the item is resolved; rows with zero count are hidden and the card disappears entirely when nothing needs action.
- Payment-methods list pagination completed the Phase 3 sweep.

**Validation:** admin tests 195/195 ✓ (Dashboard suite re-run after the class-name fix `DailyAttendanceRecord` → `DailyAttendance`).

## Checkpoint 5 — Phase 6 QA (responsive + accessibility + evidence)

Automated Playwright pass (`final-qa.mjs`, Chromium): **15/15 after fixes**.
- Admin: sidebar collapses to 76 px and persists across reload; breadcrumbs render; `Outstanding` column right-aligned; single-page result counts visible; keyboard focus outline 2 px visible on tab; no page-level horizontal overflow at 768 px; zero JS page errors.
- Portal: centered auth without marketing panel; dashboard h1 ≤ 26 px; marketing copy gone; no horizontal overflow at 390 px on payments and leaderboard; zero JS page errors.
- Incidents found & fixed during QA: (1) a stale `public/hot` file (left by an accidental dev-server run) disabled *all* admin CSS/JS — deleted, and it also explains the corrupted first "before" evidence batch; (2) payment-methods list had been missed in the pagination sweep — fixed; (3) `border-warning` wasn't in the compiled CSS until assets were rebuilt after the dashboard change.
- Evidence: true before shots re-captured from the pre-phase commits (`818f9c3` portal, `1b31c68` admin) served from git worktrees; after shots from the final trees. 8 screens × before/after in `docs/ui-evidence/{before,after}/` in each repo.

*(final summary in `UI-UX-FINAL-REPORT.md`)*
