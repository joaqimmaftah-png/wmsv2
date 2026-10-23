# Suppliers Table vs `/team/report` — UX Design Audit

**Prepared per:** Jony Ive design philosophy — *"Simplicity is not the lack of clutter, that's a consequence of simplicity. Simplicity is somehow essentially describing the purpose and place of an object and product."*

**Scope:** Audit `application/views/suppliers/index.php` against `application/views/team/report.php` (the reference design).

**Finding IDs:** SUP-01 … SUP-NN. Severity: Critical / High / Medium / Low.

---

## 1. Executive Summary

The Suppliers table already shares DNA with `/team/report` (KPI strip, segment tabs, search, view toggle, batch bar, slide-over drawer, cards view) but diverges in execution across **12 finding areas**. The reference design (`team/report`) treats every UI element as having a clear purpose and place; the Suppliers table occasionally buries purpose behind conventions (`…` kebab for primary actions) or lacks visual hierarchy (no active filter chips, no sticky header, no rate visualization).

**Overall:** The Suppliers table is *functionally* comparable but *visually and interactionally* less disciplined than `/team/report`. Closing the gap means adopting the reference's filter chips, SVG sort indicators, sticky table header, auto-submit filters, print layout, and richer row-action hierarchy.

**Implementation status:** 11 of 12 findings are implemented in `application/views/suppliers/index.php` (view layer only). SUP-10 (batch PDF export) is deliberately **not** implemented — see the note on that finding: no supplier PDF renderer exists in the controller, and the brief forbids placeholder controls.

---

## 2. Reference Design Inventory — `/team/report`

| Element | Purpose | Place | Notes |
|---|---|---|---|
| Page header (breadcrumb + title + period pill + subtitle + actions) | Orient user, primary exports | Top of page | Print-optimized banner variant |
| Executive KPI strip (5 cards) | At-a-glance health | Below header | Each card: label, value, meta |
| Scope filter bar (Month, Period, custom dates, Site, Role) | Narrow dataset | Below KPIs | Auto-submits on change (`.ts-autosubmit`) |
| Status categorization tabs (with counts) | Segment by status | Below scope bar | `role="tablist"`, `aria-selected`, count badge |
| Search input (`/` shortcut, clear button, `ts-kbd`) | Free-text filter | Toolbar right | Debounced, Enter to apply |
| Segmented view toggle (Table \| Cards) | Layout preference | Toolbar right | `aria-pressed`, SVG icons |
| Active filter chips strip | Show + remove active filters | Below toolbar | Each chip has `×` to remove individually; "Reset all" |
| Multi-select batch bar (contextual) | Bulk operations | Appears when rows selected | Export PDF/XLSX/CSV + Clear |
| Enterprise data table | Primary data surface | Main container | Sticky header, hover, selection, sortable SVG headers |
| Worker identity cell (avatar + name + meta + role) | Scannable row identity | First data column | Initials avatar, employee code, position |
| Status badges (badge-red / badge-amber / badge-green) | Status at a glance | Inline in cells | Semantic color, not custom wrapper classes |
| Attendance rate bar visualization | Quantitative scan | Rate column | Mini bar + pct, color-coded good/warn/bad |
| Row actions: Sheet (primary) + PDF + overflow | Frequent + occasional actions | Last column | Primary action is a styled button, not hidden |
| Overflow menu: Inspect / PDF / XLSX / Profile | Less frequent actions | `…` button | `role="menu"`, keyboard accessible |
| Empty state | Zero-match guidance | Main container | Illustrated, actionable ("Reset all filters") |
| Pagination footer + page-size selector | Navigate large sets | Bottom | Ellipsis window, current page highlighted |
| Right-side slide-over drawer inspector | Deep-dive without navigation | Fixed right panel | Prev/Next navigation, JSON cache, keyboard arrows |
| Print media layout | Hard-copy output | CSS `@media print` | Hides UI chrome, shows official report banner |
| Keyboard shortcuts (`/`, `Esc`, arrows) | Power-user efficiency | Global | Search focus, close, drawer navigation |

---

## 3. Finding Register

### SUP-01 · Missing active filter chips strip (High) — ✅ Implemented

