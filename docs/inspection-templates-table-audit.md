# Inspection template register — UX and integrity audit

## 1. Executive summary

`/inspections/templates` is a supervisor-only administration register, not the technician's inspection-submission queue. The original page exposed the necessary actions but lacked search, status filtering, a reliable mobile overflow affordance, and an explanation of record-count scope. The local implementation adds progressive-enhancement search/status filtering, clear full-register counts, a responsive horizontally scrollable table and clearer action/status presentation. It retains all existing endpoints, including Fill for inactive templates (the application only promises to hide inactive templates from the picker).

**Important:** two pre-existing integrity concerns in related backend workflows are documented below and have not been fixed or runtime tested. This audit does not certify the system for production acceptance.

## 2. Purpose and workflow

- **Users and tasks:** supervisor manages English/French templates, creates or edits checklist rows, opens a form or records, deactivates unused templates, or deletes templates with no inspection records. Controller explicitly rejects non-supervisors (`Inspections::templates`, `template_edit`, `template_delete`); the access matrix permits supervisors, technicians and managers into `inspections`, with a separate manager write guard.
- **Data:** primary human identifiers are `name_en`, `name_fr` and unique `code`; internal ID addresses edit/delete. Category, `layout_type` (`matrix` or `compliance`), `active` and count of associated records are relevant to the decision. Counts include every record in `inspection_records`, including drafts; they are not only submitted records.
- **Relationships:** templates contain checklist items; inspection records reference templates and record items reference checklist items. Editing is a dedicated complex bilingual editor; records and Fill are separate existing flows. An inactive template is excluded from the default picker; the direct Fill endpoint currently does not reject it.
- **Volume:** schema seeds 14 templates; real production count and growth are unknown. `get_all_templates()` loads all templates, ordered by ID; `get_record_counts()` groups all inspection records by template. The new filters operate on the already-loaded register, not a paginated backend. The number of records is not the number of visible checklist rows.
- **Stack/design:** server-rendered CodeIgniter/PHP, database-backed model, HTML/CSS and plain JS; colors and card/button patterns follow Dashboard and Materials Transactions. No new dependencies, endpoints or schema changes.

## 3. Audit matrix — evidence and status

| Principle | Status | Evidence and assessment |
|---|---|---|
| 1 Status-based navigation | PARTIAL | Formerly none; now an All/Active/Inactive select with full-register counts. Search and status combine; counts intentionally do not narrow with search. Runtime/browser count accuracy not verified. |
| 2 Toolbar | PARTIAL | New search, status and reset plus authorized create action; no export, sort or page-size controls existed and none were invented. Search resets on navigation. |
| 3 Table | PARTIAL | Actual names, code, category, layout, record count, status and existing actions retained; long table scrolls. Dynamic results and formatting await browser/DB testing. |
| 4 Cards | NOT APPLICABLE | Small administrative register requires direct comparison and actions; a second view adds duplication without evidence of benefit. Reassess with mobile users. |
| 5 Record inspection | PARTIAL | Existing dedicated editor, Fill and Records routes retained; editor is appropriate for complex bilingual fields. No context-preserving return was present or added. |
| 6 Row actions | PARTIAL | Fill, Records, Edit, and conditional POST Delete are retained, named per record; deletion asks confirmation. No-JS deletion has server checks but no confirmation. Server integrity concerns below remain. |
| 7 Semantic color | PARTIAL | Active green and inactive neutral with text labels; destructive action red. Actual contrast and display in browser are not verified. |
| 8 Pagination | NOT APPLICABLE | Current design is an administration register with 14 seeded templates, but production size is unknown; no pagination added. Revisit after measuring production volume. |
| 9 Responsive hierarchy | PARTIAL | Name first, visible status/actions; full columns available by keyboard-focusable horizontal scrolling. Physical devices and touch target usability not verified. |
| 10 Accessibility/feedback | PARTIAL | Labelled controls, focus styling, live result count, empty-match state, flash notices; JS-disabled users retain all rows/actions. Keyboard, assistive tech, touch, confirmation and announcements require manual verification. |

