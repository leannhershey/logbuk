-- =====================================================================
--  LOGBUK  —  BICOL UNIVERSITY DORMITORY DIGITALIZED LOG BOOK SYSTEM
--  DATABASE DEFINITION SCRIPT
--
--  File    : logbuk.sql
--  Engine  : InnoDB      Charset: utf8
--  Target  : MySQL 8.0+ / MariaDB 10.5+
--
--  SELF-CONTAINED. Nothing needs to be run before it: the script drops and
--  creates the schema, the tables, the foreign keys, the CHECK constraints,
--  the indexes, the view, the two triggers, the two stored procedures and
--  the demo rows.
--
--      mysql -u root -p < db/logbuk.sql
--
--  CONTENTS
--    1  schema
--    2  tables             Rooms, Admins, Occupants, Logs, Settings,
--                          OccupancyAudit
--    3  indexes
--    4  seed rows          demo set, admin passcode: admin123
--    5  view               vw_occupants_directory
--    6  triggers           trg_logs_after_insert / _after_update
--    7  procedures         sp_auto_deactivate_inactive
--                          sp_deactivate_occupant
--    8  verification       sanity queries with output
--
--  DESIGN NOTES
--    * Occupants.occupants_reg_status is ENUM('Active','Inactive') with
--      DEFAULT 'Active'. Becoming 'Inactive' is what the manual deactivation
--      module and the 2-week inactivity rule do; nothing else writes it.
--    * An Active occupant must have a room, an Inactive one must not.
--      Enforced by chk_occupants_status_room.
--    * Logs keeps its own copy of the room id. That is a HISTORICAL snapshot
--      of where the occupant lived at the time of the log, not a live link,
--      so deactivating someone never destroys their history.
--    * A room holds at most Rooms.room_capacity (4) active occupants. A CHECK
--      cannot count rows, so this is enforced by the registration code inside
--      a transaction that locks the room row (SELECT ... FOR UPDATE).
-- =====================================================================

DROP SCHEMA IF EXISTS `logbuk_db`;
CREATE SCHEMA `logbuk_db` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
USE `logbuk_db`;


-- =====================================================================
--  2. TABLES
-- =====================================================================

-- ---------------------------------------------------------------------
--  Rooms
--  room_id is <floor><number>: '0101' = floor 01, room 01.
--  The occupant ID is derived from the floor of the assigned room.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `Rooms`;
CREATE TABLE `Rooms` (
  `room_id`        CHAR(4)  NOT NULL,
  `room_capacity`  TINYINT  NOT NULL DEFAULT 4,
  PRIMARY KEY (`room_id`),
  UNIQUE KEY `RoomID_UNIQUE` (`room_id`),
  CONSTRAINT `chk_room_id_format`
    CHECK (`room_id` REGEXP '^[0-9]{4}$' AND SUBSTRING(`room_id`, 3, 2) BETWEEN '01' AND '26'),
  CONSTRAINT `chk_room_capacity`
    CHECK (`room_capacity` BETWEEN 1 AND 4)
) ENGINE = InnoDB;

