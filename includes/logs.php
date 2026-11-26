<?php

/* Log queries. Views: all / late / inside / outside, plus search, room and date filters. */

require_once __DIR__ . '/db.php';

if (!defined('LOGS_PER_PAGE')) {
    define('LOGS_PER_PAGE', 10);
}

const LOG_VIEWS = ['all', 'late', 'inside', 'outside'];

function logs_normalise_view(?string $view): string
{
    $view = strtolower(trim((string) $view));
    return in_array($view, LOG_VIEWS, true) ? $view : 'all';
}

function logs_normalise_date(?string $date): string
{
    $date = trim((string) $date);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        return '';
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $date : '';
}

function logs_normalise_filters(array $src): array
{
    $room = trim((string) ($src['room'] ?? 'all'));
    return [
        'q'    => trim((string) ($src['q'] ?? '')),
        'room' => preg_match('/^\d{4}$/', $room) ? $room : 'all',
        'date' => logs_normalise_date($src['date'] ?? ''),
    ];
}

function logs_from_where(string $view, array $filters): array
{
    $view   = logs_normalise_view($view);
    $params = [];
    $where  = [];

    if ($view === 'outside') {

        $from = "FROM Occupants o
                 LEFT JOIN Logs l
                        ON l.log_id = (SELECT x.log_id FROM Logs x
                                         WHERE x.occupant_id = o.occupant_id
                                         ORDER BY x.time_in DESC, x.log_id DESC
                                         LIMIT 1)
                 LEFT JOIN Rooms r ON r.room_id = COALESCE(l.room_id, o.room_id)";
        $where[] = "o.residency_status = 'Active'";
        $where[] = '(l.log_id IS NULL OR l.time_out IS NOT NULL)';
        $roomCol = 'COALESCE(l.room_id, o.room_id)';
    } else {
        $from = "FROM Logs l
                 JOIN Occupants o ON o.occupant_id = l.occupant_id
                 LEFT JOIN Rooms r ON r.room_id = l.room_id";
        $roomCol = 'l.room_id';
        if ($view === 'late') {
            $where[] = 'l.is_late = 1';
        } elseif ($view === 'inside') {
            $where[] = 'l.time_out IS NULL';
        }
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(o.occupant_id LIKE :q1
                     OR o.first_name LIKE :q2
                     OR o.middle_name LIKE :q3
                     OR o.last_name LIKE :q4
                     OR CONCAT_WS(" ", o.first_name, o.last_name) LIKE :q5
                     OR CONCAT_WS(" ", o.last_name, o.first_name) LIKE :q6
                     OR CONCAT_WS(", ", o.last_name, o.first_name) LIKE :q7)';

        $like = '%' . addcslashes($q, '%_\\') . '%';
        foreach ([':q1', ':q2', ':q3', ':q4', ':q5', ':q6', ':q7'] as $name) {
            $params[$name] = $like;
        }
    }

    $room = trim((string) ($filters['room'] ?? ''));
    if ($room !== '' && $room !== 'all') {
        $where[] = "$roomCol = :room";
        $params[':room'] = $room;
    }

    $date = logs_normalise_date($filters['date'] ?? '');
    if ($date !== '') {

        /* A range, not DATE(column), so the time_in index still gets used. */
        $where[] = 'l.time_in >= :d_from AND l.time_in < :d_to';
        $params[':d_from'] = $date . ' 00:00:00';
        $params[':d_to']   = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';
    }

    return [$from . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params];
}

