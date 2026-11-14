-- Apply after the menu customization, employee, and promotion migrations.
-- Adds real option-scope menu entries for the legacy customization groups,
-- removes the temporary category and test promotions, and seeds inactive sample records.

UPDATE menu_items m
JOIN menu_categories c ON c.category_id = m.category_id
SET m.category_id = c.parent_id
WHERE c.name = 'Custom Category Test' AND c.parent_id IS NOT NULL;

DELETE FROM menu_categories WHERE name = 'Custom Category Test';

DELETE r FROM promotion_redemptions r
JOIN promotions p ON p.promo_id = r.promo_id
WHERE LOWER(p.name) LIKE '%test%' OR LOWER(COALESCE(p.promo_code, '')) LIKE 'test%';

DELETE c FROM promotion_claims c
JOIN promotions p ON p.promo_id = c.promo_id
WHERE LOWER(p.name) LIKE '%test%' OR LOWER(COALESCE(p.promo_code, '')) LIKE 'test%';

DELETE p FROM promotions p
WHERE LOWER(p.name) LIKE '%test%' OR LOWER(COALESCE(p.promo_code, '')) LIKE 'test%';

-- Options are selected by availability_scope, regardless of entry kind.
INSERT INTO menu_items (category_id, item_kind, availability_scope, name, description, price, is_available)
SELECT src.category_id, 'option', 'option_only', src.name, 'Available as a menu customization option.', src.price, 1
FROM (
  SELECT 13 AS category_id, 'Cheeseballs - Plain w/ Pizza Sauce (1pc)' AS name, 47.00 AS price UNION ALL
  SELECT 13, 'Cheeseballs - Snowcheese (1pc)', 47.00 UNION ALL
  SELECT 16, 'Kimchi', 47.00 UNION ALL
  SELECT 21, 'Regular Iced Tea', 0.00 UNION ALL
  SELECT 21, 'Large Iced Tea', 40.00 UNION ALL
  SELECT 20, 'Caramel Snow Ice Coffee Drink Upgrade', 95.00 UNION ALL
  SELECT 20, 'Chocolate Snow Ice Coffee Drink Upgrade', 95.00 UNION ALL
  SELECT 15, 'Kimchi Rice Upgrade', 51.00 UNION ALL
  SELECT 10, 'Add on Bulgogi Soup', 68.00 UNION ALL
  SELECT 10, 'Regular', 0.00 UNION ALL
  SELECT 10, 'Mild Spicy', 0.00
) AS src
WHERE NOT EXISTS (
  SELECT 1 FROM menu_items existing
  WHERE existing.name = src.name AND existing.availability_scope IN ('option_only', 'item_and_option')
);

SET @sample_admin_id = (SELECT user_id FROM user_credentials WHERE role = 'admin' AND is_active = 1 ORDER BY user_id LIMIT 1);

INSERT INTO promotions
 (name, description, mechanics, promo_code, is_platform_voucher, eligibility_scope, discount_type, discount_value, max_discount, minimum_spend, starts_at, ends_at, channel, usage_limit, status, created_by)
