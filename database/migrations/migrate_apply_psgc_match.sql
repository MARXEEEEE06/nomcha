-- STEP 2 of 2: fill PSGC codes (and official names) for rows that match exactly one barangay.
-- Run ONLY after reviewing the STEP 1 preview. Rows that are NO MATCH / AMBIGUOUS are untouched.
-- Same matching rules as the STEP 1 preview.

START TRANSACTION;

UPDATE `user_addresses` a
JOIN (
  SELECT a2.address_id,
         MIN(p.region_code) AS region_code,
         MIN(p.psgc_code)   AS province_code,
         MIN(l.psgc_code)   AS municipality_code,
         MIN(b.psgc_code)   AS barangay_code,
         MIN(p.name)        AS province,
         MIN(l.name)        AS municipality,
         MIN(b.name)        AS barangay
  FROM `user_addresses` a2
  JOIN `psgc_provinces` p ON p.name = a2.province
  JOIN `psgc_localities` l ON l.province_code = p.psgc_code
       AND TRIM(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(LOWER(l.name), '(^| )sta\\.? ', '\\1santa '), '(^| )sto\\.? ', '\\1santo '), '^(city of|municipality of) +| +city$', ''))
         = TRIM(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(LOWER(a2.municipality), '(^| )sta\\.? ', '\\1santa '), '(^| )sto\\.? ', '\\1santo '), '^(city of|municipality of) +| +city$', ''))
  JOIN `psgc_barangays` b ON b.locality_code = l.psgc_code
       AND TRIM(REGEXP_REPLACE(REGEXP_REPLACE(LOWER(b.name), '(^| )sta\\.? ', '\\1santa '), '(^| )sto\\.? ', '\\1santo '))
         = TRIM(REGEXP_REPLACE(REGEXP_REPLACE(LOWER(a2.barangay), '(^| )sta\\.? ', '\\1santa '), '(^| )sto\\.? ', '\\1santo '))
  GROUP BY a2.address_id
  HAVING COUNT(*) = 1
) m ON m.address_id = a.address_id
SET a.region_code        = m.region_code,
    a.province_code      = m.province_code,
    a.municipality_code  = m.municipality_code,
    a.barangay_code      = m.barangay_code,
    a.province           = m.province,
    a.municipality       = m.municipality,
    a.barangay           = m.barangay;

COMMIT;

-- Result check: coded rows vs rows still needing re-selection
SELECT SUM(barangay_code IS NOT NULL) AS coded, SUM(barangay_code IS NULL) AS needs_reselect
FROM `user_addresses`;
