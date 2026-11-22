<?php
/**
 * Dashboard data.
 * Assumed schema (align with Rey's database if names differ):
 *   occupants(occupant_id, full_name, current_status ENUM('Inside','Outside'), residency_status, room_id ...)
 *   rooms(room_id, room_number ...)
 *   logs(log_id, occupant_id, room_id, time_in, time_out, whereabouts, is_late TINYINT)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_admin();

$pdo = db();

$total   = (int) $pdo->query("SELECT COUNT(*) FROM occupants")->fetchColumn();
$inside  = (int) $pdo->query("SELECT COUNT(*) FROM occupants WHERE current_status = 'Inside'")->fetchColumn();
$outside = (int) $pdo->query("SELECT COUNT(*) FROM occupants WHERE current_status = 'Outside'")->fetchColumn();
$late    = (int) $pdo->query("SELECT COUNT(*) FROM logs WHERE is_late = 1 AND time_in >= (NOW() - INTERVAL 7 DAY)")->fetchColumn();

$stmt = $pdo->query("
    SELECT o.occupant_id, o.full_name, r.room_number, l.time_in, l.time_out, l.is_late
    FROM logs l
    JOIN occupants o ON o.occupant_id = l.occupant_id
    JOIN rooms r     ON r.room_id     = l.room_id
    ORDER BY l.time_in DESC
    LIMIT 6
");

json_response([
    'total_occupants'   => $total,
    'inside'            => $inside,
    'outside'           => $outside,
    'late_entries'      => $late,
    'unread_logs'       => $late,   // badge on "Dormitory Logs"; swap for a real unread counter later
    'recent_activities' => $stmt->fetchAll(),
]);
