# Reports navigation and enterprise UX audit

**Date:** 2026-11-04\
**Branch reviewed:** `arena/b7cd808f-wmsv2` at `178765ff15c2a8ca51c85630cc3769610b9d1623`\
**Scope:** the Reports experience and its operational hand-offs, not a redesign of the whole application\
**Verdict:** **not yet world-class enterprise UX; several strong foundations, but reporting trust and navigation coherence need correction before executive sign-off**

---

## 1. Executive verdict

The implemented suite is broader and more capable than a typical internal workshop tool. The canonical weekly report, Reports hub, Re-order report, and newer Daily/Asset/Service Kit tables contain genuinely useful enterprise patterns. They should be retained.

The product nevertheless behaves like a collection of independently built reports rather than one coherent reporting system. Three navigation models compete: a flat 15-link Reports sidebar, a five-group hub with 21 launchers, and local fleet/report links. Reports, administrative tasks, planning destinations, and operational data entry are mixed together. Period controls, export scope, table behaviour, responsive treatment, and drill-down paths vary substantially by screen.

Most importantly, this is not only visual debt. The Daily and Annual outputs contain contradictory or misleading metrics; some register totals do not use the same filters as their rows; and currency/unit semantics are not safe in every output. **A beautiful interface over those defects would still be poor enterprise UX.** Users must be able to trust that a number means the same thing on screen, in Excel, in print, and in the next reporting period.

### Release judgement

- **Operational use:** continue using the stronger weekly and Re-order workflows, with the existing disclosures.
- **Daily/Annual management sign-off:** do not treat the affected figures as authoritative until Critical items C-01 to C-04 are resolved and reconciled.
- **Design direction:** do not commission an aesthetic redesign. First fix the reporting contract, then simplify navigation and converge existing screens onto shared report-shell primitives.
- **Executive suitability:** currently weak. Executives need consistent definitions, explicit units/currencies, credible no-data states, and a short path from an exception to evidence. Those needs outweigh additional charts.

### Source-based scorecard

These are implementation-review ratings, not user-satisfaction measurements.

| Dimension | Rating | Reason |
|---|---|---|
| Findability and navigation | **Weak** | Fifteen flat global links compete with 21 hub launchers; icon-only navigation has discovery and input-method problems. |
| Report information architecture | **Mixed** | The hub taxonomy is useful, but consumption, planning, administration, and similarly named reports overlap. |
| Operational workflow continuity | **Mixed** | Weekly close is comparatively connected; Daily, planning, reliability, and store workflows often lose context or stop at a table. |
| Visual and interaction consistency | **Mixed** | Shared Dashboard tokens are visible, but many screens introduce bespoke layouts, filters, tables, and output rules. |
| Mobile and accessibility | **Weak** | Some newer views are carefully designed; the shared shell and many older report views lack equivalent responsive, keyboard, contrast, and motion treatment. |
| Reporting trust and output parity | **Weak** | Daily/Annual definitions, thresholds, no-data handling, register scope, and financial units can disagree or mislead. |
| Reusable foundations | **Strong in pockets** | Canonical W36, Reports hub, Re-order, Daily, Fleet Availability, Asset Register, and Service Kits provide patterns worth reusing. |

## 2. Review method and evidence limits

### Included

The review traced the actual implementation of every requested entry:

- All Reports
- Workshop Daily
- Workshop Weekly
- MTTR / MTBF
- PM Due Forecast
- Monthly Maintenance Plan
- Lubricant Compliance, including its yearly view
- Store Analytics
- Issue Register
- Receipt Register
- Re-order Report
- Asset Register
- Annual Report
- Annual Plan
- Service Kits

Related destinations were inspected only where necessary to understand real workflows: Daily Activity, Weekly Snapshot, Monthly Summary, Materials Movement, Equipment History, Availability Targets, Fleet Availability, Downtime Log, the canonical W36 screen, exports, work orders, PM, materials, orders, and role checks. The Dashboard was used as the shared visual-system reference.

### Verification performed

The following existing Node structural suites passed:

1. `tests/reports_hub.js` — including its 46-endpoint hub checks
2. `tests/workshop_daily_ux.js`
3. `tests/workshop_weekly_canonical.js`
4. `tests/page_titles.js` — covering 88 rendered views
5. `tests/service_kits_ux.js`
6. `tests/reorder_ux.js`
7. `tests/fleet_availability_ux.js`
8. `tests/weekly_report_visual_consistency.js`
9. `tests/weekly_w36_table_ux.js`

### Limits

PHP and MySQL executables are not installed in the review environment, and no authenticated browser runtime was available. Therefore:

- findings about source logic, routes, permissions, markup, styles, and export builders are source-verified;
- no claim is made that PHP integration tests, database results, rendered visual appearance, or authenticated end-to-end browser scenarios were executed;
- responsive and interaction findings identify implementation risks and deterministic source behaviour, but still require device/browser acceptance testing;
- business owners must approve any change to KPI or planning definitions before implementation.

The active canonical weekly screen is `application/views/reports/weekly_w36.php`; `Reports::workshop_weekly()` and its aliases delegate to it. The older `application/views/reports/workshop_weekly.php` is not the active screen. Conversely, `_fleet_nav.php` is a legitimate include and `pdf_daily.php` is directly loaded, so neither should be misclassified as dead code.

---

## 3. What should be preserved

A disciplined redesign starts by protecting the parts that already reduce effort and risk.

| Existing pattern | Why it is valuable | Direction |
|---|---|---|
| Canonical W36 builder and aliases | Screen and exports are based on a shared report payload; live/published states, checksum-backed snapshots, and reproducible downloads support governance. | Keep the canonical model, routes, and aliases. Improve orientation and action labels rather than replacing the report. |
| W36 data-quality and exception actions | Users can understand missing/late data and move towards corrective work, including downtime and WO paths. | Preserve week context through every corrective journey. |
| Reports hub taxonomy and context | Five groups, period-aware launchers, publication status, accessible SVG trend, and explicit export context are more useful than a bare link directory. | Keep the taxonomy; make finding a report the primary task and operational summaries subordinate. |
| Re-order report | URL-driven state, server-backed behaviour, sorting, pagination, cards/table modes, filter chips, useful empty states, full-scope totals, keyboard focus, and explicit Excel scope disclosure. | Use as the principal data-table reference. Do not create another incompatible table system. |
| Workshop Daily table controls | Server-backed tabs, search/type filters, sorting, pagination, table/cards, and equipment links reduce effort on a large fleet. | Preserve these mechanics while correcting the underlying status/KPI semantics. |
| Fleet Availability, Asset Register, Service Kits | Useful local responsive/filtering patterns and contextual equipment links already exist. | Reuse selectively after runtime validation; avoid rewriting working functionality. |
| Shared Dashboard visual language | Quiet light surfaces, consistent tokens, tabular numerals, familiar cards, buttons, and tables give the product a coherent baseline. | Extend the existing tokens and primitives; do not introduce a second design theme. |
| Store Analytics currency grouping | Supplier spend is grouped by currency rather than summed into a meaningless cross-currency total. | Apply the same policy to other financial reports and exports; do not invent exchange-rate conversion. |

---

## 4. Sidebar and navigation audit

### 4.1 Hierarchy: fifteen equally weighted destinations is not a useful task hierarchy

**Problem.** The Reports section in `sidebar.php:213-291` exposes 15 links at one level: the hub plus 14 report/task destinations. Daily operational work, executive performance, planning, store ledgers, and asset administration all receive the same visual weight. The hub then reorganises the domain differently into five groups and 21 launchers, while Fleet Availability/Downtime have their own local navigation.

**Why this is a UX problem.** Users must learn three mental models and repeatedly scan labels that do not reflect their current task. A supervisor closing a week, a planner preparing services, and a manager reviewing annual performance should not need to parse the same flat inventory.

**Operational impact.** More navigation effort, weaker orientation, greater dependence on memory, and a sidebar that becomes harder to use as more reports are added.

**Improvement.** Make **Reports one global destination**. Put categories and individual reports in the Reports hub and local report navigation. If frequent-switching research later proves a need for sidebar children, use one collapsible level with task groups—not a permanent 15-item list.

### 4.2 Naming does not consistently describe intent or distinguish siblings

- **Daily Report** in the sidebar means Workshop Daily, while the hub also offers **Daily Activity**, a different WO/attendance view. The visible sidebar name does not resolve that ambiguity.
- **Weekly Report** means canonical W36, while **Weekly Snapshot** is another work/attendance/KPI screen. Their names imply duplication without explaining scope.
- **PM Due Forecast** is the clearest visible label, but its route/title attribute still says Availability Forecast. The screen correctly points users to Planned Availability for availability prediction; retain that distinction.
- **Store Analytics** primarily presents supplier spend and route lead times. Its tooltip, “Supplier Spend & Lead Times,” is more accurate than its persistent label.
- **Annual Report** versus **Annual Plan** does not communicate “performance” versus “PM due schedule” until the user opens them.
- **Add Register** in Asset Register should be **Add Equipment**; a register is the collection, not the entity being added.

