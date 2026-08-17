# vb-gratitude — e2e user stories (v0.1.0)

These stories exist so a harness author on the **vctrbase e2e platform** (`e2e/` in
the main repo) can build an Ollama-driven "give-a-shoutout" journey **without
re-reading the plugin's source**. Each story is grounded in the real v0.1.0 surface
(`manifest.json`, `src/routes.php`, `src/GratitudeService.php`,
`src/Http/Controllers/*`, `src/VbGratitudeServiceProvider.php`) and maps to a concrete
route with an assertable outcome.

**Scope:** stories only. No Playwright/TypeScript, no changes to the main-repo `e2e/`
dir, no enabling the plugin in a demo tenant.

---

## Two harness facts to design around

**1. The journey is PLUGIN-CONDITIONAL.**
`vb-gratitude` ships `enabledByDefault: false` — an admin must enable it per tenant
before any of this exists. The journey MUST first check the shared Inertia `nav` prop
for the `vb-gratitude` key and **skip cleanly** when it's absent (Story 8). Every story
1–7 below is only meaningful **when the plugin is enabled for the tenant**; treat "not
enabled" as a skip, never a failure.

**2. `vb-gratitude` is `distribution: extracted`.**
Its UI (`dist/entry.js`) mounts under `/dashboard/plugins/vb-gratitude/view` and fetches
all data over **axios against the session-api**, NOT via Inertia SSR props. Assertions
for plugin data go against **the API envelope `{traceId, data, status}`** or **the DOM
after the fetch settles** — never `readInertiaProps` for plugin data. (The `nav` prop in
Fact 1 is host-owned Inertia and IS readable — that's the only Inertia read in play.)

---

## The surface these stories exercise

| Method + route | `can:` gate | Success | Data key |
|---|---|---|---|
| `POST /api/v1/vb-gratitude/shoutouts` | `vb-gratitude.shoutouts.create.rooftop` | 201 | `data.shoutout` (incl. `points_awarded`) |
| `GET /api/v1/vb-gratitude/shoutouts` | `vb-gratitude.shoutouts.read.rooftop` | 200 | `data.shoutouts[]` (each + `recipient_name`) |
| `GET /api/v1/vb-gratitude/badges` | `vb-gratitude.badges.read.rooftop` | 200 | `data.badges[]` (`badge_key`, `earned_at`) |
| `GET /api/v1/vb-gratitude/teammates` | `vb-gratitude.shoutouts.create.rooftop` | 200 | `data.teammates[]` (safe fields only) |
| UI page | — | — | `/dashboard/plugins/vb-gratitude/view` |

**Roster → ability** (from `manifest.json` `permissionGrants`; e2e roster is
rooftop-tier, RBAC inheritance is downward):

| Role | give (create) | read feed/badges | `/teammates` | admin settings |
|---|---|---|---|---|
| `rooftop_owner` / `rooftop_admin` | ✅ | ✅ | ✅ | ✅ |
| `department_manager` | ✅ | ✅ | ✅ | ❌ |
| `employee` | ✅ | ✅ | ✅ | ❌ |
| `read_only_auditor` | ❌ (403) | ✅ | ❌ (403) | ❌ |

**Business rules the stories assert against** (`GratitudeService::createShoutout`):
- Points mint **only** through the core gamification ledger, event key
  `gratitude.shoutout.given`, source plugin `vb-gratitude`. Default 5 points
  (`pointsPerShoutout`).
- The shoutout row is **always** written; points are earned only up to
  `dailyShoutoutAllowance` (default **3**) point-earning shoutouts per giver per day.
  Past the cap the row still records with `points_awarded = 0` and **no** ledger entry.
- Badges are evaluated on the **giver's** create: `first_thanks` (1 given),
  `grateful_regular` (10), `team_connector` (5 **distinct** recipients),
  `gratitude_champion` (50), `appreciated` (10 **received**). Awards are idempotent
  (unique per user+badge per tenant).
- **`appreciated` / `receivedThisMonth` require the persona to be staff-mapped** — the
  user must resolve to a staff id via `StaffDirectory::staffIdForUser`, or the received
  count degrades to 0 and the badge never fires (by design, no crash).

---

## Story 1 — Happy path: an employee thanks a teammate (green)

**Persona:** `employee` (has create + read).

> **Given** the plugin is enabled for the tenant and the employee is on
> `/dashboard/plugins/vb-gratitude/view`,
> **and** they've fetched `GET /api/v1/vb-gratitude/teammates` to pick a valid
> `recipient_staff_id`,
> **When** they `POST /api/v1/vb-gratitude/shoutouts` with that
> `recipient_staff_id`, a `message`, and an optional `category`,
> **Then** the response is **201** with `data.shoutout.points_awarded == 5` (the
> default), a matching entry exists in the core gamification ledger for event
> `gratitude.shoutout.given`, **and** a follow-up `GET /api/v1/vb-gratitude/shoutouts`
> returns the new row newest-first with `recipient_name` populated (PII-free — a display
> name, never an email).

**Endpoints:** `GET /teammates` → `POST /shoutouts` → `GET /shoutouts`.
**Assert:** POST 201 + `data.shoutout.points_awarded == 5`; feed row present with a
non-null `recipient_name`. (Positive.)

---

## Story 2 — First shoutout earns `first_thanks`, idempotently (green)

**Persona:** a **fresh** `employee` with zero prior shoutouts (a new-user journey — no
badges yet).

> **Given** the employee has never given a shoutout (`GET /badges` returns an empty
> `data.badges`),
> **When** they give their first valid shoutout (`POST /shoutouts` → 201),
> **Then** `GET /api/v1/vb-gratitude/badges` now contains exactly one row with
> `badge_key == "first_thanks"`,
> **and When** they give a **second** valid shoutout,
> **Then** `GET /badges` still contains **exactly one** `first_thanks` row (no
> duplicate — award is idempotent).

**Endpoints:** `GET /badges` (baseline) → `POST /shoutouts` ×2 → `GET /badges`.
**Assert:** exactly one `first_thanks` after the first give and still exactly one after
the second. (Positive; the second give is the idempotency guard.)

---

## Story 3 — Daily allowance cap: over-cap records but earns no points (green, boundary)

**Persona:** `employee`, default `dailyShoutoutAllowance == 3`.

> **Given** the employee has already given **3** point-earning shoutouts today (each
> returned `points_awarded == 5`),
> **When** they give a **4th** valid shoutout the same day (`POST /shoutouts`),
> **Then** the response is still **201** and the row is recorded, **but**
> `data.shoutout.points_awarded == 0`, **and** the gamification ledger gains **no** new
> entry for that 4th shoutout (generosity is never blocked; point-farming is capped).

**Endpoints:** `POST /shoutouts` ×4 (same giver, same day).
**Assert:** 1st–3rd → `points_awarded == 5`; 4th → 201 with `points_awarded == 0` and no
new ledger entry. (Positive on recording, expected-zero on points — a boundary
assertion, not a deny.)

---

## Story 4 — Read-only auditor can look but not give (negative / expected-deny)

**Persona:** `read_only_auditor` (read on feed/badges; **no** create grant).

> **Given** the plugin is enabled and the auditor is authenticated,
> **When** they `GET /api/v1/vb-gratitude/shoutouts`,
> **Then** the response is **200** with the tenant feed in `data.shoutouts`,
> **but When** they attempt `POST /api/v1/vb-gratitude/shoutouts`,
> **Then** the response is **403** (blocked by `can:vb-gratitude.shoutouts.create.rooftop`),
> **and** `GET /api/v1/vb-gratitude/teammates` is **also 403** (the picker is gated on
> create, not read).

**Endpoints:** `GET /shoutouts` (200) · `POST /shoutouts` (403) · `GET /teammates` (403).
**Assert:** feed read succeeds; both create-gated calls deny. (Mixed: one green read,
two expected-deny — the `/teammates` 403 is the non-obvious one worth calling out.)

---

## Story 5 — Received side: the `receivedThisMonth` widget reflects a received shoutout (green)

**Persona (recipient):** a **staff-mapped** roster member — one whose user resolves to a
staff id via `StaffDirectory::staffIdForUser`. **Persona (giver):** any create-capable
role. *(Precondition: if the recipient user is not staff-mapped, the received count stays
0 by design — the harness should pick a recipient known to have a staff record, or treat
0 as a skip rather than a failure.)*

> **Given** the recipient has a staff record in the tenant and the
> `vb-gratitude.receivedThisMonth` widget currently reads 0 for them,
> **When** a giver sends that recipient a valid shoutout this month (`POST /shoutouts`
> → 201),
> **Then** the recipient's `receivedThisMonth` metric widget increments to reflect the
> received shoutout (widget payload `type: metric`, `value` increased by 1).

**Endpoints:** `POST /shoutouts` (giver) → the `vb-gratitude.receivedThisMonth` widget
resolver (recipient), asserted via the widget's rendered value / API payload after the
fetch settles.
**Assert:** the recipient's received metric goes from N to N+1 for the current month.
**Note:** the `appreciated` badge (10 received) is the same mechanism at a higher
threshold — out of scope to drive to 10 in a smoke journey, but the seam it rides
(`staffIdForUser`) is the one this story proves works end-to-end. (Positive.)

---

## Story 6 — Teammate picker returns PII-free roster (green)

**Persona:** `employee` (create grant → picker access).

> **Given** the plugin is enabled and the employee needs someone to thank,
> **When** they `GET /api/v1/vb-gratitude/teammates` (optionally with `?search=`),
> **Then** the response is **200** with `data.teammates` listing assignable staff by
> **safe fields only** (display names — **no** `work_email` or other PII), each carrying
> the `id` usable as `recipient_staff_id` in Story 1's `POST`.

**Endpoints:** `GET /teammates`.
**Assert:** 200; every teammate row exposes a name + id and **no** email/PII field.
(Positive; the PII-absence check is the point — this endpoint is the safe bridge into the
create flow.)

---

## Story 7 — Unknown recipient is a clean 422, not a 500 (negative / expected-deny)

**Persona:** `employee` (create grant), so this exercises the service, not the gate.

> **Given** the employee is authorized to give shoutouts,
> **When** they `POST /api/v1/vb-gratitude/shoutouts` with a **well-formed but
> non-existent** `recipient_staff_id` (a valid UUID that isn't an active teammate in
> this tenant),
> **Then** the response is **422** with an error envelope (a bad-input signal, never a
> 500), **and** no shoutout row is written (a follow-up `GET /shoutouts` shows no new
> row).

**Endpoints:** `POST /shoutouts` (422) → `GET /shoutouts` (unchanged).
**Assert:** 422 error envelope; feed count unchanged.
**Edge to call out — self-shoutout:** the service has **no giver==recipient guard**
(giver is a *user* id, recipient a *staff* id). If a staff-mapped user tags their own
staff record, it currently **records and may award points** — document the *actual*
behavior; do not assert a block that doesn't exist. If product later wants self-shoutouts
disallowed, that's a spec change, and this is the story that would flip to expected-deny.
(Negative / boundary.)

---

## Story 8 — Not-installed skip: the journey no-ops cleanly (harness gate)

**Persona:** any roster user in a tenant where `vb-gratitude` is **not** enabled.

> **Given** the tenant does **not** have `vb-gratitude` enabled,
> **When** the journey reads the shared Inertia `nav` prop and finds **no**
> `vb-gratitude` key,
> **Then** the journey **skips** the gratitude scenario and reports a clean skip — never
> a failed assertion, never a navigation to `/dashboard/plugins/vb-gratitude/view` (which
> would 404 or bounce when the plugin is absent).

**Endpoints:** none — this is the pre-flight gate for Stories 1–7.
**Assert:** absence of the `vb-gratitude` nav key ⇒ skip. This is the ONLY story that
reads Inertia props for anything gratitude-adjacent (the host-owned `nav`), and the only
one that is meaningful when the plugin is **not** enabled. (Neither green nor deny — a
conditional skip.)

---

## Coverage map

| # | Story | Type | Primary route |
|---|---|---|---|
| 1 | Happy path give + ledger + feed enrich | green | `POST /shoutouts` |
| 2 | `first_thanks` badge, idempotent | green | `GET /badges` |
| 3 | Daily allowance cap → 0 points | green boundary | `POST /shoutouts` ×4 |
| 4 | Auditor reads, cannot give | expected-deny | `POST /shoutouts` 403 |
| 5 | Received-side widget increments | green | `receivedThisMonth` widget |
| 6 | PII-free teammate picker | green | `GET /teammates` |
| 7 | Unknown recipient → 422 (+ self-shoutout note) | expected-deny | `POST /shoutouts` 422 |
| 8 | Not-installed skip | conditional skip | Inertia `nav` prop |
