# Persona Webhook Endpoint Configuration

## Webhook URL

**Endpoint:** `https://backend-piz0.onrender.com/api/v1/webhook/persona`

**Route:** `POST /api/v1/webhook/persona`

**Controller:** `App\Http\Controllers\WebhookController@persona`

## Webhook Configuration in Persona Dashboard

1. **Go to Persona Dashboard:**
   - Navigate to Settings → Webhooks
   - Or go to your Sandbox environment → Webhooks

2. **Add/Update Webhook:**
   - **URL:** `https://backend-piz0.onrender.com/api/v1/webhook/persona`
   - **Method:** `POST`
   - **Events to Subscribe:**
     - `inquiry.created`
     - `inquiry.started`
     - `inquiry.completed`
     - `inquiry.failed`
     - `inquiry.declined`
     - `verification.passed`
     - `verification.failed`

3. **Webhook Secret:**
   - Copy the webhook secret (starts with `whsec_`)
   - Add to your backend `.env`:
     ```env
     PERSONA_WEBHOOK_SECRET=whsec_your_secret_here
     ```

## How It Works

### Webhook Processing Flow:

1. **Persona sends webhook** → `POST /api/v1/webhook/persona`
2. **Signature Verification** (if `PERSONA_WEBHOOK_SECRET` is set):
   - Verifies `X-Persona-Signature` header
   - Uses HMAC SHA256 with webhook secret
3. **Extract Inquiry Data:**
   - `inquiry_id` from `payload['data']['id']`
   - `status` from `payload['data']['attributes']['status']`
4. **Find KYC Record:**
   - Looks up `KycVerification` by `persona_inquiry_id`
5. **Update Status:**
   - `completed.approved` → `verified`
   - `completed.declined` → `failed`
   - `expired` → `expired`
   - Other → `pending`
6. **User Updates:**
   - If verified: Sets `user.is_verified = true`
   - Sends notification to user
7. **Save Changes:**
   - Updates `kyc.status` and `kyc.verified_at`
   - Merges webhook payload into `kyc.persona_data`

## Status Mapping

| Persona Status | KYC Status | Actions |
|---------------|------------|---------|
| `completed.approved` | `verified` | Set `user.is_verified = true`, send success notification |
| `completed.declined` | `failed` | Send failure notification |
| `expired` | `expired` | Send expiration notification |
| `pending`, `started`, etc. | `pending` | No action |

## Webhook Payload Structure

Expected payload structure:
```json
{
  "data": {
    "id": "inq_xxxxx",
    "attributes": {
      "status": "completed.approved"
    }
  }
}
```

## Security

- **Signature Verification:** Enabled if `PERSONA_WEBHOOK_SECRET` is set
- **Rate Limiting:** 60 requests per minute per IP
- **No Authentication Required:** Webhooks are public endpoints (signature verification provides security)

## Testing

### Test Webhook Locally:
```bash
curl -X POST http://localhost:8000/api/v1/webhook/persona \
  -H "Content-Type: application/json" \
  -H "X-Persona-Signature: test_signature" \
  -d '{
    "data": {
      "id": "inq_test123",
      "attributes": {
        "status": "completed.approved"
      }
    }
  }'
```

### Test Webhook on Production:
Use Persona Dashboard's "Send Test Webhook" feature, or use a tool like Postman/curl to send a test request to:
```
POST https://backend-piz0.onrender.com/api/v1/webhook/persona
```

## Troubleshooting

### Webhook Not Receiving Events:
1. Check webhook URL in Persona dashboard matches exactly
2. Verify webhook is enabled in Persona dashboard
3. Check backend logs: `storage/logs/laravel.log`
4. Look for `Persona Webhook Received` log entries

### Signature Verification Failing:
1. Verify `PERSONA_WEBHOOK_SECRET` in `.env` matches Persona dashboard
2. Check `X-Persona-Signature` header is being sent
3. Review signature verification logic in `PersonaService::verifyWebhookSignature()`

### KYC Not Found:
- Webhook received but `KycVerification` record not found
- This is normal if inquiry was created outside your system
- Check logs for `Persona Webhook: KYC not found` warnings

## Logs

All webhook events are logged:
- **Success:** `Persona Webhook Received` with payload
- **Invalid Signature:** `Persona Webhook Signature Invalid`
- **KYC Not Found:** `Persona Webhook: KYC not found`
- **Invalid Payload:** Returns 400 with error message

## Related Files

- `backend/app/Http/Controllers/WebhookController.php` - Webhook handler
- `backend/app/Services/PersonaService.php` - Signature verification
- `backend/routes/api.php` - Route definition
- `backend/app/Models/KycVerification.php` - KYC model
- `backend/app/Notifications/KycVerified.php` - Notification

