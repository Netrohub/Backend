# Paylink Webhook Verification Guide

## Current Status Check

Looking at your logs, I don't see any "Paylink Webhook Received" entries, which suggests webhooks may not be arriving.

## Webhook Endpoint Details

**Endpoint:** `POST /api/v1/webhook/paylink`  
**Full URL:** `https://backend-piz0.onrender.com/api/v1/webhook/paylink`  
**Route:** Defined in `routes/api.php` line 73

## Verification Steps

### 1. Check if Webhook is Configured in Paylink Dashboard

**Important:** The webhook must be configured in the Paylink dashboard for it to work!

1. Log into Paylink Portal (https://portal.paylink.sa or similar)
2. Go to **Settings** → **Webhooks** (or **API Settings** → **Webhooks**)
3. Verify the webhook URL is set to:
   ```
   https://backend-piz0.onrender.com/api/v1/webhook/paylink
   ```
4. Make sure webhook is **Enabled**
5. Check which events are enabled (should include payment status changes)

### 2. Check Server Logs for Webhook Requests

Run this command to check for webhook logs:
```bash
grep "Paylink Webhook Received" storage/logs/laravel.log
```

If you see entries, webhooks are arriving. If not, they're not being sent.

### 3. Test Webhook Endpoint Manually

You can test if the endpoint is accessible:

```bash
curl -X POST https://backend-piz0.onrender.com/api/v1/webhook/paylink \
  -H "Content-Type: application/json" \
  -d '{
    "transactionNo": "test",
    "orderStatus": "Paid"
  }'
```

Expected response:
```json
{
  "message": "Test webhook received successfully - endpoint is working",
  "status": "success",
  "test": true
}
```

### 4. Check for Order #36 Webhook

For order #36 (transaction: `1763647622792`), check logs:
```bash
grep "1763647622792" storage/logs/laravel.log | grep -i webhook
```

## Common Issues

### Issue 1: Webhook Not Configured in Paylink Dashboard
**Symptom:** No "Paylink Webhook Received" logs  
**Solution:** Configure webhook URL in Paylink dashboard

### Issue 2: Wrong Webhook URL Format
**Symptom:** 404 errors in logs  
**Solution:** Ensure URL includes `/api/v1/` prefix:
- ✅ Correct: `https://backend-piz0.onrender.com/api/v1/webhook/paylink`
- ❌ Wrong: `https://backend-piz0.onrender.com/webhook/paylink`

### Issue 3: Webhook URL Not Accessible
**Symptom:** Connection errors or timeouts  
**Solution:** 
- Verify backend server is running
- Check firewall/security settings
- Test endpoint manually (see step 3)

### Issue 4: Webhook Sent Before Payment Record Created
**Symptom:** "Payment not found" messages (but this is normal and handled)

## Manual Processing for Order #36

Since callback failed, you can manually process order #36:

1. **Wait for webhook** (should arrive within a few minutes)
2. **OR** Use Laravel tinker to manually update:
   ```bash
   php artisan tinker
   ```
   Then:
   ```php
   $order = \App\Models\Order::find(36);
   $payment = \App\Models\Payment::where('order_id', 36)->first();
   // Verify paymentReceipt exists in paylink_response
   // Then trigger webhook logic manually or update order status
   ```

## Next Steps

1. **Check Paylink dashboard** - Verify webhook is configured
2. **Monitor logs** - Watch for "Paylink Webhook Received" entries
3. **Test endpoint** - Verify it's accessible
4. **If webhook not configured** - Set it up in Paylink dashboard immediately

The callback fix ensures future payments work, but webhooks are still important as the primary payment verification mechanism.

