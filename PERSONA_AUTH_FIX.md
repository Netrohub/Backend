# Fixing Persona API Authentication Error

## Error
```
"Must be authenticated to access this endpoint"
```

## Root Cause

The Persona API is rejecting authentication. Possible causes:

1. **Missing PERSONA_ENVIRONMENT_ID** (Most Likely)
   - The API key might be valid but without environment ID, Persona can't authenticate
   - Add `PERSONA_ENVIRONMENT_ID` to backend `.env`

2. **API Key Format Issue**
   - Persona API key should start with `sk_test_` (test) or `sk_live_` (production)
   - Your key: `sk_test_3ef3be12-87af-444f-9c71-c7546ee971a5` ✅ Looks correct

3. **API Key Permissions**
   - The API key might not have permissions for the environment
   - Check Persona dashboard → API Keys → Permissions

4. **Environment Mismatch**
   - API key might be for a different environment than specified
   - Template ID might not match the environment

## Required Backend Environment Variables

Add these to your Render backend `.env`:

```env
# Required
PERSONA_API_KEY=sk_test_3ef3be12-87af-444f-9c71-c7546ee971a5
PERSONA_TEMPLATE_ID=itmpl_1bNZnx9mrbHZKKJsvJiN9BDDTuD6
PERSONA_ENVIRONMENT_ID=env_xxxxx  # ⚠️ MISSING - Get from Persona dashboard

# Optional but recommended
PERSONA_WEBHOOK_SECRET=whsec_xxxxx
```

## How to Get PERSONA_ENVIRONMENT_ID

1. Log into https://withpersona.com
2. Go to **Settings** → **Environments**
3. Find your environment (usually "Sandbox" for test keys)
4. Copy the **Environment ID** (starts with `env_`)
5. Add to Render backend environment variables
6. Redeploy backend

## Verification Steps

After adding `PERSONA_ENVIRONMENT_ID`:

1. **Check logs** for Persona API calls:
   ```bash
   # Should see successful API calls, not authentication errors
   ```

2. **Test KYC creation**:
   - Go to `/kyc` page
   - Click "ابدأ عملية التحقق"
   - Should create inquiry successfully

3. **Check Persona dashboard**:
   - Go to Persona → Inquiries
   - Should see new inquiries being created

## Current Code Changes

✅ **Improved Error Handling:**
- `PersonaService` now throws exceptions on API errors
- `KycController` catches and returns user-friendly errors
- Frontend shows detailed error messages

✅ **Better Logging:**
- Logs API response status codes
- Logs error details for debugging

## Next Steps

1. **Add `PERSONA_ENVIRONMENT_ID` to Render backend**
2. **Redeploy backend**
3. **Test KYC creation**
4. **Check logs for success**

## If Still Failing

Check:
- API key is active in Persona dashboard
- Template ID matches the environment
- Environment ID matches the API key's environment
- API key has correct permissions

