-- Categorize customization option entries for filtering in menu item editor.
-- Existing options default to Flavor and can be recategorized in menu-item-edit.php.
ALTER TABLE menu_items
  ADD COLUMN option_type ENUM('Flavor', 'Sides', 'Drinks', 'Upgrades') NOT NULL DEFAULT 'Flavor' AFTER availability_scope;
