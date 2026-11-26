<?php

/* Counts, pie data and recent activity for the dashboard. */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/occupants.php';
require_admin();

$autoRun = null;
try {
    $autoRun = auto_deactivate_if_due(24);
} catch (Throwable $ex) {
    $autoRun = null;
}

$pdo = db();

$total   = (int) $pdo->query("SELECT COUNT(*) FROM Occupants")->fetchColumn();

$active  = (int) $pdo->query("SELECT COUNT(*) FROM Occupants WHERE residency_status = 'Active'")->fetchColumn();
$inactive = (int) $pdo->query("SELECT COUNT(*) FROM Occupants WHERE residency_status = 'Inactive'")->fetchColumn();

$inside  = (int) $pdo->query("
    SELECT COUNT(*) FROM Occupants
    WHERE presence_status = 'On Premises'
")->fetchColumn();
$outside = (int) $pdo->query("
    SELECT COUNT(*) FROM Occupants
    WHERE presence_status = 'Off Premises'
")->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM Logs
    WHERE is_late = 1 AND time_in >= ?
");
$stmt->execute([date('Y-m-d H:i:s', strtotime('-7 days'))]);
$late = (int) $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT o.occupant_id AS occupant_id,
           CONCAT_WS(' ', o.first_name, o.last_name) AS full_name,
           IFNULL(r.room_id, '—') AS room_number,
           l.time_in  AS time_in,
           l.time_out AS time_out,
           l.is_late  AS is_late
    FROM Logs l
    JOIN Occupants o ON o.occupant_id = l.occupant_id
    LEFT JOIN Rooms r ON r.room_id = l.room_id
    ORDER BY l.time_in DESC
    LIMIT 6
");

json_response([
    'total_occupants'   => $total,
    'active'            => $active,
    'inactive'          => $inactive,
    'inside'            => $inside,
    'outside'           => $outside,
    'late_entries'      => $late,
    'unread_logs'       => $late,
    'recent_activities' => $stmt->fetchAll(),
    'auto_deactivation' => $autoRun
        ? ['ran' => $autoRun['ran'], 'count' => count($autoRun['deactivated'])]
        : null,
]);
