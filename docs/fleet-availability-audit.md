# Fleet Availability Reporting — system audit and feasibility assessment

**Assessment date:** 31 October 2026 (UTC)  
**Scope:** `docs/Fleet Availability Report.html`, Backlog, Workshop Weekly, Weekly W36, the KPI engine, database schema, and repository-seeded records.  
**Change boundary:** Audit only. No application code, database structures, or records were modified. The sample values in the HTML were not imported or used as operational data.

## Evidence and limits

This assessment distinguishes verified repository evidence from live-data conclusions. The checkout contains the application source and `database/schema.sql`, but there is no local `application/config/database.local.php` and no usable database connection configured in this workspace. Consequently **the production/live database could not be queried**; row counts, field-population rates, duplicates, overlaps, timestamps, and actual historical coverage must be validated against the deployed database before a production go/no-go. The static schema includes seed SQL: it contains 44 backlog rows (3 with `machine_status='BREAKDOWN'`) but has no seed `work_orders`, `wo_sessions`, planned-downtime-ledger, or weekly-snapshot inserts. These seed counts are evidence about the checked-in SQL only—not about the deployed WMS database.

Code reviewed includes `application/controllers/Backlog.php`, `Reports.php`, `Workorders.php`; `application/models/Backlog_model.php`, `Workorder_model.php`, `W36_report_model.php`, `Kpi_engine_model.php`, `Kpi_target_model.php`, `Settings_model.php`; `application/helpers/kpi_helper.php`; the `reports/weekly_w36.php` and `reports/workshop_weekly.php` views; `database/schema.sql`; and the reference HTML itself.

### Confidence labels

- **Verified** — directly present in checked-in code/schema/HTML.
- **Static seed evidence** — present in checked-in schema's insert/update SQL, not a live-database measurement.
- **Not verified live** — requires a read-only audit on the deployed database.
- **Recommendation** — proposed action, not an existing capability.

---

## 1. Executive summary

### Overall feasibility: feasible, with material data-quality and definition gates

The target can be delivered primarily by **extending existing WMS reporting**, not by adding an independent fleet-availability module. Weekly W36 already has the strongest reusable foundation: database-backed equipment rows, work-order and labor-session retrieval, seven-day allocations, distinct breakdown-event handling, configured hours and targets, data-quality messages, historical ISO-week selection, Excel/PDF output, and publishable frozen snapshots. Workshop Weekly already renders and exports a weekly summary using the common KPI engine. Backlog supplies defect/workflow context and can be linked to a work order, but is not a downtime ledger.

The HTML reference is a **mockup with embedded W36 sample figures**, not a live report or a reliable specification for every formula. Its figures and dates are hard-coded; its event log is browser-session-only. It should guide page structure and interaction, not be copied as a source of KPI truth. In particular, some mockup rollups are arithmetic averages while WMS's W36 engine uses capacity-weighted (required-hours-weighted) aggregation and merged breakdown intervals.

**Conditional answer:** the dashboard and weekly report are substantially achievable from existing equipment, work-order, labor-session, target, and reporting data **if** the deployed data is complete and operational definitions are approved. The downtime log is achievable by extending the existing work-order/defect workflow; its reference-level event intake (start/restoration times, reason, classification, audit history) is not yet evidenced as a complete, dedicated end-user workflow. There is no support in this checkout to claim the necessary live work-order history exists or is sufficiently populated.

No new database table is recommended at this stage. Existing `work_orders`, `wo_sessions`, `equipment`, `equipment_kpi_targets`, `weekly_report_snapshots`, `backlogs`, and planning/PM tables cover much of the model. Targeted database changes should be considered only if live audit finds a specific missing, necessary fact—especially event-to-work-order identity, actual planned machine-unavailable time, or historical asset/status/effective-target attribution—that cannot be safely reconstructed from current records.

### Main risks / gates

1. **Live evidence missing in this audit environment:** no direct database query or record-level verification was possible.
2. **Operating-time policy:** current W36 implementation uses settings-driven open hours less preventive labor time. The seed schema defaults to 168 hours/week and 24/day, while a `Kpi_engine_model` explanatory comment mentions a 10-hour × 6-day pattern. Confirm the deployed settings and business policy before comparing results.
3. **Preventive labor is not the same as planned machine downtime.** `wo_sessions.hours_worked` represents technician labor; `equipment_planned_downtime` is a planning/forecast ledger. Neither alone proves actual unavailable time for a scheduled service.
4. **Backlog is a defect register, not event history:** it has calendar dates and a condition label, but no time-of-day outage interval; many defects do not have a work order. Counting backlog items as failures/downtime would be wrong.
5. **Historical reconstruction:** current equipment status and current fleet membership do not establish historical status/population. W36 snapshots make published periods reproducible, but only from the time weeks are actually published.
6. **Do not treat code comments or schema defaults as live values:** query the deployed settings and record population, and reconcile output across the reports.

---

## 2. Reference report analysis

### Target experience and navigation

