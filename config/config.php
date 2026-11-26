<?php

/* App settings. The five DB values are the only lines you normally touch. */

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'logbuk_db');
define('DB_USER', 'root');
define('DB_PASS', '');

define('ADMIN_PASSCODE_HASH', '$2y$10$FHWRlhH5pOvMeFGMcZMJcuCCyeO51Wl//Ho4YZoPieVsFBN.cw9NK');

define('CRON_SECRET', '');

define('OCCUPANTS_PER_PAGE', 10);
define('LOGS_PER_PAGE', 10);

define('LATE_CUTOFF_HOUR', 22);
define('LATE_UNTIL_HOUR', 5);
