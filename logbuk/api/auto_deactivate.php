<?php

/* Runs the inactivity rule now. Cron can pass CRON_SECRET instead of logging in. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/occupants.php';

$secret = defined('CRON_SECRET') ? (string) CRON_SECRET : '';
$given  = (string) ($_SERVER['HTTP_X_CRON_KEY'] ?? $_GET['key'] ?? '');
if ($secret === '' || !hash_equals($secret, $given)) {
    require_admin();
}

try {
    $force  = isset($_GET['force']) || isset($_GET['run']);
    $result = $force
        ? auto_deactivate_if_due(0)
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
