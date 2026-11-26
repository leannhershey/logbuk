<?php

/* Occupant records: list, filters, create/update, deactivate/reactivate, stats and pagination. */

require_once __DIR__ . '/db.php';

function occupants_sort_columns(): array
{
    return [
        'id'       => 'o.occupant_id',
        'name'     => 'o.last_name',
        'program'  => 'o.program',
        'contact'  => 'o.contact_number',
        'room'     => 'o.room_id',
        'presence' => 'o.presence_status',
        'residency'=> 'o.residency_status',
        'reg_at'   => 'o.registered_at',
    ];
}

function occupants_normalise_sort(string $sort, string $dir): array
{
    $cols = occupants_sort_columns();
    if (!isset($cols[$sort])) {
        $sort = 'id';
    }
    $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
    return [$sort, $dir];
}

function occupants_where(array $filters): array
{
    $sql    = [];
    $params = [];

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {

        $sql[] = '(o.occupant_id LIKE :q1
                   OR o.first_name LIKE :q2
                   OR o.middle_name LIKE :q3
                   OR o.last_name LIKE :q4
                   OR o.program LIKE :q5
                   OR o.contact_number LIKE :q6
                   OR CONCAT_WS(" ", o.first_name, o.last_name) LIKE :q7
                   OR CONCAT_WS(" ", o.last_name, o.first_name) LIKE :q8)';
        $like = '%' . $q . '%';
        foreach ([':q1', ':q2', ':q3', ':q4', ':q5', ':q6', ':q7', ':q8'] as $name) {
            $params[$name] = $like;
        }
    }

    $room = trim((string) ($filters['room'] ?? ''));
    if ($room !== '' && $room !== 'all') {
        $sql[] = 'o.room_id = :room';
        $params[':room'] = $room;
    }

    $residency = trim((string) ($filters['residency'] ?? ''));
    if ($residency === '' || $residency === 'all') {
        $residency = trim((string) ($filters['status'] ?? ''));
    }

    if (in_array($residency, ['Active', 'Inactive'], true)) {
        $sql[] = 'o.residency_status = :residency';
        $params[':residency'] = $residency;
    }

    $presence = trim((string) ($filters['presence'] ?? ''));
    if (in_array($presence, ['On Premises', 'Off Premises'], true)) {
        $sql[] = 'o.presence_status = :presence';
        $params[':presence'] = $presence;
    }

    return [$sql ? 'WHERE ' . implode(' AND ', $sql) : '', $params];
}

