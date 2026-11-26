<?php

/* Dormitory log history: tabs, search, room and date filters, pagination. */

require_once __DIR__ . '/../includes/auth.php';
require_admin_page();

header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/logs.php';
require_once __DIR__ . '/../includes/occupants.php';

$view    = logs_normalise_view($_GET['view'] ?? 'all');
$filters = logs_normalise_filters($_GET);
$page    = max(1, (int) ($_GET['page'] ?? 1));

$dbError = null;
try {
    $counts   = logs_counts($filters);
    $total    = $counts[$view];
    $lastPage = max(1, (int) ceil($total / LOGS_PER_PAGE));
    $page     = min($page, $lastPage);
    $rows     = logs_page($view, $filters, $page, LOGS_PER_PAGE);
    $rooms    = logs_rooms();
} catch (Throwable $ex) {
    $dbError  = $ex->getMessage();
    $counts   = array_fill_keys(LOG_VIEWS, 0);
    $total    = 0;
    $lastPage = 1;
    $rows     = [];
    $rooms    = [];
}

function logs_qs(array $overrides = []): string
{
    global $view, $filters;
    $base   = ['view' => $view, 'q' => $filters['q'], 'room' => $filters['room'], 'date' => $filters['date'], 'page' => ''];
    $merged = array_filter(array_merge($base, $overrides),
        fn ($v) => $v !== '' && $v !== 'all' && $v !== null);
    return 'logs.php' . ($merged ? '?' . http_build_query($merged) : '');
}

$hasFilters = $filters['q'] !== '' || $filters['room'] !== 'all' || $filters['date'] !== '';

$tabs = [
    'all'     => ['All logs',          $counts['all']],
    'late'    => ['Late entries',      $counts['late']],
    'inside'  => ['Still on premises', $counts['inside']],
];

