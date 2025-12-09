-- Migration: Add webhook tracking fields to kyc_verifications table
-- Date: 2025-11-26
-- PostgreSQL compatible

-- Add webhook_processed_at column
ALTER TABLE kyc_verifications 
ADD COLUMN IF NOT EXISTS webhook_processed_at TIMESTAMP NULL;

-- Add last_webhook_event_id column
ALTER TABLE kyc_verifications 
ADD COLUMN IF NOT EXISTS last_webhook_event_id VARCHAR(255) NULL;

-- Add index on webhook_processed_at for faster queries
CREATE INDEX IF NOT EXISTS kyc_verifications_webhook_processed_at_index 
ON kyc_verifications (webhook_processed_at);