function logs_count(string $view, array $filters): int
{
    [$fw, $params] = logs_from_where($view, $filters);
    $stmt = db()->prepare("SELECT COUNT(*) $fw");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function logs_counts(array $filters): array
{
    $out = [];
    foreach (LOG_VIEWS as $view) {
        $out[$view] = logs_count($view, $filters);
    }
    return $out;
}

function logs_page(string $view, array $filters, int $page = 1, int $perPage = LOGS_PER_PAGE): array
{
    [$fw, $params] = logs_from_where($view, $filters);

    $page    = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset  = ($page - 1) * $perPage;

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
        $fw
        ORDER BY l.time_in DESC, l.log_id DESC, o.occupant_id ASC
        LIMIT $perPage OFFSET $offset";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map('log_row', $stmt->fetchAll());
}

function log_find(int $logId): ?array
{
    $stmt = db()->prepare("
        SELECT l.log_id, l.time_in, l.time_out, l.is_late,
               NULLIF(TRIM(l.whereabouts), '') AS whereabouts,
               o.occupant_id, o.first_name, o.middle_name,
               o.last_name, o.suffix, o.residency_status,
               l.room_id AS room_id
        FROM Logs l
        JOIN Occupants o ON o.occupant_id = l.occupant_id
        WHERE l.log_id = ?
    ");
    $stmt->execute([$logId]);
    $row = $stmt->fetch();
    return $row ? log_row($row) : null;
}

function logs_rooms(): array
{
    return db()->query('SELECT room_id FROM Rooms ORDER BY room_id')->fetchAll(PDO::FETCH_COLUMN);
}

function log_display_name(array $o): string
{
    $name   = $o['last_name'] . ', ' . $o['first_name'];
    $middle = trim((string) ($o['middle_name'] ?? ''));
    if ($middle !== '') {
        $first = rtrim($middle, '.');
        $name .= ' ' . (function_exists('mb_substr') ? mb_strtoupper(mb_substr($first, 0, 1)) : strtoupper($first[0])) . '.';
    }
    $suffix = trim((string) ($o['suffix'] ?? ''));
    return $suffix !== '' ? "$name $suffix" : $name;
}

function log_format_date(?string $sql): string
{
    if (!$sql) {
        return '';
    }
    $ts   = strtotime($sql);
    $abbr = [1 => 'Jan.', 2 => 'Feb.', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
             7 => 'July', 8 => 'Aug.', 9 => 'Sept.', 10 => 'Oct.', 11 => 'Nov.', 12 => 'Dec.'];
    return $abbr[(int) date('n', $ts)] . ' ' . date('j, Y', $ts);
}

function log_format_time(?string $sql): string
{
    return $sql ? date('g:i A', strtotime($sql)) : '';
}

function log_row(array $r): array
{
    $hasLog   = $r['log_id'] !== null;
    $isOpen   = $hasLog && $r['time_out'] === null;
    $isLate   = $hasLog && (int) $r['is_late'] === 1;

    return [
        'log_id'         => $hasLog ? (int) $r['log_id'] : null,
        'occupant_id'    => $r['occupant_id'],
        'full_name'      => log_display_name($r),
        'residency'      => $r['residency_status'],
        'room_id'        => $r['room_id'],
        'date'           => $hasLog ? log_format_date($r['time_in']) : '',
        'date_iso'       => $hasLog ? substr($r['time_in'], 0, 10) : null,
        'time_in'        => $hasLog ? log_format_time($r['time_in']) : '',
        'time_out'       => $hasLog ? log_format_time($r['time_out']) : '',
        'time_in_raw'    => $r['time_in'],
        'time_out_raw'   => $r['time_out'],
        'whereabouts'    => $r['whereabouts'],
        'is_late'        => $isLate,

        'presence'       => $isOpen ? 'On Premises' : 'Off Premises',
        'presence_label' => $isOpen ? 'On Premises' : 'Off Premises',
        'has_log'        => $hasLog,
    ];
}

function logs_showing_label(int $total, int $page, int $perPage): string
{
    if ($total === 0) {
        return 'Showing 0 of 0 records';
    }
    $from = ($page - 1) * $perPage + 1;
    $to   = min($total, $page * $perPage);
    return sprintf('Showing %d-%d of %d record%s', $from, $to, $total, $total === 1 ? '' : 's');
}

function logs_late_badge(): int
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM Logs WHERE is_late = 1 AND time_in >= ?');
        $stmt->execute([date('Y-m-d H:i:s', strtotime('-7 days'))]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}
