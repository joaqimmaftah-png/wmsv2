# Team Certifications Tracker (`team/certifications`) & Worker Profile (`team/view/`) — UX/UI Audit

**Role of this document:** Senior product-design review only. **No application code, view, controller, model, route, or schema was changed.** This audit is the approval gate required before any redesign.

**Scope reviewed**

| Surface | Files |
|---|---|
| Certifications Tracker | `application/views/team/certifications.php` (86 lines), `Team::certifications()` in `application/controllers/Team.php`, `Team_model::get_expiring_certs()`, `refresh_cert_status()`, `get_certifications()` |
| Worker Profile | `application/views/team/view.php` (314 lines), `Team::view()`, Add-Certification `<dialog>`, `Team::delete_certification()` |
| Internal reference standard | `application/views/team/index.php` (Workforce Directory, 1,689 lines) and `application/views/team/report.php` (1,762 lines) — the system's own mature design system |
| Shared chrome | `application/views/layouts/header.php` (design tokens, `.badge b-*` system, icon glyphs), `application/views/layouts/sidebar.php` (navigation labels/promises) |
| Data model | `certifications` table in `database/schema.sql` |

**Users and their actual jobs (inferred from roles, RBAC, and workflows in code)**

1. **Supervisor (role 1)** — the compliance operator. Daily/weekly job: *"Who is expired or about to expire, who must not be assigned to regulated work, and how do I fix the record?"* Only role that can add/delete certifications.
2. **Manager (role 4)** — oversight. Job: *"Is the workforce compliant, where is the risk concentrated (site / role / certificate type), and can I prove it in an audit?"* Read-only in practice (Add/Delete are supervisor-gated).
3. **Technician / Store Keeper (roles 2/3)** — self-service. Job: *"Are **my** certificates valid, what expires next, and what do I need to renew?"* Sidebar explicitly promises this user **"My Certifications"**.

**Method:** line-by-line heuristic evaluation of both views against (a) the system's own reference patterns in `team/index` + `team/report`, (b) established UX principles (Nielsen heuristics, WCAG 2.x AA, information-scent and recognition-over-recall), and (c) published certification-compliance practice. External patterns were used to *validate* problems, never as templates to copy.

---

## 1. Executive Summary

The **data engine underneath these pages is sound** — certificate status is recomputed from expiry dates on every view (`refresh_cert_status()`), expired certificates were fixed to appear in the tracker instead of silently dropping out (`get_expiring_certs()`), the alert window is configurable (`cert_alert_days`, default 30), RBAC on `team/view` correctly restricts non-supervisors to their own profile, and the Add-Certification form uses a native `<dialog>` with real validation (expiry ≥ issued, document type/size limits, orphan-file cleanup).

The **presentation layer has not kept up**. Both pages are written in the older `wms-card` / Bootstrap-utility dialect, while the rest of Team (`index`, `report`) has moved to a token-based design system with KPI strips, status tabs with counts, search, filter chips, sortable tables with `aria-sort`, slide-over inspectors, and print layouts. The result:

- The **Tracker cannot answer its three core questions**: *Who is compliant? Who has no certificates at all? Which certificates (by type/site/role) are at risk?* It lists only expired/expiring rows, then shows a grid of worker cards containing **no certification data whatsoever** — every answer requires opening each worker one by one.
- The **Profile buries its most safety-relevant data**: on mobile, Certifications render after the profile card, timesheet download, quick stats, leave balance, and leave history; an expired certificate produces no warning banner and is not connected to the worker's open work orders.
- There is **no renewal workflow**: the only record actions are *Add* and *Delete*. Renewing means deleting the old record (and its document) and re-typing everything — destroying the very history an auditor wants.
- A **privacy/expectation defect**: the sidebar sells Technicians "My Certifications", but `Team::certifications()` applies no role scoping on GET, so any authenticated user sees **every** active worker and **every** expiring certificate in the company.

**Overall judgement:** functionally honest, visually dated, and — for the compliance use case — *one interaction too slow at every step*. Nothing here needs a visual reinvention; it needs the discipline the system already proved in `team/index` and `team/report`, plus a renewal action and role-scoped data.

**Finding counts:** 4 Critical · 7 High · 6 Medium · 3 Low (register in §4).

---

## 2. What Works — Do Not Rebuild

These are genuine strengths. Any approved redesign must preserve them.

| # | Strength | Evidence |
|---|---|---|
| W1 | **Status can never lie about dates.** Stored `status` is recomputed from `expiry_date` on every tracker/profile/directory view. | `Team_model::refresh_cert_status()`; called in `Team::view()`, `Team::certifications()`, `get_roster_overview()` |
| W2 | **Expired certificates are visible.** The tracker query includes already-expired rows (a previous lower-bound bug that hid them is fixed and documented). | `Team_model::get_expiring_certs()` docblock |
| W3 | **Configurable alert window** instead of a hard-coded 30 days, shared by tracker, profile, and status refresh. | `Team::_cert_alert_days()`, Settings → `cert_alert_days` |
| W4 | **Correct RBAC on the profile.** Non-supervisors/managers are redirected to their own record; Add/Delete POSTs are supervisor-gated server-side, not just hidden in the UI. | `Team::view()`, `Team::certifications()` POST guard, `Team::delete_certification()` |
| W5 | **Native `<dialog>` for Add Certification** — works without Bootstrap JS, supports `Esc`, has a backdrop; the code comment explains *why*, showing the old modal was genuinely broken. | `team/view.php` lines 274–313 |
| W6 | **Honest attendance maths.** The 30-day rate divides by judged days (Present+Absent+Leave), not calendar days, with the reasoning commented; "No records" is distinguished from 0%. | `team/view.php` Quick Stats; `Team_model::get_monthly_report()` |
| W7 | **Safe destructive action (relatively).** Delete confirms, states the document is removed too, deletes row-then-file, and reports failure honestly. | `team/view.php` cert table; `Team::delete_certification()` |
| W8 | **Upload hygiene.** Document restricted to PDF/JPG/PNG ≤ 8 MB, stored under `uploads/documents/`, cleaned up if the insert fails. | `Team::_save_cert_document()` |
| W9 | **Leave balance transparency** — entitled/approved/pending/remaining per type, untracked types shown as "—" rather than a fake 0. | `team/view.php`; `Team_model::get_leave_balance()` |
| W10 | **The directory already solved certification surfacing.** `team/index` shows per-worker `n Expired / n Expiring / n Valid` badges, a Cert Alerts tab, cert-aware sorting, and a member drawer with certificate detail. The patterns to reuse already exist in-house. | `team/index.php` cert cell, tabs, `member_json()` |

