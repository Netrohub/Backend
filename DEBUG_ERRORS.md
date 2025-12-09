# Debugging Laravel Errors

## To See Full Error Messages

When viewing logs, scroll up to see the **actual error message**. The stack trace you're seeing is just the end part.

### Common Commands:

```bash
# View last 100 lines of log
tail -n 100 storage/logs/laravel.log

# View full error (scroll up)
less storage/logs/laravel.log

# Search for specific errors
grep -i "exception\|error\|fatal" storage/logs/laravel.log | tail -20

# View real-time logs
tail -f storage/logs/laravel.log
```

## Common Issues After Frontend API Changes

### 1. Route Not Found (404)
**Symptoms:** Stack trace ending in `NotFoundHttpException`

**Possible Causes:**
- Frontend calling wrong endpoint
- API_BASE_URL mismatch
- Route prefix issue

**Check:**
```bash
# List all routes
php artisan route:list | grep api

# Check specific route
php artisan route:list | grep login
```

### 2. Authentication Errors (401)
**Symptoms:** Unauthorized errors

**Check:**
- Token being sent correctly in Authorization header
- Token hasn't expired
- User exists and is active

### 3. CORS Errors
**Symptoms:** CORS preflight failures

**Check:**
- `config/cors.php` settings
- `HandleCors` middleware is working
- Allowed origins configured

### 4. Validation Errors (422)
**Symptoms:** Validation exception

**Check:**
- Request payload matches expected format
- Required fields are present
- Data types are correct

## Next Steps

1. **Run this command to see the full error:**
   ```bash
   tail -n 200 storage/logs/laravel.log | grep -A 50 "Exception\|Error\|Fatal"
   ```

2. **Check what route is being called:**
   - Look for the URL in the log
   - Verify it matches an existing route

3. **Verify API_BASE_URL:**
   - Frontend should use: `https://backend-piz0.onrender.com/api/v1`
   - Endpoints should NOT include `/v1` prefix (already in base URL)

4. **Test a simple endpoint:**
   ```bash
   curl -X GET https://backend-piz0.onrender.com/api/v1/leaderboard
   ```

## Enhanced Error Logging

Error logging has been enhanced in `bootstrap/app.php` to include:
- Full exception message
- File and line number
- Full stack trace
- Request URL and method
- IP address

All errors will now be logged with full context.

