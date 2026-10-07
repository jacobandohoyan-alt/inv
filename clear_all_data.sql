-- DELETES ALL SHOP DATA in the existing database `abuzodb`, so the real data can be entered.
-- Run in phpMyAdmin (SQL tab) with the database `abuzodb` selected.
--
-- Emptied  : products, suppliers, stock in, stock out, expenses
-- KEPT     : users (the accounts that can log in)
-- To remove the user accounts too, remove the "--" in front of the last TRUNCATE line.
-- Cannot be undone: export a backup first if you are not sure.

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `stock_in`;
TRUNCATE TABLE `stock_out`;
TRUNCATE TABLE `expenses`;
TRUNCATE TABLE `products`;
TRUNCATE TABLE `suppliers`;
-- TRUNCATE TABLE `users`;      -- then open registration.php to create the first Admin

SET FOREIGN_KEY_CHECKS = 1;