---

## 3. Research & Benchmark Context (validation, not imitation)

External evidence was used to check whether the problems found are real compliance-workflow problems or matters of taste. They are real:

- **Tiered, escalating expiry alerts are the industry norm** — typically 90 / 60 / 30 days (tightening to 14 / 7 for high-risk credentials), routed to *both* the holder and their manager, with the first alert set from the *renewal lead time* (course frequency + approval time), not a round number [1](https://certlister.com/blog/certificate-expiration-tracking-best-practices/). Certification-tracking evaluations likewise treat configurable multi-threshold alerts to employee + manager, a full audit trail, and **role-based requirement mapping (gaps vs. what a role requires)** as the capabilities that separate a compliance system from a list [2](https://www.muchskills.com/blog/employee-certification-tracking-software).
- **Compliance dashboards are expected to answer three questions in under a minute** — who is current, who expires within 90 days, who is overdue — filterable by site, role/shift, and credential type [3](https://www.ag5.com/certification-tracking-software/). Dashboards that display *every* record instead of the actionable subset become unreadable; the actionable subset (expiring 30–60 days, lowest-compliance groups, highest-risk credentials) is what belongs on the surface [4](https://www.screenly.io/manufacturing/training-certification-compliance-dashboards/).
- **Accessibility:** meaning must never ride on colour alone — status needs a text/icon second channel; text contrast ≥ 4.5:1 (WCAG 1.4.1 / 1.4.3) [5](https://www.nngroup.com/articles/visual-treatments-accessibility/). Links must describe their purpose out of context (WCAG 2.4.4), form inputs need programmatically associated labels (1.3.1 / 3.3.2), and table headers need proper scope/sort semantics.

**What was deliberately *not* imported from benchmarks:** LMS course enrolment, automated email/SMS reminder engines, and scheduling blocks on lapsed licences. WMS has no notification-delivery infrastructure for certificates today (no cert-expiry hooks exist in `Attention_model`, Dashboard, or any mailer), no table mapping *required* certificates to roles/positions/equipment, and no evidence yet that assignment-blocking is a desired policy. Recommending those as UI would ship decoration over missing foundations — they are recorded as product decisions (CERT-12), not design tasks.

**The strongest benchmark is internal.** `team/index` and `team/report` already implement, in this codebase's tokens and idioms: KPI strips, count-bearing status tabs (`role="tablist"`, `aria-selected`), `/`-shortcut search, active-filter chips with individual removal, SVG sort indicators with `aria-sort`, identity cells (avatar palette + name + employee code), tinted semantic badges (`.badge b-success/b-warning/b-danger`), rate bars, empty states with reset actions, pagination with page-size, batch export bars, slide-over inspectors with keyboard navigation, and `@media print` report banners. The two audited pages use **none** of these. Consistency with a user's already-learned system beats novelty (Jakob's Law applied internally).

---

## 4. Finding Register

Severity: **Critical** = blocks or misleads the core compliance job, or leaks data · **High** = makes the job slow/error-prone for most users · **Medium** = friction, inconsistency, or risk in edge cases · **Low** = polish.

### A. Certifications Tracker (`team/certifications`)

---

#### CERT-01 · "My Certifications" shows everyone's certifications — **Critical**

**Evidence.** Sidebar (all non-manager roles): label `My Certifications` for technicians (`layouts/sidebar.php`). But `Team::certifications()` guards only the *POST*; the GET path unconditionally loads `get_all_active()` (all active workers) and `get_expiring_certs()` (all expiring/expired certificates company-wide) and renders them for any authenticated user. Contrast `Team::view()`, `member_json()`, `sheet_json()`, and `roster_csv()`, which all scope non-supervisors to themselves.

**Why it matters.** (1) *Privacy:* a technician can enumerate colleagues' licence numbers' expiry state and open their documents — horizontal PII exposure of exactly the kind `team/index` was deliberately closed against ("only Supervisors & Managers view the full workforce directory"). (2) *Expectation:* the navigation promises a personal page; landing on a company-wide alert table breaks trust and orientation. (3) *Usability:* the technician's actual job ("are **my** certs OK?") is made harder — their own expiring certificate is one anonymous row among everyone's.

**Recommendation.** Scope the dataset by role, mirroring the existing pattern in `Team::report()` (`$can_view_all` / `$only_uid`): supervisors+managers get the workforce tracker; technicians/store keepers get a personal view (their certificates, their next expiry, renewal guidance). Keep the URL; change the data and the heading to match the promise. *This needs a controller change, so it is a design recommendation for approval, not a view tweak.*

**Expected outcome.** Privacy posture consistent with the rest of Team; the technician's #1 question answered in the first screenful; sidebar label becomes true.

---

#### CERT-02 · The tracker cannot show compliance — only non-compliance — **Critical**

**Evidence.** The only dataset is `get_expiring_certs($days)`: rows whose expiry is ≤ today + window. There is no list of valid certificates, no per-worker totals, no denominator. The header summary is a single 12px line inside the alert card: *"3 expired · 5 expiring within 30 days"* — with no workforce total, no compliance percentage, no comparison. A worker whose certificates are all valid is *invisible*; a worker with **zero** certificates is indistinguishable from a fully compliant one.

**Why it matters.** A compliance tool that can only enumerate failures cannot answer "are we compliant?" — the manager's primary question — and cannot detect the highest-risk state of all: a worker who should hold a certificate but has none recorded. Research consensus is that the dashboard's job is *current / expiring / overdue* at a glance, with the workforce as denominator [3](https://www.ag5.com/certification-tracking-software/).

**Recommendation.** Add a summary strip computed from data the system *already aggregates* in `Team_model::get_roster_overview()` (`cert_valid / cert_expiring / cert_expired / cert_total` per worker): e.g. *Workers with certificates · Fully valid · Expiring ≤ window · Expired · No certificates recorded*. Make "No certificates recorded" a first-class, clickable segment — it is currently the tracker's biggest blind spot.

**Expected outcome.** Compliance becomes a measurable state (% valid, count at risk, count unrecorded) instead of an anecdote; audits start from a denominator.

---

#### CERT-03 · Worker cards contain no certification information — **Critical**

**Evidence.** The entire lower half of the page (`certifications.php` lines 62–86) renders one card per active worker with avatar, name, position — and a single button, "View / Add Certifications". No counts, no worst-status, no next expiry. The directory (`team/index`) already renders `2 Expired` / `1 Expiring` / `3 Valid` badges per worker; the dedicated certifications page shows *less* certification data than the general directory.

**Why it matters.** The page's structure forces a serial click-through: to find "who has expired certs?" a supervisor must open every profile (or abandon this page for `team/index`'s Cert Alerts tab — which already does this job better, making this page redundant for its main task). Recognition-over-recall is violated: information exists in the model (`get_roster_overview()` cert counts) but is withheld at the exact point of decision.

**Recommendation.** Replace info-free cards with a **worker compliance table** (or enrich cards if cards are retained): identity cell (two-initial avatar from the shared palette, name, employee ID, position), site, certificate summary (Valid / Expiring / Expired counts or worst-status badge), next expiry date + days left, and a row action to the profile's certification section. Default sort: expired first, then soonest expiry (the directory's `certs:desc` "Cert Alerts First" sort already encodes this ordering).

**Expected outcome.** The tracker becomes the fastest route to "who needs action", superseding profile-hopping; page purpose matches its name.

---

#### CERT-04 · No search, filter, sort, pagination, or export — **High**

**Evidence.** 86-line view: a fixed table (ordered by expiry in SQL) and an unbounded card grid. No `q` search, no site/role/certificate-type filter, no column sorting, no pagination, no CSV/print. Every sibling list page (`team/index`, `team/report`, suppliers, lubricants) has all of these.

**Why it matters.** At workshop scale (dozens–hundreds of workers), an unfilterable alert table fails the filter-by-site/role/type expectation that compliance dashboards are judged on [3](https://www.ag5.com/certification-tracking-software/), and the absence of export/print blocks the audit use case ("give me the expiry list for Friday's safety meeting") that the rest of the system supports via CSV and print banners.

**Recommendation.** Adopt the house pattern wholesale: status tabs (All / Expired / Expiring / Valid / None recorded, with counts), search (`/` shortcut), site + certificate-name filters with removable chips, sortable columns (Worker, Certificate, Expiry, Days left, Status) with SVG indicators + `aria-sort`, page-size pagination, CSV export of the filtered set, and a print banner. Reuse `team/report`'s `.ts-*` or `team/index`'s `.tm-*` primitives rather than inventing a third dialect.

**Expected outcome.** Any compliance question sliceable in ≤ 2 interactions; audit evidence exportable; zero new patterns for users to learn.

---

#### CERT-05 · No action can be taken from the tracker — **High**

**Evidence.** Rows offer exactly two exits: worker-name link → profile, and "Open" → document. There is no Renew, Edit, Upload-document, or even "Add certificate for this worker" deep link. Remediation requires: open profile → scroll to Certifications → use Add (which creates a *new* record; see VIEW-02).

**Why it matters.** An alert surface without a remediation path is a report, not a tracker. Every expired row is the start of a task; making the task begin on another page, in another context, adds navigation cost at the moment of highest intent (and the document link, the least useful action here, is the most prominent).

**Recommendation.** Add a row action hierarchy mirroring `team/report` (primary visible action + overflow): primary = **Renew / Update** (once VIEW-02 exists) or **View worker**; overflow = Open document, Add certificate. Until a renew action exists, the honest primary action is a deep link to `team/view/{id}#certifications` with the Add dialog ready — do not ship inert "Renew" buttons.

**Expected outcome.** Alert → action in one click; tracker becomes a work queue, not a noticeboard.

---

#### CERT-06 · Single undifferentiated alert window hides renewal lead-time risk — **High**

**Evidence.** One configurable threshold (`cert_alert_days`, 30) drives status, tracker inclusion, and counts. A certificate expiring in 29 days that needs a quarterly course + manager approval is presented identically to one renewable online tomorrow. The view also recomputes expired/expiring counts inline (view-layer logic duplicated from the model).

**Why it matters.** Best practice sets the *first* alert from renewal lead time (course cadence + approvals + margin); a flat 30-day window systematically arrives too late for slow renewals [1](https://certlister.com/blog/certificate-expiration-tracking-best-practices/). Tiered 90/60/30 presentation is the norm because urgency is not binary [2](https://www.muchskills.com/blog/employee-certification-tracking-software).

**Recommendation.** Present **time buckets** on the tracker (Expired · ≤ 30 days · 31–90 days) as tabs/sections reusing the existing counts, and label the configured window wherever "Expiring Soon" appears ("within 30 days — change in Settings"). Defer any *notification* ladder (email/in-app) until delivery infrastructure is a deliberate product decision (CERT-12); bucketing is display-only and honest today.

**Expected outcome.** Slow renewals surface while they are still schedulable; urgency is legible without new backend systems.

---

#### CERT-07 · Invisible workers and inconsistent populations — **Medium**

**Evidence.** The alert table joins *all* users (`get_expiring_certs` has no status filter — correctly, since an on-leave worker's licence still lapses), but the worker grid below uses `get_all_active()` only. A worker who is Inactive or On Leave can appear in the alert table yet have no card; conversely the grid's population is silently "Active only" with no label saying so.

**Why it matters.** Two lists on one page with different, unstated denominators invite misreading ("why is he in the table but not below?") and can hide lapsed certificates of on-leave staff returning to work.

**Recommendation.** One population, stated once ("Active workers · N"), with employment status shown as a badge where it differs; or drop the separate grid entirely in favour of CERT-03's unified table where status is a column/filter.

**Expected outcome.** No phantom discrepancies; returning-from-leave compliance checked before, not after, the first shift.

---

#### CERT-08 · Empty and all-valid states are silent — **Medium**

**Evidence.** If `$certs` is empty, the alert card simply doesn't render (`if (!empty($certs))`). There is no "All certificates are valid 🎉 / nothing expires within 30 days" confirmation, no last-checked context. If `$workers` is empty, the page is a heading and whitespace.

**Why it matters.** Absence of an alert is ambiguous: *all clear* vs. *nothing recorded* vs. *page broken*. Empty states are a designed surface in the house system (`team/report` illustrated empty state + reset action); silence here undermines confidence in a compliance tool, where "no news" must be verifiably good news.

**Recommendation.** Explicit states: (a) all-valid → success panel with counts ("42 certificates · all valid · next expiry 12 Mar 2027 (Sarah …, Forklift)"); (b) none recorded → guidance + (supervisor) Add entry point; (c) filtered-to-zero → reset-filters action.

**Expected outcome.** "No alerts" becomes a trustworthy, auditable statement.

---

### B. Worker Profile (`team/view/`)

---

#### VIEW-01 · Expired certification on a profile raises no alarm and connects to nothing — **Critical**

**Evidence.** The profile renders certificates as a neutral table; an `Expired` badge is the only signal. There is no profile-level banner ("⚠ 1 expired certificate — Forklift Operator Licence expired 12 days ago"), no summary count in the section header, and no link to the worker's current assignments — even though `member_json()` already computes `open_workorders` for the directory drawer, `Team::view()` doesn't load them at all. A supervisor can read this profile top-to-bottom and miss that the person is assigned open work while unlicensed.

**Why it matters.** The profile is where assignment and fitness-for-work decisions are made. Compliance risk that requires table-scanning to notice will be missed under time pressure; risk disconnected from workload can't be prioritised (expired cert + 3 open WOs ≠ expired cert + no assignments).

**Recommendation.** (1) A conditional compliance banner at the top of the Certifications card (red for expired, amber for expiring ≤ window) stating count, certificate, days, and the next action. (2) Show open work orders on the profile (data already exists via `get_worker_open_workorders()`), so cert risk and workload are visible together. (3) Section header carries a status summary badge ("2 Valid · 1 Expired").

**Expected outcome.** Fitness-for-work risk is unmissable at the point of decision; supervisors triage by combined risk, not by whichever table they happened to read.

---

#### VIEW-02 · No Edit / Renew — renewal destroys history — **High**

**Evidence.** Controller exposes `certifications()` (add only) and `delete_certification()`; there is no edit endpoint. The UI matches: Add button + per-row Delete, nothing else. Renewing a licence therefore means Delete (record **and document file** removed) then re-Add from scratch — retyping number/body/dates and re-uploading the document. `certifications` has no `updated_at`, no `verified_by`, no superseded-by link (schema), so the renewal chain leaves no trace.

**Why it matters.** Auditability is the first thing assessors look for in certification systems — who changed what, when, and what the previous credential was [2](https://www.muchskills.com/blog/employee-certification-tracking-software). Delete-and-recreate guarantees gaps (minutes where the worker shows *no* certificate), transcription errors (retyped numbers), and zero renewal history. It also trains supervisors to postpone renewals because they're tedious — the opposite of the desired behaviour.

**Recommendation.** A **Renew** action (edit-in-place with renewed dates + replacement document), preserving the prior record as history (superseded, read-only) or at minimum an `updated_at`/audit note. This needs a controller/model decision (edit endpoint + history policy) — flagged for approval, with the UI being the smaller half. Until then, label Delete honestly and keep its confirmation text (already good).

**Expected outcome.** Renewal becomes a 30-second update with an intact evidence chain; the tracker’s Renew action (CERT-05) gains a real destination.

---

#### VIEW-03 · A certificate with no expiry date is "Valid" forever — **High**

**Evidence.** In the Add dialog, only Certificate Name is required; Expiry Date is optional. `refresh_cert_status()`: `WHEN expiry_date IS NULL THEN 'Valid'`. The tracker excludes NULL-expiry rows entirely (`expiry_date IS NOT NULL`). So a supervisor can add "Forklift Operator License" with no expiry and the system will report that worker as valid indefinitely, on every surface.

**Why it matters.** This is a silent false-negative generator in a safety system — the worst failure mode for a compliance tool. Some certificates genuinely never expire, but that should be an *explicit* attestation ("Does not expire"), not the accidental result of an empty field.

**Recommendation.** Make expiry required *or* an explicit "No expiry" checkbox that must be deliberately chosen; surface no-expiry certificates in their own tracker segment so they can be reviewed. Add-dialog validation copy should say why ("Expiry is what drives alerts; leave blank only if this certificate never expires" — replaced by the checkbox).

**Expected outcome.** "Valid" always means "checked against a date or explicitly attested"; nothing lapses silently.

---

#### VIEW-04 · Certification order and mobile hierarchy bury the safety data — **High**

**Evidence.** DOM order: left column (Profile → Timesheet download → Quick Stats → Leave Balance → Leave History) precedes the right column (Certifications → Attendance). Below the `lg` breakpoint the grid stacks, so on a phone the certificate table sits after five other cards. Within the right column, Certifications does lead — good — but the left stack is long (Leave History is unbounded: `get_leave_history()` has no limit, unlike the drawer's 5).

**Why it matters.** Supervisors check profiles in the workshop, on phones, often mid-conversation ("can Joseph take this job?"). The answer they need (cert status) is the last thing rendered. Frequency-of-use ordering — identity, compliance, current workload, attendance, leave, downloads — is inverted for the compliance and availability cards.

**Recommendation.** Reorder to task frequency: Profile (with compliance summary badge) → **Certifications** → Open Work Orders → Attendance → Leave → Timesheet download last (it's a periodic admin task, not a daily one). Cap Leave History (e.g. latest 5 + link), matching the drawer. This is DOM/markup order only; the grid system already stacks full-width.

**Expected outcome.** Time-to-answer for "is this person cleared?" drops from a full-page scroll to the first screenful on mobile.

---

#### VIEW-05 · Both pages speak a retired design dialect — **High** (consistency)

**Evidence.** Both views use `wms-card`, Bootstrap utilities (`btn-outline-primary`, `bg-success/bg-warning/bg-danger`, `text-warning`, `col-md-*`), hard-coded hex (`#1a3c5e`, `#dc2626`, `#94a3b8`…) and `bi bi-*` icon classes — while `team/index`, `team/report`, and the sidebar use design tokens (`var(--red)`, `var(--t1)`…), tinted badges (`.badge b-*`), and inline SVG icons. Concretely:

- **Badges render differently for the same state.** `bg-success` maps to a *solid* green fill (header compat layer, line 732) with inherited dark text; the house `b-success` is a *tinted* fill with green text. "Valid" therefore looks different on `team/view` vs. `team/index` — same word, two visual languages, and the solid version has the weaker contrast pairing.
- **Most `bi` icons render nothing.** `layouts/header.php` defines glyphs for only ~9 `bi-*` classes; the audited pages use `bi-patch-check`, `bi-exclamation-triangle`, `bi-bar-chart`, `bi-calendar-*`, `bi-envelope`, `bi-person-badge`, `bi-phone`, `bi-plus` — none defined, so headings show gaps where icons were intended.
- **Avatars disagree.** Tracker cards: single initial, fixed navy. Profile: two initials, same navy. Directory: two initials from a per-user colour palette. One person, three identities.
- **Unescaped output (tracker).** `certifications.php` line 74 prints `$w->first_name . ' ' . $w->last_name` without `htmlspecialchars` — the profile escapes the same fields correctly. Inconsistent output discipline is how stored-XSS slips in.

**Why it matters.** Inconsistency is a usability tax (users relearn status colours per page) and a maintenance tax (three badge systems, dead icon classes). The solid-badge contrast pairing also risks failing WCAG 4.5:1 where the tinted system passes.

**Recommendation.** Migrate both views to the house primitives exactly as the suppliers/lubricants audits did: tokens not hex, `.badge b-*`, SVG icons (or remove icon slots), shared avatar component, `card-head` → house card headers. Mechanical, view-layer-only, and precedented in this repo.

**Expected outcome.** One status language across Team; icons either present or absent (never phantom gaps); contrast-safe badges everywhere.

---

#### VIEW-06 · Accessibility gaps beyond colour — **High**

**Evidence.**

- **Generic link text:** every document link reads "Open" (both pages). Out of context (screen-reader link list) it conveys nothing — WCAG 2.4.4. File type/size never shown.
- **Invisible link affordance:** worker names in the tracker are links styled `color:inherit;text-decoration:none` — indistinguishable from text until hover (which touch users never get).
- **Colour-only attendance squares:** 30 twenty-pixel `<div title="…">` blocks. `title` is unavailable to keyboard and most touch users; the squares aren't focusable, have no text alternative or `role="img"`/`aria-label` summary, and Rest/Holiday/Not-Recorded are greys/teals that collapse in grayscale. (The legend is good — but it explains colours the user may not be able to perceive per-square.)
- **Unassociated form labels:** Add-Certification `<label>` elements have no `for`, inputs no `id` — clicking a label doesn't focus its field, and screen readers can't reliably pair them (WCAG 1.3.1/3.3.2). Same pattern in the timesheet download form.
- **Data tables** lack `scope="col"`, caption, and (on the tracker) any sort semantics; the profile cert table's action column header is an empty `<th></th>`.
- **Status by colour + text is present** (badges carry words — good), but "Days Left" leans on red/amber/grey text colour for gradations of urgency at 10–12px, the sizes most at risk in bright workshop light.

**Recommendation.** Descriptive document links ("Forklift licence — PDF, 1.2 MB" or an icon+`aria-label` per certificate), visible link styling, an accessible attendance summary (text "24 present · 3 absent · 2 leave · 1 not recorded in last 30 days" — already computed for Quick Stats — plus `aria-label` on the strip), `for`/`id` pairing in both forms, `scope="col"` headers, and expiry communicated as *words + date* ("Expired 12 days ago — 03 Oct 2026") with colour as reinforcement only [5](https://www.nngroup.com/articles/visual-treatments-accessibility/).

**Expected outcome.** Both pages pass a WCAG 2.2 AA pass on the audited criteria; operable by keyboard, touch, and screen reader without loss of meaning.

---

#### VIEW-07 · Free-text certificate names fragment the data — **Medium**

**Evidence.** `cert_name` is a free-text input (placeholder "e.g. Forklift Operator License"). Nothing stops "Forklift", "Forklift Licence", and "FORKLIFT LICENSE" becoming three different certificates — defeating any grouping, filtering (CERT-04), or future requirement-mapping. The schema (`varchar(200)`) confirms no catalogue exists.

**Why it matters.** Every higher-order feature the business will ask for next — "all forklift licences expiring this quarter", "who may operate the crane" — depends on certificate *types* being consistent. Free text quietly makes that impossible while appearing to work.

**Recommendation.** Near-term (no schema change): a `<datalist>` suggesting existing distinct names as the supervisor types, plus normalisation on save. Longer-term product decision: a managed certificate-type catalogue (CERT-12). Do not hard-code a type list in the view.

**Expected outcome.** Certificate names converge; type-level filtering/reporting becomes possible without a data-cleanup project.

---

#### VIEW-08 · Dead ends and internal jargon in self-service view — **Medium**

**Evidence.** For a technician viewing their own profile: "No certifications recorded" offers no next step (they can't add one — correctly — but aren't told to see a supervisor); email/phone are plain text (no `mailto:`/`tel:`); Leave Balance ends with an implementer's footnote — *"Entitlements are configured under Settings → `leave_entitlements`"* — exposing internal configuration vocabulary to every worker; the Request Leave button lives inside Leave *History's* header rather than near Leave *Balance*, where the decision ("can I afford leave?") is made.

**Why it matters.** Self-service pages succeed when each empty/decision state names the next action and owner. Internal jargon erodes trust ("is this page for me?") and generates supervisor questions the page could have answered.

**Recommendation.** Role-aware empty states ("No certificates on file — your supervisor adds these; ask them if something's missing"), tappable contact links, move/duplicate Request Leave beside the balance, and restrict the settings footnote to supervisors (or reword: "Balances set by HR policy").

**Expected outcome.** Fewer "what do I do now?" escalations; the page reads as written for its reader.

---

#### VIEW-09 · Tracker table omits the fields auditors ask for — **Medium**

**Evidence.** Tracker columns: Worker, Employee ID, Certificate, Expiry, Days Left, Status, Document. Missing vs. the profile table: certificate **number** and **issuing body**; missing everywhere: **issued date** is captured but displayed on *neither* page. Document links give no type/size, and there's no indication a document is missing for an expired cert (the highest-risk combination: lapsed *and* unevidenced).

**Recommendation.** Add Number + Issuing Body to the tracker (they exist on the profile — this is parity), show Issued alongside Expiry on the profile ("Issued 04 Jan 2024 · Expires 03 Jan 2027"), and flag "No document" explicitly where a certificate has none.

**Expected outcome.** The tracker can serve as the audit working list without opening every profile/document.

---

#### VIEW-10 · No print or export path for the profile's compliance record — **Low**

**Evidence.** Global `@media print` hides buttons/sidebar but neither page defines a print banner or print-tuned layout (contrast `team/report`'s official banner with title/period/count/generated-at).

**Recommendation.** Print banner + hide the Add/Delete/dialog chrome when printing either page, once CERT-04's export work is approved.

**Expected outcome.** A presentable one-page compliance record for personnel files and audits.

---

#### VIEW-11 · Small consistency defects — **Low**

- Tracker heading is a bare 18px `<h1>` with a lone Back button; house pages pair title + subtitle + contextual actions (`team/index` head). Neither audited page has a subtitle explaining scope ("Active workers · alert window 30 days").
- Profile mixes `b-secondary` and `bg-*` badge classes within one badge row (line 35 vs. 33–34).
- "Request Leave" success path and leave-history status badges repeat the solid-badge issue of VIEW-05.
- Add dialog's file hint ("PDF / JPG / PNG, max 8 MB") is good; add accepted-types reinforcement is already on the input — keep both during any migration.

---

### C. Missing capabilities (product decisions, not design defects)

#### CERT-12 · Foundations that do not exist yet — **Decision required before design**

| Missing foundation | Why design can't precede it |
|---|---|
| **Required-certificates matrix** (role/position/equipment → required cert types) | Without it, "gap" can only mean "none recorded", never "missing a required licence". This is the single most valuable compliance feature in benchmarks [2](https://www.muchskills.com/blog/employee-certification-tracking-software) — and entirely a data/policy decision. |
| **Expiry notifications** (in-app attention queue / email to holder + supervisor at 90/60/30) | No delivery mechanism exists in the codebase for certificates (`Attention_model` has no cert group). UI badges without delivery = decoration. |
| **Audit trail** (`updated_at`, changed-by, renewal history) | Schema decision underpinning VIEW-02. |
| **Assignment eligibility** (warn/block when assigning a WO requiring an expired cert) | Policy decision (warn vs. block) + requires the matrix above. VIEW-01's banner is the agreed first step. |

**Recommendation.** Record these as explicit product decisions with owners. The redesign below consumes only the first (as a *display* of "none recorded" until the matrix exists) and designs *space* for the rest without shipping placeholders — per the house rule established in the suppliers audit (SUP-10: no dead controls).

---

## 5. Consolidated Recommendations & Priorities

Priority = severity × reach × (1 / effort). Phases assume approval of this audit.

| Phase | Items | Priority | Effort | Layer |
|---|---|---|---|---|
| **0 — Safety & honesty (do first)** | CERT-01 role-scope the tracker (own vs. workforce); VIEW-03 expiry-required-or-explicit-none; escape worker names (VIEW-05, 1 line) | **Critical** | S–M | Controller + view |
| **1 — Make the tracker a tracker** | CERT-02 KPI/compliance strip incl. "None recorded"; CERT-03 worker compliance table (counts, next expiry, worst-status, certs-first sort); CERT-08 explicit empty/all-valid states; VIEW-01 profile compliance banner + header summary | **Critical/High** | M | View (+ reuse `get_roster_overview()` data) |
| **2 — House-pattern parity** | CERT-04 tabs/search/chips/sort/pagination/CSV/print; CERT-06 time buckets (Expired / ≤30 / 31–90); CERT-07 single stated population; VIEW-04 task-ordered profile + open work orders + capped leave history; VIEW-05 token/badge/icon/avatar migration; VIEW-06 accessibility fixes | **High** | M–L | View only |
| **3 — Workflow completion** | VIEW-02 Renew/Edit with history (needs CERT-12 audit-trail decision); CERT-05 row actions wired to Renew/Add; CERT-09 field parity (number/body/issued, missing-document flag); VIEW-07 datalist normalisation; VIEW-08 role-aware empty states, contact links, jargon removal; VIEW-10 print banners | **High/Medium** | M | View + small controller/model |
| **4 — Product decisions (no UI yet)** | CERT-12: required-cert matrix, notification ladder + delivery, assignment eligibility policy | **Decision** | — | Product/schema |

**Explicitly not recommended:** a visual redesign language different from `team/index`/`team/report`; card-grid tracker layouts (tables scan better for compliance triage); gamified compliance scores; any control whose backend doesn't exist.

---

## 6. Expected Outcomes (measurable)

| Improvement | Outcome metric (validate post-release) |
|---|---|
| Role-scoped tracker (CERT-01) | Technicians see only own data (privacy audit passes); "my next expiry" visible without scrolling/clicking |
| Compliance strip + worker table (CERT-02/03) | Supervisor answers "who is expired / expiring / unrecorded?" from one page — target < 30 s in moderated testing (currently: N profile visits) |
| Filters/search/sort/export (CERT-04) | Any site/role/type slice in ≤ 2 interactions; audit list exportable as filtered CSV |
| Time buckets (CERT-06) | Certificates needing slow renewals identified ≥ 60 days out; fewer already-expired discoveries |
| Profile banner + workload (VIEW-01) | Expired-cert assignments spotted on the profile; zero "didn't notice" misses in task testing |
| Renew workflow (VIEW-02) | Renewal completed without record deletion; full renewal chain retrievable for audit |
| Expiry integrity (VIEW-03) | Zero certificates in "Valid" state without either an expiry date or an explicit no-expiry attestation |
| Design-system migration (VIEW-05/06) | One badge/icon language across Team; WCAG 2.2 AA checks pass (contrast, link purpose, labels, non-colour status) |
| Task-ordered profile (VIEW-04) | On mobile, cert status reachable in first screenful; timesheet download (monthly task) no longer outranks daily compliance |

**Suggested validation before build (research, lightweight):** 5-user moderated test (2 supervisors, 1 manager, 2 technicians) on the current pages with three tasks — (1) "Find everyone whose forklift licence has expired", (2) "Is [worker] cleared to work next week?", (3) (technician) "When does your next certificate expire?" — timing each, to baseline the metrics above and confirm the finding severities with observed behaviour.

---

## 7. Approval Gate

**Nothing in `application/` has been changed.** Implementation begins only after the reviewer approves (or amends) the phases in §5. Decisions needed from the product owner:

1. **CERT-01:** confirm technicians/store keepers should see *only their own* certificates on `team/certifications` (recommended), or that the page becomes supervisor/manager-only with technicians routed to their profile.
2. **VIEW-03:** expiry date required, or explicit "Does not expire" attestation (recommended)?
3. **VIEW-02 / CERT-12:** is renewal history (superseded records retained) required for audits at AML? This decides whether Renew is an edit or a versioned chain.
4. **CERT-12:** are required-certificate-per-role definitions, expiry notifications, and WO-assignment warnings in scope for a later phase, or formally out of scope?

---

## 8. Certificate Expiry Notifications — Feasibility & Specification

**Status: ✅ IMPLEMENTED** (specced 2026-10-27, implemented on request on this branch). Decisions implemented: **channel = in-app bell**, **recipients = Supervisors + Managers + the certificate holder**, **timing = tiered (90 / 30 / expired)**. Implementation is `Attention_model::_certificate_alerts()` + structural assertions in `tests/global_find_attention.js`; verified with 17 functional checks under PHP-WASM (role matrix, tier boundaries incl. expires-today, inactive exclusion, holder self-scoping, self-clearing data contract) and the existing node suites.

### 8.1 Direct answer

**Yes — in-app notification alerts are fully feasible with infrastructure that already exists, and need no new table, no cron job, and no mail server.** Email alerts are *not* currently feasible without new setup: the codebase has no SMTP/mail-sending configuration or mailer anywhere (mail references are login-throttling and form fields only).

**What exists today is passive, not pushed:** expiry is visible *if you visit* the Certifications Tracker (alert table), `team/index` (Cert Compliance KPI + Cert Alerts tab), or a worker's profile (status badges). Nothing reaches out to you. `Attention_model` — the engine behind the bell — has no certificate group, and neither Dashboard nor any other surface references certificate expiry.

### 8.2 The existing mechanism this reuses

Every page render already builds role-scoped *operational attention* groups via `MY_Controller::_operational_attention()` → `Attention_model::for_user($user_id, $role_id, …)`, rendered as the bell dropdown in the top bar (`layouts/sidebar.php`): a red count badge, grouped sections (`attention-tone-critical/warning/info/success`), up to 3 preview items per group (`Attention_model::PREVIEW_LIMIT`), each item a `label / detail / meta / url` link, plus a "View all" destination per group. Current groups: uncontrolled incidents, overdue PM, awaiting verification (supervisor only), parts newly available.

Certificate alerts slot into this as additional groups. **No header/sidebar markup or CSS changes are required** — the renderer is fully generic over groups. That is the strongest argument for this channel: the alert appears on *every* page, next to the exceptions supervisors already check, and inherits an interface users have already learned.

### 8.3 Specification (for approval when implementation is requested)

**Groups (computed from `certifications.expiry_date`, never from the stored `status`, so no write/UPDATE runs on page load):**

| Group | Condition | Tone | Rationale |
|---|---|---|---|
| Certificates expired | `expiry_date < today` | `critical` (red) | Already lapsed — worker may be non-compliant for regulated work now |
| Certificates expiring soon | `today ≤ expiry_date ≤ today + cert_alert_days` (Settings, default 30) | `warning` (amber) | The existing "Expiring Soon" window — respects the setting supervisors already control |
| Certificates expiring in 31–90 days | `cert_alert_days < expiry_date − today ≤ 90` (empty if `cert_alert_days ≥ 90`) | `info` (blue) | Early-warning tier for slow renewals (quarterly courses, approvals); display-only — does **not** change the `status` enum or tracker semantics |

**Recipients & scoping (mirrors the RBAC the rest of Team already enforces):**

| Role | Sees | Item click → | "View all" → |
|---|---|---|---|
| Supervisor (1) | All **active** workers' certs in each tier | `team/view/{worker_id}` (where Add/Renew/Delete live) | `team/certifications` |
| Manager (4) | All active workers' certs (read-only oversight) | `team/view/{worker_id}` | `team/certifications` |
| Technician (2) / Store Keeper (3) | **Only their own** certs (`certifications.user_id = current user`) | Own profile | Own profile *(until CERT-01 scopes the tracker; a holder must never land on the company-wide list)* |
| CEO (5) | None (bell is suppressed for CEO today — unchanged) | — | — |

Inactive workers are excluded from supervisor/manager groups (same population as `get_all_active()` and the directory): a lapsed licence of someone who left is not an actionable exception, and including it would train users to ignore the badge.

**Item anatomy** (following the existing incident/PM items): supervisor/manager — `label` = worker name, `detail` = certificate name, `meta` = `Expired 12d · 03 Oct 2026` / `Expires in 9d · 05 Nov 2026`; holder — `label` = certificate name, `detail` = issuing body / certificate number where present. Sorted soonest expiry first (expired: most-overdue first). Counts add into the existing bell badge (99+ cap already handled).

**Behaviour:** alerts are *state, not messages* — there is no mark-as-read and nothing to dismiss. An alert disappears on the next page load once the certificate is renewed (expiry extended), corrected, or deleted — the same self-clearing contract as Overdue PM. No `notifications` table, no read-state, no accumulation of stale alerts.

**As implemented:** one parameterised builder in `Attention_model` (`_certificate_alerts()` → `_certificate_group()` / `_certificate_query()` / `_certificate_meta()` / `_cert_alert_days()`), wired into `for_user()` with the role matrix above; guarded by `table_exists('certifications'/'users')`; `Settings_model::get('cert_alert_days', 30)` (clamped 1–365) for the middle tier; one small query per tier following the existing `PREVIEW_LIMIT + 1` count pattern. **Files touched: `application/models/Attention_model.php` + `tests/global_find_attention.js`** (+ this doc). No schema, route, view, or settings changes.

**Acceptance criteria:**

- [x] Bell badge count includes certificate tiers; groups render with correct tones and counts
- [x] Supervisor and Manager see all active workers; Technician/Store Keeper see only their own (verified per role)
- [x] Expired / ≤ `cert_alert_days` / 31–90-day boundaries correct, incl. a certificate expiring *today* (soon, not expired)
- [x] Certificates with no expiry date never alert (see VIEW-03: they also should not silently read "Valid" — separate fix)
- [x] Renewing (extending expiry) or deleting a certificate clears its alert on next load; no dismiss control exists *(by construction — groups are computed from current `expiry_date` on every render, nothing is stored)*
- [x] Holder "View all"/item links never expose another worker's record (CERT-01 dependency respected)
- [x] No `UPDATE`/status-refresh executed during attention computation (asserted in tests: no `refresh_cert_status` reference); one query per tier

### 8.4 Honest limitations of the chosen channel

In-app-only means **nobody is alerted while logged out** — a certificate can lapse over a holiday and the system will be silent until someone next signs in. There is no escalation, digest, or delivery log. If that risk becomes unacceptable, the follow-on is email (holder + supervisor at the same tiers), which requires: SMTP/host configuration that does not exist today, a scheduled sender (cron/CLI), a sent-log to prevent duplicate reminders, and delivery testing. WhatsApp/SMS would additionally need a paid gateway account. Neither should be designed as UI until that infrastructure is a deliberate product decision (CERT-12).

---

## Appendix A — Evidence index

| Claim | Source |
|---|---|
| Tracker lists only expiring/expired; no valid/none views | `Team::certifications()` → `get_expiring_certs()`; `certifications.php` |
| Tracker worker cards carry no cert data | `certifications.php` lines 62–86 |
| Tracker GET has no role scoping; sidebar promises "My Certifications" | `Team::certifications()`; `layouts/sidebar.php` |
| Profile RBAC scopes to self | `Team::view()` |
| No edit/renew endpoint; delete removes file | `Team::delete_certification()`, `delete_certification` in model; schema (no `updated_at`) |
| NULL expiry ⇒ "Valid", excluded from tracker | `Team_model::refresh_cert_status()`, `get_expiring_certs()` |
| Directory already computes per-worker cert counts + drawer detail | `Team_model::get_roster_overview()`, `Team::member_json()`, `team/index.php` |
| Solid vs. tinted badge definitions; ~9 `bi-*` glyphs defined | `layouts/header.php` lines 542–556, 732–741 |
| Unescaped worker name (tracker) vs. escaped (profile) | `certifications.php` line 74; `view.php` line 30 |
| No cert-expiry notification/attention integration | grep: `Attention_model`, `Dashboard*` — no certificate references |
| No required-certificate concept anywhere | grep: `required.*cert` — no matches in `application/`, schema |

## Appendix B — Files referenced, none modified

`application/views/team/certifications.php` · `application/views/team/view.php` · `application/views/team/index.php` · `application/views/team/report.php` · `application/controllers/Team.php` · `application/models/Team_model.php` · `application/views/layouts/header.php` · `application/views/layouts/sidebar.php` · `application/core/MY_Controller.php` · `database/schema.sql`
