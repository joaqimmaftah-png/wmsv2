# Attendance Check-In / Check-Out Workflow

> **Status: Proposed — not implemented yet.** This document records the agreed direction for a future attendance workflow. The current WMS attendance page is manually maintained and does not verify GPS or QR codes.

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

- [ ] Supervisor can configure and validate the site's coordinates and geofence radius.
- [ ] Site screen can display a rotating QR token; expired or forged tokens are rejected.
- [ ] Technician can check in and check out from a mobile browser after signing in.
- [ ] Both QR and GPS validation are required for each punch.
- [ ] Successful punches use server timestamps and retain an auditable event history.
- [ ] Technician availability in the work-order picker follows the rules above.
- [ ] Assignment rules are rechecked server-side when saving.
- [ ] Supervisor corrections require a reason and are auditable.
- [ ] Existing attendance and work-order reports remain consistent.
