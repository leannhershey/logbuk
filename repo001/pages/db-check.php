<?php
/**
 * Connection doctor.  Open:  http://localhost/repo001/pages/db-check.php
 *
 * Run this when a screen says "Database not reachable". It reads the same
 * config/config.php the app uses, tries the same connection, and reports the
 * first thing that is wrong instead of a raw PDO message.
 *
 * It never prints the password -- only whether one is set.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

$checks = [];   // ['label' => , 'ok' => bool|null, 'detail' => , 'fix' => ]

function add(array &$checks, string $label, ?bool $ok, string $detail = '', string $fix = ''): void
{
    $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
}

/* ---------- PHP side ---------- */
add($checks, 'PHP version', version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION,
    'LogBUk needs PHP 8.0 or newer (XAMPP 8.x).');

$drivers = PDO::getAvailableDrivers();
add($checks, 'PDO MySQL driver loaded', in_array('mysql', $drivers, true),
    $drivers ? implode(', ', $drivers) : 'none',
    'Enable extension=pdo_mysql in php.ini, then restart Apache.');

/* ---------- config ---------- */
add($checks, 'Config file read', true,
    'DB_HOST=' . DB_HOST . '  DB_NAME=' . DB_NAME . '  DB_USER=' . DB_USER
    . '  DB_PASS=' . (DB_PASS === '' ? '(empty)' : '(set)'),
    '');

/* ---------- TCP port ---------- */
$host = DB_HOST === 'localhost' ? '127.0.0.1' : DB_HOST;
$port = 3306;
$fp   = @fsockopen($host, $port, $errno, $errstr, 2.0);
add($checks, 'MySQL listening on ' . $host . ':' . $port, (bool) $fp,
    $fp ? 'port open' : ($errstr !== '' ? $errstr : 'no answer'),
    'Start MySQL in the XAMPP Control Panel. If it will not start, another MySQL is already using port 3306.');
if ($fp) { fclose($fp); }

/* ---------- socket (XAMPP/PHP often prefers this on localhost) ---------- */
$socketPaths = ['/opt/lampp/var/mysql/mysql.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/mysql/mysql.sock'];
$foundSocket = '';
foreach ($socketPaths as $sp) {
    if (@file_exists($sp)) { $foundSocket = $sp; break; }
}
if (defined('DB_SOCKET') && DB_SOCKET !== '') {
    $foundSocket = DB_SOCKET;
}
add($checks, 'Unix socket found', $foundSocket !== '' ? true : null,
    $foundSocket !== '' ? $foundSocket : 'not found in the usual places (normal on Windows)',
    'Only matters on macOS/Linux. On Windows this row can stay grey.');

/* ---------- the real connection ---------- */
$pdo  = null;
$err  = '';
$dsns = [];
if (defined('DB_SOCKET') && DB_SOCKET !== '' && @file_exists(DB_SOCKET)) {
    $dsns['socket'] = 'mysql:unix_socket=' . DB_SOCKET . ';charset=utf8mb4';
}
if ($foundSocket !== '' && !isset($dsns['socket'])) {
    $dsns['socket'] = 'mysql:unix_socket=' . $foundSocket . ';charset=utf8mb4';
}
$dsns['tcp'] = 'mysql:host=' . (DB_HOST === 'localhost' ? '127.0.0.1' : DB_HOST) . ';port=3306;charset=utf8mb4';

$usedDsn = '';
foreach ($dsns as $kind => $dsn) {
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $usedDsn = $kind;
        $err     = '';
        break;
    } catch (Throwable $ex) {
        $err = $ex->getMessage();
        $pdo = null;
    }
}

$authOk = $pdo !== null;
$fix = '';
if (!$authOk) {
    $low = strtolower($err);
    if (str_contains($low, 'access denied')) {
        $fix = DB_PASS === ''
            ? 'MySQL rejected ' . DB_USER . ' with an empty password. Your MySQL root has a password: set DB_PASS in config/config.php.'
            : 'MySQL rejected ' . DB_USER . ' with the password in config/config.php. If your root has no password, set DB_PASS to \'\'.';
    } elseif (str_contains($low, 'connection refused') || str_contains($low, '2002')) {
        $fix = 'Nothing is answering on the MySQL port. Start MySQL in the XAMPP Control Panel.';
    } else {
        $fix = 'See the message below.';
    }
}
add($checks, 'MySQL login (the same credentials the app uses)', $authOk,
    $authOk ? 'logged in via ' . $usedDsn : $err, $fix);

