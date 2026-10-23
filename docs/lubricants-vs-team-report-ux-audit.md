# Lubricants Suite vs `/team/report` — UX Design Audit

**Prepared per:** the `/team/report` enterprise design system, as already applied to
`application/views/suppliers/index.php` (see `docs/suppliers-vs-team-report-ux-audit.md`).

**Scope:** the six lubricants surfaces in `application/views/lubricants/` —
Consumption Ledger (`index.php`), Lube Budget (`budget.php`), Record (`record.php`),
Lube Types (`types.php`), OEM Specifications (`specifications.php`),
PM Forecast (`forecast.php`) — audited against `application/views/team/report.php`.

**Finding IDs:** LUB-01 … LUB-NN. Severity: Critical / High / Medium / Low.

---

## 1. Executive Summary

Before this pass the six lubricants views shared a name and a colour variable but not a
design system. Each view redeclared its own `.lub-subnav` block, each had a different
header shape (`<h1>` in some, a `card` header in others), KPIs existed only on the ledger
and the budget, tables had no sticky header, empty states were bare `<p>` tags, and none of
the surfaces had print styles. The result read as six separate screens rather than one
module.

The fix is structural, not cosmetic: a single shared partial
(`application/views/lubricants/_ui.php`) now owns the suite chrome — token layer,
suite navigation, page head, KPI card, progress bar, badge adapter, illustrated empty
state, and print banner — and every surface composes those primitives. Surface-specific
layout (a budget matrix, a two-panel editor, a data-entry form) still differs where the
job differs, because the requirement is the design *system*, not one literal layout
repeated six times.

**Implementation status:** 12 of 14 findings implemented, view layer only. LUB-13
(lubricants PDF export) and LUB-14 (server-side per-column sort on the budget matrix) are
deliberately **not** implemented: the first needs a new controller endpoint, the second
needs a controller change. The brief forbids placeholder controls, so neither was shipped
as an inert button.

---

## 2. Reference Design Inventory — `/team/report`

| Element | Purpose | Place | Lubricants primitive |
|---|---|---|---|
| Page header (breadcrumb + title + period pill + subtitle + actions) | Orient the user, expose exports | Top of page | `$lub_page_head([...])` |
| Executive KPI strip | At-a-glance health | Below header | `$lub_kpi()` / `.lub-kpi-grid` |
| Scope filter bar | Narrow the dataset | Below KPIs | `.lub-scope-bar` + `.lub-autosubmit` |
| Status tabs with counts | Segment by state | Below scope bar | ledger tabs (`.lub-tab`) |
| Search input with `/` shortcut + `kbd` hint | Free-text filter | Toolbar right | `.lub-search-input` + `.lub-kbd` |
| Segmented view toggle | Layout preference | Toolbar right | `[data-lub-view]`, `aria-pressed` |
| Active filter chips strip | Show + remove active filters | Below toolbar | `.lub-chips-bar` / `.lub-chip` |
| Multi-select batch bar | Bulk export | Appears on selection | `#lubBatchBar` |
| Enterprise data table | Primary data surface | Main container | `.lub-table` + `.lub-table-wrap` |
| Identity cell (avatar + name + meta) | Scannable row identity | First column | `.lub-entity` / `.lub-avatar` |
| Status badges | State at a glance | Inline | `$lub_badge()` → `.badge b-*` |
| Rate / health bar | Quantitative scan | Numeric columns | `$lub_bar()` / `.lub-rate-*` |
| Slide-over drawer | Deep dive without navigating | Fixed right panel | `.lub-drawer` |
| Empty state | Zero-data guidance | Main container | `$lub_empty()` |
| Pagination footer | Navigate large sets | Bottom | `.lub-footer` / `.lub-page-link` |
| Print layout | Hard-copy output | `@media print` | `$lub_print_banner()` |

---

## 3. Finding Register

### LUB-01 · Six private copies of the suite navigation (High) — ✅ Implemented

**Current:** every view declared its own `.lub-subnav` CSS and its own `<nav>` markup,
with the active link hard-coded per file. `budget.php` styled `.lub-subnav`; `types.php`
styled a slightly different radius; the ledger had none at all.

**Reference:** one `.ts-*` chrome layer, applied consistently.

**Impact:** the nav drifted visually, and the budget link stayed visible to read-only
roles that the controller refuses to serve.

**Fix:** `$lub_nav($active)` in `_ui.php` renders the suite navigation from a single link
table, marks `aria-current="page"`, and hides write-only destinations when
`$can_write` is false. All six views call it; no view declares `.lub-subnav` styles.

