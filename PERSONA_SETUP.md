# Persona KYC Service Configuration

## Required Environment Variables

The PersonaService requires the following environment variables to be set:

```bash
PERSONA_API_KEY=your_persona_api_key
PERSONA_TEMPLATE_ID=your_template_id
PERSONA_ENVIRONMENT_ID=your_environment_id
PERSONA_WEBHOOK_SECRET=your_webhook_secret (optional, for webhook verification)
PERSONA_BASE_URL=https://withpersona.com/api/v1 (optional, defaults to this)
```

## Error Fix

**Issue:** `PersonaService` constructor was failing when environment variables were not set because PHP 8+ doesn't allow assigning `null` to typed string properties.

**Fix Applied:**
- Validate configuration values before assigning to typed properties
- Throw clear `RuntimeException` with helpful error messages if configuration is missing
- Ensures properties are always strings after validation

## Setting Up Persona

1. **Get Persona API Credentials:**
   - Sign up at https://withpersona.com
   - Create an API key in your Persona dashboard
   - Get your Template ID and Environment ID

2. **Set Environment Variables:**
   - In Render: Go to your backend service → Environment → Add Environment Variable
   - Add all required variables listed above

3. **Test Configuration:**
   - The service will now throw a clear error if configuration is missing
   - Check logs for: "PERSONA_API_KEY is not configured" etc.

## Error Messages

If Persona is not configured, you'll see one of these errors:
- `PERSONA_API_KEY is not configured. Please set it in your environment variables.`
- `PERSONA_TEMPLATE_ID is not configured. Please set it in your environment variables.`
- `PERSONA_ENVIRONMENT_ID is not configured. Please set it in your environment variables.`

These errors will appear in Laravel logs and prevent the service from being instantiated incorrectly.

