# LogBUk — Occupant modules

Run order (MySQL/MariaDB + PHP 8):

**The SQL file: `db/logbuk.sql`**

One self-contained script — schema, 6 tables, foreign keys, CHECK constraints, indexes,
seed rows, the view, both triggers and both stored procedures. Nothing needs to run before it.

```bash
mysql -u root -p < db/logbuk.sql      # drops + rebuilds logbuk_db
php -S localhost:8000 -t repo001
```

`db/sql-ver-002.sql`, `sql-ver-003.sql` and `sql-ver-004.sql` are the incremental history of how
the schema got here. You do **not** need them if you use `logbuk.sql`.

`occupants_reg_status` stays **`ENUM('Active','Inactive')`, `DEFAULT 'Active'`**. The rules are
what render an occupant `Inactive`; nothing converts the column type.
The 2-week rule is live: `sp_auto_deactivate_inactive()` + `trg_logs_after_*` triggers,
run lazily once a day or on demand via `api/auto_deactivate.php?force=1`.
The semester-end rule is planned in `plan-rey-002.md`.

Then open <http://localhost:8000/index.html> → Admin → `admin123` → **Occupants**
(or go straight to `/pages/occupants.php`). The dashboard's Occupants link works too.

---

## 1. SQL for DB generation  —— done (yours) + one required patch

| File | What it is |
|---|---|
| `db/logbuk.sql` | **the SQL deliverable** — complete build in one file |
| `db/sql-ver-002.sql` | your original forward-engineered schema, byte-identical |
| `db/sql-ver-003.sql` | **patch 1** — required by module 4 (see below) |
| `db/sql-ver-004.sql` | **patch 2** — 2-week inactivity rule (status column untouched) |
| `db/test-ver-004.sql` | PASS/FAIL self-test for patch 2 |
| `db/seed-occupants.sql` | 6 rooms, 9 occupants, 6 log rows, 1 admin (`admin123`) |

`sql-ver-003.sql` changes only what deactivation needs:

| Change | Why |
|---|---|
| `Occupants` PK → `occupants_id` only (was a 3-column composite) | a composite PK forced `Logs` to reference *(occupant, room)* |
| `Logs` FK → `Occupants(occupants_id)` + new FK to `Rooms` | the room on a log row is now a **historical snapshot**; clearing an occupant's room no longer hits error 1451 |
| `Occupants.Rooms_room_id` → NULLABLE | the deactivate dialog promises they *leave the room* |
| `CHECK chk_occupants_status_room` | `Active` ⇒ must have a room; `Inactive` ⇒ must have none |
| `CHECK chk_contact_number` | `09XXXXXXXXX`, 11 digits |
| 4 indexes | `idx_occupants_name`, `_reg_status`, `_reg_at`, `_room_status`, `idx_logs_occupant_time` |
| `OccupancyAudit` table | who / when / why / previous room for every register, update, deactivation, reactivation |
| `occupants_last_seen_at` + `trg_logs_after_*` (patch 2) | last log activity, kept current by triggers |
| `occupants_deactivated_at` / `_reason` / `_source` (patch 2) | when and why the status became `Inactive` |

Verified: clearing the room on an Active occupant is rejected, a bad contact number is
rejected, and deactivating a timed-in occupant keeps their log rows.

## 2. Occupants interface (html) — `occupants.php`

Server-rendered list: ID, full name, program, contact, room, presence, residency,
registered at, actions. `occupants.html` is a redirect to it so the existing
`dashboard.js` sidebar link lands on the real page.

## 3. Sort, filter and search (PHP + JS)

**PHP** — all of it happens in SQL, so the page works with JavaScript off:

| Feature | Where | Notes |
|---|---|---|
| Search | `includes/occupants.php → occupants_where()` | one field, matches ID, first/middle/last name, program **or** contact number |
| Filter | same | Room, Residency (Active/Inactive), Presence (Inside/Outside) |
| Sort | `occupants_sort_columns()` | 8 sortable headers, whitelist-mapped to real columns (never interpolated), asc/desc toggle, always tie-broken by ID |
| Paging | `occupants_page()` + `pagination_items()` | 5 per page, `LIMIT/OFFSET`, `1 … 4 5 6 … 12` pager |

**JS** — `assets/js/occupants.js` makes it feel instant: 250 ms debounce on typing,
in-place swap of just the table card, URL kept in sync (`?q=&room=&residency=&presence=&sort=&dir=&page=`).
Every URL is a real, shareable, bookmarkable page.

## 4. Deactivation (HTML modals + SQL status condition)

- `⛔` in the Actions column → **Deactivate occupant?** modal: "…will become Inactive and
  leave Room 0101. Their log history is kept. They can be reactivated later with a new room."
  Optional **reason** dropdown (end of semester / 2 weeks outside / withdrew / transferred).
- `↻` on an inactive row → **Reactivate occupant?** modal, must pick a room;
  full rooms are `disabled` in the dropdown. Occupant ID and log history are kept.
- Endpoint: `api/occupants_api.php` (`action=deactivate|reactivate|validate`).
  Server re-checks everything; the UI cannot bypass a rule.
- Toast confirms with the real numbers: *"01-0001 deactivated. Room 0101 now has 1/4 slots used."*
- If a deactivated occupant is still timed in, their open log is closed automatically
  so the dashboard's On-Premises count stays honest.
- Every action writes an `OccupancyAudit` row.

## 5. Add / Edit forms (HTML)

`register-occupant.php` and `edit-occupant.php` share `includes/occupant-form.php`.

