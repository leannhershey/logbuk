USE `logbuk_db`;

SELECT '--- T1: status column is still the ENUM with DEFAULT Active ---' AS test;
-- information_schema reports an ENUM default WITH its quotes ('Active'),
-- so both forms are accepted here.
SELECT COLUMN_TYPE, COLUMN_DEFAULT,
       IF(COLUMN_TYPE = "enum('Active','Inactive')"
          AND TRIM(BOTH '''' FROM COLUMN_DEFAULT) = 'Active',
          'PASS', 'FAIL') AS result
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = 'logbuk_db'
   AND TABLE_NAME   = 'Occupants'
   AND COLUMN_NAME  = 'occupants_reg_status';

SELECT '--- T2: existing Active/Inactive values survived the patch ---' AS test;
SELECT occupants_id, occupants_reg_status AS residency,
       CASE
         WHEN occupants_id = '01-0001' AND occupants_reg_status = 'Active'   THEN 'PASS'
         WHEN occupants_id = '03-0003' AND occupants_reg_status = 'Inactive' THEN 'PASS'
         ELSE 'FAIL'
       END AS result
  FROM Occupants
 WHERE occupants_id IN ('01-0001', '03-0003')
 ORDER BY occupants_id;

SELECT '--- T3: last_seen backfilled from log history ---' AS test;
SELECT occupants_id, occupants_last_seen_at,
       IF(occupants_last_seen_at IS NOT NULL, 'PASS', 'FAIL') AS result
  FROM Occupants ORDER BY occupants_id;

SELECT '--- T4: trigger refreshes last_seen on a new time-in ---' AS test;
UPDATE Occupants SET occupants_last_seen_at = '2020-01-01 00:00:00' WHERE occupants_id = '01-0002';
INSERT INTO Logs (logs_time_in, logs_whereabouts, Occupants_occupants_id, Occupants_Rooms_room_id)
VALUES (NOW(), 1, '01-0002', '0101');
SELECT occupants_id, occupants_last_seen_at,
       IF(occupants_last_seen_at > '2020-01-02', 'PASS', 'FAIL') AS result
  FROM Occupants WHERE occupants_id = '01-0002';
DELETE FROM Logs WHERE Occupants_occupants_id = '01-0002' AND logs_time_out IS NULL;

SELECT '--- T5: 2-week rule renders a stale occupant Inactive, frees the room ---' AS test;
UPDATE Occupants SET occupants_last_seen_at = DATE_SUB(NOW(), INTERVAL 40 DAY),
                     occupants_loc_status = 'Outside'
 WHERE occupants_id = '01-0003';
UPDATE Occupants SET occupants_last_seen_at = DATE_SUB(NOW(), INTERVAL 1 DAY)
 WHERE occupants_id = '01-0001';

CALL sp_auto_deactivate_inactive(NULL);

SELECT occupants_id,
       occupants_reg_status AS residency,
       IFNULL(occupants_deactivate_source, '—') AS source,
       IFNULL(Rooms_room_id, '—') AS room,
       CASE
         WHEN occupants_id = '01-0003' AND occupants_reg_status = 'Inactive'
              AND occupants_deactivate_source = 'inactivity'
              AND Rooms_room_id IS NULL                    THEN 'PASS'
         WHEN occupants_id = '01-0001' AND occupants_reg_status = 'Active' THEN 'PASS'
         ELSE 'FAIL'
       END AS result
  FROM Occupants
 WHERE occupants_id IN ('01-0001', '01-0003');

SELECT '--- T6: an occupant who is currently Inside is never auto-deactivated ---' AS test;
UPDATE Occupants SET occupants_last_seen_at = DATE_SUB(NOW(), INTERVAL 60 DAY),
                     occupants_loc_status = 'Inside'
 WHERE occupants_id = '02-0001';
INSERT INTO Logs (logs_time_in, logs_whereabouts, Occupants_occupants_id, Occupants_Rooms_room_id)
VALUES (DATE_SUB(NOW(), INTERVAL 3 DAY), 1, '02-0001', '0201');

CALL sp_auto_deactivate_inactive(NULL);

SELECT occupants_id, occupants_reg_status AS residency,
       IF(occupants_reg_status = 'Active', 'PASS', 'FAIL') AS result
  FROM Occupants WHERE occupants_id = '02-0001';
DELETE FROM Logs WHERE Occupants_occupants_id = '02-0001' AND logs_time_out IS NULL;

SELECT '--- T7: reactivation restores Active + a room ---' AS test;
UPDATE Occupants SET occupants_reg_status = 'Active',
                     Rooms_room_id = '0103',
                     occupants_deactivated_at = NULL,
                     occupants_deactivate_reason = NULL,
                     occupants_deactivate_source = NULL
 WHERE occupants_id = '01-0003';
SELECT occupants_id, occupants_reg_status AS residency, Rooms_room_id AS room,
       IF(occupants_reg_status = 'Active' AND Rooms_room_id = '0103', 'PASS', 'FAIL') AS result
  FROM Occupants WHERE occupants_id = '01-0003';

SELECT '--- T8: view exposes the word, not a number ---' AS test;
SELECT occupants_id, residency,
       IF(residency IN ('Active','Inactive'), 'PASS', 'FAIL') AS result
  FROM vw_occupants_directory ORDER BY occupants_id;

SELECT '--- T9: manual procedure writes the same bookkeeping ---' AS test;
CALL sp_deactivate_occupant('02-0002', 'Test manual deactivation', 1);
SELECT occupants_id, occupants_reg_status AS residency,
       occupants_deactivate_source, occupants_deactivate_reason,
       IF(occupants_reg_status = 'Inactive' AND occupants_deactivate_source = 'manual'
          AND Rooms_room_id IS NULL, 'PASS', 'FAIL') AS result
  FROM Occupants WHERE occupants_id = '02-0002';

SELECT '--- T10: CHECK constraint still refuses Active with no room ---' AS test;
-- expected: ERROR 4025. Run by hand:
--   UPDATE Occupants SET occupants_reg_status='Active', Rooms_room_id=NULL WHERE occupants_id='02-0002';
SELECT 'run T10 by hand: expect ERROR 4025 on that statement' AS note;