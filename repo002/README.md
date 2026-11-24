# LogBUk — repo001

Bicol University Dormitory Digitalized Log Book System.
This folder is the organised project: the uploaded source, the SQL, and everything
listed in `MODULES.md`, arranged in the final folder layout.

---

## Folder layout

```
repo001/
├── index.html                     role selection -> occupant ID -> admin passcode
├── .htaccess                      friendly 404 + no directory listings
├── README.md                      this file
├── HOW-TO-RUN.txt                 ▲ XAMPP setup + "why do I see PHP code?" 
├── MODULES.md                     what each module is and which file implements it
│
├── pages/                         ▲ every screen except index.html lives here
│   ├── dashboard.html             admin dashboard (stat cards, pie, recent activity)
│   ├── occupants.php              Occupants list: search, filters, sorting, pagination
│   ├── occupants.html             legacy link -> redirects to occupants.php
│   ├── register-occupant.php      add an occupant
│   ├── edit-occupant.php          edit / move room
│   ├── db-check.php               connection doctor (open it when a page says "Database not reachable")
│   └── 404-page.html              shown for modules that are not built yet
│
├── assets/
│   ├── css/       main.css · dashboard.css · occupants.css
│   ├── js/        main.js · index.js · dashboard.js · occupants.js
│   │              occupant-form.js · tooltips.js
│   └── images/    logo.png · logo_header.png · logo_white.png · backgrounds
│
├── api/                           JSON endpoints (fetch targets)
│   ├── occupants_api.php          deactivate | reactivate | validate | stats
│   ├── verify_passcode.php        admin passcode check
│   ├── dashboard.php              dashboard figures
│   ├── auto_deactivate.php        manual/cron trigger for the 2-week rule
│   └── logout.php
│
├── includes/                      server-side shared code
│   ├── db.php · auth.php · occupants.php · occupant-form.php
│   ├── sidebar.php                sidebar renderer (PHP pages)
│   └── auto-deactivate.php        lazy runner for the 2-week rule
│
├── config/config.php              DB credentials, passcode hash, page size
├── db/                            SQL — see "Database" below
└── uploads/                       the images/zip artefacts you sent, for reference
```

**Why `pages/`:** everything except the entry screen sits one level down, so every
relative path inside `pages/` starts with `../` (assets, api) and links to a sibling
page are bare filenames (`occupants.php`, `dashboard.html`).

---

## Running it

```bash
# 1. database (one file, drops and rebuilds logbuk_db)
mysql -u root -p < db/logbuk.sql

# 2. serve the folder as the document root
php -S localhost:8000 -t repo001

# 3. open
http://localhost:8000/index.html      Admin -> admin123 -> Occupants
```

On XAMPP/Apache, drop `repo001` into `htdocs`, start Apache + MySQL, import
`db/logbuk.sql` in phpMyAdmin, then open `http://localhost/repo001/`.

> **Open the site through `http://localhost/repo001/`, never by double-clicking a file.**
> A `.php` file on disk is plain text; only Apache + PHP turn it into a page. Opened
> as `file:///C:/xampp/htdocs/repo001/pages/occupants.php` the browser shows the source
> code. Full checklist in `HOW-TO-RUN.txt`.

---

## Database

| File | What it is |
|---|---|
| `db/logbuk.sql` | **the deliverable** — schema, 6 tables, FKs, CHECKs, indexes, seed rows, view, 2 triggers, 2 procedures |
| `db/sql-ver-002.sql` | your original forward-engineered schema, unmodified |
| `db/sql-ver-003.sql` | patch 1 — deactivation support + `OccupancyAudit` |
| `db/sql-ver-004.sql` | patch 2 — 2-week inactivity rule (status column stays an `ENUM`) |
| `db/test-ver-004.sql` | PASS/FAIL self-test for patch 2 (27 checks) |
| `db/seed-occupants.sql` | the same demo rows as `logbuk.sql`, kept separate for re-seeding |

`logbuk.sql` already contains everything in 002/003/004. Use either the one file
**or** the 002 → 003 → seed → 004 chain, never both on the same database.

`occupants_reg_status` is `ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'`.
The 2-week rule and manual deactivation are what render an occupant `Inactive`.

---

## Access control

The three server-rendered screens are guarded: `pages/occupants.php`,
`register-occupant.php` and `edit-occupant.php` start with

```php
require_once __DIR__ . '/../includes/auth.php';
require_admin_page();          // no session -> ../index.html?next=...&admin
```

`require_admin_page()` (in `includes/auth.php`) is the page-level twin of
`require_admin()`, which the API endpoints use: a page redirects to the passcode
form, an endpoint answers `401 JSON`. The session is created by
`api/verify_passcode.php` after a bcrypt check against `Admins` and destroyed by
`api/logout.php` — the sidebar's log out button calls it, so logging out really
ends the session rather than just navigating home.

`USE_MOCK` in `assets/js/main.js` is **false**: every screen reads MySQL through /api.

## Navigation, and the 404 page

The sidebar keeps every planned module visible. Anything not built yet
(**Rooms**, **Dormitory Logs**, **Reports**) links to
`pages/404-page.html?from=<module>` instead of a dead `href`, so a click never
lands on a server error page. The 404 screen names the module that was clicked and
offers Dashboard / Occupants / Home.

Both the JavaScript sidebar (`assets/js/dashboard.js`, used by `dashboard.html`)
and the PHP sidebar (`includes/sidebar.php`, used by the `.php` pages) point at the
same URLs — they must be edited together.

---

## Status

| Piece | State |
|---|---|
| Login / occupant ID / admin dashboard | done (Leann) |
| Occupant ID validation | done (Elisha) |
| MySQL schema | done (Rey) |
| Occupants list, add, edit, search, room assignment | done |
| Manual deactivate / reactivate with a new room | done |
| 2-week inactivity auto-deactivation | done |
| Semester-end auto-deactivation | planned only — `../plan-rey-002.md` |
| Rooms / Dormitory Logs / Reports screens | not built — served by `404-page.html` |
| Passcode guard on the admin pages | done — `require_admin_page()` |
| Session logout | done — `api/logout.php` |


---

## When a page says "Database not reachable"

Open **`http://localhost/repo001/pages/db-check.php`**. It reads the same
`config/config.php`, tries the same login, and lists each check as OK / FAIL with the
fix under the failing row: PHP version, PDO MySQL driver, MySQL port, socket, login,
database present, the 6 tables, the ENUM column, seed rows, procedures, triggers,
admin row.

The occupant list itself now explains the common failures instead of printing raw SQL:

| MySQL message | What the page tells you |
|---|---|
| `[1045] Access denied` | MySQL refused the login — set `DB_PASS` in `config/config.php` |
| `[1049] Unknown database` | import `db/logbuk.sql` in phpMyAdmin |
| `[2002] Connection refused` | start MySQL in the XAMPP Control Panel |
| `Table … doesn't exist` | re-import `db/logbuk.sql`; don't mix it with the 002→004 chain |
