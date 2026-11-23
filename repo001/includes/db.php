<?php
require_once __DIR__ . '/../config/config.php';

/** Shared PDO handle (MySQL). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = DB_SOCKET !== ''
        ? 'mysql:unix_socket=' . DB_SOCKET . ';dbname=' . DB_NAME . ';charset=utf8mb4'
        : 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_response(['ok' => false, 'message' => $message], $status);
}

/**
 * Turn a PDO error message into something a person can act on.
 * Every branch names the file to open and what to look for.
 */
function db_error_hint(string $msg): array
{
    $m = strtolower($msg);

    if (str_contains($m, 'access denied')) {
        return [
            'title' => 'MySQL refused the login.',
            'steps' => [
                'MySQL is running, but it rejected <code>' . htmlspecialchars(DB_USER, ENT_QUOTES) . '</code> / the password in <code>config/config.php</code>.',
                DB_PASS === ''
                    ? 'You have <strong>no password set</strong> in that file. If your XAMPP MySQL root has a password, add it there:<br><code>define(\'DB_PASS\', \'yourPassword\');</code>'
                    : 'You have a password set in that file. If your MySQL has no root password, make it empty:<br><code>define(\'DB_PASS\', \'\');</code>',
                'Unsure which? Open <a href="http://localhost/phpmyadmin" target="_blank" rel="noopener">phpMyAdmin</a> and try logging in as <code>' . htmlspecialchars(DB_USER, ENT_QUOTES) . '</code>.',
            ],
        ];
    }

    if (str_contains($m, 'unknown database')) {
        return [
            'title' => 'The database has not been created yet.',
            'steps' => [
                'Import <code>db/logbuk.sql</code> at <a href="http://localhost/phpmyadmin" target="_blank" rel="noopener">phpMyAdmin</a> (Import tab → choose the file → Go).',
                'That one file creates <code>' . htmlspecialchars(DB_NAME, ENT_QUOTES) . '</code>, all 6 tables, the view, both triggers and both procedures.',
                'Reload this page afterwards.',
            ],
        ];
    }

    if (str_contains($m, 'connection refused') || str_contains($m, '2002') || str_contains($m, 'no such file')) {
        return [
            'title' => 'MySQL is not running, or not on ' . htmlspecialchars(DB_HOST, ENT_QUOTES) . '.',
            'steps' => [
                'Open the XAMPP Control Panel and press <strong>Start</strong> next to <strong>MySQL</strong>.',
                'Wait for it to turn green, then reload this page.',
                'If it refuses to start, another MySQL/MariaDB is already using port 3306 — stop it or change the port.',
            ],
        ];
    }

    if (str_contains($m, "doesn't exist") || str_contains($m, 'no such table')) {
        return [
            'title' => 'The database exists, but a table is missing.',
            'steps' => [
                'The schema is incomplete or from an older patch. Re-import <code>db/logbuk.sql</code>.',
                'Do not mix the one-file build with the incremental <code>002 → 003 → seed → 004</code> chain on the same database.',
            ],
        ];
    }

    return [
        'title' => 'Database error.',
        'steps' => [
            'Check MySQL is running in the XAMPP Control Panel.',
            'Confirm <code>config/config.php</code> matches your MySQL user and password.',
            'Confirm <code>db/logbuk.sql</code> has been imported.',
        ],
    ];
}

/** Escape for HTML output (used by the PHP-rendered views). */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}