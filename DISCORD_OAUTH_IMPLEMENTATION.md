# Discord OAuth2 Implementation Summary

## Overview
This document summarizes the complete Discord OAuth2 implementation, business rule enforcement, and Discord bot integration.

## Database Changes

### New Fields Added to `users` Table
- `display_name` (nullable) - Optional nickname, never used as identifier
- `discord_user_id` (nullable, unique) - Discord user ID
- `discord_username` (nullable) - Discord username with discriminator
- `discord_avatar` (nullable) - Discord avatar URL
- `discord_connected_at` (timestamp, nullable) - When Discord was connected
- `is_seller` (boolean, default false) - Seller flag

### Migrations Created
1. `2025_01_20_000000_add_discord_fields_to_users_table.php` - Adds Discord fields
2. `2025_01_20_000001_normalize_user_identities.php` - Normalizes usernames and cleans up data

## Authentication

### Discord OAuth2 Endpoints
- `GET /api/v1/auth/discord/redirect?mode=login|connect` - Redirect to Discord OAuth
- `GET /api/v1/auth/discord/callback` - OAuth callback handler
- `POST /api/v1/discord/disconnect` - Disconnect Discord (authenticated)

### Modes
- **Login Mode**: Logs in existing user or creates new account
- **Connect Mode**: Links Discord to existing authenticated user

## Business Rules

### Sellers Must Link Discord
Required before:
- Creating listings
- Editing listings
- Marking delivery
- Seller actions in disputes

**Middleware**: `RequireDiscordForSellers`
**Error Response**:
```json
{
  "error": "discord_required_for_sellers",
  "message": "You must connect your Discord account before listing or selling."
}
```

### Buyers Must Link Discord for Disputes
Required before:
- `POST /orders/:orderId/disputes`

**Middleware**: `RequireDiscordForDisputes`
**Error Response**:
```json
{
  "error": "discord_required_for_disputes",
  "message": "Please connect your Discord account before opening a dispute."
}
```

## Discord Bot Integration

### Event Emitters
All events are sent to `DISCORD_BOT_WEBHOOK_URL` via POST requests.

#### Dispute Events
- `dispute.created` - When a dispute is created
- `dispute.updated` - When a dispute status changes
- `dispute.resolved` - When a dispute is resolved

#### Listing Events
- `listing.created` - When a new listing is created
- `listing.updated` - When a listing is updated
- `listing.status_changed` - When listing status changes

### Event Payload Format
```json
{
  "event_type": "dispute.created",
  "data": {
    "dispute_id": 551,
    "order_id": 8802,
    "buyer_id": 102,
    "seller_id": 77,
    "buyer_discord_id": "713409587178455110",
    "seller_discord_id": "992240889372110999",
    "reason": "Account not working",
    "status": "open",
    "timestamp": "2025-01-02T10:00:00Z"
  }
}
```

## Configuration

### Environment Variables
Add to `.env`:
```env
DISCORD_CLIENT_ID=your_client_id
DISCORD_CLIENT_SECRET=your_client_secret
DISCORD_REDIRECT_URI=https://yourdomain.com/api/v1/auth/discord/callback
DISCORD_OAUTH_SCOPES=identify email
DISCORD_BOT_WEBHOOK_URL=https://your-discord-bot-webhook-url
```

### Config File
Updated `config/services.php` with Discord OAuth configuration.

## Services Created

1. **DiscordEventEmitter** - Base service for sending events to Discord bot
2. **DisputeEventEmitter** - Handles dispute-related events
3. **ListingEventEmitter** - Handles listing-related events

## Middleware Created

1. **RequireDiscordForSellers** - Enforces Discord requirement for sellers
2. **RequireDiscordForDisputes** - Enforces Discord requirement for dispute creation

## User Model Updates

- Added `hasDiscord()` method
- Added `generateUsername()` static method for username generation
- Updated fillable fields to include Discord fields
- Updated casts to include Discord timestamps

## Routes Updated

- Added Discord OAuth routes (public)
- Added Discord disconnect route (authenticated)
- Applied middleware to listing routes (sellers)
- Applied middleware to dispute creation route (buyers)

## Integration Points

### DisputeController
- Emits `dispute.created` on creation
- Emits `dispute.updated` on status changes
- Emits `dispute.resolved` when resolved

### ListingController
- Emits `listing.created` on creation
- Emits `listing.updated` on updates
- Emits `listing.status_changed` on status changes

## Next Steps

1. Run migrations:
   ```bash
   php artisan migrate
   ```

2. Set up Discord OAuth application:
   - Create application at https://discord.com/developers/applications
   - Add redirect URI: `https://yourdomain.com/api/v1/auth/discord/callback`
   - Copy Client ID and Secret to `.env`

3. Configure Discord bot webhook URL in `.env`

4. Test OAuth flow:
   - Login mode: `/api/v1/auth/discord/redirect?mode=login`
   - Connect mode: `/api/v1/auth/discord/redirect?mode=connect`

5. Verify event emission by checking Discord bot logs

## Notes

- All Discord events are sent asynchronously (non-blocking)
- Events are logged on failure but don't fail the main operation
- Username normalization ensures all usernames match `[a-z0-9_]{3,20}` pattern
- Display name is optional and never used as an identifier
- The system uses `username` as the primary identifier throughout