**Current (Suppliers):** Active filters (tab, site, currency, status, search) are not visible after application. User must remember what is filtered or re-open each `<select>` to check.

**Reference (`team/report`):** `.ts-chips-bar` below the toolbar lists every active filter as a removable chip (`×`), plus a "Reset all" link.

**Impact:** Users lose context of filtered state; no quick way to remove a single filter without reopening dropdowns.

**Acceptance criteria:**
- [ ] Active filters render as chips below the toolbar
- [ ] Each chip shows filter name + value + `×` remove button
- [ ] "Reset all" link clears all filters
- [ ] Chips strip hidden when no filters active

---

### SUP-02 · Filter controls require manual "Apply" click (Medium) — ✅ Implemented

**Current (Suppliers):** Month/period/site/currency/status/per_page controls do not auto-submit; user must click an "Apply" button.

**Reference (`team/report`):** `.ts-autosubmit` inputs/Selects trigger `form.submit()` on `change`. Month, Period, Site, Role all auto-submit.

**Impact:** Extra interaction step; violates "purpose and place" — changing a filter should immediately have effect.

**Acceptance criteria:**
- [ ] Month/Period/Site/Currency/Status auto-submit on change
- [ ] "Apply" button retained as fallback for non-JS
- [ ] Custom date range submits only when both dates present

---

### SUP-03 · Sort indicators use text arrows instead of SVG (Low) — ✅ Implemented

**Current (Suppliers):** `&#8597;` / `&#9650;` / `&#9660;` HTML entities for sort direction.

**Reference (`team/report`):** Inline SVG sort icon (`.ts-sort-ico`) with active/dimmed states and `aria-sort` attribute on `<th>`.

**Impact:** Inconsistent with system's icon language; no ARIA sort state.

**Acceptance criteria:**
- [ ] SVG sort indicator matching `team/report`
- [ ] `aria-sort="none\|ascending\|descending"` on sortable `<th>`
- [ ] `aria-label` on sort links

---

### SUP-04 · Table header not sticky (Medium) — ✅ Implemented

**Current (Suppliers):** Table header scrolls away with body rows on wide matrices; no `position: sticky` on `<th>`.

**Reference (`team/report`):** `.ts-table thead th { position: sticky; top: 0; z-index: 2; }` with background matching surface.

**Impact:** Column context lost when scrolling long supplier lists.

**Acceptance criteria:**
- [ ] Table header sticks on scroll
- [ ] Header background visually separated from body
- [ ] Z-index above body rows, below overlay menus

---

### SUP-05 · Status uses custom wrapper classes instead of shared `.badge` (Low) — ✅ Implemented

**Current (Suppliers):** `.sup-st-active`, `.sup-st-inactive`, `.sup-st-blacklisted` — bespoke pill classes.

**Reference (`team/report`):** `.badge badge-red`, `.badge badge-amber`, `.badge badge-green` — shared semantic badge system.

**Impact:** Duplicate styling system; inconsistent with rest of WMS badge language.

**Acceptance criteria:**
- [ ] Status pills use shared `.badge b-*` classes
- [ ] Active → `.badge b-success` (green), Inactive → `.badge b-secondary` (gray), Blacklisted → `.badge b-danger` (red)
- [ ] Remove `.sup-st-*` CSS

---

### SUP-06 · No quantitative rate/health visualization (Medium) — ✅ Implemented

**Current (Suppliers):** Catalog count, order count, on-time % shown as plain numbers; no visual bar/sparkline.

**Reference (`team/report`):** `.ts-rate-wrap` with `.ts-rate-bar` + `.ts-rate-fill`, color-coded good/warn/bad.

**Impact:** Users must read numbers to gauge supplier health; no scanable visual signal.

**Acceptance criteria:**
- [ ] On-time % rendered as mini bar + percentage
- [ ] Color-coded by threshold (>=90 green, >=75 amber, <75 red)
- [ ] "No records" state when no closed POs

---

### SUP-07 · Row actions: primary actions hidden behind `…` (High — Ive principle violation)

**Current (Suppliers):** Inspect and Profile (the two most frequent actions) are behind the overflow kebab; write users must click twice for routine actions.

**Reference (`team/report`):** Sheet (primary, styled blue) + PDF are visible buttons; only Inspect/PDF/XLSX/Profile go in overflow.

