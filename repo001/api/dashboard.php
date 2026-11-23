<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auto-deactivate.php';
require_admin();

$autoRun = null;
try {
    $autoRun = auto_deactivate_if_due(24);
} catch (Throwable $ex) {
    $autoRun = null;   
}

$pdo = db();

$total   = (int) $pdo->query("SELECT COUNT(*) FROM Occupants")->fetchColumn();
/* occupants_reg_status is ENUM('Active','Inactive') */
$active  = (int) $pdo->query("SELECT COUNT(*) FROM Occupants WHERE occupants_reg_status = 'Active'")->fetchColumn();
$inactive = (int) $pdo->query("SELECT COUNT(*) FROM Occupants WHERE occupants_reg_status = 'Inactive'")->fetchColumn();
/* Pie Chart Record */
$inside  = (int) $pdo->query("
    SELECT COUNT(*) FROM Occupants
    WHERE occupants_loc_status = 'Inside'
")->fetchColumn();
$outside = (int) $pdo->query("
    SELECT COUNT(*) FROM Occupants
    WHERE occupants_loc_status = 'Outside'
")->fetchColumn();

// Late entries in the last 7 days
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM Logs
    WHERE logs_is_late = 1 AND logs_time_in >= ?
");
$stmt->execute([date('Y-m-d H:i:s', strtotime('-7 days'))]);
$late = (int) $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT o.occupants_id AS occupant_id,
           CONCAT_WS(' ', o.occupants_f_name, o.occupants_l_name) AS full_name,
           IFNULL(r.room_id, '—') AS room_number,
           l.logs_time_in  AS time_in,
           l.logs_time_out AS time_out,
           l.logs_is_late  AS is_late
    FROM Logs l
    JOIN Occupants o ON o.occupants_id = l.Occupants_occupants_id
    LEFT JOIN Rooms r ON r.room_id = l.Occupants_Rooms_room_id
    ORDER BY l.logs_time_in DESC
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