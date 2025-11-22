-- Rollback: Remove Persona KYC fields from users table
-- Date: 2025-11-26
-- PostgreSQL compatible

-- Drop index first
DROP INDEX IF EXISTS users_kyc_status_index;

-- Drop UNIQUE constraint (this will also drop the associated index)
ALTER TABLE users 
DROP CONSTRAINT IF EXISTS users_persona_inquiry_id_unique;

-- Drop CHECK constraint
ALTER TABLE users 
DROP CONSTRAINT IF EXISTS users_kyc_status_check;

-- Drop columns
ALTER TABLE users 
DROP COLUMN IF EXISTS persona_inquiry_id,
DROP COLUMN IF EXISTS persona_reference_id,
DROP COLUMN IF EXISTS kyc_status,
DROP COLUMN IF EXISTS kyc_verified_at,
DROP COLUMN IF EXISTS phone_verified_at,
DROP COLUMN IF EXISTS verified_phone;

