# Fix: Still Using SQLite Despite Config Showing PostgreSQL

## Problem
Even though `config('database.default')` shows "pgsql" in tinker, HTTP requests are still using SQLite.

## Root Cause
There's likely a cached config file (`bootstrap/cache/config.php`) that was created with SQLite settings before the database was properly configured.

## Solution

### Step 1: Delete Cached Config File

```bash
# Check if cached config exists
ls -la bootstrap/cache/config.php

# If it exists, delete it
rm -f bootstrap/cache/config.php

# Also check for other cached files
rm -f bootstrap/cache/routes-v7.php
rm -f bootstrap/cache/services.php
```

### Step 2: Clear All Caches Again

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

### Step 3: Verify Config is Correct

```bash
php artisan tinker
> config('database.default')
# Should output: "pgsql"
> config('database.connections.pgsql.host')
# Should show your PostgreSQL host
> DB::connection()->getDatabaseName()
# Should show your PostgreSQL database name
> exit
```

### Step 4: Rebuild Config Cache (Optional, but ensures it's correct)

```bash
# Only rebuild if you're CERTAIN all environment variables are set correctly
php artisan config:cache
```

### Step 5: Restart Service

After deleting cached files, restart the service in Render Dashboard.

## Why This Happens

Laravel caches configuration files for performance. If the config was cached when SQLite was the default (or when DB_CONNECTION wasn't set), it will keep using SQLite until the cache is cleared and the cached file is deleted.

## Prevention

Make sure all environment variables (especially DB_CONNECTION, DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD) are set in Render Dashboard BEFORE running `php artisan config:cache` in production.

