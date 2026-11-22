<?php
session_start();

function require_admin(): void {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['message' => 'Admin verification required.']);
        exit;
    }
}