**Acceptance:**
- [x] One nav definition, used by all six surfaces
- [x] Active entry marked with `aria-current="page"`
- [x] Budget link omitted for read-only roles
- [x] No view redeclares `.lub-subnav` CSS

### LUB-02 · No shared page header (High) — ✅ Implemented

**Current:** `index.php` and `budget.php` used `<h1>` + a loose `.cs` subtitle; `record.php`
used a centred `max-width:760px` card with a heading inside it; `types.php` and
`specifications.php` had no page title at all beyond the nav.

**Reference:** breadcrumb + title + period pill + subtitle + action cluster.

**Fix:** `$lub_page_head(['crumb' => ..., 'title' => ..., 'pill' => ..., 'subtitle' => ..., 'actions' => ...])`
on all six surfaces, with a real breadcrumb back to `lubricants`.

**Acceptance:**
- [x] Breadcrumb, title, pill, subtitle on every surface
- [x] Primary actions live in the head, not scattered in body copy
- [x] Titles remain plain text (no PHP undefined-index warnings)

### LUB-03 · KPI strips only on two surfaces (Medium) — ✅ Implemented

**Current:** the ledger and budget had KPI cards; types, specifications and forecast
jumped straight from the heading into a table.

**Reference:** a KPI strip establishes scale before detail.

**Fix:** `$lub_kpi($label, $value, $sub, $tone)` renders the shared card. Counts are
derived from the rows the controller already sends (`$specs`, `$lube_types`, `$forecast`);
no new query, no new field.

**Acceptance:**
- [x] Five of six surfaces carry a KPI strip (the record form does not — a form has no
      dataset to summarise, and inventing KPIs for it would be decoration)
- [x] Every KPI value traces to an existing controller variable
- [x] Tone only applied where a threshold genuinely exists

### LUB-04 · Tables had no sticky header or bounded scroll (Medium) — ✅ Implemented

**Current:** `types.php`, `specifications.php`, `forecast.php` and `budget.php` scrolled the
whole document; a user reading row 40 lost the column headings.

**Reference:** sticky `thead th` inside a `max-height` scroll container.

**Fix:** every table sits in `.lub-table-wrap` (sticky header, bounded height) or the
forecast's `.lub-forecast-wrap` (sticky header + sticky first column).

**Acceptance:**
- [x] Header stays visible while scrolling a long catalogue
- [x] Forecast pins the month column so horizontal scroll keeps row identity
- [x] Horizontal scroll never clipped the page body (wrapping container, not `<body>`)

### LUB-05 · Empty states were bare sentences (Low) — ✅ Implemented

**Current:** e.g. `budget.php` rendered `<p>No lubricant types are available for this
period…</p>`; `types.php` rendered an empty `<tbody>`.

**Reference:** illustrated icon + explanation + actionable next step.

**Fix:** `$lub_empty($title, $body, $cta)` with an inline SVG icon. CTAs point at pages
that exist: budget → Lube Types, forecast → OEM Specifications, specifications → the form
on the same page (no CTA when the form is already visible to the reader).

**Acceptance:**
- [x] Icon, headline, explanation on all zero-data states
- [x] Every CTA resolves to a real route
- [x] No CTA offered to a role that cannot act

### LUB-06 · Badges were hand-rolled per view (Low) — ✅ Implemented

**Current:** lubricants views used local `pill` / inline `background` spans for category,
unit and status, so colours did not match the rest of the app.

**Reference:** `.badge` + `.b-success / .b-warning / .b-danger / .b-info / .b-secondary`.

**Fix:** `$lub_badge()` delegates to the layout badge classes; variance badges use
semantic tones (`+x` danger, `−x` warning, on-standard success, no-standard neutral).

**Acceptance:**
- [x] Badges render through the shared `.badge` system
- [x] Variance tone maps to direction, not magnitude
- [x] "No OEM standard" is neutral, not an error

### LUB-07 · Budget allocation had no at-a-glance health column (Medium) — ✅ Implemented

**Current:** the model returns `remaining_cost` and `utilization_pct` per line, but the
matrix showed only budget cost and period issues; a reader could not see which fluids were
overspending without doing arithmetic.

**Reference:** a rate/health bar column (`ts-rate-*`).

**Fix:** the budget matrix gained a **Utilisation** column driven by the existing
`utilization_pct`, rendered with `$lub_bar()`, and the client calculator repaints it while
the user edits quantities and rates.

