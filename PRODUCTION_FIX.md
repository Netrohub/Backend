# Production Configuration Fixes

## Issue 1: Missing APP_KEY

**Error**: `No application encryption key has been specified`

**Solution**: Generate and set APP_KEY in Render Dashboard

1. **Generate key locally** (or on Render shell):
   ```bash
   php artisan key:generate --show
   ```

2. **Set in Render Dashboard**:
   - Go to your service → Environment Variables
   - Add: `APP_KEY` = `<generated-key>`
   - Or run on Render shell: `php artisan key:generate --force`

## Issue 2: Still Using SQLite Instead of PostgreSQL

**Error**: `Database file at path [/app/database/database.sqlite] does not exist`

**Root Cause**: Even though we updated the config, if `DB_CONNECTION` is not set, it might fall back to SQLite.

**Solution**: Ensure all database environment variables are set in Render Dashboard:

### Required Environment Variables:

```
DB_CONNECTION=pgsql
DB_HOST=<your-postgres-host>
DB_PORT=5432
DB_DATABASE=<your-database-name>
DB_USERNAME=<your-database-user>
DB_PASSWORD=<your-database-password>
```

### Steps to Fix:

1. **Verify environment variables are set** in Render Dashboard
2. **Clear config cache**:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

3. **Verify database connection**:
   ```bash
   php artisan tinker
   > config('database.default')
   # Should output: "pgsql"
   
   > DB::connection()->getPdo();
   # Should connect successfully
   ```

4. **Check if config/database.php default is correct**:
   The default should be: `env('DB_CONNECTION', env('APP_ENV') === 'production' || env('APP_ENV') === 'staging' ? 'pgsql' : 'sqlite')`

## Quick Fix Commands (Run on Render Shell):

```bash
# 1. Generate APP_KEY (this will output a key, copy it)
php artisan key:generate --show

# Then add it to Render Dashboard → Environment Variables:
# APP_KEY=<paste-generated-key-here>

# OR generate it directly (will be saved to .env if it exists):
php artisan key:generate --force

# 2. Verify all database environment variables are set
# In Render Dashboard, ensure these are set:
# - DB_CONNECTION=pgsql (already in render.yaml)
# - DB_HOST=<your-postgres-host>
# - DB_DATABASE=<your-database-name>
# - DB_USERNAME=<your-database-user>
# - DB_PASSWORD=<your-database-password>

# 3. Clear config cache
php artisan config:clear
php artisan cache:clear

# 4. Verify database config
php artisan tinker
> config('database.default')
# Should output: "pgsql"
> config('database.connections.pgsql.host')
# Should show your PostgreSQL host
> exit

# 5. Test database connection
php artisan migrate:status
```

## After Fixing:

1. Restart the service in Render Dashboard
2. Test registration endpoint again
3. Check logs: `tail -f storage/logs/laravel.log`