Use names based on the user’s question: *What happened today?*, *Close the weekly report*, *Why is reliability changing?*, *What PM is due?*, *What was issued/received?*, and *What needs replenishment?* Do not rename routes merely for tidiness; visible labels and aliases can change while URLs remain stable.

### 4.3 Active-state ownership is fragile

`ni_active('reports')` is a prefix test, and the hub link compensates with 12 manual exclusions (`sidebar.php:4-8,217`). Annual Report, Annual Plan, and Service Kits are not excluded, so their own item and the general Reports item can both appear active. Other unlisted routes inherit different behaviour depending on whether their prefix happens to be excluded.

This is an information-architecture defect, not just a styling detail: the active item is the user’s primary “where am I?” cue.

Replace the exclusion chain with an explicit route-to-navigation-owner map. Every report route, alias, and related local screen should resolve to exactly one current destination and expose `aria-current="page"`.

### 4.4 Collapsed navigation is weak for keyboard, touch, zoom, and confidence

The deterministic source behaviour is:

- labels and section headings are hidden until `.expanded`;
- expansion is triggered only by `mouseenter`/`mouseleave` (`sidebar.php:652-665`);
- there is no equivalent focus/click expansion for the main sidebar;
- at widths up to 760px, expansion is disabled, leaving the icon rail;
- tooltip appearance is hover-only and `.ni` has no sidebar-specific focus-visible treatment;
- tooltips are positioned outside the rail while both `#sidebar` and `.nav-wrap` clip overflow (`header.php:98-106,129-184`), creating a real clipping risk;
- desktop expansion changes the canvas margin from the rail width to 224px, moving the report content while the user is trying to aim at a link;
- the long navigation uses a hidden scrollbar, making additional off-screen destinations less discoverable.

This does **not** mean every icon is unclickable on touch. It means users cannot reliably discover what the icons mean before committing, keyboard users do not get the expanded label model, and narrow-screen users are left with the weakest version of the navigation.

**Improvement.** Provide an explicit, keyboard-operable menu/disclosure control with `aria-expanded`; show labels on focus as well as hover; use `aria-current`; keep visible focus indicators; and use an overlay rather than pushing report content. On narrow screens, use a labelled off-canvas menu with Escape, backdrop, and sensible focus return. A visible scrollbar or equivalent overflow cue is preferable to hiding the existence of more items.

### 4.5 Role visibility does not match store and executive tasks cleanly

The Reports sidebar is limited to Supervisor and Manager. Store Keeper and Technician do not see it; CEO has a separate dashboard. Reports also contain or link to unlike tasks:

- Availability Targets is configuration/write work.
- Asset import and Add Equipment are administration/write work.
- Weekly naming/publication is a controlled close process with explicit Supervisor/Manager exceptions.
- Issue stock is an operational write task, not report consumption.

Role restrictions may be intentional and should not be removed for convenience. However, a Store Keeper who must work through inventory while Managers consume issue/receipt/reorder reports has a fragmented workflow, and executives should not be given an unreliable Annual Report merely to make Reports visible.

**Improvement.** Define a task/permission matrix for read-only report access separately from write actions. Consider narrowly scoped Store read access to store reports and contextual Technician access to equipment history only after approval. Keep imports, target changes, publication, stock issue, and equipment creation under their existing ownership rules. Do not grant blanket Reports access as a shortcut.

### 4.6 Suitability by workshop role

Planner and reliability engineer are task personas here, not a recommendation to invent new RBAC roles. Map them to approved existing roles/permissions before changing access.

| Role/persona | Current fit | Principal gap | Required experience |
|---|---|---|---|
| **Technician** | Appropriately shielded from a large global report catalogue, but equipment evidence can be useful during assigned work. | Reports are absent rather than contextual; a technician who needs history must rely on another role or leave the work context. | Keep broad Reports restricted. If approved, provide equipment/WO-context history for assigned work without exposing administration or executive reporting. |
| **Workshop Supervisor** | Daily and canonical weekly destinations support shift review and weekly close; W36 publication/data-quality handling is comparatively strong. | Flat navigation, Daily trust defects, dense filters, and mixed admin/report tasks weaken a controlled operational routine. | A short Operations home: today, current/previous week, data quality, publication, and valid corrective actions. |
| **Maintenance Planner** | Forecast, monthly/annual schedules, lubricant compliance, and kits cover useful planning inputs. | Related horizons are separate terminal reports; due items do not consistently lead to asset/schedule/WO action and back. | One Maintenance Planning family with preserved horizon/month/year context and reliable next actions. |
| **Reliability Engineer** | MTTR/MTBF and annual trends expose relevant measures and per-equipment summaries. | Null/zero ambiguity, limited filtering/export, and weak event/WO drill-down prevent efficient root-cause investigation. | Shared KPI definitions, honest no-data states, period/equipment filtering, and equipment → event/WO evidence. |
| **Store Keeper / Procurement** | Re-order is strong; issue/receipt and supplier data cover useful operational records. | Reports are not visible to the Store role, the Issue stock action is invalid, scopes/currencies differ, and related tasks are fragmented. | Approved read-only Inventory & Procurement access, valid item-selected write paths, consistent filter/export scope, and per-currency/unit totals. |
| **Maintenance Manager** | Can move across daily, weekly, planning, store, and annual reporting; weekly publication access is explicit. | Too many equivalent destinations and inconsistent metrics/output rules increase review effort and sign-off risk. | Role-relevant Operations/Performance summary, trusted exception paths, and unambiguous live/published/full-scope outputs. |
| **CEO / Executive** | A separate executive dashboard exists and Annual Report attempts a yearly summary. | Annual correctness/currency defects and weak evidence links make the current report unsuitable for unqualified sign-off. | A restrained corrected Annual Performance view with explicit basis, comparable periods, and a short path to monthly/equipment evidence—not another dashboard. |
| **Asset/Data Administrator** | Asset import/template and equipment records are available with role checks. | Administration is embedded in a reporting destination and two register concepts can be confused. | Equipment-owned master-data tasks, clear authoritative register naming, and a return link to the read-only Asset Record. |

---

## 5. Report information architecture review

### 5.1 Requested entries

| Current entry | Actual user value | Main UX/IA issue | Recommended disposition |
|---|---|---|---|
| **All Reports** | Reporting home, telemetry, exceptions, publication state, trend, report library, quick exports. | The operational dashboard precedes report finding and duplicates Dashboard-like content. | Retain as the single Reports landing page; put the library first or provide clear Library/Overview ordering. |
| **Workshop Daily** | Fleet status/readiness, engine availability, WO context, full-day Excel/PDF, rich filtering. | Historical/current status and KPI/output contradictions (C-01/C-02). | Keep the newer table mechanics; correct semantics before further polish. Label it **Workshop Daily** everywhere. |
| **Workshop Weekly** | Canonical W36 close, detailed machine/group/fleet KPIs, publication, data-quality action, exports. | Dense sections and independent filter bars; live/published export actions can produce different readings despite disclosure. | Retain as the weekly workspace, with Fleet Availability and Downtime Log as local tasks. |
| **MTTR / MTBF** | Fleet trend and per-equipment reliability for a selected number of months. | Nulls become zero; no equivalent rich filtering, export, or equipment/WO drill-down. | Rename visibly to **Reliability (MTTR / MTBF)**; correct no-data semantics and add contextual evidence links. |
| **PM Due Forecast** | PM schedules due within 30/60/90/180 days. | It is not availability forecasting; rows are largely terminal and planning siblings are disconnected. | Keep the calculation; label **PM Due — Next N Days** and link assets/schedules where permitted. |
| **Monthly Maintenance Plan** | Monthly PM and corrective/open WO plan, Excel output. | Static destination with limited filtering/actionability; overlaps forecast and annual schedule. | Keep as the monthly planning view within a coherent planning family. |
| **Lubricant Compliance** | Monthly and yearly usage versus recommendation, variances, non-compliance. | Month/year split, limited large-table controls and equipment/WO evidence paths. | Retain both routes/views, connected by clear Month/Year navigation and consistent filters. |
| **Store Analytics** | Supplier spend by currency, order KPIs, discrepancy rate, learned route lead times. | Name promises broader store analytics; no direct supplier/order drill-down; mixed year/all-time scopes require care. | Rename **Supplier Spend & Lead Times** and link to existing order/supplier evidence. |
| **Issue Register** | Filterable stock issue ledger and allocation summary. | Broken Issue stock hand-off; totals/allocation/table scopes can disagree; large-result cap. | Keep as a Stock Movements view; fix scope before adding polish. |
| **Receipt Register** | Filterable goods-receipt ledger, shortage indication, Excel. | Value and unit price lack clear currency semantics; totals/filter and truncation issues. | Keep as a Stock Movements view; make financial and export scope explicit and correct. |
| **Re-order Report** | Actionable low-stock/out-of-stock scope with calculated quantities. | Excel excludes the status tab, but this is explicitly disclosed; hand-off is to general Spare Orders. | Preserve as the reference implementation. Improve order hand-off only where existing data supports it. |
| **Asset Register** | Rich fleet register, lifetime KPIs, import/export, filtering and equipment links. | Read report and import/create administration are mixed; relationship to Equipment Register is unclear; print scope differs from visible view. | Keep read-only Asset Records in Reports; locate administration in Equipment with permission-aware links. |
| **Annual Report** | Executive yearly KPI, trend, equipment, fault, lubricant, and spend summary. | Misleading PM/rollup metrics, hard-coded currency, threshold/output inconsistency, weak drill-down. | Correct and reconcile before executive use; position under Performance, not Reliability/Planning. |
| **Annual Plan** | Active PM schedules whose current `next_due_date` falls in the selected year. | It is not necessarily a recurrence-generated annual plan; static rows and no planning hand-off. | Label **Annual PM Due Schedule** until recurrence behaviour is explicitly specified and implemented. |
| **Service Kits** | Service-kit recommendations, search/filter/sort/pagination/cards, Excel and print. | Export all types and visible-page print are disclosed but different in scope; downstream material/order action is limited. | Preserve the improved table patterns and disclosures; connect only reliable existing material/order relationships. |

