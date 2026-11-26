<?php

/* Daily and monthly reports with print and PDF download. */

require_once __DIR__ . '/../includes/auth.php';
require_admin_page();

header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/reports.php';

$view = (($_GET['view'] ?? 'daily') === 'monthly') ? 'monthly' : 'daily';

$date = (string) ($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
    $date = date('Y-m-d');
}
$monthStr = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthStr)) {
    $monthStr = date('Y-m');
}

if ($view === 'daily') {
    $from = $to = $date;
    $title = 'Daily Report · ' . date('M. j, Y', strtotime($date));
} else {
    $from  = $monthStr . '-01';
    $to    = date('Y-m-t', strtotime($from));
    $title = 'Monthly Report · ' . date('F Y', strtotime($from));
}

$dbError = null;
$stats   = ['time_ins' => 0, 'time_outs' => 0, 'late' => 0];
$records = [];
try {
    $stats   = report_range_stats($from, $to);
    $records = report_logs_range($from, $to, 2000);
    $records = array_reverse($records);
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
}

$generated = date('M. j, Y, g:i A');
$activeNav = 'reports';
$reportPdf = [
    'title'     => $title,
    'generated' => $generated,
    'emptyText' => 'No log records for this ' . ($view === 'daily' ? 'day' : 'month'),
    'stats'     => $stats,
    'rows'      => array_map(static fn($r) => [
        'full_name'   => (string) $r['full_name'],
        'occupant_id' => (string) $r['occupant_id'],
        'room'        => (string) ($r['room_id'] ?: '-'),
        'date'        => (string) $r['date'],
        'time_in'     => (string) $r['time_in'],
        'time_out'    => $r['time_out'] !== '' && $r['time_out'] !== null ? (string) $r['time_out'] : '-',
        'whereabouts' => $r['whereabouts'] !== '' && $r['whereabouts'] !== null ? (string) $r['whereabouts'] : '-',
        'status'      => $r['is_late'] ? 'Late' : 'On Time',
    ], $records),
];
$downloadFilename = $view === 'daily'
    ? 'logbuk-daily-report-' . $date . '.pdf'
    : 'logbuk-monthly-report-' . $monthStr . '.pdf';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $pageTitle = 'Reports'; require __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
  <div class="admin-shell">
    <aside class="sidebar" id="sidebar" data-active="<?= e($activeNav) ?>">
      <?php require __DIR__ . '/../includes/sidebar.php'; ?>
    </aside>

    <div class="admin-content">
      <header class="page-header">
        <span data-icon="chart"></span>
        <h1>Reports</h1>
      </header>

      <main class="page-body">
        <?php if ($dbError !== null): $hint = db_error_hint($dbError); ?>
          <div class="db-error">
            <strong><?= e($hint['title']) ?></strong><br>
            <span class="db-error__detail"><?= e($dbError) ?></span>
          </div>
        <?php else: ?>

        <form class="rp-toolbar" method="get" action="reports.php" id="rpForm">
          <div class="rp-toggle" role="tablist" aria-label="Report period">
            <a class="rp-toggle__btn <?= $view === 'daily' ? 'is-active' : '' ?>" href="reports.php?view=daily">Daily</a>
            <a class="rp-toggle__btn <?= $view === 'monthly' ? 'is-active' : '' ?>" href="reports.php?view=monthly">Monthly</a>
          </div>
          <input type="hidden" name="view" value="<?= e($view) ?>">

          <div class="rp-actions">
            <label class="rp-date">
              <span><?= $view === 'daily' ? 'Date' : 'Month' ?></span>
              <?php if ($view === 'daily'): ?>
                <input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()">
              <?php else: ?>
                <input type="month" name="month" value="<?= e($monthStr) ?>" onchange="this.form.submit()">
              <?php endif; ?>
            </label>
            <button type="button" class="rp-btn rp-btn--solid" id="rpDownload">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11"/><path d="M7 11l5 5 5-5"/><path d="M5 20h14"/></svg>
              Download Report
            </button>
            <button type="button" class="rp-btn rp-btn--ghost" id="rpPrint">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 9V4h10v5"/><rect x="4" y="9" width="16" height="8" rx="1.5"/><path d="M7 14h10v6H7z"/></svg>
              Print
            </button>
          </div>
        </form>

        <div class="rp-titlebar">
          <h2><?= e($title) ?></h2>
          <small>Generated <?= e($generated) ?></small>
        </div>

        <section class="rp-stats" aria-label="Report totals">
          <article class="rp-stat">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h5a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-5"/><path d="M10 8l4 4-4 4"/><path d="M14 12H3"/></svg>
            <div><span class="rp-stat__label">Time-Ins</span><strong class="rp-stat__value"><?= (int) $stats['time_ins'] ?></strong></div>
          </article>
          <article class="rp-stat">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h5"/><path d="M16 8l4 4-4 4"/><path d="M20 12H9"/></svg>
            <div><span class="rp-stat__label">Time-Outs</span><strong class="rp-stat__value"><?= (int) $stats['time_outs'] ?></strong></div>
          </article>
          <article class="rp-stat">
            <span data-icon="clock"></span>
            <div><span class="rp-stat__label">Late Entries</span><strong class="rp-stat__value"><?= (int) $stats['late'] ?></strong></div>
          </article>
        </section>

        <section class="list-card" id="rpCard">
          <div class="table-scroll">
            <table class="occupants-table rp-table">
              <thead>
                <tr>
                  <th>Occupant</th><th>Room</th><th>Date</th><th>Time In</th>
                  <th>Time Out</th><th>Whereabouts</th><th>Entry Status</th>
                </tr>
              </thead>
              <tbody id="rpBody">
              <?php if (!$records): ?>
                <tr><td colspan="7" class="table-empty">
                  <div class="empty-state">
                    <div class="empty-state__icon"><span data-icon="chart"></span></div>
                    <p class="empty-state__title">No log records for this <?= $view === 'daily' ? 'day' : 'month' ?></p>
                    <p class="empty-state__text">Pick another <?= $view === 'daily' ? 'date' : 'month' ?> to see its report.</p>
                  </div>
                </td></tr>
              <?php else: foreach ($records as $r): ?>
                <tr data-rp-row>
                  <td class="cell-occupant"><strong><?= e($r['full_name']) ?></strong><small><?= e($r['occupant_id']) ?></small></td>
                  <td><?= e($r['room_id'] ?: '-') ?></td>
                  <td class="cell-date"><?= e($r['date']) ?></td>
                  <td class="cell-time"><?= e($r['time_in']) ?></td>
                  <td class="cell-time"><?= $r['time_out'] !== '' ? e($r['time_out']) : '-' ?></td>
                  <td class="cell-where"><?= $r['whereabouts'] !== '' && $r['whereabouts'] !== null ? e($r['whereabouts']) : '-' ?></td>
                  <td><?= $r['is_late'] ? '<span class="badge badge--late">Late</span>' : '<span class="badge badge--on">On Time</span>' ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <footer class="list-footer">
            <span id="rpShowing"></span>
            <nav class="pager" id="rpPager" aria-label="Pagination"></nav>
          </footer>
        </section>

        <script>
        (function () {
          var PER = 10;
          var rows = Array.prototype.slice.call(document.querySelectorAll('[data-rp-row]'));
          var pager = document.getElementById('rpPager');
          var label = document.getElementById('rpShowing');
          var page = 1, pages = Math.max(1, Math.ceil(rows.length / PER));

          function render() {
            rows.forEach(function (tr, i) {
              tr.hidden = !(i >= (page - 1) * PER && i < page * PER);
            });
            var from = rows.length ? (page - 1) * PER + 1 : 0;
            var to = Math.min(rows.length, page * PER);
            label.textContent = rows.length
              ? 'Showing ' + from + '-' + to + ' of ' + rows.length + ' record' + (rows.length === 1 ? '' : 's')
              : 'Showing 0 of 0 records';
            pager.innerHTML = '';
            if (pages < 2) return;
            for (var n = 1; n <= pages; n++) {
              (function (n) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'pager__btn' + (n === page ? ' is-active' : '');
                b.textContent = n;
                b.onclick = function () { page = n; render(); };
                pager.appendChild(b);
              })(n);
            }
          }
          render();

          
          window.addEventListener('beforeprint', function () { rows.forEach(function (t) { t.hidden = false; }); });
          window.addEventListener('afterprint', render);
          document.getElementById('rpPrint').addEventListener('click', function () { window.print(); });

          
          var dl = document.getElementById('rpDownload');
          if (dl) dl.addEventListener('click', function () {
            var data = <?= json_encode($reportPdf, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            if (!window.LogbukDailyReport) { alert('The PDF builder did not load. Please refresh the page.'); return; }
            window.LogbukDailyReport.download(data, <?= json_encode($downloadFilename) ?>);
          });
        })();
        </script>

        <?php endif; ?>
      </main>
    </div>
  </div>

  <script src="../assets/js/core.js"></script>
  <script src="../assets/js/admin-shell.js"></script>
  <script src="../assets/js/report-pdf.js"></script>
  <script src="../assets/js/daily-report-pdf.js"></script>
</body>
</html>
