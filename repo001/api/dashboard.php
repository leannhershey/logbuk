<?php
/**
 * Dashboard data for dashboard.html
 *
 *   GET api/dashboard.php
 *
 * Reads the real schema (sql-ver-002 + 003 + 004):
 *   Occupants(occupants_id, occupants_f_name, ..., occupants_loc_status,
 *             occupants_reg_status TINYINT 1/0, Rooms_room_id, occupants_reg_at)
 *   Rooms(room_id, room_capacity)
 *   Logs(logs_id, logs_time_in, logs_time_out, logs_is_late, Occupants_occupants_id)
 *
 * The "outside for 2 weeks" rule is applied first (at most once a day), so the
 * numbers you see already reflect it.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auto-deactivate.php';
require_admin();

$autoRun = null;
try {
    $autoRun = auto_deactivate_if_due(24);
} catch (Throwable $ex) {
    $autoRun = null;   // patch not applied -> not fatal
}

$pdo = db();

$total   = (int) $pdo->query("SELECT COUNT(*) FROM Occupants")->fetchColumn();
/* occupants_reg_status is ENUM('Active','Inactive') -- compare against the
   words, never 1/0. A numeric comparison here silently matches nothing. */
$active  = (int) $pdo->query("SELECT COUNT(*) FROM Occupants WHERE occupants_reg_status = 'Active'")->fetchColumn();
$inactive = (int) $pdo->query("SELECT COUNT(*) FROM Occupants WHERE occupants_reg_status = 'Inactive'")->fetchColumn();
/* Presence is counted over EVERY occupant, not just the Active ones: an
   Inactive occupant sits Outside too, and the pie must add up to the total.
   Deriving $outside as ($active - $inside) dropped them -- 3 + 3 instead of
   3 + 6 on the demo data. */
$inside  = (int) $pdo->query("
    SELECT COUNT(*) FROM Occupants
    WHERE occupants_loc_status = 'Inside'
")->fetchColumn();
$outside = (int) $pdo->query("
    SELECT COUNT(*) FROM Occupants
    WHERE occupants_loc_status = 'Outside'
")->fetchColumn();

// Late entries in the last 7 days (bound value works on MySQL and MariaDB)
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
