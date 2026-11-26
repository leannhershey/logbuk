<?php

/* JSON twin of pages/logs.php, used for live filtering. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logs.php';

require_admin();

header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    json_error('Method not allowed. Use GET.', 405);
}

try {
    if (isset($_GET['id'])) {
        $row = log_find((int) $_GET['id']);
        if (!$row) {
            json_error('Log record not found.', 404);
        }
        json_response(['ok' => true, 'record' => $row]);
    }

    $view    = logs_normalise_view($_GET['view'] ?? 'all');
    $filters = logs_normalise_filters($_GET);
    $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? LOGS_PER_PAGE)));

    $counts   = logs_counts($filters);
    $total    = $counts[$view];
    $lastPage = max(1, (int) ceil($total / $perPage));
    $page     = min(max(1, (int) ($_GET['page'] ?? 1)), $lastPage);

    json_response([
        'ok'       => true,
        'view'     => $view,
        'filters'  => $filters,
        'counts'   => $counts,
        'total'    => $total,
        'page'     => $page,
        'pages'    => $lastPage,
        'per_page' => $perPage,
        'rows'     => logs_page($view, $filters, $page, $perPage),
    ]);
} catch (Throwable $ex) {
    error_log('logs api: ' . $ex->getMessage());
    json_error('Server error: ' . $ex->getMessage(), 500);
}
