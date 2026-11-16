# Navigation implementation — Phases A and B

This implements the approved sidebar/navigation review without changing the
database schema, role IDs, or the authorization boundary.

## What changed

- **Canonical navigation model:** `application/helpers/navigation_helper.php`
  owns the role-filtered tree, longest-prefix active leaf, presentation-only
  link filter, and inline SVG icons. `MY_Controller::render()` loads it.
- **Explicit shell:** `layouts/sidebar.php` is data-driven. Groups are native
  `<details>` disclosures with `aria-expanded`/`aria-controls`; the current leaf
  has `aria-current="page"`. `assets/js/navigation.js` adds persisted desktop
  collapse and a labelled mobile drawer with Escape/Tab containment and inert
  canvas. The duplicate sidebar identity block and duplicate account-menu
  Users/Settings links are gone.
- **Hover-to-expand (restored):** hovering the collapsed icon rail with a
  mouse/trackpad expands it to the full 240px width and opens every group, so the
  whole tree is visible at once — the pre-redesign behaviour, with no click
  required. It is an additive pointer enhancement: click and keyboard remain the
  primary controls and native `<details>` still work without JS. The expansion
  **overlays** the page (it never changes the `#canvas` margin), so dense registers
  never reflow or overflow. Leaving the rail restores the previous width and group
  open-states; an explicit click on the toggle or a group pins the rail open so it
  survives mouse-leave. Hover is gated to the event's own `pointerType` (touch is
  ignored, so touchscreen laptops and the mobile drawer are unaffected) and never
  writes a preference to `localStorage`.
- **Width-aware default:** the labelled 240px rail is the default on wide
  desktops (≥1600px). Between 761px and 1599px the shell starts on the 68px icon
  rail, because the dense registers (attendance, work orders, inventory) were
  designed against that canvas width and an always-expanded sidebar made them
  overflow horizontally at 1440px. Expansion is one explicit click or a pointer
  hover (see above), a stored choice always overrides the width default, and
  mobile is always the labelled drawer. The first viewport sync is instant
  (`nav-boot` disables the canvas transition until after first paint), because
  dense registers measure table width during load and a 240px→68px animation
  overflows them mid-transition.
- **Built-in-server asset guard:** `index.php` now returns `false` for existing
  files under `/assets/` when running under `php -S … index.php`, so the shell
  JavaScript is served in local development and CI instead of being routed to a
  404. The guard resolves paths with `realpath()` and confines them to `assets/`.
- **Page-local suites:** Planning (`forecast/_nav.php`) and Attendance
  (`attendance/_nav.php`) use `shared/_module_nav.php`. Specialist/setup
  functions are no longer promoted as global sidebar leaves.
- **Canonical names:** Inventory Overview, Stock Items, Stock Movements,
  Purchase Orders, Work Order Parts, Defect Backlog, Preventive Maintenance,
  PM Parts Forecast, Planning Allowances, 3-Week Plan, Shift Roster, Check-in
  Log, User Accounts, System Settings, Downtime Register.
- **Report library:** the hub no longer duplicates the fleet local nav. Fleet
  Availability and Downtime Register are library rows; section numbers and
  quick-export counts are derived from the catalogue.
- **Contextual links:** dashboard, parts board, orders, stock movements,
  lubricants, PM parts pills, equipment profile, and Downtime Register
  cross-links are authorized or de-linked. Lube Budget fails closed without an
  explicit write flag. Personal attendance/timesheet and on-site status are
  scoped for Technician/Store Keeper in the rendered UI.
- **Role help:** `users/form.php` now describes the current five roles,
  including CEO Executive-Summary-only, without promising pending Phase C
  policy changes.

## Role trees

Expanded leaf counts are asserted in `tests/run.php` and Chromium:

| Role | Leaves | Notes |
|---|---:|---|
| Supervisor | 31 | Full approved tree |
| Technician | 16 | Maintenance + personal work; no planning/reports/admin |
| Store Keeper | 13 | Inventory/purchasing + personal work; no maintenance WO destinations |
| Manager | 25 | Read-only tree with existing publication/order-status exceptions |
| CEO | 1 | Executive Summary only |

## Verification

- PHP 8.3 WASM lint: **191 PHP files OK**.
- `php tests/run.php` equivalent: **471 passed, 0 failed**.
- Node loop: **20 passed, 1 pre-existing failure** (the browser suite skips
  without Chrome/PHP; structural navigation coverage still runs).
  The sole failure is `tests/lubricant_masters_ux.js:31`
  (`No active lubricant types found` vs current empty-state wording), reproduced
  on the untouched baseline.
- Chromium DOM: **5 role trees passed** (forbidden destinations, one current
  leaf, keyboard disclosure, laptop auto-compact to the 68px canvas, hover opens
  every group as a 240px overlay without moving the canvas, mouse-leave restores
  the prior width and group states with no storage write, wide-desktop labelled
  rail, desktop collapse, mobile drawer labelling/touch targets, Escape/Tab
  containment, reduced motion).
- GitHub CI on the PR: PHP 8.2 / 8.3 / 8.4 lint + unit suites and both database
  E2E jobs (MySQL 8.0, MariaDB 10.4), which include the authenticated
  `tests/ui_e2e.js` browser pass.

Commands:

```bash
php tests/run.php
node tests/navigation_shell.js
CHROME_PATH=/path/to/chrome node tests/navigation_browser.js
```

`navigation_browser.js` skips without Chrome/PHP; set
`WMS_NAV_BROWSER_REQUIRED=1` to require it. Pre-rendered fixtures can be supplied
with `WMS_NAV_FIXTURE_DIR`.

## Not implemented — Phase C requires separate approval

- Technician work-order browse scope.
- Workforce-wide Daily Attendance read/write policy.
- Technician stock-write responsibility.
- Any CEO/Store report-access expansion.

The existing controller guards and `MY_Controller` matrix remain the
authorization boundary. The navigation helper only decides which links to
offer.
