<?php

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        // set to true on a real HTTPS deployment
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';

/**
 * Page guard for the server-rendered admin screens.
 *
 * Same session check as require_admin(), but the two callers need opposite
 * answers: an API endpoint should reply 401 JSON, while a page should send the
 * browser back to the passcode form. That difference is why this exists
 * separately instead of reusing require_admin() on the .php screens.
 */
function require_admin_page(): void
{
    if (empty($_SESSION['is_admin'])) {
        header('Location: ../index.html?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '') . '#admin');
        exit;
    }
}

/** 401 JSON unless the admin passcode was verified in this session. */
function require_admin(): void
{
    if (empty($_SESSION['is_admin'])) {
        json_error('Admin verification required.', 401);
    }
}

/** Admin id for the audit trail, or null when not logged in. */
function current_admin_id(): ?int
{
    return !empty($_SESSION['is_admin']) ? (int) ($_SESSION['admin_id'] ?? 1) : null;
}

/** Read JSON (or form-encoded) request body as an array. */
function request_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return $_POST ?: [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ($_POST ?: []);
}

/** Reject anything that is not a POST. */
function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        json_error('Method not allowed. Use POST.', 405);
    }
}