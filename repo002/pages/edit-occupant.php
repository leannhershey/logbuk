<?php
/**
 * Admin -> Occupants -> Edit Occupant
 * The occupant ID never changes; a room move keeps the log history (the room
 * stored on each log row is a historical snapshot).
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin_page();          // no session -> back to index.html#admin

require_once __DIR__ . '/../includes/occupants.php';

$mode    = 'edit';
$errors  = [];
$dbError = null;
$occupant = null;

$occupantId = trim((string) ($_GET['id'] ?? $_POST['occupant_id'] ?? ''));

try {
    $rooms = rooms_with_occupancy();
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
    $rooms   = [];
}

if ($occupantId === '') {
    $dbError = 'No occupant ID given. Open this page from the Occupants list.';
} elseif ($dbError === null) {
    $occupant = occupant_find($occupantId);
    if (!$occupant) {
        $dbError = 'Occupant ' . $occupantId . ' was not found.';
    }
}

if ($occupant) {
    // default values: the stored record
    $values = [
        'first_name'     => $occupant['first_name'],
        'middle_name'    => $occupant['middle_name'] ?? '',
        'last_name'      => $occupant['last_name'],
        'suffix'         => $occupant['suffix'] ?? '',
        'program'        => $occupant['program'] ?? '',
        'contact_number' => $occupant['contact_number'],
        'room_id'        => $occupant['room_id'] ?? '',
    ];

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
            $result = occupant_update($occupantId, $values);
            if ($result['ok']) {
                header('Location: occupants.php?updated=' . urlencode($occupantId));
                exit;
            }
            $errors = $result['errors'] ?? [];
            if (!$errors) {
                $errors['form'] = $result['message'] ?? 'Could not save the changes.';
            }
        } catch (Throwable $ex) {
            $dbError = $ex->getMessage();
        }
    }

    $displayId = $occupantId;
}

$activeNav = 'occupants';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LogBUk – Edit Occupant</title>
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
        <h1>Edit Occupant</h1>
      </header>

      <main class="page-body">
        <nav class="breadcrumb" aria-label="Breadcrumb">
          <a href="occupants.php">Occupants</a>
          <span aria-hidden="true">›</span>
          <span aria-current="page">Edit <?= e($occupantId ?: 'occupant') ?></span>
        </nav>

        <?php if ($dbError !== null): ?>
          <div class="db-error"><strong>Cannot open this occupant:</strong><br><?= e($dbError) ?></div>
        <?php else: ?>
          <?php if (isset($errors['form'])): ?>
            <div class="alert alert--error" role="alert">
              <span data-icon="warning"></span><span><?= e($errors['form']) ?></span>
            </div>
          <?php endif; ?>

          <?php if ($occupant['residency'] === 'Inactive'): ?>
            <div class="alert alert--error" role="alert">
              <span data-icon="warning"></span>
              <span>
                This occupant is <strong>Inactive</strong> and has no room.
                <?php if (!empty($occupant['deactivate_reason'])): ?>
                  Reason: <strong><?= e($occupant['deactivate_reason']) ?></strong>
                  (<?= e($occupant['deactivate_source'] ?: 'manual') ?>).
                <?php endif; ?>
                Saving this form will reactivate them in the room you choose.
              </span>
            </div>
          <?php endif; ?>

          <?php require __DIR__ . '/../includes/occupant-form.php'; ?>
        <?php endif; ?>
      </main>
    </div>
  </div>

  <script src="../assets/js/main.js"></script>
  <script src="../assets/js/tooltips.js"></script>
  <script src="../assets/js/occupants.js"></script>
  <script src="../assets/js/occupant-form.js"></script>
</body>
</html>
