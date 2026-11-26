<?php

/* Room availability: one row per room with capacity, assigned occupants and free slots. */

require_once __DIR__ . '/db.php';

function rooms_overview(): array
{
    $sql = "
        SELECT r.room_id,
               r.capacity,
               COUNT(o.occupant_id)                       AS used,
               GREATEST(r.capacity - COUNT(o.occupant_id), 0) AS free,
               GROUP_CONCAT(
                   CONCAT(o.presence_status, '~', o.last_name, ', ', o.first_name)
                   ORDER BY o.last_name, o.first_name
                   SEPARATOR '|'
               )                                            AS occupant_names
          FROM Rooms r
          LEFT JOIN Occupants o
                 ON o.room_id = r.room_id
                AND o.residency_status = 'Active'
         GROUP BY r.room_id, r.capacity
         ORDER BY r.room_id
    ";

    $rows = db()->query($sql)->fetchAll();

    $rooms = [];
    foreach ($rows as $r) {
        $capacity = (int) $r['capacity'];
        $used     = (int) $r['used'];
        $free     = max(0, $capacity - $used);

        $names = [];
        if (!empty($r['occupant_names'])) {
            foreach (explode('|', $r['occupant_names']) as $n) {
                [$loc, $name] = array_pad(explode('~', $n, 2), 2, '');
                $name = trim(preg_replace('/\s+/', ' ', $name));
                if ($name !== '') {

                    $names[] = ['name' => $name, 'inside' => $loc === 'On Premises'];
                }
            }
        }

        $rooms[] = [
            'room_id'   => $r['room_id'],
            'floor'     => substr($r['room_id'], 0, 2),
            'floor_no'  => (int) substr($r['room_id'], 0, 2),
            'room_no'   => substr($r['room_id'], 2),
            'capacity'  => $capacity,
            'used'      => $used,
            'free'      => $free,
            'percent'   => $capacity > 0 ? (int) round($used / $capacity * 100) : 0,
            'status'    => room_status($used, $capacity),
            'occupants' => $names,
            'is_full'   => $free === 0,
            'is_empty'  => $used === 0,
        ];
    }

    return $rooms;
}

function room_status(int $used, int $capacity): string
{
    if ($used <= 0) {
        return 'empty';
    }
    return $used >= $capacity ? 'full' : 'available';
}

function rooms_totals(array $rooms): array
{
    $capacity = 0;
    $used     = 0;
    $full     = 0;
    $empty    = 0;

    foreach ($rooms as $r) {
        $capacity += $r['capacity'];
        $used     += $r['used'];
        if ($r['is_full']) {
            $full++;
        }
        if ($r['is_empty']) {
            $empty++;
        }
    }

    return [
        'rooms'    => count($rooms),
        'capacity' => $capacity,
        'used'     => $used,
        'free'     => max(0, $capacity - $used),
        'full'     => $full,
        'empty'    => $empty,
        'percent'  => $capacity > 0 ? (int) round($used / $capacity * 100) : 0,
    ];
}

function rooms_by_floor(array $rooms): array
{
    $floors = [];
    foreach ($rooms as $r) {
        $floors[$r['floor']][] = $r;
    }
    ksort($floors);
    return $floors;
}
