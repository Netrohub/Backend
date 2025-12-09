# Quick Fix for Production Issues

## Issue 1: Generate APP_KEY (No .env file in production)

Since Render doesn't use .env files, generate the key and add it manually:

```bash
# Generate key (won't try to write to .env)
php artisan key:generate --show
```

Copy the output (it will look like: `base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx...`)

Then add it to **Render Dashboard → Environment Variables**:
- Key: `APP_KEY`
- Value: `<paste the entire generated key>`

## Issue 2: Still Using SQLite Instead of PostgreSQL

The config cache might be stale. Run these commands:

```bash
# 1. Verify DB_CONNECTION is set
env | grep DB_CONNECTION
# Should show: DB_CONNECTION=pgsql

# 2. If not set, check Render Dashboard → Environment Variables
# Make sure DB_CONNECTION=pgsql is set

# 3. Clear ALL caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# 4. Verify database config
php artisan tinker
> config('database.default')
# Should output: "pgsql"
> config('database.connections.pgsql.host')
# Should show your PostgreSQL host
> exit

# 5. If still showing SQLite, force rebuild config cache
# (Only if you're sure all env vars are set correctly)
php artisan config:cache
```

## After Adding APP_KEY

1. **Restart the service** in Render Dashboard
2. **Test registration** endpoint again
3. **Check logs**: `tail -f storage/logs/laravel.log`

## If Still Using SQLite After Above Steps

The issue is that `DB_CONNECTION` environment variable might not be set in Render Dashboard. Even though it's in `render.yaml`, you need to verify it's actually set:

1. Go to Render Dashboard → Your Service → Environment
2. Verify `DB_CONNECTION=pgsql` exists
3. If missing, add it
4. Restart service