The reference is a responsive single-page mock application with a fixed desktop side rail and three hash-routed pages: Fleet availability (`#dashboard`), Weekly report (`#report`), and Log downtime (`#entry`). It uses a compact enterprise visual language: left navigation, reporting-week chip, KPI cards, panels, dense but horizontally scrollable tables, status colors, accessible focus states, keyboard escape to close a detail drawer, and mobile horizontal navigation. The week in its static header is **W36, 31 Aug–6 Sep 2026**, and it declares a fleet of machines and 17 equipment types. All displayed identity, target, status, and KPI input values are embedded in the page's JavaScript object `D`.

### A. Fleet Availability Dashboard

**Hierarchy / elements**

- Header with report name, W36/date range and machine/type counts; “Export Excel” and “Export PDF” controls.
- A highlighted fleet-availability KPI with target meter, target-relative status and percentage-point difference.
- Five supporting cards: downtime hours, failures, MTBF, MTTR, plus the main availability card (six card slots including the lead KPI).
- Equipment-type availability horizontal bars, sorted worst-first, with per-type target ticks and target bands.
- Seven-day fleet-availability bar chart and target line.
- Availability bars for up to six locations with the most machines.
- “Machines needing attention” table, showing highest-down-time assets, model/location, down hours, failure count, and availability.
- Selecting a type bar navigates to the weekly table and opens that group. Selecting an equipment item opens a right-side detail drawer with the seven-day downtime/preventive stack, weekly values, target, and session-local events.
- A source-data warning is shown for mock rows with downtime but no failure count. Filters are not present on this dashboard; date is fixed.

**Mock calculations observed in JavaScript**

For each day, `required = 24 - preventive_hours`, and `day availability = (required - min(downtime, required))/required` (100% if required is non-positive). For a week, the mock calculates `required = 168 - sum(preventive_hours)`, `availability = max(0, (required - downtime)/required)`, `MTBF = (required - downtime)/failures`, and `MTTR = downtime/failures`. The static `D.apps` and `D.fleet` values are precomputed baselines; user-added events adjust those baselines, rather than rebuilding the full report from an operational source. Type values average member-machine availability in portions of the mock logic; fleet availability averages type-level availabilities. This is not the WMS rollup rule and should not be adopted without approval.

**Data status:** all numbers and target dates in this page are **mockup data**. They are not evidence of what WMS currently stores.

### B. Weekly Report

- Same fixed W36 heading, week dates and export controls.
- Expandable/collapsible equipment-type sections, followed by machine rows; desktop-style wide table becomes horizontally scrollable on smaller screens.
- Type and fleet rows show an average daily-availability strip and weekly preventive hours, downtime hours, failures, MTBF, MTTR, availability, and target. Machine rows show the same measures and a seven-cell daily status strip. A warning flag calls out downtime without a failure count.
- Filters: free-text equipment/model/location search; location select; below-target-only checkbox; expand-all/collapse-all.
- Selecting an asset opens its details drawer. Type rows link back from the dashboard.
- The visible mock report has weekly period but no functional historical-week selector. The header's export buttons only show a “not part of mockup” toast; there is no actual Excel/PDF generation in this HTML.
- The availability strips are colored for available, preventive, partly down, or mostly down states. Table target comparisons classify below/on/near target.

### C. Log Downtime

- Form takes one date/day, equipment, Breakdown or Preventive type, start time, back-at time or “still down at end of day”, a reason from a hard-coded list, and optional free-text comment.
- An updating preview compares daily and weekly availability, downtime, preventive hours, failure count, MTBF and MTTR before/after logging.
- An equipment/type board shows each machine's status color for the selected day; selecting a machine fills the form. A session-local event list permits removal; removing an event reverses the mock adjustment.
- The form calculates elapsed hours for same-day events only. It rejects end times not later than start, and prevents totals above 24 hours; “still down” clips to midnight. It does not handle overnight intervals, multiple dates, time zones, or reopening an event.
- “Reason” options are a static vocabulary, not linked to WMS failure categories. No named creator, work-order ID, verification, persistence, duplicate detection, true event history, planned schedule, or restoration confirmation is represented. Reloading the page loses entries.
- Logged events alter browser memory and redraw the screens; this is not database write behavior.

**Reference specification conclusion:** copy the three-page workflow and information hierarchy, but make week selection and export real, retain explicit unknown/unavailable states, use WMS's approved KPI engine, and persist outage changes through the existing work-order/defect workflow—not through the mockup's separate in-memory event list.

---

## 3. Existing WMS audit

### A. Backlog

**Verified implementation**

- `Backlog_model` documents the module as a defect/backlog register replacing manual “Backlog on going” / “Backlog done” sheets. A backlog item is explicitly **not** a work order. Items may be inspection/checklist/job-card/operator defects; many are closed without a WO.
- `backlogs` stores optional `equipment_id`, raw `asset_label`, model/serial/meter text, date-only `start_date` and `end_date`, defect description/observation, source/reporter/owner, priority/deadline, `machine_status` (`OPERATIONAL`, `BREAKDOWN`, `STANDBY`), workflow status, closing remarks/mechanic, optional `work_order_id`, schedule proposal fields, and closure/verification evidence.
- `Backlog_model::get_all()` left-joins to equipment, filters by status/priority/source/machine status/date/equipment, and derives `days_down` from date-only start/end dates. For active status it measures through today; it does not use timestamped outage intervals.
- `get_register_overview()` joins backlog to equipment and optionally work orders/users; it reads actual linked active plan jobs and reports mismatches. Backlog activity is captured in append-only `backlog_events`. The controller provides register views and CSV/XLSX output.
- Database relations in schema connect backlog work-order links and plan-job links. The actual relation is optional; no reason to assume every defect or reported breakdown has a WO.

