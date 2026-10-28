# Worker Profile (`team/view/<id>`) — Professional Data Table UX Audit, Improvement & Implementation

**Date:** 2026-10-27 · **Scope:** `application/views/team/view.php` and the `Team::view()` datasets (Certifications card, Leave Balance, Leave History, Attendance strip, Quick Stats). No schema changes, no new permissions, no invented fields/statuses. This page is the *record detail page* of the workforce module (Principle 5's destination from the Directory, Roster, Certifications Tracker and Punch Log).

**Related prior work:** `docs/team-certifications-view-ux-audit.md` reviewed this page earlier (VIEW findings); the Renew/Edit certification flow (PR #63) already landed here. This pass covers the profile's tables as tables.

---

## 1. Executive Summary

The profile answered "who is this worker?" well (identity card, quick stats, leave balance) but its two growing tables did not scale or stay consistent: the Certifications table showed the *stored* status — so a certificate with no expiry date read "Valid" forever (VIEW-03, already fixed on the Tracker in PR #63 but not here) — with retired solid badges, no counts, no tabs and no pagination; Leave History grew unbounded down the sidebar with solid badges and no status filter. This change gives both cards the house treatment (counts, status tabs, independent pagination, tinted badges), fixes the no-expiry display, makes the 30-day attendance strip accessible (it was colour-only swatches), and associates the remaining unlabelled form controls — without touching any endpoint, validation or permission.

## 2. Table Purpose and User Workflow

- **Purpose:** single-worker record — identity, employment facts, certification compliance, leave balance/history, recent attendance; the hub supervisors open before assigning work, approving leave, or renewing a certificate.
- **Primary users:** Supervisor (full actions), Manager (read-only), the worker themselves (own profile only — enforced server-side in `Team::view()`).
- **Frequent tasks:** check certification status/expiry; renew or add a certificate; check leave balance before approving; review leave history; download the AML timesheet; scan recent attendance.
- **Records & tables:** Certifications (worker × certificate), Leave Balance (leave type × entitled/approved/pending/remaining — bounded by configured types), Leave History (leave requests, newest first, grows yearly), Attendance (last 30 days grid + Present/Absent/Leave summary). Detail target *is* this page; outward links: Edit Profile, Request Leave, timesheet download, document files.
- **Statuses (existing only):** certificate Valid / Expiring Soon / Expired (+ the display label "No expiry date" for NULL expiry, as on the Tracker); leave Approved / Pending / Rejected; attendance Present / Absent / Leave / Rest / Holiday / Not recorded; worker Active / Inactive / On Leave.

## 3. Existing Implementation Findings (pre-change evidence)

- Strengths kept: server-side own-profile guard; cert status refreshed from dates on every view (`refresh_cert_status`); attendance-rate computed over *judged* days (Rest/Holiday excluded — documented in code); native `<dialog>` Add/Renew flows; escaped output.
- **Defects:** (a) cert rows printed `$c->status` directly — NULL-expiry certs stored as `Valid` displayed Valid with no expiry shown (the Tracker fixed this display-side in PR #63; the profile didn't); (b) cert badges were retired solid `bg-success/bg-warning/bg-danger` classes with hard-coded hex sub-text; (c) no cert counts/tabs — an expired cert hid among valid ones; (d) certifications and leave history rendered **all rows** (a 10-year worker's leave list runs the whole sidebar); (e) leave badges solid `bg-*` variants; (f) attendance dots were colour-only `<div title>` swatches (title tooltips are not reliably announced; no text alternative beyond the legend); (g) Add-certification dialog labels and the timesheet Month/Period labels had no `for` association; (h) document link read just "Open".

## 4. UX Audit Matrix — 10 Principles

| # | Principle | Verdict (before) | Evidence / change |
|---|---|---|---|
| 1 | Status tabs | **FAIL → Fixed** | Certifications: All/Expired/Expiring Soon/Valid/No Expiry Date tabs with worker-scoped counts; Leave History: All/Approved/Pending/Rejected tabs |
| 2 | Smart toolbar | **PARTIAL** | Per-card tabs + pagination now act as the toolbar; a search box was deliberately *not* added — a single worker's certs/leave are few and tab+page covers growth honestly (documented, not faked) |
| 3 | Professional table | **PARTIAL → Fixed** | `scope="col"` headers, tinted house badges, days-left in tokens, descriptive "Open document" links, urgency ordering (expired → expiring → valid → no-expiry) |
| 4 | Card view | **N/A (justified)** | The cards *are* the cards; the profile is already a card layout. A second card mode inside cards adds nothing |
| 5 | Record detail | **PASS (this page)** | The page itself is the detail view linked from Directory/Roster/Tracker/Punch Log; identity/stats/balance stay first, history below |
| 6 | Contextual row actions | **PARTIAL → Fixed** | Cert rows keep Renew/Edit + Delete (existing endpoints, supervisor-gated, confirm states the document consequence — from PR #63); leave rows intentionally action-free here (decisions live in the leave workflow, not invented onto the profile) |
| 7 | Semantic colour | **PARTIAL → Fixed** | Cert + leave badges moved from solid `bg-*`/hard-coded hex to shared tinted `b-*` classes with text labels; status never colour-alone (labels + days-left text) |
| 8 | Pagination | **FAIL → Fixed** | Certifications 10/page (5/10/25/50), Leave History 5/page (5/10/25) — **independently paged** (`cpage`/`lpage`) so one card never resets the other; "Showing a–b of N in this tab" summaries |
| 9 | Responsive | **PARTIAL** | Existing Bootstrap grid (4/8 split stacks on mobile); cert table horizontally scrolls via `.wms-table` conventions; tabs wrap |
| 10 | Micro-interactions & a11y | **FAIL → Fixed** | Attendance swatches now `role="img"` with `aria-label` ("2026-10-26: Present") + a visible "Last 30 days: n Present · n Absent…" text summary; dialog + timesheet labels `for`-associated; tabs `role=tablist`/`aria-selected` |

## 5. Additional Enterprise-Grade Findings

- **A. Data accuracy:** display status is *computed* from `expiry_date` + the configured alert window at render (same rule as `refresh_cert_status` and the Tracker), so profile and tracker can no longer disagree; NULL expiry = "No expiry date" on both surfaces. Attendance-rate denominator logic untouched (already correct and documented).
- **B. Functional integrity:** cert tabs filter the worker's own certificates only; leave tabs match `leave_requests.status` values exactly (`Approved`/`Pending`/`Rejected`); Add/Renew/Delete still post to the same endpoints with the same validation; both pagers preserve the other card's tab/page in URLs.
- **C. Performance:** datasets are per-worker (bounded by one person's history); pagination happens in PHP after existing model queries — no new queries added.
- **D. Consistency:** badge classes, tab pattern, pager and "No expiry date" label now match the Certifications Tracker; identity/quick-stats cards untouched structurally.
- **E. Security:** own-profile guard, supervisor gating of Add/Renew/Delete/Edit, and CSRF via `form_open` unchanged; outputs were already escaped and remain so.

## 6. Prioritized Defect & Improvement Register

| ID | Severity | Finding → outcome |
|---|---|---|
| VP-01 | **High** | NULL-expiry certificate displayed "Valid" forever on the profile (VIEW-03 parity gap) → computed display status + "No expiry date" badge, urgency ordering; harness-verified |
| VP-02 | **Medium** | Certifications + Leave History rendered all rows unbounded → status tabs + independent pagination with counts |
| VP-03 | **Medium** | Attendance strip colour-only (`title` divs) → `role="img"` labels + visible text summary of the 30 days |
| VP-04 | **Low** | Solid retired badges + hard-coded hex in cert/leave rows → shared tinted `b-*` badges, token colours |
| VP-05 | **Low** | Unlabelled controls (Add-cert dialog, timesheet Month/Period) → `for`/`id` association |
| VP-06 | **Low** | Document link text "Open" (no context) → "Open document" with `aria-label` naming the certificate; "No document" stated explicitly |

## 7. Recommended Table Structure & Interaction Specification (as built)

- **Certifications card:** header summary ("N recorded · x expired · y expiring soon"), tabs (All/Expired/Expiring Soon/Valid/No Expiry Date with counts), columns Certificate | Number | Issued By | Expiry (+ days-left/expired text) | Status badge | Document | Actions (Renew/Edit, Delete — supervisor). Ordered by urgency then expiry; 10 per page.
- **Leave Balance:** unchanged (bounded, numeric right-aligned mono; header `scope` added).
- **Leave History:** status tabs (All/Approved/Pending/Rejected), type + status badges, date range + days; 5 per page.
- **Attendance strip:** 30 day cells with accessible names + legend + text summary; Quick Stats card unchanged.
- **No search/cards/export on this page:** per-worker datasets are small and already exported via the Tracker/Reports; adding them here would duplicate module functions (documented decision, §4.2/4.4).

## 8. Responsive Behaviour

Unchanged grid: sidebar (profile/stats/leave) stacks above certifications on small screens; tabs wrap; cert table keeps `.wms-table` overflow behaviour; pagers wrap. Dialogs already max-width responsive.

## 9. Before-and-After Comparison

| Area | Before | After | Business behaviour changed? |
|---|---|---|---|
| No-expiry cert | "Valid" badge, no expiry | "No expiry date" badge, sorted last | No — stored status untouched; display matches Tracker |
| Find expired certs | Scan all rows | Expired tab + header count | No |
| Long leave history | Whole sidebar scroll | 5/page + status tabs | No |
| Attendance strip | Colour swatches + legend | Accessible names + text summary | No |
| Cert/leave badges | Solid `bg-*`, hex text | Tinted shared `b-*` | No (visual) |

## 10. Implementation Plan & Dependencies (executed)

1. `Team::view()` computes cert display dataset (counts, tabs, urgency sort, pagination) + leave tabs/pagination. 2. View: tabs/pagers/badges/labels/attendance a11y. 3. Structural tests + CI. 4. PHP-WASM verification driving the real `view()` with stub models.

## 11. Implementation

Files: `application/controllers/Team.php` (`view()` datasets only — no endpoint changes), `application/views/team/view.php`, `tests/team_profile_ux.js` (new), `.github/workflows/tests.yml`, this document.

## 12. Test Results & Verification Evidence

| Check | Result |
|---|---|
| PHP-WASM `TOKEN_PARSE` lint (controller, view) | ✅ OK |
| Harness driving the real `Team::view()` (stub models, 14 certs incl. expired/expiring/NULL + 7 leave rows): counts 14/1/1/1, NULL → "No expiry date", urgency order (expired first), cert pagination 10+4, leave counts 7/4/2/1 + pagination, tab filters, render badges/counts/pager, exactly one `<h1>` | ✅ 16/16, zero PHP warnings |
| `node tests/team_profile_ux.js` (new, in CI) | ✅ Pass |
| Existing suites: `attendance_log_ux`, `shift_roster_ux`, `certifications_table_ux`, `page_titles`, `table_ux`, `global_find_attention`, `reorder_ux`, `reports_hub`, `service_kits_ux` | ✅ Pass |
| `node tests/lubricant_masters_ux.js` | ❌ Pre-existing failure (lubricant views untouched; disclosed, not fixed here) |
| Live MySQL/browser E2E | ⏳ Runs in CI — **not verified locally** |

## 13. Outstanding Risks, Unresolved Decisions & Acceptance Checklist

**Not built (documented decisions):** search within a worker's certificates/leave (tabs+pagination suffice at per-worker scale); leave row actions on the profile (approval lives in the leave workflow); attendance range beyond 30 days (the timesheet/report modules own ranges); required-certificates gap view (CERT-12 — still awaits the role→certificate policy from the Certifications audit).

**Acceptance checklist:** ☑ correct records/values (harness; computed status parity with Tracker) · ☑ hierarchy supports tasks (compliance first in cert card) · ☑ tabs/pagination compose and preserve each other's state · ☑ counts match defined rules · ☑ actions functional & authorised (existing endpoints only) · ☑ details correct (this is the detail page) · ☑ destructive op confirms + consequence stated (unchanged) · ☑ responsive grid preserved · ☑ empty states per tab (no expired / none pending / every cert dated) · ☑ keyboard/labels (tabs, dialog + timesheet labels, attendance names) · ☑ house conventions · ☑ tests pass with failures disclosed · ☑ no functionality removed (Add/Renew/Delete, timesheet download, Request Leave intact) · ☐ CI DB/E2E on PR (pending, outside sandbox).

**Sample verification tasks:** (1) Supervisor: "does John have anything expired?" → profile → Certifications header count / Expired tab. (2) Manager: check leave balance before approving → Leave Balance + Pending tab of Leave History. (3) Worker: "was I marked present last Friday?" → attendance strip cell (accessible name + summary).
