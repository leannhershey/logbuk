-- MySQL Workbench Forward Engineering (DEBUGGED / FIXED)
--
-- Fixes applied:
--   Rooms : removed mangled DEFAULT on room_id; added proper table-level CHECK
--           constraints; fixed column name in room_capacity CHECK; removed smart
--           quotes; removed duplicate DEFAULT keyword.
--   Occupants: removed bogus DEFAULT 'ON UPDATE CASCADE' on occupants_id.
--   Logs   : moved time-order validation to a table-level CHECK referencing the
--           correct column names (logs_time_out, logs_time_in).
--   Settings: removed duplicate DEFAULT keyword on settings_updated_at.

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- -----------------------------------------------------
-- Schema logbuk
-- -----------------------------------------------------
DROP SCHEMA IF EXISTS `logbuk`;

-- -----------------------------------------------------
-- Schema logbuk
-- -----------------------------------------------------
CREATE SCHEMA IF NOT EXISTS `logbuk` DEFAULT CHARACTER SET utf8;
USE `logbuk`;

-- -----------------------------------------------------
-- Table `logbuk`.`Rooms`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `logbuk`.`Rooms`;

CREATE TABLE IF NOT EXISTS `logbuk`.`Rooms` (
  `room_id` CHAR(4) NOT NULL,
  `room_capacity` TINYINT(2) NOT NULL DEFAULT 4,
  PRIMARY KEY (`room_id`),
  UNIQUE INDEX `RoomID_UNIQUE` (`room_id` ASC) VISIBLE,
  CONSTRAINT `chk_room_id_format`
    CHECK (SUBSTRING(room_id, 3, 2) BETWEEN '01' AND '26'),
  CONSTRAINT `chk_room_capacity`
    CHECK (room_capacity BETWEEN 1 AND 4)
) ENGINE = InnoDB;

-- -----------------------------------------------------
-- Table `logbuk`.`Admins`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `logbuk`.`Admins`;

CREATE TABLE IF NOT EXISTS `logbuk`.`Admins` (
  `admins_id` INT NOT NULL AUTO_INCREMENT,
  `admins_passcode` VARCHAR(255) NOT NULL,
  `admins_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`admins_id`),
  UNIQUE INDEX `admins_id_UNIQUE` (`admins_id` ASC) VISIBLE
) ENGINE = InnoDB;

-- -----------------------------------------------------
-- Table `logbuk`.`Occupants`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `logbuk`.`Occupants`;

CREATE TABLE IF NOT EXISTS `logbuk`.`Occupants` (
  `occupants_id` CHAR(7) NOT NULL,
  `occupants_f_name` VARCHAR(100) NOT NULL,
  `occupants_m_name` VARCHAR(50) NULL,
  `occupants_l_name` VARCHAR(50) NOT NULL,
  `occupants_s_name` VARCHAR(10) NULL,
  `occupants_program` VARCHAR(100) NULL,
  `occupants_contact_number` VARCHAR(11) NOT NULL,
  `occupants_loc_status` ENUM('Inside', 'Outside') NOT NULL DEFAULT 'Outside',
  `occupants_reg_status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  `occupants_reg_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `Rooms_room_id` CHAR(4) NOT NULL,
  `Admins_admins_id` INT NOT NULL,
  PRIMARY KEY (`occupants_id`, `Rooms_room_id`, `Admins_admins_id`),
  UNIQUE INDEX `occupants_id_UNIQUE` (`occupants_id` ASC) VISIBLE,
  INDEX `fk_Occupants_Rooms_idx` (`Rooms_room_id` ASC) VISIBLE,
  INDEX `fk_Occupants_Admins1_idx` (`Admins_admins_id` ASC) VISIBLE,
  CONSTRAINT `fk_Occupants_Rooms`
    FOREIGN KEY (`Rooms_room_id`)
    REFERENCES `logbuk`.`Rooms` (`room_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_Occupants_Admins1`
    FOREIGN KEY (`Admins_admins_id`)
    REFERENCES `logbuk`.`Admins` (`admins_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION
) ENGINE = InnoDB;

-- -----------------------------------------------------
-- Table `logbuk`.`Logs`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `logbuk`.`Logs`;

CREATE TABLE IF NOT EXISTS `logbuk`.`Logs` (
  `logs_id` INT NOT NULL AUTO_INCREMENT,
  `logs_time_in` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `logs_time_out` DATETIME NULL,
  `logs_whereabouts` TINYINT NOT NULL,
  `logs_is_late` TINYINT NOT NULL DEFAULT 0,
  `Occupants_occupants_id` CHAR(7) NOT NULL,
  `Occupants_Rooms_room_id` CHAR(4) NOT NULL,
  PRIMARY KEY (`logs_id`, `Occupants_occupants_id`, `Occupants_Rooms_room_id`),
  INDEX `fk_Logs_Occupants1_idx` (`Occupants_occupants_id` ASC, `Occupants_Rooms_room_id` ASC) VISIBLE,
  CONSTRAINT `chk_logs_time_order`
    CHECK (logs_time_out IS NULL OR logs_time_out > logs_time_in),
  CONSTRAINT `fk_Logs_Occupants1`
    FOREIGN KEY (`Occupants_occupants_id` , `Occupants_Rooms_room_id`)
    REFERENCES `logbuk`.`Occupants` (`occupants_id` , `Rooms_room_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION
) ENGINE = InnoDB;

-- -----------------------------------------------------
-- Table `logbuk`.`Settings`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `logbuk`.`Settings`;

CREATE TABLE IF NOT EXISTS `logbuk`.`Settings` (
  `settings_id` INT NOT NULL AUTO_INCREMENT,
  `settings_curfew_time` TIME NOT NULL DEFAULT '22:00:00',
  `settings_updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `Admins_admins_id` INT NOT NULL,
  PRIMARY KEY (`settings_id`, `Admins_admins_id`),
  INDEX `fk_Settings_Admins1_idx` (`Admins_admins_id` ASC) VISIBLE,
  CONSTRAINT `fk_Settings_Admins1`
    FOREIGN KEY (`Admins_admins_id`)
    REFERENCES `logbuk`.`Admins` (`admins_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION
) ENGINE = InnoDB;

SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
