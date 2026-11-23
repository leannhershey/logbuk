<?php
require_once __DIR__ . '/../includes/auth.php';

require_post();

$input    = request_input();
$passcode = (string) ($input['passcode'] ?? '');

if ($passcode === '') {
    json_error('Please enter the passcode.', 400);
}

$adminId = null;

try {
    $rows = db()->query('SELECT admins_id, admins_passcode FROM Admins')->fetchAll();
} catch (Throwable $ex) {
    $rows = [];
}

foreach ($rows as $row) {
    if (password_verify($passcode, $row['admins_passcode'])) {
        $adminId = (int) $row['admins_id'];
        break;
    }
}

// Fallback so a fresh database without an Admins row still has a way in.
if ($adminId === null && defined('ADMIN_PASSCODE_HASH') && password_verify($passcode, ADMIN_PASSCODE_HASH)) {
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