**Acceptance:**
- [x] Utilisation column shows a bar + percentage
- [x] Live recalculation updates utilisation without a server round trip
- [x] No new database field; percentage is the model's own output

### LUB-08 · Budget period picker was detached from the page header (Low) — ✅ Implemented

**Current:** year + month inputs floated in a `flex` row next to the title, with no label
association and no relationship to the export links (which ignored the visible selection).

**Fix:** the picker moved into the shared scope bar as labelled controls
(`#bgtYear`, `#bgtMonth`), auto-submitting on change, and the CSV/XLSX links are built from
the resolved `year`/`month` so they always match what is on screen.

**Acceptance:**
- [x] Period controls sit in `.lub-scope-bar` with associated labels
- [x] Changing the period reloads the same view
- [x] Export URLs carry the visible period

### LUB-09 · Rules-of-engagement copy was buried in dense paragraphs (Low) — ✅ Implemented

**Current:** the budget page explained the PM-demand and rate assumptions in one long
sentence wedged under the panel title; the record form explained nothing, so users assumed
the OEM comparison happened live in the form.

**Fix:** panel subtitles stay short; the record surface gained a three-step "How this is
recorded" side panel covering OEM variance, work-order scope, and that line cost is an
estimate of value issued rather than a purchase cost.

**Acceptance:**
- [x] Assumptions visible without expanding anything
- [x] Guidance never claims a capability the controller does not have
- [x] Guidance panel hidden when printing

### LUB-10 · Recording form had no role-aware state (Medium) — ✅ Implemented

**Current:** a read-only user could load `lubricants/record` and be shown a full form whose
submit the controller would reject.

**Reference:** surfaces degrade to explanation, not to a dead control.

**Fix:** when `$can_write` is false the surface renders the shared empty state explaining
which roles may record, plus a link back to the ledger — no form, no disabled buttons.

**Acceptance:**
- [x] No submit affordance for read-only roles
- [x] Explanation names the permitted roles
- [x] Direct URL access degrades gracefully

### LUB-11 · No print layout anywhere in the module (Low) — ✅ Implemented

**Fix:** `$lub_print_banner($title, $meta)` renders a print-only banner with company name,
context and timestamp; `@media print` hides nav, toolbars, chips, filters, action bars and
drawers, and flattens table chrome. The banner carries the resolved period / counts so a
printed sheet is self-describing.

**Acceptance:**
- [x] Print output hides interactive chrome
- [x] Banner identifies company, surface, scope and generation time
- [x] Budget print shows planned total and month

### LUB-12 · Client-side catalogue filtering existed nowhere outside the ledger (Low) — ✅ Implemented

**Fix:** `types.php` and `specifications.php` gained a `/`-focusable search that filters
loaded rows (with an explicit "no match" row), matching the ledger's search affordance.
Filtering is presentation-only — no request, no server sort needed.

**Acceptance:**
- [x] Search filters the rendered rows and shows a no-match row
- [x] `/` focuses the field
- [x] Clearing restores every row

### LUB-13 · Batch PDF export for lubricants consumption (Low) — ⏸️ Deferred

**Reason:** there is no lubricants PDF renderer. Adding one requires a controller endpoint,
which the brief excludes from this pass. CSV and XLSX already cover the batch need and both
endpoints exist. Shipping a PDF button without the endpoint would produce a dead control —
explicitly forbidden, and the same call made on SUP-10.

### LUB-14 · Server-side sort on the budget matrix (Low) — ⏸️ Deferred

**Reason:** the matrix is a monthly editor keyed by lubricant, not a paginated dataset;
sorting it server-side needs a controller change with no user benefit beyond the default
ordering. The ledger — the surface that actually paginates — has full `aria-sort` headers
(`date`, `equipment`, `lube`, `qty`, `variance`, `cost`).

---

## 4. What Already Matched (Preserved, Not Rebuilt)

- Ledger scope filters, tabs with counts, `/` search, three-way view switch, multi-select
  batch CSV/XLSX, dossier drawer with cache + arrow navigation, windowed pager, per-page
  selector, print banner — all behaviour kept; only presentation changed.
- Budget POST contract: `year`, `month`, `budget[<lube_id>][qty]`, `budget[<lube_id>][cost]`,
  hidden year/month, live `recalc()` arithmetic, `#bgtFillPmBtn` copy-PM behaviour,
  `data-pm` / `data-actual-qty` / `data-default-rate` attributes, all element ids, and the
  `!$can_write → readonly` gating.
