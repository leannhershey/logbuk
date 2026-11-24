<?php
/**
 * Occupant E-Logbook  (module: Occupant Time-In & Time-Out)
 *
 * POST {action: "verify",   occupant_id}   -> checks the ID, returns the occupant card
 * POST {action: "time_in"}                 -> records a time-in for the verified occupant
 * POST {action: "time_out", whereabouts}   -> closes the open time-in
 * POST {action: "reset"}                   -> forgets the verified occupant (Back to E-logbook)
 *
 * The occupant ID is verified once, then kept in the PHP session, so time_in /
 * time_out can never be sent for an ID that did not pass "verify".
 *
 * Source of truth for inside/outside: an OPEN log row (logs_time_out IS NULL).
 * Occupants.occupants_loc_status is rewritten from it on every transaction.
 */
require_once __DIR__ . '/../includes/auth.php';

require_post();

$input  = request_input();
$action = (string) ($input['action'] ?? '');

/* ---------- helpers ---------- */

/** "Rene Baterbonia Jr." */
function occ_full_name(array $o): string
{
    return trim($o['occupants_f_name'] . ' ' . $o['occupants_l_name']
        . (!empty($o['occupants_s_name']) ? ' ' . $o['occupants_s_name'] : ''));
}

function occ_label(string $status): string
{
    return $status === 'Inside' ? 'On Premises' : 'Off Premises';
}