$activeNav = 'logs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $pageTitle = 'Dormitory Logs'; require __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
  <div class="admin-shell">
    <aside class="sidebar" id="sidebar" data-active="<?= e($activeNav) ?>">
      <?php require __DIR__ . '/../includes/sidebar.php'; ?>
    </aside>

    <div class="admin-content">
      <header class="page-header">
        <span data-icon="file"></span>
        <h1>Dormitory Logs</h1>
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

        <form class="list-toolbar" method="get" action="logs.php" id="logFilterForm">
          <input type="hidden" name="view" id="viewInput" value="<?= e($view) ?>">

          <label class="toolbar-field toolbar-field--search">
            <span>Occupant</span>
            <span class="search-box">
              <span data-icon="search"></span>
              <input type="search" name="q" id="searchInput" autocomplete="off"
                     placeholder="Search by ID or name" value="<?= e($filters['q']) ?>">
            </span>
          </label>

          <label class="toolbar-field">
            <span>Room</span>
            <select name="room" id="roomFilter">
              <option value="all">All Rooms</option>
              <?php foreach ($rooms as $roomId): ?>
                <option value="<?= e($roomId) ?>" <?= $filters['room'] === $roomId ? 'selected' : '' ?>><?= e($roomId) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="toolbar-field">
            <span>Date</span>
            <input type="date" name="date" id="dateFilter" class="date-input"
                   value="<?= e($filters['date']) ?>" aria-label="Filter by date">
          </label>

          <a class="btn-clear <?= $hasFilters ? '' : 'is-idle' ?>" id="clearFilters"
             href="<?= e(logs_qs(['q' => '', 'room' => '', 'date' => '', 'page' => ''])) ?>">Clear</a>

          <noscript><button type="submit" class="btn btn--primary">Apply</button></noscript>

        </form>

        <nav class="log-tabs" id="logTabs" aria-label="Log views">
          <?php foreach ($tabs as $key => [$label, $n]): ?>
            <a class="log-tab <?= $view === $key ? 'is-active' : '' ?>" data-view="<?= e($key) ?>"
               href="<?= e(logs_qs(['view' => $key === 'all' ? '' : $key, 'page' => ''])) ?>"
               <?= $view === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?> (<?= (int) $n ?>)</a>
          <?php endforeach; ?>
        </nav>

        <section class="list-card" id="logsCard">
          <div class="table-scroll">
            <table class="occupants-table logs-table">
              <thead>
                <tr>
                  <th>Log ID</th>
                  <th>Occupant</th>
                  <th>Room</th>
                  <th>Date</th>
                  <th>Time In</th>
                  <th>Time Out</th>
                  <th>Whereabouts</th>
                  <th>Late Entry</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$rows): ?>
                <?php
                  if ($hasFilters) {
                      $emptyIcon = 'search'; $emptyTitle = 'No records match your filters';
                      $emptyText = 'Try a different name, occupant ID, room or date.';
                  } elseif ($view === 'late') {
                      $emptyIcon = 'clock'; $emptyTitle = 'No late entries';
                      $emptyText = 'Nobody has timed in after curfew.';
                  } elseif ($view === 'inside') {
                      $emptyIcon = 'door'; $emptyTitle = 'Nobody is on premises';
                      $emptyText = 'Every occupant is currently timed out.';
                  } elseif ($view === 'outside') {
                      $emptyIcon = 'door'; $emptyTitle = 'Nobody is off premises';
                      $emptyText = 'Every active occupant is currently inside.';
                  } else {
                      $emptyIcon = 'file'; $emptyTitle = 'No log records yet';
                      $emptyText = 'Records appear here as soon as an occupant times in.';
                  }
                ?>
                <tr><td colspan="8" class="table-empty">
                  <div class="empty-state">
                    <div class="empty-state__icon"><span data-icon="<?= e($emptyIcon) ?>"></span></div>
                    <p class="empty-state__title"><?= e($emptyTitle) ?></p>
                    <p class="empty-state__text"><?= e($emptyText) ?></p>
                    <?php if ($hasFilters): ?><a class="empty-state__link" href="<?= e(logs_qs(['q' => '', 'room' => '', 'date' => '', 'page' => ''])) ?>">Clear filters</a><?php endif; ?>
                  </div>
                </td></tr>
              <?php else: foreach ($rows as $r): ?>
                <tr data-log-row data-log-id="<?= e($r['log_id']) ?>">
                  <td class="cell-id"><?= $r['has_log'] ? (int) $r['log_id'] : '-' ?></td>
                  <td class="cell-occupant">
                    <strong><?= e($r['full_name']) ?></strong>
                    <small><?= e($r['occupant_id']) ?></small>
                  </td>
                  <td class="cell-room"><?= e($r['room_id'] ?: '-') ?></td>
                  <td class="cell-date"><?= $r['has_log'] ? e($r['date']) : '-' ?></td>
                  <td class="cell-time"><?= $r['has_log'] ? e($r['time_in']) : '-' ?></td>
                  <td class="cell-time"><?= $r['time_out'] !== '' ? e($r['time_out']) : '-' ?></td>
                  <td class="cell-where"><?= $r['whereabouts'] !== null ? e($r['whereabouts']) : '-' ?></td>
                  <td>
                    <?php if ($r['is_late']): ?>
                      <span class="badge badge--late">Yes</span>
                    <?php elseif ($r['has_log']): ?>
                      <span class="badge badge--on">No</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <footer class="list-footer">
            <span id="showingLabel"><?= e(logs_showing_label($total, $page, LOGS_PER_PAGE)) ?></span>
            <?php $items = pagination_items($page, $lastPage); if ($items): ?>
              <nav class="pager" aria-label="Pagination">
                <?php foreach ($items as $item): ?>
                  <?php if ($item === null): ?><span class="pager__gap">…</span>
                  <?php else: ?>
                    <a class="pager__btn <?= $item === $page ? 'is-active' : '' ?>"
                       href="<?= e(logs_qs(['page' => $item > 1 ? $item : ''])) ?>"
                       <?= $item === $page ? 'aria-current="page"' : '' ?>><?= $item ?></a>
                  <?php endif; ?>
                <?php endforeach; ?>
              </nav>
            <?php endif; ?>
          </footer>
        </section>

        <?php endif; ?>
      </main>
    </div>
  </div>

  <script src="../assets/js/core.js"></script>
  <script src="../assets/js/admin-shell.js"></script>
  <script src="../assets/js/logs.js"></script>
</body>
</html>
