<?php

/* Dashboard: stat cards, pie chart and recent activity (data comes from api/dashboard.php). */
require_once __DIR__ . '/../includes/auth.php';
require_admin_page();

header('Cache-Control: no-store');

$activeNav = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $pageTitle = 'Dashboard'; require __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
  <div class="admin-shell">
    <aside class="sidebar" id="sidebar" data-active="<?= e($activeNav) ?>">
      <?php require __DIR__ . '/../includes/sidebar.php'; ?>
    </aside>

    <div class="admin-content">
      <header class="page-header">
        <span data-icon="home"></span>
        <h1>Dashboard</h1>
      </header>

      <main class="page-body">
        <section class="stat-grid" aria-label="Occupancy summary">
          <article class="stat-card">
            <span data-icon="users"></span>
            <span class="stat-card__label">Total Occupants</span>
            <span class="stat-card__value" id="statTotal">-</span>
          </article>
          <article class="stat-card">
            <span data-icon="home"></span>
            <span class="stat-card__label">On Premises</span>
            <span class="stat-card__value" id="statInside">-</span>
          </article>
          <article class="stat-card">
            <span data-icon="exit"></span>
            <span class="stat-card__label">Off Premises</span>
            <span class="stat-card__value" id="statOutside">-</span>
          </article>
          <article class="stat-card">
            <span data-icon="clock"></span>
            <span class="stat-card__label">Late Entries &middot; 7 days</span>
            <span class="stat-card__value" id="statLate">-</span>
          </article>
        </section>

        <div class="dash-grid">
          <section class="card panel">
            <h2>On vs Off Premises</h2>
            <div class="pie-wrap">
              <div class="pie" id="pie" role="img" aria-label="On versus off premises chart"></div>
              <div class="legend">
                <div class="legend__item"><span class="legend__dot legend__dot--on"></span>On Premises - <span id="legendOn">0</span></div>
                <div class="legend__item"><span class="legend__dot legend__dot--off"></span>Off Premises - <span id="legendOff">0</span></div>
              </div>
            </div>
          </section>

          <section class="card panel">
            <h2>Recent Activity</h2>
            <div class="table-scroll">
              <table class="activity-table">
                <thead>
                  <tr>
                    <th>Occupant Name</th>
                    <th>Room</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody id="activityBody">
                  <tr><td colspan="5" class="table-empty">Loading...</td></tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </main>
    </div>
  </div>

  <script src="../assets/js/core.js"></script>
  <script src="../assets/js/admin-shell.js"></script>
  <script src="../assets/js/dashboard.js"></script>
</body>
</html>
