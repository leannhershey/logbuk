-- -------------------------------------------------------------
-- LogBUk demo data for the Occupants screen
-- Run once, after sql-ver-002.sql:
--   mysql -u root -p logbuk_db < db/seed-occupants.sql
-- -------------------------------------------------------------
USE `logbuk_db`;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE `Logs`;
TRUNCATE TABLE `Occupants`;
TRUNCATE TABLE `Settings`;
TRUNCATE TABLE `Admins`;
TRUNCATE TABLE `Rooms`;
SET FOREIGN_KEY_CHECKS = 1;

-- Rooms: floor 01, 02, 03 (room_id = <floor><2-digit number>, per the ID rule)
INSERT INTO `Rooms` (`room_id`, `room_capacity`) VALUES
  ('0101', 4), ('0102', 4), ('0103', 4),
  ('0201', 4), ('0202', 4),
  ('0301', 4);

-- One admin (log in with passcode: admin123)
-- bcrypt hash of 'admin123' -- change it before deploying.
INSERT INTO `Admins` (`admins_passcode`) VALUES
  ('$2y$10$FHWRlhH5pOvMeFGMcZMJcuCCyeO51Wl//Ho4YZoPieVsFBN.cw9NK');

INSERT INTO `Settings` (`settings_curfew_time`, `Admins_admins_id`) VALUES ('22:00:00', 1);

-- 9 occupants, 5 per page  -> matches "Showing 1-5 of 9 occupants"
-- occupants_last_seen_at is filled from each occupant's log activity, so the
-- 2-week inactivity rule has an honest baseline on day one.
INSERT INTO `Occupants`
  (`occupants_id`, `occupants_f_name`, `occupants_m_name`, `occupants_l_name`, `occupants_s_name`,
   `occupants_program`, `occupants_contact_number`, `occupants_loc_status`, `occupants_reg_status`,
   `occupants_reg_at`, `occupants_last_seen_at`, `Rooms_room_id`, `Admins_admins_id`)
VALUES
  ('01-0001', 'Rene',   'Villanueva', 'Baterbonia', 'Jr.', 'BS Information Technology', '09171234567', 'Inside',  'Active', '2026-06-05 09:00:00', '2026-11-21 22:15:00', '0101', 1),
  ('01-0002', 'Mariah', 'S.',         'Deborah',    NULL,  'BS Computer Science',       '09981234567', 'Outside', 'Active', '2026-06-05 08:15:00', '2026-06-05 08:15:00', '0101', 1),
  ('01-0003', 'Jeo',    'O.',         'Bak',        NULL,  'BS Biology',                '09191234567', 'Outside', 'Active', '2026-07-10 10:00:00', '2026-11-21 21:00:00', '0102', 1),
  ('02-0001', 'Cris',   'M.',         'Hippers',    NULL,  'BS Civil Engineering',      '09201234567', 'Inside',  'Active', '2026-07-12 08:30:00', '2026-11-21 21:45:00', '0201', 1),
  ('02-0002', 'Sanya',  'L.',         'Lopez',      NULL,  'BS Accountancy',            '09181234567', 'Outside', 'Active', '2026-08-01 13:00:00', '2026-11-20 21:30:00', '0201', 1),
  ('02-0003', 'Daniela','K.',         'BK',         NULL,  'BS Nursing',                '09361234567', 'Outside', 'Active', '2026-08-01 14:20:00', '2026-11-20 07:10:00', '0202', 1),
  ('03-0001', 'Dinorobong', NULL,     'Tubo',       NULL,  'BS Architecture',           '09471234567', 'Inside',  'Active', '2026-08-15 09:45:00', '2026-11-21 22:10:00', '0301', 1),
  ('03-0002', 'Paolo',  'R.',         'Santos',     NULL,  'BS Electrical Engineering', '09561234567', 'Outside', 'Active', '2026-09-02 11:10:00', '2026-09-02 11:10:00', '0301', 1),
  -- Ana is Inactive, so she has no room (chk_occupants_status_room requires it)
  ('03-0003', 'Ana',    'B.',         'Reyes',      NULL,  'BS Education',              '09651234567', 'Outside', 'Inactive','2026-09-02 11:30:00', '2026-09-02 11:30:00', NULL,   1);

-- Logs (whereabouts: 1=Canteen 2=Library 3=Class 4=Home 5=Others -- see settings note)
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
