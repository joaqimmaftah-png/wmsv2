# Certifications Tracker (`team/certifications`) — Professional Data Table UX Audit, Improvement & Implementation

**Required output:** Full audit and implementation (§12 of the brief). **Authorization:** local development on this branch. No production data or schema was modified; no fields, statuses, permissions, or actions were invented — flagged recommendations are marked *(requires confirmation)*.

**Relationship to prior work:** `docs/team-certifications-view-ux-audit.md` reviewed `team/certifications` + `team/view/` and set an approval gate. This document is the data-table deep-dive on the tracker alone and implements the tracker-side phases (0–2, CSV part of 3). Renew/Edit (VIEW-02), tracker-row Renew (CERT-05 destination), and the required-certificates matrix / notifications beyond the already-merged bell alerts (CERT-12) remain product decisions — see §13.

---

## 1. Executive Summary

**Before:** the tracker was an 86-line view with two disconnected artefacts — a fixed table of *only* expiring/expired certificates (no search, sort, filter, pagination, export, or empty state) and a grid of worker cards containing *no certification data at all*. It could not answer "who is compliant?", "who has nothing recorded?", or "which certificates expire at Site/role X?" — and it exposed every worker's certificates to every logged-in role despite the sidebar promising technicians "My Certifications".

**After (implemented this branch):** a server-side enterprise data table in the house `team/index`/`team/report` pattern:

- Role-scoped dataset (Supervisor/Manager = workforce; Technician/Store Keeper = own records only) — **fixes the CERT-01 privacy defect**
- 5-KPI compliance strip (Total · Valid · Expiring Soon · Expired · Workers With No Certificates)
- Status tabs with filtered-scope counts: **All · Expired · Expiring Soon · Valid · No Expiry Date · None Recorded** (the last lists *workers* with zero certificates)
- Search (`/` shortcut, debounced), certificate-name and document-state filters, removable filter chips, auto-submit selects
- Sortable columns (`aria-sort`), server pagination (10/25/50/100, "Showing x–y of z", ellipsis window), Table/Cards views preserving all state
- Filtered **CSV export** (`team/certifications_csv`, BOM + formula-injection sanitising, role-scoped), print banner
- Descriptive document links, explicit "No document", supervisor Delete via the existing authorised endpoint, honest empty states, token-based styling (retired `bg-*` badges/hex/bi-icons removed), and the previously unescaped worker name is now escaped everywhere

**What did not change:** routes (`team/certifications` + new `team/certifications_csv`), the Add-Certification POST flow and its validation, `refresh_cert_status()` semantics, `cert_alert_days`, permissions on add/delete, the `certifications` schema, and `team/view` (out of scope here except as the Profile destination).

---

## 2. Table Purpose and User Workflow

