# Production Setup Guide

## After Migrations Run Successfully

### 1. Verify Cache Table Exists

The cache table should have been created by the migration. Verify it exists:

```bash
# Option 1: Check via psql (if you have access)
psql $DATABASE_URL -c "\dt cache"

# Option 2: Use Laravel tinker
php artisan tinker
>>> Schema::hasTable('cache')
# Should return: true
```

### 2. Fix Cache Clear Issue

If `php artisan cache:clear` fails with "relation cache does not exist":

**Quick Fix**: Temporarily switch to file cache:

In Render Dashboard, add/update environment variable:
```
CACHE_DRIVER=file
```

Then:
```bash
php artisan config:clear
php artisan cache:clear
```

**Permanent Fix**: Verify cache table exists and is accessible:

```bash
# Check if cache table exists
php artisan tinker
>>> DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'cache'")
```

If the table doesn't exist, re-run the migration:
```bash
php artisan migrate:refresh --path=database/migrations/0001_01_01_000001_create_cache_table.php --force
```

### 3. Verify Database Connection

```bash
php artisan tinker
>>> config('database.default')
# Should output: "pgsql"

>>> DB::connection()->getDatabaseName()
# Should output your PostgreSQL database name
```

### 4. Set Up Application Key (if not already set)

```bash
php artisan key:generate --force
```

### 5. Optimize for Production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Common Issues

### Issue: Cache table doesn't exist
**Solution**: The migration ran, but verify the table was created in the correct schema (PostgreSQL uses 'public' schema by default).

### Issue: Tinker parse error
**Solution**: Don't use `>>>` in tinker - just type the command directly:
```bash
php artisan tinker
> config('database.default')
```

### Issue: Environment variables not loading
**Solution**: 
1. Check Render Dashboard → Environment Variables
2. Clear config cache: `php artisan config:clear`
3. Restart the service

