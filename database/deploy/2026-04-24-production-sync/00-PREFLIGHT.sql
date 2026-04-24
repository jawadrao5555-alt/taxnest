-- ==========================================================================
-- PREFLIGHT — schema sanity checks BEFORE running migration
-- Run this FIRST. If any check fails, STOP and report.
-- ==========================================================================

-- 1) ZIA must exist on production (id=7)
SELECT 'ZIA company exists' AS check_name, COUNT(*) AS result
FROM companies WHERE id=7 AND name LIKE '%ZIA%';
-- expected: result=1

-- 2) PUNJAB PLUS must NOT already exist (no duplicate)
SELECT 'PUNJAB PLUS not yet present (NTN)' AS check_name, COUNT(*) AS result
FROM companies WHERE ntn='1687011-5' OR id=17;
-- expected: result=0

-- 3) Email must be free
SELECT 'PUNJAB PLUS email free' AS check_name, COUNT(*) AS result
FROM users WHERE email='hassankhan21500@gmail.com' OR id=18;
-- expected: result=0

-- 4) ZIA invoice numbers must NOT already exist on prod
SELECT 'ZIA invoice numbers free' AS check_name, COUNT(*) AS result
FROM invoices WHERE invoice_number IN (
  'INV-2026-000593','INV-2026-000595','INV-2026-000597',
  'INV-2026-000598','INV-2026-000599','INV-2026-000600','INV-2026-000601',
  'INV-2026-000602','INV-2026-000603','INV-2026-000604','INV-2026-000605',
  'INV-2026-000606','INV-2026-000607','INV-2026-000608','INV-2026-000609',
  'INV-2026-000610','INV-2026-000611','INV-2026-000612','INV-2026-000613',
  'INV-2026-000614','INV-2026-000615','INV-2026-000616','INV-2026-000617',
  'INV-2026-000618','INV-2026-000619','INV-2026-000620','INV-2026-000621',
  'INV-2026-000622','INV-2026-000623'
);
-- expected: result=0 (if not zero, IDs collide → STOP)

-- 5) Required tables must exist
SELECT 'core tables present' AS check_name, COUNT(*) AS result
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('companies','users','branches','subscriptions','invoices','invoice_items','pricing_plans');
-- expected: result=7

-- 6) Trial plan must exist
SELECT 'trial plan exists' AS check_name, COUNT(*) AS result
FROM pricing_plans WHERE is_trial=1;
-- expected: result>=1

-- 7) Required new columns must exist on companies (added recently)
SELECT 'pra_access_code column' AS check_name, COUNT(*) AS result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='pra_access_code';
-- expected: result=1 (if 0, run ALTER TABLE first — see 99-SCHEMA-FIXES.sql)

SELECT 'pra_pos_id column' AS check_name, COUNT(*) AS result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='pra_pos_id';

SELECT 'pra_production_token column' AS check_name, COUNT(*) AS result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='pra_production_token';

SELECT 'agent_api_key column' AS check_name, COUNT(*) AS result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='agent_api_key';

SELECT 'province column' AS check_name, COUNT(*) AS result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='province';