**Fitness for target metrics**

- Good for open-defect counts, breakdown condition warnings, narrative/reason/context, planned scheduling proposals, and linking a defect to repair evidence.
- **Not sufficient for total downtime/availability:** dates are calendar dates (no outage start/restoration times); `days_down` is derived defect age and is not a measured machine-down interval; a defect may not make the asset unavailable; `machine_status` can be null/stale and is not historically versioned in `backlogs`.
- A backlog item is not a breakdown count. Multiple backlog defects may describe one incident; one backlog may have no WO; recurrence is a defect relation and not necessarily a new failure event.
- Static-seed evidence: 44 checked-in backlog rows; 3 rows carry `machine_status='BREAKDOWN'`. Most displayed seed records are inspection/checklist defects and use date-level values. The SQL links some imported asset labels to equipment via later updates. This is not a production completeness statistic.

**Assessment:** use Backlog as a linked cause/defect and maintenance workflow source, not as the authoritative downtime event or denominator source.

### B. Workshop Weekly

**Verified implementation**

- `Reports::workshop_weekly()` resolves an input week and calls `_build_workshop_weekly()`. The page is rendered from `reports/workshop_weekly.php` and has Excel and browser-print/PDF exports.
- The builder obtains the period's fleet KPI from `Kpi_engine_model->fleet()`, then groups active (non-retired) equipment by equipment type and supplements the KPI data with work-order observations/actions, a latest-WO/current status code, start date, day-down display, WO count, and target.
- Group KPI rollups call `Kpi_engine_model->rollup()`; the target is resolved per machine and the default comes from settings. Group/fleet-level information and exports already exist.
- Its status/annotation portion has a separate path from the KPI engine: work orders are fetched using start date, creation date, and open status conditions; it chooses a latest WO/current equipment state, and estimates days down from the first WO in the week's list. Thus status, WO annotation, and KPI period logic are not simply one identical dataset projection.

**Assessment**

Workshop Weekly is a useful existing UX/export and supplemental summary. Its availability source is already the common KPI engine, making it reusable. For the target's consistent event totals and historical weekly readings, prefer W36's single model/payload as the report calculation contract; reconcile Workshop Weekly's separate status/day-down/WO-selection display against W36 rather than building new calculations on top of it. Existing Excel/PDF patterns can be reused.

### C. Weekly W36

**Verified implementation**

- Route and controller: `reports/weekly_w36`; date selection via ISO week (with date-in-week support), previous/next-week controls, live reading and publish/frozen reading.
- `W36_report_model::build()` constructs one report payload for the web preview and Excel writer. It reads equipment, weekly work orders, labor sessions, report groups/sites, target rows, PM schedules/completions, inspection evidence and the settings required for the report. The reportable fleet/order is controlled by equipment flags/report groups (including reportability helper logic), not by a hard-coded machine array.
- `work_order_rows()` retrieves timestamped WOs whose intervals touch the period; open and carried-in work orders can contribute. `session_rows()` retrieves `wo_sessions` by session start date. The model filters/re-slices data per equipment and passes it to the pure KPI helpers.
- `kpi_helper.php` groups linked work orders into breakdown events, merges touching/overlapping intervals, clips event intervals to reporting boundaries, splits downtime by day, counts distinct breakdown events according to configured count mode, and treats an open event as continuing through the report cutoff. It calculates daily/weekly values and capacity-weighted rollups.
- For preventive time it counts positive `wo_sessions.hours_worked` tied to a work order that is classed Preventive. Unlinked sessions or sessions whose WO cannot be classified are not included as PM labor.
- Target resolution follows dated equipment, standing equipment, dated group, standing group, and settings fallback in `Kpi_target_model` (documented in schema/comments and model). Missing target/site/group and current equipment-status versus open-WO conflicts can appear in a data-quality list.
- The W36 view shows machine/group/fleet weekly and daily values, expandable day details/events, charts and quality indicators. The route supports downloadable `.xlsx`, print/browser-PDF, and publication to `weekly_report_snapshots`. Published payload/checksum freezes a report for repeatable historical reads. The report hub links both W36 and Workshop Weekly.

**Core KPI definition in the WMS engine**

- Open hours: settings-driven (`weekly_open_hours`, `daily_open_hours`).
- Preventive time: actual labor hours from sessions on Preventive WOs.
- Required time: open hours minus preventive labor hours.
- Downtime: union of breakdown work-order intervals (deduplicated overlaps/touches and clipped to period).
- Availability: `(required time − downtime) / required time`, clamped to 0–100%; null when required time is zero.
- Breakdown count: grouped event count, configurable whether only events starting in period or carried-in events too.
- MTTR: downtime divided by counted events; null with no event.
- MTBF: `(required time − downtime) / counted events`; zero-failure display policy can be dash or uptime.
- Fleet/type rollup: total remaining required hours divided by total required hours (capacity-weighted), not mean of percentages; targets are also weighted according to helper rules.

