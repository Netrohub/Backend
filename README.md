# NXOLand Backend API

Laravel 11 API-only backend for NXOLand marketplace.

## Setup

1. Copy `.env.example` to `.env` and configure:
   - Database credentials (PostgreSQL)
   - Tap Payments keys
   - Persona KYC credentials
   - Sanctum stateful domains

2. Install dependencies:
```bash
composer install
```

3. Generate application key:
```bash
php artisan key:generate
```

4. Run migrations:
```bash
php artisan migrate
```

5. Run queue worker (for escrow auto-release):
```bash
php artisan queue:work
```

## Environment Variables

See `.env.example` for all required environment variables.

## API Endpoints

### Auth
- `POST /api/register` - Register new user
- `POST /api/login` - Login
- `POST /api/logout` - Logout (auth required)
- `GET /api/user` - Get current user (auth required)

### Listings
- `GET /api/listings` - List all active listings
- `POST /api/listings` - Create listing (auth required)
- `GET /api/listings/{id}` - Get listing details
- `PUT /api/listings/{id}` - Update listing (auth required)
- `DELETE /api/listings/{id}` - Delete listing (auth required)

### Orders
- `GET /api/orders` - Get user orders (auth required)
- `POST /api/orders` - Create order (auth required)
- `GET /api/orders/{id}` - Get order details (auth required)
- `PUT /api/orders/{id}` - Update order (auth required)

### Payments
- `POST /api/payments/create` - Create Tap payment (auth required)

### Disputes
- `GET /api/disputes` - Get user disputes (auth required)
- `POST /api/disputes` - Create dispute (auth required)
- `GET /api/disputes/{id}` - Get dispute details (auth required)
- `PUT /api/disputes/{id}` - Update dispute (admin only)

### Wallet
- `GET /api/wallet` - Get wallet balance (auth required)
- `POST /api/wallet/withdraw` - Request withdrawal (auth required)

### KYC
- `GET /api/kyc` - Get KYC status (auth required)
- `POST /api/kyc` - Create Persona inquiry (auth required)

### Webhooks
- `POST /api/webhook/tap` - Tap Payments webhook
- `POST /api/webhook/persona` - Persona webhook

### Public
- `GET /api/leaderboard` - Get leaderboard
- `GET /api/members` - List verified members
- `GET /api/members/{id}` - Get member details

### Admin
- `GET /api/admin/users` - List all users
- `PUT /api/admin/users/{id}` - Update user
- `DELETE /api/admin/users/{id}` - Delete user
- `GET /api/admin/disputes` - List all disputes
- `GET /api/admin/listings` - List all listings
- `GET /api/admin/orders` - List all orders
- `GET /api/admin/kyc` - List all KYC verifications

## Escrow Flow

1. Buyer creates order → Status: `pending`
2. Buyer pays via Tap → Status: `paid`
3. Tap webhook CAPTURED → Status: `escrow_hold` (funds held)
4. After 12 hours → Job releases funds → Status: `completed`
5. If dispute → Status: `disputed` (funds frozen)

## Deployment on Render

1. Connect GitHub repository
2. Set environment variables in Render dashboard
3. Set build command: `composer install --no-dev --optimize-autoloader`
4. Set start command: `php artisan serve --host=0.0.0.0 --port=$PORT`
5. Enable queue worker service for escrow jobs
