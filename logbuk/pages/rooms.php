<?php

/* Room cards: capacity, current occupants and free slots per room. */

require_once __DIR__ . '/../includes/auth.php';
require_admin_page();

header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/rooms.php';

$dbError  = null;
$rooms    = [];
$totals   = ['rooms' => 0, 'capacity' => 0, 'used' => 0, 'free' => 0, 'full' => 0, 'empty' => 0, 'percent' => 0];
$floors   = [];

try {
    $rooms  = rooms_overview();
    $totals = rooms_totals($rooms);
    $floors = rooms_by_floor($rooms);
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
}

$activeNav = 'rooms';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $pageTitle = 'Rooms'; require __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
  <div class="admin-shell">
    <aside class="sidebar" id="sidebar" data-active="<?= e($activeNav) ?>">
      <?php require __DIR__ . '/../includes/sidebar.php'; ?>
    </aside>

    <div class="admin-content">
      <header class="page-header">
        <span data-icon="door"></span>
        <h1>Rooms</h1>
      </header>

      <main class="page-body">
        <?php if ($dbError !== null): $hint = db_error_hint($dbError); ?>
          <div class="db-error">
            <strong><?= e($hint['title']) ?></strong><br>
            <span class="db-error__detail"><?= e($dbError) ?></span>
            <ol class="db-error__fix">
              <?php foreach ($hint['steps'] as $step): ?><li><?= $step ?></li><?php endforeach; ?>
            </ol>
          </div>
        <?php else: ?>

        <section class="room-summary" aria-label="Room availability summary">
          <article class="room-stat">
            <span class="room-stat__value"><?= (int) $totals['capacity'] ?></span>
            <span class="room-stat__label">Total slots</span>
          </article>
          <article class="room-stat room-stat--used">
            <span class="room-stat__value"><?= (int) $totals['used'] ?></span>
            <span class="room-stat__label">Occupied</span>
          </article>
          <article class="room-stat room-stat--free">
            <span class="room-stat__value"><?= (int) $totals['free'] ?></span>
            <span class="room-stat__label">Available slots</span>
          </article>
          <article class="room-stat">
            <span class="room-stat__value"><?= (int) $totals['percent'] ?>%</span>
            <span class="room-stat__label">Occupancy</span>
          </article>
        </section>

        <?php if (!$rooms): ?>
          <section class="card room-empty">
            <h2 class="room-empty__title">No rooms yet</h2>
            <p class="room-empty__text">No rooms are registered yet. Add one in the database (Rooms table), then reload.</p>
          </section>
        <?php else: ?>
          <section class="room-grid">
            <?php foreach ($rooms as $r): ?>
              <article class="room-card room-card--<?= e($r['status']) ?>">
                <header class="room-card__head">
                  <h2 class="room-card__id">Room <?= e($r['room_id']) ?></h2>
                  <span class="room-card__floor">Floor <?= (int) $r['floor_no'] ?> &middot; Room <?= e($r['room_no']) ?></span>
                </header>

                <div class="room-card__body">
                  <div class="room-card__ratio"><?= (int) $r['used'] ?> / <?= (int) $r['capacity'] ?></div>

                  <div class="room-bar" role="img"
                       aria-label="<?= (int) $r['used'] ?> of <?= (int) $r['capacity'] ?> beds occupied in room <?= e($r['room_id']) ?>">
                    <span class="room-bar__fill" style="width: <?= (int) $r['percent'] ?>%"></span>
                  </div>

                  <?php if ($r['occupants']): ?>
                    <ul class="room-card__people">
                      <?php foreach ($r['occupants'] as $p): $k = $p['inside'] ? 'on' : 'off'; ?>
                        <li>
                          <svg class="room-person room-person--<?= $k ?>" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="7" r="4.2"/><path d="M4 21c0-4.4 3.6-7.5 8-7.5s8 3.1 8 7.5z"/></svg>
                          <span><?= e($p['name']) ?></span>
                          <span class="sr-only"><?= $p['inside'] ? '(on premises)' : '(off premises)' ?></span>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <p class="room-card__nobody">No occupants assigned.</p>
                  <?php endif; ?>

                  <?php if ($r['free'] > 0): ?>
                    <a class="room-card__register" href="register-occupant.php?room=<?= e($r['room_id']) ?>">+ Register here</a>
                  <?php else: ?>
                    <span class="room-card__register is-disabled">Room full</span>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </section>

          <p class="room-note">
            <span class="room-note__item">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M12 11v6M12 7.5v.01"/></svg>
              Each room has a maximum capacity of four (4) occupants.
            </span>
            <span class="room-note__item"><svg class="room-person room-person--on" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="color:var(--color-on)"><circle cx="12" cy="7" r="4.2"/><path d="M4 21c0-4.4 3.6-7.5 8-7.5s8 3.1 8 7.5z"/></svg>On Premises</span>
            <span class="room-note__item"><svg class="room-person room-person--off" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="color:var(--color-off)"><circle cx="12" cy="7" r="4.2"/><path d="M4 21c0-4.4 3.6-7.5 8-7.5s8 3.1 8 7.5z"/></svg>Off Premises</span>
            <span class="room-note__item room-note__sep"><span class="room-note__dot" style="background:var(--room-empty)"></span>Empty</span>
            <span class="room-note__item"><span class="room-note__dot" style="background:var(--room-available)"></span>Available</span>
            <span class="room-note__item"><span class="room-note__dot" style="background:var(--room-full)"></span>Full</span>
          </p>
        <?php endif; ?>

        <?php endif; ?>
      </main>
    </div>
  </div>

  <script src="../assets/js/core.js"></script>
  <script src="../assets/js/admin-shell.js"></script>
</body>
</html>
