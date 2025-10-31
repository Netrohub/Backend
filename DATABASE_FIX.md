# Database Configuration Fix

## Issue
Laravel was defaulting to SQLite instead of PostgreSQL in production.

## Solution
Updated `config/database.php` to default to PostgreSQL when `APP_ENV=production`.

## Required Environment Variables

Ensure these are set in your production environment (Render Dashboard):

```
DB_CONNECTION=pgsql
DB_HOST=<your-postgres-host>
DB_PORT=5432
DB_DATABASE=<your-database-name>
DB_USERNAME=<your-database-user>
DB_PASSWORD=<your-database-password>
```

## If Config is Cached

If you're still seeing SQLite errors, clear the config cache:

```bash
php artisan config:clear
php artisan cache:clear
```

Then verify the connection:

```bash
php artisan tinker
>>> config('database.default')
# Should output: "pgsql"
```

## Migration Command

After setting environment variables correctly:

```bash
php artisan migrate
```

If you get prompted about production, answer "Yes" to proceed.

