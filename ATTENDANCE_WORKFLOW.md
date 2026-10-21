# Attendance Check-In / Check-Out Workflow

> **Status: Implemented (first release) — migration `0010_attendance_punches.sql`.**
> The QR + GPS punch flow, the site screen, the supervisor corrections and the
> assignment gate now exist; see "Implementation notes" at the end for exactly
> what shipped, what is configurable, and what is still deliberately out of
> scope. The manual daily attendance screen (`team/attendance`) keeps working
> and remains the place where a day can be corrected by hand; a punch keeps its
> day row in sync instead of competing with it.

## Goal

Let technicians record their own arrival and departure from the site using a phone, and use those punches to determine who is eligible for assignment to a new work order. A punch is accepted only when **both** the QR and GPS checks pass.

This is a practical site-presence check, not absolute proof: GPS can be inaccurate or spoofed, and a QR code can be shared or relayed.

## One-time site setup

1. A supervisor configures the site latitude and longitude and an allowed GPS radius (geofence). Choose a radius that fits the actual worksite and expected phone accuracy; there is no assumed default radius.
2. The WMS must be served over **HTTPS** so mobile browsers can request location and use secure browser features.
3. Recommended: display a **short-lived, rotating QR code** on a screen at the site entrance. The QR should contain only a short-lived signed token, not technician information. A printed, fixed QR is a simpler fallback, but is easier to copy and share.
4. Technicians need their own WMS accounts and must already be signed in on their phones before punching.

## Technician check-in

1. The technician signs in to WMS on their phone.
2. At the site, they scan the current site QR code. It opens the WMS attendance punch page.
3. WMS requests the phone's location for this punch only. It does **not** continuously track the phone.
4. The server verifies that:
   - the signed-in account belongs to an eligible technician;
   - the QR token is valid and not expired;
   - GPS coordinates are within the configured site radius; and
   - the reported GPS accuracy is acceptable under the configured policy.
5. If all checks pass, WMS records a server-timestamped **check-in** and makes the technician eligible for assignment. If a check fails, WMS explains the reason and does not mark them on-site.

## Technician check-out

1. Before leaving, the technician repeats the QR scan and one-time location check.
2. The server validates the QR and location again, then records a server-timestamped **check-out**.
3. A technician is considered on-site only when today's latest valid punch is a check-in with no later check-out.

### Open work-session handling

If the technician has an open work-order session at check-out, WMS should warn them and require the session to be closed or reviewed by a supervisor. The exact policy (block check-out versus allow it with a warning) must be decided before implementation; do not silently leave a work session open.

## Work-order assignment eligibility

Show a technician in the new-assignment picker only when all of these are true:

- the technician is active and has checked in today;
- there is no valid check-out after that check-in; and
- the technician is not assigned to an unfinished work order.

Treat these work-order statuses as unfinished: **Open**, **In Progress**, **Waiting Parts**, and **Waiting Tools**. **Completed** and **Closed** are finished. When a work order becomes Completed or Closed, the technician may be considered for another assignment, but must still be checked in and on-site.

Apply these eligibility checks both when building the picker **and** on the server when saving an assignment. Hiding a name in the UI alone is not sufficient.

## Failure handling and supervisor corrections

- If location permission is denied, GPS is unavailable, the accuracy is too poor, or the QR has expired, do not create a successful punch; show a useful retry instruction.
- Provide a supervisor-only correction/override for missed or incorrect punches. Record who made the correction, when, and the reason.
- A correction must not silently erase the original punch history.
- Do not require continuous location permission. Ask only when checking in or out.

## Data and implementation notes

- Keep punch events as auditable **check-in/check-out events**, then derive the daily attendance summary from them. The existing attendance table stores one row per worker per day with manual status and optional times; it is not sufficient by itself for retaining a robust punch audit trail or future repeat punches.
- Use server time as the authoritative punch time, not the phone's clock.
- Store only location evidence needed for the punch (for example, coordinates and reported accuracy) and set an appropriate retention policy. Do not collect fingerprint templates or continuous location history.
- Preserve the existing monthly reports by deriving their daily status/times from the punch data or synchronizing a summary row.
- QR validation and GPS/geofence validation must happen server-side. Client-side checks are for user feedback only.
- A fixed printed QR plus GPS can be used for a first pilot if there is no site screen, but it has weaker replay protection than a rotating QR.

## Acceptance checklist

- [x] Supervisor can configure and validate the site's coordinates and geofence radius.
      → `Attendance::sites()` (`attendance/sites`): name, point, radius 25–5 000 m,
      worst accuracy 5–2 000 m, active flag. "Use my current location" fills the
      point from the supervisor's own phone; "How far am I from this point?"
      reports the distance so a wrong entry is caught on site.
