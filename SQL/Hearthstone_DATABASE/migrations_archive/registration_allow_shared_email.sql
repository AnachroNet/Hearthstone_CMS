-- Allow multiple pending signups / accounts to share the same email when
-- Admin → Settings → General → "Allow multiple accounts to use the same email" is enabled.
-- Saving that setting in the admin panel also runs this change automatically when possible.

USE `hearthstone_site`;

ALTER TABLE `pending_accounts` DROP INDEX `unique_email`;

-- Store which account requested a reset when several accounts share one email.
-- Skip if the column already exists (Admin → General runs this automatically when enabling shared email).
ALTER TABLE `password_resets`
  ADD COLUMN `username` varchar(32) DEFAULT NULL AFTER `email`;
