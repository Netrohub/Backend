-- Migration: Add Persona KYC fields to users table
-- Date: 2025-11-26
-- PostgreSQL compatible

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

-- Add kyc_status column (using CHECK constraint for PostgreSQL enum-like behavior)
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS kyc_status VARCHAR(50) NULL DEFAULT 'pending';

-- Add CHECK constraint for kyc_status (using DO block - PostgreSQL doesn't support IF NOT EXISTS with ADD CONSTRAINT)
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
