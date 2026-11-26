<?php

/* Admin settings: change the passcode and manage administrator accounts. */

require_once __DIR__ . '/../includes/auth.php';
require_admin_page();

header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/logs.php';

const PASSCODE_MIN_LENGTH = 6;

function passcode_problems(string $new, string $confirm, string $newKey, string $confirmKey, string $label): array
{
    $errors = [];
    if ($new === '') {
        $errors[$newKey] = $label . ' is required.';
    } elseif (strlen($new) < PASSCODE_MIN_LENGTH) {
        $errors[$newKey] = 'Use at least ' . PASSCODE_MIN_LENGTH . ' characters.';
    }
    if (strlen($new) > 72) {
        $errors[$newKey] = 'Use at most 72 characters — bcrypt cannot store more, so it would be cut off and you would be locked out.';
    }
    if ($confirm === '') {
        $errors[$confirmKey] = 'Please confirm the passcode.';
    } elseif ($new !== '' && $new !== $confirm) {
        $errors[$confirmKey] = 'Passcodes do not match.';
    }
    return $errors;
}

function passcode_taken(string $passcode, ?int $exceptId = null): bool
{
    foreach (db()->query('SELECT admin_id, passcode FROM Admin')->fetchAll() as $row) {
        if ($exceptId !== null && (int) $row['admin_id'] === $exceptId) {
            continue;
        }
        if (passcode_matches($passcode, $row['passcode'])) {
            return true;
        }
    }
    return false;
}

function pw_field(string $id, string $name, string $label, string $placeholder, array $errors): void
{
    $err = $errors[$name] ?? '';
    ?>
    <div class="field <?= $err !== '' ? 'has-error' : '' ?>" id="field-<?= e($name) ?>">
      <label for="<?= e($id) ?>"><?= e($label) ?> <span class="req" aria-hidden="true">*</span></label>
      <input type="password" id="<?= e($id) ?>" name="<?= e($name) ?>"
             placeholder="<?= e($placeholder) ?>" autocomplete="new-password" required>
      <p class="field__error" role="alert"><span data-icon="warning"></span><span><?= e($err) ?></span></p>
    </div>
    <?php
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$tab = (string) ($_GET['tab'] ?? 'passcode');
if (!in_array($tab, ['passcode', 'admins', 'add'], true)) {
    $tab = 'passcode';
}

$errors  = [];
$dbError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            $errors['form'] = 'Your session expired. Reload the page and try again.';

        } elseif ($action === 'change_passcode') {
            $tab     = 'passcode';

            $current = normalise_passcode((string) ($_POST['current_passcode'] ?? ''));
            $new     = normalise_passcode((string) ($_POST['new_passcode'] ?? ''));
            $confirm = normalise_passcode((string) ($_POST['confirm_passcode'] ?? ''));
            $adminId = current_admin_id();

            $errors = passcode_problems($new, $confirm, 'new_passcode', 'confirm_passcode', 'New passcode');

            $stmt = db()->prepare('SELECT passcode FROM Admin WHERE admin_id = ?');
            $stmt->execute([$adminId]);
            $row = $stmt->fetch();

            if ($current === '') {
                $errors['current_passcode'] = 'Current passcode is required.';
            } elseif (!$row) {
                $errors['form'] = 'This account is not stored in the Admin table, so its passcode cannot be changed here.';
            } elseif (!passcode_matches($current, $row['passcode'])) {
                $errors['current_passcode'] = 'Current passcode is incorrect. Try again.';
            }

            if (!$errors && $new === $current) {
                $errors['new_passcode'] = 'The new passcode must be different from the current one.';
            }
            if (!$errors && passcode_taken($new, $adminId)) {
                $errors['new_passcode'] = 'That passcode cannot be used. Choose a different one.';
            }

            if (!$errors) {
                db()->prepare('UPDATE Admin SET passcode = ? WHERE admin_id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $adminId]);
                session_regenerate_id(true);
                header('Location: settings.php?tab=passcode&saved=passcode');
                exit;
            }

        } elseif ($action === 'add_admin') {
            $tab     = 'add';
            $new     = normalise_passcode((string) ($_POST['admin_passcode'] ?? ''));
            $confirm = normalise_passcode((string) ($_POST['admin_confirm'] ?? ''));

            $errors = passcode_problems($new, $confirm, 'admin_passcode', 'admin_confirm', 'Passcode');
            if (!$errors && passcode_taken($new)) {
                $errors['admin_passcode'] = 'That passcode cannot be used. Choose a different one.';
            }

            if (!$errors) {
                $pdo = db();
                $pdo->prepare('INSERT INTO Admin (passcode) VALUES (?)')
                    ->execute([password_hash($new, PASSWORD_DEFAULT)]);
                header('Location: settings.php?tab=admins&saved=admin&id=' . (int) $pdo->lastInsertId());
                exit;
            }
        }
    } catch (Throwable $ex) {
        $dbError = $ex->getMessage();
    }
}

