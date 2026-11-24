<?php
/**
 * LogBUk configuration.
 * Works with the schema in sql-ver-002.sql (database name: logbuk_db).
 * If your local database is named `logbuk`, just change DB_NAME below.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'logbuk_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// Socket used when DB_HOST is 'localhost' (XAMPP users can leave this empty).
define('DB_SOCKET', '');

// Occupants per page in the admin list.
define('OCCUPANTS_PER_PAGE', 5);

// Time-in after this hour counts as a late entry (24h clock, matches Settings.settings_curfew_time).
define('LATE_CUTOFF_HOUR', 22);

// Time-ins between midnight and this hour also count as late (a 12:30 AM entry is not "on time").
// Set to 0 to flag only entries after the curfew until midnight.
define('LATE_UNTIL_HOUR', 5);

/*
 * Fallback admin passcode, used only when the Admins table is empty.
 * bcrypt hash of "admin123" -- change with:
 *   php -r "echo password_hash('yourPasscode', PASSWORD_DEFAULT);"
 */
define('ADMIN_PASSCODE_HASH', '$2y$10$FHWRlhH5pOvMeFGMcZMJcuCCyeO51Wl//Ho4YZoPieVsFBN.cw9NK');
