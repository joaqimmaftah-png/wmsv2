-- =============================================================================
-- Migration 0011: Restore Verbatim Asset Register Machine IDs (`registration_no`)
-- =============================================================================
-- Restores the exact original `registration_no` strings from the master Asset
-- Register document (`EX 006`, `C-HEADT 007`, `C-HEADT 008`) on any database
-- that previously applied migration 0008, keeping all integer `equipment.id`
-- foreign keys (specs, meter readings, stock movements, backlogs, WOs, PMs,
-- inspections) 100% intact.
-- =============================================================================

SET NAMES utf8mb4;

-- Align `C-HV-EX 006` -> `EX 006` safely (handles both normal upgrade and pre-existing `EX 006`)
UPDATE `equipment` e_old
LEFT JOIN `equipment` e_new ON e_new.`registration_no` = 'EX 006'
SET e_old.`registration_no` = 'EX 006'
WHERE e_old.`registration_no` = 'C-HV-EX 006' AND e_new.`id` IS NULL;

DELETE e_dup FROM `equipment` e_dup
INNER JOIN `equipment` e_keep ON e_keep.`registration_no` = 'EX 006'
WHERE e_dup.`registration_no` = 'C-HV-EX 006';

-- Align `C-HE-ADT 007` -> `C-HEADT 007` safely (handles both normal upgrade and pre-existing `C-HEADT 007`)
UPDATE `equipment` e_old
LEFT JOIN `equipment` e_new ON e_new.`registration_no` = 'C-HEADT 007'
SET e_old.`registration_no` = 'C-HEADT 007'
WHERE e_old.`registration_no` = 'C-HE-ADT 007' AND e_new.`id` IS NULL;

DELETE e_dup FROM `equipment` e_dup
INNER JOIN `equipment` e_keep ON e_keep.`registration_no` = 'C-HEADT 007'
WHERE e_dup.`registration_no` = 'C-HE-ADT 007';

-- Align `C-HE-ADT 008` -> `C-HEADT 008` safely (handles both normal upgrade and pre-existing `C-HEADT 008`)
UPDATE `equipment` e_old
LEFT JOIN `equipment` e_new ON e_new.`registration_no` = 'C-HEADT 008'
SET e_old.`registration_no` = 'C-HEADT 008'
WHERE e_old.`registration_no` = 'C-HE-ADT 008' AND e_new.`id` IS NULL;

DELETE e_dup FROM `equipment` e_dup
INNER JOIN `equipment` e_keep ON e_keep.`registration_no` = 'C-HEADT 008'
WHERE e_dup.`registration_no` = 'C-HE-ADT 008';