This is materially more defensible than the mockup's static baselines and plain averages. It still depends on the correctness of WO classification, event linkage, dates, settings, and labor sessions.

**Historical/data caveats**

- The report can rebuild old weeks from retained WO/session data, but equipment population/group/location and status are generally read from current equipment records. Historic membership/status is not inherently reconstructed from those current fields. A published snapshot freezes what was published; it does not retroactively make unpublished weeks historically accurate.
- The `equipment_kpi_targets.target_date` field and report snapshot facilities help preserve target/report decisions, but effective dates and snapshot coverage must be checked in live records.
- The W36 model emits useful data-quality findings, but the live database must be tested for missing/out-of-range timestamps, invalid interval length, event links, orphan work orders/sessions, duplicate records, and target coverage.

### Relevant data model and relationships

| Table | Relevant fields / relationship | Reporting use and limit |
|---|---|---|
| `equipment` | `id`, `registration_no`, `machine_type`, brand/model, `location`, `status`, `report_group`, `include_in_weekly_report`, current meters | Asset identity, grouping, current status/location; status is not a historical status timeline. |
| `work_orders` | `equipment_id`, `type`, `is_breakdown`, `breakdown_event_id`, status, `start_datetime`, `end_datetime`, `downtime_hours`, symptoms/root cause/action, `actual_hours` | Primary actual event/repair source. `downtime_hours` is also stored/recomputed, but engine uses interval merging; classification and valid timestamps matter. |
| `wo_sessions` | `wo_id`, `start_datetime`, `end_datetime`, `hours_worked`, status/work performed | Labor time; session is linked to WO. Actual preventive labor is not identical to asset downtime. |
| `equipment_kpi_targets` | equipment/group scope, equipment/group key, target date, target % | Availability target resolution; historical effectiveness needs validation. |
| `weekly_report_snapshots` | week bounds, ISO label, JSON payload, checksum, publish actor/time | Reproducible published W36 weeks, not automatic backfill of older periods. |
| `backlogs` / `backlog_events` | optional `equipment_id`, date-only defect, condition/status, optional WO and plan links, history events | Defect/workflow/context and link to WO; not a measured outage ledger. |
| `maintenance_plan_jobs` | equipment, planned start/end, hours/day, job/category/status, optional WO/backlog/PM links | Planned work scheduling; plan is not evidence that downtime actually occurred. |
| `equipment_planned_downtime` | equipment/day/category/hours/source | Machine-day planned/forecast ledger. Distinguish from actual PM stoppage. |
| `pm_schedules`, `pm_completions` | planned schedule/due values/completion date and optional WO | PM planning/completion reporting; does not itself quantify realized downtime. |
| `report_groups`, `report_sites`, `settings` | report vocab/order, open hours, week rules, target/count policies | Report behavior/configuration; live values must be queried and approved. |

---

## 4. Data mapping matrix

Status applies to the **source currently verifiable from code/schema**, not a claim that all deployed rows are populated. “Needs Validation” is used where the model exists but its actual accuracy or business interpretation depends on live data or policy.