### 5.2 Related pages create avoidable overlap

These are not requests to delete working functionality. They are candidates for clearer naming, local grouping, and later consolidation after route-usage and data-parity review.

| Related destination | Relationship problem | Better placement |
|---|---|---|
| Daily Activity | WO/attendance focus is obscured by the generic name Daily Report. | **Work & Attendance — Daily**, within Operations; do not confuse with fleet Workshop Daily. |
| Weekly Snapshot | Another “Weekly Report” with a different content contract. | **Work & Attendance — Weekly**, or merge only after parity review; never substitute it for canonical W36. |
| Monthly Summary | Monthly performance overlaps Annual Report’s monthly table and MTTR trends. | Place Monthly, Reliability, and Annual under Performance with a shared KPI basis and explicit scope. |
| Fleet Availability and Downtime Log | Related weekly tasks appear as separate hub launchers but already have local navigation. | Keep as local Weekly Report tabs/actions, not additional global sidebar items. |
| Availability Targets | CRUD/configuration presented among reports. | Settings or Weekly Report → Targets for authorised Supervisors; retain POST and role rules. |
| Materials Movement | Complements Issue and Receipt registers but has a separate mental model. | Stock Movements local views: Issues, Receipts, Stock on Hand; preserve existing routes. |
| Equipment History | Requires an asset context and is not a natural peer of Annual Report. | Asset Register row/profile → Equipment History; optionally list under Asset Records with an equipment picker. |
| Asset import / Add Equipment | Administrative actions inside a reporting destination. | Equipment administration, with return links to the read-only register. |

### 5.3 Recommended information architecture

Use five task groups, preserving the existing hub’s useful taxonomy while separating performance from planning:

1. **Operations**\
   Workshop Daily; Workshop Weekly; Fleet Availability; Downtime Log; Work & Attendance (Daily/Weekly).
2. **Performance & Reliability**\
   Monthly Performance; Reliability (MTTR / MTBF); Annual Performance.
3. **Maintenance Planning**\
   PM Due Forecast; Monthly PM Plan; Annual PM Due Schedule; Lubricant Compliance; Service Kits.
4. **Inventory & Procurement**\
   Supplier Spend & Lead Times; Replenishment; Issues; Receipts; Stock on Hand.
5. **Asset Records**\
   Asset Register; Equipment History.

Administration remains available through explicit permission-aware links in its owning module. Quick exports remain secondary to report selection, not a third competing navigation system.

---

## 6. End-to-end workflow and interaction audit

### 6.1 Daily review: from fleet exception to evidence and action

**Current path.** Reports/sidebar → Workshop Daily → choose date → use tabs/search/type/sort/page → open an equipment profile or download the full day.

**What works.** The newer server-backed table mechanics are appropriate for a large fleet. Equipment links create an evidence path. The toolbar explicitly states that Excel/PDF export the full day rather than the current filters (`workshop_daily.php:201`); the scope is not undisclosed.

**Where it fails.** A historical date can still use current equipment status and currently open WOs. Any selected open/in-progress/waiting WO can make the machine Down, irrespective of whether that WO establishes a breakdown outage. The readiness ratio is labelled Overall Availability while engine-based availability appears elsewhere. Null availability becomes zero on screen/charts. These defects undermine the entire review-and-act loop.

**Target workflow.**

1. Open Workshop Daily with an explicit date, fleet population, data basis, and target visible.
2. See **Equipment readiness** and **time-based availability** as separate, correctly labelled measures.
3. Filter to exceptions without changing the definition or population unexpectedly.
4. Open the equipment/WO evidence and return to the same date, filters, sort, and page.
5. Choose an explicitly labelled **Full fleet export** or a filter-preserving export whose behaviour is approved; never silently change scope.

### 6.2 Weekly close and publication: the best workflow, with avoidable mode ambiguity

**Current path.** Select week → inspect fleet and machine detail → investigate data quality → log downtime/create corrective WO → filter sections → export → publish → revisit the frozen snapshot.

**What works.** Shared canonical data, publication state, reproducibility, data-quality messages, expandable details, and corrective actions are strong. Screen-only filters are explicitly described as not changing counters or exports.

**Where it creates effort.** Three independent filter toolbars and initially collapsed groups require repeated orientation. When a published week is viewed live, the UI discloses that Excel downloads the published report while Print/PDF uses the live reading (`weekly_w36.php:578-599`). Disclosure prevents concealment, but adjacent export actions still represent different versions.

**Target workflow.** Keep section-scoped filters where their criteria differ, but add clearer section navigation, persistent reading-mode context, and expand/collapse orientation. Name actions according to their actual source: **Download published Excel**, **Print live draft**, or **Download this live reading** only if a live workbook is intentionally supported. The user should never need to read fine print to discover that two adjacent exports represent different report versions.

### 6.3 Performance review: Monthly, Reliability, and Annual are not one coherent family

Monthly Summary, MTTR/MTBF, and Annual Report use related availability/reliability concepts but have different controls, no-data behaviour, table capabilities, and export support. Annual’s top-level rollup does not consistently use the same engine basis as its monthly figures. Worst Performers and recurring faults stop at text rather than equipment/WO evidence.

A manager should be able to move **year → month → equipment → WO/event** while preserving period context. That does not require a new analytics platform: add contextual links to existing equipment and work-order screens and make period definitions explicit.

### 6.4 Maintenance planning: related schedules, disconnected decisions

PM Due Forecast, Monthly Plan, and Annual Plan all draw on current due-date/schedule concepts at different horizons. They are presented as separate terminal reports rather than a planning sequence. Monthly/Annual rows do not provide consistent equipment, schedule, or WO actions. Service Kits then sits separately from replenishment and orders.

**Target workflow.** Review due work → open asset/schedule evidence → perform the existing PM/WO action → return to the same horizon/month/year → verify required kits/materials → continue to existing inventory/order tasks where reliable mappings exist.

Do not imply that Annual Plan projects every recurrence until that business rule is implemented and approved. Until then, “Annual PM Due Schedule” is more honest.

### 6.5 Store and procurement: the most fragmented role workflow

Issue, Receipt, Materials Movement, Re-order, and Supplier Analytics answer related questions but do not behave as one workspace. Store Keepers lack the Reports navigation; Managers see the reports; and Issue Register’s **Issue stock** button points to `materials/issue` without the item ID required by `Materials::issue($id)` (`Materials.php:723-727`). That action cannot complete the intended task as linked.

Re-order is the exception: its filtering, totals, empty states, and disclosures are strong. It should be the starting point for convergence, not another redesign target.

**Target workflow.** Use local Stock Movements views with shared period/filter behaviour. Preserve those filters in rows, KPIs, allocation summaries, and exports. Replace the invalid Issue action with a valid existing item-selection/issue path. From Replenishment or supplier evidence, continue to existing order screens with only reliable, permission-aware context.

