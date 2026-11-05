-- Token gift letter quests (18270–18279): StartItemCount = quest_template_addon.ProvidedItemCount.
-- UPDATE alone does nothing when no addon row exists; this INSERTs from quest_template.

INSERT INTO quest_template_addon (ID, ProvidedItemCount, SpecialFlags)
SELECT q.ID, 1, 1
FROM quest_template q
WHERE q.ID BETWEEN 18270 AND 18279
ON DUPLICATE KEY UPDATE
  ProvidedItemCount = 1,
  SpecialFlags = 1,
  PrevQuestID = 0,
  NextQuestID = 0,
  ExclusiveGroup = 0,
  BreadcrumbForQuestId = 0;

-- Sanity check (should return rows with ProvidedItemCount = 1):
-- SELECT q.ID, q.StartItem, a.ProvidedItemCount, a.SpecialFlags
-- FROM quest_template q
-- LEFT JOIN quest_template_addon a ON a.ID = q.ID
-- WHERE q.ID BETWEEN 18270 AND 18279;