try {
    $admins = db()->query('SELECT admin_id, created_at FROM Admin ORDER BY admin_id')->fetchAll();
} catch (Throwable $ex) {
    $dbError = $dbError ?? $ex->getMessage();
    $admins  = [];
}

$adminCount = count($admins);
$nextId     = $adminCount ? max(array_column($admins, 'admin_id')) + 1 : 1;
$newest     = $adminCount ? log_format_date(max(array_column($admins, 'created_at'))) : '-';

$flash = null;
if (($_GET['saved'] ?? '') === 'passcode') {
    $flash = 'Your passcode was updated.';
} elseif (($_GET['saved'] ?? '') === 'admin') {
    $flash = 'Administrator ' . (int) ($_GET['id'] ?? 0) . ' was added successfully.';
}

$activeNav = 'settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $pageTitle = 'Settings'; require __DIR__ . '/../includes/head.php'; ?>
</head>
<body class="settings-page">
  <div class="admin-shell">
    <aside class="sidebar" id="sidebar" data-active="<?= e($activeNav) ?>">
      <?php require __DIR__ . '/../includes/sidebar.php'; ?>
    </aside>

    <div class="admin-content">
      <header class="page-header">
        <span data-icon="settings"></span>
        <h1>Settings</h1>
      </header>

      <main class="page-body">
        <?php

          $section = $tab === 'passcode' ? 'passcode' : 'admins';
        ?>
        <nav class="settings-tabs" aria-label="Settings sections" role="tablist">
          <a class="settings-tab <?= $section === 'passcode' ? 'is-active' : '' ?>" href="settings.php?tab=passcode"
             role="tab" <?= $section === 'passcode' ? 'aria-selected="true" aria-current="page"' : 'aria-selected="false"' ?>>
            <span data-icon="key"></span> Passcode
          </a>
          <a class="settings-tab <?= $section === 'admins' ? 'is-active' : '' ?>" href="settings.php?tab=admins"
             role="tab" <?= $section === 'admins' ? 'aria-selected="true" aria-current="page"' : 'aria-selected="false"' ?>>
            <span data-icon="settings"></span> Administrators
          </a>
        </nav>

        <?php if ($flash !== null): ?>
          <div class="alert alert--success" role="status"><span data-icon="check"></span><span><?= e($flash) ?></span></div>
        <?php endif; ?>

        <?php if ($dbError !== null): $hint = db_error_hint($dbError); ?>
          <div class="db-error">
            <strong><?= e($hint['title']) ?></strong><br>
            <span class="db-error__detail"><?= e($dbError) ?></span>
          </div>
        <?php endif; ?>

        <?php if (isset($errors['form'])): ?>
          <div class="alert alert--error" role="alert">
            <span data-icon="warning"></span><span><?= e($errors['form']) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($tab === 'passcode'): ?>

        <form method="post" action="settings.php?tab=passcode" novalidate>
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="change_passcode">
          <div class="occupant-form__layout">
            <section class="card occupant-form__card">
              <h2 class="settings-card__title">Change Passcode</h2>
              <p class="settings-card__sub">Update the passcode of the administrator who is signed in.</p>
              <div class="form-grid">
                <div class="form-grid__full"><?php pw_field('currentPasscode', 'current_passcode', 'Current passcode', 'Enter current passcode', $errors); ?></div>
                <div class="form-grid__full"><?php pw_field('newPasscode', 'new_passcode', 'New passcode', 'Enter new passcode', $errors); ?></div>
                <div class="form-grid__full"><?php pw_field('confirmPasscode', 'confirm_passcode', 'Confirm new passcode', 'Re-enter new passcode', $errors); ?></div>
              </div>
              <div class="occupant-form__actions">
                <button type="submit" class="btn btn--primary">Save Changes</button>
                <a class="btn btn--outline" href="settings.php?tab=passcode">Cancel</a>
              </div>
            </section>

            <aside class="card room-panel">
              <h2 class="room-panel__title">Account Security</h2>
              <div class="settings-people"><span class="person person--filled">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="7.5" r="4"/><path d="M4 21c0-4.4 3.6-7.5 8-7.5s8 3.1 8 7.5z"/></svg>
              </span></div>
              <ul class="settings-tips">
                <li>Keep your passcode private.</li>
                <li>Choose one others cannot guess.</li>
                <li>Change it whenever you suspect it was shared.</li>
              </ul>
            </aside>
          </div>
        </form>

        <?php elseif ($tab === 'admins'): ?>

        <section class="stat-grid settings-stats" aria-label="Administrator summary">
          <article class="stat-card">
            <span data-icon="users"></span>
            <span class="stat-card__label">Total Administrators</span>
            <span class="stat-card__value"><?= (int) $adminCount ?></span>
          </article>
          <article class="stat-card">
            <span data-icon="clock"></span>
            <span class="stat-card__label">Newest Account</span>
            <span class="stat-card__value stat-card__value--small"><?= e($newest) ?></span>
          </article>
        </section>

        <section class="list-card">
          <div class="settings-list__head">
            <div>
              <h2>Administrators</h2>
              <p>Dormitory management accounts that can sign in.</p>
            </div>
            <a class="btn btn--primary settings-add" href="settings.php?tab=add">+ Add Administrator</a>
          </div>
          <div class="table-scroll">
            <table class="occupants-table">
              <thead><tr><th>Admin ID</th><th>Created At</th><th>Status</th></tr></thead>
              <tbody>
              <?php if (!$admins): ?>
                <tr><td colspan="3" class="table-empty">No administrators found.</td></tr>
              <?php else: foreach ($admins as $a): ?>
                <tr>
                  <td class="cell-id"><?= (int) $a['admin_id'] ?></td>
                  <td><?= e(log_format_date($a['created_at']) . ' ' . log_format_time($a['created_at'])) ?></td>
                  <td>
                    <?php if ((int) $a['admin_id'] === (int) (current_admin_id() ?? 1)): ?>
                      <span class="settings-tag settings-tag--you">This device</span>
                    <?php else: ?>
                      <span class="settings-muted">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <footer class="list-footer">
            <span>Showing <?= $adminCount ? '1-' . $adminCount : '0' ?> of <?= (int) $adminCount ?> administrator<?= $adminCount === 1 ? '' : 's' ?></span>
          </footer>
        </section>

        <?php else: ?>

        <form method="post" action="settings.php?tab=add" novalidate>
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="add_admin">
          <div class="occupant-form__layout">
            <section class="card occupant-form__card">
              <h2 class="settings-card__title">Add Administrator</h2>
              <p class="settings-card__sub">Create another dormitory management account.</p>
              <div class="form-grid">
                <div class="field form-grid__full">
                  <label for="newAdminId">Admin ID</label>
                  <input type="text" id="newAdminId" value="<?= (int) $nextId ?> (auto-generated)"
                         readonly aria-readonly="true" class="input--readonly">
                </div>
                <div class="form-grid__full"><?php pw_field('adminPasscode', 'admin_passcode', 'Passcode', 'Enter passcode', $errors); ?></div>
                <div class="form-grid__full"><?php pw_field('adminConfirm', 'admin_confirm', 'Confirm passcode', 'Re-enter passcode', $errors); ?></div>
              </div>
              <div class="occupant-form__actions">
                <button type="submit" class="btn btn--primary">Save Administrator</button>
                <a class="btn btn--outline" href="settings.php?tab=admins">Cancel</a>
              </div>
            </section>

            <aside class="card room-panel">
              <h2 class="room-panel__title">Administrators</h2>
            <p class="room-panel__note"><a href="settings.php?tab=admins">&larr; Back to the list</a></p>
              <p class="room-panel__room"><?= (int) $adminCount ?> registered</p>
              <div class="room-panel__people">
                <?php for ($i = 0; $i < max(3, min(4, $adminCount + 1)); $i++): ?>
                  <span class="person <?= $i < $adminCount ? 'person--filled' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="7.5" r="4"/><path d="M4 21c0-4.4 3.6-7.5 8-7.5s8 3.1 8 7.5z"/></svg>
                  </span>
                <?php endfor; ?>
              </div>
              <p class="room-panel__hint">Next Admin ID: <?= (int) $nextId ?></p>
            </aside>
          </div>
        </form>
        <?php endif; ?>
      </main>
    </div>
  </div>

  <script src="../assets/js/core.js"></script>
  <script src="../assets/js/admin-shell.js"></script>
</body>
</html>
