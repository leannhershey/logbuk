<?php

/* Checks the admin passcode and starts the admin session. */

require_once __DIR__ . '/../includes/auth.php';

require_post();

$input    = request_input();

$passcode = normalise_passcode((string) ($input['passcode'] ?? ''));

if ($passcode === '') {
    json_error('Please enter the passcode.', 400);
}

if (passcode_too_long($passcode)) {
    json_error('That passcode is longer than 72 characters, which cannot be stored. Check for a stray paste.', 400);
}

$adminId = null;

try {
    $rows = db()->query('SELECT admin_id, passcode FROM Admin')->fetchAll();
} catch (Throwable $ex) {
    error_log('verify_passcode: ' . $ex->getMessage());
    json_error('Database error. Check config/config.php and make sure db/logbuk.sql is imported.', 503);
}

foreach ($rows as $row) {
    if (passcode_matches($passcode, $row['passcode'])) {
        $adminId = (int) $row['admin_id'];
        break;
    }
}

if ($adminId === null && $rows === [] && defined('ADMIN_PASSCODE_HASH') && passcode_matches($passcode, ADMIN_PASSCODE_HASH)) {
    $adminId = 1;
}

if ($adminId === null) {
    json_error('Incorrect passcode.', 401);
}

session_regenerate_id(true);
$_SESSION['is_admin']  = true;
$_SESSION['admin_id']  = $adminId;
$_SESSION['verified_at'] = time();

json_response(['ok' => true, 'admin_id' => $adminId]);