- Record's dependent work-order filter (`data-equipment`, hide + disable off-machine jobs).
- Types' `can_manage_types` (supervisor) gate; specifications' `data-spec-edit` form fill
  and `#specSaveHint` live status.
- Forecast's two-month matrix, sticky first column, and mobile month cards.
- CSRF on every mutation form (`form_open()`), server-side validation, RBAC.

---

## 5. Files Changed

1. `application/views/lubricants/_ui.php` — **new** shared partial (tokens, nav, page head,
   KPI, bar, badge, empty state, print banner, `lub-*` CSS).
2. `application/views/lubricants/index.php` — ledger rebuilt on the partial.
3. `application/views/lubricants/budget.php` — budget rebuilt on the partial.
4. `application/views/lubricants/record.php` — two-column form + guidance, role-aware state.
5. `application/views/lubricants/types.php` — KPI strip, split layout, filter, empty state.
6. `application/views/lubricants/specifications.php` — KPI strip, split layout, filter, empty state.
7. `application/views/lubricants/forecast.php` — KPI strip, sticky column, month cards, empty state.
8. `tests/table_ux.js` — lubricants parity assertions.
9. `docs/lubricants-vs-team-report-ux-audit.md` — this document.

No controller, model, route, migration, or RBAC change. Every rendered value comes from
data the controller already provided; no fields were invented.

---

## 6. Acceptance Checklist (verified)

- [x] `node tests/table_ux.js` passes (existing suites + new lubricants assertions)
- [x] Every surface includes `_ui.php` and calls `$lub_nav()` / `$lub_page_head()` / `$lub_print_banner()`
- [x] No surface redeclares `.lub-subnav` styles
- [x] Ledger sort headers expose `aria-sort`; sort indicator is SVG, not a text arrow
- [x] Ledger view switch writes `?view=`, keeps a forced-table class, and survives paging
- [x] Batch export hrefs build `?month=…&ids=…` from the live selection
- [x] Budget keeps its POST field names, element ids, `data-*` calculator attributes,
      `#bgtFillPmBtn`, and readonly gating
- [x] Budget export links carry the visible period
- [x] Types gated on `can_manage_types`; specifications and record gated on `can_write`
- [x] Forecast pins the month column and swaps to month cards under 900px
- [x] Empty states carry icon + explanation + a CTA that resolves
- [x] Print styles hide chrome and print a self-describing banner
- [x] No PHP errors, warnings, or notices in any rendered scenario

---

## 7. Verification Performed

This sandbox has no PHP/MySQL stack, so views were rendered against a real PHP 8.3 runtime
compiled to WebAssembly (`php-wasm`) with CI helpers stubbed (`base_url`, `csrf_field`,
`form_open`, `Lubricant_model::clean_month`, `$CATEGORIES`, and fixture rows).

| View | full | empty | filtered | readonly |
|---|---|---|---|---|
| `index.php` (ledger) | 0 errors | 0 errors | 0 errors | 0 errors |
| `budget.php` | 0 errors | 0 errors | 0 errors | 0 errors |
| `record.php` | 0 errors | 0 errors | 0 errors | 0 errors |
| `types.php` | 0 errors | 0 errors | 0 errors | 0 errors |
| `specifications.php` | 0 errors | 0 errors | 0 errors | 0 errors |
| `forecast.php` | 0 errors | 0 errors | 0 errors | 0 errors |

Rendered markup assertions confirmed: one active nav entry per surface, `aria-sort` on the
six ledger sort columns, budget field names `budget[<id>][qty]` / `budget[<id>][cost]`,
`data-pm` / `data-actual-qty` / `data-default-rate` present, `#grandTotal` and the four KPI
ids present, CSV/XLSX hrefs carrying `?year=&month=`, readonly mode dropping
`#saveBudgetBtn` and `#bgtFillPmBtn` while marking inputs `readonly`, and empty scenarios
dropping the table in favour of `$lub_empty()`.

The rendered HTML was then loaded into jsdom and the inline scripts exercised:
**24/24 checks passed** — batch bar reveal, selected count, `?ids=` href construction for
CSV and XLSX, clear, view switch + hidden input + forced-table class, drawer open / fetch /
render / close, budget per-line total, grand total, utilisation KPI, issue value using the
edited rate, and the copy-PM-demand button.

**Not verified here:** pixel comparison and live MySQL data (no browser binary or database
in this sandbox). Those belong to the CI environment.