**Impact:** Primary task (inspect a supplier) costs 2 clicks instead of 1. Clutter removal without simplicity — violates Ive's principle.

**Acceptance criteria:**
- [ ] Inspect and Profile visible as `.btn btn-ghost btn-sm`
- [ ] Edit + Delete (occasional/destructive) remain in overflow
- [ ] Delete visually separated (red), confirmation + CSRF preserved

---

### SUP-08 · No slide-over drawer JSON cache or keyboard drawer navigation (Low) — ✅ Implemented

**Current (Suppliers):** Drawer fetches JSON on every open; no prev/next keyboard navigation between suppliers.

**Reference (`team/report`):** `sheetCache` object caches JSON by URL; Left/Right arrow keys navigate drawer between ordered suppliers.

**Impact:** Slower repeat opens; no keyboard efficiency for reviewing multiple suppliers.

**Acceptance criteria:**
- [ ] Cache drawer JSON payloads by URL
- [ ] Left/Right arrows navigate drawer between suppliers
- [ ] Prev/Next buttons disable at boundaries

---

### SUP-09 · Missing print media layout (Low) — ✅ Implemented

**Current (Suppliers):** No `@media print` styles; printing includes UI chrome (search, filters, drawer).

**Reference (`team/report`):** Comprehensive print media: hides toolbar/chips/batch/drawer, shows official report banner, tightens table padding.

**Impact:** Printed supplier directory is unusable (UI elements waste paper, no header context).

**Acceptance criteria:**
- [ ] `@media print` hides `.no-print` elements
- [ ] Print banner with report title, period, count, date
- [ ] Table borders/shadows removed for print

---

### SUP-10 · Batch actions lack PDF export (Low) — ⏸️ Deferred (backend dependency)

**Current (Suppliers):** Batch bar exports CSV and XLSX only.

**Reference (`team/report`):** Batch bar exports PDF, XLSX, and CSV.

**Impact:** Inconsistent export format offering across the system.

**Decision — deferred:** `/team/report` can offer PDF because `Team::timesheet()` renders a per-worker PDF. `Suppliers` has **no** PDF renderer (`summary_json`, `export_csv`, and `export_xlsx` only). Adding a PDF button would ship a dead control, which the design brief forbids. This finding stays open until a supplier-dossier PDF endpoint exists in the controller; the batch bar therefore exposes CSV + XLSX only, both of which are live.

**Acceptance criteria (blocked on backend):**
- [ ] Batch "Export Selected PDF" added once a supplier PDF endpoint exists
- [ ] PDF endpoint respects selected supplier IDs

---

### SUP-11 · Empty state lacks illustration + actionable reset (Low) — ✅ Implemented

**Current (Suppliers):** Plain centered text "No suppliers match the current filters."

**Reference (`team/report`):** `.ts-empty` with SVG icon, heading, explanatory paragraph, and "Reset all filters" button.

**Impact:** Empty state feels dead; no clear path forward.

**Acceptance criteria:**
- [ ] SVG empty-state icon
- [ ] Heading + context-aware message (filters active vs. none)
- [ ] "Reset all filters" button when filters active

---

### SUP-12 · No `view=user-forced-table` persistence class (Low) — ✅ Implemented

**Current (Suppliers):** View switcher persists `view=` in URL but does not add a force-table class to shell.

**Reference (`team/report`):** `.ts-user-forced-table` class added to shell when user explicitly picks Table, preventing mobile auto-cards override.

**Impact:** Mobile users who prefer table view get overridden to cards on resize.

**Acceptance criteria:**
- [ ] `.ts-user-forced-table` toggled on shell when view explicitly set

---

## 4. What Already Matches (Do Not Rebuild)

These elements are already aligned with `/team/report` and should be preserved:

- KPI strip (5 cards) — present in suppliers/index
- Segment filter tabs with counts — present
- Search input with `/` shortcut — present
- View toggle (Table / By Country / Cards) — present
- Multi-select batch bar — present (CSV/XLSX only, see SUP-10)
- Slide-over drawer inspector — present (Vendor Inspector)
- Cards view grid — present
- Pagination footer with ellipsis window — present
- `role="menu"` overflow with keyboard navigation — present (portal pattern)
- `aria-expanded` on overflow button — present
- CSRF on delete forms — present
- Server-side delete guard (linked orders) — present

