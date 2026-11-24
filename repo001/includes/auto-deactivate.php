<?php
/**
 * Runs the "outside for 2 weeks" rule.
 *
 * Called from two places:
 *   - api/auto_deactivate.php   (ad-hoc / cron / curl)
 *   - occupants.php and dashboard.html on load, at most once per day
 *     (guarded by Settings.settings_last_auto_run, so it works even on a
 *      XAMPP laptop where there is no cron)
 *
 * The rule itself lives in MySQL (sp_auto_deactivate_inactive), not in PHP,
 * so it can also be run by hand from Workbench and still behaves identically.
 */

require_once __DIR__ . '/db.php';

/**
 * @return array{ran: bool, skipped_reason?: string, deactivated: array}
 */
function auto_deactivate_if_due(int $everyHours = 24): array
{
    $pdo = db();

    // Master switch + last run
    $settings = $pdo->query("
        SELECT settings_auto_deactivate, settings_inactivity_days, settings_last_auto_run
        FROM Settings ORDER BY settings_id LIMIT 1
    ")->fetch();

    if (!$settings) {
        return ['ran' => false, 'skipped_reason' => 'no settings row', 'deactivated' => []];
    }
    if ((int) $settings['settings_auto_deactivate'] !== 1) {
        return ['ran' => false, 'skipped_reason' => 'disabled in settings', 'deactivated' => []];
    }

    $lastRun = $settings['settings_last_auto_run'];
    if ($lastRun !== null && strtotime($lastRun) > strtotime("-{$everyHours} hours")) {
        return ['ran' => false, 'skipped_reason' => 'already ran recently', 'deactivated' => []];
    }

    // The procedure reads the threshold from Settings when passed NULL.
    $stmt = $pdo->prepare('CALL sp_auto_deactivate_inactive(?)');
    $stmt->execute([null]);
    $rows = $stmt->fetchAll();
    // free the connection for the next query (a CALL can leave a result set open)
    $stmt->closeCursor();

    return [
        'ran'         => true,
        'threshold'   => (int) $settings['settings_inactivity_days'],
        'deactivated' => $rows,
    ];
}

/** Human summary for a log line / API response. */
function auto_deactivate_summary(array $result): string
{
    if (!$result['ran']) {
        return 'Skipped: ' . ($result['skipped_reason'] ?? 'not due');
    }
    $n = count($result['deactivated']);
    return $n === 0
        ? 'Ran: no occupant had been inactive for ' . ($result['threshold'] ?? 14) . ' days.'
        : 'Ran: deactivated ' . $n . ' occupant(s) inactive for ' . ($result['threshold'] ?? 14) . ' days.';
}