- Occupant ID is **read-only and generated**: room `0202` → `02-0004`, taken from the
  floor of the chosen room. On edit it is shown but never changes.
- Live **Room Occupancy** panel (`1/4` + four person icons) follows the room select.
- Suffix dropdown (None/Jr./Sr./II/III/IV), optional middle name and program.
- Validation in **three** places, same rules: `assets/js/occupant-form.js` (instant),
  an AJAX `validate` call before submit, and `validate_occupant_input()` on the server.
- **Max 4 per room** is enforced with `SELECT … FOR UPDATE` inside a transaction, so two
  admins saving at the same moment cannot both land the 5th occupant.
- Both forms are plain HTML `POST`s — they still work if JavaScript fails.

---

## Known gaps (not part of modules 2–5)

- **Part B is not enforced yet**: `occupants.php`, `register-occupant.php` and
  `edit-occupant.php` do not call `require_admin()`. The API endpoints exist and
  `verify_passcode.php` works; adding the page guard is a two-line change per page when you want it.
- **Automatic** deactivation (2-week outside streak, end of semester) is not built — only the
  manual module. `OccupancyAudit.audit_reason` and `Settings.settings_curfew_time` are the hooks for it.
- `rooms.html`, `logs.html`, `reports.html` still don't exist (sidebar links 404).
- Lives in MariaDB 11.8 here; the SQL is MySQL 8 compatible, but the inbox `now()` /
  `REGEXP` CHECK on contact numbers is MariaDB-verified only.


---

## Folder layout and navigation

Every screen except `index.html` lives in `pages/`. Paths were updated to match:

| From | To | Changed |
|---|---|---|
| `pages/*.php`, `pages/*.html` | CSS / JS / images | `assets/…` -> `../assets/…` |
| `pages/*.php` | PHP includes | `__DIR__ . '/includes/…'` -> `'../includes/…'` |
| `pages/*.php` | API calls | `../api/occupants_api.php` |
| `assets/js/main.js` | API root | `API_BASE` derived from the page URL, so it works from `/` and `/pages` |
| `assets/js/dashboard.js` | sidebar + redirects | siblings for pages, `../index.html` for the entry screen |
| `assets/js/occupant-form.js` | form validation fetch | `../api/occupants_api.php` |
| `assets/js/index.js` | post-passcode redirect | `pages/dashboard.html` |

Sidebar links for modules that are not built yet (**Rooms**, **Dormitory Logs**,
**Reports**) point at `pages/404-page.html?from=<module>` instead of a missing file.
Both sidebars must stay in sync: `assets/js/dashboard.js` (for `dashboard.html`) and
`includes/sidebar.php` (for the PHP pages).

A root `.htaccess` adds `ErrorDocument 404 /pages/404-page.html` so any other missing
path also lands on that screen under Apache. The PHP built-in server ignores
`.htaccess`; that is fine, because no link relies on it.

## 6. Access guard  —— done

| File | Purpose |
|---|---|
| `includes/auth.php` | `require_admin_page()` redirects to `../index.html#admin`; `require_admin()` answers 401 for /api |
| `pages/occupants.php`, `register-occupant.php`, `edit-occupant.php` | call `require_admin_page()` before any query |
| `api/logout.php` | destroys the session; `assets/js/occupants.js` posts to it |
| `pages/db-check.php` | connection doctor: config → port → login → database → tables → ENUM → seed → routines → triggers |

`assets/js/main.js` runs with `USE_MOCK: false`, so the dashboard and the list read
MySQL instead of the sample data.

## 7. 404 page  —— done

| File | Purpose |
|---|---|
| `pages/404-page.html` | stand-in for Rooms, Dormitory Logs, Reports and any other unbuilt screen |

Reads `?from=` to name the module in the heading and the page title, then offers
**Back to Dashboard**, **Occupants** and **Home**. Styling is inline so the page
still renders if the CSS bundle is missing; it needs no database and no JavaScript.

## 7. Occupant E-Logbook — Time-In & Time-Out  —— done

Open `index.html` → **Continue as Occupant**. Three screens inside `#view-occupant`:
occupant ID → occupant information (**TIME-IN** when off premises, **whereabouts + TIME-OUT**
when on premises) → confirmation (with the late-entry notice).

| File | Purpose |
|---|---|
| `api/occupant_logbook.php` | `verify` / `time_in` / `time_out` / `reset`; every rule is enforced here |
| `assets/js/occupant-logbook.js` | the three screens; only displays what the API decides |
| `assets/css/occupant-logbook.css` | styles for screens 2 and 3 (dorm-room background) |
| `db/sql-ver-005-whereabouts-text.sql` | **optional** — keeps the exact whereabouts text next to the 1–5 code |

Rules (all server-side, in transactions that lock the occupant row):

- ID must exist and be `Active` with a room, otherwise it is refused.
- Inside/outside comes from an **open log** (`logs_time_out IS NULL`); `occupants_loc_status` is rewritten from it on every transaction.
- Time-in is refused while an open log exists. The log stores the time-in, the room snapshot and `logs_is_late`.
- Late = after `Settings.settings_curfew_time`, or before `LATE_UNTIL_HOUR` (config, default 5 = 5 AM).
- Time-out needs an open log and a non-empty whereabouts, and must be strictly after the time-in.
- `logs_whereabouts` is the 1–5 code (Canteen/Library/Class/Home/Others), guessed from the typed text. With patch 005 the typed text is kept too.
- `assets/js/main.js` `setFieldError()` now targets the message span, so the warning icon stays visible.