| Item | Finding (from code) |
|---|---|
| Table / module | Certifications Tracker, Team module (`Team::certifications`, `team/certifications`) |
| Business purpose | **Monitoring + triage**: keep every worker's certificates valid, surface lapses before they become safety/audit findings, and route remediation to the worker profile. It is not an editing surface (add/delete live on `team/view`), so the table's job is *find → judge → open profile*. |
| Primary users | Supervisor (role 1, operator; only role that can add/delete), Manager (role 4, oversight/audit, read-only), Technician (2) & Store Keeper (3) (self-service: "are *my* certs valid?") |
| Most frequent tasks | (1) Who is expired/expiring and how soon? (2) Who has nothing recorded? (3) Open a worker to renew/add. (4) Produce the register for an audit/meeting (print/CSV). (5) Holder: when does my next certificate expire? |
| Primary record identifier | Certificate record (`certifications.id`), identified in UI by Worker + Certificate name (+ number) |
| Must-see-immediately | Worker, certificate, expiry + days remaining, status |
| Statuses (existing, from `refresh_cert_status`) | `Valid`, `Expiring Soon` (≤ `cert_alert_days`, default 30), `Expired` — derived from `expiry_date`; NULL expiry ⇒ stored `Valid` but displayed honestly as **No expiry date** |
| Actions (existing) | Open worker profile; open document; Delete (supervisor, POST + CSRF + confirm, document removed); Add happens on the profile. *No Edit/Renew exists — not invented here.* |
| Relationships | `certifications.user_id → users.id`; documents on disk under `uploads/documents/`; certificate counts already feed `team/index` (roster) and the attention bell (`Attention_model`, merged PR #62) |
| Data volume | One row per certificate per worker; workshop scale (10² workers, 10²–10³ rows) — server-filtered/sorted/paginated, no client dump |
| Stack / design system | CodeIgniter 3, server-rendered PHP views, house tokens in `layouts/header.php` (`.badge b-*`, SVG icons, `ct-` scoped CSS follows the `ts-`/`tm-` per-view pattern) |

---

## 3. Existing Implementation Findings (pre-change evidence)

1. **Dataset was hard-limited** to `get_expiring_certs($days)` (expired + ≤ window). Valid and no-expiry records were unlistable; "compliant" was unprovable (audit CERT-02).
2. **No role scoping on GET** — `get_all_active()` + all expiring certs for any authenticated user (CERT-01, privacy).
3. **Worker cards carried zero certification data**; answering "who has expired certs?" required opening every profile (CERT-03).
4. **No search/filter/sort/pagination/export/print**; unbounded rendering (CERT-04).
5. **No action path** from an alert row beyond a non-descriptive worker-name link and a generic "Open" document link (CERT-05, WCAG 2.4.4).
6. **Two populations on one page** (alert table: all users; cards: Active only), unstated (CERT-07).
7. **Silent empty states** — no certs ⇒ the card simply vanished (CERT-08).
8. **Retired dialect**: `wms-card`, solid `bg-success/bg-warning/bg-danger` badges, hard-coded hex, `bi-*` icons with no glyphs defined in the layout, single-initial avatars, and **worker name printed unescaped** (`certifications.php:74`).

What was already correct and is preserved: status refresh from dates, expired inclusion, configurable alert window, supervisor-only POST add/delete with CSRF, document upload constraints (on the profile), orphan-file cleanup.

---

## 4. UX Audit Matrix — 10 Principles

| # | Principle | Before | Evidence | After |
|---|---|---|---|---|
| 1 | Status-based tab filtering | **FAIL** | Single "Needing Attention" list; no tabs/counts | **PASS** — 6 tabs incl. None Recorded; counts computed after search/cert/doc filters, before tab (house rule); `role="tablist"`, `aria-selected`; state preserved across tabs |
| 2 | Smart toolbar & controls | **FAIL** | One Back button | **PASS** — certificate + document filters (options from real data only), search with `/` + 450 ms debounce, auto-submit selects, view toggle, CSV export, Reset; wraps on mobile |
| 3 | Professional table view | **PARTIAL** | Readable but unsortable, solid badges, generic links, unescaped name | **PASS** — sticky header, `scope="col"` + `aria-sort`, identity cell (palette avatar, name, ID·position), right-aligned Days Left with word+colour, tinted `.badge b-*`, issued/number/body columns, descriptive document links |
| 4 | Card / grid view | **PARTIAL** | Cards existed but were info-free navigation tiles | **PASS** — record cards with status, expiry+days, number, body, issued, document/profile actions; view switching preserves q/cert/doc/tab/sort/page-size; `aria-pressed` |
| 5 | Record detail panel/page | **PARTIAL** | Profile page exists; row link undiscoverable (`color:inherit`, no underline) | **PARTIAL (justified)** — detail remains the dedicated profile page (record complexity + add/delete live there); rows/cards now have a visible **Profile** action. A slide-over drawer was *not* added: it would duplicate `team/view` and the member drawer on `team/index` without new data. *(Drawer = recommendation only, requires confirmation.)* |
| 6 | Contextual row actions | **FAIL** | Name link + "Open" only | **PASS** — visible Profile (primary), document link where present, supervisor Delete (existing endpoint, POST/CSRF, confirm naming the document consequence). No invented Edit/Renew/Assign actions |
| 7 | Consistent semantic colour | **PARTIAL** | Solid `bg-*` fills inconsistent with house tinted badges; hex literals | **PASS** — `b-danger` Expired, `b-warning` Expiring Soon, `b-available` Valid, `b-secondary` No expiry date; tokens only; status always word + colour (never colour alone) |
| 8 | Pagination & data navigation | **FAIL** | None; unbounded | **PASS** — server pagination 10/25/50/100, Showing x–y of z (filtered total), prev/next + ellipsis window, `aria-current="page"`, filters/sort preserved; page clamped to range |
| 9 | Responsive & information priority | **FAIL** | 7-col table + card grid, no breakpoints | **PASS** — ≤760 px hides Issuing Body/Issued (secondary) keeping Worker/Certificate/Expiry/Days/Status/Actions; table scrolls horizontally only as fallback; cards grid is the touch-friendly alternative; KPI strip 5→3→2 columns |
| 10 | Micro-interactions, accessibility, feedback | **PARTIAL** | No focus/search affordances; `title`-only hints; no empty states | **PASS** — `/` focuses search, debounced submit, filter chips with remove + Reset all, three explicit empty states (none-recorded / nothing-recorded / no-match), focus-visible via global styles, print banner; flash feedback continues via the layout after delete/add |

## 5. Additional Enterprise-Grade Findings

| Area | Before | After / status |
|---|---|---|
| A. Data accuracy | Days/status computed in the *view*; NULL-expiry silently "Valid" | Days & tiers computed once in the dataset builder from `expiry_date` after `refresh_cert_status()`; NULL-expiry shown as **No expiry date** and counted in its own tab (display label only — stored status unchanged). Counts rule documented and unit-tested |
| B. Functional integrity | Search/sort/filter/export did not exist | All compose server-side from one builder shared by screen + CSV (`_build_certifications_dataset()`), so export ≡ screen dataset; record links open the correct profile; delete still hits the existing authorised endpoint |
| C. Performance | Unbounded rows + per-page full status refresh (pre-existing) | Pagination bounds rendering; builder is O(n) over one joined query + one worker query. Status refresh on every tracker view is pre-existing behaviour, unchanged. At 10³ rows this is fine; >10⁴ would justify SQL-side filtering *(requires confirmation, not needed now)* |
| D. Consistency | Retired dialect, phantom icons, solid badges | House pattern parity with `team/index`/`team/report` (KPI strip, tabs, chips, sort icons, avatars, print banner). New scoped prefix `ct-` follows the `ts-`/`tm-` convention |
| E. Security & permissions | **GET exposed all certs to all roles**; unescaped worker name (stored-XSS vector) | Dataset scoped server-side (never UI-hiding); manager remains read-only via the global Manager guard + supervisor-gated delete UI *and* endpoint; every rendered value escaped; CSV formula-injection sanitised like `roster_csv()` |

## 6. Prioritized Defect & Improvement Register

| ID | Severity | Finding → disposition |
|---|---|---|
| CT-01 | **Critical** | No role scoping on tracker GET (privacy) → **Fixed**: builder scopes by role; holder sees own records + own worker row only |
| CT-02 | **Critical** | Compliance unprovable (only failures listed; none-recorded invisible) → **Fixed**: All/Valid/No-Expiry tabs + None Recorded worker segment + KPI denominator |
| CT-03 | **High** | Worker cards info-free → **Fixed**: replaced by certificate record table/cards with per-worker identity cells and counts in tabs/KPIs |
| CT-04 | **High** | No search/filter/sort/pagination/export → **Fixed**: full toolbar, whitelisted sorts, pagination, filtered CSV, print |
| CT-05 | **High** | No remediation path from a row → **Fixed**: visible Profile action plus in-tracker Renew/Edit (supervisor) via `Team::edit_certification` — see §12 addendum |
| CT-06 | **Medium** | Single-window urgency only → **Fixed**: tabs now partition Expired / Expiring Soon (≤ window) / early-warning band (window+1…90d, labelled from the configured window) / Valid (>90d); KPI “Valid” still counts everything beyond the alert window — see §12 addendum |
| CT-07 | **Medium** | Two unstated populations on one page → **Fixed**: one role-scoped population; subtitle states scope and worker count |
| CT-08 | **Medium** | Silent empty states → **Fixed**: three explicit states with next actions |
| CT-09 | **Medium** | Unescaped worker name (stored XSS) → **Fixed** + regression assertion |
| CT-10 | **Medium** | Retired visual dialect (solid badges, hex, dead icons) → **Fixed**: tokens, `.badge b-*`, inline SVG, palette avatars |
| CT-11 | **Low** | Missing issued/number/body on tracker; generic "Open" links → **Fixed**: columns + card fields, descriptive `Open PDF` links with `aria-label`s, explicit "No document" |
| CT-12 | **Low** | No per-page control / showing summary → **Fixed** in pagination footer |

Acceptance criteria & verification per finding: role matrix + count rules (PHP-WASM dataset harness, 15 checks), rendering anatomy + escaping + empty states (PHP-WASM render harness, 16 checks), structural regressions (`tests/certifications_table_ux.js`, wired into CI), house suites green (see §12).

## 7. Recommended Table Structure & Interaction Specification (as built)

**Columns (default sort: Expiry ↑, no-date last, tie-break Worker → Certificate → id):**

| # | Column | Align | Format / content | Sortable |
|---|---|---|---|---|
| 1 | Worker | left | Palette avatar (2 initials), name → profile, `EMP-ID · Position` meta | ✔ worker |
| 2 | Certificate | left | Name; certificate number (mono) beneath | ✔ certificate |
| 3 | Issuing Body | left (hidden ≤760 px) | Text or — | — (low triage value) |
| 4 | Issued | left (hidden ≤760 px) | `d M Y` or — | ✔ issued |
| 5 | Expiry | left | `d M Y` or — | ✔ expiry (default) |
| 6 | Days Left | **right** | `12d overdue` / `Today` / `in 9d` / —, colour-reinforced | via Expiry |
| 7 | Status | left | Tinted badge: Expired / Expiring Soon / Valid / No expiry date | ✔ status (Expired→Soon→Valid→No-expiry) |
| 8 | Document | left | `Open PDF` (descriptive aria-label) or `No document` | — |
| 9 | Actions | right | Profile; Delete (supervisor, confirm) | — |

**Filters/toolbar:** tabs as §4.1; certificate select populated from *distinct real cert names in scope*; document = Any/With/Without; search across worker, employee ID, certificate, number, issuing body, position (≤100 chars, server-side); chips for every active filter + Reset all; page sizes 10/25/50/100; Table|Cards toggle; Export CSV mirrors filters/tab/sort.

**Record inspection:** dedicated profile page (justified §4.5); Add/Delete remain there and are linked as "Add Certificates" from None-Recorded rows for supervisors.

**Permissions:** Supervisor — all + delete; Manager — all, read-only; Technician/Store Keeper — own only, no delete; CEO — no access (controller matrix unchanged). Backend guards untouched; the builder scopes data server-side.

**Visual design:** Inter via global tokens; 24 px page title, 11 px KPI labels, 12.5 px cells; hairline separators, sticky header, hover row; `--blue/--green/--amber/--red` semantics through tinted fills; global focus-visible retained; `@media print` banner with scope counts.

## 8. Responsive Behaviour

Desktop: full 9-column table, 5 KPIs. ≤1100 px: KPIs 3-up. ≤760 px: KPIs 2-up, search full-width, Issuing Body/Issued hidden (data remains in Cards view and profile), horizontal scroll as fallback only, tap targets remain ≥28 px controls with 44 px-class global touch handling unchanged.

## 9. Before-and-After Comparison

| Change | Before → After | Why / benefit | Business behaviour changed? |
|---|---|---|---|
| Role scoping | Everyone saw all → role-scoped dataset | Privacy (CT-01); sidebar promise kept | **Yes (security fix)** — holders lose others' data they should never have had |
| Register scope | Expiring-only → all statuses + none-recorded | Compliance provable; blind spot closed | No — same records, fully presented |
| Navigation | Serial profile-hopping → tabs/search/sort/pagination | Triage in ≤2 interactions | No |
| Worker cards | Info-free tiles → record cards with expiry/status/doc | Comparison possible without opening profiles | No (tiles removed; their Profile link survives on every row/card) |
| Actions | Hidden name link → Profile/Delete/document links | Remediation starts from the alert | No new actions; Delete endpoint unchanged |
| Export/print | None → filtered CSV + print banner | Audit evidence | No |
| Styling | Solid badges/hex/dead icons → house tokens | Consistency, contrast | No |
| Escaping | Raw worker name → escaped | Stored-XSS closure | No |

## 10. Implementation Plan & Dependencies (executed)

1. `Team_model::get_certifications_overview($only_user_id)` — all-status joined records (existing `get_expiring_certs()` untouched for other consumers).
2. `Team::_build_certifications_dataset()` + `certifications_csv()` — one pipeline for screen & export; POST Add flow untouched.
3. `team/certifications.php` — full presentation rebuild (`ct-` scoped, tokens).
4. `tests/certifications_table_ux.js` + CI wiring. Dependencies: none (no schema, no new libraries). Risks: `certifications_csv` route resolves via the existing `team/(:any)` route; delete redirects to the profile (pre-existing) — acceptable, flash confirms.

## 11. Implementation

Files: `application/models/Team_model.php` (+1 method), `application/controllers/Team.php` (GET dataset builder + CSV; POST unchanged), `application/views/team/certifications.php` (rewritten), `tests/certifications_table_ux.js` (new), `.github/workflows/tests.yml` (runs the new suite), this doc.

## 12. Test Results & Verification Evidence

| Check | Result |
|---|---|
| PHP syntax (PHP-WASM `TOKEN_PARSE` lint): controller, model, view | ✅ OK ×3 |
| Dataset harness (real PHP, stubbed model): counts per tab, search-before-counts, cert/doc filters, sort worker desc, default expiry order (no-date last), holder vs manager scoping, none-segment | ✅ 15/15 |
| Render harness (real PHP, stubbed CI helpers): table/cards/none/empty scenarios, badges, descriptive links, delete form present for supervisor & absent for holder, exactly one `<h1>`, zero warnings | ✅ 16/16 |
| `node tests/certifications_table_ux.js` (new, in CI) | ✅ Pass |
| Existing suites: `page_titles`, `table_ux`, `global_find_attention`, `reorder_ux`, `reports_hub`, `service_kits_ux` | ✅ Pass |
| `node tests/lubricant_masters_ux.js` | ❌ Pre-existing failure ("catalog needs empty state" in lubricants files untouched by this change — fails identically without it; disclosed, not fixed here) |
| Live MySQL/browser E2E | ⏳ Runs in CI on the PR (no DB/browser in this sandbox) — **not verified locally** |

## 12b. Addendum — Implemented 2026-10-27 (follow-up “implement” instruction)

Built on top of the tracker, without schema changes or invented business rules:

- **Edit / Renew certification (CT-05 / VIEW-02).** `Team_model::update_certification()` + `Team::edit_certification($id)` (POST-only, supervisor-only server-side, expiry-before-issue rejected without writes). Renew updates the same record — the profile keeps one row per certificate instead of delete-and-retype. Document replace/removal supported (PDF/JPG/PNG ≤8 MB via the existing `_save_cert_document`); the superseded file is deleted only after the update succeeds. Exposed as **Renew** on tracker rows, **Renew / Edit** in the inspector drawer, and **Renew / Edit** on the worker profile certificate table (both post to the same endpoint; the tracker returns to the filtered list via a validated query string). Renewal *history* was **not** added — that would require a schema change (an audit/history table), which remains a decision for the user.
- **Certificate inspector ( §4.5).** Slide-over drawer on the tracker (Inspect button per row/card, Prev/Next across the page, Esc/overlay close) fed by `Team::certifications_json($id)`, role-scoped exactly like the tracker (Supervisors/Managers any record; other roles only their own). Shows status/days remaining, certificate number, issuing body, dates, document link, and worker identity; supervisors can jump straight to Renew from it.
- **Early-warning tab (CT-06).** When `cert_alert_days` < 90 a band tab (e.g., “Expiring in 31–90d”) partitions the register from Valid (>90d); the KPI “Valid” figure deliberately keeps the broader meaning (everything beyond the alert window), documented in the metric definition.

| Check | Result |
|---|---|
| PHP-WASM `TOKEN_PARSE` lint (controller, model, both views) | ✅ OK ×4 |
| Dataset harness (real PHP) | ✅ 17/17 incl. upcoming-band partition + KPI valid semantics |
| Render harness | ✅ 16/16, zero PHP warnings |
| Endpoint harness (real PHP): in-place update, status recompute, tracker/profile redirects, invalid-date rejection, holder blocked, document removal | ✅ 12/12 |
| `node tests/certifications_table_ux.js` (extended) | ✅ Pass |
| Existing suites (`page_titles`, `table_ux`, `global_find_attention`, `reorder_ux`, `reports_hub`, `service_kits_ux`) | ✅ Pass |
| `node tests/lubricant_masters_ux.js` | ❌ Pre-existing failure, unchanged (unrelated files) |
| Live MySQL/browser E2E | ⏳ Runs in CI — **not verified locally** |

## 13. Outstanding Risks, Unresolved Decisions & Acceptance Checklist

**Decisions still required (not built):** required-certificates-per-role matrix (CERT-12) — needs the organisation's role → required-certificate policy; until then "None Recorded" means *no record*, not *non-compliant for role*. Renewal *history*/audit trail (VIEW-02 extension) — needs a schema decision (history table), not yet authorised.

**Acceptance checklist:** ☑ correct records/values (harness) · ☑ hierarchy supports triage · ☑ search/filters/sort/pagination compose (one builder, tested) · ☑ counts match defined rules · ☑ actions functional & authorised (existing endpoints; role-tested UI) · ☑ details open correct record (profile links by `user_id`) · ☑ destructive op confirms + consequence stated · ☑ responsive preserves essential columns/actions · ☑ empty states (loading/error are full-page server renders — no async states apply) · ☑ keyboard/focus/labels (`/`, tabs, aria-sort, descriptive links) · ☑ house conventions · ☑ tests pass with failures disclosed · ☑ no functionality removed (expiring table + worker navigation both survive in stronger form) · ☐ CI DB/E2E on PR (pending, outside sandbox).

**Sample verification tasks:** (1) Supervisor: find all expired forklift licences → Expired tab, read names. (2) Manager: export register for audit → Export CSV equals screen filters. (3) Technician: "when does my next cert expire?" → My Certifications, first row by Expiry.
