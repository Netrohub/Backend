-- Complete SQL migration for Persona KYC implementation
-- Date: 2025-11-26
-- PostgreSQL compatible
-- Run this file to apply all Persona KYC migrations at once

BEGIN;

-- ============================================
-- 1. Add Persona KYC fields to users table
-- ============================================

-- Add persona_inquiry_id column
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS persona_inquiry_id VARCHAR(255) NULL;

-- Add unique constraint on persona_inquiry_id (PostgreSQL creates index automatically)
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'users_persona_inquiry_id_unique'
    ) THEN
        ALTER TABLE users 
        ADD CONSTRAINT users_persona_inquiry_id_unique 
        UNIQUE (persona_inquiry_id);
    END IF;
END $$;

-- Add persona_reference_id column
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS persona_reference_id VARCHAR(255) NULL;

-- Add kyc_status column
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS kyc_status VARCHAR(50) NULL DEFAULT 'pending';

-- Add CHECK constraint for kyc_status
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'users_kyc_status_check'
    ) THEN
        ALTER TABLE users 
        ADD CONSTRAINT users_kyc_status_check 
        CHECK (kyc_status IS NULL OR kyc_status IN ('pending', 'verified', 'failed', 'expired', 'canceled', 'review'));
    END IF;
END $$;

-- Add kyc_verified_at column
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS kyc_verified_at TIMESTAMP NULL;

-- Add verified_phone column
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS verified_phone VARCHAR(255) NULL;

-- Add phone_verified_at column
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS phone_verified_at TIMESTAMP NULL;

-- Add index on kyc_status for faster queries
CREATE INDEX IF NOT EXISTS users_kyc_status_index ON users (kyc_status);

-- ============================================
-- 2. Add webhook tracking to kyc_verifications table
-- ============================================

-- Add webhook_processed_at column
ALTER TABLE kyc_verifications 
ADD COLUMN IF NOT EXISTS webhook_processed_at TIMESTAMP NULL;

-- Add last_webhook_event_id column
ALTER TABLE kyc_verifications 
ADD COLUMN IF NOT EXISTS last_webhook_event_id VARCHAR(255) NULL;

-- Add index on webhook_processed_at for faster queries
CREATE INDEX IF NOT EXISTS kyc_verifications_webhook_processed_at_index 
ON kyc_verifications (webhook_processed_at);

COMMIT;

-- Verify the changes
SELECT 
    column_name, 
    data_type, 
    is_nullable, 
    column_default
FROM information_schema.columns 
WHERE table_name = 'users' 
AND column_name IN (
    'persona_inquiry_id', 
    'persona_reference_id', 
    'kyc_status', 
    'kyc_verified_at', 
    'verified_phone', 
    'phone_verified_at'
)
ORDER BY column_name;

SELECT 
    column_name, 
    data_type, 
    is_nullable
FROM information_schema.columns 
WHERE table_name = 'kyc_verifications' 
AND column_name IN (
    'webhook_processed_at', 
    'last_webhook_event_id'
)
ORDER BY column_name;