### 6.6 Asset records: good register mechanics, unclear administrative boundary

Asset Register now has useful search/filter/sort/pagination/cards and equipment links. Those should stay. Import, template, and Add Equipment are administration tasks embedded in the same destination, while an Equipment Register already exists elsewhere. Printing forces all rows into table view, unlike Service Kits’ explicitly visible-page print.

Separate **view asset record** from **maintain asset master data**, clarify which register is authoritative, and make print scope explicit. Do not duplicate equipment administration in Reports.

### 6.7 Executive review: currently not decision-safe enough

The Annual Report offers the right general category of summary, but its PM, rollup, currency, and drill-down defects make it unsuitable for unqualified executive sign-off. More KPI cards or more chart decoration would worsen the problem.

The executive path should be a restrained Annual Performance view with a visible reporting basis, comparable periods, unambiguous units/currencies, no-data honesty, and a small number of links into monthly/equipment evidence. Reuse the corrected existing report rather than building another executive dashboard.

---

## 7. Visual, responsive, accessibility, and international usability review

### 7.1 Visual consistency

The Dashboard provides a reasonable baseline: shared colour/type/spacing tokens, light surfaces, compact cards, and consistent table/button primitives. The Reports hub, Re-order, canonical W36, Fleet Availability, and Service Kits then introduce substantial local design systems, while older screens rely heavily on inline styles and Bootstrap-like class remnants.

The issue is not that every page must look identical. W36 legitimately needs a specialised operational grid. The issue is that equivalent tasks—choosing a period, filtering, seeing no data, exporting, understanding status—look and behave differently without a user benefit.

**Recommendation.** Standardise the report shell and semantics, not the internal layout of every report. Shared primitives should cover:

- page title, purpose, period/data basis, and local navigation;
- labelled filter bar with explicit Apply/Reset and URL state;
- KPI definition/value/unit/target/no-data treatment;
- responsive table region, sorting, pagination, and optional cards where comparison remains useful;
- live/published/filter/full-scope status;
- export/print actions with source and scope in the label or immediate description;
- empty, error, partial, stale, and no-qualifying-event states;
- print metadata and accessible chart alternatives.

### 7.2 Information density and hierarchy

The Reports hub puts telemetry, exceptions, and trend before the library. Annual presents eight KPI cards before the evidence. Several screens use very small secondary text and multiple competing status colours. Dense information is appropriate for specialists, but only when it is prioritised around a decision.

- Make **find/open a report** the hub’s first-class task; keep operational overview below it or clearly separated.
- Retain detailed W36 data, but improve section wayfinding rather than removing necessary columns.
- Use colour for status plus text/icon meaning; avoid arbitrary card colours that do not encode a decision.
- Replace repeated “0” summaries with **— / No qualifying data** where no calculation basis exists.

### 7.3 Period controls and export consistency

There are explicit Apply/Load controls on the hub and Daily, but unlabelled auto-submit controls on several Annual, Forecast, Lubricant, and Store screens, often without a non-JavaScript submit fallback. Some inputs use `title` or placeholder rather than persistent labels. Invalid or missing periods can fall back to the current period without an equally visible explanation.

Export behaviour is equally varied:

- Daily exports the full day and says so—retain the disclosure.
- Re-order Excel ignores the status tab but honours other filters and says so—retain the disclosure, then evaluate whether the rule matches user intent.
- Service Kits exports all types and prints the visible page, with disclosure.
- Asset print forces the full register into table mode.
- Canonical W36 can export published Excel while printing a live view, with disclosure.
- Store registers can cap rows at 5,000 while KPI totals cover the broader query, without a consistent export-truncation contract.

Standardise period/filter controls and output contracts before visual cleanup. **Disclosure is necessary, but it does not make an arbitrary scope rule intuitive.**

### 7.4 Tables and large datasets

Re-order and the newer Daily tables prove that the repository can support robust data-table behaviour. Many MTTR, forecast, plan, lubricant, store, and annual tables still lack equivalent search, sort, pagination, captions, scoped headers, or sticky context. Store registers render up to 5,000 rows rather than providing normal server pagination.

Do not mechanically convert every table into cards. Wide comparative reports need tables; cards can help on narrow screens when field priority is deliberate. Use horizontal scrolling inside an explicitly labelled table region, sticky identifiers where helpful, and a card alternative only when it preserves the task.

### 7.5 Responsive behaviour

The shared header’s small-screen rules principally adjust the top bar (`header.php:657-687`); there is no general small-screen collapse for shared four-column KPI grids/page padding. Many reports depend on their own local CSS, wide tables, and horizontal scrolling. Newer Reports hub, Re-order, Daily, W36, Fleet, and Service Kit rules are useful references, not proof that the whole module is responsive.

Required runtime coverage after remediation:

- 320, 375, 768, 1024, and wide desktop layouts;
- portrait and landscape on touch devices;
- 200% browser zoom and increased text size;
- long equipment IDs, fault text, supplier names, translated labels, and multi-currency values;
- no page-level horizontal overflow; intentional table-region scrolling only;
- filters, exports, pagination, disclosure controls, and return paths remain operable.

### 7.6 Accessibility

Source findings include:

- hover-only sidebar labels/tooltips and missing sidebar focus-visible treatment;
- active navigation not consistently exposed with `aria-current`;
- many report tables without captions or explicit scoped headers;
- unassociated labels and auto-submit period controls on several screens;
- small low-contrast secondary text;
- global reduced-motion CSS covers only selected header utilities, while cards, progress bars, and critical LEDs can still animate;
- canvas charts without a consistent accessible data alternative;
- some newer tab/filter patterns need full keyboard/screen-reader semantics, not just ARIA roles.

Calculated contrast against white is approximately **3.62:1 for `#86868b`** and **2.21:1 for `#aeaeb2`**; both are below 4.5:1 for normal text. These tokens are used for small labels and metadata. `#1c8c3c` is approximately 4.32:1 on white and also needs context-specific review for small text.

The Reports hub’s accessible SVG trend and Re-order’s captions, focus states, labels, and live regions are positive references. Accessibility remediation should reuse those examples rather than adding an overlay or separate accessible version.

### 7.7 International mining operations and low-bandwidth environments

- Dates vary between ISO, `d/m/Y`, and long display forms. Use an unambiguous display convention such as `04 Nov 2026`; retain machine-safe ISO values in URLs and structured exports.
- Quantities and financial values must always expose unit/currency. Do not sum pieces, litres, and other units into one unexplained “Total Qty,” and do not add currencies together.
- Exports should carry report title, selected period, filters/scope, site/company, generated timestamp and timezone basis, user where appropriate, units/currencies, and live/published state.
- Chart.js and Google Fonts are loaded from public CDNs in the shared header. A remote workshop with restricted or intermittent connectivity can lose chart rendering; unguarded `new Chart(...)` calls can then fail. Keep the system-font fallback, self-host critical front-end assets where deployment policy allows, guard chart initialisation, and provide the underlying table/text regardless of chart availability.

---

## 8. Prioritised recommendations

### Critical — reporting correctness and decision safety

These items justify calculation changes because the current presentation can mislead. They are not aesthetic redesign requests.

#### C-01 — Workshop Daily is not a reliable point-in-time fleet report

- **Problem:** `_build_workshop_daily()` uses all non-retired equipment, current equipment state, and WOs created on the date **or currently open/active with an earlier start** (`Reports.php:1939-1991`). It does not apply the weekly reportability flag. Any selected Open/In Progress/Waiting WO can set Down status.
- **Why UX/UI:** the date control promises a historical report, but the content is partly “now.” Status, observation, downtime, and availability no longer describe one coherent point in time.
- **Impact:** yesterday’s report can change when a WO is closed today; a machine can be shown down for non-breakdown work; daily and weekly fleet populations can disagree. Shift decisions and historical accountability are unsafe.
- **Improvement:** define and implement an approved as-of-date status rule; include only WOs/events relevant to that date and distinguish current status separately if needed; align or explicitly explain fleet membership versus W36. Preserve current-status monitoring as a separately labelled live view if required.
- **Acceptance:** closing a WO today does not retroactively rewrite a prior day except through an explicitly identified late-data correction; population and outage rules have fixture tests and reconcile with the approved reporting policy.

#### C-02 — Workshop Daily KPI and output contracts contradict each other

