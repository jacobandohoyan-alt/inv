-- Removes the Sales feature from the EXISTING database `abuzodb`.
-- Run ONCE in phpMyAdmin (SQL tab) with the database `abuzodb` selected.
--
-- Drops   : sales, sale_items, refunds   (the Sales page no longer exists)
-- Drops   : products.selling_price       (we only manage inventory, not the products sold)
-- Keeps   : products.cost_price          (now the "Cost Price" of ONE unit, shown on the Products page)
-- Cannot be undone: export a backup first if you are not sure.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `refunds`;
DROP TABLE IF EXISTS `sale_items`;
DROP TABLE IF EXISTS `sales`;

SET FOREIGN_KEY_CHECKS = 1;

-- (if this line says "Can't DROP 'selling_price'", the column is already gone - that is fine)
ALTER TABLE `products` DROP COLUMN `selling_price`;
