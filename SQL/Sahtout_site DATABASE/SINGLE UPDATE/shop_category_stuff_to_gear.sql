-- Rename shop_items category Stuff -> Gear (sahtout_site). Run once.

UPDATE `shop_items`
SET `category` = 'Gear'
WHERE `category` = 'Stuff';

ALTER TABLE `shop_items`
  DROP CHECK `chk_shop_items_category`;

ALTER TABLE `shop_items`
  ADD CONSTRAINT `chk_shop_items_category`
  CHECK (`category` IN ('Mount', 'Pet', 'Gold', 'Service', 'Gear', 'Set'));
