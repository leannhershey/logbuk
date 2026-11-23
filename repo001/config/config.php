<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'logbuk_db');
define('DB_USER', 'root');
define('DB_PASS', '');

define('DB_SOCKET', '');

define('OCCUPANTS_PER_PAGE', 5);

define('LATE_CUTOFF_HOUR', 22);

/*
 * Fallback admin passcode, used only when the Admins table is empty.
 * bcrypt hash of "admin123" -- change with:
 *   php -r "echo password_hash('yourPasscode', PASSWORD_DEFAULT);"
 */
define('ADMIN_PASSCODE_HASH', '$2y$10$FHWRlhH5pOvMeFGMcZMJcuCCyeO51Wl//Ho4YZoPieVsFBN.cw9NK');