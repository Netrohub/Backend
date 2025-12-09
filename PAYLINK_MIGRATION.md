# Paylink Payment Integration - Migration Complete

## Overview
This document describes the migration from Tap Payments to Paylink API for the NXOLand platform.

## Changes Made

### 1. Configuration
- Added Paylink configuration to `config/services.php`
- Environment variables required:
  - `PAYLINK_BASE_URL` (default: `https://restpilot.paylink.sa` for testing)
  - `PAYLINK_API_ID`
  - `PAYLINK_API_SECRET`

### 2. New Service
- Created `app/Services/PaylinkClient.php`
  - Handles authentication with token caching
  - Methods: `createInvoice()`, `getInvoice()`, `cancelInvoice()`
  - Auto-refreshes tokens when expired

### 3. Database Migrations
- Added `paylink_transaction_no` to `payments` table
- Added `paylink_response` JSON field to `payments` table
- Added `paylink_transaction_no` to `orders` table
- Made `tap_charge_id` nullable in payments (for backward compatibility)

### 4. Backend Updates
- **PaymentController**: 
  - Replaced Tap with Paylink
  - Returns `paymentUrl` instead of `redirect_url`
  - Added `callback()` method for payment redirects
- **WebhookController**: 
  - Added `paylink()` method for Paylink webhooks
  - Always re-queries invoice status (never trusts webhook payload alone)
  - Handles payment confirmation and escrow flow

### 5. Routes
- Added `/payments/paylink/callback` route (web.php)
- Added `/api/v1/webhook/paylink` route (api.php)
- Added `/api/v1/checkout` alias for `/api/v1/payments/create`

### 6. Frontend Updates
- Updated `Checkout.tsx` to use `paymentUrl` field
- Updated `PaymentCreateResponse` type to include `paymentUrl`
- Changed payment method display from "Tap Payment" to "Paylink Payment"

## Migration Steps

1. **Add environment variables** to `.env`:
   ```
   PAYLINK_BASE_URL=https://restpilot.paylink.sa
   PAYLINK_API_ID=your_api_id
   PAYLINK_API_SECRET=your_secret_key
   ```

2. **Run migrations**:
   ```bash
   php artisan migrate
   ```

3. **Update Paylink webhook URL** in Paylink dashboard:
   - URL: `https://your-domain.com/api/v1/webhook/paylink`

4. **Test the integration**:
   - Create a test order
   - Initiate payment
   - Verify redirect to Paylink
   - Complete payment
   - Verify webhook processing
   - Verify order status update

## API Flow

1. **Create Payment**:
   - User clicks "Pay now" → Frontend calls `/api/v1/payments/create`
   - Backend creates Paylink invoice
   - Returns `paymentUrl`
   - Frontend redirects user to `paymentUrl`

2. **Payment Processing**:
   - User completes payment on Paylink
   - Paylink redirects to `/payments/paylink/callback?order_id=X&transactionNo=Y`
   - Backend verifies payment status via API
   - Redirects user to frontend order page

3. **Webhook Processing**:
   - Paylink sends webhook to `/api/v1/webhook/paylink`
   - Backend re-queries invoice status (security)
   - If paid: Updates order to `escrow_hold`, triggers escrow flow
   - Sends notifications to buyer and seller

## Security Features

- ✅ Never trusts callback query params - always re-queries invoice status
- ✅ Webhook always re-queries invoice status from Paylink API
- ✅ Prevents duplicate payment processing with database locks
- ✅ Validates paid amount matches order amount
- ✅ Idempotency checks for existing payments

## Backward Compatibility

- Tap payment fields remain in database (nullable)
- Old Tap webhook routes still exist (deprecated)
- Frontend supports both `paymentUrl` and `redirect_url` fields

## Next Steps

1. Test thoroughly in sandbox environment
2. Update production environment variables
3. Configure Paylink webhook URL in production
4. Monitor logs for any issues
5. Remove Tap code after successful migration (optional)

## Notes

- Paylink uses SAR currency (converted from USD at 3.75 rate)
- Token caching: 30 minutes (or 30 hours if persistToken=true)
- Webhook should be configured in Paylink dashboard
- Callback URL is where user is redirected after payment

