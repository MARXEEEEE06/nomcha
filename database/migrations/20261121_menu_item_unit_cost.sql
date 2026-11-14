-- Optional per-unit cost for gross margin reporting. Existing prices remain unchanged.
ALTER TABLE menu_items
  ADD COLUMN unit_cost DECIMAL(10,2) DEFAULT NULL AFTER price;
