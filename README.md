# LogBUk: Bicol University Dormitory **Digitalized Log Book**. 
-Occupants time in and out at a kiosk; the dormitory admin gets a dashboard, occupant and room records, log history and reports.

**Built with** PHP 8.1+ · MySQL / MariaDB · plain HTML, CSS and JavaScript.

---

## 1. Requirements

| What | Version |
|---|---|
| PHP | 8.1 or newer (with `pdo_mysql`) |
| MySQL / MariaDB | any recent version (XAMPP's is fine) |
| Browser | Chrome, Edge or Firefox |

## 2. Install (XAMPP, 3 steps)

1. Copy this folder into `C:\xampp\htdocs\` (the folder name becomes the URL, e.g. `htdocs\logbuk`).
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Import the database: open <http://localhost/phpmyadmin> → **Import** → choose `db/logbuk.sql` → **Go**.
   That one file creates the `logbuk_db` database, the 5 tables, the triggers, the stored procedure and the demo data.

Open the app at <http://localhost/logbuk/>.

> Prefer PHP's built-in server? Run `php -S localhost:8000` inside this folder and open <http://localhost:8000/>.
> Always use `http://...` — opening a `.php` file straight from the file explorer shows its code and cannot work.

**Admin passcode: `admin123`** (entrance screen → *Continue as Admin*).
Change it right away in **Settings → Change Passcode**, or replace `ADMIN_PASSCODE_HASH` in `config/config.php`.

## 3. How it is used

**Kiosk (occupants).** The start screen offers two doors. Occupants pick *Occupant*, type their ID
(e.g. `01-0001`), then press **TIME-IN**, or **TIME-OUT** with a whereabouts. Each transaction is
recorded and shown back as a receipt.

**Admin screens** (sidebar, after the passcode):

| Screen | What it does |
|--------|--------------|
| Dashboard | totals, on/off premises pie, recent activity |
| Occupants | search, filter, register, edit, deactivate / reactivate |
| Rooms | capacity, occupants and free slots per room |
| Dormitory Logs | full log history with tabs (all / late / inside / outside) |
| Reports | daily and monthly figures, print view, PDF download |
| Settings | change passcode, manage administrators |

Late rule: a time-in after **22:00** or before **05:00** is flagged as late. Change the hours in `config/config.php`.
Automatic deactivation: an occupant with no log activity for **14 days** is set Inactive (once a day, in the background).

## 4. Folder structure

```
logbuk/
├── index.html            
├── .htaccess             
├── config/config.php     
├── includes/             
│   ├── db.php            
│   ├── auth.php          
│   ├── head.php          
│   ├── sidebar.php       
│   ├── occupant-form.php 
│   ├── occupants.php     
│   ├── rooms.php         
│   ├── logs.php          
│   └── reports.php       
├── pages/               
├── api/                  
├── assets/
│   ├── css/              base.css (tokens + shared), admin.css, kiosk.css
│   ├── js/               core.js, admin-shell.js, kiosk.js, dashboard.js,
│   │                     occupants.js, logs.js, report-pdf.js, daily-report-pdf.js
│   └── images/
└── db/logbuk.sql         
```

## 5. Configuration (`config/config.php`)

| Constant | Meaning |
|----------|---------|
| `DB_HOST`, `DB_PORT`, `DB_NAME` | where MySQL is (`logbuk_db` by default) |
| `DB_USER`, `DB_PASS` | the MySQL login — set these to match your XAMPP |
| `ADMIN_PASSCODE_HASH` | fallback passcode hash, used only when the `Admin` table is empty |
| `CRON_SECRET` | lets cron call `api/auto_deactivate.php?key=...` without logging in (`''` = admins only) |
| `LATE_CUTOFF_HOUR`, `LATE_UNTIL_HOUR` | what counts as a late time-in |
| `OCCUPANTS_PER_PAGE`, `LOGS_PER_PAGE` | rows per page |

## 6. Database
| Table | Columns |
|-------|---------|
| **Rooms** | `room_id` (PK), `capacity` |
| **Occupants** | `occupant_id` (PK), `room_id` (FK), `registered_by` (FK → Admin), `first_name`, `middle_name`, `last_name`, `suffix`, `program`, `contact_number`, `presence_status`, `residency_status`, `registered_at` |
| **Logs** | `log_id` (PK), `occupant_id` (FK), `room_id` (FK), `time_in`, `time_out`, `whereabouts`, `is_late` |
| **Admin** | `admin_id` (PK), `passcode`, `created_at` |
| **Settings** | `setting_id` (PK), `updated_by` (FK → Admin), `curfew_time`, `updated_at` |

Two enum sets are worth knowing: `presence_status` is `On Premises` / `Off Premises` (who is inside right now)
and `residency_status` is `Active` / `Inactive` (who still lives in the dorm). Relationship: one Room
accommodates many Occupants, one Occupant has many Logs, one Admin registers many Occupants and manages Settings.

Beyond the ERD, seven helper columns support the 14-day inactivity rule: `last_seen_at`, `deactivated_at`,
`deactivate_reason`, `deactivate_source` on Occupants, plus `inactivity_days`, `auto_deactivate` and `last_auto_run`
on Settings. Two triggers keep `last_seen_at` current, and `sp_auto_deactivate_inactive` runs the rule.

`whereabouts` is free text the occupant types at time-out (max 150 characters), so an open log has none yet.

## 7. If something goes wrong

| What you see | Fix |
|---|---|
| Page shows PHP source code | open it through `http://localhost/...`, not `file:///` |
| "Object not found" | the folder must sit in `htdocs` and contain `index.html` |
| "MySQL refused that user and password" | fix `DB_USER` / `DB_PASS` in `config/config.php` |
| "The database does not exist yet" | import `db/logbuk.sql` in phpMyAdmin |
| "Nothing is answering on the MySQL port" | start **MySQL** in the XAMPP Control Panel |
| No styling or icons | the URL is wrong: it must start with `http://localhost/logbuk/` |
| Sent back to the passcode screen | normal when there is no admin session yet |

## 8. Developers

Elisha Faith R. Alcazar,  
Katreena Allysa C. Asiado,  
Jhon Rey B. Barrameda,  
Leann Hershey A. Secillano,  
Mary Chlowee Nicolette F. Vichozo
