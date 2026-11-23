<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/occupants.php';

header('Content-Type: application/json');
require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    json_error('Method not allowed. Use POST.', 405);
}

$input  = request_input();
$action = (string) ($input['action'] ?? '');

try {
    switch ($action) {
        case 'deactivate':
            $id = trim((string) ($input['occupant_id'] ?? ''));
            if ($id === '') {
                json_error('occupant_id is required.', 400);
            }
            $result = occupant_deactivate($id, $input['reason'] ?? null, current_admin_id());
            if (!$result['ok']) {
                json_response($result, 409);
            }
            json_response($result);

        case 'reactivate':
            $id     = trim((string) ($input['occupant_id'] ?? ''));
            $roomId = trim((string) ($input['room_id'] ?? ''));
            if ($id === '' || $roomId === '') {
                json_error('occupant_id and room_id are required.', 400);
            }
            $result = occupant_reactivate($id, $roomId, current_admin_id());
            json_response($result, $result['ok'] ? 200 : 409);

        case 'validate':
            [, $errors] = validate_occupant_input($input, $input['occupant_id'] ?? null);
            if ($errors) {
                json_response(['ok' => false, 'errors' => $errors, 'message' => 'Please fix the highlighted fields.'], 422);
            }
            json_response(['ok' => true]);

        case 'stats':
            json_response(['ok' => true, 'stats' => occupants_stats()]);

        default:
            json_error('Unknown action.', 400);
    }
} catch (Throwable $ex) {
    error_log('occupants_api: ' . $ex->getMessage());
    json_error('Server error: ' . $ex->getMessage(), 500);
}