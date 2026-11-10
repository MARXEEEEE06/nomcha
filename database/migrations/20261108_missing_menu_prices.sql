-- Set category-based estimates for available items that were missing prices.
UPDATE menu_items
SET price = CASE item_id
    WHEN 26 THEN 1299.00 -- Feast Good for 4-6
    WHEN 27 THEN 999.00  -- Chicken Good for 4
    WHEN 28 THEN 749.00  -- Chicken Good for 3
    WHEN 40 THEN 299.00  -- Fish & Chapchae
    WHEN 58 THEN 104.00  -- Plain Fish Cake Fries
    WHEN 59 THEN 114.00  -- Snow Cheese Fish Cake Fries
    WHEN 63 THEN 51.00   -- Kimchi Coleslaw
END
WHERE item_id IN (26, 27, 28, 40, 58, 59, 63)
  AND price IS NULL;
