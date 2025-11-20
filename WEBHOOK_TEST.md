# Paylink Webhook Testing Guide

## Webhook Configuration

### Endpoint URL
The Paylink webhook endpoint is:
```
POST https://backend-piz0.onrender.com/api/v1/webhook/paylink
```

### Route
Defined in `backend/routes/api.php`:
```php
Route::post('/webhook/paylink', [WebhookController::class, 'paylink']);
```

### Verification Steps

1. **Check if webhook is configured in Paylink Dashboard:**
   - Log into Paylink Portal
   - Go to Settings → Webhooks
   - Verify the webhook URL is set to: `https://backend-piz0.onrender.com/api/v1/webhook/paylink`
   - Make sure webhook is **Enabled**

2. **Check webhook logs:**
   - Look for log entries starting with "Paylink Webhook Received"
   - Check if webhooks are arriving for order #36 (transaction: 1763647622792)

3. **Test webhook manually:**
   - Use the test webhook feature in Paylink dashboard, OR
   - Send a test POST request to verify endpoint is accessible

4. **Monitor for webhook delivery:**
   - Paylink should send webhook when order status changes to "Paid"
   - Check logs for: `[2025-11-20] production.INFO: Paylink Webhook Received`

## Current Issue

Order #36:
- **Transaction No:** 1763647622792
- **Status:** Payment confirmed (has paymentReceipt)
- **Order Status:** Still `payment_intent` (not processed)
- **Callback:** Failed due to undefined variable error (now fixed)
- **Webhook:** Unknown if received/processed

## Troubleshooting

### If webhooks are not arriving:
1. Check Paylink dashboard webhook configuration
2. Verify webhook URL is correct (include `/api/v1/` prefix)
3. Check if webhook is enabled
4. Check server logs for any 404/500 errors on webhook endpoint
5. Verify backend server is accessible from Paylink servers

### If webhooks arrive but don't process:
1. Check logs for "Paylink Webhook: Invoice status"
2. Verify payment record exists for transaction number
3. Check if order status is still `payment_intent`
4. Verify paymentReceipt exists in invoice response

### Manual Processing (If needed):
Since callback failed for order #36, you can:
1. Wait for webhook (should arrive within minutes)
2. OR manually trigger webhook processing via API
3. OR update order status manually in database (not recommended)