| Reference feature / KPI | Existing module | Database source | Data status | Required action |
|---|---|---|---|---|
| Asset ID, model, type, location | W36; Workshop Weekly; Backlog | `equipment.registration_no`, `brand`, `model`, `machine_type` / `equipment_types`, `location` | **Available** structurally; **Needs Validation** live values | Validate duplicate/mismatched registrations, site vocabulary, and reportable fleet flags. |
| Current equipment status | W36; Workshop Weekly | `equipment.status`; open WO status | **Available** current; **Partial** historically | Present current status as current, not historical. Reconcile status-versus-open-WO conflicts. Historical status requires a record/snapshot source. |
| Reportable fleet/type groups | W36; Workshop Weekly | `equipment.include_in_weekly_report`, `report_group`, `report_groups`, type tables | **Available** structurally; **Needs Validation** configuration/population | Confirm fleet population and whether all asset classes in reference should be included. |
| Weekly period / ISO week | W36; Workshop Weekly | `Settings_model`, settings; snapshots | **Available** | W36 ISO-week/date picker is preferred; confirm local week start and UTC/site timezone. |
| Open/available time denominator | W36; Workshop Weekly; mock report | `settings.weekly_open_hours`, `daily_open_hours`; PM session hours | **Needs Validation** | Approve calendar/shift hours and treatment of PM/lunch/standby/asset-not-required periods. Seed defaults show 168/24; code comment elsewhere differs. |
| Actual breakdown start/end | Work Orders; W36 | `work_orders.start_datetime`, `end_datetime`, `status`; `equipment_id` | **Partial** structurally; **Needs Validation** live | Check requiredness and completeness; open WO end should remain unknown and be clipped at report cutoff, not treated as a completed event. |
| Total breakdown downtime | W36 KPI engine | `work_orders` breakdown intervals; `breakdown_event_id` | **Derivable** | Use merged/clipped interval union, not summed `downtime_hours`; validate null/negative/reversed dates and event overlap. |
| Failure/breakdown count | W36 KPI engine | `is_breakdown`, type, `breakdown_event_id`, WO IDs/start time | **Derivable** | Count distinct events, not WOs. Audit event ID coverage and `wo_is_breakdown` classification consistency. |
| Breakdown reason / failure mode | Backlog; Work Orders | `backlogs.description`, `observation`; `work_orders.symptoms`, `root_cause`, `equipment_systems`, `title` | **Partial** | Link relevant defect to WO/event; use validated categories where available; retain raw narrative. No safe category inference from arbitrary text. |
| Planned maintenance activity | Backlog; PM; Workshop Weekly; W36 | `pm_schedules`, `pm_completions`, preventive WOs and `wo_sessions`; `maintenance_plan_jobs` | **Available** as planning/activity evidence; **Partial** as actual machine downtime | Keep scheduled work, performed labor, and actual asset-unavailable interval distinct. Do not infer actual planned downtime from PM WO existence or labor alone. |
| Planned vs unplanned downtime split | W36; planning | Breakdown event flag + `equipment_planned_downtime` ledger and actual WOs | **Partial** | Agree classification and actual-vs-plan semantics. Preventive time currently reduces required hours; planned downtime is not a simple event category in the KPI engine. |
| Preventive hours in reference table | W36 | Preventive `wo_sessions.hours_worked` | **Derivable**, contingent on sessions | Aggregate linked sessions in period; validate overlapping labor sessions, missing session durations, edits, and whether “hours” is labor or machine outage. |
| Weekly equipment availability | W36; Workshop Weekly | Work orders, sessions, settings, helper output | **Derivable** | Use one W36/KPI-engine payload and document denominator; distinguish no required time from 100% availability. |
| Fleet/type/location availability and charts | W36; Workshop Weekly | Equipment dimension + engine rollup | **Derivable** | Use required-hour-weighted rollups. Do not copy reference HTML's simple mean of type averages. Reuse model charts/payload or centralize output. |
| Daily availability trend | W36 | Clipped event intervals, daily open hours, sessions, targets | **Derivable** | Use W36 daily rollups and consistent daily denominator; dates and boundary convention must be tested. |
| MTBF / MTTR | W36; current workshop report | Distinct events and engine downtime/required hours | **Derivable** | Use documented formulas and zero-failure/null policy. Confirm whether MTTR is event interval duration or repair labor; WMS defines downtime/event. |
| Machine-level weekly daily strip/details | W36 | Seven daily KPI entries and events | **Available** in report model/view | Re-style/reuse; validate responsive behavior and expose unknown/no-data indicators. |
| Downtime log reason, timestamp, status | Work Orders + Backlog | `work_orders` interval/description fields and optional backlog link | **Partial** | Extend existing WO workflow into targeted event logging. Require asset, classification, start, reason, and restoration/ongoing status; preserve actor and audit. |
| Work order and defect linkage | Backlog / Work Orders | `backlogs.work_order_id`, `work_orders`, `backlog_events` | **Available** structurally, optional | Audit missing/incorrect links. Do not count both backlog and WO as separate failures. |
| Historical fleet/status/target comparisons | W36 snapshots; equipment/targets | `weekly_report_snapshots`, current `equipment`, target table | **Partial** | Verify snapshot coverage and dated target behavior. Snapshot completed reports; do not claim unrecorded historical status. |
| Dashboard targets and threshold coloring | W36 targets; reference mock | `equipment_kpi_targets`, group/settings fallback | **Available** structurally | Use resolved WMS target. Confirm business status bands; mock’s “within 15 points” rule is not a universal WMS policy. |
| Excel/PDF and printing | W36; Workshop Weekly | Report payload and exports | **Available** | Reuse W36 workbook and print/PDF pathway; align selected week and frozen/live mode. |

---

## 5. KPI calculation assessment

### Availability

The user-specified expression `(available time − downtime) / available time` is valid only once “available time” is formally defined. W36 implements a more explicit operational variant:

`required_time = configured_open_time − preventive_labor_hours`  
`availability = max(0, min(1, (required_time − union_breakdown_downtime) / required_time))`

This is mathematically coherent if (a) configured open time is the intended operating opportunity and (b) the preventive labor-hour adjustment is the approved convention. The second assumption is not automatically true: technician labor hours can overlap or differ from the time the machine was unavailable. A maintenance job can involve multiple mechanics over a shorter outage, and a machine can be held unavailable while labor is not being booked. The reference uses preventive hours similarly but does not settle that business definition. **Decision required:** is KPI availability based on a 24/7 calendar, defined operating shifts, or available scheduled operating hours? Should PM exclusion be PM elapsed outage time, actual labor time, or none? Do assets with no required hours display null/“not applicable,” never fabricated 100%?

### Downtime and overlaps

W36's interval union/clipping is the right basis for overlapping/touching WOs. It avoids double-counting separate repair tickets for the same unavailable period. Validate that one physical incident's work orders share `breakdown_event_id`, and that the interval source records actual restoration, not administrative WO closure. Stored `downtime_hours` is present and the controller recomputes it in many write flows, but the weekly KPI helper uses event timestamps/interval logic rather than blindly summing that field.

An incomplete/open interval should remain open and be measured only to the selected report cutoff. A missing start cannot be calculated; a missing end is not zero downtime. Invalid `end <= start` intervals are ignored by the helper, so they must generate an explicit quality warning and be corrected/reviewed rather than silently disappearing from a published KPI. Overnight events are supported by WMS timestamps and day splitting; the HTML mock's same-day-only input is not.

