# LogBUk (LEANN & JHON REY'S ROLE DONE)

Bicol University Dormitory Digitalized Log Book System.

## Folder structure

```
repo001/
├── index.html                  
├── .htaccess                   no directory listing, friendly 404
├── README.md                   
│
├── pages/                     
│   ├── dashboard.html          
│   ├── occupants.php           
│   ├── register-occupant.php   
│   ├── edit-occupant.php       
│   └──  db-check.php            
│
├── app/
│   ├── bootstrap.php
│   └── classes.php
│
├── api/                       
│   ├── occupants_api.php      
│   └──  verify_passcode.php · dashboard.php · auto_deactivate.php · logout.php
│
├── includes/                   
│   ├── db.php · auth.php · occupants.php · occupant-form.php
│   └──  sidebar.php · auto-deactivate.php
│
├── config/config.php        
│
├── assets/
│   ├── css/app.css             
│   ├── js/                     app.js (shared) · index.js · dashboard.js · occupants.js
│   └── images/
│
├── db/                      
│   ├── logbuk.sql             
│   └──  tests/test-ver-004.sql 
│
└── 
```

## Run it (XAMPP / WAMP / Apache)

**Always open the site through `http://localhost/...`, never as a file.** A `.php` file
opened as `file:///...` shows its source, because only Apache + PHP execute it.

1. Put the folder at `C:\xampp\htdocs\repo001` (so `repo001\index.html` exists).
2. XAMPP Control Panel → start **Apache** and **MySQL**.
3. phpMyAdmin → Import → `repo001/db/logbuk.sql` → Go
   (or `mysql -u root < db/logbuk.sql`). This drops and rebuilds `logbuk_db`
   with 6 tables, view, triggers, procedures and 9 demo occupants.
4. Check `config/config.php` (XAMPP default: user `root`, empty password).
5. Open `http://localhost/repo001/` → Admin → passcode `admin123` → Occupants.

Without Apache: `php -S localhost:8000 -t repo001`, then `http://localhost:8000/`.

| Symptom | Cause / fix |
|---|---|
| Page shows PHP code | You are on `file://`. Use the `http://localhost/repo001/` address. |
| "Object not found" | Folder is not exactly `htdocs/repo001`, or the name's case differs. |
| "Unable to connect" | Apache is not running. |
| "Database not reachable" | Open `pages/db-check.php`: it lists each check OK/FAIL with the fix. |
| `[1045] Access denied` | Set `DB_PASS` in `config/config.php`. |
| `[1049] Unknown database` / table missing | Import `db/logbuk.sql` (never mix it with `db/archive/`). |
| `[2002] Connection refused` | Start MySQL. |