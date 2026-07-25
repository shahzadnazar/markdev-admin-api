# MarkDev Design System

Applies to both applications: **Student Portal** (`markdev-student-portal`, React 19 + Tailwind v4, tokens in `src/index.css @theme`) and **Admin Portal** (`markdev-admin-api`, Blade + Alpine + Tailwind v4, tokens in `resources/css/app.css @theme`). Same design language, different information architecture: the admin portal is operational/data-first/desktop-first; the student portal is task-first and mobile-responsive.

## 1. Color

**Brand decision:** both apps use the approved MarkDev palette below (migrated from the earlier `#0C5ABD` at the token level — no per-screen edits). All ratios were computed during the migration; the main primary reaches 9.59:1 on white.

### Brand
| Token | Value | Contrast | Use |
| --- | --- | --- | --- |
| `--color-primary` (primary-600) | `#124389` | 9.59:1 on white | main actions, active nav, focus rings, selected tabs, links, primary charts |
| `--color-primary-deep` (primary-700) | `#0F376F` | 11.69:1 white-on | hover/pressed on primary |
| `--color-primary-bright` (primary-500) | `#1D5AA6` | 6.85:1 on white | secondary emphasis, chart accents |
| `--color-primary-container` (primary-100) | `#DCE9F7` | 9.49:1 w/ `#0F376F` text | selected/active soft surfaces |
| `--color-surface-ice` (primary-50) | `#EEF4FB` | 15.44:1 w/ body text | page canvas, hover rows |
| `--color-accent` | `#1FBBEB` | 2.24:1 on white | **decorative only** — chart lines, tiny indicators. Never a filled button, never white text on it. |
| `--color-secondary` | `#6B53C4` | — | admission/registration accents, secondary chips — never for buttons |
| Tints | `primary/10`, `primary/5` | — | active-nav backgrounds, selected states, info surfaces |

Shadows are tinted `rgba(18,67,137,…)`; the brand gradient is `#124389 → #6B53C4`. DomPDF templates carry the same values as literals (PDF renderers cannot read CSS variables).

### Neutrals
Token names: `--color-on-surface` (headings/body, ≈ gray-950), `--color-on-surface-variant` (secondary text, ≥ 4.5:1), `--color-outline` / `--color-outline-variant` (borders, muted metadata), `--color-surface-ice` / `surface-container*` (page + card fills), white cards on a `#F5F7FB`-family canvas.

### Semantic
| Meaning | Token | Usage rules |
| --- | --- | --- |
| Success | `--color-success` (+`success-container`) | Approve, Activate, Verify, Mark paid, paid/active/completed badges. **Not** for ordinary Save. |
| Warning | `--color-warning` (+`warning-container`) | pending/under-review badges, grace/due-today notices, suspend/hold actions |
| Danger | `--color-error` (+`error-container`) | Delete, Reject, Revoke, overdue/failed badges. **Never** Cancel. |
| Info | `primary/10` surfaces | account-details panels, informational banners |

Status → color registry (both apps must use these labels AND colors — status is always text + color, never color alone):

```
active/paid/completed/approved  → success (soft bg)
pending/under review/in grace   → warning (soft bg)
open/processing/in progress     → primary (soft bg)
upcoming/draft/inactive         → neutral gray
rejected/failed/past_due/void   → danger (soft bg)
```

## 2. Typography

One family set for both apps: **Hanken Grotesk** (display/headings) + **Inter** (body) + **JetBrains Mono** (numbers, IDs, eyebrows). Do not add families.

| Role | Token / class | Size |
| --- | --- | --- |
| Page title | `text-headline-md` (portal) / `text-2xl` compact header (admin) | 24 px / 600 |
| Section heading | `text-body-lg` semibold / admin `eyebrow` + 16 px | 16–18 px |
| Card title | `text-body-md` semibold | 14–16 px |
| Body | `text-body-sm`–`md` | 14 px |
| Table text | 13–14 px (`.td` in admin) | — |
| Label | `text-label-md` (14/500), helper `text-label-sm` (12) | — |
| Metric | `text-headline-md` | 24 px / 600 |

Rules: no 32–40 px headings on portal pages; metrics inside page bodies cap at `headline-md`; mono+uppercase reserved for eyebrows/labels/IDs; no arbitrary `text-[Npx]` below 12 px for readable content.

## 3. Spacing, radius, shadows

- 4/8 px system. Page padding: desktop 24 px, mobile 16 px. Section gap 16 px (`space-y-4`/`gap-4`), card gap 16 px.
- Cards: white, `border`-less with very subtle primary-tinted shadow (`--shadow-card`) **or** 1 px outline — never both heavy. Padding 16–20 px. Radius: 8 px controls (`rounded-lg`), 12 px cards/modals (`rounded-xl`/`2xl` max). No stacked glows; `--shadow-elevated` reserved for overlays.
- Metric cards ≈ 110–140 px tall; icon chips ≤ 36 px.

## 4. Buttons

Portal: `src/components/ui/button.tsx` (cva). Admin: `resources/views/components/btn.blade.php`.

| Variant | Portal / Admin | Use for |
| --- | --- | --- |
| primary (filled blue, white text) | ✓ / ✓ | Save, Create, Add, Submit, Continue, Update, Generate |
| success (filled green, white text) | ✓ / ✓ | Approve, Activate, Verify, Mark paid, Pay/Submit money actions |
| warning (amber) | ✓ / — (planned) | Suspend, Hold, Pause |
| destructive/danger (red) | ✓ / ✓ | Delete, Reject, Revoke |
| secondary (outlined blue) | ✓ / ✓ | Export, Download, Preview |
| ghost (neutral) | ✓ / ✓ | Cancel, Back, Close, View, Edit, Reset filters |
| link | ✓ / — | inline navigation |
| icon | ✓ / icon-btn pattern | requires `aria-label` + tooltip/`title`, ≥ 36 px target |