## 4–6. Enterprise findings and prioritized register

### IT-01 — Concurrent deletion / unreliable success feedback (High, unresolved)
- **Requirement:** data integrity / functional integrity. **Current:** `Inspections::template_delete` checks record count, then deletes items and template in separate statements, without a transaction, result checks or a fresh locked check. Record creation can happen concurrently. In the supplied schema, `fk_insp_records_template` and `fk_insp_record_items_item` constrain deletion, but live schema/DB error behavior is unknown.
- **Impact:** a race or DB failure may cause failed/partial deletion or a misleading success message; risk depends on real foreign keys and `db_debug`. **Recommendation:** in a separately tested backend change, transact and lock the template and relevant operations, re-check usage under a safe concurrency protocol, verify delete results/transaction status and return truthful error feedback; test with a competing create in the supported DB. Do not assume a single count check guarantees safety.
- **Acceptance:** existing inspection and checklist history survives concurrent create/delete; no success on failed deletion; unused templates delete atomically. **Dependencies/risks:** InnoDB/FKs and deployment schema, all record-creation paths, transaction isolation and lock ordering. **Verification:** DB integration/concurrency test and failure injection. Not changed in this UX patch.

### IT-02 — Existing record/template association on save (High, unresolved)
- **Requirement:** data accuracy and functional integrity. **Current:** `Inspections::save` obtains posted `template_id` and `record_id`, but the inspected edit path does not compare the template ID with the existing record before constructing replacement item rows (`Inspection_model::update_record`).
- **Impact:** a tampered/stale submission may associate an inspection with the wrong template or replace answers unexpectedly, subject to actual DB constraints. **Recommendation:** validate existing record's immutable template relationship and associated item IDs before editing. **Acceptance:** mismatched template edits fail without any record/item changes; normal edit succeeds. **Dependencies:** product policy for legitimate template migration, existing drafts and API callers. **Verification:** authenticated integration tests with matched/mismatched IDs and a rollback assertion. Not changed here.

### IT-03 — Finding and understanding templates (Medium, addressed locally)
- **Current/evidence:** original template view displayed all rows with no search/status filter and generic “Records” counts; backend count groups all records by template. **Impact:** supervisors must visually scan, and may mistake drafts for submitted-only records. **Recommendation:** search bilingual names/code/category; combine with a status filter; show full-register status counts and explain record count scope. **Acceptance:** matching and reset behavior work; counts are explicitly full register; no record hidden without a filter. **Risk:** client-side filtering is only appropriate while the loaded dataset stays modest. **Verification:** `tests/inspection_templates_ux.js` simulated DOM; browser/DB not verified.

### IT-04 — Row/action usability and accessibility (Medium, addressed locally, verification pending)
- **Current/evidence:** original table was a fixed-width, unlabelled action cluster without a dedicated scroll region, per-record action names or filter empty state. **Impact:** harder scanning, keyboard/screen-reader ambiguity and possible mobile overflow. **Recommendation:** name-first hierarchy, table region, named action links, focus states and zero-match feedback. **Acceptance:** essential fields/actions remain reachable at narrow widths, keyboard operation and readable focus; no actions lost. **Risk:** long translations and zoom; horizontal scroll still needed. **Verification:** source checks and page-title check passed; browser keyboard, screen reader and mobile checks remain outstanding.

### IT-05 — Inactive behavior is ambiguous (Medium, unresolved policy)
- **Current/evidence:** `get_templates(TRUE)` hides inactive templates in picker, but `fill()` resolves a template without checking `active`; the former register and this patch retain Fill for inactive rows. **Impact:** users may still create inspections via direct link. **Recommendation:** agree whether inactive means picker-hidden or barred from new inspections; if barred, enforce it in both Fill and new-save while preserving access to historical records and edit policy. **Acceptance:** documented behavior enforced consistently on all entry paths. **Dependencies:** business decision and historical edit requirements. **Verification:** role-based GET/POST integration tests; not verified or changed.

