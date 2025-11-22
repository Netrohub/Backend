-- Rollback: Remove webhook tracking fields from kyc_verifications table
-- Date: 2025-11-26
-- PostgreSQL compatible

-- Drop index first
DROP INDEX IF EXISTS kyc_verifications_webhook_processed_at_index;

-- Drop columns
ALTER TABLE kyc_verifications 
DROP COLUMN IF EXISTS webhook_processed_at,
DROP COLUMN IF EXISTS last_webhook_event_id;

