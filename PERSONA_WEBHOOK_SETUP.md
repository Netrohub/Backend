# Persona Webhook Configuration - Corrections Needed

## Issues Found

### 1. ❌ Webhook URL is Incomplete

**Current (Wrong):**
```
https://backend-piz0.onrender.com
```

**Should Be:**
```
https://backend-piz0.onrender.com/api/v1/webhook/persona
```

The webhook route is at `/api/v1/webhook/persona` (see `backend/routes/api.php` line 47).

### 2. ⚠️ API Version Mismatch

**Persona Dashboard Shows:** `2023-01-05`  
**Code Uses:** `2024-02-05`

**Fix:** Update Persona webhook API version to `2024-02-05` to match the code, OR update the code to use `2023-01-05`.

### 3. ✅ Events Look Good

The enabled events are correct:
- `inquiry.created`
- `inquiry.started`
- `inquiry.completed`
- `inquiry.failed`
- `inquiry.declined`
- `verification.passed`
- `verification.failed`

## Steps to Fix

1. **Update Webhook URL in Persona Dashboard:**
   - Go to the webhook settings
   - Change URL from: `https://backend-piz0.onrender.com`
   - To: `https://backend-piz0.onrender.com/api/v1/webhook/persona`
   - Click "Save"

2. **Update API Version:**
   - In Persona dashboard, change API version from `2023-01-05` to `2024-02-05`
   - OR update `PersonaService.php` line 43 to use `2023-01-05`

3. **Copy Webhook Secret:**
   - Click the eye icon to reveal the webhook secret
   - Copy it (starts with `whsec_`)
   - Add to your backend `.env`:
     ```env
     PERSONA_WEBHOOK_SECRET=whsec_your_secret_here
     ```

4. **Add Missing Environment ID:**
   - You still need `PERSONA_ENVIRONMENT_ID` (from Settings → Environments)
   - Add to backend `.env`:
     ```env
     PERSONA_ENVIRONMENT_ID=env_your_environment_id_here
     ```

## Summary

✅ **Correct:**
- Webhook status: Enabled
- Events enabled: All necessary events
- Webhook secret: Available (copy it)

❌ **Needs Fix:**
- Webhook URL: Missing `/api/v1/webhook/persona` path
- API Version: Mismatch (2023 vs 2024)
- Missing: `PERSONA_ENVIRONMENT_ID` in backend `.env`
- Missing: `PERSONA_WEBHOOK_SECRET` in backend `.env`

