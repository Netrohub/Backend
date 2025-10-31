# Fix: APP_KEY Showing Null in Render

## Issue
`config('app.key')` returns null even after adding `APP_KEY` to Render Dashboard.

## Possible Causes

1. **Service not restarted** after adding environment variable
2. **Environment variable not saved correctly** in Render Dashboard
3. **Wrong variable name** (should be exactly `APP_KEY`)
4. **Cached config** still using old (null) value

## Step-by-Step Fix

### Step 1: Verify APP_KEY is Set in Render Dashboard

1. Go to Render Dashboard → Your Service → Environment
2. Scroll down and verify `APP_KEY` exists
3. Check the value - it should be exactly: `base64:0yNsmWN+v7f2vpWRQcXqcTG852wlJ60q7SEzCpU7LUA=`
4. Make sure there are no extra spaces or quotes

### Step 2: Verify Environment Variable is Loaded

Run this in Render shell:

```bash
# Check if APP_KEY is in environment
env | grep APP_KEY

# Should show:
# APP_KEY=base64:0yNsmWN+v7f2vpWRQcXqcTG852wlJ60q7SEzCpU7LUA=
```

### Step 3: If APP_KEY is NOT in env output

**Option A: Add via Render Dashboard (Recommended)**
1. Go to Render Dashboard → Your Service → Environment
2. Click "Add Environment Variable"
3. Key: `APP_KEY` (exactly, case-sensitive)
4. Value: `base64:0yNsmWN+v7f2vpWRQcXqcTG852wlJ60q7SEzCpU7LUA=`
5. Save
6. **Restart the service** (very important!)

**Option B: Add via render.yaml (Alternative)**
If Render Dashboard isn't working, you can add it to `render.yaml`:

```yaml
envVars:
  - key: APP_KEY
    value: base64:0yNsmWN+v7f2vpWRQcXqcTG852wlJ60q7SEzCpU7LUA=
```

Then commit and push - Render will pick it up on next deploy.

### Step 4: Clear Caches After Restart

After restarting, run:

```bash
php artisan config:clear
php artisan cache:clear

# Verify it's loaded
php artisan tinker
> env('APP_KEY')
# Should show the key
> config('app.key')
# Should also show the key
> exit
```

### Step 5: If Still Null - Check Render Logs

Check Render Dashboard → Logs for any errors about environment variables.

## Important Notes

- **Always restart the service** after adding/modifying environment variables in Render
- The variable name must be exactly `APP_KEY` (case-sensitive)
- No quotes around the value in Render Dashboard
- If using `render.yaml`, the value should also not have quotes

## Alternative: Set via Build Command

If environment variables aren't working, you can generate it during build:

In `render.yaml`, modify buildCommand:
```yaml
buildCommand: |
  composer install --no-dev --optimize-autoloader && 
  php artisan key:generate --show > /tmp/app_key.txt || true
```

But this is NOT recommended - use environment variables instead.