-- ---------------------------------------------------------------------
--  Admins
--  Passcoded access to the management side. Passcodes are stored as
--  bcrypt hashes (password_hash / password_verify), never in plain text.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `Admins`;
CREATE TABLE `Admins` (
  `admins_id`          INT           NOT NULL AUTO_INCREMENT,
  `admins_passcode`    VARCHAR(255)  NOT NULL,
  `admins_created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`admins_id`),
  UNIQUE KEY `admins_id_UNIQUE` (`admins_id`)
) ENGINE = InnoDB;

-- ---------------------------------------------------------------------
--  Occupants
--  occupants_id      CHAR(7)  '01-0001'  = <floor>-<sequence>
--  occupants_loc_status  presence in the dorm right now
--  occupants_reg_status  residency: Active (has a room) / Inactive (none)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `Occupants`;
CREATE TABLE `Occupants` (
  `occupants_id`                  CHAR(7)       NOT NULL,
  `occupants_f_name`              VARCHAR(100)  NOT NULL,
  `occupants_m_name`              VARCHAR(50)   NULL,
  `occupants_l_name`              VARCHAR(50)   NOT NULL,
  `occupants_s_name`              VARCHAR(10)   NULL,
  `occupants_program`             VARCHAR(100)  NULL,
  `occupants_contact_number`      VARCHAR(11)   NOT NULL,
  `occupants_loc_status`          ENUM('Inside','Outside')   NOT NULL DEFAULT 'Outside',
  `occupants_reg_status`          ENUM('Active','Inactive')  NOT NULL DEFAULT 'Active',
  `occupants_reg_at`              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `occupants_last_seen_at`        DATETIME      NULL,
  `occupants_deactivated_at`      DATETIME      NULL,
  `occupants_deactivate_reason`   VARCHAR(120)  NULL,
  `occupants_deactivate_source`   ENUM('manual','inactivity','semester_end') NULL,
  `Rooms_room_id`                 CHAR(4)       NULL,
  `Admins_admins_id`              INT           NOT NULL,
  PRIMARY KEY (`occupants_id`),
  UNIQUE KEY `occupants_id_UNIQUE` (`occupants_id`),
  -- no ON UPDATE CASCADE here on purpose: MariaDB forbids a CHECK constraint
  -- on a column that is part of a cascading foreign key (error 1901), and
  -- chk_occupants_status_room below reads Rooms_room_id. Room ids are stable,
  -- so a cascade is not needed anyway.
  CONSTRAINT `fk_Occupants_Rooms`
    FOREIGN KEY (`Rooms_room_id`) REFERENCES `Rooms` (`room_id`)
    ON DELETE RESTRICT ON UPDATE NO ACTION,
  CONSTRAINT `fk_Occupants_Admins1`
    FOREIGN KEY (`Admins_admins_id`) REFERENCES `Admins` (`admins_id`)
    ON DELETE RESTRICT ON UPDATE NO ACTION,
  CONSTRAINT `chk_contact_number`
    CHECK (`occupants_contact_number` REGEXP '^09[0-9]{9}$'),
  CONSTRAINT `chk_occupants_status_room`
    CHECK (
      (`occupants_reg_status` = 'Active'   AND `Rooms_room_id` IS NOT NULL) OR
      (`occupants_reg_status` = 'Inactive' AND `Rooms_room_id` IS NULL)
    )
) ENGINE = InnoDB;

-- ---------------------------------------------------------------------
--  Logs
--  One row per visit. time_out NULL = the occupant is still in.
--  Occupants_Rooms_room_id is the room at the time of the log (snapshot).
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `Logs`;
CREATE TABLE `Logs` (
  `logs_id`                     INT      NOT NULL AUTO_INCREMENT,
  `logs_time_in`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `logs_time_out`               DATETIME NULL,
  `logs_whereabouts`            TINYINT  NOT NULL DEFAULT 5,
  `logs_is_late`                TINYINT  NOT NULL DEFAULT 0,
  `Occupants_occupants_id`      CHAR(7)  NOT NULL,
  `Occupants_Rooms_room_id`     CHAR(4)  NOT NULL,
  PRIMARY KEY (`logs_id`, `Occupants_occupants_id`, `Occupants_Rooms_room_id`),
  CONSTRAINT `chk_logs_time_order`
    CHECK (`logs_time_out` IS NULL OR `logs_time_out` > `logs_time_in`),
  CONSTRAINT `chk_logs_is_late`
    CHECK (`logs_is_late` IN (0, 1)),
  CONSTRAINT `fk_Logs_Occupants1`
    FOREIGN KEY (`Occupants_occupants_id`) REFERENCES `Occupants` (`occupants_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_Logs_Rooms`
    FOREIGN KEY (`Occupants_Rooms_room_id`) REFERENCES `Rooms` (`room_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB;

-- ---------------------------------------------------------------------
--  Settings
--  One row. Curfew time and the thresholds used by the automatic rules.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `Settings`;
CREATE TABLE `Settings` (
  `settings_id`                 INT      NOT NULL AUTO_INCREMENT,
  `settings_curfew_time`        TIME     NOT NULL DEFAULT '22:00:00',
  `settings_inactivity_days`    TINYINT  NOT NULL DEFAULT 14
      COMMENT 'Auto-deactivate after this many days with no log activity',
  `settings_semester_end`       DATE     NULL
      COMMENT 'End of the current semester; drives the semester-end rule',
  `settings_auto_deactivate`    TINYINT  NOT NULL DEFAULT 1
      COMMENT 'Master switch for automatic deactivation',
  `settings_last_auto_run`      DATETIME NULL
      COMMENT 'Last time the automatic rules ran',
  `settings_updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `Admins_admins_id`            INT      NOT NULL,
  PRIMARY KEY (`settings_id`, `Admins_admins_id`),
  CONSTRAINT `chk_settings_inactivity_days`
    CHECK (`settings_inactivity_days` BETWEEN 1 AND 365),
  CONSTRAINT `chk_settings_auto`
    CHECK (`settings_auto_deactivate` IN (0, 1)),
  CONSTRAINT `fk_Settings_Admins1`
    FOREIGN KEY (`Admins_admins_id`) REFERENCES `Admins` (`admins_id`)
    ON DELETE RESTRICT ON UPDATE NO ACTION
) ENGINE = InnoDB;

-- ---------------------------------------------------------------------
--  OccupancyAudit
--  Append-only trail: who changed an occupant's residency, when, and why.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `OccupancyAudit`;
CREATE TABLE `OccupancyAudit` (
  `audit_id`                    INT           NOT NULL AUTO_INCREMENT,
  `Occupants_occupants_id`      CHAR(7)       NOT NULL,
  `audit_action`                ENUM('Registered','Updated','Deactivated','Reactivated') NOT NULL,
  `audit_old_room`              CHAR(4)       NULL,
  `audit_new_room`              CHAR(4)       NULL,
  `audit_reason`                VARCHAR(255)  NULL,
  `Admins_admins_id`            INT           NULL,
  `audit_at`                    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`),
  CONSTRAINT `fk_audit_occupant`
    FOREIGN KEY (`Occupants_occupants_id`) REFERENCES `Occupants` (`occupants_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB;


-- =====================================================================
--  3. INDEXES
--     (primary/unique keys are declared above; these are the lookup and
--      sort/filter indexes used by the Occupants screen)
-- =====================================================================
CREATE INDEX `idx_occupants_name`         ON `Occupants` (`occupants_l_name`, `occupants_f_name`);
CREATE INDEX `idx_occupants_reg_status`   ON `Occupants` (`occupants_reg_status`);
CREATE INDEX `idx_occupants_reg_at`       ON `Occupants` (`occupants_reg_at`);
CREATE INDEX `idx_occupants_room_status`  ON `Occupants` (`Rooms_room_id`, `occupants_reg_status`);
CREATE INDEX `idx_occupants_status_seen`  ON `Occupants` (`occupants_reg_status`, `occupants_last_seen_at`);
CREATE INDEX `idx_logs_occupant_time`     ON `Logs`      (`Occupants_occupants_id`, `logs_time_in`);
CREATE INDEX `idx_logs_time_in`           ON `Logs`      (`logs_time_in`);
CREATE INDEX `idx_audit_occupant`         ON `OccupancyAudit` (`Occupants_occupants_id`, `audit_at`);


-- =====================================================================
--  4. SEED ROWS  (demo data — delete this section for a production install)
-- =====================================================================

-- Admins: passcode is "admin123", stored as a bcrypt hash.
-- Replace with:  php -r "echo password_hash('yourPasscode', PASSWORD_DEFAULT);"
INSERT INTO `Admins` (`admins_id`, `admins_passcode`) VALUES
  (1, '$2y$10$FHWRlhH5pOvMeFGMcZMJcuCCyeO51Wl//Ho4YZoPieVsFBN.cw9NK');

-- Rooms: floors 01, 02, 03
INSERT INTO `Rooms` (`room_id`, `room_capacity`) VALUES
  ('0101', 4), ('0102', 4), ('0103', 4),
  ('0201', 4), ('0202', 4),
  ('0301', 4);

-- Settings: one row, curfew 22:00, inactivity threshold 14 days
INSERT INTO `Settings`
  (`settings_id`, `settings_curfew_time`, `settings_inactivity_days`,
   `settings_semester_end`, `settings_auto_deactivate`, `Admins_admins_id`)
VALUES
  (1, '22:00:00', 14, NULL, 1, 1);

-- Occupants: 9 rows. '03-0003' is Inactive and therefore has no room.
INSERT INTO `Occupants`
  (`occupants_id`, `occupants_f_name`, `occupants_m_name`, `occupants_l_name`, `occupants_s_name`,
   `occupants_program`, `occupants_contact_number`, `occupants_loc_status`, `occupants_reg_status`,
   `occupants_reg_at`, `occupants_last_seen_at`, `Rooms_room_id`, `Admins_admins_id`)
VALUES
  ('01-0001', 'Rene',       'Villanueva', 'Baterbonia', 'Jr.', 'BS Information Technology', '09171234567', 'Inside',  'Active',   '2026-06-05 09:00:00', '2026-11-21 22:15:00', '0101', 1),
  ('01-0002', 'Mariah',     'S.',         'Deborah',    NULL,  'BS Computer Science',       '09981234567', 'Outside', 'Active',   '2026-06-05 08:15:00', '2026-11-21 20:40:00', '0101', 1),
  ('01-0003', 'Jeo',        'O.',         'Bak',        NULL,  'BS Biology',                '09191234567', 'Outside', 'Active',   '2026-07-10 10:00:00', '2026-11-21 21:00:00', '0102', 1),
  ('02-0001', 'Cris',       'M.',         'Hippers',    NULL,  'BS Civil Engineering',      '09201234567', 'Inside',  'Active',   '2026-07-12 08:30:00', '2026-11-21 21:45:00', '0201', 1),
  ('02-0002', 'Sanya',      'L.',         'Lopez',      NULL,  'BS Accountancy',            '09181234567', 'Outside', 'Active',   '2026-08-01 13:00:00', '2026-11-20 21:30:00', '0201', 1),
  ('02-0003', 'Daniela',    'K.',         'BK',         NULL,  'BS Nursing',                '09361234567', 'Outside', 'Active',   '2026-08-01 14:20:00', '2026-11-20 07:10:00', '0202', 1),
  ('03-0001', 'Dinorobong', NULL,         'Tubo',       NULL,  'BS Architecture',           '09471234567', 'Inside',  'Active',   '2026-08-15 09:45:00', '2026-11-21 22:10:00', '0301', 1),
  ('03-0002', 'Paolo',      'R.',         'Santos',     NULL,  'BS Electrical Engineering', '09561234567', 'Outside', 'Active',   '2026-09-02 11:10:00', '2026-11-19 18:05:00', '0301', 1),
  ('03-0003', 'Ana',        'B.',         'Reyes',      NULL,  'BS Education',              '09651234567', 'Outside', 'Inactive', '2026-09-02 11:30:00', '2026-09-02 11:30:00', NULL,   1);

-- Logs: whereabouts 1=Canteen 2=Library 3=Class 4=Home 5=Others
INSERT INTO `Logs`
  (`logs_time_in`, `logs_time_out`, `logs_whereabouts`, `logs_is_late`,
   `Occupants_occupants_id`, `Occupants_Rooms_room_id`)
VALUES
  ('2026-11-21 22:15:00', NULL,                  3, 1, '01-0001', '0101'),
  ('2026-11-21 19:00:00', '2026-11-21 21:00:00', 2, 0, '01-0003', '0102'),
  ('2026-11-21 21:45:00', NULL,                  4, 0, '02-0001', '0201'),
  ('2026-11-20 18:30:00', '2026-11-20 21:30:00', 1, 0, '02-0002', '0201'),
  ('2026-11-19 22:40:00', '2026-11-20 07:10:00', 4, 1, '02-0003', '0202'),
  ('2026-11-21 22:10:00', NULL,                  5, 1, '03-0001', '0301');


-- =====================================================================
--  5. VIEW — vw_occupants_directory
--     Read model for the Occupants screen: one row per occupant with the
--     room capacity and how many active occupants share that room.
-- =====================================================================
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


-- =====================================================================
--  6 & 7. ROUTINES — triggers and stored procedures
--         All four objects are inside one DELIMITER block so this section
--         can be pasted into MySQL Workbench or piped through the CLI.
-- =====================================================================
DROP TRIGGER IF EXISTS `trg_logs_after_insert`;
DROP TRIGGER IF EXISTS `trg_logs_after_update`;
DROP PROCEDURE IF EXISTS `sp_auto_deactivate_inactive`;
DROP PROCEDURE IF EXISTS `sp_deactivate_occupant`;

DELIMITER $$

-- ---------------------------------------------------------------------
--  TRIGGERS: keep Occupants.occupants_last_seen_at current.
--  The inactivity rule measures "outside for 2 weeks" against this column,
--  so every log row an occupant produces refreshes it.
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
--  PROCEDURE: sp_auto_deactivate_inactive
--  The "outside for 2 weeks" rule.
--
--  Renders an occupant 'Inactive' when all of these hold:
--    * occupants_reg_status = 'Active'
--    * they are not currently inside (no open log)
--    * occupants_last_seen_at is at least p_days old
--
--  The room is released, the log history is untouched, and the reason and
--  source are recorded so the row explains itself later.
--
--  p_days NULL  ->  Settings.settings_inactivity_days (default 14)
--  Returns one row per occupant deactivated by this run.
-- ---------------------------------------------------------------------
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

    -- close any log still open for an occupant about to be deactivated
    UPDATE `Logs` l
      JOIN `Occupants` o ON o.`occupants_id` = l.`Occupants_occupants_id`
       SET l.`logs_time_out` = NOW()
     WHERE l.`logs_time_out` IS NULL
       AND o.`occupants_reg_status` = 'Active'
       AND COALESCE(o.`occupants_last_seen_at`, o.`occupants_reg_at`) < v_cutoff;

    -- deactivate
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

    -- audit trail
    INSERT INTO `OccupancyAudit`
        (`Occupants_occupants_id`, `audit_action`, `audit_old_room`,
         `audit_new_room`, `audit_reason`, `Admins_admins_id`)
    SELECT o.`occupants_id`, 'Deactivated', NULL, NULL,
           CONCAT('Automatic: no check-in for ', v_days, ' days'), NULL
      FROM `Occupants` o
     WHERE o.`occupants_reg_status` = 'Inactive'
       AND o.`occupants_deactivate_source` = 'inactivity'
       AND o.`occupants_deactivated_at` >= DATE_SUB(NOW(), INTERVAL 1 MINUTE);

    -- stamp the run
    UPDATE `Settings` SET `settings_last_auto_run` = NOW()
     WHERE `settings_id` = (SELECT MIN(`settings_id`)
                              FROM (SELECT `settings_id` FROM `Settings`) s);

    -- report
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

-- ---------------------------------------------------------------------
--  PROCEDURE: sp_deactivate_occupant
--  Manual deactivation from the occupant list. Same bookkeeping as the
--  automatic rule, so a row cannot tell you which path wrote it by looking
--  at its shape -- only by occupants_deactivate_source.
-- ---------------------------------------------------------------------
CREATE PROCEDURE `sp_deactivate_occupant`(
    IN p_occupant_id CHAR(7),
    IN p_reason      VARCHAR(120),
    IN p_admin_id    INT
)
BEGIN
    DECLARE v_room CHAR(4);

    SELECT `Rooms_room_id` INTO v_room
      FROM `Occupants` WHERE `occupants_id` = p_occupant_id;

    -- close an open log so a timed-in occupant cannot be left dangling
    UPDATE `Logs`
       SET `logs_time_out` = NOW()
     WHERE `Occupants_occupants_id` = p_occupant_id
       AND `logs_time_out` IS NULL;

    UPDATE `Occupants`
       SET `occupants_reg_status`        = 'Inactive',
           `Rooms_room_id`               = NULL,
           `occupants_loc_status`        = 'Outside',
           `occupants_deactivated_at`    = NOW(),
           `occupants_deactivate_reason` = COALESCE(p_reason, 'Manual deactivation'),
           `occupants_deactivate_source` = 'manual'
     WHERE `occupants_id` = p_occupant_id;

    INSERT INTO `OccupancyAudit`
        (`Occupants_occupants_id`, `audit_action`, `audit_old_room`,
         `audit_new_room`, `audit_reason`, `Admins_admins_id`)
    VALUES (p_occupant_id, 'Deactivated', v_room, NULL,
            COALESCE(p_reason, 'Manual deactivation'), p_admin_id);
END$$

DELIMITER ;


-- =====================================================================
--  8. VERIFICATION
--     Sanity queries that should all return the expected shape.
-- =====================================================================

-- 8.1 tables created
SELECT TABLE_NAME, TABLE_TYPE
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = 'logbuk_db'
 ORDER BY TABLE_TYPE, TABLE_NAME;

-- 8.2 status column is the ENUM with DEFAULT 'Active'
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = 'logbuk_db'
   AND TABLE_NAME   = 'Occupants'
   AND COLUMN_NAME IN ('occupants_reg_status', 'occupants_loc_status');

-- 8.3 routines created
SELECT ROUTINE_TYPE, ROUTINE_NAME
  FROM information_schema.ROUTINES
 WHERE ROUTINE_SCHEMA = 'logbuk_db'
 ORDER BY ROUTINE_TYPE, ROUTINE_NAME;

-- 8.4 triggers created
SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE
  FROM information_schema.TRIGGERS
 WHERE TRIGGER_SCHEMA = 'logbuk_db';

-- 8.5 room occupancy (max 4 per room)
SELECT r.`room_id`,
       r.`room_capacity`,
       COUNT(o.`occupants_id`)                                  AS `active_occupants`,
       (r.`room_capacity` - COUNT(o.`occupants_id`))             AS `slots_left`
  FROM `Rooms` r
  LEFT JOIN `Occupants` o
         ON o.`Rooms_room_id` = r.`room_id`
        AND o.`occupants_reg_status` = 'Active'
 GROUP BY r.`room_id`, r.`room_capacity`
 ORDER BY r.`room_id`;

-- 8.6 occupant directory
SELECT `occupants_id`, `residency`, IFNULL(`Rooms_room_id`, '—') AS `room`,
       IFNULL(`occupants_deactivate_source`, '—') AS `deactivated_by`
  FROM `vw_occupants_directory`
 ORDER BY `occupants_id`;