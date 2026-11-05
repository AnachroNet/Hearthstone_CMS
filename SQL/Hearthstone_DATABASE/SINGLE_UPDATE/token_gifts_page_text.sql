-- Track world page_text chain for readable token gift letters.

ALTER TABLE `token_gifts`
  ADD COLUMN `page_text_id` int unsigned NOT NULL DEFAULT '0' AFTER `quest_id`,
  ADD COLUMN `page_text_page_count` tinyint unsigned NOT NULL DEFAULT '0' AFTER `page_text_id`;
