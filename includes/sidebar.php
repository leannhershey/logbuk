<?php

/* The admin sidebar. Add or rename a screen here only: every page renders this one file. */

$navLinks = [
    ['key' => 'dashboard', 'label' => 'Dashboard',      'href' => 'dashboard.php',  'icon' => 'home'],
    ['key' => 'occupants', 'label' => 'Occupants',      'href' => 'occupants.php',   'icon' => 'users'],
    ['key' => 'rooms',     'label' => 'Rooms',          'href' => 'rooms.php',       'icon' => 'door'],
    ['key' => 'logs',      'label' => 'Dormitory Logs', 'href' => 'logs.php',        'icon' => 'file'],
    ['key' => 'reports',   'label' => 'Reports',        'href' => 'reports.php',     'icon' => 'chart'],
    ['key' => 'settings',  'label' => 'Settings',       'href' => 'settings.php',    'icon' => 'settings'],
];

require_once __DIR__ . '/logs.php';
$openLogs = logs_late_badge();

$activeNav = $activeNav ?? '';
?>
<div class="sidebar__logo">
  <img src="../assets/images/logo_white.png" alt="LogBUk logo">
</div>

<nav class="sidebar__nav" aria-label="Main navigation">
  <?php foreach ($navLinks as $link): ?>
    <a class="nav-link <?= $link['key'] === $activeNav ? 'is-active' : '' ?>"
       href="<?= e($link['href']) ?>"
       data-tooltip="<?= e($link['label']) ?>"
       data-tooltip-self
       aria-label="<?= e($link['label']) ?>">
      <span data-icon="<?= e($link['icon']) ?>"></span>
      <span><?= e($link['label']) ?></span>
      <?php if ($link['key'] === 'logs'): ?>
        <span class="nav-badge <?= $openLogs > 0 ? '' : 'hidden' ?>" id="navLogsBadge"
              title="Late entries in the last 7 days" aria-label="<?= $openLogs ?> late entries in the last 7 days"><?= $openLogs ?></span>
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