- **Problem:** the screen labels available-machine count as **Overall Availability** (`workshop_daily.php:99-136`), while PDF uses engine time-based availability (`Reports.php:2091-2097,2152-2156`). Screen/charts convert null availability to zero (`workshop_daily.php:49-68,147-161`). Screen/XLSX use fixed 90/70 thresholds while PDF uses resolved targets; targets are not shown on the main screen. XLSX’s overall KPI averages group percentages rather than using the fleet required-time rollup (`Reports.php:2380-2398`).
- **Why UX/UI:** identical labels and adjacent export buttons imply one metric and one threshold policy, but deliver different meanings and colours.
- **Impact:** a supervisor can see red/zero where data is merely undefined, sign off a different percentage in PDF, and compare unequal fleet groups incorrectly.
- **Improvement:** name count-based readiness separately; use the shared KPI engine for time-based availability; show target and basis; preserve null as no-data; use one presentation policy across screen, XLSX, PDF, and print; use the fleet rollup rather than an average of group percentages.
- **Acceptance:** fixtures cover no required hours, no WOs, unequal group sizes, custom equipment/group targets, and overlapping events. The same dataset yields the same labelled KPI and target status in every output.

#### C-03 — Annual Report management metrics are misleading

- **Problem:** monthly **PM Done** is `WO total − breakdowns` (`annual_report.php:102-116`; repeated in XLSX at `Reports.php:795-808`), which counts unrelated/open WOs as PM done. Top-level **PM Completed** counts all preventive WOs without requiring completion (`Dashboard_model.php:645-653,738-742`). PM compliance divides distinct serviced equipment by all schedule rows (`Dashboard_model.php:696-703,750-751`). Annual MTTR uses raw WO downtime/count rather than the engine’s union-based annual figures, and annual availability is a simple mean of monthly percentages (`Dashboard_model.php:724-746`). XLSX also uses fixed 90/70 colours (`Reports.php:795-823`).
- **Why UX/UI:** executive labels assert completion, compliance, and fleet performance that the calculations do not establish. Screen and workbook cues do not share one definition.
- **Impact:** incorrect PM performance, reliability, and availability can drive resourcing, bonus, budget, and board-level decisions.
- **Improvement:** obtain business approval for PM due/done/compliance denominators; derive reliability from the shared event engine; either compute an annual required-time rollup or label a monthly mean precisely; use one target policy; link Worst Performers to equipment evidence. Do not patch labels to conceal the calculations.
- **Acceptance:** monthly/annual PM and reliability values reconcile to approved source records and shared KPI fixtures; screen and XLSX agree; no-data is not presented as zero performance.

#### C-04 — Currency and unit semantics are unsafe in parts of the reporting suite

- **Problem:** Annual Parts Spend is prefixed with a hard-coded `$` (`annual_report.php:42-50`). Receipt Register shows aggregate value and row unit price without a currency, while the underlying rows carry currency. Store totals can sum values across currencies. Issue KPI “Total Qty” can combine unlike stock units. Annual monthly lubricant is labelled litres without a per-unit grouping in that monthly query.
- **Why UX/UI:** a number without its unit/currency is not a complete report value; a total across incompatible units is not a meaningful total.
- **Impact:** procurement and financial figures can be misread or invalid for TZS/USD/UGX operations; quantity summaries can be operationally meaningless.
- **Improvement:** use the existing currency vocabulary and `wms_money()`; show currency per row/export column; group totals by currency without inventing conversion; group quantities by unit or replace the aggregate with counts that are valid across items; reconcile Annual with the Store Analytics policy.
- **Acceptance:** no mixed-currency or mixed-unit aggregate is presented as one total; exports retain currency/unit; site currency changes do not require view rewrites.

### High — blocked tasks, inconsistent scope, and core navigation

| ID | Problem and why it is a UX issue | User/operational impact | Practical improvement |
|---|---|---|---|
| **H-01** | Flat 15-link sidebar competes with the 21-launcher hub and local navigation. Users must learn three structures. | Slow findability and weak orientation for every report role. | Make Reports one global entry; use the five task groups and local navigation in §9. Preserve routes/aliases. |
| **H-02** | Hover-only expansion, clipped tooltip risk, content reflow, hidden overflow cues, and manual active-state exclusions make navigation input-dependent and ambiguous. | Keyboard/touch/zoom users cannot discover labels confidently; multiple active items undermine location. | Explicit accessible menu/disclosure, overlay expansion, focus treatment, `aria-current`, route-owner map, visible overflow cue, narrow-screen drawer. |
| **H-03** | Reports, administration, and write tasks share destinations; role visibility does not support Store work cleanly. | Users see irrelevant tasks or cannot reach relevant read-only evidence; permission workarounds risk over-access. | Separate report read permission from task write permission; relocate admin actions; approve narrow role scopes rather than blanket access. |
| **H-04** | Issue Register’s **Issue stock** link omits the required item ID. The visible action cannot complete its task. | Dead end/404 and lost time during a stock transaction. | Link to the valid existing item-selection/issue workflow, or pass a validated selected item; retain write-role checks and add a route test. |
| **H-05** | Period controls, filter persistence, and export scope differ across reports. Auto-submit/no-JS behaviour and version-specific W36 exports add effort. | Wrong-period/full-scope downloads, repeated filter entry, and uncertainty about what was exported. | Shared labelled GET filter bar, explicit Apply/Reset, visible validation, return context, and output labels stating period/version/filter/full scope. Preserve good existing disclosures. |
| **H-06** | Store register rows, KPIs, allocation analytics, and exports do not use one complete filter/result contract. `register_totals()` omits reference/person and part-number search; allocation analytics apply only dates; rows/exports cap at 5,000. | Filtered KPI totals can disagree with visible rows; allocation charts ignore filters; exports can be silently incomplete. | Reuse one filter builder for all consumers; include part-number search; paginate server-side; show full result count; export the complete approved scope or explicitly block/warn on truncation. |
| **H-07** | Shared KPI grids/page shell and many older tables lack a general small-screen strategy. | Field and tablet users face squeezed cards, wide pages, hidden columns, and difficult filter/export controls. | Shared responsive report primitives, intentional table/card behaviour, and the device/zoom acceptance matrix in §7.5. |
| **H-08** | MTTR, PM plans, compliance, supplier analytics, and annual exceptions often terminate at text/tables; context is lost across related screens. | Users must copy machine/order IDs and reconstruct filters, slowing corrective action. | Add contextual equipment/WO/PM/order links where existing routes and permissions support them; preserve return period/filter state. Do not add action buttons without a valid next task. |
| **H-09** | MTTR/MTBF and some annual summaries convert null to zero (`Dashboard_model.php:318-401`), while other screens correctly use an em dash. | “No qualifying events” looks like zero-hour repair or zero performance, corrupting trend interpretation. | Preserve null end to end; show **No qualifying events/data**, use chart gaps, and export an empty value plus reason rather than numeric zero. |

### Medium — coherence, accessibility, and scalability

| ID | Problem and why it is a UX issue | Impact | Practical improvement |
|---|---|---|---|
| **M-01** | Hub telemetry/exceptions/trend precede the report library and duplicate Dashboard-like behaviour. | Users hunting a report scroll through another dashboard; two homes compete. | Put the library first or make Overview clearly subordinate; deep-link Dashboard exceptions into filtered reports. Keep existing telemetry where it supports weekly close. |
| **M-02** | Daily/Weekly/Monthly siblings and Plan/Forecast names overlap; Annual Plan is a current-next-due schedule, not necessarily a recurrence plan. | Users open the wrong report or assume broader coverage. | Apply the names/grouping in §5; consolidate only after usage and data-parity review. |
| **M-03** | Equivalent controls and states use extensive bespoke CSS/inline styles across report families. | Inconsistent behaviour and high maintenance cost; fixes must be repeated. | Introduce shared shell components/tokens incrementally, starting with filters/KPIs/tables/export metadata; leave specialised W36 internals intact. |
| **M-04** | Sidebar/table/form semantics, contrast, focus, motion, and chart alternatives are uneven. | Keyboard, low-vision, screen-reader, and motion-sensitive users face avoidable barriers. | Shared accessibility baseline, token contrast corrections, semantic tables/forms, reduced-motion coverage, keyboard tests, and text/table chart alternatives. |
| **M-05** | Print/export scope and provenance vary even when disclosed. | Printed/downloaded evidence is hard to compare or audit later. | Common output metadata block and action labels; align Asset/Service Kit/Daily/W36 behaviour to explicit approved contracts. Label CSV/XLSX/PDF accurately. |
| **M-06** | Public CDN charts/fonts and unguarded chart initialisation assume connectivity. | Blank charts and script failures in remote/low-bandwidth sites. | Self-host critical assets where possible, guard `window.Chart`, retain system fonts, and render data tables/text independently. |
| **M-07** | Several large reliability/planning/lubricant/annual tables lack the search/sort/pagination now present elsewhere. | Long scanning and costly rendering as data grows. | Reuse Re-order/Daily patterns according to measured dataset size; server-side pagination for growing ledgers, lightweight client controls for bounded lists. |
| **M-08** | Store Analytics mixes selected-year and all-time KPI scopes; supplier/route evidence is not directly linked. | Users may compare unlike periods or stop before resolving a late supplier/order. | Label every KPI’s time basis adjacent to its value and link to filtered existing order/supplier views. |

