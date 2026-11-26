<?php

/* Shared report queries, so the page, the API and the PDF can never quote different numbers. */

require_once __DIR__ . '/db.php';

function report_months(): array
{
    $rows = db()->query("
        SELECT YEAR(time_in)  AS y,
               MONTH(time_in) AS m,
               COUNT(*)            AS n,
               SUM(time_out IS NULL) AS open_rows
          FROM Logs
         GROUP BY y, m
         ORDER BY y DESC, m DESC
    ")->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $y = (int) $r['y'];
        $m = (int) $r['m'];
        $out[] = [
            'year'      => $y,
            'month'     => $m,
            'label'     => date('F Y', mktime(0, 0, 0, $m, 1, $y)),
            'logs'      => (int) $r['n'],
            'open_logs' => (int) ($r['open_rows'] ?? 0),
        ];
    }
    return $out;
}

function report_summary(int $year, int $month): array
{
    $pdo   = db();
    $first = sprintf('%04d-%02d-01', $year, $month);
    $last  = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));

    $stmt = $pdo->prepare("
        SELECT COUNT(*)                      AS logs,
               COUNT(DISTINCT occupant_id) AS occupants,
               SUM(time_out IS NULL)    AS open_logs
          FROM Logs
         WHERE time_in >= :from
           AND time_in <  DATE_ADD(:to, INTERVAL 1 DAY)
    ");
    $stmt->execute([':from' => $first, ':to' => $last]);
    $row = $stmt->fetch() ?: ['logs' => 0, 'occupants' => 0, 'open_logs' => 0];

    $late = 0;
    try {
        $lateStmt = $pdo->prepare("
            SELECT COUNT(*) FROM Logs
             WHERE time_in >= :from
               AND time_in <  DATE_ADD(:to, INTERVAL 1 DAY)
               AND is_late = 1
        ");
        $lateStmt->execute([':from' => $first, ':to' => $last]);
        $late = (int) $lateStmt->fetchColumn();
    } catch (Throwable $e) {
        $late = 0;
    }

    $hours = 0.0;
    try {
        $hStmt = $pdo->prepare("
            SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, time_in, time_out)), 0)
              FROM Logs
             WHERE time_in >= :from
               AND time_in <  DATE_ADD(:to, INTERVAL 1 DAY)
               AND time_out IS NOT NULL
        ");
        $hStmt->execute([':from' => $first, ':to' => $last]);
        $hours = round(((int) $hStmt->fetchColumn()) / 60, 1);
    } catch (Throwable $e) {
        $hours = 0.0;
    }

    return [
        'meta'   => [
            'year'  => $year,
            'month' => $month,
            'label' => date('F Y', mktime(0, 0, 0, $month, 1, $year)),
            'from'  => $first,
            'to'    => $last,
        ],
        'totals' => [
            'logs'      => (int) $row['logs'],
            'occupants' => (int) $row['occupants'],
            'late'      => $late,
            'open_logs' => (int) ($row['open_logs'] ?? 0),
            'hours'     => $hours,
        ],
    ];
}

function report_logs(int $year, int $month, int $limit = 200): array
{
    return report_logs_range(
        sprintf('%04d-%02d-01', $year, $month),
        date('Y-m-t', mktime(0, 0, 0, $month, 1, $year)),
        $limit
    );
}

function report_logs_range(string $first, string $last, int $limit = 2000): array
{
    require_once __DIR__ . '/logs.php';

    $sql = "
        SELECT l.log_id,
               l.time_in,
               l.time_out,
               l.is_late,
               NULLIF(TRIM(l.whereabouts), '') AS whereabouts,
               o.occupant_id,
               o.first_name,
               o.middle_name,
               o.last_name,
               o.suffix,
               o.residency_status,
               COALESCE(l.room_id, o.room_id) AS room_id
          FROM Logs l
          JOIN Occupants o ON o.occupant_id = l.occupant_id
          LEFT JOIN Rooms r ON r.room_id = l.room_id
         WHERE l.time_in >= :from
           AND l.time_in <  DATE_ADD(:to, INTERVAL 1 DAY)
         ORDER BY l.time_in DESC, l.log_id DESC
         LIMIT " . max(1, min(2000, $limit));

    $stmt = db()->prepare($sql);
    $stmt->execute([':from' => $first, ':to' => $last]);

    return array_map('log_row', $stmt->fetchAll());
}

function report_range_stats(string $first, string $last): array
{
    $pdo = db();
    $p   = [':from' => $first, ':to' => $last];

    $in = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(is_late = 1), 0) FROM Logs
                          WHERE time_in >= :from AND time_in < DATE_ADD(:to, INTERVAL 1 DAY)");
    $in->execute($p);
    [$timeIns, $late] = $in->fetch(PDO::FETCH_NUM);

    $out = $pdo->prepare("SELECT COUNT(*) FROM Logs
                           WHERE time_out >= :from AND time_out < DATE_ADD(:to, INTERVAL 1 DAY)");
    $out->execute($p);

    return ['time_ins' => (int) $timeIns, 'time_outs' => (int) $out->fetchColumn(), 'late' => (int) $late];
}
