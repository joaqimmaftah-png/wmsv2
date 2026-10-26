# Workshop Daily Report — UX / Product Design Review

**Page under review:** `/reports/workshop_daily` — `application/views/reports/workshop_daily.php` (279 lines), `Reports::workshop_daily()` + `Reports::_build_workshop_daily()` (`application/controllers/Reports.php:1758–1905`), plus its two export paths (`_export_workshop_xlsx`, `_export_workshop_pdf`).

**Status:** review only. **No file was modified, no migration, no data change.** Everything below is a recommendation awaiting your approval.

**Evidence boundary.** Source inspection, controller/model/helper reading, seed-data counting, WCAG contrast computation (Node, values in Appendix A) and the existing dependency-free regression suite. This workspace has **no PHP executable, no MySQL service and no authenticated browser session** (the same limitation recorded in `docs/table-ux-audit.md` and `docs/service-kits-report-audit.md`). Therefore: arithmetic and query semantics are read from source, cross-checked against `SYSTEM.md`; **no runtime, print, screen-reader or real-data verification is claimed**. "Defect" below means "provably divergent from the codebase's own stated rule or from its sibling report", not "measured in production".

---

## 1. What this page is for (established from code and navigation, not assumed)

| Question | Answer supported by the source |
|---|---|
| Job-to-be-done | A **dated, grouped-by-machine-type readiness sheet** for one calendar day: what is up, what is down, why, and what is being done. It is the document that feeds the daily workshop meeting and is printed/signed for the file. |
| Users | Supervisor (role 1) and Manager (role 4). `MY_Controller` gates the whole `reports` module to `array(1, 4)` (`application/core/MY_Controller.php:166`); the sidebar shows Reports only to those roles. |
| Primary reading task | 1) *Is the fleet up?* 2) *Which machines are down and for how long?* 3) *What is being done and when will it be back?* 4) *Are we above target?* |
| Record | One machine (`registration_no`), grouped under equipment type. |
| Population | 116 machines (none Retired in the seed) across 7 types, of which **24 are ADT**; 19 are seeded as `Breakdown` (`database/schema.sql:2549–2700`). This number drives the density and grouping findings, and the 19 gives you a staging check for F-01. |
| Period | One date, `?date=YYYY-MM-DD`, max = today. |
| Outputs | Screen, "Excel" (18-column XLSX), "PDF" (standalone HTML print view), and browser Print. Four renderings of one report. |
| Data authority | `Kpi_engine_model` → `kpi_helper`: `availability = (required − downtime) ÷ required`, required = open hours (10 h/day) minus preventive labour, downtime = **union** of breakdown intervals. This is the approved definition and it is used correctly here. `SYSTEM.md:1408`. |

**What is genuinely good, and should survive any redesign:**

1. **One arithmetic authority.** The page does not compute availability itself; it asks `Kpi_engine_model->fleet($date, $date)` and uses `rollup()` for the type figure. The type KPI is required-weighted, not an average of percentages (`Reports.php:1894–1899`). This is the single hardest thing to get right in a multi-machine availability report and it is already right.
2. **Group-then-detail structure.** Machine type is a real dimension of this workshop (7 types, one of them dominating), and grouping by type is more useful than a flat A–Z fleet list.
3. **Period is a URL parameter**, the exports carry the same period, and the page is GET-only — no hidden state, no write actions on a reading surface. This matches the pattern the `/reports` hub was deliberately rebuilt around (`tests/reports_hub.js`).
4. **The date is validated server-side**, not just by the input's `max` (`_resolve_report_date`, `Reports.php:1735–1752`).
5. **The per-type daily KPI is already computed per machine and per group** and the machine target is already resolved (`Reports.php:1861, 1879, 1901`). The page is sitting on top of the comparison data a supervisor needs.

---

## 2. Executive summary

This is a **well-computed report presented as a raw data dump**. The engine underneath it is defensible; the surface above it is not yet a decision document. Four problems are severe enough that I would not put this page in front of a plant manager in its current state:

1. **The Status/observation columns describe *now*, not the date you selected.** The report is dated, navigable to any past day, and printable — but its "Down/Available" badge and its observation text come from work orders whose *current* status is open (`Reports.php:1794–1796, 1822`). Open any past date and the page will show a machine as "Available" on the same row where the KPI engine says 43% availability, because the machine has since been repaired.
2. **"Availability" means two different things on this one page, and a third thing in the PDF.** The KPI card labelled *Overall Availability* is a machine-count ratio (`workshop_daily.php:61, 86`); the type KPI is the required-weighted time-based figure; the PDF header labelled *Overall Availability* prints the time-based fleet figure (`Reports.php:1928–1930, 1990`). Same words, different numbers, two surfaces, one of them printed.
3. **Colour coding ignores the agreed target, and the three outputs of one report disagree.** The screen hard-codes green ≥ 90 %, amber ≥ 70 % (`workshop_daily.php:103, 184, 208, 225`); the daily XLSX does the same (`Reports.php:2159`); the daily **PDF** colours by the machine's target (`Reports.php:2032`). The site's approved target is 88 % (`Kpi_engine_model::TARGET_FALLBACK`), and the sibling weekly report shows target and variance explicitly. So a machine at 89 % is green on screen, amber in the PDF, and unmarked against a target the screen never displays.
4. **Unknown is rendered as good news.** If the engine has no row for a machine it is assigned `availability = 1` → 100 %, green (`Reports.php:1860`); if a type has no measurable data its KPI becomes `0` → 0 %, red (`Reports.php:1899`). Both are inventions. The hub page explicitly refuses to do this (`reports/index.php:940–946` prints "—" and "Not available"), so the codebase already knows the rule this page breaks.

The rest of the register is about **density, findability, and trust scaffolding**: 116 rows with no filter or sort, group labels that scroll away after the first row, an 18-column table that is wider than its own declared minimum, charts placed *after* the 116 rows, clock times labelled as durations, and a printed sheet with no as-of time, no target, no definition and no sign-off line.