function occupants_count(array $filters): int
{
    [$where, $params] = occupants_where($filters);
    $stmt = db()->prepare("SELECT COUNT(*) FROM Occupants o $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function occupants_stats(): array
{
    $row = db()->query("
        SELECT COUNT(*)                                                              AS total,
               SUM(residency_status = 'Active')                                    AS active,
               SUM(residency_status = 'Inactive')                                  AS inactive,
               SUM(presence_status = 'On Premises' AND residency_status = 'Active') AS inside
        FROM Occupants
    ")->fetch();

    return [
        'total'    => (int) ($row['total'] ?? 0),
        'active'   => (int) ($row['active'] ?? 0),
        'inactive' => (int) ($row['inactive'] ?? 0),
        'inside'   => (int) ($row['inside'] ?? 0),
    ];
}

function occupants_page(array $filters, int $page = 1, int $perPage = OCCUPANTS_PER_PAGE, string $sort = 'id', string $dir = 'ASC'): array
{
    [$where, $params] = occupants_where($filters);
    [$sort, $dir]     = occupants_normalise_sort($sort, $dir);

    $page    = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset  = ($page - 1) * $perPage;

    $col = occupants_sort_columns()[$sort];

    $sql = "
        SELECT o.occupant_id,
               o.first_name,
               o.middle_name,
               o.last_name,
               o.suffix,
               o.program,
               o.contact_number,
               o.presence_status,
               o.residency_status,
               o.registered_at,
               o.last_seen_at,
               o.deactivated_at,
               o.deactivate_reason,
               o.deactivate_source,
               o.room_id,
               r.capacity
        FROM Occupants o
        LEFT JOIN Rooms r ON r.room_id = o.room_id
        $where
        ORDER BY $col $dir, o.occupant_id ASC
        LIMIT $perPage OFFSET $offset
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map('occupant_row', $stmt->fetchAll());
}

function occupant_find(string $id): ?array
{
    $stmt = db()->prepare("
        SELECT o.*, r.capacity
        FROM Occupants o
        LEFT JOIN Rooms r ON r.room_id = o.room_id
        WHERE o.occupant_id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? occupant_row($row) : null;
}

function occupant_row(array $row): array
{
    $middle = trim((string) ($row['middle_name'] ?? ''));
    $suffix = trim((string) ($row['suffix'] ?? ''));

    $fullName = $row['first_name'];
    if ($middle !== '') {
        $first   = rtrim($middle, '.');
        $initial = function_exists('mb_substr')
            ? mb_strtoupper(mb_substr($first, 0, 1))
            : strtoupper(substr($first, 0, 1));
        $fullName .= ' ' . $initial . '.';
    }
    $fullName .= ' ' . $row['last_name'];
    if ($suffix !== '') {
        $fullName .= ' ' . $suffix;
    }

    $inside = ($row['presence_status'] ?? '') === 'On Premises';
    $active = ($row['residency_status'] ?? '') === 'Active';

    return [
        'occupant_id'    => $row['occupant_id'],
        'full_name'      => $fullName,
        'first_name'     => $row['first_name'],
        'middle_name'    => $row['middle_name'],
        'last_name'      => $row['last_name'],
        'suffix'         => $row['suffix'],
        'program'        => $row['program'],
        'contact_number' => $row['contact_number'],
        'room_id'        => $row['room_id'],
        'capacity'  => isset($row['capacity']) ? (int) $row['capacity'] : null,
        'presence'       => $row['presence_status'],
        'presence_key'   => $inside ? 'on' : 'off',
        'presence_label' => $inside ? 'On Premises' : 'Off Premises',
        'residency'      => $active ? 'Active' : 'Inactive',
        'last_seen_at'   => $row['last_seen_at'] ?? null,
        'deactivated_at' => $row['deactivated_at'] ?? null,
        'deactivate_reason' => $row['deactivate_reason'] ?? null,
        'deactivate_source' => $row['deactivate_source'] ?? null,
        'registered_at'  => $row['registered_at'],
        'reg_date'       => date('F j, Y', strtotime($row['registered_at'])),
        'reg_time'       => date('g:i A', strtotime($row['registered_at'])),
    ];
}

function rooms_with_occupancy(): array
{
    $rows = db()->query("
        SELECT r.room_id,
               r.capacity,
               COUNT(o.occupant_id) AS used
        FROM Rooms r
        LEFT JOIN Occupants o
               ON o.room_id = r.room_id
              AND o.residency_status = 'Active'
        GROUP BY r.room_id, r.capacity
        ORDER BY r.room_id
    ")->fetchAll();

    foreach ($rows as &$r) {
        $r['used']      = (int) $r['used'];
        $r['capacity']  = (int) $r['capacity'];
        $r['available'] = max(0, $r['capacity'] - $r['used']);
        $r['is_full']   = $r['available'] === 0;
    }
    return $rows;
}

function rooms_for_filter(): array
{
    return rooms_with_occupancy();
}

function room_state(string $roomId, ?string $ignoreOccupantId = null): ?array
{
    $stmt = db()->prepare("SELECT room_id, capacity FROM Rooms WHERE room_id = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) {
        return null;
    }

    $sql    = "SELECT COUNT(*) FROM Occupants WHERE room_id = ? AND residency_status = 'Active'";
    $params = [$roomId];
    if ($ignoreOccupantId !== null) {
        $sql .= ' AND occupant_id <> ?';
        $params[] = $ignoreOccupantId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $used = (int) $stmt->fetchColumn();

    $capacity = (int) $room['capacity'];

    return [
        'room_id'   => $room['room_id'],
        'capacity'  => $capacity,
        'used'      => $used,
        'available' => max(0, $capacity - $used),
        'is_full'   => $used >= $capacity,
    ];
}

function room_members(string $roomId): array
{
    $stmt = db()->prepare("
        SELECT occupant_id, first_name, middle_name, last_name, suffix, presence_status
        FROM Occupants
        WHERE room_id = ? AND residency_status = 'Active'
        ORDER BY occupant_id
    ");
    $stmt->execute([$roomId]);
    return array_map(fn ($r) => occupant_row($r), $stmt->fetchAll());
}

function next_occupant_id(string $roomId): string
{
    $floor = substr($roomId, 0, 2);
    if (!preg_match('/^\d{2}$/', $floor)) {
        throw new RuntimeException('Room ID must start with a 2-digit floor, e.g. 0101.');
    }

    $stmt = db()->prepare("
        SELECT MAX(CAST(SUBSTRING(occupant_id, 4) AS UNSIGNED))
        FROM Occupants
        WHERE occupant_id LIKE CONCAT(:floor, '-%')
    ");
    $stmt->execute([':floor' => $floor]);
    $max = (int) $stmt->fetchColumn();

    $next = $max + 1;

    do {
        $candidate = sprintf('%s-%04d', $floor, $next);
        $check = db()->prepare('SELECT 1 FROM Occupants WHERE occupant_id = ?');
        $check->execute([$candidate]);
        $exists = (bool) $check->fetchColumn();
        $next++;
    } while ($exists && $next < 10000);

    return $candidate;
}

const OCCUPANT_SUFFIXES = ['', 'Jr.', 'Sr.', 'II', 'III', 'IV'];

function validate_occupant_input(array $in, ?string $editingId = null): array
{
    $clean = [
        'first_name'     => trim((string) ($in['first_name'] ?? '')),
        'middle_name'    => trim((string) ($in['middle_name'] ?? '')),
        'last_name'      => trim((string) ($in['last_name'] ?? '')),
        'suffix'         => trim((string) ($in['suffix'] ?? '')),
        'program'        => trim((string) ($in['program'] ?? '')),
        'contact_number' => preg_replace('/\D+/', '', (string) ($in['contact_number'] ?? '')),
        'room_id'        => trim((string) ($in['room_id'] ?? '')),
    ];

    $errors = [];

    if ($clean['first_name'] === '') {
        $errors['first_name'] = 'First name is required.';
    } elseif (strlen($clean['first_name']) > 100) {
        $errors['first_name'] = 'First name is too long (max 100).';
    }

    if ($clean['last_name'] === '') {
        $errors['last_name'] = 'Last name is required.';
    } elseif (strlen($clean['last_name']) > 50) {
        $errors['last_name'] = 'Last name is too long (max 50).';
    }

    if (strlen($clean['middle_name']) > 50) {
        $errors['middle_name'] = 'Middle name is too long (max 50).';
    }

    if ($clean['suffix'] !== '' && !in_array($clean['suffix'], OCCUPANT_SUFFIXES, true)) {
        $errors['suffix'] = 'Unknown suffix.';
    }

    if (strlen($clean['program']) > 100) {
        $errors['program'] = 'Program is too long (max 100).';
    }

    if ($clean['contact_number'] === '') {
        $errors['contact_number'] = 'Contact number is required.';
    } elseif (!preg_match('/^09\d{9}$/', $clean['contact_number'])) {
        $errors['contact_number'] = 'Use 11 digits starting with 09 (e.g. 09171234567).';
    }

    if ($clean['room_id'] === '') {
        $errors['room_id'] = 'Please choose a room.';
    } else {
        $room = room_state($clean['room_id'], $editingId);
        if ($room === null) {
            $errors['room_id'] = 'That room does not exist.';
        } elseif ($room['is_full']) {
            $errors['room_id'] = sprintf(
                'Room %s is full (%d/%d). Choose another room.',
                $room['room_id'], $room['used'], $room['capacity']
            );
        }
    }

    return [$clean, $errors];
}

function occupant_create(array $in, ?int $adminId = null): array
{
    [$clean, $errors] = validate_occupant_input($in);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'message' => 'Please fix the highlighted fields.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {

        $stmt = $pdo->prepare('SELECT room_id, capacity FROM Rooms WHERE room_id = ? FOR UPDATE');
        $stmt->execute([$clean['room_id']]);
        $room = $stmt->fetch();
        if (!$room) {
            $pdo->rollBack();
            return ['ok' => false, 'errors' => ['room_id' => 'That room does not exist.'], 'message' => 'Room not found.'];
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Occupants WHERE room_id = ? AND residency_status = 'Active'");
        $stmt->execute([$clean['room_id']]);
        $used = (int) $stmt->fetchColumn();
        if ($used >= (int) $room['capacity']) {
            $pdo->rollBack();
            return [
                'ok'      => false,
                'errors'  => ['room_id' => sprintf('Room %s is full (%d/%d).', $room['room_id'], $used, $room['capacity'])],
                'message' => 'That room is already full.',
            ];
        }

        $occupantId = next_occupant_id($clean['room_id']);

        $adminId = $adminId ?? (int) $pdo->query('SELECT MIN(admin_id) FROM Admin')->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO Occupants
              (occupant_id, first_name, middle_name, last_name, suffix,
               program, contact_number, presence_status, residency_status,
               registered_at, last_seen_at, room_id, registered_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Off Premises', 'Active', NOW(), NOW(), ?, ?)
        ");
        $stmt->execute([
            $occupantId,
            $clean['first_name'],
            $clean['middle_name'] !== '' ? $clean['middle_name'] : null,
            $clean['last_name'],
            $clean['suffix'] !== '' ? $clean['suffix'] : null,
            $clean['program'] !== '' ? $clean['program'] : null,
            $clean['contact_number'],
            $clean['room_id'],
            $adminId,
        ]);

        $pdo->commit();
        return ['ok' => true, 'occupant_id' => $occupantId, 'room' => room_state($clean['room_id'])];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ((int) $ex->getCode() === 23000) {
            return ['ok' => false, 'errors' => ['room_id' => 'That occupant ID was just taken. Please try again.'], 'message' => 'Duplicate occupant ID.'];
        }
        throw $ex;
    }
}

function occupant_update(string $occupantId, array $in, ?int $adminId = null): array
{
    $existing = occupant_find($occupantId);
    if (!$existing) {
        return ['ok' => false, 'message' => 'Occupant not found.', 'errors' => []];
    }

    [$clean, $errors] = validate_occupant_input($in, $occupantId);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'message' => 'Please fix the highlighted fields.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT room_id, capacity FROM Rooms WHERE room_id = ? FOR UPDATE');
        $stmt->execute([$clean['room_id']]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            return ['ok' => false, 'errors' => ['room_id' => 'That room does not exist.'], 'message' => 'Room not found.'];
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM Occupants
            WHERE room_id = ? AND residency_status = 'Active' AND occupant_id <> ?
        ");
        $stmt->execute([$clean['room_id'], $occupantId]);
        $used = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT capacity FROM Rooms WHERE room_id = ?');
        $stmt->execute([$clean['room_id']]);
        $capacity = (int) $stmt->fetchColumn();

        if ($used >= $capacity) {
            $pdo->rollBack();
            return [
                'ok'      => false,
                'errors'  => ['room_id' => sprintf('Room %s is full (%d/%d).', $clean['room_id'], $used, $capacity)],
                'message' => 'That room is already full.',
            ];
        }

        $stmt = $pdo->prepare("
            UPDATE Occupants SET
              first_name = ?, middle_name = ?, last_name = ?, suffix = ?,
              program = ?, contact_number = ?, room_id = ?,
              residency_status = 'Active',
              deactivated_at = NULL,
              deactivate_reason = NULL,
              deactivate_source = NULL
            WHERE occupant_id = ?
        ");
        $stmt->execute([
            $clean['first_name'],
            $clean['middle_name'] !== '' ? $clean['middle_name'] : null,
            $clean['last_name'],
            $clean['suffix'] !== '' ? $clean['suffix'] : null,
            $clean['program'] !== '' ? $clean['program'] : null,
            $clean['contact_number'],
            $clean['room_id'],
            $occupantId,
        ]);

        $pdo->commit();
        return ['ok' => true, 'occupant_id' => $occupantId, 'room' => room_state($clean['room_id'])];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

function occupant_deactivate(string $occupantId, ?string $reason = null): array
{
    $occ = occupant_find($occupantId);
    if (!$occ) {
        return ['ok' => false, 'message' => 'Occupant not found.'];
    }
    if ($occ['residency'] !== 'Active') {
        return ['ok' => false, 'message' => 'This occupant is already inactive.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {

        if ($occ['presence'] === 'On Premises') {
            $pdo->prepare("UPDATE Occupants SET presence_status = 'Off Premises' WHERE occupant_id = ?")
                ->execute([$occupantId]);

            $pdo->prepare("
                UPDATE Logs SET time_out = NOW()
                WHERE occupant_id = ? AND time_out IS NULL
            ")->execute([$occupantId]);
        }

        $pdo->prepare("
            UPDATE Occupants
            SET residency_status    = 'Inactive',
                room_id             = NULL,
                presence_status     = 'Off Premises',
                deactivated_at      = NOW(),
                deactivate_reason   = ?,
                deactivate_source   = 'manual'
            WHERE occupant_id = ?
        ")->execute([$reason !== null && $reason !== '' ? $reason : 'Manual deactivation', $occupantId]);

        $pdo->commit();
        return [
            'ok'        => true,
            'message'   => sprintf('%s is now inactive and has left Room %s. Log history kept.', $occ['full_name'], $occ['room_id']),
            'occupant'  => occupant_find($occupantId),
            'old_room'  => $occ['room_id'],
            'room'      => $occ['room_id'] ? room_state($occ['room_id']) : null,
        ];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

function occupant_reactivate(string $occupantId, string $roomId): array
{
    $occ = occupant_find($occupantId);
    if (!$occ) {
        return ['ok' => false, 'message' => 'Occupant not found.'];
    }
    if ($occ['residency'] === 'Active') {
        return ['ok' => false, 'message' => 'This occupant is already active.'];
    }

    $room = room_state($roomId);
    if ($room === null) {
        return ['ok' => false, 'message' => 'That room does not exist.', 'errors' => ['room_id' => 'Unknown room.']];
    }
    if ($room['is_full']) {
        return [
            'ok'      => false,
            'message' => sprintf('Room %s is full (%d/%d).', $room['room_id'], $room['used'], $room['capacity']),
            'errors'  => ['room_id' => 'That room is full.'],
        ];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT room_id FROM Rooms WHERE room_id = ? FOR UPDATE');
        $stmt->execute([$roomId]);

        $pdo->prepare("
            UPDATE Occupants
            SET residency_status    = 'Active',
                room_id             = ?,
                presence_status     = 'Off Premises',
                deactivated_at      = NULL,
                deactivate_reason   = NULL,
                deactivate_source   = NULL
            WHERE occupant_id = ?
        ")->execute([$roomId, $occupantId]);

        $pdo->commit();
        return [
            'ok'       => true,
            'message'  => sprintf('%s is active again in Room %s.', $occ['full_name'], $roomId),
            'occupant' => occupant_find($occupantId),
            'room'     => room_state($roomId),
        ];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

function showing_label(int $total, int $page, int $perPage): string
{
    if ($total === 0) {
        return 'Showing 0 of 0 occupants';
    }
    $from = ($page - 1) * $perPage + 1;
    $to   = min($total, $page * $perPage);
    return sprintf('Showing %d–%d of %d occupant%s', $from, $to, $total, $total === 1 ? '' : 's');
}

function pagination_items(int $current, int $last): array
{
    if ($last <= 1) {
        return [];
    }
    $items    = [];
    $dotsFrom = null;
    for ($p = 1; $p <= $last; $p++) {
        $show = $p === 1 || $p === $last || abs($p - $current) <= 1;
        if ($show) {
            if ($dotsFrom !== null) {
                $items[]  = null;
                $dotsFrom = null;
            }
            $items[] = $p;
        } else {
            $dotsFrom = $dotsFrom ?? $p;
        }
    }
    return $items;
}

/* Outside-for-two-weeks rule. Runs at most once a day; the SQL itself lives in the stored procedure. */
function auto_deactivate_if_due(int $everyHours = 24): array
{
    $pdo = db();

    $settings = $pdo->query("
        SELECT auto_deactivate, inactivity_days, last_auto_run
        FROM Settings ORDER BY setting_id LIMIT 1
    ")->fetch();

    if (!$settings) {
        return ['ran' => false, 'skipped_reason' => 'no settings row', 'deactivated' => []];
    }
    if ((int) $settings['auto_deactivate'] !== 1) {
        return ['ran' => false, 'skipped_reason' => 'disabled in settings', 'deactivated' => []];
    }

    $lastRun = $settings['last_auto_run'];
    if ($lastRun !== null && strtotime($lastRun) > strtotime("-{$everyHours} hours")) {
        return ['ran' => false, 'skipped_reason' => 'already ran recently', 'deactivated' => []];
    }

    $stmt = $pdo->prepare('CALL sp_auto_deactivate_inactive(?)');
    $stmt->execute([null]);
    $rows = $stmt->fetchAll();

    $stmt->closeCursor();

    return [
        'ran'         => true,
        'threshold'   => (int) $settings['inactivity_days'],
        'deactivated' => $rows,
    ];
}

function auto_deactivate_summary(array $result): string
{
    if (!$result['ran']) {
        return 'Skipped: ' . ($result['skipped_reason'] ?? 'not due');
    }
    $n = count($result['deactivated']);
    return $n === 0
        ? 'Ran: no occupant had been inactive for ' . ($result['threshold'] ?? 14) . ' days.'
        : 'Ran: deactivated ' . $n . ' occupant(s) inactive for ' . ($result['threshold'] ?? 14) . ' days.';
}
