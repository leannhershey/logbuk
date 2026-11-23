<?php
/**
 * Server-rendered admin sidebar.
 *
 * Same markup and classes that dashboard.js builds for dashboard.html, but
 * produced by PHP so pages that are server-rendered do not depend on JS
 * (and are not affected by the mock-mode guard in dashboard.js).
 *
 * Expects: $activeNav ('dashboard' | 'occupants' | 'rooms' | 'logs' | 'reports')
 * and includes/occupants.php to be loaded for the logs badge count.
 */

/* Every screen lives in /pages, so these are plain siblings.
   Modules that are not built yet point at 404-page.html with a ?from= tag
   instead of a dead href, so the sidebar never produces a broken link. */
$navLinks = [
    ['key' => 'dashboard', 'label' => 'Dashboard',      'href' => 'dashboard.html',                    'icon' => 'home'],
    ['key' => 'occupants', 'label' => 'Occupants',      'href' => 'occupants.php',                     'icon' => 'users'],
    ['key' => 'rooms',     'label' => 'Rooms',          'href' => '404-page.html?from=rooms',          'icon' => 'door'],
    ['key' => 'logs',      'label' => 'Dormitory Logs', 'href' => '404-page.html?from=logs',           'icon' => 'file'],
    ['key' => 'reports',   'label' => 'Reports',        'href' => '404-page.html?from=reports',        'icon' => 'chart'],
];

/* Badge on "Dormitory Logs": occupants with an open log (still timed in).
   Falls back to 0 when the database is unreachable. */
$openLogs = 0;
try {
    $openLogs = (int) db()->query('SELECT COUNT(*) FROM Logs WHERE logs_time_out IS NULL')->fetchColumn();
} catch (Throwable $ex) {
    $openLogs = 0;
}

$activeNav = $activeNav ?? '';
?>
<div class="sidebar__logo">
  <img src="../assets/images/logo_white.png" alt="LogBUk logo">
</div>

<nav class="sidebar__nav" aria-label="Main navigation">
  <?php foreach ($navLinks as $link): ?>
    <a class="nav-link <?= $link['key'] === $activeNav ? 'is-active' : '' ?>"
       href="<?= e($link['href']) ?>">
      <span data-icon="<?= e($link['icon']) ?>"></span>
      <span><?= e($link['label']) ?></span>
      <?php if ($link['key'] === 'logs'): ?>
        <span class="nav-badge <?= $openLogs > 0 ? '' : 'hidden' ?>" id="navLogsBadge"><?= $openLogs ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

<div class="sidebar__user">
  <div class="sidebar__avatar"><span data-icon="admin"></span></div>
  <div class="sidebar__user-info"><strong>Dormitory</strong><small>Administrator</small></div>
  <button class="sidebar__logout" id="logoutBtn" type="button"
          data-tooltip="Log out" aria-label="Log out">
    <span data-icon="logout"></span>
  </button>
</div>