### Low — cleanup after higher-risk work

| ID | Problem | Impact | Practical improvement |
|---|---|---|---|
| **L-01** | `reports/workshop_weekly.php` is unreferenced by current rendering, while many local style remnants remain. | Maintenance confusion and accidental edits to the wrong screen. | Remove/archive only after route/render assertions and a clean test run; do not touch the active W36 include/PDF paths. |
| **L-02** | Small copy inconsistencies remain: Add Register, route/title terminology, inconsistent button phrasing. | Minor but avoidable hesitation. | Apply the naming glossary globally while keeping URLs stable; use consistent verbs such as Open, Apply, Download Excel, Print. |
| **L-03** | Consolidation decisions currently lack observed route usage and role-task evidence. | Removing a “duplicate” could delete a valued workflow. | Review privacy-appropriate route/export usage and run role-based task sessions before retiring Daily Activity, Weekly Snapshot, or other working views. |

---

## 9. Improved Reports menu structure

### Preferred global sidebar

```text
Dashboard
…
Reports                         → /reports
…
```

There should be **one global Reports item**, not 15 permanent siblings. This is the largest simplification with the least business-logic risk. Keep the existing global modules and role rules outside this audit unchanged.

### Reports landing and local navigation

```text
Reports
├── Operations
│   ├── Workshop Daily
│   ├── Workshop Weekly
│   │   ├── Fleet availability
│   │   ├── Downtime log
│   │   └── Availability targets   [authorised configuration link]
│   └── Work & Attendance
│       ├── Daily activity
│       └── Weekly snapshot
│
├── Performance & Reliability
│   ├── Monthly Performance
│   ├── Reliability (MTTR / MTBF)
│   └── Annual Performance
│
├── Maintenance Planning
│   ├── PM Due Forecast
│   ├── Monthly PM Plan
│   ├── Annual PM Due Schedule
│   ├── Lubricant Compliance       [Month / Year]
│   └── Service Kits
│
├── Inventory & Procurement
│   ├── Supplier Spend & Lead Times
│   ├── Replenishment              [existing Re-order report]
│   └── Stock Movements
│       ├── Issues
│       ├── Receipts
│       └── Stock on Hand           [existing Materials Movement]
│
└── Asset Records
    ├── Asset Register
    └── Equipment History           [normally entered with an asset selected]

Administration, outside report consumption:
├── Equipment → Add Equipment / Import Asset Register
├── Inventory → Issue / Receive Stock
├── Settings / Weekly Report → Availability Targets
└── Work Orders / PM / Orders → operational create and update actions
```

### Behaviour of the proposed structure

- Existing URLs and canonical W36 aliases remain valid.
- The hub can render these groups without introducing a second sidebar tree.
- Local tabs/links are appropriate only for genuinely related views; they must expose the current view and preserve period/filter state.
- Role-based hiding applies to destinations, while controller authorisation remains the security boundary.
- Administrative links are visible only when the user can perform the task.
- Do not add favourites, saved views, a new dashboard builder, or another search system unless observed user needs justify them. The current problem is excess structure, not a shortage of features.

### Fallback if stakeholders require sidebar children

Use a single collapsed **Reports** disclosure containing only the five group links. Individual reports remain in the hub. This is a compromise; it is preferable to restoring a permanent flat list.

---

## 10. Implementation roadmap

The sequence is deliberate: **trust → access/navigation → shared interaction → workflow completion → consolidation**. Do not start with a big-bang visual rewrite.

### Phase 0 — Reporting safety and reconciliation

**Scope:** C-01 to C-04, H-04, H-06, H-09.\
**Design work:** approve metric glossary, Daily as-of semantics, PM compliance definitions, fleet membership, currency/unit policy, and export contract with operations/finance/maintenance owners.\
**Engineering work:**

- correct Daily status/population, KPI labels/nulls/targets/rollups and screen/XLSX/PDF parity;
- correct Annual PM/compliance/reliability/availability and thresholds;
- enforce per-currency/per-unit presentation;
- repair Issue stock navigation;
- unify Store filters/totals/allocation/export scope and add proper pagination/result counts;
- preserve null in reliability outputs.

**Exit criteria:**

- approved KPI and report-output contract documented;
- automated fixtures prove daily replay, no-data, overlapping events, unequal groups, custom targets, PM status, multi-currency/unit, and register-filter cases;
- screen/XLSX/PDF values reconcile for the same dataset;
- no known broken primary report action remains.

### Phase 1 — Calm navigation and explicit role boundaries

**Scope:** H-01 to H-03, H-02 active-state defects; initial M-02 labels.\
**Work:**

- reduce global navigation to one Reports entry;
- implement the five-group hub/local structure without changing canonical URLs;
- replace prefix exclusions with explicit route ownership;
- add keyboard/touch menu behaviour, focus/ARIA, and non-reflowing expansion;
- separate administrative actions and approve read-only role scopes;
- apply the naming glossary.

**Exit criteria:**

- all requested reports remain reachable by authorised users;
- exactly one current navigation destination and `aria-current` per route;
- all sidebar/report navigation works with keyboard alone and touch;
- Store/Technician/CEO access changes, if any, have explicit permission tests;
- no write permission is widened as part of navigation work.

### Phase 2 — Shared report shell, responsive baseline, and output contracts

**Scope:** H-05, H-07, M-03 to M-08.\
**Pilot screens:** Workshop Daily after its data fix, MTTR/MTBF, Annual Performance, Issue/Receipt, and one planning view. Re-order is the reference, not a rewrite target.\
**Work:**

- shared labelled GET filter bar and return context;
- shared KPI/no-data/target/unit components;
- responsive table/pagination/card primitives;
- common export/print labels and provenance;
- contrast, semantic tables/forms, reduced motion, and chart fallback;
- self-host/guard critical chart assets according to deployment policy.

**Exit criteria:**

- pilot screens pass responsive/zoom/keyboard/screen-reader acceptance;
- no page-level horizontal overflow at target widths;
- filter/period and export scope are visible and tested;
- reports remain usable when Chart.js/fonts are unavailable;
- no additional local copy of the shared filter/table system is introduced.

### Phase 3 — Complete operational hand-offs and simplify the hub

**Scope:** H-08, M-01, M-02, M-07/M-08 remainder.\
**Work:**

- connect annual/monthly reliability to equipment/WO evidence;
- connect PM forecast/plans to existing asset/schedule actions and back;
- connect supplier/replenishment/kit evidence to valid existing material/order tasks;
- group Stock Movements and planning/performance families;
- make the report library primary in the hub while retaining useful weekly-close status;
- add table controls only where dataset size and task need justify them.

**Exit criteria:**

- role-based scenarios complete without manual ID copying or re-entering period context;
- every action has a valid authorised destination and return path;
- no new duplicate dashboard or analytics layer is created;
- export and detail links preserve the approved scope.

### Phase 4 — Evidence-led consolidation and hardening

**Scope:** L-01 to L-03 and remaining legacy views.\
**Work:**

- observe route/export usage and run representative role-task sessions;
- compare Daily Activity/Weekly Snapshot/Monthly Summary data contracts before merging or retiring anything;
- remove the unreferenced legacy weekly template only after verification;
- migrate remaining screens incrementally to the shell;
- run full regression, accessibility, low-bandwidth, export, and role test matrices.

**Exit criteria:**

- no loss of existing business capability or canonical route compatibility;
- duplicate destinations have an explicit retain/rename/merge/retire decision and evidence;
- final acceptance includes real PHP/MySQL/browser execution, not structural checks alone.

### Suggested delivery ownership

| Workstream | Required contributors |
|---|---|
| KPI/report semantics | Maintenance lead, planner, operations manager, finance/store representative, backend engineer |
| Navigation and shell | Product designer/UX architect, frontend/PHP engineer, accessibility reviewer |
| Permissions | System owner/security reviewer plus each affected role representative |
| Operational acceptance | Supervisor, planner/reliability user, Store Keeper, Manager, executive consumer; Technician for contextual asset history if approved |
| Export governance | Backend engineer plus the people who actually consume Excel/PDF outputs |

---

## 11. Acceptance test plan

### Data and output tests

