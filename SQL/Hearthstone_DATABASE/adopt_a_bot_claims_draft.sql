-- Optional: allow browsing bot pool before token charge (draft claims).
ALTER TABLE `adopt_a_bot_claims`
  MODIFY `purchase_id` int unsigned DEFAULT NULL,
  MODIFY `status` enum('draft','pending','processing','completed','cancelled') NOT NULL DEFAULT 'draft';
