<?php
/**
 * Admin -> Occupants  (the screen you reach from the sidebar)
 *
 * Server-rendered: sorting, filtering, search, counting and pagination are all
 * done in MySQL. The JS layer (assets/js/occupants.js) only makes it feel
 * instant and drives the deactivate / reactivate modals through api/occupants_api.php.
 *
 *   occupants.php
 *   occupants.php?q=rene
 *   occupants.php?room=0101&residency=Active&sort=name&dir=desc&page=2
 *
 * `occupants.html` is a one-line redirect to this file so the existing
 * dashboard.js sidebar link keeps working.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin_page();          // no session -> back to index.html#admin

require_once __DIR__ . '/../includes/occupants.php';

/* ---------------- request ---------------- */
$filters = [
    'q'         => $_GET['q'] ?? '',
    'room'      => $_GET['room'] ?? 'all',
    'residency' => $_GET['residency'] ?? ($_GET['status'] ?? 'all'),
    'presence'  => $_GET['presence'] ?? 'all',
];

$sort = (string) ($_GET['sort'] ?? 'id');
$dir  = (string) ($_GET['dir'] ?? 'asc');
[$sort, $dir] = occupants_normalise_sort($sort, $dir);

$page = max(1, (int) ($_GET['page'] ?? 1));

/* ---------------- data ---------------- */
$dbError   = null;
$autoRun    = null;

try {
    // "Outside for 2 weeks" rule: runs at most once a day, then the page
    // renders with whatever the rule decided. Fails silently if the patch
    // (sql-ver-004.sql) has not been applied yet.
    require_once __DIR__ . '/../includes/auto-deactivate.php';
    try {
        $autoRun = auto_deactivate_if_due(24);
    } catch (Throwable $ex) {
        $autoRun = null;   // procedure missing -> not fatal
    }

    $stats     = occupants_stats();
    $total     = occupants_count($filters);
    $lastPage  = max(1, (int) ceil($total / OCCUPANTS_PER_PAGE));
    $page      = min($page, $lastPage);
    $occupants = occupants_page($filters, $page, OCCUPANTS_PER_PAGE, $sort, $dir);
    $rooms     = rooms_with_occupancy();
} catch (Throwable $ex) {
    $dbError   = $ex->getMessage();
    $stats     = ['total' => 0, 'active' => 0, 'inactive' => 0, 'inside' => 0];
    $total     = 0;
    $lastPage  = 1;
    $occupants = [];
    $rooms     = [];
}

/* ---------------- helpers ---------------- */

/** Current query string with overrides — used by sort links, pager and filters. */
function qs(array $overrides = []): string
{
    $base = [
        'q'         => $_GET['q'] ?? '',
        'room'      => $_GET['room'] ?? '',
        'residency' => $_GET['residency'] ?? '',
        'presence'  => $_GET['presence'] ?? '',
        'sort'      => $_GET['sort'] ?? '',
        'dir'       => $_GET['dir'] ?? '',
        'page'      => $_GET['page'] ?? '',
    ];
    $merged = array_filter(array_merge($base, $overrides), fn ($v) => $v !== '' && $v !== 'all' && $v !== null);
    return 'occupants.php' . ($merged ? '?' . http_build_query($merged) : '');
}

/** Sortable column header: toggles asc/desc, shows an arrow on the active column. */
function sort_header(string $key, string $label, string $currentSort, string $currentDir): string
{
    $isActive = $currentSort === $key;
    $nextDir  = $isActive && strtoupper($currentDir) === 'ASC' ? 'desc' : 'asc';
    $arrow    = $isActive
        ? (strtoupper($currentDir) === 'ASC' ? '▲' : '▼')
        : '';
    $href = qs(['sort' => $key, 'dir' => $nextDir, 'page' => '']);
    $cls  = 'th-sort' . ($isActive ? ' is-sorted' : '');
    return sprintf(
        '<a class="%s" href="%s" aria-sort="%s" data-sort="%s" data-dir="%s">%s<span class="th-sort__arrow">%s</span></a>',
        $cls, e($href),
        $isActive ? (strtoupper($currentDir) === 'ASC' ? 'ascending' : 'descending') : 'none',
        e($key), e($nextDir), e($label), $arrow
    );
}