- Historical Daily replay after a WO is closed later.
- WO type/status versus actual breakdown outage.
- Reportable versus non-reportable fleet membership.
- Zero required hours, no failures, no data, and partial late data.
- Overlapping downtime events and unequal equipment/group required time.
- Equipment/group/default target resolution in screen, XLSX, and PDF.
- PM due, completed, open preventive, non-PM corrective, and inactive schedules.
- Annual versus monthly rollup definitions.
- TZS/USD/UGX rows and mixed stock units without invalid aggregation.
- Issue/Receipt reference, person, equipment, category, allocation, supplier, item code, description, and part-number filters across rows/KPIs/analytics/exports.
- More than 5,000 register rows: pagination, total counts, and complete or explicitly constrained export.

### Navigation and workflow tests

- Every requested report and canonical alias has one owner/active state.
- Keyboard-only: open navigation, identify labels, enter a report, change period, filter, export, open evidence, and return.
- Touch: discover labels, open/close the menu, operate filters and tables without hover.
- Supervisor weekly close including live/published export versions.
- Daily equipment/WO journey preserving date/filter/page.
- Planner month/year/horizon and return context.
- Store issue/receipt/replenishment/order hand-offs under each approved role.
- Invalid periods and unauthorised actions produce visible, safe outcomes.

### Visual, responsive, and accessibility tests

- Real browser rendering against the Dashboard reference at the viewport/zoom matrix in §7.5.
- Long content, empty/partial/error states, and multi-currency values.
- Manual screen-reader checks for headings, navigation, tables, forms, status updates, and chart alternatives.
- Automated accessibility checks supplemented by manual keyboard/contrast/target-size review.
- Reduced-motion and 200% zoom.
- Print/PDF comparison with on-screen scope and values.
- Restricted-network test with CDN requests blocked.

### Success measures

Use operational outcomes, not aesthetic opinion:

- zero known misleading Daily/Annual KPI definitions or cross-output mismatches;
- zero invalid primary actions;
- no mixed-currency/mixed-unit aggregate presented as a single total;
- one active Reports navigation owner per route;
- no silent export truncation or undisclosed scope change;
- authorised users can complete the target role scenarios without re-entering context;
- no critical keyboard/touch blocker in the Reports workflows;
- no regression in canonical weekly publication or existing export reproducibility.

---

## 12. Key source evidence

| Evidence | Location |
|---|---|
| Prefix active-state helper, 15-link Reports block, manual exclusions | `application/views/layouts/sidebar.php:4-8,213-291` |
| Hover-only expansion and narrow-width behaviour | `application/views/layouts/sidebar.php:652-665` |
| Sidebar clipping, hidden labels/tooltips, hidden scrollbar | `application/views/layouts/header.php:98-184` |
| Shared small-screen/reduced-motion coverage | `application/views/layouts/header.php:657-687` |
| Reports hub library and responsive rules | `application/views/reports/index.php:809-879,1077-1095` |
| Daily equipment/WO/status/population construction | `application/controllers/Reports.php:1938-2072` |
| Daily PDF time-based KPI and target | `application/controllers/Reports.php:2081-2097,2150-2157` |
| Daily XLSX fixed thresholds and group-average overall KPI | `application/controllers/Reports.php:2268-2399` |
| Daily readiness label, null-to-zero chart data, fixed colours | `application/views/reports/workshop_daily.php:43-68,99-166` |
| Daily full-day export disclosure | `application/views/reports/workshop_daily.php:201` |
| Canonical weekly live/published export disclosure | `application/views/reports/weekly_w36.php:578-599` |
| Canonical weekly filters/actions/detail patterns | `application/views/reports/weekly_w36.php:645-867,1043-1306` |
| Annual calculations | `application/models/Dashboard_model.php:602-753` |
| Annual PM Done, hard-coded spend symbol, terminal performers | `application/views/reports/annual_report.php:40-59,80-158` |
| Annual XLSX thresholds and PM formula | `application/controllers/Reports.php:770-831` |
| MTTR/MTBF null conversion | `application/models/Dashboard_model.php:318-401` |
| Store row filters, total mismatches, allocation scope, 5,000-row cap | `application/models/Store_model.php:20-161` |
| Receipt value/unit-price presentation | `application/views/reports/receipt_register.php:30-103` |
| Invalid Issue stock hand-off and required route parameter | `application/views/reports/issue_register.php:15-22`; `application/controllers/Materials.php:723-763` |
| Re-order table/export reference patterns | `application/views/reports/reorder.php:698-745,791-1110` |
| Currency vocabulary and grouping precedent | `application/helpers/currency_helper.php`; `application/controllers/Reports.php:353-373`; `application/views/reports/store_analytics.php` |
| Shared KPI arithmetic policy | `SYSTEM.md:804-855` |

---

## Final recommendation

**Do not redesign Reports for appearance. Make it trustworthy, calm, and connected.**

The shortest responsible path is to correct Daily/Annual/unit/currency semantics first, collapse the global navigation into one Reports destination, preserve the canonical weekly and Re-order foundations, and migrate the remaining screens incrementally onto shared filter/table/export/accessibility primitives. That will improve technician-adjacent evidence gathering, supervisor control, planner effort, store reconciliation, and executive confidence without introducing a new design system or unnecessary features.

---

## Implementation addendum — first Phase 0 safety batch

The following working-tree changes were made after the original audit snapshot:

- **H-04 addressed in source:** Issue Register now sends authorised users to the existing inventory item-selection workflow instead of calling `materials/issue` without an ID. Read-only Managers no longer see that write action.
- **H-06 partially addressed:** Store rows, KPI totals, and allocation analytics now share one complete filter builder, including reference, receiver, equipment, and part-number search. Screens and workbooks compare the bounded result with the full filtered line count and explicitly identify truncation; workbook totals are labelled as downloaded-row totals when capped. Server pagination and unrestricted/complete exports remain future work.
- **H-09 partially addressed:** the MTTR/MTBF report preserves null in monthly, per-equipment, and fleet values; renders an em dash and no-qualifying-breakdown explanation; avoids null comparisons; and leaves chart gaps. Annual reliability null handling remains with the Annual correction work.
- **Verification:** `tests/reporting_safety.js` was added and passed with the nine structural suites used by this audit. PHP/MySQL behavior, generated workbook contents, and authenticated browser rendering still require runtime verification.
- **Deliberately unchanged:** Daily historical/status definitions, Annual PM/compliance definitions, currency/unit policy, sidebar structure, and canonical W36 business logic were not changed in this batch.

---

## Implementation addendum — second Phase 0 safety batch

This batch extends the same low-ambiguity reporting-safety work without changing the disputed business definitions:

- **Mixed-unit Store totals removed:** `register_totals()` now returns delivered and approved quantities by stock unit and values by transaction currency. Its legacy scalar quantity/value fields are populated only when exactly one corresponding unit/currency exists. Raw `NULL`/empty units are merged under an explicit `(unit not set)` label without overwriting either quantity.
- **Allocation analytics made unit-safe:** Issue allocation rows are grouped by stock unit, display that unit, omit the previously unqualified mixed-currency value, and rank by movement-line count rather than by adding incompatible quantities.
- **Issue/Receipt screens made dimension-aware:** their full-filter KPI quantities are listed by unit. Receipt values are listed by transaction currency, and row unit prices include their normalized currency code. No currency conversion was introduced.
- **Register XLSX contracts corrected:** both export callers pass the full grouped summary. Receipt workbooks now place Currency in K, Line Value in L, and Supplier in M; each row still calculates delivered quantity × unit price (`I*J`). Complete results receive clearly labelled per-unit and per-currency footer summaries. Capped results explicitly say `RESULT LIMITED — NO OVERALL TOTALS` and suppress those summaries, superseding the first batch's downloaded-row-total footer.
- **Annual null handling extended:** monthly, equipment, and annual-average MTTR/MTBF values remain `null` when undefined; the screen uses dashes and the workbook writes blank numeric cells. Unknown availability sorts after measured values and is excluded from the measured “Worst Performers” list rather than being treated as zero.
- **Annual lubricants and parts-spend labels corrected:** monthly lubricant consumption is grouped by its lubricant-master unit on screen and in XLSX instead of being summed under an assumed litre label. The Annual summary workbook now exposes nullable reliability values. WO spare-part spend retains its existing cost basis and is labelled with the configured site currency on screen and in XLSX; no exchange-rate behavior was added or changed.
- **Regression documentation and coverage:** `tests/reporting_safety.js` now checks the unit/currency, capped-footer, Annual-null, lubricant, configured-currency, and workbook-column contracts. All 11 report/navigation structural suites used for these batches pass, as do `node --check tests/reporting_safety.js` and `git diff --check`.
- **Still requires runtime acceptance:** PHP linting/execution, MySQL query results, generated-workbook inspection/recalculation, and authenticated browser/role testing were not available in this workspace. Structural checks do not establish those behaviors.
- **Deliberately unchanged:** the 5,000-row cap, server-pagination/unrestricted-export scope, Daily historical fleet/WO inclusion, Annual PM completion/compliance calculations, MTTR event definitions, availability formulas, recurrence rules, exchange-rate behavior, routes/RBAC, sidebar structure, and canonical W36 logic remain unchanged.