### Weekly boundaries and historical cutoffs

W36 uses a half-open period `[week_start 00:00, day-after-week-end 00:00)` for interval calculations and fetches records that touch the period. It splits intervals by day and can carry a failure into the week. Breakdown-count mode controls whether carried-in events count as failures; this setting must be consistent on all screens. Historical published weeks should use the frozen payload. Confirm the site timezone at storage/query/export boundaries: SQL `DATETIME` has no timezone offset, while PHP timezone and local week settings determine interpretation.

### Distinct failures, linked work orders and events

`breakdown_event_id` is designed to link multiple WOs to one incident. The KPI helper groups breakdown WOs into event records and counts distinct events. The live audit must find:

- breakdown WOs with no `is_breakdown`/recognized classification;
- multiple WOs for one event with missing/mismatched event ID;
- event IDs crossing equipment boundaries or re-used accidentally;
- events without valid timestamps or end evidence;
- event records opened/closed through status changes without correctly setting `end_datetime`;
- non-breakdown maintenance WOs incorrectly tagged as breakdowns.

Backlog count is not breakdown frequency. WO count is not breakdown count. Incident records and incident details also do not automatically represent equipment failure events.

### Planned versus unplanned

The application has several related but non-interchangeable sources: work-order type/classification, preventive sessions, PM schedules/completions, `maintenance_plan_jobs`, and the `equipment_planned_downtime` planned/forecast ledger. The W36 availability KPI uses breakdown intervals for actual downtime and PM labor sessions to reduce required time. It does **not** currently expose an audited actual planned-outage vs unplanned-outage event total equivalent to the mock's preventive/down segmentation. Existence of a PM WO is not sufficient proof of planned downtime. Choose a controlled classification rule and preserve planned forecast separately from actual realized outage.

### MTTR / MTBF

WMS's formula is MTTR = union downtime / distinct counted breakdowns and MTBF = (required time − downtime) / distinct counted breakdowns. They are undefined with zero failures; code has an explicit configured dash-versus-uptime MTBF rule and MTTR remains null. This is superior to the reference's fallback of dividing by a failure count that may be zero, but the report and dashboard must present null as “—”/“not applicable,” not “0” or a seemingly successful value. Confirm that business expects operational availability measures rather than labor-efficiency measures.

### Reconciliation and validation requirements

Before presenting any live fleet total, run read-only diagnostics on the deployed DB for (1) required timestamp completeness and valid ordering, (2) interval overlap and linked event identity, (3) WO classification/type disagreement, (4) duplicate source/ref/event identifiers, (5) equipment references and retired/reportable population, (6) open WO/equipment status conflict, (7) backlog links and orphan references, (8) session linkage and implausible/overlapping hours, (9) target coverage/effective-date validity, and (10) snapshot range/checksum coverage. Compare W36 and Workshop Weekly for the same week and explain any known differences before changing screens.

---

## 6. Gap analysis

### Functional gaps

- Reference-style cross-page dashboard, W36 week selection and downtime log workflow are not one unified reporting experience. W36 and Workshop Weekly exist separately; the reference is a standalone mock.
- Dashboard in HTML has filters/links and charts, but these are partly illustrative; actual WMS dashboard integration and navigation should follow existing report hub conventions.
- A logged downtime event must be a persisted work-order/event action with workflow, not a browser-only record.

### Data gaps / uncertainty

- Live work order and session population/completeness is **unknown in this audit**. Repository seed SQL contains no WOs or sessions.
- Backlog date/status information is too coarse for reliable outage durations and not all defects are operational breakdowns.
- Planned availability and actual planned outage duration are different data concepts; the current model principally has PM labor and planned forecasts, not proven actual planned outage intervals.
- Current status/location/group may not reflect status/location/group at a historical report date. Past un-published weeks cannot be assumed historically exact.
- Failure category, restoration verification, duplicate event linkage, and timezone practice need live-data validation.

### Calculation gaps / inconsistencies

- HTML values are embedded and partly pre-aggregated; its event adjustments are not a complete recomputation from events.
- HTML reports average machine/type percentages in some rollups; WMS helper uses capacity-weighted rollups. These will differ legitimately.
- WMS has a common engine, but Workshop Weekly adds separate status/day-down and WO-selection logic. The target must read from one agreed reporting payload.
- `Kpi_engine_model` documentation comment describes 10h × 6d while checked-in schema settings default to 168 weekly/24 daily. This may be stale documentation; deployed settings were not observable. Confirm, then correct documentation/configuration if needed (no change made here).
- Zero-required-time availability is null in the KPI helper; other report code paths may default missing KPI to `1.0`. Such fallbacks need alignment so an unavailable/undefined KPI never shows as 100%.

### UX/UI and export gaps

- Reference export buttons are placeholders; actual WMS W36 exports already exist and should be wired consistently from the unified view.
- The reference log lacks persistence, edit/reopen/audit history and validation for actual intervals. It also lacks event/work-order linkage and confirmation of restoration.
- Replicate card hierarchy and responsive charts/tables using WMS styles; retain existing report’s quality messages and publish status. Avoid displaying a KPI when inputs are missing—show “data incomplete” or “not applicable.”

