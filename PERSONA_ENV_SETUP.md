# Persona Environment Variables Setup

## Where Persona Env Vars Should Be

✅ **BACKEND ONLY** - Persona credentials should NEVER be in the frontend

## Required Backend Environment Variables

Your backend `.env` needs these Persona variables:

```env
# Persona API Credentials (BACKEND ONLY)
PERSONA_API_KEY=sk_test_3ef3be12-87af-444f-9c71-c7546ee971a5
PERSONA_TEMPLATE_ID=itmpl_1bNZnx9mrbHZKKJsvJiN9BDDTuD6
PERSONA_ENVIRONMENT_ID=env_xxxxx  # ⚠️ MISSING - You need to add this
PERSONA_WEBHOOK_SECRET=whsec_xxxxx  # ⚠️ OPTIONAL but recommended
PERSONA_BASE_URL=https://withpersona.com/api/v1  # Optional, defaults to this
```

## What You're Missing

Based on your current `.env`, you're missing:

1. **PERSONA_ENVIRONMENT_ID** - REQUIRED
   - Get this from your Persona dashboard
   - Usually starts with `env_`
   - Identifies which Persona environment to use (sandbox vs production)

2. **PERSONA_WEBHOOK_SECRET** - OPTIONAL but recommended
   - Used to verify webhook signatures from Persona
   - Get this from Persona dashboard → Webhooks section
   - Helps prevent webhook spoofing

## Why Frontend Doesn't Need Persona Env Vars

The frontend:
- Loads Persona SDK from CDN (no credentials needed)
- Gets `inquiry_url` from backend API call
- Opens the inquiry URL in a modal/window
- Never directly calls Persona API

The backend:
- Creates Persona inquiries using API key
- Receives webhooks from Persona
- Validates webhook signatures
- Stores KYC verification status

## How to Get Missing Values

1. **PERSONA_ENVIRONMENT_ID**:
   - Log into https://withpersona.com
   - Go to Settings → Environments
   - Copy the Environment ID (starts with `env_`)

2. **PERSONA_WEBHOOK_SECRET**:
   - Log into https://withpersona.com
   - Go to Settings → Webhooks
   - Copy the Webhook Secret (starts with `whsec_`)

## Updated Backend .env (Add These)

```env
# Add these to your backend .env file
PERSONA_ENVIRONMENT_ID=env_your_environment_id_here
PERSONA_WEBHOOK_SECRET=whsec_your_webhook_secret_here
```

## Security Notes

⚠️ **NEVER** put Persona credentials in:
- Frontend `.env` files
- Frontend code
- Public repositories
- Client-side JavaScript

✅ **ONLY** in:
- Backend `.env` file
- Backend server environment variables
- Secure secret management systems

## Current Status

✅ You have:
- PERSONA_API_KEY
- PERSONA_TEMPLATE_ID

❌ You're missing:
- PERSONA_ENVIRONMENT_ID (REQUIRED)
- PERSONA_WEBHOOK_SECRET (OPTIONAL but recommended)

## Frontend Requirements

The frontend doesn't need any Persona environment variables. It:
1. Calls backend API `/api/v1/kyc` (POST)
2. Backend creates Persona inquiry and returns `inquiry_url`
3. Frontend opens `inquiry_url` in modal/window
4. Persona handles verification
5. Backend receives webhook from Persona
6. Frontend polls backend for KYC status

