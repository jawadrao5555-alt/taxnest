-- ==========================================================================
-- POST-MIGRATION VERIFICATION — run after all INSERT files
-- ==========================================================================
SELECT 'PUNJAB PLUS company present' AS check_name, COUNT(*) AS result FROM companies WHERE id=17;
-- expected: 1

SELECT 'PUNJAB PLUS PRA configured' AS check_name, COUNT(*) AS result
FROM companies WHERE id=17 AND pra_pos_id='192944' AND pra_production_token IS NOT NULL;
-- expected: 1

SELECT 'KHALID user present' AS check_name, COUNT(*) AS result FROM users WHERE id=18;
-- expected: 1

SELECT 'PUNJAB PLUS branch present' AS check_name, COUNT(*) AS result FROM branches WHERE company_id=17;
-- expected: >=1

SELECT 'PUNJAB PLUS trial subscription' AS check_name, COUNT(*) AS result
FROM subscriptions WHERE company_id=17 AND active=1;
-- expected: 1

SELECT 'ZIA today invoices count' AS check_name, COUNT(*) AS result
FROM invoices WHERE company_id=7 AND id BETWEEN 593 AND 671;
-- expected: 79

SELECT 'ZIA invoice items count' AS check_name, COUNT(*) AS result
FROM invoice_items WHERE invoice_id BETWEEN 593 AND 671;
-- expected: 81

SELECT 'ZIA invoices status breakdown' AS check_name, status, COUNT(*) AS n
FROM invoices WHERE company_id=7 AND id BETWEEN 593 AND 671
GROUP BY status;
-- expected: locked rows visible
