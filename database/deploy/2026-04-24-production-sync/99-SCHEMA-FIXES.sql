-- ==========================================================================
-- SCHEMA FIXES — run ONLY if PREFLIGHT step 7 shows missing columns
-- All ALTER statements use IF NOT EXISTS-equivalent pattern (idempotent via try/catch in MySQL via INFORMATION_SCHEMA check).
-- For MySQL 8+ you can use the `ADD COLUMN IF NOT EXISTS` syntax (8.0.29+).
-- Otherwise, manually skip the columns that already exist.
-- ==========================================================================

ALTER TABLE companies
  ADD COLUMN IF NOT EXISTS pra_environment VARCHAR(255) NOT NULL DEFAULT 'sandbox',
  ADD COLUMN IF NOT EXISTS pra_pos_id VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS pra_production_token VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS pra_proxy_url VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS pra_access_code VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS pra_reporting_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS agent_api_key VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS agent_last_seen TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS agent_version VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS agent_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS province VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS sector_type VARCHAR(255) NOT NULL DEFAULT 'Retail',
  ADD COLUMN IF NOT EXISTS standard_tax_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  ADD COLUMN IF NOT EXISTS pos_type VARCHAR(20) NOT NULL DEFAULT 'general',
  ADD COLUMN IF NOT EXISTS pos_theme VARCHAR(30) NOT NULL DEFAULT 'purple',
  ADD COLUMN IF NOT EXISTS pos_dashboard_style VARCHAR(30) NOT NULL DEFAULT 'default',
  ADD COLUMN IF NOT EXISTS restaurant_mode TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS kds_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS kitchen_printer_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS auto_print_kot TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS inventory_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS receipt_printer_size VARCHAR(10) NOT NULL DEFAULT '80mm',
  ADD COLUMN IF NOT EXISTS confidential_pin VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS next_local_invoice_number INT NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS manager_override_pin VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS cashier_discount_limit DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  ADD COLUMN IF NOT EXISTS manager_discount_limit DECIMAL(5,2) NOT NULL DEFAULT 50.00;

-- Add unique key on agent_api_key if not exists (skip if dupe)
-- ALTER TABLE companies ADD UNIQUE KEY companies_agent_api_key_unique (agent_api_key);