### Technical gaps

- Need deployed DB profiling and reconciliation before implementation can be safely signed off.
- Need contract-level reuse of `W36_report_model`/`Kpi_engine_model` in a dashboard rather than duplicated SQL/calculation.
- Need defined service-level data-quality policy for invalid intervals and event classification, including whether a report may be published with unresolved critical issues.
- Any proposed indexes should follow query-plan measurement; existing indexes include period/status and equipment relationships (schema's later `ALTER TABLE` statements). Do not add speculative indexes or schema columns before profiling.

---

## 7. Recommended architecture

1. **Authoritative asset dimension:** `equipment` supplies canonical equipment IDs, labels, group and site. Avoid matching by raw backlog labels in reporting except as a quality fallback.
2. **Authoritative actual downtime/breakdown:** breakdown work-order event intervals and `breakdown_event_id`, processed by `kpi_helper.php` / `Kpi_engine_model`. Work-order records and merged event intervals are the source of actual unplanned breakdown downtime. Do not sum backlog ages, WO counts, or separate planned-forecast rows into this value.
3. **Preventive work:** use PM schedules/completions for planned maintenance context and linked preventive WO sessions for labor. Keep machine-unavailable elapsed time separate from technician hours unless a business rule deliberately equates them.
4. **Backlog contribution:** link a relevant backlog defect to the WO and expose description/observation, priority, status, and closure/verification as event context. Backlog does not create a second outage or breakdown count.
5. **One calculation/report contract:** reuse the W36 report payload and common KPI engine for dashboard and weekly view. If a fleet dashboard needs a rolling window, call the same KPI engine with its chosen period; do not add a second formula implementation. Use central rollup helpers for fleet/type/location chart series.
6. **Historical reproducibility:** use existing `weekly_report_snapshots` publication workflow for approved weekly periods. Clearly distinguish live recalculation from the published frozen report. Do not imply that a late-created snapshot recovers historical equipment state.
7. **Downtime logging UX:** add entry/edit/restore actions to the existing work-order flow (and link Backlog when applicable). Make the form validate equipment, actual start, classification, narrative/reason, and end/restoration or open state; record actor/audit details in existing WO status history/events where appropriate. Use existing event identity semantics. Only add a table/field if the live audit proves the current workflow cannot safely represent a required fact.
8. **No duplicate availability module:** integrate report navigation and visual components into existing Reports/W36, not a new source-of-truth module. Retain Backlog and Workshop Weekly workflows.

### Smallest reliable change

After live data and business rules are validated, the smallest likely implementation is: (a) close any WO/event data-quality defects; (b) make W36's calculation payload the sole source for dashboard and weekly output; (c) add a responsive dashboard presentation and filters over that payload; and (d) extend the existing WO entry workflow for dependable downtime logging. No new database structure is justified by source inspection alone.

---

## 8. Implementation roadmap

### Phase 1 — Data integrity and calculation policy

- Profile deployed records read-only using approved, repeatable SQL; provide counts and completeness/invalidity rates by period and by equipment group.
- Confirm open-hours schedule, PM denominator rule, breakdown count mode, whether carried-in failures count, target thresholds, timezone, closure meaning and planned/unplanned taxonomy.
- Reconcile `is_breakdown`, WO type, `breakdown_event_id`, equipment current status, start/end timestamps, stored `downtime_hours`, backlog WO link and session hours. Repair records through existing audited workflows; preserve originals.
- Confirm whether historical reports have snapshots and decide how to label older reconstructed periods.

### Phase 2 — Backend integration and KPI service

- Make one report/KPI payload the contract for fleet dashboard, weekly report, details, charts and exports.
- Reuse `Kpi_engine_model` and `W36_report_model`; remove any remaining “null => 100%” or separate weighted/unweighted KPI calculations after tests establish the intended behavior.
- Ensure all consumers use the same period boundaries, published/live snapshot mode, timezone and target resolver.
- Add clear data-quality warnings for invalid/incomplete event intervals and report-level validation gates. Tune indexes only after query plans and row volumes are measured.

### Phase 3 — Fleet Availability Dashboard

- Integrate dashboard as an existing Reports/W36 view, not a separate data module.
- KPI cards: availability and target delta, union downtime, distinct breakdown events, MTBF, MTTR; distinguish no-data/not-applicable.
- Use approved daily series, target comparison, type/location rollups, and worst-performing equipment table. Filters should select week/range, group/site, asset, and data-quality/below-target states.
- Link chart/table selection to the weekly report and equipment/work-order detail. Render no fabricated or static metrics.

### Phase 4 — Weekly Report

- Extend W36 presentation to the reference's compact hierarchy and daily availability strip; retain ISO week navigation, expandable equipment rows and data-quality notices.
- Align Workshop Weekly's observation/status content with W36's event data; keep its existing export/workflow only if it remains distinct and useful.
- Add consistent fleet/type summaries and an explicit “live” versus “published” indicator. Provide no-data/target-unresolved states.

### Phase 5 — Log Downtime

- Extend existing Work Order creation/update: choose equipment, breakdown/PM classification, start, ongoing/restoration time, reason/cause/narrative, and linked backlog. Ensure an update to a restoration/end time recalculates the event interval under existing rules.
- Preview KPIs from the same engine before save, but do not persist preview arithmetic as a parallel KPI record.
- Validate time ordering, overnight and cross-week events, duplicate event linkage, concurrent edits, permissions, and audit trail. Never make a PM WO automatically a planned-downtime event absent explicit evidence.

### Phase 6 — Export, validation, and testing

- Reuse W36 `.xlsx` and print/PDF routes and ensure selected week/snapshot mode matches the visible report.
- Test deterministic published downloads/checksum; compare dashboard, weekly view, PDF and Excel from identical payloads.
- Build KPI fixtures for overlapping WOs, split repair work orders, carried-in/outage across week boundaries, open events, zero required time, zero failures, sessions, PM and unplanned classifications, timezone/local midnight, no target, missing event ID, and status disagreement.
- Pilot against a manually reviewed operational week; reconcile calculated event-by-event totals with the maintenance team before release.

---

## 9. Acceptance criteria

1. **Source traceability:** every displayed KPI can be traced from the view to the shared KPI payload and source rows; no hard-coded reference values are used.
2. **Fleet population:** selected week includes only approved reportable active assets per documented policy; exclusions and missing group/site are visible.
3. **Calculation consistency:** dashboard, weekly table, W36 preview, PDF and Excel display identical values (within documented rounding) for the same week and live/published mode.
4. **Correct availability:** approved open-hours and PM policy is displayed/documented; numerator/denominator can be independently reproduced from required time and clipped union downtime; zero-required periods are “not applicable,” not 100%.
5. **Correct downtime:** overlapping/touching intervals count once; events crossing week boundaries are split at local reporting boundaries; open events stop at the same published/live cutoff in all consumers.
6. **Correct failures:** split work orders sharing one event ID count as one failure; backlog items and ordinary PM work are not counted as breakdowns; unresolved classification or missing links create a visible warning.
7. **Data integrity:** invalid/reversed intervals, missing start/end where required, orphan equipment/session/WO references, event ID collision, and status-vs-open-WO disagreement are detected and reportable.
8. **Planned/unplanned semantics:** actual planned outage, PM labor and unplanned breakdown are separately defined and not conflated. Any unavailable split is displayed only when its source evidence is sufficient.
9. **Historical behavior:** users can select prior ISO weeks. Published reports return the identical frozen payload/checksum; unpublished reports are clearly labeled live and use stated current-asset limitations.
10. **Downtime logging:** user actions persist through existing WO workflow, record actor/history, support ongoing and restored events, validate dates/times, and link to backlog when applicable; a refresh does not erase an event.
11. **No fabricated metrics:** missing, unresolved, or zero-denominator data is rendered as unavailable/unknown with a useful explanation, not placeholder zero or full availability.
12. **Responsive/accessible UX:** keyboard and screen-reader accessible navigation, filters, charts/tables, event form and detail panel; no content loss at mobile widths.
13. **Live pilot reconciliation:** for an explicitly approved sample week, a maintenance reviewer signs off per-equipment event intervals, breakdown count, required hours, preventive hours and fleet/group rollups.

---

## 10. Final decision

- **Can the target experience be achieved using existing data?** The reporting architecture and most calculations can be built from existing WMS structures. The reference's static example figures cannot be reproduced as genuine live values, and this audit cannot certify deployed data sufficiency because no live database connection was available.
- **Can it be achieved with minor improvements?** Likely yes for a reliable dashboard and weekly report if deployed work orders, interval timestamps, event IDs, sessions, equipment links and settings are sufficiently complete. The log-downtime experience requires a persistent extension to existing work-order entry/workflow and robust validations; it should not be a new independent event store.
- **Are significant database changes necessary?** Not established. Existing tables already cover assets, WOs, sessions, breakdown-event grouping, targets, plans, backlog links, and weekly snapshots. Defer schema changes until live profiling proves a required fact cannot be represented. Historical status/effective fleet reconstruction or actual planned machine downtime may be genuine gaps, but should first be tested against current event history and business needs.
- **What can be implemented immediately after audit approval?** UX/report integration and reuse of W36's existing report payload, date navigation, quality panel, charts, print/PDF and Excel flow—once the calculation policy and live data audit are signed off. A dashboard must remain blocked from claiming validated actual availability until those checks pass.
- **What depends on additional data capture?** Reliable event starts/restoration times, event linkage and classification where current WOs are incomplete; actual scheduled outage duration if required distinct from labor and forecasts; and historical asset/status attribution if reconstruction is required before snapshots.
- **Recommended first implementation step:** run a **read-only production data profile and KPI reconciliation for one recent completed week**, using W36 as the baseline. Return a per-asset ledger of included WOs/events, clipped duration, preventive session hours, required hours, target, status conflicts and exclusion reasons; reconcile that with the workshop owner and approve the open-hours/PM/count/timezone rules. Then decide whether the small integration is safe and whether any data capture/schema gap is real.

**Bottom line:** reuse Backlog for defect context, Work Orders and event IDs for actual breakdowns, sessions/PM records for maintenance evidence, and Weekly W36/KPI Engine for calculations, report selection, exports and historical snapshots. Integrate the reference's UX into those capabilities; do not copy its mock numbers or create another downtime/availability source of truth.
