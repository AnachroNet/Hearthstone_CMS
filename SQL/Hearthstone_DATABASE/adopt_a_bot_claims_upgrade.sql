-- Run once on hearthstone_site as a MySQL user with ALTER privilege (app user may not have it).
-- Adds async adoption progress columns and the processing status.

ALTER TABLE `adopt_a_bot_claims`
  MODIFY `status` enum('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
  ADD COLUMN `progress_message` varchar(500) DEFAULT NULL AFTER `bot_character_name`,
  ADD COLUMN `progress_log` mediumtext DEFAULT NULL AFTER `progress_message`;
