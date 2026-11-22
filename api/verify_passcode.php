<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$passcode = $input['passcode'] ?? '';

if ($passcode !== '' && password_verify($passcode, ADMIN_PASSCODE_HASH)) {
    session_regenerate_id(true);
    $_SESSION['is_admin'] = true;
    json_response(['ok' => true]);
}
json_response(['ok' => false, 'message' => 'Incorrect passcode.'], 401);