---

## 5. Recommended Redesign Spec (Suppliers → `/team/report` alignment)

**Priority order for implementation:**

| Priority | Finding | Effort |
|---|---|---|
| 1 | SUP-07 — Visible Inspect + Profile buttons | Low |
| 2 | SUP-01 — Active filter chips strip | Medium |
| 3 | SUP-02 — Auto-submit filters | Low |
| 4 | SUP-04 — Sticky table header | Low |
| 5 | SUP-06 — On-time % rate bar | Medium |
| 6 | SUP-05 — Shared `.badge` classes | Low |
| 7 | SUP-03 — SVG sort indicators + `aria-sort` | Low |
| 8 | SUP-08 — Drawer JSON cache + arrow nav | Low |
| 9 | SUP-09 — Print media layout | Low |
| 10 | SUP-10 — Batch PDF export | Deferred — needs a supplier PDF endpoint |
| 11 | SUP-11 — Illustrated empty state | Low |
| 12 | SUP-12 — `.ts-user-forced-table` class | Low |

**Backward compatibility:** All changes are view-layer only. Controller (`Suppliers::index`), model (`Supplier_model`), routes, CSRF, and RBAC (`can_write()`) remain untouched. Existing `data-sup-open` drawer JS and portal overflow menu JS are preserved.

---

## 6. Files to Modify

1. `application/views/suppliers/index.php` — all view changes (CSS + HTML + JS)
2. `docs/suppliers-vs-team-report-ux-audit.md` — this document (audit record)

No controller, model, route, or migration changes required.

---

## 7. Acceptance Checklist (verified)

Static regression suite: `node tests/table_ux.js` (passes).

- [x] `node tests/table_ux.js` passes (existing + 17 new supplier assertions)
- [x] Inspect + Profile visible as buttons in table row actions
- [x] Edit + Delete only in overflow, write roles only
- [x] Active filter chips render and remove individually; hidden when no filters
- [x] Filters auto-submit on change (Apply retained for keyboard / no-JS)
- [x] Table header sticky on scroll (wrapping scroll area with `max-height`)
- [x] SVG sort indicators with `aria-sort` + `aria-current` page link
- [x] Status pills use shared `.badge b-success / b-warning / b-secondary / b-danger`
- [x] On-time % rate bar rendered, "No closed POs" state when unreported
- [x] Print `@media` hides UI chrome, shows report banner
- [x] Empty state has SVG icon + reset, or "Add Supplier" when the directory is empty
- [x] Drawer JSON cached per supplier; arrow nav stops at boundaries
- [x] No PHP syntax errors

## 8. Verification performed

Because this sandbox has no PHP/MySQL stack, the view was exercised against a real
PHP 8.3 runtime compiled to WebAssembly (`php-wasm`) with stubbed CI helpers
(`base_url`, `csrf_field`, `Supplier_model::clean_status`, `$site_settings`):

| Scenario | Result |
|---|---|
| Table view, 3 vendors, write role | Renders; 0 PHP warnings/notices |
| Filtered + cards view, read-only role | 5 chips, no write actions, filters preselected |
| Empty directory | Empty state + Add Supplier, no chips |
| Grouped (By Country) view, segment chip | Group cards + 1 chip |

The rendered HTML was then loaded into jsdom and the inline script was exercised:
23/23 interaction checks passed — view switch + URL sync, `sup-user-forced-table`,
multi-select batch bar, drawer fetch/cache, prev/next boundary disabling, `Esc`,
`/` search focus, and auto-submit on filter change.

**Not verified here:** visual pixel comparison and live MySQL data (no browser
binary or database in this sandbox). Those need the CI environment.

## 9. Files changed

1. `application/views/suppliers/index.php` — complete presentation-layer rebuild
2. `tests/table_ux.js` — 17 new parity assertions
3. `docs/suppliers-vs-team-report-ux-audit.md` — this document

No controller, model, route, migration, or RBAC change. Every value rendered still
comes from `_build_suppliers_dataset()`; no new fields were invented.
