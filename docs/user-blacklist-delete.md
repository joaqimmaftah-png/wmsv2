# User Blacklist + Safe Delete — developer notes (0017)

**Request:** "On Users there's only Deactivate — add a way to delete and
blacklist people."

**What shipped:** `users.status` gains a `Blacklisted` value (mirroring the
existing `suppliers.status` Blacklisted), a real hard-delete for
zero-history accounts, and immediate session revocation. Migration
`database/migrations/0017_user_blacklist_and_delete.sql`, applied with
`php scripts/migrate.php`.

## The four states, and why they are distinct

| Status | Meaning | Can sign in? | Reversible? |
|---|---|---|---|
| `Active` | Normal access | Yes | — |
| `On Leave` | HR state, not an access decision | Yes (unchanged behaviour) | Yes |
| `Inactive` | Left / access disabled, neutral | No | Yes, via Activate |
| `Blacklisted` | Misconduct / security risk | No | Only via Unblacklist → lands on `Inactive`, then explicit Activate |

Collapsing Blacklisted into Inactive would lose the distinction between
"person left" and "person must never come back" — and a generic
Activate toggle could silently undo a security decision. That is why
`toggle_status()` refuses Blacklisted accounts and `unblacklist()` lands
on `Inactive` instead of `Active`.

## Best-practice rules applied (the pattern for any similar request)

1. **Never hard-delete a person with history.** Work orders, attendance,
   leave, certifications and stock movements point at `users.id` (mostly
   RESTRICT FKs). Deleting would either fail with an opaque SQL error or
   erase the audit trail. So `delete()` counts references first
   (`Users::_reference_counts()`) and refuses with a human message —
   the same guard `Suppliers::delete()` uses for linked purchase orders.
   Only zero-history accounts (test/duplicate logins) are hard-deleted,
   and their uploaded photo/signature files are removed with them.

2. **Blacklist is a status, not a separate table.** One enum value,
   consistent with `suppliers.status`, keeps every existing
   `status = 'Active'` picker (technicians, roster, assignment gates)
   correct with zero changes — Blacklisted people simply stop appearing.

3. **Audit the decision.** `blacklisted_by` / `blacklisted_at` /
   `blacklist_reason` (self-FK `ON DELETE SET NULL`) make the decision
   attributable. The reason is prompted in the UI and optional.

4. **Enforce at every layer, not just the button.**
   - `Auth::login()` — only `Active` / `On Leave` may sign in; refusal
     uses the same generic "Invalid email or password." message so the
     response never reveals account state.
   - `MY_Controller::_check_auth()` — re-validates status on **every**
     request, so blacklisting/deactivating/deleting someone ends their
     live session immediately instead of at expiry. Fail-open with a log
     line on DB errors (same posture as `_load_settings()`).
   - `Users` controller is Supervisor-only (existing matrix), all writes
     are POST + CSRF (`require_post()`), self-actions are blocked, and
     the **last active Supervisor** can neither be blacklisted nor
     deleted (lockout guard).

5. **Schema change = migration + schema.sql + tests.** Fresh installs
   read `schema.sql`; existing installs run `scripts/migrate.php`.
   Both were updated, the `schema_migrations` ledger checksum was added,
   and `tests/run.php` asserts the new enum, columns, actions and login
   gate. Run `php tests/run.php` after any schema touch.

## How to apply this pattern to the next user request

- **New status / flag?** Prefer extending an existing enum over a new
  table; check every `where('status', 'Active')` picker still behaves.
- **Delete something?** Count references first; refuse with guidance
  instead of letting the FK throw. Hard-delete only zero-history rows.
- **Access change?** Update the login gate *and* the per-request session
  check — a status change that only applies at next sign-in is not
  immediate enough for security decisions.
- **Always:** POST + CSRF for writes, Supervisor-only via the access
  matrix, migration + `schema.sql` + ledger + test, and a short doc like
  this one explaining *why*, not just *what*.
