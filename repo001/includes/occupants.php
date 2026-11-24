<?php
/**
 * Data access + validation for the Occupants modules.
 *
 * Used by:
 *   occupants.html          -> list / sort / filter / search
 *   register-occupant.php   -> add form
 *   edit-occupant.php       -> edit form
 *   api/occupants_api.php   -> modal actions (deactivate / reactivate) + validation
 *
 * Keeping it in one place means the list, the forms and the API can never
 * disagree about what a valid occupant looks like.
 *
 * Requires sql-ver-002.sql + sql-ver-003.sql.
 */

require_once __DIR__ . '/db.php';

/* ------------------------------------------------------------------
 * Sorting
 * ------------------------------------------------------------------ */

/** Whitelist: sort key => real column. Never interpolate user input. */
function occupants_sort_columns(): array
{
    return [
        'id'       => 'o.occupants_id',
        'name'     => 'o.occupants_l_name',        // surname first, like a class list
        'program'  => 'o.occupants_program',
        'contact'  => 'o.occupants_contact_number',
        'room'     => 'o.Rooms_room_id',
        'presence' => 'o.occupants_loc_status',
        'residency'=> 'o.occupants_reg_status',
        'reg_at'   => 'o.occupants_reg_at',
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

/* ------------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------------ */

/**
 * $filters: ['q','room','residency','presence','status']
 *   q         free text: occupant ID, name, program or contact number
 *   room      room id or 'all'
 *   residency Active | Inactive | all
 *   presence  Inside | Outside | all
 *   status    alias of residency (kept so old links keep working)
 */
function occupants_where(array $filters): array
{
    $sql    = [];
    $params = [];

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        // One named placeholder per occurrence: MySQL rejects reuse when
        // emulated prepares are off.
        $sql[] = '(o.occupants_id LIKE :q1
                   OR o.occupants_f_name LIKE :q2
                   OR o.occupants_m_name LIKE :q3
                   OR o.occupants_l_name LIKE :q4
                   OR o.occupants_program LIKE :q5
                   OR o.occupants_contact_number LIKE :q6
                   OR CONCAT_WS(" ", o.occupants_f_name, o.occupants_l_name) LIKE :q7
                   OR CONCAT_WS(" ", o.occupants_l_name, o.occupants_f_name) LIKE :q8)';
        $like = '%' . $q . '%';
        foreach ([':q1', ':q2', ':q3', ':q4', ':q5', ':q6', ':q7', ':q8'] as $name) {
            $params[$name] = $like;
        }
    }

    $room = trim((string) ($filters['room'] ?? ''));
    if ($room !== '' && $room !== 'all') {
        $sql[] = 'o.Rooms_room_id = :room';
        $params[':room'] = $room;
    }

    $residency = trim((string) ($filters['residency'] ?? ''));
    if ($residency === '' || $residency === 'all') {
        $residency = trim((string) ($filters['status'] ?? ''));
    }
    // occupants_reg_status is ENUM('Active','Inactive') -- the words are the values.
    if (in_array($residency, ['Active', 'Inactive'], true)) {
        $sql[] = 'o.occupants_reg_status = :residency';
        $params[':residency'] = $residency;
    }

    $presence = trim((string) ($filters['presence'] ?? ''));
    if (in_array($presence, ['Inside', 'Outside'], true)) {
        $sql[] = 'o.occupants_loc_status = :presence';
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

/** Counts for the summary chips above the table (ignores the filters). */
function occupants_stats(): array
{
    $row = db()->query("
        SELECT COUNT(*)                                                              AS total,
               SUM(occupants_reg_status = 'Active')                                    AS active,
               SUM(occupants_reg_status = 'Inactive')                                  AS inactive,
               SUM(occupants_loc_status = 'Inside' AND occupants_reg_status = 'Active') AS inside
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
        SELECT o.occupants_id,
               o.occupants_f_name,
               o.occupants_m_name,
               o.occupants_l_name,
               o.occupants_s_name,
               o.occupants_program,
               o.occupants_contact_number,
               o.occupants_loc_status,
               o.occupants_reg_status,
               o.occupants_reg_at,
               o.occupants_last_seen_at,
               o.occupants_deactivated_at,
               o.occupants_deactivate_reason,
               o.occupants_deactivate_source,
               o.Rooms_room_id,
               r.room_capacity
        FROM Occupants o
        LEFT JOIN Rooms r ON r.room_id = o.Rooms_room_id
        $where
        ORDER BY $col $dir, o.occupants_id ASC
        LIMIT $perPage OFFSET $offset
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map('occupant_row', $stmt->fetchAll());
}

/** One occupant (or null). */
function occupant_find(string $id): ?array
{
    $stmt = db()->prepare("
        SELECT o.*, r.room_capacity
        FROM Occupants o
        LEFT JOIN Rooms r ON r.room_id = o.Rooms_room_id
        WHERE o.occupants_id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? occupant_row($row) : null;
}

function occupant_row(array $row): array
{
    $middle = trim((string) ($row['occupants_m_name'] ?? ''));
    $suffix = trim((string) ($row['occupants_s_name'] ?? ''));

    $fullName = $row['occupants_f_name'];
    if ($middle !== '') {
        $first   = rtrim($middle, '.');
        $initial = function_exists('mb_substr')
            ? mb_strtoupper(mb_substr($first, 0, 1))
            : strtoupper(substr($first, 0, 1));
        $fullName .= ' ' . $initial . '.';
    }
    $fullName .= ' ' . $row['occupants_l_name'];
    if ($suffix !== '') {
        $fullName .= ' ' . $suffix;
    }

    $inside = ($row['occupants_loc_status'] ?? '') === 'Inside';
    $active = ($row['occupants_reg_status'] ?? '') === 'Active';

    return [
        'occupant_id'    => $row['occupants_id'],
        'full_name'      => $fullName,
        'first_name'     => $row['occupants_f_name'],
        'middle_name'    => $row['occupants_m_name'],
        'last_name'      => $row['occupants_l_name'],
        'suffix'         => $row['occupants_s_name'],
        'program'        => $row['occupants_program'],
        'contact_number' => $row['occupants_contact_number'],
        'room_id'        => $row['Rooms_room_id'],
        'room_capacity'  => isset($row['room_capacity']) ? (int) $row['room_capacity'] : null,
        'presence'       => $row['occupants_loc_status'],
        'presence_key'   => $inside ? 'on' : 'off',
        'presence_label' => $inside ? 'On Premises' : 'Off Premises',
        'residency'      => $active ? 'Active' : 'Inactive',
        'last_seen_at'   => $row['occupants_last_seen_at'] ?? null,
        'deactivated_at' => $row['occupants_deactivated_at'] ?? null,
        'deactivate_reason' => $row['occupants_deactivate_reason'] ?? null,
        'deactivate_source' => $row['occupants_deactivate_source'] ?? null,
        'registered_at'  => $row['occupants_reg_at'],
        'reg_date'       => date('F j, Y', strtotime($row['occupants_reg_at'])),
        'reg_time'       => date('g:i A', strtotime($row['occupants_reg_at'])),
    ];
}

/* ------------------------------------------------------------------
 * Rooms
 * ------------------------------------------------------------------ */

/** All rooms with live occupancy (active occupants only). */
function rooms_with_occupancy(): array
{
    $rows = db()->query("
        SELECT r.room_id,
               r.room_capacity,
               COUNT(o.occupants_id) AS used
        FROM Rooms r
        LEFT JOIN Occupants o
               ON o.Rooms_room_id = r.room_id
              AND o.occupants_reg_status = 'Active'
        GROUP BY r.room_id, r.room_capacity
        ORDER BY r.room_id
    ")->fetchAll();

    foreach ($rows as &$r) {
        $r['used']      = (int) $r['used'];
        $r['capacity']  = (int) $r['room_capacity'];
        $r['available'] = max(0, $r['capacity'] - $r['used']);
        $r['is_full']   = $r['available'] === 0;
    }
    return $rows;
}

function rooms_for_filter(): array
{
    return rooms_with_occupancy();
}

/** Capacity state of one room, optionally ignoring one occupant (for edits). */
function room_state(string $roomId, ?string $ignoreOccupantId = null): ?array
{
    $stmt = db()->prepare("SELECT room_id, room_capacity FROM Rooms WHERE room_id = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) {
        return null;
    }

    $sql    = "SELECT COUNT(*) FROM Occupants WHERE Rooms_room_id = ? AND occupants_reg_status = 'Active'";
    $params = [$roomId];
    if ($ignoreOccupantId !== null) {
        $sql .= ' AND occupants_id <> ?';
        $params[] = $ignoreOccupantId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $used = (int) $stmt->fetchColumn();

    $capacity = (int) $room['room_capacity'];

    return [
        'room_id'   => $room['room_id'],
        'capacity'  => $capacity,
        'used'      => $used,
        'available' => max(0, $capacity - $used),
        'is_full'   => $used >= $capacity,
    ];
}

/** Active occupants of one room (for the occupancy panel). */
function room_members(string $roomId): array
{
    $stmt = db()->prepare("
        SELECT occupants_id, occupants_f_name, occupants_m_name, occupants_l_name, occupants_s_name, occupants_loc_status
        FROM Occupants
        WHERE Rooms_room_id = ? AND occupants_reg_status = 'Active'
        ORDER BY occupants_id
    ");
    $stmt->execute([$roomId]);
    return array_map(fn ($r) => occupant_row($r), $stmt->fetchAll());
}

/* ------------------------------------------------------------------
 * Occupant ID generation:  <floor of room>-<sequence>   e.g. 0101 -> 01-0001
 * ------------------------------------------------------------------ */

function next_occupant_id(string $roomId): string
{
    $floor = substr($roomId, 0, 2);                 // '01'
    if (!preg_match('/^\d{2}$/', $floor)) {
        throw new RuntimeException('Room ID must start with a 2-digit floor, e.g. 0101.');
    }

    // Highest sequence already used on that floor, from IDs and from the room.
    $stmt = db()->prepare("
        SELECT MAX(CAST(SUBSTRING(occupants_id, 4) AS UNSIGNED))
        FROM Occupants
        WHERE occupants_id LIKE CONCAT(:floor, '-%')
    ");
    $stmt->execute([':floor' => $floor]);
    $max = (int) $stmt->fetchColumn();

    $next = $max + 1;
    // Safety: never hand out an ID that somehow already exists.
    do {
        $candidate = sprintf('%s-%04d', $floor, $next);
        $check = db()->prepare('SELECT 1 FROM Occupants WHERE occupants_id = ?');
        $check->execute([$candidate]);
        $exists = (bool) $check->fetchColumn();
        $next++;
    } while ($exists && $next < 10000);

    return $candidate;
}

/* ------------------------------------------------------------------
 * Validation (shared by the forms and the API)
 * ------------------------------------------------------------------ */

const OCCUPANT_SUFFIXES = ['', 'Jr.', 'Sr.', 'II', 'III', 'IV'];

/**
 * @return array{0: array, 1: array}  [clean values, field => error message]
 */
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

/* ------------------------------------------------------------------
 * Mutations
 * ------------------------------------------------------------------ */

/**
 * Register a new occupant.
 * The room row is locked (SELECT ... FOR UPDATE) so two admins cannot both
 * squeeze the 5th person into a 4-bed room.
 *
 * @return array{ok: bool, occupant_id?: string, errors?: array, message?: string, room?: array}
 */
function occupant_create(array $in, ?int $adminId = null): array
{
    [$clean, $errors] = validate_occupant_input($in);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'message' => 'Please fix the highlighted fields.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the room while we count and insert.
        $stmt = $pdo->prepare('SELECT room_id, room_capacity FROM Rooms WHERE room_id = ? FOR UPDATE');
        $stmt->execute([$clean['room_id']]);
        $room = $stmt->fetch();
        if (!$room) {
            $pdo->rollBack();
            return ['ok' => false, 'errors' => ['room_id' => 'That room does not exist.'], 'message' => 'Room not found.'];
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Occupants WHERE Rooms_room_id = ? AND occupants_reg_status = 'Active'");
        $stmt->execute([$clean['room_id']]);
        $used = (int) $stmt->fetchColumn();
        if ($used >= (int) $room['room_capacity']) {
            $pdo->rollBack();
            return [
                'ok'      => false,
                'errors'  => ['room_id' => sprintf('Room %s is full (%d/%d).', $room['room_id'], $used, $room['room_capacity'])],
                'message' => 'That room is already full.',
            ];
        }

        $occupantId = next_occupant_id($clean['room_id']);

        $adminId = $adminId ?? (int) $pdo->query('SELECT MIN(admins_id) FROM Admins')->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO Occupants
              (occupants_id, occupants_f_name, occupants_m_name, occupants_l_name, occupants_s_name,
               occupants_program, occupants_contact_number, occupants_loc_status, occupants_reg_status,
               occupants_reg_at, occupants_last_seen_at, Rooms_room_id, Admins_admins_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Outside', 'Active', NOW(), NOW(), ?, ?)
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

        occupant_audit($occupantId, 'Registered', null, $clean['room_id'], null, $adminId);

        $pdo->commit();
        return ['ok' => true, 'occupant_id' => $occupantId, 'room' => room_state($clean['room_id'])];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Duplicate ID (race) -> rethrow as a friendly message.
        if ((int) $ex->getCode() === 23000) {
            return ['ok' => false, 'errors' => ['room_id' => 'That occupant ID was just taken. Please try again.'], 'message' => 'Duplicate occupant ID.'];
        }
        throw $ex;
    }
}

/** Update an existing occupant (the occupant ID never changes). */
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
        $stmt = $pdo->prepare('SELECT room_id, room_capacity FROM Rooms WHERE room_id = ? FOR UPDATE');
        $stmt->execute([$clean['room_id']]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            return ['ok' => false, 'errors' => ['room_id' => 'That room does not exist.'], 'message' => 'Room not found.'];
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM Occupants
            WHERE Rooms_room_id = ? AND occupants_reg_status = 'Active' AND occupants_id <> ?
        ");
        $stmt->execute([$clean['room_id'], $occupantId]);
        $used = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT room_capacity FROM Rooms WHERE room_id = ?');
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
              occupants_f_name = ?, occupants_m_name = ?, occupants_l_name = ?, occupants_s_name = ?,
              occupants_program = ?, occupants_contact_number = ?, Rooms_room_id = ?,
              occupants_reg_status = 'Active',
              occupants_deactivated_at = NULL,
              occupants_deactivate_reason = NULL,
              occupants_deactivate_source = NULL
            WHERE occupants_id = ?
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

        $oldRoom = $existing['room_id'];
        if ($oldRoom !== $clean['room_id']) {
            occupant_audit($occupantId, 'Updated', $oldRoom, $clean['room_id'], 'Room changed', $adminId);
        }

        $pdo->commit();
        return ['ok' => true, 'occupant_id' => $occupantId, 'room' => room_state($clean['room_id'])];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * Deactivate: the occupant leaves the room, every log row is kept.
 * Returns the freed room so the UI can say "Room 0101 now has 3/4".
 */
function occupant_deactivate(string $occupantId, ?string $reason = null, ?int $adminId = null): array
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
        // An occupant who is still "Inside" must not be left with a dangling presence.
        if ($occ['presence'] === 'Inside') {
            $pdo->prepare("UPDATE Occupants SET occupants_loc_status = 'Outside' WHERE occupants_id = ?")
                ->execute([$occupantId]);

            // Close any open log so the dashboard's On-Premises count stays honest.
            $pdo->prepare("
                UPDATE Logs SET logs_time_out = NOW()
                WHERE Occupants_occupants_id = ? AND logs_time_out IS NULL
            ")->execute([$occupantId]);
        }

        $pdo->prepare("
            UPDATE Occupants
            SET occupants_reg_status          = 'Inactive',
                Rooms_room_id                 = NULL,
                occupants_loc_status          = 'Outside',
                occupants_deactivated_at      = NOW(),
                occupants_deactivate_reason   = ?,
                occupants_deactivate_source   = 'manual'
            WHERE occupants_id = ?
        ")->execute([$reason !== null && $reason !== '' ? $reason : 'Manual deactivation', $occupantId]);

        occupant_audit($occupantId, 'Deactivated', $occ['room_id'], null, $reason, $adminId);

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

/** Reactivate: needs a (new) room; the occupant ID and log history are kept. */
function occupant_reactivate(string $occupantId, string $roomId, ?int $adminId = null): array
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
            SET occupants_reg_status        = 'Active',
                Rooms_room_id               = ?,
                occupants_loc_status        = 'Outside',
                occupants_deactivated_at    = NULL,
                occupants_deactivate_reason = NULL,
                occupants_deactivate_source = NULL
            WHERE occupants_id = ?
        ")->execute([$roomId, $occupantId]);

        occupant_audit($occupantId, 'Reactivated', null, $roomId, 'Reactivated with a new room', $adminId);

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

/** Append to OccupancyAudit (silently no-ops if the patch is not loaded). */
function occupant_audit(string $occupantId, string $action, ?string $oldRoom, ?string $newRoom, ?string $reason, ?int $adminId): void
{
    try {
        $stmt = db()->prepare("
            INSERT INTO OccupancyAudit
              (Occupants_occupants_id, audit_action, audit_old_room, audit_new_room, audit_reason, Admins_admins_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$occupantId, $action, $oldRoom, $newRoom, $reason, $adminId]);
    } catch (Throwable $ex) {
        // Audit table missing (patch not applied) -- never block the main action.
    }
}

/* ------------------------------------------------------------------
 * Pagination helpers
 * ------------------------------------------------------------------ */

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
