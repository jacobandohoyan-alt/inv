-- Adds the unit to every product (the system also does this automatically the first time it runs).
-- Run ONCE in phpMyAdmin (database: abuzodb) only if the Products page shows a "Unknown column 'unit'" error.
ALTER TABLE products ADD COLUMN unit VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER category;

-- Optional: give your old products a unit that fits them
-- UPDATE products SET unit = 'kilo'  WHERE sku IN ('TEA-001','TAP-001','MLK-001');
-- UPDATE products SET unit = 'pack'  WHERE sku = 'CFE-001';

-- Optional: move the old categories to the new list (Kitchen, Bar Counter, Necessities, Packaging)
-- UPDATE products SET category = 'Kitchen'      WHERE category = 'Ingredients';
-- UPDATE products SET category = 'Bar Counter'  WHERE category = 'Coffee';
