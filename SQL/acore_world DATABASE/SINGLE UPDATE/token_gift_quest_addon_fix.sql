-- Token gift letter quests (18270–18279): StartItemCount comes from ProvidedItemCount.
UPDATE quest_template_addon
SET ProvidedItemCount = 1,
    SpecialFlags = 1,
    PrevQuestID = 0,
    NextQuestID = 0,
    ExclusiveGroup = 0,
    BreadcrumbForQuestId = 0
WHERE ID BETWEEN 18270 AND 18279;

-- Ensure a row exists for each published gift quest (run per ID if needed):
-- INSERT INTO quest_template_addon (ID, ProvidedItemCount, SpecialFlags) VALUES (18270, 1, 1)
-- ON DUPLICATE KEY UPDATE ProvidedItemCount = 1, SpecialFlags = 1;