/** Occupant row, optionally locked for the rest of the transaction. */
function occ_find(PDO $pdo, string $id, bool $lock = false): ?array
{
    $st = $pdo->prepare('SELECT * FROM Occupants WHERE occupants_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Newest log row that has no time-out yet, or null. */
function occ_open_log(PDO $pdo, string $id, bool $lock = false): ?array
{
    $st = $pdo->prepare(
        'SELECT logs_id, logs_time_in FROM Logs
          WHERE Occupants_occupants_id = ? AND logs_time_out IS NULL
          ORDER BY logs_time_in DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** An occupant may log only if Active AND holding a room. */
function occ_has_room(array $o): bool
{
    return $o['occupants_reg_status'] === 'Active' && !empty($o['Rooms_room_id']);
}

function occ_curfew(PDO $pdo): string
{
    $t = $pdo->query('SELECT settings_curfew_time FROM Settings ORDER BY settings_id LIMIT 1')->fetchColumn();
    return $t ?: sprintf('%02d:00:00', defined('LATE_CUTOFF_HOUR') ? LATE_CUTOFF_HOUR : 22);
}

/** Strictly after the curfew, or in the small hours (before LATE_UNTIL_HOUR). */
function occ_is_late(string $nowSql, string $curfew): bool
{
    $time  = substr($nowSql, 11, 8);                                   // HH:MM:SS
    $until = sprintf('%02d:00:00', defined('LATE_UNTIL_HOUR') ? LATE_UNTIL_HOUR : 5);
    return $time > $curfew || $time < $until;
}

/** mbstring is not guaranteed on every PHP install; fall back to the plain functions. */
function occ_len(string $s): int   { return function_exists('mb_strlen')     ? mb_strlen($s)     : strlen($s); }
function occ_lower(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s); }

/** Logs.logs_whereabouts is a code: 1 Canteen, 2 Library, 3 Class, 4 Home, 5 Others. */
function occ_whereabouts_code(string $text): int
{
    $t = occ_lower($text);
    $map = [
        1 => ['canteen', 'cafeteria', 'eat', 'food', 'lunch', 'dinner', 'breakfast'],
        2 => ['library', 'lib '],
        3 => ['class', 'lecture', 'lab', 'school', 'college'],
        4 => ['home', 'house', 'uwi', 'province'],
    ];
    foreach ($map as $code => $words) {
        foreach ($words as $w) {
            if (str_contains($t . ' ', $w)) return $code;
        }
    }
    return 5;
}

/** True once db/sql-ver-005-whereabouts-text.sql has been applied. */
function occ_has_text_column(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        $has = (bool) $pdo->query("SHOW COLUMNS FROM Logs LIKE 'logs_whereabouts_text'")->fetch();
    }
    return $has;
}

function occ_payload(array $o, string $status): array
{
    return [
        'id'      => $o['occupants_id'],
        'name'    => occ_full_name($o),
        'program' => $o['occupants_program'] ?: '—',
        'room'    => $o['Rooms_room_id'],
        'contact' => $o['occupants_contact_number'],
        'status'  => $status,
        'label'   => occ_label($status),
    ];
}

/* ---------- actions ---------- */

try {
    $pdo = db();

    /* ===== 1. verify ===== */
    if ($action === 'verify') {
        $id = trim((string) ($input['occupant_id'] ?? ''));
        if ($id === '') {
            json_error('Please enter your occupant ID.', 422);
        }

        $o = preg_match('/^\d{2}-\d{4}$/', $id) ? occ_find($pdo, $id) : null;
        if (!$o) {
            unset($_SESSION['occupant_id']);
            json_error('This ID is not registered. Check the ID and try again.', 404);
        }
        if (!occ_has_room($o)) {
            unset($_SESSION['occupant_id']);
            json_error('No room is assigned to this ID. Please see dormitory management.', 403);
        }

        $_SESSION['occupant_id'] = $o['occupants_id'];
        $status = occ_open_log($pdo, $id) ? 'Inside' : 'Outside';
        json_response(['ok' => true, 'occupant' => occ_payload($o, $status)]);
    }

    /* ===== reset ===== */
    if ($action === 'reset') {
        unset($_SESSION['occupant_id']);
        json_response(['ok' => true]);
    }

    /* everything below needs a verified occupant */
    $id = (string) ($_SESSION['occupant_id'] ?? '');
    if ($id === '') {
        json_error('Your session expired. Enter your occupant ID again.', 401);
    }

    /* ===== 2. time-in ===== */
    if ($action === 'time_in') {
        $pdo->beginTransaction();

        $o = occ_find($pdo, $id, true);                    // row lock: no duplicate / double-click inserts
        if (!$o || !occ_has_room($o)) {
            $pdo->rollBack();
            json_error('No room is assigned to this ID. Please see dormitory management.', 403);
        }
        if (occ_open_log($pdo, $id, true)) {
            $pdo->rollBack();
            json_error('You are already inside. Time out first before you time in again.', 409);
        }

        $now  = (string) $pdo->query('SELECT NOW()')->fetchColumn();
        $late = occ_is_late($now, occ_curfew($pdo));

        $pdo->prepare(
            'INSERT INTO Logs (logs_time_in, logs_is_late, Occupants_occupants_id, Occupants_Rooms_room_id)
             VALUES (?, ?, ?, ?)'
        )->execute([$now, $late ? 1 : 0, $id, $o['Rooms_room_id']]);

        $pdo->prepare("UPDATE Occupants SET occupants_loc_status = 'Inside' WHERE occupants_id = ?")
            ->execute([$id]);
        $pdo->commit();

        $dt = new DateTime($now);
        json_response(['ok' => true, 'result' => [
            'name'        => occ_full_name($o),
            'transaction' => 'Time-in',
            'date'        => $dt->format('F j, Y'),
            'time'        => $dt->format('g:i A'),
            'whereabouts' => null,
            'status'      => 'Inside',
            'label'       => occ_label('Inside'),
            'late'        => $late,
            'curfew'      => (new DateTime(occ_curfew($pdo)))->format('g:i A'),
        ]]);
    }

    /* ===== 3. time-out ===== */
    if ($action === 'time_out') {
        $where = trim((string) ($input['whereabouts'] ?? ''));
        if ($where === '') {
            json_error('Please enter your destination before time-out.', 422);
        }
        if (occ_len($where) > 100) {
            json_error('Whereabouts is too long (100 characters max).', 422);
        }

        $pdo->beginTransaction();

        $o = occ_find($pdo, $id, true);
        if (!$o) {
            $pdo->rollBack();
            json_error('This ID is not registered.', 404);
        }
        $log = occ_open_log($pdo, $id, true);
        if (!$log) {
            $pdo->rollBack();
            json_error('No active time-in record found. Time in first.', 409);
        }

        $now = (string) $pdo->query('SELECT NOW()')->fetchColumn();
        if (strtotime($now) <= strtotime($log['logs_time_in'])) {
            $pdo->rollBack();
            json_error('Time-out must be after your time-in. Wait a moment and try again.', 409);
        }

        $code = occ_whereabouts_code($where);
        if (occ_has_text_column($pdo)) {
            $pdo->prepare(
                'UPDATE Logs SET logs_time_out = ?, logs_whereabouts = ?, logs_whereabouts_text = ?
                  WHERE logs_id = ? AND Occupants_occupants_id = ?'
            )->execute([$now, $code, $where, $log['logs_id'], $id]);
        } else {
            $pdo->prepare(
                'UPDATE Logs SET logs_time_out = ?, logs_whereabouts = ?
                  WHERE logs_id = ? AND Occupants_occupants_id = ?'
            )->execute([$now, $code, $log['logs_id'], $id]);
        }

        $pdo->prepare("UPDATE Occupants SET occupants_loc_status = 'Outside' WHERE occupants_id = ?")
            ->execute([$id]);
        $pdo->commit();

        $dt = new DateTime($now);
        json_response(['ok' => true, 'result' => [
            'name'        => occ_full_name($o),
            'transaction' => 'Time-out',
            'date'        => $dt->format('F j, Y'),
            'time'        => $dt->format('g:i A'),
            'whereabouts' => $where,
            'status'      => 'Outside',
            'label'       => occ_label('Outside'),
            'late'        => false,
            'curfew'      => null,
        ]]);
    }

    json_error('Unknown action.', 400);

} catch (Throwable $ex) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('occupant_logbook: ' . $ex->getMessage());
    json_error('Something went wrong on the server. Please try again.', 500);
}
