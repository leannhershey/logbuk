/* LogBUk database: schema, triggers, procedure and demo data. Import this one file. */

DROP SCHEMA IF EXISTS `logbuk_db`;
CREATE SCHEMA `logbuk_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `logbuk_db`;

CREATE TABLE `Rooms` (
  `room_id`   CHAR(4)  NOT NULL,
  `capacity`  TINYINT  NOT NULL DEFAULT 4,
  PRIMARY KEY (`room_id`),
  CONSTRAINT `chk_room_id_format`
    CHECK (`room_id` REGEXP '^[0-9]{4}$'
           AND SUBSTRING(`room_id`, 1, 2) BETWEEN '01' AND '24'
           AND SUBSTRING(`room_id`, 3, 2) BETWEEN '01' AND '24'),
  CONSTRAINT `chk_room_capacity`
    CHECK (`capacity` BETWEEN 1 AND 4)
) ENGINE = InnoDB;

CREATE TABLE `Admin` (
  `admin_id`    INT           NOT NULL AUTO_INCREMENT,
  `passcode`    VARCHAR(255)  NOT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`)
) ENGINE = InnoDB;

CREATE TABLE `Occupants` (
  `occupant_id`         CHAR(7)       NOT NULL,
  `room_id`             CHAR(4)       NULL,
  `registered_by`       INT           NOT NULL,
  `first_name`          VARCHAR(100)  NOT NULL,
  `middle_name`         VARCHAR(50)   NULL,
  `last_name`           VARCHAR(50)   NOT NULL,
  `suffix`              VARCHAR(10)   NULL,
  `program`             VARCHAR(100)  NULL,
  `contact_number`      VARCHAR(11)   NOT NULL,
  `presence_status`     ENUM('On Premises', 'Off Premises') NOT NULL DEFAULT 'Off Premises',
  `residency_status`    ENUM('Active', 'Inactive')         NOT NULL DEFAULT 'Active',
  `registered_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`        DATETIME      NULL,
  `deactivated_at`      DATETIME      NULL,
  `deactivate_reason`   VARCHAR(120)  NULL,
  `deactivate_source`   ENUM('manual', 'inactivity') NULL,
  PRIMARY KEY (`occupant_id`),
  /* room_id takes part in the CHECK below, so MariaDB wants it without ON UPDATE CASCADE */
  CONSTRAINT `fk_occupants_room`
    FOREIGN KEY (`room_id`) REFERENCES `Rooms` (`room_id`)
    ON DELETE RESTRICT ON UPDATE NO ACTION,
  CONSTRAINT `fk_occupants_admin`
    FOREIGN KEY (`registered_by`) REFERENCES `Admin` (`admin_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_contact_number`
    CHECK (`contact_number` REGEXP '^09[0-9]{9}$'),
  CONSTRAINT `chk_residency_room`
    CHECK (
      (`residency_status` = 'Active'   AND `room_id` IS NOT NULL) OR
      (`residency_status` = 'Inactive' AND `room_id` IS NULL)
    )
) ENGINE = InnoDB;

CREATE TABLE `Logs` (
  `log_id`        INT           NOT NULL AUTO_INCREMENT,
  `occupant_id`   CHAR(7)       NOT NULL,
  `room_id`       CHAR(4)       NOT NULL,
  `time_in`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `time_out`      DATETIME      NULL,
  `whereabouts`   VARCHAR(150)  NULL,
  `is_late`       TINYINT(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (`log_id`),
  CONSTRAINT `fk_logs_occupant`
    FOREIGN KEY (`occupant_id`) REFERENCES `Occupants` (`occupant_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_logs_room`
    FOREIGN KEY (`room_id`) REFERENCES `Rooms` (`room_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_logs_time_order`
    CHECK (`time_out` IS NULL OR `time_out` > `time_in`),
  CONSTRAINT `chk_logs_is_late`
    CHECK (`is_late` IN (0, 1))
) ENGINE = InnoDB;

CREATE TABLE `Settings` (
  `setting_id`         INT      NOT NULL AUTO_INCREMENT,
  `updated_by`         INT      NOT NULL,
  `curfew_time`        TIME     NOT NULL DEFAULT '22:00:00',
  `inactivity_days`    TINYINT  NOT NULL DEFAULT 14
      COMMENT 'Auto-deactivate after this many days with no log activity',
  `auto_deactivate`    TINYINT  NOT NULL DEFAULT 1
      COMMENT 'Master switch for automatic deactivation',
  `last_auto_run`      DATETIME NULL
      COMMENT 'Last time the automatic rule ran',
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  CONSTRAINT `fk_settings_admin`
    FOREIGN KEY (`updated_by`) REFERENCES `Admin` (`admin_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_settings_inactivity_days`
    CHECK (`inactivity_days` BETWEEN 1 AND 365),
  CONSTRAINT `chk_settings_auto`
    CHECK (`auto_deactivate` IN (0, 1))
) ENGINE = InnoDB;

CREATE INDEX `idx_occupants_name`        ON `Occupants` (`last_name`, `first_name`);
CREATE INDEX `idx_occupants_residency`   ON `Occupants` (`residency_status`);
CREATE INDEX `idx_occupants_room`        ON `Occupants` (`room_id`, `residency_status`);
CREATE INDEX `idx_occupants_seen`        ON `Occupants` (`residency_status`, `last_seen_at`);
CREATE INDEX `idx_logs_occupant_time`    ON `Logs` (`occupant_id`, `time_in`);
CREATE INDEX `idx_logs_time_in`          ON `Logs` (`time_in`);

INSERT INTO `Admin` (`admin_id`, `passcode`) VALUES
  (1, '$2y$10$FHWRlhH5pOvMeFGMcZMJcuCCyeO51Wl//Ho4YZoPieVsFBN.cw9NK');

INSERT INTO `Rooms` (`room_id`, `capacity`) VALUES
  ('0101', 4), ('0102', 4), ('0103', 4),
  ('0201', 4), ('0202', 4),
  ('0301', 4);

INSERT INTO `Settings`
  (`setting_id`, `updated_by`, `curfew_time`, `inactivity_days`, `auto_deactivate`)
VALUES
  (1, 1, '22:00:00', 14, 1);

INSERT INTO `Occupants`
  (`occupant_id`, `room_id`, `registered_by`, `first_name`, `middle_name`, `last_name`, `suffix`,
   `program`, `contact_number`, `presence_status`, `residency_status`, `registered_at`, `last_seen_at`)
VALUES
  ('01-0001', '0101', 1, 'Rene',       'Villanueva', 'Baterbonia', 'Jr.', 'BS Information Technology', '09171234567', 'On Premises',  'Active',   '2026-06-05 09:00:00', '2026-11-21 22:15:00'),
  ('01-0002', '0101', 1, 'Mariah',     'S.',         'Deborah',    NULL,  'BS Computer Science',       '09981234567', 'Off Premises', 'Active',   '2026-06-05 08:15:00', '2026-11-21 20:40:00'),
  ('01-0003', '0102', 1, 'Jeo',        'O.',         'Bak',        NULL,  'BS Biology',                '09191234567', 'Off Premises', 'Active',   '2026-07-10 10:00:00', '2026-11-21 21:00:00'),
  ('02-0001', '0201', 1, 'Cris',       'M.',         'Hippers',    NULL,  'BS Civil Engineering',      '09201234567', 'On Premises',  'Active',   '2026-07-12 08:30:00', '2026-11-21 21:45:00'),
  ('02-0002', '0201', 1, 'Sanya',      'L.',         'Lopez',      NULL,  'BS Accountancy',            '09181234567', 'Off Premises', 'Active',   '2026-08-01 13:00:00', '2026-11-20 21:30:00'),
  ('02-0003', '0202', 1, 'Daniela',    'K.',         'BK',         NULL,  'BS Nursing',                '09361234567', 'Off Premises', 'Active',   '2026-08-01 14:20:00', '2026-11-20 07:10:00'),
  ('03-0001', '0301', 1, 'Dinorobong', NULL,         'Tubo',       NULL,  'BS Architecture',           '09471234567', 'On Premises',  'Active',   '2026-08-15 09:45:00', '2026-11-21 22:10:00'),
  ('03-0002', '0301', 1, 'Paolo',      'R.',         'Santos',     NULL,  'BS Electrical Engineering', '09561234567', 'Off Premises', 'Active',   '2026-09-02 11:10:00', '2026-11-19 18:05:00'),
  ('03-0003', NULL,   1, 'Ana',        'B.',         'Reyes',      NULL,  'BS Education',              '09651234567', 'Off Premises', 'Inactive', '2026-09-02 11:30:00', '2026-09-02 11:30:00');

INSERT INTO `Logs` (`occupant_id`, `room_id`, `time_in`, `time_out`, `whereabouts`, `is_late`) VALUES
  ('01-0001', '0101', '2026-11-21 22:15:00', NULL,                  NULL,      1),
  ('01-0003', '0102', '2026-11-21 19:00:00', '2026-11-21 21:00:00', 'Library', 0),
  ('02-0001', '0201', '2026-11-21 21:45:00', NULL,                  NULL,      0),
  ('02-0002', '0201', '2026-11-20 18:30:00', '2026-11-20 21:30:00', 'Canteen', 0),
  ('02-0003', '0202', '2026-11-19 22:40:00', '2026-11-20 07:10:00', 'Home',    1),
  ('03-0001', '0301', '2026-11-21 22:10:00', NULL,                  NULL,      1);

DROP TRIGGER IF EXISTS `trg_logs_after_insert`;
DROP TRIGGER IF EXISTS `trg_logs_after_update`;
DROP PROCEDURE IF EXISTS `sp_auto_deactivate_inactive`;

DELIMITER $$

CREATE TRIGGER `trg_logs_after_insert`
AFTER INSERT ON `Logs`
FOR EACH ROW
BEGIN
    UPDATE `Occupants`
       SET `last_seen_at` = GREATEST(
             COALESCE(`last_seen_at`, '1970-01-01 00:00:00'),
             COALESCE(NEW.`time_out`, NEW.`time_in`)
           )
     WHERE `occupant_id` = NEW.`occupant_id`;
END$$

CREATE TRIGGER `trg_logs_after_update`
AFTER UPDATE ON `Logs`
FOR EACH ROW
BEGIN
    UPDATE `Occupants`
       SET `last_seen_at` = GREATEST(
             COALESCE(`last_seen_at`, '1970-01-01 00:00:00'),
             COALESCE(NEW.`time_out`, NEW.`time_in`)
           )
     WHERE `occupant_id` = NEW.`occupant_id`;
END$$

CREATE PROCEDURE `sp_auto_deactivate_inactive`(IN p_days INT)
BEGIN
    DECLARE v_days   INT DEFAULT 14;
    DECLARE v_cutoff DATETIME;

    IF p_days IS NOT NULL AND p_days > 0 THEN
        SET v_days = p_days;
    ELSE
        SELECT COALESCE(`inactivity_days`, 14) INTO v_days
          FROM `Settings` ORDER BY `setting_id` LIMIT 1;
        IF v_days IS NULL THEN SET v_days = 14; END IF;
    END IF;

    SET v_cutoff = DATE_SUB(NOW(), INTERVAL v_days DAY);

    UPDATE `Logs` l
      JOIN `Occupants` o ON o.`occupant_id` = l.`occupant_id`
       SET l.`time_out` = NOW()
     WHERE l.`time_out` IS NULL
       AND o.`residency_status` = 'Active'
       AND COALESCE(o.`last_seen_at`, o.`registered_at`) < v_cutoff;

    UPDATE `Occupants` o
       SET o.`residency_status`    = 'Inactive',
           o.`room_id`             = NULL,
           o.`presence_status`     = 'Off Premises',
           o.`deactivated_at`      = NOW(),
           o.`deactivate_reason`   = CONCAT('No check-in for ', v_days, ' days'),
           o.`deactivate_source`   = 'inactivity'
     WHERE o.`residency_status` = 'Active'
       AND o.`presence_status`  = 'Off Premises'
       AND COALESCE(o.`last_seen_at`, o.`registered_at`) < v_cutoff
       AND NOT EXISTS (
             SELECT 1 FROM `Logs` l2
              WHERE l2.`occupant_id` = o.`occupant_id`
                AND l2.`time_out` IS NULL
           );

    UPDATE `Settings` SET `last_auto_run` = NOW()
     WHERE `setting_id` = (SELECT MIN(`setting_id`)
                             FROM (SELECT `setting_id` FROM `Settings`) s);

    SELECT `occupant_id`,
           CONCAT_WS(' ', `first_name`, `last_name`) AS `full_name`,
           `last_seen_at`,
           `deactivated_at`,
           `deactivate_reason`
      FROM `Occupants`
     WHERE `residency_status`  = 'Inactive'
       AND `deactivate_source` = 'inactivity'
       AND `deactivated_at` >= DATE_SUB(NOW(), INTERVAL 1 MINUTE);
END$$

DELIMITER ;

/* directory of active occupants with room load */
CREATE OR REPLACE VIEW vw_occupants_directory AS
SELECT o.occupant_id, o.first_name, o.middle_name, o.last_name, o.suffix,
       o.program, o.contact_number, o.presence_status, o.residency_status,
       o.room_id, r.capacity, COUNT(o2.occupant_id) AS room_occupancy
FROM Occupants o
LEFT JOIN Rooms r ON o.room_id = r.room_id
LEFT JOIN Occupants o2 ON o.room_id = o2.room_id AND o2.residency_status = 'Active'
WHERE o.residency_status = 'Active'
GROUP BY o.occupant_id, o.first_name, o.middle_name, o.last_name, o.suffix,
         o.program, o.contact_number, o.presence_status, o.residency_status,
         o.room_id, r.capacity;