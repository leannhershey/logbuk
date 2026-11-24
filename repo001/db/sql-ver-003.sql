-- =====================================================================
-- LogBUk schema patch: 002 -> 003
-- Run AFTER sql-ver-002.sql and seed-occupants.sql:
--     mysql -u root -p logbuk_db < db/sql-ver-003.sql
--
-- Why this patch is required before deactivation can work:
--
--   * Occupants.Rooms_room_id was NOT NULL, so an occupant could never
--     "leave the room" the way the deactivate dialog promises.
--   * Logs had a COMPOSITE foreign key on
--     (Occupants_occupants_id, Occupants_Rooms_room_id) -> Occupants.
--     Clearing an occupant's room therefore raised error 1451: the log
--     rows still pointed at the old (occupant, room) pair.
--     Fix: Logs keeps the room as a HISTORICAL SNAPSHOT of where the
--     occupant was living at that moment, with its own FK to Rooms, and
--     the occupant link becomes a simple FK on occupants_id.
--   * Added a CHECK constraint so residency status and room assignment
--     can never disagree:  Active  => has a room,
--                           Inactive => room is NULL.
--
-- Order matters: the Logs foreign key is dropped FIRST, because a
-- foreign key must always have an index on its referenced column --
-- and step 2 temporarily removes the only index on occupants_id.
-- =====================================================================

USE `logbuk_db`;

-- ---------------------------------------------------------------
-- 1. Release the composite FK from Logs (must happen first)
-- ---------------------------------------------------------------
ALTER TABLE `Logs` DROP FOREIGN KEY `fk_Logs_Occupants1`;

-- ---------------------------------------------------------------
-- 2. Occupants: single-column primary key, room becomes nullable
-- ---------------------------------------------------------------
ALTER TABLE `Occupants`
  DROP PRIMARY KEY,
  DROP INDEX `occupants_id_UNIQUE`,
  ADD PRIMARY KEY (`occupants_id`),
  MODIFY `Rooms_room_id` CHAR(4) NULL DEFAULT NULL;

-- ---------------------------------------------------------------
-- 3. Re-attach Logs. The room column is now a historical snapshot:
--    FK to Rooms, not to Occupants, so clearing an occupant's room
--    never touches their log history.
-- ---------------------------------------------------------------
ALTER TABLE `Logs`
  ADD CONSTRAINT `fk_Logs_Rooms`
    FOREIGN KEY (`Occupants_Rooms_room_id`)
    REFERENCES `Rooms` (`room_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `Logs`
  ADD CONSTRAINT `fk_Logs_Occupants1`
    FOREIGN KEY (`Occupants_occupants_id`)
    REFERENCES `Occupants` (`occupants_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `Logs` ADD INDEX `idx_logs_occupant_time` (`Occupants_occupants_id`, `logs_time_in`);

-- ---------------------------------------------------------------
-- 4. Status/room condition, enforced by the database itself
-- ---------------------------------------------------------------
ALTER TABLE `Occupants`
  ADD CONSTRAINT `chk_occupants_status_room`
    CHECK (
      (`occupants_reg_status` = 'Active'   AND `Rooms_room_id` IS NOT NULL) OR
      (`occupants_reg_status` = 'Inactive' AND `Rooms_room_id` IS NULL)
    );

ALTER TABLE `Occupants`
  ADD CONSTRAINT `chk_contact_number`
    CHECK (`occupants_contact_number` REGEXP '^09[0-9]{9}$');

-- ---------------------------------------------------------------
-- 5. Indexes for sort / filter / search
-- ---------------------------------------------------------------
ALTER TABLE `Occupants` ADD INDEX `idx_occupants_name` (`occupants_l_name`, `occupants_f_name`);
ALTER TABLE `Occupants` ADD INDEX `idx_occupants_reg_status` (`occupants_reg_status`);
ALTER TABLE `Occupants` ADD INDEX `idx_occupants_reg_at` (`occupants_reg_at`);
ALTER TABLE `Occupants` ADD INDEX `idx_occupants_room_status` (`Rooms_room_id`, `occupants_reg_status`);

-- ---------------------------------------------------------------
-- 6. Deactivation audit trail (who / when / why / previous room)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `OccupancyAudit` (
  `audit_id` INT NOT NULL AUTO_INCREMENT,
  `Occupants_occupants_id` CHAR(7) NOT NULL,
  `audit_action` ENUM('Registered','Updated','Deactivated','Reactivated') NOT NULL,
  `audit_old_room` CHAR(4) NULL,
  `audit_new_room` CHAR(4) NULL,
  `audit_reason` VARCHAR(255) NULL,
  `Admins_admins_id` INT NULL,
  `audit_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`),
  INDEX `idx_audit_occupant` (`Occupants_occupants_id`, `audit_at`),
  CONSTRAINT `fk_audit_occupant`
    FOREIGN KEY (`Occupants_occupants_id`)
    REFERENCES `Occupants` (`occupants_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB;