Sizes: sm 32–36 px, md 40 px, lg 44 px; radius 8 px; 13–14 px / 500–600; icon gap 6–8 px. One filled primary per section. ≥3 row actions → three-dot overflow menu. Submit buttons disable while pending and keep their width (portal: `disabled={isPending}` + `<Spinner>`; admin: submit-once guard in `x-btn`).

## 5. Forms

- Components: portal `FormField` + `ui/{input,select,textarea,checkbox,radio-group,switch}`; admin `x-form.{input,select,textarea,label,error,toggle}` (`.field` ≈ 42 px height).
- Labels always visible; placeholders never the only label. Required = red asterisk on the label (not a red label).
- Inline validation directly under the field (`FormError` / `x-form.error`); long admin forms additionally get an error summary at top with scroll-to-first.
- Group with sections (Personal / Contact / Academic / Billing / Permissions); two columns only for short related fields; one column on mobile and for sensitive/long content.
- Long forms: sticky bottom action bar (`Cancel · Save changes`), duplicate-submit prevention.

## 6. Tables (admin-first)

`.th` (40–44 px header) / `.td` / `.row` in `app.css`; `x-table` wrapper provides overflow scrolling and footer slot.

- Row height ~48 px (compact 40); horizontal cell padding 12–16 px; hover highlight, no zebra, no full-cell borders.
- Alignment: text left; **numbers/currency right (`.td-num`)**; actions right; status left; dates consistent.
- Every index: search where relevant, filters via `x-filter-bar`, pagination + always-visible "Showing X–Y of Z", empty state (`x-empty-state`/`EmptyState`), specific confirm dialogs for destructive row actions.
- Row actions: 1–2 neutral icon buttons (labelled) + overflow menu beyond that.

## 7. Feedback

- **Toasts** — completed background actions. Portal: sonner via one wrapper (position top-right). Admin: flash block in layout (success/error; roadmap: stackable + warning).
- **Inline errors** — field validation only.
- **Page-level alert banners** — workflow failures/maintenance (shared alert component; 4 semantic variants).
- **Confirmation dialogs** — destructive/high-impact only; never for harmless actions.

Message pattern — every alert answers *what happened / why it matters / what next*:

```
Payment could not be recorded
The transaction reference is already associated with another payment.
Check the reference number and try again.
```

Confirm pattern — name the object, state permanence:

```
Delete student?
This will permanently delete Ahmad Ali's profile and related records.
This action cannot be undone.
[Cancel] [Delete student]
```

Banned copy: bare "Success", "Failed", "Invalid data", "Something went wrong", bare "Are you sure?".

## 8. Modals & drawers

- Modals: confirmations, short forms (≤ ~6 fields), status changes. Sizes sm–3xl; Escape closes; focus trapped (Radix in portal; `x-modal` in admin); body scroll locked; never rendered inside `<tbody>`.
- Drawers: previews, filters, audit detail (roadmap in admin).
- Full pages: student admission, course creation, role management, multi-step flows. No multi-section forms inside `max-w-md` modals.

## 9. Layout shells

- Sidebar 280 px, grouped by domain (Overview / People / Learning / Finance / System in admin; Overview / Learning / Community / Account in portal). Active item = `primary/10` background + primary text — no gradient states.
- Topbar 64 px sticky: page context, notifications, user menu.
- Page header: eyebrow + 24 px title + one-line description left; secondary + one primary action pinned top-right (`sm:flex-nowrap`). No hero sections.
- Mobile: admin off-canvas sidebar; portal drawer (Radix dialog semantics), 40–44 px touch targets, full-width primary actions where appropriate.

## 10. States

- Loading: skeletons per surface (portal `Skeleton`/`CourseCardSkeleton`), button spinners; never a full-app spinner.
- Empty: icon + specific title + guidance + next action (portal `EmptyState`, admin `x-empty-state`); compact (`py-10`), title below page-title size.
- Error: portal `ErrorState` with retry; admin flash + branded 403.

## 11. Accessibility standard

- Text contrast ≥ 4.5:1 (tokens chosen accordingly).
- Global `:focus-visible` **outline** ring (2 px primary, offset 2) — outline, not box-shadow, so `overflow-hidden` cannot clip it.
- All icon-only controls: `aria-label` (+ tooltip). Interactive targets ≥ 40 px.
- Status = text + color. Form errors linked to fields. Keyboard-accessible menus/modals/rows.

## 12. File map

| Concern | Student portal | Admin portal |
| --- | --- | --- |
| Tokens | `src/index.css` `@theme` | `resources/css/app.css` `@theme` |
| Buttons | `components/ui/button.tsx` | `components/btn.blade.php` |
| Badges/status | `components/ui/badge.tsx` + `lib/status.ts` | `components/badge.blade.php` |
| Forms | `components/shared/form-field.tsx`, `ui/*` | `components/form/*` |
| Tables | (Table primitive planned) | `components/table.blade.php`, `.th/.td/.row` |
| Feedback | sonner Toaster (`App.tsx`), `ui/alert.tsx` | layout flash, confirm-form |
| Shell | `components/layout/*` | `components/admin/*` |
| Empty/err | `shared/empty-state.tsx`, `shared/error-state.tsx` | `components/empty-state.blade.php` |