### IT-06 — Scale and persistence (Low, deferred)
- **Current/evidence:** all template rows and grouped record counts are loaded on each request; new filters are client-side and not persisted on navigation. **Impact:** large datasets may become slow and users lose their place after edit. **Recommendation:** measure production sizes and query timings; if needed add backend filtering, deterministic sort/pagination and context-safe return state. **Acceptance:** thresholds and behavior agreed and measured with realistic data. **Dependencies:** real volumes and index review. **Verification:** production-safe profiling and integration tests. No performance claim made.

## 7–8. Table and interaction specification

- **Columns:** English and French template name (primary), code, English category, layout, right-aligned record count, active/inactive, actions. Default backend order remains ascending ID. No extra fields or optional-column controls.
- **Toolbar:** text search matches English/French names, code and English/French categories; status select offers All, Active, Inactive. Counts are computed over all loaded templates, independent of search; result announcement gives filtered matches out of total. Reset restores both filters. With JS disabled, controls stay hidden and all records/actions remain usable. No unsupported export, sorting, card view or pagination.
- **Actions:** Fill and Records use existing code routes; Edit uses ID; Delete appears only when the current count is zero and submits the existing CSRF-protected POST with confirmation. Backend remains the authority. Inactive direct Fill remains available pending IT-05. Existing editor owns validation and form state.
- **Visual/responsive:** inherited shared font; slate text, white surfaces, subtle borders, blue primary action, green active / neutral inactive and red destructive action; explicit text accompanies color. Narrow layouts wrap controls; horizontally scrollable table retains all columns and actions. No essential information is hidden. Empty register, no-match, success and error states are distinct; a server-rendered full page needs no synthetic loading state.

## 9. Before and after

| Area | Before | Local change and benefit | Business behavior |
|---|---|---|---|
| Finding records | Manual scan | Search + status filter and reset | No data/query changes; filters apply to loaded rows only |
| Counts | “Records” without scope | Full-register status totals and record-count scope stated | All draft/submitted counts unchanged |
| Hierarchy | Code first, compact inline actions | Bilingual name first, distinct status and named actions | Same existing routes and conditional delete |
| Small screens | Table without explicit scroll region | Focusable horizontal scroll and wrapped toolbar | No columns removed |
| Failure/safety | Confirmation and backend zero-count check | Clearer irreversible confirmation; existing guards retained | Race and failure reporting remain open (IT-01) |

## 10–12. Implementation and verification

- Changed `application/views/inspections/templates.php`; added `tests/inspection_templates_ux.js`. No migration, controller or model changes for this task. Uses existing `form_open` CSRF handling and server guards.
- `node tests/inspection_templates_ux.js` — passed: simulated DOM verifies search, status intersection, no-match, reset and no-table handling; source guards check routes, POST and permissions.
- `node tests/page_titles.js`, `node tests/table_ux.js`, `node tests/reports_hub.js` — passed.
- `git diff --check` — passed. PHP CLI is unavailable in this environment; no PHP rendering, real database, concurrency, browser, accessibility or authenticated workflow testing was performed. The node tests do not validate backend security or visual output.

## 13. Acceptance checklist and remaining decisions

- [x] Existing table data fields, links and role checks retained in source; no fabricated statuses or actions.
- [x] Client-side combined filtering/reset and result count verified in a simulated DOM.
- [ ] Actual records/counts, URLs and CSRF under a configured database: **not verified**.
- [ ] Supervisor, technician and manager access plus create/edit/delete/fill/records flows: **not verified**.
- [ ] Deletion failure/race protection and record-template relationship integrity: **unmet**, IT-01 / IT-02.
- [ ] Keyboard, screen reader, visual contrast and mobile/tablet usability: **not verified**.
- [ ] Inactive-template submission policy: **decision required**, IT-05.
- [ ] Production-scale data, performance and need for pagination: **not verified**, IT-06.