VALUES
 ('Sample Promo 01 - Chicken Day', 'Inactive sample offer for preview and testing.', 'Sample record only - activate after replacing its details.', 'SAMPLEPROMO01', 0, 'all', 'percent', 10, 100, 300, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Promo 02 - Group Feast', 'Inactive sample offer for preview and testing.', 'Sample record only - activate after replacing its details.', 'SAMPLEPROMO02', 0, 'all', 'percent', 15, 200, 700, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Promo 03 - Rice Bowl Treat', 'Inactive sample offer for preview and testing.', 'Sample record only - activate after replacing its details.', 'SAMPLEPROMO03', 0, 'all', 'percent', 10, 80, 250, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Promo 04 - Seafood Special', 'Inactive sample offer for preview and testing.', 'Sample record only - activate after replacing its details.', 'SAMPLEPROMO04', 0, 'all', 'percent', 12, 120, 350, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Promo 05 - Sandwich Combo', 'Inactive sample offer for preview and testing.', 'Sample record only - activate after replacing its details.', 'SAMPLEPROMO05', 0, 'all', 'percent', 10, 75, 200, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Promo 06 - Noodle Night', 'Inactive sample offer for preview and testing.', 'Sample record only - activate after replacing its details.', 'SAMPLEPROMO06', 0, 'all', 'percent', 15, 100, 300, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Promo 07 - Side Dish Bonus', 'Inactive sample offer for preview and testing.', 'Sample record only - activate after replacing its details.', 'SAMPLEPROMO07', 0, 'all', 'percent', 10, 50, 150, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Promo 08 - Cafe Koreano', 'Inactive sample offer for preview and testing.', 'Sample record only - activate after replacing its details.', 'SAMPLEPROMO08', 0, 'all', 'percent', 12, 60, 180, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Promo 09 - Dessert Break', 'Inactive sample offer for preview and testing.', 'Sample record only - activate after replacing its details.', 'SAMPLEPROMO09', 0, 'all', 'percent', 10, 70, 200, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Promo 10 - Weekend Table', 'Inactive sample offer for preview and testing.', 'Sample record only - activate after replacing its details.', 'SAMPLEPROMO10', 0, 'all', 'percent', 20, 250, 900, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Voucher 01 - Welcome', 'Inactive sample voucher for preview and testing.', 'Sample record only - activate after replacing its details.', NULL, 1, 'all', 'fixed', 50, NULL, 250, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Voucher 02 - Chicken Treat', 'Inactive sample voucher for preview and testing.', 'Sample record only - activate after replacing its details.', NULL, 1, 'all', 'fixed', 75, NULL, 350, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Voucher 03 - Feast Saver', 'Inactive sample voucher for preview and testing.', 'Sample record only - activate after replacing its details.', NULL, 1, 'all', 'fixed', 150, NULL, 700, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Voucher 04 - Bowl Discount', 'Inactive sample voucher for preview and testing.', 'Sample record only - activate after replacing its details.', NULL, 1, 'all', 'fixed', 40, NULL, 200, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Voucher 05 - Seafood Saver', 'Inactive sample voucher for preview and testing.', 'Sample record only - activate after replacing its details.', NULL, 1, 'all', 'fixed', 60, NULL, 300, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Voucher 06 - Sandwich Deal', 'Inactive sample voucher for preview and testing.', 'Sample record only - activate after replacing its details.', NULL, 1, 'all', 'fixed', 35, NULL, 180, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Voucher 07 - Noodle Night', 'Inactive sample voucher for preview and testing.', 'Sample record only - activate after replacing its details.', NULL, 1, 'all', 'fixed', 50, NULL, 250, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Voucher 08 - Side Bonus', 'Inactive sample voucher for preview and testing.', 'Sample record only - activate after replacing its details.', NULL, 1, 'all', 'fixed', 25, NULL, 150, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Voucher 09 - Cafe Treat', 'Inactive sample voucher for preview and testing.', 'Sample record only - activate after replacing its details.', NULL, 1, 'all', 'fixed', 40, NULL, 180, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id),
 ('Sample Voucher 10 - Weekend Table', 'Inactive sample voucher for preview and testing.', 'Sample record only - activate after replacing its details.', NULL, 1, 'all', 'fixed', 200, NULL, 1000, '2026-12-01 00:00:00', '2027-12-31 23:59:59', 'all', NULL, 'draft', @sample_admin_id);

-- These staff placeholders are intentionally deactivated and use reserved .invalid emails.
INSERT INTO user_info (first_name, middle_name, last_name, home_addr, mobileno)
SELECT src.first_name, NULL, src.last_name, 'Inactive sample employee record', src.mobileno
FROM (
  SELECT 'Juan' AS first_name, 'Dela Cruz' AS last_name, '09999999001' AS mobileno UNION ALL
  SELECT 'Maria', 'Santos', '09999999002' UNION ALL
  SELECT 'Jose', 'Reyes', '09999999003' UNION ALL
  SELECT 'Ana', 'Garcia', '09999999004' UNION ALL
  SELECT 'Pedro', 'Bautista', '09999999005'
) AS src
WHERE NOT EXISTS (SELECT 1 FROM user_info i WHERE i.mobileno = src.mobileno);

INSERT INTO user_credentials (user_id, email_addr, password, role, is_active)
SELECT i.user_id, src.email_addr, SHA2(UUID(), 256), 'staff', 0
FROM (
  SELECT '09999999001' AS mobileno, 'juan.delacruz@sample.invalid' AS email_addr UNION ALL
  SELECT '09999999002', 'maria.santos@sample.invalid' UNION ALL
  SELECT '09999999003', 'jose.reyes@sample.invalid' UNION ALL
  SELECT '09999999004', 'ana.garcia@sample.invalid' UNION ALL
  SELECT '09999999005', 'pedro.bautista@sample.invalid'
) AS src
JOIN user_info i ON i.mobileno = src.mobileno
WHERE NOT EXISTS (SELECT 1 FROM user_credentials c WHERE c.email_addr = src.email_addr);
