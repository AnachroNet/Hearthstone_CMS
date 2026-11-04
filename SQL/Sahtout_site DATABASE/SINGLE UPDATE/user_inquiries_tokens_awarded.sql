-- Track shop tokens granted when staff respond to Contact Us inquiries.

ALTER TABLE `user_inquiries`
  ADD COLUMN `tokens_awarded` int unsigned NOT NULL DEFAULT '0' AFTER `response_text`;
