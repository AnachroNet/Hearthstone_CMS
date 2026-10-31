-- Remove voting schema from an existing sahtout_site database.
-- Safe to run if tables were already removed.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `vote_log`;
DROP TABLE IF EXISTS `vote_log_history`;
DROP TABLE IF EXISTS `vote_sites`;

SET FOREIGN_KEY_CHECKS = 1;
