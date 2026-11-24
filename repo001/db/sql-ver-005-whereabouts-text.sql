-- =====================================================================
--  OPTIONAL patch 5 — keep the exact whereabouts text the occupant typed
--
--  Logs.logs_whereabouts stays the 1-5 category code (Canteen / Library /
--  Class / Home / Others) so the rest of the system is unchanged. This adds
--  a nullable column that also keeps the typed text ("BU Library").
--
--  api/occupant_logbook.php detects the column by itself: it works with or
--  without this patch. Run it ONCE, after logbuk.sql.
-- =====================================================================
USE `logbuk_db`;

ALTER TABLE `Logs`
  ADD COLUMN `logs_whereabouts_text` VARCHAR(100) NULL AFTER `logs_whereabouts`;