$activeNav = 'occupants';

/* room_id => occupancy, so the deactivate modal can say what the freed slot is */
$roomByld = [];
foreach ($rooms as $r) {
    $roomByld[$r['room_id']] = $r;
}

/* Empty-state variant */
$hasFilters = trim((string) $filters['q']) !== ''
           || ($filters['room'] !== 'all' && $filters['room'] !== '')
           || ($filters['residency'] !== 'all' && $filters['residency'] !== '')
           || ($filters['presence'] !== 'all' && $filters['presence'] !== '');

$view = ($_GET['view'] ?? 'table') === 'cards' ? 'cards' : 'table';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LogBUk – Occupants</title>
  <link rel="icon" href="../assets/images/logo.png" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/main.css">
  <link rel="stylesheet" href="../assets/css/dashboard.css">
  <link rel="stylesheet" href="../assets/css/occupants.css">
</head>
<body>
  <div class="admin-shell">
    <aside class="sidebar" id="sidebar" data-active="<?= e($activeNav) ?>">
      <?php require __DIR__ . '/../includes/sidebar.php'; ?>
    </aside>

    <div class="admin-content">
      <header class="page-header">
        <span data-icon="users"></span>
        <h1>Occupants</h1>
      </header>

      <main class="page-body">
        <?php if ($autoRun && $autoRun['ran'] && count($autoRun['deactivated']) > 0): ?>
          <div class="alert alert--error" id="autoRunNotice" role="status">
            <span data-icon="warning"></span>
            <span>
              <strong><?= count($autoRun['deactivated']) ?></strong> occupant(s) were automatically deactivated
              for not checking in for <strong><?= (int) ($autoRun['threshold'] ?? 14) ?> days</strong>.
              Their rooms were freed and their log history kept — they can be reactivated.
            </span>
          </div>
        <?php endif; ?>

        <?php if (!empty($_GET['registered'])): ?>
          <div class="alert alert--success" id="flashMessage" role="status">
            <span data-icon="clipboard"></span>
            <span>Occupant <strong><?= e($_GET['registered']) ?></strong> registered successfully.</span>
          </div>
        <?php elseif (!empty($_GET['updated'])): ?>
          <div class="alert alert--success" id="flashMessage" role="status">
            <span data-icon="clipboard"></span>
            <span>Changes to <strong><?= e($_GET['updated']) ?></strong> were saved.</span>
          </div>
        <?php endif; ?>

        <?php if ($dbError !== null): $hint = db_error_hint($dbError); ?>
          <div class="db-error">
            <strong><?= e($hint['title']) ?></strong><br>
            <span class="db-error__detail"><?= e($dbError) ?></span>

            <ol class="db-error__fix">
              <?php foreach ($hint['steps'] as $step): ?>
                <li><?= $step /* pre-escaped: contains <code> tags */ ?></li>
              <?php endforeach; ?>
            </ol>

            <div class="db-error__meta">
              Using <code><?= e(DB_USER) ?>@<?= e(DB_HOST) ?></code>,
              database <code><?= e(DB_NAME) ?></code>,
              password <?= DB_PASS === '' ? '<em>empty</em>' : '<em>set</em>' ?>
              — file <code>config/config.php</code>.
            </div>
          </div>
        <?php else: ?>

        <!-- Summary chips -->
        <section class="stat-chips" aria-label="Occupancy summary">
          <a class="chip <?= !$hasFilters ? 'is-active' : '' ?>" href="occupants.php">
            <span class="chip__value"><?= $stats['total'] ?></span>
            <span class="chip__label">All occupants</span>
          </a>
          <a class="chip" href="<?= e(qs(['residency' => 'Active', 'page' => ''])) ?>"
             data-tooltip="Occupants with an active room assignment">
            <span class="chip__value"><?= $stats['active'] ?></span>
            <span class="chip__label">Active</span>
          </a>
          <a class="chip" href="<?= e(qs(['presence' => 'Inside', 'residency' => 'Active', 'page' => ''])) ?>"
             data-tooltip="Active occupants currently timed in">
            <span class="chip__value"><?= $stats['inside'] ?></span>
            <span class="chip__label">On premises</span>
          </a>
          <a class="chip <?= ($filters['residency'] === 'Inactive') ? 'is-active' : '' ?>"
             href="<?= e(qs(['residency' => 'Inactive', 'page' => ''])) ?>"
             data-tooltip="Deactivated occupants with no room — can be reactivated">
            <span class="chip__value"><?= $stats['inactive'] ?></span>
            <span class="chip__label">Inactive</span>
          </a>
        </section>

        <!-- Toolbar -->
        <form class="list-toolbar" method="get" action="occupants.php" id="filterForm">
          <input type="hidden" name="sort" value="<?= e($sort) ?>">
          <input type="hidden" name="dir"  value="<?= e($dir) ?>">

          <label class="toolbar-field toolbar-field--search">
            <span>Search</span>
            <span class="search-box">
              <span data-icon="search"></span>
              <input type="search" name="q" id="searchInput" autocomplete="off"
                     placeholder="Search by ID, name, program or contact"
                     value="<?= e($filters['q']) ?>">
            </span>
          </label>

          <label class="toolbar-field">
            <span>Room</span>
            <select name="room" id="roomFilter">
              <option value="all">All Rooms</option>
              <?php foreach ($rooms as $room): ?>
                <option value="<?= e($room['room_id']) ?>"
                  <?= $filters['room'] === $room['room_id'] ? 'selected' : '' ?>>
                  <?= e($room['room_id']) ?> (<?= $room['used'] ?>/<?= $room['capacity'] ?><?= $room['is_full'] ? ' full' : '' ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="toolbar-field">
            <span>Residency</span>
            <select name="residency" id="residencyFilter">
              <option value="all"      <?= $filters['residency'] === 'all'      ? 'selected' : '' ?>>All</option>
              <option value="Active"   <?= $filters['residency'] === 'Active'   ? 'selected' : '' ?>>Active</option>
              <option value="Inactive" <?= $filters['residency'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
          </label>

          <label class="toolbar-field">
            <span>Presence</span>
            <select name="presence" id="presenceFilter">
              <option value="all"     <?= $filters['presence'] === 'all'     ? 'selected' : '' ?>>All</option>
              <option value="Inside"  <?= $filters['presence'] === 'Inside'  ? 'selected' : '' ?>>On Premises</option>
              <option value="Outside" <?= $filters['presence'] === 'Outside' ? 'selected' : '' ?>>Off Premises</option>
            </select>
          </label>

          <noscript><button type="submit" class="btn btn--primary">Apply</button></noscript>

          <a class="btn--register" href="register-occupant.php">
            <span data-icon="plus"></span>Register Occupant
          </a>
        </form>

        <!-- Table -->
        <section class="list-card" id="listCard">
          <div class="table-scroll">
            <table class="occupants-table">
              <thead>
                <tr>
                  <th><?= sort_header('id',    'Occupant ID', $sort, $dir) ?></th>
                  <th><?= sort_header('name',  'Full Name',   $sort, $dir) ?></th>
                  <th><?= sort_header('program', 'Program',   $sort, $dir) ?></th>
                  <th><?= sort_header('contact', 'Contact Number', $sort, $dir) ?></th>
                  <th><?= sort_header('room',  'Room',        $sort, $dir) ?></th>
                  <th><?= sort_header('presence', 'Presence', $sort, $dir) ?></th>
                  <th><?= sort_header('residency', 'Residency', $sort, $dir) ?></th>
                  <th><?= sort_header('reg_at', 'Registered At', $sort, $dir) ?></th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$occupants): ?>
                  <?php
                    if ($stats['total'] === 0 && !$hasFilters) {
                        $emptyIcon  = 'users';
                        $emptyTitle = 'No occupants registered yet';
                        $emptyText  = 'The dormitory log book is empty. Register the first occupant to get started.';
                        $emptyCta   = true;
                    } elseif (($filters['room'] !== 'all' && $filters['room'] !== '')
                              && trim((string) $filters['q']) === ''
                              && ($filters['residency'] === 'all' || $filters['residency'] === '')
                              && ($filters['presence'] === 'all' || $filters['presence'] === '')) {
                        $emptyIcon  = 'door';
                        $emptyTitle = 'Room ' . $filters['room'] . ' is still vacant';
                        $emptyText  = 'No occupant has been assigned to this room.';
                        $emptyCta   = true;
                    } else {
                        $emptyIcon  = 'search';
                        $emptyTitle = 'No occupant matches your filters';
                        $emptyText  = 'Try a different name, occupant ID, room or residency.';
                        $emptyCta   = false;
                    }
                  ?>
                  <tr>
                    <td colspan="9" class="table-empty">
                      <div class="empty-state">
                        <div class="empty-state__icon"><span data-icon="<?= e($emptyIcon) ?>"></span></div>
                        <p class="empty-state__title"><?= e($emptyTitle) ?></p>
                        <p class="empty-state__text"><?= e($emptyText) ?></p>
                        <?php if ($emptyCta): ?>
                          <a class="btn--register empty-state__cta" href="register-occupant.php">
                            <span data-icon="plus"></span>Register Occupant
                          </a>
                        <?php else: ?>
                          <a class="empty-state__link" href="occupants.php">Clear filters</a>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($occupants as $o): ?>
                    <?php $isActive = $o['residency'] === 'Active'; ?>
                    <tr data-occupant-row data-id="<?= e($o['occupant_id']) ?>"
                        data-name="<?= e($o['full_name']) ?>"
                        data-room="<?= e($o['room_id']) ?>"
                        data-residency="<?= e($o['residency']) ?>">
                      <td class="cell-id"><?= e($o['occupant_id']) ?></td>
                      <td class="cell-name"><?= e($o['full_name']) ?></td>
                      <td class="cell-program"><?= e($o['program'] ?: '—') ?></td>
                      <td class="cell-contact"><?= e($o['contact_number']) ?></td>
                      <td class="cell-room"><?= e($o['room_id'] ?: '—') ?></td>
                      <td>
                        <span class="badge badge--<?= e($o['presence_key']) ?>"><?= e($o['presence_label']) ?></span>
                      </td>
                      <td>
                        <span class="badge badge--<?= $isActive ? 'active' : 'inactive' ?>"><?= e($o['residency']) ?></span>
                      </td>
                      <td class="cell-registered">
                        <strong><?= e($o['reg_date']) ?></strong>
                        <small><?= e($o['reg_time']) ?></small>
                      </td>
                      <td>
                        <div class="row-actions">
                          <a class="icon-btn"
                             href="edit-occupant.php?id=<?= urlencode($o['occupant_id']) ?>"
                             data-tooltip="Edit details"
                             aria-label="Edit <?= e($o['full_name']) ?>">
                            <span data-icon="edit"></span>
                          </a>
                          <?php if ($isActive): ?>
                            <button type="button" class="icon-btn icon-btn--danger"
                                    data-tooltip="Deactivate — frees their room, keeps log history"
                                    aria-label="Deactivate <?= e($o['full_name']) ?>"
                                    data-action="open-deactivate"
                                    data-id="<?= e($o['occupant_id']) ?>"
                                    data-name="<?= e($o['full_name']) ?>"
                                    data-room="<?= e($o['room_id']) ?>"
                                    data-room-used="<?= (int) ($roomByld[$o['room_id']]['used'] ?? 0) ?>"
                                    data-room-capacity="<?= (int) ($roomByld[$o['room_id']]['capacity'] ?? 0) ?>">
                              <span data-icon="ban"></span>
                            </button>
                          <?php else: ?>
                            <button type="button" class="icon-btn icon-btn--restore"
                                    data-tooltip="Reactivate — assign a new room"
                                    aria-label="Reactivate <?= e($o['full_name']) ?>"
                                    data-action="open-reactivate"
                                    data-id="<?= e($o['occupant_id']) ?>"
                                    data-name="<?= e($o['full_name']) ?>">
                              <span data-icon="refresh"></span>
                            </button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <footer class="list-footer">
            <span id="showingLabel"><?= e(showing_label($total, $page, OCCUPANTS_PER_PAGE)) ?></span>
            <?php $items = pagination_items($page, $lastPage); ?>
            <?php if ($items): ?>
              <nav class="pager" aria-label="Pagination">
                <?php foreach ($items as $item): ?>
                  <?php if ($item === null): ?>
                    <span class="pager__gap">…</span>
                  <?php else: ?>
                    <a class="pager__btn <?= $item === $page ? 'is-active' : '' ?>"
                       href="<?= e(qs(['page' => $item > 1 ? $item : ''])) ?>"
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

  <!-- ================= Deactivate modal ================= -->
  <div class="modal" id="deactivateModal" hidden>
    <div class="modal__backdrop" data-close-modal></div>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="deactivateTitle">
      <h2 class="modal__title" id="deactivateTitle">Deactivate occupant?</h2>
      <p class="modal__text" id="deactivateText">
        This occupant will become <strong>Inactive</strong> and leave their room.
        Their log history is kept. They can be reactivated later with a new room.
      </p>

      <label class="modal__field">
        <span>Reason <small>(optional)</small></span>
        <select id="deactivateReason">
          <option value="">— none —</option>
          <option value="End of semester">End of semester</option>
          <option value="Outside for 2 weeks">Outside for 2 weeks</option>
          <option value="Withdrew / dropped">Withdrew / dropped</option>
          <option value="Transferred dormitory">Transferred dormitory</option>
          <option value="Other">Other</option>
        </select>
      </label>

      <div class="modal__error hidden" id="deactivateError"></div>

      <div class="modal__actions">
        <button type="button" class="btn-modal btn-modal--ghost" data-close-modal>Cancel</button>
        <button type="button" class="btn-modal btn-modal--primary" id="confirmDeactivate">Deactivate</button>
      </div>
    </div>
  </div>

  <!-- ================= Reactivate modal ================= -->
  <div class="modal" id="reactivateModal" hidden>
    <div class="modal__backdrop" data-close-modal></div>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="reactivateTitle">
      <h2 class="modal__title" id="reactivateTitle">Reactivate occupant?</h2>
      <p class="modal__text" id="reactivateText">
        This occupant will become <strong>Active</strong> again. Choose the room they will be assigned to.
        Their occupant ID and log history stay the same.
      </p>

      <label class="modal__field">
        <span>Assign room</span>
        <select id="reactivateRoom">
          <?php foreach ($rooms as $room): ?>
            <option value="<?= e($room['room_id']) ?>" <?= $room['is_full'] ? 'disabled' : '' ?>>
              <?= e($room['room_id']) ?> (<?= $room['used'] ?>/<?= $room['capacity'] ?><?= $room['is_full'] ? ' — full' : '' ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <div class="modal__error hidden" id="reactivateError"></div>

      <div class="modal__actions">
        <button type="button" class="btn-modal btn-modal--ghost" data-close-modal>Cancel</button>
        <button type="button" class="btn-modal btn-modal--primary" id="confirmReactivate">Reactivate</button>
      </div>
    </div>
  </div>

  <!-- toast -->
  <div class="toast" id="toast" hidden></div>

  <script src="../assets/js/main.js"></script>
  <script src="../assets/js/tooltips.js"></script>
  <script src="../assets/js/occupants.js"></script>
</body>
</html>