---

## Implementation addendum — third Phase 0 batch: C-02 Daily output alignment

This batch addresses C-02's low-ambiguity presentation/output mismatches only. It does not reinterpret the Daily report's disputed status, fleet, work-order, or as-of rules:

- **One shared Daily summary:** `Reports::_workshop_daily_summary()` supplies the screen, XLSX, and print/PDF with count-based status readiness, its reported-fleet counts, KPI-engine time-based fleet availability, and the resolved fleet target. The time-based measure uses `Kpi_engine_model::fleet($date, $date)`; readiness remains available-status rows divided by reported status rows. Equipment targets resolve through equipment, report-group (where that optional column exists), then site fallback; type and fleet targets use the existing required-time-weighted rollup, with a site-target fallback only when no target members resolve.
- **Screen and print/PDF labels separated:** the screen presents Available/Down as status counts and identifies Fleet Availability as time-based, with KPI-engine basis and target. The print/PDF labels status readiness separately from the engine value, names a missing qualifying time base, and exposes type/equipment target context.
- **Workbook contract aligned:** XLSX no longer averages type percentages into a fleet KPI. It reports the status counts, status readiness, and engine fleet availability as separate rows. Dedicated Machine Target and Type Target columns expose the equipment/group targets; the fleet target is shown in the fleet summary label. Machine, type, and fleet time-based values use those targets for colour status. Undefined values remain blank and neutral; readiness is not scored against an availability target.
- **No-data and chart behaviour:** screen chart series keep nulls as gaps; null machine/type/fleet values display neutrally rather than as 0%. Chart initialization now returns safely when Chart.js is unavailable, and tooltips identify a missing qualifying time base. Daily availability sorting keeps nulls last in either direction.
- **Regression coverage and documentation:** `tests/workshop_daily_ux.js` now checks the shared summary use, separate measures, target/no-data policy, removal of fixed 90/70 and group-average behavior, null-last sorting, and inline JavaScript parsing. The test is already included in the CI Node step; `tests/README.md` documents the C-02 coverage and its structural limitations.
- **Verification:** all 11 report/navigation Node suites passed, including `workshop_daily_ux.js` and `reporting_safety.js`; both changed Node test files passed `node --check`, `git diff --check` passed, and the conflict-marker scan was clean. PHP lint/application execution was not run because the workspace has no PHP CLI. MySQL integration, generated XLSX inspection/recalculation, and authenticated browser/role acceptance remain unrun; source checks do not prove those behaviors.
- **Deliberately unchanged:** disputed Daily fleet/WO inclusion, as-of status rules, KPI calculations and availability definitions, routes/RBAC, pagination, and full-day export scope remain unchanged. Annual/PM/compliance definitions, MTTR event/availability definitions, recurrence rules, exchange rates, sidebar structure, and canonical W36 logic also remain outside this batch.

---

## Implementation addendum — fourth Phase 0 batch: C-03 Annual availability disclosure

This is a presentation-only C-03 sub-batch. It states the existing Annual availability calculation more precisely without selecting a new annual KPI definition:

- **Annual availability label corrected:** the existing figure remains the unweighted arithmetic mean of monthly fleet-availability percentages for months with defined values. The screen identifies it as a mean of measured months, states that it is not a full-year required-time rollup, and explains the no-measured-months case. The Annual Summary workbook uses the same label and basis note; its target is shown separately.
- **Annual XLSX colour policy aligned:** monthly and equipment availability colours now use the same resolved site target and existing 70% watch band as the Annual screen, rather than independent fixed 90/70 performance cutoffs. The Annual Summary sheet includes the target basis.
- **Calculations deliberately unchanged:** monthly availability values, the unweighted-mean formula, the separate engine-derived annual per-equipment availability, fleet population, PM Done/PM Completed calculations, PM compliance denominator, Annual MTTR formula, and other Annual business rules remain unchanged. This does not resolve C-03's metric-definition findings; those still need owner approval and source-record reconciliation.
- **Regression coverage:** `tests/reporting_safety.js` checks the existing measured-month mean, the precise screen/workbook disclosure, the target/watch-band parity, and absence of fixed XLSX 90/70 thresholds. `tests/README.md` documents this limited scope.
- **Verification:** all 11 report/navigation Node suites passed; both changed Node test files passed `node --check`; `git diff --check`, the C-02/C-03 addendum whitespace checks, and the conflict-marker scan passed. PHP lint/application execution was not run because PHP CLI is absent. MySQL integration, generated XLSX inspection/recalculation, and authenticated browser acceptance remain unrun.
- **Next approval gates:** C-01's as-of status/population rules and the substantive C-03 PM, compliance, and annual reliability/availability definitions remain blocked on approved business rules and reconciliation fixtures.

---

## Implementation addendum — fifth Phase 0 batch: H-06 Store Register pagination

This batch closes the Issue/Receipt Register screen pagination gap while preserving existing filters, full-filter totals, and the capped-workbook policy:

- **Full filtered screen pages:** the Issue and Receipt screens now use validated `page` and `per_page` inputs (10/25/50/100 rows; default 25) and fetch only the requested slice. Page count and visible range derive from `register_totals()['lines']`, which remains computed across the complete shared filter, not the current page. Existing date filters, headline totals, period-covered scope, and other filter semantics remain unchanged.
- **Stable ordering and state:** both model queries retain descending register date with descending transaction ID as the tie-breaker. Pager URLs and page-size changes preserve the active filters; changing a filter resets to page one. A page number beyond the filtered result is clamped to the last valid page.
- **XLSX policy unchanged:** export links contain the active filters but no screen page/size parameters. Export callers still use the model's default `MAX_ROWS` cap (5,000); the screen separately discloses when the workbook contains only the latest capped rows, and the workbook's existing truncation marker and no-overall-totals footer remain intact. No uncapped export was introduced.
- **Regression coverage and documentation:** `tests/reporting_safety.js` now checks model limit/offset and stable ordering, validated page sizes and full-filter counts, filter-preserving screen controls, page-scoped data fetches, and export isolation/default-cap behavior. `tests/README.md` describes those checks and their structural limitations.
- **Verification:** all 11 CI report/navigation structural Node suites passed, including the expanded `reporting_safety.js`; `node --check` passed for the changed reporting-safety test, `git diff --check` passed, and the conflict-marker scan was clean. PHP lint/application execution and MySQL integration could not run because PHP and MySQL CLIs are absent. Generated XLSX inspection/recalculation and authenticated browser/role acceptance remain outstanding; structural checks do not prove those behaviors.
- **Deliberately unchanged:** business definitions, shared filter semantics, headline totals, report date scope, XLSX cap, routes/RBAC, and the contents or completeness policy of exports remain unchanged.

---

## Implementation addendum — sixth Phase 1 sub-batch: H-01 Reports navigation consolidation

This bounded navigation change reduces the global sidebar's flat report list to one Reports entry. It preserves the existing report hub, URLs, and access boundaries:

- **One global destination:** the 14 direct report links have been removed from the sidebar and replaced by its existing Reports home link. The hub remains the catalogue and continues to expose the reports previously available as sidebar siblings. The Reports entry is still shown only to Supervisors and Managers; no route authorization or write permission changed.
- **Clear active location:** the single Reports link is active on report routes and exposes `aria-current="location"`, avoiding competing current states between the hub link and a report-specific sidebar sibling.
- **Regression coverage:** `tests/reports_hub.js` checks that the sidebar section contains exactly one Reports link, retains its Supervisor/Manager visibility guard and active-location state, and that the hub still includes all former direct report destinations. The test is now part of the CI Node checks; `tests/README.md` documents the coverage.
- **Verification:** all 12 CI report/navigation structural Node tests passed, including the expanded `reports_hub.js`; `node --check` passed for the changed Node tests, `git diff --check` and scoped whitespace checks passed, and the conflict-marker scan was clean. PHP rendering could not be linted or executed because the PHP CLI is absent. Authenticated role/browser acceptance remains outstanding.
- **Deliberately unchanged:** report URLs/aliases, controller authorization, the hub's existing catalogue and categories, local fleet navigation, all non-report sidebar sections, and the broader H-02 keyboard/touch/menu redesign.
