-- STEP 1 of 2: add PSGC code columns to user_addresses and preview the match.
-- Run AFTER psgc_data.sql. Non-destructive: only adds nullable columns, then SELECTs.
-- Back up nomcha_db first (export from phpMyAdmin).
--
-- Rollback (if ever needed):
--   ALTER TABLE `user_addresses` DROP COLUMN `region_code`, DROP COLUMN `province_code`,
--     DROP COLUMN `municipality_code`, DROP COLUMN `barangay_code`;
--
-- Design notes:
--   * Existing name columns (province, municipality, barangay) are kept as a snapshot.
--     The app fills them from the codes server-side, so existing displays keep working.
--   * No foreign keys to psgc_* on purpose: PSGC is reloaded quarterly (see psgc_data.sql).
--     The app validates the code chain instead (includes/psgc_helpers.php).
--   * NULL codes = legacy free-text address; the UI asks the user to re-select it.

ALTER TABLE `user_addresses`
  ADD COLUMN IF NOT EXISTS `region_code` char(10) DEFAULT NULL AFTER `street_address`,
  ADD COLUMN IF NOT EXISTS `province_code` char(10) DEFAULT NULL AFTER `province`,
  ADD COLUMN IF NOT EXISTS `municipality_code` char(10) DEFAULT NULL AFTER `municipality`,
  ADD COLUMN IF NOT EXISTS `barangay_code` char(10) DEFAULT NULL AFTER `barangay`;

-- Match preview (read-only). Names are compared case-insensitively. "Sta."/"Sto." are treated as
-- "Santa"/"Santo" (PSGC abbreviates them). On the municipality, a leading "City of" /
-- "Municipality of" and a trailing " City" are also ignored.
-- result = WILL MATCH      -> STEP 2 will fill the codes and official names
-- result = NO MATCH / AMBIGUOUS -> codes stay NULL; user re-selects in the dropdowns
SELECT a.address_id, a.user_id,
       a.province, a.municipality, a.barangay,
       COUNT(b.psgc_code) AS matches,
       MIN(p.name) AS official_province,
       MIN(l.name) AS official_municipality,
       MIN(b.name) AS official_barangay,
       CASE COUNT(b.psgc_code)
         WHEN 1 THEN 'WILL MATCH'
         WHEN 0 THEN 'NO MATCH - user must re-select'
         ELSE 'AMBIGUOUS - user must re-select'
       END AS result
FROM `user_addresses` a
LEFT JOIN `psgc_provinces` p ON p.name = a.province
LEFT JOIN `psgc_localities` l ON l.province_code = p.psgc_code
     AND TRIM(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(LOWER(l.name), '(^| )sta\\.? ', '\\1santa '), '(^| )sto\\.? ', '\\1santo '), '^(city of|municipality of) +| +city$', ''))
       = TRIM(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(LOWER(a.municipality), '(^| )sta\\.? ', '\\1santa '), '(^| )sto\\.? ', '\\1santo '), '^(city of|municipality of) +| +city$', ''))
LEFT JOIN `psgc_barangays` b ON b.locality_code = l.psgc_code
     AND TRIM(REGEXP_REPLACE(REGEXP_REPLACE(LOWER(b.name), '(^| )sta\\.? ', '\\1santa '), '(^| )sto\\.? ', '\\1santo '))
       = TRIM(REGEXP_REPLACE(REGEXP_REPLACE(LOWER(a.barangay), '(^| )sta\\.? ', '\\1santa '), '(^| )sto\\.? ', '\\1santo '))
GROUP BY a.address_id
ORDER BY a.address_id;
