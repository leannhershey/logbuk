-- =====================================================================
-- LogBUk schema patch: 003 -> 004
-- Run AFTER sql-ver-002.sql, sql-ver-003.sql, seed-occupants.sql:
--     mysql -u root -p logbuk_db < db/sql-ver-004.sql
--
-- WHAT THIS PATCH ADDS
--   1. occupants_reg_status STAYS an ENUM('Active','Inactive') with
--      DEFAULT 'Active'. No type change -- see the note below.
--   2. "Outside for 2 weeks" rule, implemented in the database:
--        * occupants_last_seen_at          -- last log activity
--        * trg_logs_after_insert/update    -- keeps it current
--        * sp_auto_deactivate_inactive()   -- sets status to 'Inactive'
--        * Settings.settings_inactivity_days (default 14)
--   3. Bookkeeping for deactivation: when / why / by which rule.
--   4. sp_deactivate_occupant() -- manual path, same bookkeeping.
--   5. vw_occupants_directory   -- list screen read model.
--
-- NOTE ON THE STATUS COLUMN
--   An earlier draft of this patch converted occupants_reg_status to
--   TINYINT 1/0. It is deliberately NOT doing that. The column is a
--   three-state-capable ENUM ('Active','Inactive') whose DEFAULT is
--   'Active'; the automatic and manual rules are what render an occupant
--   'Inactive'. Keeping the ENUM also means:
--     * no ENUM->integer conversion (which maps Active=1, Inactive=2 and
--       can silently collapse both to 1 under strict mode)
--     * chk_occupants_status_room from patch 003 keeps working untouched
--     * the words 'Active'/'Inactive' stay readable in Workbench and in
--       every screenshot you have to submit
--
-- Semester-end deactivation is NOT in this file -- see plan-rey-002.md.
-- The columns it needs (settings_semester_end, ...) are added here so that
-- patch will not have to touch table structure again.
-- =====================================================================

USE `logbuk_db`;

-- ---------------------------------------------------------------
-- 1. Settings: thresholds used by the automatic rules
-- ---------------------------------------------------------------
ALTER TABLE `Settings`
  ADD COLUMN `settings_inactivity_days`  TINYINT NOT NULL DEFAULT 14
      COMMENT 'Auto-deactivate after this many days with no log activity',
  ADD COLUMN `settings_semester_end`     DATE NULL
      COMMENT 'Semester end date; used by the end-of-semester rule',
  ADD COLUMN `settings_auto_deactivate`  TINYINT NOT NULL DEFAULT 1
      COMMENT 'Master switch for automatic deactivation',
  ADD COLUMN `settings_last_auto_run`    DATETIME NULL
      COMMENT 'Last time sp_auto_deactivate_inactive() ran';

-- ---------------------------------------------------------------
-- 2. Occupants: bookkeeping for deactivation (status column untouched)
-- ---------------------------------------------------------------
ALTER TABLE `Occupants`
  ADD COLUMN `occupants_last_seen_at`      DATETIME NULL
      COMMENT 'Last log activity; drives the 2-week rule',
  ADD COLUMN `occupants_deactivated_at`    DATETIME NULL,
  ADD COLUMN `occupants_deactivate_reason` VARCHAR(120) NULL,
  ADD COLUMN `occupants_deactivate_source` ENUM('manual','inactivity','semester_end') NULL;

-- Backfill last activity from the log history, else the registration date.
UPDATE `Occupants` o
SET o.`occupants_last_seen_at` = COALESCE(
      (SELECT MAX(GREATEST(COALESCE(l.`logs_time_out`, l.`logs_time_in`), l.`logs_time_in`))
         FROM `Logs` l
        WHERE l.`Occupants_occupants_id` = o.`occupants_id`),
      o.`occupants_reg_at`
    );

-- Occupants already Inactive were deactivated before this bookkeeping existed.
UPDATE `Occupants`
SET `occupants_deactivated_at`    = NOW(),
    `occupants_deactivate_reason` = 'Deactivated before automatic rules existed',
    `occupants_deactivate_source` = 'manual'
WHERE `occupants_reg_status` = 'Inactive'
  AND `occupants_deactivated_at` IS NULL;

-- ---------------------------------------------------------------
-- 3. Index for the inactivity scan (status + last seen)
-- ---------------------------------------------------------------
ALTER TABLE `Occupants`
  ADD INDEX `idx_occupants_status_seen` (`occupants_reg_status`, `occupants_last_seen_at`);

