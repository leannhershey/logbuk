<?php

/* Month index, summary and full rows for the Reports screen and the PDF. */
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$action = (string) ($_GET['action'] ?? 'months');

try {
    $pdo = db();
} catch (Throwable $ex) {
    json_error('Database not reachable: ' . $ex->getMessage(), 503);
}

if ($action === 'months') {
    $rows = $pdo->query("
        SELECT YEAR(time_in)  AS y,
               MONTH(time_in) AS m,
               COUNT(*)            AS n,
               SUM(time_out IS NULL) AS open_rows
          FROM Logs
         GROUP BY y, m
         ORDER BY y DESC, m DESC
    ")->fetchAll();

    $months = array_map(static function (array $r): array {
        $y = (int) $r['y'];
        $m = (int) $r['m'];
        return [
            'year'      => $y,
            'month'     => $m,
            'label'     => date('F Y', mktime(0, 0, 0, $m, 1, $y)),
            'logs'      => (int) $r['n'],
            'open_logs' => (int) $r['open_rows'],
        ];
    }, $rows);

    json_response(['ok' => true, 'months' => $months]);
}

if ($action === 'summary') {
    $year  = (int) ($_GET['year'] ?? 0);
    $month = (int) ($_GET['month'] ?? 0);

    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        json_error('Pick a valid month and year.', 400);
    }

    $first = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $last  = date('Y-m-t 23:59:59', strtotime($first));
    $label = date('F Y', strtotime($first));

    $warnings = [];
    $sql = "
        SELECT l.log_id,
               l.occupant_id          AS occupant_id,
               CONCAT_WS(' ', o.first_name, NULLIF(o.middle_name, ''),
                         o.last_name, NULLIF(o.suffix, '')) AS full_name,
               l.room_id         AS room,
               l.time_in,
               l.time_out,
               l.whereabouts,
               l.is_late
          FROM Logs l
          JOIN Occupants o ON o.occupant_id = l.occupant_id
         WHERE l.time_in BETWEEN ? AND ?
         ORDER BY l.time_in ASC, l.log_id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$first, $last]);
    $raw = $stmt->fetchAll();

    $logs       = [];
    $byOccupant = [];
    $byDay      = [];
    $byCategory = [];
    $lateCount  = 0;
    $openCount  = 0;
    $noRoom     = 0;
    $longStays  = 0;
    $now        = time();

    foreach ($raw as $r) {
        $inTs  = strtotime($r['time_in']);
        $outTs = $r['time_out'] !== null ? strtotime($r['time_out']) : null;

        $minutes = null;
        if ($inTs !== false && $outTs !== null) {
            $minutes = (int) round(($outTs - $inTs) / 60);
        } elseif ($inTs !== false) {
            $minutes = (int) round(($now - $inTs) / 60);
            $openCount++;
        }

        if ($minutes !== null && $minutes > 18 * 60) {
            $longStays++;
        }
        if (empty($r['room'])) {
            $noRoom++;
        }

        /* whereabouts is only filled in at time-out, so an open log has none yet */
        $catName = $r['whereabouts'] !== null && trim((string) $r['whereabouts']) !== ''
            ? trim((string) $r['whereabouts'])
            : 'Still on premises';
        $byCategory[$catName] = ($byCategory[$catName] ?? 0) + 1;

        if ((int) $r['is_late'] === 1) {
            $lateCount++;
        }

        $day = date('Y-m-d', $inTs);
        $byDay[$day] = ($byDay[$day] ?? 0) + 1;

        $name = trim(preg_replace('/\s+/', ' ', (string) $r['full_name']));
        if (!isset($byOccupant[$r['occupant_id']])) {
            $byOccupant[$r['occupant_id']] = [
                'occupant_id' => $r['occupant_id'],
                'full_name'   => $name,
                'room'        => $r['room'],
                'visits'      => 0,
                'minutes'     => 0,
                'late'        => 0,
                'open'        => 0,
            ];
        }
        $byOccupant[$r['occupant_id']]['visits']++;
        $byOccupant[$r['occupant_id']]['minutes'] += max(0, (int) $minutes);
        if ((int) $r['is_late'] === 1) {
            $byOccupant[$r['occupant_id']]['late']++;
        }
        if ($r['time_out'] === null) {
            $byOccupant[$r['occupant_id']]['open']++;
        }

        $logs[] = [
            'log_id'     => (int) $r['log_id'],
            'occupant_id' => $r['occupant_id'],
            'full_name'   => $name,
            'room'        => $r['room'] ?: '—',
            'date'        => date('M j, Y', $inTs),
            'time_in'     => date('g:i A', $inTs),
            'time_out'    => $outTs !== null ? date('g:i A', $outTs) : '—',
            'minutes'     => (int) $minutes,
            'duration'    => report_duration((int) $minutes),
            'category'    => $catName,
            'whereabouts' => $catName,
            'late'        => (int) $r['is_late'] === 1,
            'open'        => $r['time_out'] === null,
        ];
    }

    if ($openCount > 0) {
        $warnings[] = $openCount . ' log row(s) in this month have no time-out. '
                    . 'Their duration is measured up to now, so the monthly total can still change.';
    }
    if ($longStays > 0) {
        $warnings[] = $longStays . ' visit(s) run longer than 18 hours, which usually means '
                    . 'a forgotten time-out rather than a real stay.';
    }
    if ($noRoom > 0) {
        $warnings[] = $noRoom . ' row(s) reference a room that no longer exists; '
                    . 'the room column cannot be filled for them.';
    }
    if (count($raw) === 0) {
        $warnings[] = 'There are no log rows in ' . $label . '.';
    }

    ksort($byDay);
    arsort($byOccupant);
    $occupants = array_values($byOccupant);
    usort($occupants, static fn($a, $b) => $b['visits'] <=> $a['visits'] ?: strcmp($a['full_name'], $b['full_name']));
    arsort($byCategory);

    $totalMinutes = array_sum(array_column($occupants, 'minutes'));

    json_response([
        'ok'      => true,
        'period'  => [
            'year'  => $year,
            'month' => $month,
            'label' => $label,
            'from'  => $first,
            'to'    => $last,
        ],
        'totals'  => [
            'logs'          => count($logs),
            'occupants'     => count($occupants),
            'late'          => $lateCount,
            'open'          => $openCount,
            'minutes'       => $totalMinutes,
            'hours'         => round($totalMinutes / 60, 1),
            'avg_minutes'   => count($logs) ? (int) round($totalMinutes / count($logs)) : 0,
            'busiest_day'   => $byDay ? array_search(max($byDay), $byDay, true) : null,
            'busiest_count' => $byDay ? max($byDay) : 0,
        ],
        'categories' => $byCategory,
        'per_day'    => $byDay,
        'occupants'  => $occupants,
        'logs'       => $logs,
        'warnings'   => $warnings,
        'meta'       => [
            'generated_at'   => date('Y-m-d H:i:s'),
            'generated_by' => 'LogBUk',
        ],
    ]);
}

json_error('Unknown action.', 400);

function report_duration(int $minutes): string
{
    if ($minutes <= 0) {
        return '0m';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h === 0) {
        return $m . 'm';
    }
    return $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '');
}
