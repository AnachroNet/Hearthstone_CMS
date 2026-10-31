-- Remove vote-era "points" currency; shop uses tokens only.
-- Merges legacy point_cost into token_cost before dropping columns.

UPDATE `shop_items`
SET `token_cost` = `token_cost` + `point_cost`
WHERE `point_cost` > 0;

ALTER TABLE `shop_items` DROP COLUMN `point_cost`;

ALTER TABLE `user_currencies` DROP COLUMN `points`;

ALTER TABLE `purchases` DROP COLUMN `point_cost`;
