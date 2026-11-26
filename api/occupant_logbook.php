<?php

/* Kiosk time-in and time-out. Open to occupants, then gated by the occupant ID. */

require_once __DIR__ . '/../includes/auth.php';

require_post();

$input  = request_input();
$action = (string) ($input['action'] ?? '');

function occ_full_name(array $o): string
{
    return trim($o['first_name'] . ' ' . $o['last_name']
        . (!empty($o['suffix']) ? ' ' . $o['suffix'] : ''));
}

function occ_find(PDO $pdo, string $id, bool $lock = false): ?array
{
    $st = $pdo->prepare('SELECT * FROM Occupants WHERE occupant_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function occ_open_log(PDO $pdo, string $id, bool $lock = false): ?array
{
    $st = $pdo->prepare(
        'SELECT log_id, time_in FROM Logs
          WHERE occupant_id = ? AND time_out IS NULL
          ORDER BY time_in DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function occ_has_room(array $o): bool
{
    return $o['residency_status'] === 'Active' && !empty($o['room_id']);
}

function occ_curfew(PDO $pdo): string
{
    $t = $pdo->query('SELECT curfew_time FROM Settings ORDER BY setting_id LIMIT 1')->fetchColumn();
    return $t ?: sprintf('%02d:00:00', defined('LATE_CUTOFF_HOUR') ? LATE_CUTOFF_HOUR : 22);
}

function occ_is_late(string $nowSql, string $curfew): bool
{
    $time  = substr($nowSql, 11, 8);
    $until = sprintf('%02d:00:00', defined('LATE_UNTIL_HOUR') ? LATE_UNTIL_HOUR : 5);
    return $time > $curfew || $time < $until;
}

function occ_len(string $s): int   { return function_exists('mb_strlen')     ? mb_strlen($s)     : strlen($s); }

function occ_payload(array $o, string $status): array
{
    return [
        'id'      => $o['occupant_id'],
        'name'    => occ_full_name($o),
        'program' => $o['program'] ?: '—',
        'room'    => $o['room_id'],
        'contact' => $o['contact_number'],
        'status'  => $status,
        'label'   => $status,
    ];
}

try {
    $pdo = db();

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

        $_SESSION['occupant_id'] = $o['occupant_id'];
        $status = occ_open_log($pdo, $id) ? 'On Premises' : 'Off Premises';
        json_response(['ok' => true, 'occupant' => occ_payload($o, $status)]);
    }

    if ($action === 'reset') {
        unset($_SESSION['occupant_id']);
        json_response(['ok' => true]);
    }

    $id = (string) ($_SESSION['occupant_id'] ?? '');
    if ($id === '') {
        json_error('Your session expired. Enter your occupant ID again.', 401);
    }

    if ($action === 'time_in') {
        $pdo->beginTransaction();

        $o = occ_find($pdo, $id, true);
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
            'INSERT INTO Logs (time_in, is_late, occupant_id, room_id)
             VALUES (?, ?, ?, ?)'
        )->execute([$now, $late ? 1 : 0, $id, $o['room_id']]);

        $pdo->prepare("UPDATE Occupants SET presence_status = 'On Premises' WHERE occupant_id = ?")
            ->execute([$id]);
        $pdo->commit();

        $dt = new DateTime($now);
        json_response(['ok' => true, 'result' => [
            'name'        => occ_full_name($o),
            'transaction' => 'Time-in',
            'date'        => $dt->format('F j, Y'),
            'time'        => $dt->format('g:i A'),
            'whereabouts' => null,
            'status'      => 'On Premises',
            'label'       => 'On Premises',
            'late'        => $late,
            'curfew'      => (new DateTime(occ_curfew($pdo)))->format('g:i A'),
        ]]);
    }

    if ($action === 'time_out') {
        $where = trim((string) ($input['whereabouts'] ?? ''));
        if ($where === '') {
            json_error('Please enter your destination before time-out.', 422);
        }
        if (occ_len($where) > 150) {
            json_error('Whereabouts is too long (150 characters max).', 422);
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
        if (strtotime($now) <= strtotime($log['time_in'])) {
            $pdo->rollBack();
            json_error('Time-out must be after your time-in. Wait a moment and try again.', 409);
        }

        $pdo->prepare(
            'UPDATE Logs SET time_out = ?, whereabouts = ?
              WHERE log_id = ? AND occupant_id = ?'
        )->execute([$now, $where, $log['log_id'], $id]);

        $pdo->prepare("UPDATE Occupants SET presence_status = 'Off Premises' WHERE occupant_id = ?")
            ->execute([$id]);
        $pdo->commit();

        $dt = new DateTime($now);
        json_response(['ok' => true, 'result' => [
            'name'        => occ_full_name($o),
            'transaction' => 'Time-out',
            'date'        => $dt->format('F j, Y'),
            'time'        => $dt->format('g:i A'),
            'whereabouts' => $where,
            'status'      => 'Off Premises',
            'label'       => 'Off Premises',
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
