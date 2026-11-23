<?php

require_once __DIR__ . '/../includes/auth.php';
require_admin_page();          // no session -> back to index.html#admin

require_once __DIR__ . '/../includes/occupants.php';

$errors  = [];
$values  = ['first_name' => '', 'middle_name' => '', 'last_name' => '', 'suffix' => '', 'program' => '', 'contact_number' => '', 'room_id' => ''];
$success = null;
$dbError = null;

$mode = 'create';

try {
    $rooms = rooms_with_occupancy();
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
    $rooms   = [];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $values = [
        'first_name'     => trim((string) ($_POST['first_name'] ?? '')),
        'middle_name'    => trim((string) ($_POST['middle_name'] ?? '')),
        'last_name'      => trim((string) ($_POST['last_name'] ?? '')),
        'suffix'         => trim((string) ($_POST['suffix'] ?? '')),
        'program'        => trim((string) ($_POST['program'] ?? '')),
        'contact_number' => trim((string) ($_POST['contact_number'] ?? '')),
        'room_id'        => trim((string) ($_POST['room_id'] ?? '')),
    ];

    try {
        $result = occupant_create($values);
        if ($result['ok']) {
            header('Location: occupants.php?registered=' . urlencode($result['occupant_id']));
            exit;
        }
        $errors = $result['errors'] ?? [];
        if (!$errors) {
            $errors['form'] = $result['message'] ?? 'Could not save the occupant.';
        }
    } catch (Throwable $ex) {
        $dbError = $ex->getMessage();
    }
}

$displayId = '';
if ($values['room_id'] !== '') {
    try {
        $displayId = next_occupant_id($values['room_id']);
    } catch (Throwable $ex) {
        $displayId = '';
    }
}

$activeNav = 'occupants';
$pageTitle = 'Register Occupant';
$pageIcon  = 'users';
$pageSub   = 'Admin Occupants › Register Occupant';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LogBUk – Register Occupant</title>
  <link rel="icon" href="../assets/images/logo.png" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
  <div class="admin-shell">
    <aside class="sidebar" id="sidebar" data-active="<?= e($activeNav) ?>">
      <?php require __DIR__ . '/../includes/sidebar.php'; ?>
    </aside>

    <div class="admin-content">
      <header class="page-header">
        <span data-icon="<?= e($pageIcon) ?>"></span>
        <h1><?= e($pageTitle) ?></h1>
      </header>

      <main class="page-body">
        <nav class="breadcrumb" aria-label="Breadcrumb">
          <a href="occupants.php">Occupants</a>
          <span aria-hidden="true">›</span>
          <span aria-current="page">Register Occupant</span>
        </nav>

        <?php if ($dbError !== null): ?>
          <div class="db-error"><strong>Database error:</strong><br><?= e($dbError) ?></div>
        <?php else: ?>
          <?php if (isset($errors['form'])): ?>
            <div class="alert alert--error" role="alert">
              <span data-icon="warning"></span><span><?= e($errors['form']) ?></span>
            </div>
          <?php endif; ?>

          <?php require __DIR__ . '/../includes/occupant-form.php'; ?>
        <?php endif; ?>
      </main>
    </div>
  </div>

  <script src="../assets/js/app.js"></script>
  <script src="../assets/js/occupants.js"></script>
</body>
</html>