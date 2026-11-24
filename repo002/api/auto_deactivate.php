<?php
/**
 * Runs the inactivity rule on demand.
 *
 *   GET  api/auto_deactivate.php            -> runs only if it is due (24h)
 *   GET  api/auto_deactivate.php?force=1    -> runs regardless (admin)
 *   GET  api/auto_deactivate.php?run=1      -> alias of force
 *
 * Point a cron job at ?force=1 once a day if your host allows it:
 *   0 2 * * *  curl -s "http://localhost/logbuk/api/auto_deactivate.php?force=1"
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/occupants.php';
require_once __DIR__ . '/../includes/auto-deactivate.php';

try {
    $force  = isset($_GET['force']) || isset($_GET['run']);
    $result = $force
        ? auto_deactivate_if_due(0)     // everyHours = 0 -> always due
        : auto_deactivate_if_due(24);

    json_response([
        'ok'          => true,
        'ran'         => $result['ran'],
        'message'     => auto_deactivate_summary($result),
        'deactivated' => $result['deactivated'],
        'stats'       => occupants_stats(),
    ]);
} catch (Throwable $ex) {
    error_log('auto_deactivate: ' . $ex->getMessage());
    json_error('Could not run the inactivity rule: ' . $ex->getMessage(), 500);
}