/* ---------- schema ---------- */
$tables = [];
if ($pdo) {
    try {
        $pdo->exec('USE `' . DB_NAME . '`');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        add($checks, 'Database "' . DB_NAME . '" exists', true, count($tables) . ' tables', '');
    } catch (Throwable $ex) {
        add($checks, 'Database "' . DB_NAME . '" exists', false, $ex->getMessage(),
            'Import db/logbuk.sql in phpMyAdmin (Import tab -> choose file -> Go).');
    }

    $need = ['Rooms', 'Admins', 'Occupants', 'Logs', 'Settings', 'OccupancyAudit'];
    $missing = array_values(array_diff($need, $tables));
    add($checks, 'All 6 tables present', $missing === [],
        $missing === [] ? implode(', ', $need) : 'missing: ' . implode(', ', $missing),
        'Import db/logbuk.sql. Do not mix it with the 002 -> 003 -> seed -> 004 chain.');

    if ($missing === []) {
        $col = $pdo->query("SHOW COLUMNS FROM `Occupants` LIKE 'occupants_reg_status'")->fetch(PDO::FETCH_ASSOC);
        $isEnum = $col && stripos((string) $col['Type'], 'enum') === 0;
        add($checks, 'occupants_reg_status is an ENUM', (bool) $isEnum,
            $col ? $col['Type'] . '  default=' . var_export($col['Default'], true) : 'column not found',
            'Re-import db/logbuk.sql. The column must stay ENUM(\'Active\',\'Inactive\').');

        $rows = (int) $pdo->query('SELECT COUNT(*) FROM `Occupants`')->fetchColumn();
        add($checks, 'Occupants table has rows', $rows > 0, $rows . ' rows',
            'The list will show its empty state until the seed rows are loaded (db/logbuk.sql includes them).');

        $routines = $pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
        add($checks, 'Stored procedures installed', count($routines) >= 2,
            $routines ? implode(', ', $routines) : 'none',
            'Import db/logbuk.sql.');

        $triggers = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
        add($checks, 'Triggers installed', count($triggers) >= 2,
            $triggers ? implode(', ', $triggers) : 'none',
            'Import db/logbuk.sql.');

        $admins = (int) $pdo->query('SELECT COUNT(*) FROM `Admins`')->fetchColumn();
        add($checks, 'An admin passcode is stored', $admins > 0, $admins . ' row(s)',
            'Insert the Admins row from db/logbuk.sql, or rely on ADMIN_PASSCODE_HASH in config/config.php (admin123).');
    }
}

$failures = array_filter($checks, fn($c) => $c['ok'] === false);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LogBUk – Database check</title>
<link rel="icon" href="../assets/images/logo.png" type="image/png">
<style>
  body { font-family: "Poppins", "Segoe UI", system-ui, sans-serif; background: #f3f3f3; margin: 0; padding: 32px 18px; color: #111; }
  .wrap { max-width: 780px; margin: 0 auto; }
  h1 { font-size: 1.25rem; margin: 0 0 4px; }
  .sub { font-size: .8rem; color: #6b7280; margin: 0 0 20px; }
  .verdict { border-radius: 12px; padding: 14px 18px; font-size: .86rem; font-weight: 700; margin-bottom: 18px; }
  .verdict--bad { background: #ffe4e4; color: #991b1b; }
  .verdict--good { background: #dcfce7; color: #166534; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 3px 10px rgba(0,0,0,.08); }
  th, td { text-align: left; padding: 10px 14px; font-size: .78rem; border-bottom: 1px solid #eef0f4; vertical-align: top; }
  th { background: #ffe3a9; font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; }
  td.mono { font-family: ui-monospace, Menlo, monospace; font-size: .72rem; color: #374151; word-break: break-word; }
  .badge { display: inline-block; min-width: 46px; text-align: center; border-radius: 999px; padding: 2px 8px; font-size: .66rem; font-weight: 700; }
  .ok { background: #dcfce7; color: #166534; }
  .no { background: #ffe4e4; color: #991b1b; }
  .na { background: #eef0f4; color: #6b7280; }
  .fix { display: block; margin-top: 4px; color: #b45309; font-size: .72rem; font-weight: 600; }
  .back { display: inline-block; margin-top: 20px; font-size: .8rem; font-weight: 700; color: #0b3c9c; text-decoration: none; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Database check</h1>
  <p class="sub">Same config file, same credentials, same connection the app uses.</p>

  <?php if (!$failures): ?>
    <div class="verdict verdict--good">Everything checks out — the app can reach its database.</div>
  <?php else: ?>
    <div class="verdict verdict--bad">
      <?= count($failures) ?> problem<?= count($failures) === 1 ? '' : 's' ?> found — fix the red rows below, top to bottom.
    </div>
  <?php endif; ?>

  <table>
    <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): ?>
      <tr>
        <td><?= htmlspecialchars($c['label'], ENT_QUOTES) ?></td>
        <td>
          <span class="badge <?= $c['ok'] === true ? 'ok' : ($c['ok'] === false ? 'no' : 'na') ?>">
            <?= $c['ok'] === true ? 'OK' : ($c['ok'] === false ? 'FAIL' : 'skip') ?>
          </span>
        </td>
        <td class="mono">
          <?= htmlspecialchars($c['detail'], ENT_QUOTES) ?>
          <?php if ($c['ok'] === false && $c['fix'] !== ''): ?>
            <span class="fix">→ <?= htmlspecialchars($c['fix'], ENT_QUOTES) ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <a class="back" href="../index.html">← Back to LogBUk</a>
</div>
</body>
</html>