-- ---------------------------------------------------------------
-- 4. Triggers: keep occupants_last_seen_at honest
-- ---------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_logs_after_insert`;
DROP TRIGGER IF EXISTS `trg_logs_after_update`;

DELIMITER $$

CREATE TRIGGER `trg_logs_after_insert`
AFTER INSERT ON `Logs`
FOR EACH ROW
BEGIN
    UPDATE `Occupants`
       SET `occupants_last_seen_at` = GREATEST(
             COALESCE(`occupants_last_seen_at`, '1970-01-01 00:00:00'),
             COALESCE(NEW.`logs_time_out`, NEW.`logs_time_in`)
           )
     WHERE `occupants_id` = NEW.`Occupants_occupants_id`;
END$$

CREATE TRIGGER `trg_logs_after_update`
AFTER UPDATE ON `Logs`
FOR EACH ROW
BEGIN
    UPDATE `Occupants`
       SET `occupants_last_seen_at` = GREATEST(
             COALESCE(`occupants_last_seen_at`, '1970-01-01 00:00:00'),
             COALESCE(NEW.`logs_time_out`, NEW.`logs_time_in`)
           )
     WHERE `occupants_id` = NEW.`Occupants_occupants_id`;
END$$

DELIMITER ;

-- ---------------------------------------------------------------
-- 5. Stored procedure: the 2-week rule
--
--    Deactivates every occupant who is
--      * 'Active'
--      * not currently inside (no open log)
--      * last seen >= p_days ago
--    The deactivation is expressed exactly like the manual one:
--    status -> 'Inactive', room released, log history untouched.
--
--    p_days NULL -> Settings.settings_inactivity_days (default 14)
--    Returns one row per occupant deactivated by this run.
-- ---------------------------------------------------------------
DROP PROCEDURE IF EXISTS `sp_auto_deactivate_inactive`;

DELIMITER $$

CREATE PROCEDURE `sp_auto_deactivate_inactive`(IN p_days INT)
BEGIN
    DECLARE v_days   INT DEFAULT 14;
    DECLARE v_cutoff DATETIME;

    IF p_days IS NOT NULL AND p_days > 0 THEN
        SET v_days = p_days;
    ELSE
        SELECT COALESCE(`settings_inactivity_days`, 14) INTO v_days
          FROM `Settings` ORDER BY `settings_id` LIMIT 1;
        IF v_days IS NULL THEN SET v_days = 14; END IF;
    END IF;

    SET v_cutoff = DATE_SUB(NOW(), INTERVAL v_days DAY);

    -- 1. close any log that is still open for an occupant about to be deactivated
    --    (belt and braces: the WHERE below already excludes them)
    UPDATE `Logs` l
      JOIN `Occupants` o ON o.`occupants_id` = l.`Occupants_occupants_id`
       SET l.`logs_time_out` = NOW()
     WHERE l.`logs_time_out` IS NULL
       AND o.`occupants_reg_status` = 'Active'
       AND COALESCE(o.`occupants_last_seen_at`, o.`occupants_reg_at`) < v_cutoff;

    -- 2. deactivate
    UPDATE `Occupants` o
       SET o.`occupants_reg_status`        = 'Inactive',
           o.`Rooms_room_id`               = NULL,
           o.`occupants_loc_status`        = 'Outside',
           o.`occupants_deactivated_at`    = NOW(),
           o.`occupants_deactivate_reason` = CONCAT('No check-in for ', v_days, ' days'),
           o.`occupants_deactivate_source` = 'inactivity'
     WHERE o.`occupants_reg_status` = 'Active'
       AND o.`occupants_loc_status` = 'Outside'
       AND COALESCE(o.`occupants_last_seen_at`, o.`occupants_reg_at`) < v_cutoff
       AND NOT EXISTS (
             SELECT 1 FROM `Logs` l2
              WHERE l2.`Occupants_occupants_id` = o.`occupants_id`
                AND l2.`logs_time_out` IS NULL
           );

    -- 3. audit trail
    INSERT INTO `OccupancyAudit`
        (`Occupants_occupants_id`, `audit_action`, `audit_old_room`, `audit_new_room`, `audit_reason`, `Admins_admins_id`)
    SELECT o.`occupants_id`, 'Deactivated', NULL, NULL,
           CONCAT('Automatic: no check-in for ', v_days, ' days'), NULL
      FROM `Occupants` o
     WHERE o.`occupants_reg_status` = 'Inactive'
       AND o.`occupants_deactivate_source` = 'inactivity'
       AND o.`occupants_deactivated_at` >= DATE_SUB(NOW(), INTERVAL 1 MINUTE);

    -- 4. stamp the run
    UPDATE `Settings` SET `settings_last_auto_run` = NOW()
     WHERE `settings_id` = (SELECT MIN(`settings_id`) FROM (SELECT `settings_id` FROM `Settings`) s);

    -- 5. report
    SELECT `occupants_id`,
           CONCAT_WS(' ', `occupants_f_name`, `occupants_l_name`) AS `full_name`,
           `occupants_last_seen_at`,
           `occupants_deactivated_at`,
           `occupants_deactivate_reason`
      FROM `Occupants`
     WHERE `occupants_reg_status` = 'Inactive'
       AND `occupants_deactivate_source` = 'inactivity'
       AND `occupants_deactivated_at` >= DATE_SUB(NOW(), INTERVAL 1 MINUTE);
END$$

DELIMITER ;

-- ---------------------------------------------------------------
-- 6. Stored procedure: manual deactivation (same bookkeeping)
-- ---------------------------------------------------------------
DROP PROCEDURE IF EXISTS `sp_deactivate_occupant`;

DELIMITER $$

CREATE PROCEDURE `sp_deactivate_occupant`(
    IN p_occupant_id CHAR(7),
    IN p_reason      VARCHAR(120),
    IN p_admin_id    INT
)
BEGIN
    DECLARE v_room CHAR(4);

    SELECT `Rooms_room_id` INTO v_room FROM `Occupants` WHERE `occupants_id` = p_occupant_id;

    -- close an open log so an "Inside" occupant cannot be left dangling
    UPDATE `Logs`
       SET `logs_time_out` = NOW()
     WHERE `Occupants_occupants_id` = p_occupant_id AND `logs_time_out` IS NULL;

    UPDATE `Occupants`
       SET `occupants_reg_status`        = 'Inactive',
           `Rooms_room_id`               = NULL,
           `occupants_loc_status`        = 'Outside',
           `occupants_deactivated_at`    = NOW(),
           `occupants_deactivate_reason` = COALESCE(p_reason, 'Manual deactivation'),
           `occupants_deactivate_source` = 'manual'
     WHERE `occupants_id` = p_occupant_id;

    INSERT INTO `OccupancyAudit`
        (`Occupants_occupants_id`, `audit_action`, `audit_old_room`, `audit_new_room`, `audit_reason`, `Admins_admins_id`)
    VALUES (p_occupant_id, 'Deactivated', v_room, NULL, COALESCE(p_reason, 'Manual deactivation'), p_admin_id);
END$$

DELIMITER ;

-- ---------------------------------------------------------------
-- 7. View: read model for the Occupants list
-- ---------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_occupants_directory` AS
SELECT o.`occupants_id`,
       o.`occupants_f_name`,
       o.`occupants_m_name`,
       o.`occupants_l_name`,
       o.`occupants_s_name`,
       o.`occupants_program`,
       o.`occupants_contact_number`,
       o.`occupants_loc_status`,
       o.`occupants_reg_status`                              AS `residency`,
       o.`occupants_reg_at`,
       o.`occupants_last_seen_at`,
       o.`occupants_deactivated_at`,
       o.`occupants_deactivate_reason`,
       o.`occupants_deactivate_source`,
       o.`Rooms_room_id`,
       r.`room_capacity`,
       (SELECT COUNT(*) FROM `Occupants` x
         WHERE x.`Rooms_room_id` = o.`Rooms_room_id`
           AND x.`occupants_reg_status` = 'Active')          AS `room_used`
  FROM `Occupants` o
  LEFT JOIN `Rooms` r ON r.`room_id` = o.`Rooms_room_id`;

-- ---------------------------------------------------------------
-- 8. Check
-- ---------------------------------------------------------------
SELECT `occupants_id`,
       `occupants_reg_status` AS `residency`,
       IFNULL(`Rooms_room_id`, '—') AS `room`,
       IFNULL(`occupants_last_seen_at`, '—') AS `last_seen`,
       IFNULL(`occupants_deactivate_source`, '—') AS `source`
  FROM `Occupants`
 ORDER BY `occupants_id`;