**The design direction I recommend is not a redesign.** It is bringing this page up to three rules the project has already established and tested elsewhere: *target-aware status* (weekly report, hub), *honest unavailable states* (hub), and *offline-safe, accessible presentation* (hub's server-rendered SVG + hidden data table). Every recommendation below reuses data the controller already computes.

---

## 3. Deliverable 1 — Findings

### 3.1 What works

| # | Works | Why it matters |
|---|---|---|
| W-01 | Single arithmetic authority (`Kpi_engine_model` → `kpi_helper`) | Any two screens that disagree destroy report trust faster than any visual defect. This is the platform's biggest asset. |
| W-02 | Required-weighted type rollup | A 24-machine ADT group and a 3-machine crane group cannot be averaged as percentages. `kpi_rollup()` prevents that. |
| W-03 | Type grouping reflects the real fleet shape | 7 types, one dominant; grouping matches how the workshop organises crews and spares. |
| W-04 | Date in URL, exports carry the period, no write actions | Shareable, bookmarkable, refresh-safe, and consistent with `/reports`. |
| W-05 | Server-side date validation with a real 400 | Prevents silent "yesterday" reports from malformed links. |
| W-06 | Group summary strip already shows `available / down / total` per type | The right idea — an at-a-glance layer above the detail table. It just needs a target and honest empty states. |
| W-07 | Per-machine target is already resolved and passed to the view (`Reports.php:1879`) | The comparison the supervisor actually makes is one line of rendering away. |

### 3.2 What fails

Severity: **C**ritical / **H**igh / **M**edium / **L**ow. Each finding states evidence, consequence, and why it matters.

| ID | Pri | Finding | Evidence | Consequence |
|---|---|---|---|---|
| **F-01** | **C** | **A dated report whose status describes "now".** The work-order query is `DATE(created_at) = :date OR (status NOT IN (Completed, Closed) AND start_datetime <= :date)`, i.e. the second branch is evaluated against *today's* status. `isDown` is then derived from that row's current status. | `Reports.php:1790–1800`, `1822–1826` | For any date in the past: repaired machines show **"Available"** while the same row prints 43 % availability and 6 h downtime; observation/action columns go blank because the row no longer matches. The page is silently optimistic about history — the one thing a signed daily sheet is used for. |
| **F-02** | **C** | **"Availability" carries two different meanings on one screen, and a third in print.** Card 4 = `count(up) ÷ count(total)` (`:61`), labelled *Overall Availability*, chipped Good/Fair/Critical at 80/60. Type KPI = required-weighted time. PDF header = engine time-based fleet figure, also labelled *Overall Availability*. | `workshop_daily.php:61, 86–89`; `Reports.php:1928–1930, 1990` | Two numbers, two definitions, same label, one of them printed and signed. A supervisor reconciling screen vs PDF concludes the system is unreliable. |
| **F-03** | **C** | **Colour coding ignores the agreed target, and the three renderings of this one report disagree.** Screen: fixed green ≥ 90 %, amber ≥ 70 % (`:103, 184, 208, 225`). Daily XLSX: fixed 0.9/0.7 (`Reports.php:2159`). Daily PDF: **target-aware**, `avail ≥ machine_target → good` (`Reports.php:2032`). Approved target: 88 %. | `workshop_daily.php:184`; `Reports.php:2032, 2159` | The same machine is green on screen, amber in the PDF, and nothing anywhere says *why*. Colour without a legend or a target is decoration pretending to be information — and it violates WCAG 1.4.1 because "meets target" is conveyed **only** by hue. |
| **F-04** | **C** | **Unknown ≠ zero is not honoured.** No engine row → `availability = 1` (100 %, green). Group rollup `null` → `daily_kpi = 0` (0 %, red). `$totalEq` includes machines with no measurable basis. | `Reports.php:1860, 1899`; `workshop_daily.php:86–89, 103, 208` | A machine with no required-time basis is counted in *Fleet*, *Available*, and the headline percentage as if it were up; a type with no data is reported as a red 0 % emergency. Both are fabricated statuses on an accountability document. |
| **F-05** | **H** | **"Down" counts planned work.** `isDown` is true for any WO with status Open / In Progress / Waiting Parts / Waiting Tools, with no breakdown filter. The engine, on the same row, counts only breakdown intervals. | `Reports.php:1822–1826` vs `kpi_helper` breakdown union | A PM job in progress marks a healthy machine "Down". The count-based readiness and the time-based availability on one row are computed from different populations, so they can never be reconciled by the reader. |
| **F-06** | **H** | **No target and no variance anywhere on screen**, although both are already available. `target` is passed per machine (`:1879`) and per group (`:1901`) and never rendered; `kpi_analysis()` (variance in pp) exists and is unused by this page. The weekly report has both columns. | `workshop_daily.php` (no occurrence of `target`); `Reports.php:1879, 1901`; `kpi_helper.php:392–398`; `workshop_weekly.php:181–182` | The user is asked to judge "is 87 % good?" with no reference. Research is unambiguous that a KPI without its target forces the reader to hold the benchmark in memory, and that shows a gap as a *length* rather than as arithmetic ([KPI card anatomy: actual/target/trend/variance/threshold](https://fuselabcreative.com/kpis-and-data-dashboard-design/)). |
| **F-07** | **H** | **Report scope does not honour the documented reporting population.** `SYSTEM.md:47` defines a reportable machine as `include_in_weekly_report = 1` and not Retired, "the population of the weekly report and forecast"; `W36_report_model` enforces it (`:444`). Both workshop reports filter on `status != 'Retired'` only. | `Reports.php:1785`, `1228`; `SYSTEM.md:47`; `W36_report_model.php:444` | Fleet totals and readiness on this page can differ from the signed weekly report and the forecast for the same day, with no visible explanation. Any machine a planner deliberately excluded still counts here. |
| **F-08** | **H** | **116 rows, zero findability.** No search, no status filter, no "only down", no sort, no "jump to type". Sort is hard-coded `type, registration_no`. | `Reports.php:1801`; `workshop_daily.php:125–199` | The primary daily question — *"which machines are down?"* — requires scanning 116 rows. The `/reports` hub already tells the user the count; the report they click into cannot answer "which". |
| **F-09** | **H** | **Group identity disappears immediately.** The type label, `# EQ`, `AVAIL`, `DOWN` and the type's *Daily KPI* are rendered only on the **first row of each group** (`$first` guards) in ordinary cells with no `scope="rowgroup"` and no sticky treatment. | `workshop_daily.php:150–156, 186–189` | In the 24-row ADT group, after one screenful the reader no longer knows the type, the group size, or the group KPI. The most important comparison (group vs group) is visually attached to one arbitrary machine. |
| **F-10** | **H** | **Table density and width are structurally wrong for a report.** Cells inherit the shared `.tbl td { padding:13px 24px }` (`header.php:532`) — 18 columns × 48 px ≈ **864 px of pure horizontal padding**; declared fixed widths already total **1 300 px** while the wrapper claims `min-width:1200px`. Horizontal scrolling then removes TYPE + MACHINE ID from view. | `workshop_daily.php:125, 127–146`; `header.php:526–541`; arithmetic in Appendix A | You must scroll right to read why a machine is down and left to know which machine it is. NN/g's guidance for tables wider than the viewport is explicit: freeze the header row **and** the left identifier column ([Mobile tables](https://www.nngroup.com/articles/mobile-tables/), [Data tables](https://www.nngroup.com/articles/data-tables/)). |
| **F-11** | **H** | **The glanceable layer is below the unglanceable one.** The seven per-type bar charts are rendered *after* the 116-row table. | `workshop_daily.php:203–260` | On a single day with ~7 types, the charts are the highest-value element on the page and no one sees them. This inverts "overview first, zoom and filter, details on demand". |
| **F-12** | **H** | **Print is not one document.** Browser Print renders the on-screen 1 300 px table with `overflow-x:auto` and no `@page` size (portrait A4), plus Chart.js canvases; the "PDF" button renders a *different*, hand-built HTML document with `@page A4 landscape`, its own palette, its own colour rules and inline CSS bars. | `workshop_daily.php:263–273`; `Reports.php:1435` (the one scoped `@page`), `1946–2060` | Two buttons labelled as outputs of one report produce two different documents. Print from screen will clip columns 8–18; the PDF keeps them but is a separate visual language. Users learn not to trust either. |
| **F-13** | **H** | **Accessibility: measured contrast failures and colour-only meaning.** `--t3 #86868b` = **3.62:1** on white and `--t4 #aeaeb2` = **2.21:1** — both used for 11–11.5 px data text (LOCATION, DOWN TIME, UP TIME, START DATE, E/T, blank cells). `--green #1c8c3c` = **4.32:1** at 11.5 px. The date field has no `<label>`. Tables have no `<caption>`; section titles are `<div class="ct">`, not headings. | `workshop_daily.php:33, 90–98, 165–183`; `header.php:31–34, 40`; Appendix A | Fails WCAG 2.2 AA 1.4.3 (normal text needs 4.5:1) and 1.4.1 (status conveyed by hue alone). The shop-floor reality — a 55-year-old supervisor reading a 11.5 px grey date on a bright monitor — is exactly the case the standard exists for. |
| **F-14** | **M** | **Labels that misstate the data.** "DOWN TIME" is `date('H:i', start_datetime)` — a *clock time*, not a duration; "UP TIME" is the end clock time, blank for still-down machines. "E/T", "TOTAL DT", "DAYS ↓", "# EQ", "AVAIL" are unexplained abbreviations, and "AVAIL" means a **count** in the group block (`:152`) and a **percentage** two columns later (`:183`). | `workshop_daily.php:127–146, 152, 177–179, 183`; `Reports.php:1845–1852` | "DOWN TIME 08:30" reads as 8.5 hours to a supervisor and as 08:30 to an engineer. Two columns named AVAIL on one row is a guaranteed misread in a meeting. |
| **F-15** | **M** | **Multiple interventions per day are collapsed to the latest one.** WOs are indexed `if (!isset($wo_map[$id]))` over `created_at DESC`, so only the newest WO per machine survives. The weekly report keeps all of them. | `Reports.php:1805–1810` vs `1272–1283` | A machine with two separate failures today shows one observation. The *daily* report is less informative than the weekly about the same day. |
| **F-16** | **M** | **Status vocabulary drifts between sibling reports.** Daily: `Down` / `Available` badges. Weekly, same machines, same source logic: `O` / `BD` / `WSP` / `UM` with a legend. | `workshop_daily.php:158–163`; `Reports.php:1285–1298`; `workshop_weekly.php:99–118` | The same machine on the same day reads "Down" here and "WSP" there. The weekly legend even defines "Waiting Sparepart" — a distinction the daily report throws away, although *waiting on parts* is the single most actionable state in a workshop. |
| **F-17** | **M** | **Invented trend affordance.** The Available card prints a green `↑ 96 %` chip with no comparison period; the `↑` encodes improvement that was never measured. | `workshop_daily.php:73` | Decoration in the exact place where a real trend would be valuable (`Kpi_engine_model::daily_fleet()` already exists and powers the hub's chart). |
| **F-18** | **M** | **No provenance block.** Nothing states the availability definition or open hours, the data cut-off time, who generated it, or a sign-off line. The PDF prints a company name and a date only. Other printable reports in this codebase do carry a sign-off line (`Reports.php:3053`). | `Reports.php:1956–1968` | A daily sheet reviewed in a meeting and filed cannot be defended: 88 % of *what*, as of *when*, produced by *whom*. The hub page shows exactly the missing vocabulary ("10 h/day · 60 h/week", "Figures read … server time"). |
| **F-19** | **M** | **Information is repeated four times and ordered worst-first.** Fleet strip → by-type strip → per-row AVAIL% → per-type charts → in-table DAILY KPI column all restate availability. | `workshop_daily.php:56–114, 183–189, 203–260` | Redundancy costs vertical space without adding a decision; meanwhile the one thing missing (target) appears nowhere. |
| **F-20** | **M** | **Long text is unbounded.** Observation/action cells cap width at 200/130 px but wrap indefinitely with `word-break:break-word`; a 400-character symptom narrative produces a very tall row that destroys the scan rhythm of a 116-row table. | `workshop_daily.php:165–166` | One verbose entry can dominate a printed page and push the rest of the fleet off it. |
| **F-21** | **M** | **The report is a dead end.** Machine IDs are not links; WOs are not referenced by number. Other reports in this app already link machine ID → `equipment/view/{id}` (service kits audit). | `workshop_daily.php:160` | To act on a row the user must leave, re-search the registration number, and find the work order. Every "why is this still down?" question costs a context switch. |
| **F-22** | **M** | **Empty states conflate three different situations.** The only empty state is "No equipment data found" when `$report` is empty. A fully healthy day, a day with no interventions, and a data failure all look identical: a table of "Available" rows. | `workshop_daily.php:147–149` | "Nothing happened" and "nothing was recorded" must not look the same on an accountability document. The hub distinguishes these explicitly (`reports/index.php:940–946`). |
| **F-23** | **L** | Heading uses the legacy `page-heading` class at 16 px instead of `.main-page-title` (20 px); no `.main-page-head` wrapper; sections are `<div>`s, so the page has exactly one heading level. | `workshop_daily.php:28`; `tests/page_titles.js:73` (only guards `<div class="page-heading">`) | Screen-reader heading navigation yields a single item for an 8-section page. Also the only report page that stylistically doesn't match the hub it was opened from. |
| **F-24** | **L** | No previous/next day, no "Today" shortcut; `Load` requires a click for what is usually "yesterday". | `workshop_daily.php:31–35` | The single most common navigation act in a daily report — step back one day — is the least supported. |
| **F-25** | **L** | Double rounding: `rollup … round(...,2)` then `×100` in the view (`:188`), and `round($m['availability']*100)` at `:184`. | `Reports.php:1899`; `workshop_daily.php:184, 188` | A machine at 87.5 % prints "88 %" — i.e. "at target" — while the colour rule compares the unrounded 0.875. Display and status disagree at exactly the boundary that matters. |
| **F-26** | **L** | 7 Chart.js instances loaded from `cdn.jsdelivr.net` (`header.php:10`), while `/reports` was deliberately rebuilt to ship **no** canvas and no CDN chart library, with a regression test enforcing it. | `header.php:10`; `workshop_daily.php:227`; `tests/reports_hub.js:39–44` | On a plant network without CDN egress the page shows seven blank panels. The codebase already contains the offline-safe pattern (server SVG + visually-hidden data table). |
| **F-27** | **L** | Sidebar active state uses a prefix match plus a hand-maintained exclusion list that is missing `annual_report`, `annual_plan`, `service_kits`, `materials_movement`, `equipment_history`, `weekly_targets`. | `sidebar.php:5–8, 217` | Two nav items appear active at once on those pages; on this page it happens to be correct, which is why the bug persists. |
| **F-28** | **L** | Numbers are centred rather than right-aligned; no zebra striping or `position:sticky` header on the long table. | `workshop_daily.php:127–199` | Right-aligned numerals let magnitudes be compared by scanning one edge; centred numerals force re-parsing each cell. |

### 3.3 What is missing (capability gaps, not defects)

| # | Missing | Why the report needs it |
|---|---|---|
| M-01 | **Comparison layer**: target per machine and per type, variance in percentage points, and a "vs target" reading. Data already in the controller; `kpi_analysis()` already exists. |
| M-02 | **A "what changed / what needs action" layer**: machines down > N days, waiting on parts, no action recorded, no WO linked. The daily sheet's job is to drive the morning meeting; today it lists facts without prioritising them. |
| M-03 | **Findability controls** appropriate to 116 rows: quick segments (*All / Down / Waiting parts / No action recorded*), text search over machine ID / location / model, and a type jump. |
| M-04 | **Definition and provenance**: availability formula, open hours, downtime union rule, as-of timestamp, generated-by, sign-off block. |
| M-05 | **Drill-through** to `equipment/view/{id}` and to the work order. |
| M-06 | **Honest state model**: `measured / no basis / data unavailable` as three distinct presentations, instead of 100 %, 0 % and red. |
| M-07 | **One canonical print artefact** (the PDF endpoint) that the on-screen Print button defers to, so there is exactly one printed layout. |
| M-08 | **Day-over-day context**: the same fleet's figure yesterday and the 7-day trend, which the engine already produces (`daily_fleet()`). A single day's availability is nearly meaningless alone — 10 open hours means one breakdown moves a machine by 10 pp. |


### 3.4 Adjacent finding that blocks a clean fix (outside this page, reported for sequencing)

**A-01 · High · The weekly XLSX footer averages percentages.** `_export_workshop_weekly_xlsx` sums each group's `weekly_kpi` and divides by the number of groups (`Reports.php:1703–1712`) to produce the "TOTAL FLEET" KPI cell. That is precisely the operation the daily builder's own comment forbids — *"required-weighted, never an average of percentages (the same rule as the weekly sheet)"* (`Reports.php:1883–1886`) — so the weekly workbook's fleet figure can differ from the engine's fleet figure and from the PDF. It does not affect this page's rendering, but it blocks the "same number on screen, XLSX and PDF" acceptance test in §9, and it is a two-line fix (`$this->Kpi_engine_model->fleet(...)`) if you want it in the same release.

---

## 4. Deliverable 2 — Recommendations

Ordered by finding. Each recommendation states **what**, **why** (principle or evidence), and **how**, deliberately reusing what the codebase already has. No new tables, no new endpoints unless stated.

### R-01 — Make the dated report describe the selected date *(fixes F-01)*
**What.** Derive down-state and observations from work orders that **overlap** the selected day — `start_datetime < day_end AND (end_datetime IS NULL OR end_datetime >= day_start)` — and label the resulting state in the past tense ("Was down · restored 14:20 on 12 Mar") rather than the present tense ("Down").
**Why.** A dated document that answers a present-tense question is the most expensive kind of wrong: it is wrong in the optimistic direction, on the artefact that gets signed. The downtime figure already comes from interval union, so the fix aligns the row with the number beside it.
**How.** Replace the current-status branch in the WO query (`Reports.php:1790–1800`) with interval overlap; keep a separate "current state" flag if the live view is still wanted, and *label it as such*. Show `Down (open)`, `Restored 14:20`, `No intervention recorded`.
**Outcome.** Screen, history and PDF agree. The supervisor can trust yesterday's sheet and reconcile yesterday's KPI.

### R-02 — One availability, one name, two clearly distinguished measures *(fixes F-02, F-17)*
**What.** Rename and separate:
- **Fleet availability (time-based, KPI engine)** — the signed number, same as the PDF and the weekly report.
- **Machines up today (count)** — `112 of 116`, explicitly a count, explicitly not a KPI.
Never print the words "Overall Availability" in front of a count ratio. Remove the `↑` chip; replace it with the real delta vs target in pp, or with a 7-day trend if you want a trend.
**Why.** Consistency of a label across surfaces is not cosmetic: it is the contract that lets a manager trust the printed sheet after looking at the screen. The weekly report already gets this right by saying "Fleet Readiness (machines up) · machines up ÷ total" (`workshop_weekly.php:76, 95–100`).
**Outcome.** One number called availability, one called readiness, both with their populations named. Removes the only defect on this page that can cause a wrong decision in a management meeting.

### R-03 — Colour by target, and print the target *(fixes F-03, F-06, partially F-19)*
**What.** Three-state banding driven by the machine's own target, computed **once** in the controller/view: `≥ target` → neutral/green; `target − 10 … target` → amber "watch"; `< target − 10` → red. Show `Target 88 %` and `−3 pp` beside the value. Add a legend. Apply the identical rule in screen, XLSX and PDF, and carry the target in both exports.
**Why.** The band `target − 10` is already the house rule on the hub (`reports/index.php:92–93`); the weekly report already prints TARGET and ANALYSIS columns; the daily PDF already colours by target. The screen is the outlier, not the standard. Also: target-free colour is information conveyed by hue alone (WCAG 1.4.1) and, per [KPI dashboard guidance](https://www.domo.com/learn/article/kpi-dashboards), a threshold only means something relative to an agreed target.
**How.** One small helper used by all three renderers (e.g. `kpi_tone($value, $target)` returning `ok|watch|crit|unknown`), consumed by the view, the XLSX and the PDF. Data is already available: `$m['target']`, `$g['target']` (`Reports.php:1879, 1901`).
**Outcome.** Colour becomes defensible information. The most asked question in the meeting — "are we above target?" — is answered on the page instead of inferred from a hue.

### R-04 — Model "no data" explicitly *(fixes F-04, F-22)*
**What.** Replace the `availability = 1` fallback with `null` → render `—` with the text `no basis`; exclude no-basis machines from the availability rollup *and* from the "Available" count, and disclose the coverage (`108 of 116 machines measured`). For a type with no measurable data, show `—`, not `0 %`. Add a distinct all-clear state ("All 116 machines available · no interventions recorded today") that is visually different from empty/unavailable.
**Why.** The hub already implements precisely this rule and a regression test enforces it (`reports_hub.js`: *"unavailable KPIs show a dash, not a zero"*). Manufacturing a green 100 % for missing data is worse than showing nothing: it hides a data-quality failure behind a good number.
**Outcome.** Data-quality problems become visible and actionable instead of invisible and lucky.

### R-05 — Define "Down" as a maintenance state, not as "any open work order" *(fixes F-05)*
**What.** Count a machine as down when it has an overlapping **breakdown** (or a hold state such as waiting parts), using the existing `wo_is_breakdown()` / `machine_status` fields; exclude planned PM labour. Then present both measures with their populations: `Machines down: 4` and `Downtime today: 26.5 h (union of 4 breakdowns)`.
**Why.** Two numbers on one row must be traceable to one population, or the reader stops reconciling them. The engine already excludes planned work from the basis; the badge must follow the same rule.
**Outcome.** The readiness count and the downtime figure stop contradicting each other.

### R-06 — Honour the documented reporting population *(fixes F-07)*
**What.** Filter equipment through `equip_is_reportable()` (already used by the W36 model and the forecast), and state the scope on the page and in both exports: "116 reportable machines (excluded: 3 not in weekly reporting, 2 retired)". Add the flag to the select list.
**Why.** `SYSTEM.md:47` defines this population in writing; two reports reading the same fleet must not silently disagree. A visible scope line also answers the "why is this machine not on my sheet?" question before it's asked.
**Outcome.** Daily, weekly, W36 and forecast become reconcilable by construction.
**Dependency:** requires your confirmation that the flag is meant to apply to the daily sheet too (see §8, Q-01).

### R-07 — Give the page a filter bar and put the exceptions first *(fixes F-08, F-11, F-19)*
**What.** Above the table: (a) type segments with counts, (b) status segments **All / Down / Waiting parts / No action recorded**, (c) text search over machine ID, model, location, (d) `Down only` toggle. Default order: **down machines first inside each type, then longest days-down**. Move the per-type charts above the table, next to the summary strip.
**Why.** NN/g's data-table research puts *find a record* and *compare* ahead of *read everything*, and is explicit that filtering/sorting must be easy and that the identifier column must be findable ([Data tables](https://www.nngroup.com/articles/data-tables/)). Screening 116 rows to find 4 machines is work the computer should do.
**Outcome.** "Which machines are down and why" goes from a 116-row scan to a single click — the core daily task, solved in one second.

### R-08 — Fix group identity: a real group header row *(fixes F-09)*
**What.** Replace the "first row carries the group" pattern with an explicit, full-width group header row per type — `ADT · 24 machines · 21 up · 3 down · KPI 84 % (target 88 %, −4 pp)` — plus `scope="rowgroup"` on the type cell and a sticky header row. Keep the machine rows plain.
**Why.** A group attribute belongs on the group, not on an arbitrary member. With 24 ADT rows this is the difference between a table that can be read at row 40 and one that cannot.
**Outcome.** The reader never loses the type, the group size or the group KPI while scrolling; the comparison layer becomes structural instead of a one-off cell.

### R-09 — A report-density table variant *(fixes F-10, F-28)*
**What.** Add a `.tbl-report` variant: 8–10 px vertical / 12–14 px horizontal padding, 11.5–12 px numerals, right-aligned numeric columns, sticky first column (TYPE) and sticky header, zebra rows, and an explicit `<caption>`. Keep the comfortable `.tbl` for record pages.
**Why.** The shared 24 px horizontal padding (48 px per cell) is a *record-page* rhythm; across 18 report columns it consumes ~72 % of a 1 200 px row before any data. NN/g recommends freezing the identifier column on horizontally scrolling tables, and right-aligning numerals is a decades-old typographic convention for magnitude comparison ([Mobile tables](https://www.nngroup.com/articles/mobile-tables/), [alignment practice](https://uxbooth.com/articles/designing-user-friendly-data-tables/)).
**Outcome.** Roughly +35 % more rows visible per screen and no more left/right scroll hunting to answer one question.

### R-10 — Make screen Print defer to the PDF artefact *(fixes F-12, M-07)*
**What.** One canonical printed layout (the `_export_workshop_pdf` document, already `@page A4 landscape`), opened by **both** the "PDF" and "Print" buttons. Delete the page-local `@media print` block that tries to print the 1 300 px interactive table. If a screen print must remain, give the page its own `@page { size: A4 landscape }` and hide the canvas charts.
**Why.** Two entry points to "the same document" must produce the same document, or neither is trusted. The dedicated print endpoint already exists and is better suited to paper.
**Outcome.** What the manager signs is what everyone else sees. No more clipped columns on A4 portrait.

### R-11 — Accessibility pass *(fixes F-13, F-23)*
**What.** (a) `--t3`/`--t4` may not carry data text: use `--t2 #6e6e73` (5.07:1) for secondary data and `--t1` for values; (b) never use `--green` at ≤12 px — darken the text token used for green figures (or pair the number with a word); (c) add a real `<label for>` and a "Today" shortcut to the date control; (d) give the table a `<caption>`, `scope="col"`/`scope="rowgroup"`, and section `<h2>`s; (e) carry status with a word as well as a colour (`▼ 3 pp below target`); (f) heading → `.main-page-title` + `.main-page-head`.
**Why.** 3.62:1 and 2.21:1 fail WCAG 2.2 AA 1.4.3 outright; hue-only thresholds fail 1.4.1. These are measured values (Appendix A), not taste.
**Outcome.** Readable on a bright workshop monitor, usable with a screen reader, and compliant without a visual redesign.

### R-12 — Say what the columns actually contain *(fixes F-14)*
**What.** Rename: `DOWN TIME` → `DOWN SINCE`, `UP TIME` → `RESTORED AT`, `TOTAL DT` → `DOWNTIME (h)`, `E/T` → `EST. REPAIR (h)`, `# EQ` → `MACHINES`, `DAYS ↓` → `DAYS DOWN`, and the count column `AVAIL` → `UP`. Put units in the header once, not in every cell. Keep an abbreviation only where a tooltip/full name exists.
**Why.** Header text carries the entire interpretive burden of a table; a header that names the wrong thing (a clock time called a duration) cannot be rescued by a good design. Also required for the export consumers to read the same document.
**Outcome.** No reader misreads 08:30 as 8.5 hours; no reader confuses an up-count with a percentage.
**Dependency:** export column headings are consumed externally in this project (see `table-ux-audit.md` F-08 precedent) — rename on screen first, then agree the export headings with consumers.

### R-13 — Keep every intervention of the day *(fixes F-15, F-16)*
**What.** Show all overlapping WOs for the day as a short numbered list (the weekly report already does this: observation / action / WO date), capped at 3 with "+2 more". Adopt the weekly status vocabulary (`O / BD / WSP / UM` + legend) so one machine cannot read "Down" here and "WSP" there — and keep "Waiting parts" visible, because it is the most actionable state in a workshop.
**Why.** Cross-report consistency is the cheapest trust win available in this codebase: the sibling report already has the correct model. Losing the "waiting parts" distinction on the *daily* page specifically hides the thing the meeting should act on.
**Outcome.** The daily sheet becomes at least as informative as the weekly about the same day, and the two use one language.

### R-14 — Provenance and sign-off block *(fixes F-18, M-04)*
**What.** A small footer/first-page block on screen and in the printed artefact: company, report date, *Availability = (required − downtime) ÷ required; required = 10 open h/day − preventive labour; downtime = union of breakdown intervals*, **figures as of HH:MM (server time)**, generated by *user*, and a `Prepared by / Reviewed by` signature line for the printed copy.
**Why.** A document that is signed and filed must be auditable. The hub already prints "10 h/day · 60 h/week" and "Figures read … server time"; the daily sheet should not be the one surface that explains nothing.
**Outcome.** The number survives scrutiny in a meeting, and the printed sheet is defensible months later.

### R-15 — Give long text a ceiling and a door *(fixes F-20, F-21)*
**What.** Clamp observation/action to 2 lines with an expand control (`<details>` — works without JS); link the machine ID to `equipment/view/{id}` and the observation to its work order (`WO-…`). Preserve the full text in the XLSX export.
**Why.** Density must not be bought by deleting information, and a report that cannot take you to the record forces a re-search for every row you act on. Progressive disclosure is the standard answer: summary in the grid, full text on demand.
**Outcome.** Row heights stay predictable on screen and on paper; acting on a row costs one click instead of a context switch.

### R-16 — Replace the CDN charts with the project's own chart pattern *(fixes F-26, M-08)*
**What.** Use the server-rendered SVG pattern already used on `/reports` (fixed 0–100 % axis, dashed target line, `role="img"`, `aria-label` summary, visually-hidden data `<table>`), one small multiple per type. Add the 7-day trend and yesterday's figure for the fleet KPI.
**Why.** `/reports` was *deliberately* rebuilt to avoid canvas and CDN dependence, and `tests/reports_hub.js` enforces it ("no blank canvas when the CDN is offline"). It also prints on paper, which canvas panels do not do reliably.
**Outcome.** Charts render on any plant network, are readable by assistive technology, and print. One day's number gains the context that makes it interpretable.

### R-17 — Small corrections *(fixes F-23, F-24, F-25, F-27)*
Previous/next-day + "Today" controls; single rounding at the point of display (keep 1 decimal for values near target); complete the sidebar exclusion list (or better: replace the hand-maintained list with a route-prefix match on the longest matching nav item).

---

## 5. Deliverable 3 — Priorities

### Critical — decide before the next management meeting
| ID | Item | Effort | Risk if deferred |
|---|---|---|---|
| R-01 | Dated report must describe the selected date (F-01) | M | Signed historical sheets are wrong in the optimistic direction. |
| R-02 | One availability, one name; count is readiness (F-02) | S | Screen and PDF print different numbers under the same label. |
| R-03 | Target-aware colour + visible target (F-03, F-06) | M | Colour asserts a judgement the reader cannot verify; screen/PDF disagree. |
| R-04 | Unknown ≠ zero; coverage disclosure (F-04) | S–M | Missing data is reported as 100 % green; data failures stay invisible. |

### High — next iteration
| ID | Item | Effort |
|---|---|---|
| R-05 | "Down" defined by breakdown/hold, not any open WO (F-05) | M |
| R-06 | Honour `include_in_weekly_report` + visible scope line (F-07) — *pending Q-01* | S |
| R-07 | Filter bar + segments + exceptions first (F-08, F-11) | M |
| R-08 | Real group header rows + sticky header (F-09) | M |
| R-09 | Report-density `.tbl-report` + sticky identifier column (F-10, F-28) | M |
| R-10 | One print artefact (F-12) | S |
| R-11 | Accessibility pass (F-13, F-23) | M |

### Medium — planned
| ID | Item | Effort |
|---|---|---|
| R-12 | Honest column labels (F-14) — *screen first, exports after Q-03* | S |
| R-13 | All interventions + shared status vocabulary (F-15, F-16) | M |
| R-14 | Provenance + sign-off (F-18) | S |
| R-15 | Text clamp + drill-through (F-20, F-21) | M |
| R-16 | Offline-safe SVG charts + day-over-day context (F-26, M-08) | M |
| — | Honest empty / all-clear / no-match states (F-22) | S |
| — | De-duplicate the four availability restatements (F-19) | S |

### Low — housekeeping, do while nearby
| ID | Item |
|---|---|
| R-17 | Prev/next day, Today, single rounding, sidebar active-state, `main-page-title`, number alignment/zebra |

**Sequencing note.** R-02, R-03 and R-04 share one small helper and one edit to `_build_workshop_daily()`; do them as **one change** to avoid a half-consistent release where the screen is target-aware but the PDF is not. R-01 is independent and can ship first if you want the fastest trust win.

---

## 6. Deliverable 4 — Expected outcomes

| Improvement | Usability | Efficiency | Clarity | User confidence |
|---|---|---|---|---|
| **R-01** date-correct state | The row means what its column says | Removes manual reconciliation with the WO list | Past tense + restore time removes ambiguity | **Decisive** — the signed sheet becomes defensible |
| **R-02** one availability | One label, one number, everywhere | Ends "which number do I quote?" | Names the population behind each measure | **High** — removes the single biggest source of distrust |
| **R-03** target + variance | Answers "is this good?" without mental arithmetic | No lookup of the target; no recalculation | Comparison is a length/gap, not a colour guess | **High** — every value becomes judgeable |
| **R-04** unknown ≠ zero | Shows *what we don't know* | Surfaces data gaps before they reach a meeting | 100 % and 0 % stop lying | **High** — the report stops over-claiming |
| **R-05** down = maintenance state | Count and downtime agree | No manual exclusion of planned jobs | One population per number | Medium–High |
| **R-06** documented scope | Fleet total matches the weekly sign-off | No reconciliation between reports | Scope stated on the page | Medium–High |
| **R-07** filters + exceptions first | Core daily task in one click | Est. 116-row scan → 4 visible rows | Attention goes to what needs action | **High** |
| **R-08** group headers | Reader keeps context at row 40 | No re-orientation after scrolling | Structure mirrors the org chart | Medium–High |
| **R-09** report density | More rows per screen, aligned numerals | ~+35 % rows visible; no horizontal hunt | Scannable columns | Medium |
| **R-10** one print artefact | Print ≠ surprise | One layout to maintain | Paper matches screen | Medium–High |
| **R-11** accessibility | Works at 11 px on a bright monitor, with AT | — | Status in words as well as colour | Medium (Critical for compliance) |
| **R-12** honest labels | No misread durations or counts | — | Header names the real quantity | **High** (cheap) |
| **R-13** all interventions + shared vocabulary | Daily ≥ weekly, same words | No cross-report translation | "Waiting parts" survives | Medium–High |
| **R-14** provenance + sign-off | The sheet is auditable | No external notes needed | Definitions visible | **High** for a signed document |
| **R-15** clamp + drill-through | Long text doesn't break the scan | Row → record in one click | Full text preserved on demand | Medium |
| **R-16** offline-safe charts + trend | Charts always render | Prints; no CDN dependency | A day gains context | Medium |

**The honest headline:** three of the four Critical items are *presentation of numbers the controller already computes*. Effort is small; the change in trust is large. What this page needs is not a new visual language — it is alignment with the language the project already wrote down and tested.

---

## 7. Proposed target anatomy (proposal only — not implemented)

```
┌ Toolbar ─────────────────────────────────────────────────────────────────┐
│ Workshop Daily Report · Sun 26 Oct 2026      [‹] [Today] [›]  [date]      │
│ 116 reportable machines · figures as of 07:42 · 10 open h/day   [PDF][xls]│
├ Executive strip (4 cards, all target-aware, unknown = —) ────────────────┤
│ Fleet availability 87.2%  target 88% ▼ −0.8 pp   │ Machines up 112/116   │
│ Downtime today 26.5 h (union, 4 breakdowns)      │ Waiting parts 3       │
├ Per-type small multiples (SVG, target line) + 7-day fleet trend ─────────┤
├ Segments: All 116 · Down 4 · Waiting parts 3 · No action recorded 2 ─────┤
│ Search [machine / model / location]        Types: [All][ADT 24][EX 8]…   │
├ Table (report density, sticky header, sticky TYPE, down-first) ──────────┤
│ ▸ ADT — 24 machines · 21 up · 3 down · KPI 84% (target 88%, −4 pp)       │
│   MACHINE ID │ MODEL │ LOCATION │ DOWN SINCE │ DAILY │ TARGET │ VAR │ …   │
│   …observation (2 lines + expand) — WO-1042 — was down, restored 14:20   │
└ Provenance + Prepared by / Reviewed by ──────────────────────────────────┘
```

**What is removed and why:** the duplicate by-type strip *and* the in-table DAILY KPI column collapse into the group header rows; the `↑` chips become real deltas; browser-print styling of the interactive table is retired in favour of the single PDF artefact.

---

## 8. Decisions I need from you before changing anything

| ID | Question | Why it blocks work |
|---|---|---|
| **Q-01** | Is `include_in_weekly_report` meant to exclude machines from the **daily** sheet too (`SYSTEM.md:47` says it defines the weekly population)? | Changes the fleet total and every percentage. A business decision, not a design one. |
| **Q-02** | Is "Overall Availability" in the daily meeting the **time-based KPI** (88 % target) or the **count of machines up**? | Decides which number becomes the headline. I recommend time-based KPI as the headline, count as secondary. |
| **Q-03** | May the **XLSX/PDF column headings** change (e.g. `DOWN TIME` → `DOWN SINCE`)? | External consumers may parse these files (precedent: `table-ux-audit.md` F-08). |
| **Q-04** | Should a machine with **no required-time basis** be shown, hidden, or shown as "no basis" and excluded from the KPI? | Decides R-04's default. I recommend: shown, `—`, excluded, with a coverage line. |
| **Q-05** | Is the daily sheet **read by technicians/team leaders**? Today roles 2 and 3 cannot open `reports` at all. | If yes, that is a scope change well beyond visual design. |
| **Q-06** | Is one calendar day the right grain, given 10 open hours per day means a single breakdown is ≈10 pp? | A 7-day rolling context (R-16) may matter more than per-day precision. |

---

## 9. Verification plan (what must be true before this counts as done)

1. `php -l` on any changed PHP; `php tests/run.php`; `node tests/*.js` (baseline in Appendix B).
2. Authenticated staging with seed data: open **yesterday** and a **day > 30 days ago**; confirm status/observation match the work orders of that day and reconcile with `equipment/view`.
3. Reconcile screen ↔ XLSX ↔ PDF for one date: fleet availability, machines up/down, per-type KPI, per-machine availability, target, variance. Same numbers, same colours, same words.
4. Contrast re-audit against Appendix A thresholds (≥ 4.5:1 for all data text).
5. Print the PDF artefact to A4 landscape; confirm no clipped columns and that the sign-off block is visible.
6. Keyboard + screen-reader pass: table caption/scope, group headers announced, date field labelled, no colour-only status.
7. Zero-data and partial-data runs: one type with no engine data, one machine with no basis, one day with no work orders.
8. Confirm no new query per machine was introduced in `_build_workshop_daily` (the engine already batches; keep it that way).

---

## Appendix A — Measurements taken (Node, this workspace)

Contrast against white (WCAG 2.2 relative-luminance formula):

| Token | Value | Ratio | AA 4.5:1 for normal text |
|---|---|---|---|
| `--t1` | `#1d1d1f` | 16.83:1 | pass |
| `--t2` | `#6e6e73` | 5.07:1 | pass |
| `--t3` | `#86868b` | **3.62:1** | **fail** (used for location, times, dates, E/T) |
| `--t4` | `#aeaeb2` | **2.21:1** | **fail** (used for blank/subdued cells) |
| `--blue` | `#0071e3` | 4.70:1 | pass (marginal; 4.39:1 on row hover) |
| `--green` | `#1c8c3c` | **4.32:1** | **fail** (used for 11.5 px AVAIL %) |
| `--amber` | `#b45309` | 5.02:1 | pass |
| `--red` | `#c0392b` | 5.44:1 | pass |
| PDF palette `#375623` / `#1F4E79` / `#7F6000` / `#C00000` / `#2E75B6` | — | 8.34 / 8.66 / 5.87 / 6.48 / 4.84 | pass |

Table geometry (`workshop_daily.php:125–146`):
- declared fixed column widths sum to **1 300 px** while the wrapper declares `min-width:1200px`;
- inherited cell padding 13 px 24 px → 18 columns × 48 px ≈ **864 px** of horizontal padding inside a 1 200 px row;
- the declared widths alone already exceed the wrapper's own `min-width` by ~100 px before the (unmeasured, flexible) OBSERVATION column is added, so the table overflows its container at every realistic viewport.

Fleet size from the seeded dataset: **116** equipment rows, 7 types (**ADT 24**, EXCAVATOR 8, DOZER 8, GRADER 4, RIG 3, CRANE 3, FORKLIFT 2) — the basis for the "group label scrolls away" and "116-row scan" findings.

## Appendix B — Test baseline observed before any change

`node tests/*.js`: `global_find_attention` **PASS**, `page_titles` **PASS**, `reorder_ux` **PASS**, `reports_hub` **PASS**, `service_kits_ux` **PASS**, `table_ux` **PASS**; `lubricant_masters_ux` **FAIL** (pre-existing assertion mismatch), `ui_e2e` **FAIL** (requires a live server + `/tmp/cookies.txt`). Both failures pre-date this review and are unrelated to the workshop daily report.

## Appendix C — Evidence index

| Finding | File | Lines |
|---|---|---|
| F-01 current-state WO query | `application/controllers/Reports.php` | 1790–1800, 1822–1826 |
| F-02 count vs time availability | `views/reports/workshop_daily.php` / `Reports.php` | 61, 86–89 / 1928–1930, 1990 |
| F-03 per-surface colour rules | `views/reports/workshop_daily.php` / `Reports.php` | 103, 184, 208, 225 / 2032 (PDF, target-aware), 2159 (XLSX, fixed) |
| F-04 unknown → 1 / 0 | `Reports.php` | 1860, 1899 |
| F-05 open-WO = down | `Reports.php` | 1822–1826 |
| F-06 target computed, never shown | `Reports.php` / `kpi_helper.php` / `workshop_weekly.php` | 1879, 1901 / 392–398 / 181–182 |
| F-07 reporting population | `SYSTEM.md` / `Reports.php` / `W36_report_model.php` | 47 / 1785, 1228 / 444 |
| F-08 no findability | `Reports.php` / view | 1801 / 125–199 |
| F-09 first-row group cells | view | 150–156, 186–189 |
| F-10 density & width | view / `layouts/header.php` | 125–146 / 526–541 |
| F-11 charts after table | view | 203–260 |
| F-12 two print artefacts | view / `Reports.php` | 263–273 / 1435, 1946+ |
| F-13 contrast, labels, colour-only | view / `header.php` | 33, 90–98, 165–183 / 31–34, 40 |
| F-14 misleading headers | view / `Reports.php` | 127–146, 152, 177–183 / 1845–1852 |
| F-15 latest WO only | `Reports.php` | 1805–1810 |
| F-16 status vocabulary drift | `Reports.php` / `workshop_weekly.php` | 1285–1298 / 99–118 |
| F-17 invented trend chip | view | 73 |
| F-18 no provenance | `Reports.php` | 1956–1968 |
| F-22 empty states | view / `reports/index.php` | 147–149 / 940–946 |
| F-26 CDN chart vs house pattern | `header.php` / `tests/reports_hub.js` | 10 / 39–44 |
| F-27 sidebar exclusion list | `layouts/sidebar.php` | 5–8, 217 |

---

**Next step:** approve, modify or reject the register — in particular Q-01 to Q-06. I have made no changes. On approval I would implement in the sequencing of §5, starting with R-01 + the R-02/R-03/R-04 helper as a single consistency change.
