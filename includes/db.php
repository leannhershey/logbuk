<?php

/* The shared PDO connection plus the JSON helpers every endpoint uses. */

require_once __DIR__ . '/../config/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    /* PDO reads "localhost" as a socket and then ignores the port, so send 127.0.0.1 instead. */
    $host = strcasecmp((string) DB_HOST, 'localhost') === 0 ? '127.0.0.1' : DB_HOST;
    $dsn  = 'mysql:host=' . $host . ';port=' . (int) DB_PORT
          . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    } catch (PDOException $ex) {
        throw new PDOException(db_error_hint($ex->getMessage())['title'] . ' ' . $ex->getMessage());
    }

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

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* Turns the three connection errors people actually hit into a next step. */
function db_error_hint(string $message): array
{
    $m = strtolower($message);

    if (str_contains($m, 'access denied')) {
        return [
            'title' => 'MySQL refused that user and password.',
            'steps' => ['Set DB_USER and DB_PASS in <code>config/config.php</code> to the login you use in phpMyAdmin.'],
        ];
    }
    if (str_contains($m, 'unknown database') || str_contains($m, '1049')) {
        return [
            'title' => 'The database does not exist yet.',
            'steps' => ['Import <code>db/logbuk.sql</code> in phpMyAdmin (Import tab) and run the page again.'],
        ];
    }
    if (str_contains($m, 'refused') || str_contains($m, '2002')) {
        return [
            'title' => 'Nothing is answering on the MySQL port.',
            'steps' => ['Start MySQL in the XAMPP Control Panel, then reload.'],
        ];
    }

    return ['title' => 'Database error.', 'steps' => ['Check that MySQL is running and <code>db/logbuk.sql</code> was imported.']];
}
