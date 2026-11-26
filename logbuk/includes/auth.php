<?php

/* Session start, admin guards and request helpers shared by the pages and the API. */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';

/* Pages bounce to the passcode screen; API endpoints answer 401 JSON instead. */
function require_admin_page(): void
{
    if (empty($_SESSION['is_admin'])) {
        header('Location: ../index.html?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '') . '#admin');
        exit;
    }
}

function require_admin(): void
{
    if (empty($_SESSION['is_admin'])) {
        json_error('Admin verification required.', 401);
    }
}

/* Also strips invisible unicode spaces, which is why a pasted passcode can look "wrong". */
function normalise_passcode(string $value): string
{
    $out = preg_replace('/^[\s\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', '', $value);
    $out = preg_replace('/[\s\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', (string) $out);

    return $out === null ? trim($value) : (string) $out;
}

/* bcrypt only compares the first 72 bytes, so anything longer is refused outright. */
function passcode_matches(string $typed, ?string $hash): bool
{
    if ($hash === null || $hash === '') {
        return false;
    }

    if (strlen($typed) > 72) {
        return false;
    }
    return password_verify($typed, $hash);
}

function passcode_too_long(string $typed): bool
{
    return strlen($typed) > 72;
}

function current_admin_id(): ?int
{
    return !empty($_SESSION['is_admin']) ? (int) ($_SESSION['admin_id'] ?? 1) : null;
}

function request_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return $_POST ?: [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ($_POST ?: []);
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        json_error('Method not allowed. Use POST.', 405);
    }
}