- [x] Site screen can display a rotating QR token; expired or forged tokens are rejected.
      → `Attendance::board()` asks `Attendance::token()` for a signed token; the
      token is `v1.<site>.<window>.<HMAC-SHA256>` and the server only accepts the
      current window plus the configured grace. A token from another site, another
      secret, a future window or a tampered MAC is refused. Each site's secret can
      be rotated, which invalidates every old screenshot.
- [x] Technician can check in and check out from a mobile browser after signing in.
      → `attendance/punch` (camera opens it from the QR) and `Attendance::record()`
      (POST-only). The punch type alternates automatically from the worker's own
      history, so the phone never chooses check-in vs check-out.
- [x] Both QR and GPS validation are required for each punch.
      → `record()` refuses unless the token verifies AND the position is inside the
      radius AND the reported accuracy is within the site's limit; a failed attempt
      writes nothing.
- [x] Successful punches use server timestamps and retain an auditable event history.
      → `attendance_punches` rows carry server `punched_at`/`created_at`, the
      distance, the accuracy, the radius in force, the IP and (for corrections) the
      reason and author. Nothing updates or deletes a punch.
- [x] Technician availability in the work-order picker follows the rules above.
      → `Attendance_model::technicians_for_assignment()` → used by the work-order
      create/edit picker while `attendance_gate_enabled` is on.
- [x] Assignment rules are rechecked server-side when saving.
      → `Workorders::_assignment_problems()` runs on create/edit and refuses a
      technician being ADDED who is not eligible (clearing one stays possible).
- [x] Supervisor corrections require a reason and are auditable.
      → `attendance/log` + `Attendance::correction()`: a correction is a new event
      (`origin = supervisor`) with a mandatory reason, the author and the audit time.
- [x] Existing attendance and work-order reports remain consistent.
      → `Attendance_model::sync_attendance_row()` rewrites the `attendance` day row
      from the punches (first check-in, last check-out, hours, `Present`) after every
      punch/correction, leaving an approved-leave row and human notes untouched.

---

## Implementation notes

Files added in this release:

| File | Purpose |
|---|---|
| `database/migrations/0010_attendance_punches.sql` | `attendance_sites`, `attendance_punches`, four attendance settings (idempotent) |
| `application/helpers/attendance_helper.php` | Geofence arithmetic, signed QR token issue/verify, punch sequencing — pure functions |
| `application/models/Attendance_model.php` | Sites, punch events, derived day row, eligibility, assignment re-check |
| `application/controllers/Attendance.php` | `punch`, `record`, `token`, `board`, `sites`, `policy`, `log`, `correction` |
| `application/views/attendance/*.php` | Phone punch page, result page, site screen, sites & policy, punch log |
| `assets/vendor/qrcode/qrcode.js` | Vendored MIT QR encoder (qrcode-generator 1.4.4) |

### Decisions taken while implementing

- **Open work session at check-out** — the open question is resolved as a
  setting, `attendance_open_session_policy`, default **`warn`**: the check-out is
  recorded and the open session is named in the message. Set it to `block` to
  refuse the check-out until the session is closed. Either way the session is
  never silently forgotten.
- **Assignment gate is OFF by default** (`attendance_gate_enabled = 0`). Turning
  it on without a configured site would empty every work-order picker; a
  supervisor enables it on `attendance/sites` after the first site exists. When
  it is on, the picker and the save handler enforce the rules; when it is off,
  the WMS behaves exactly as before.
- **Only newly added technicians are re-checked on edit.** Removing someone who
  is no longer eligible, or editing an unrelated field of an order that was
  assigned before the gate was switched on, must not be blocked.
- **Token lifetime and grace** — `attendance_qr_ttl_seconds` (default 60) and
  `attendance_qr_grace_seconds` (default 120) absorb the moment the screen
  refreshes. A token from a *future* window is refused: only this server issues
  tokens, so a future window means the code did not come from here.
- **Double taps** — a second punch within 15 seconds is refused rather than
  producing an accidental check-in/check-out pair.
- **Rejected attempts are not stored.** The doc asks that a failed punch create
  nothing; the phone page explains the reason, and the supervisor log is a record
  of accepted events only. If failed-attempt analytics are ever wanted, they need
  their own table and retention decision.
- **Work day** is the server's calendar date of the punch. A shift that crosses
  midnight still punches on the date it happens; splitting a night shift into a
  single "work day" would need a shift-aware rule that this release does not
  invent.
- **The site screen is supervisor-only.** Issuing a token is issuing the site
  key; a technician is meant to scan it on site, not fetch it remotely.
- **Location retention** — coordinates/accuracy are stored on each accepted punch
  (`attendance_punches`). No continuous history and no fingerprint data are
  collected. Choose a retention window for old punches (the audit value usually
  outweighs the storage); `DELETE FROM attendance_punches WHERE work_date < ...`
  is the whole policy, and deleting punches does not change the derived
  `attendance` day rows already written.
- **HTTPS is required** for the browser geolocation API. Over plain HTTP the
  phone page says so instead of failing silently; the server still refuses a
  punch without coordinates.
