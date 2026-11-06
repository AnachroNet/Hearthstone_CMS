-- Rename shop_items category Stuff -> Gear (sahtout_site). Run once.
-- Drop the check first: updating to 'Gear' fails while the old constraint still lists 'Stuff' only.

ALTER TABLE `shop_items`
  DROP CHECK `chk_shop_items_category`;

UPDATE `shop_items`
SET `category` = 'Gear'
WHERE `category` = 'Stuff';

ALTER TABLE `shop_items`
  ADD CONSTRAINT `chk_shop_items_category`
  CHECK (`category` IN ('Mount', 'Pet', 'Gold', 'Service', 'Gear', 'Set'));
