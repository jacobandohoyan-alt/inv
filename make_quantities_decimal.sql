-- Lets products counted in KILO have decimal quantities (2.5 kilo).
-- The system does this automatically the first time Products / Stock In / Out opens.
-- Run ONCE in phpMyAdmin (database: abuzodb) only if those pages show a "Quantity must be..." error
-- or your MySQL user is not allowed to change tables. Existing quantities are kept.
ALTER TABLE products  MODIFY stock_qty     DECIMAL(12,3) NOT NULL DEFAULT 0.000;
ALTER TABLE products  MODIFY reorder_level DECIMAL(12,3) NOT NULL DEFAULT 10.000;
ALTER TABLE stock_in  MODIFY quantity      DECIMAL(12,3) NOT NULL;
ALTER TABLE stock_out MODIFY quantity      DECIMAL(12,3) NOT NULL;
