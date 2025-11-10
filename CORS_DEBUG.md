# CORS Debugging Guide for Avatar Upload

## Issue
Avatar upload endpoint `/api/v1/user/avatar` is blocked by CORS policy.

## Checklist

### 1. ✅ Backend Setup (Completed)
- [x] HandleCors middleware in `app/Http/Middleware/HandleCors.php`
- [x] HandleCors registered in `bootstrap/app.php` (prepended to API middleware)
- [x] CORS config in `config/cors.php` with `https://nxoland.com`
- [x] Avatar upload route added: `POST /user/avatar`
- [x] AuthController has updateAvatar() method

### 2. ⚠️ Render Environment Variables (CHECK THIS)

On Render Dashboard → Backend Service → Environment:

**Required Variables:**
```bash
FRONTEND_URL=https://nxoland.com
```

**How to Add:**
1. Go to https://dashboard.render.com
2. Select your backend service (backend-piz0.onrender.com)
3. Go to "Environment" tab
4. Add: `FRONTEND_URL` = `https://nxoland.com`
5. Click "Save Changes"
6. Service will auto-redeploy

### 3. ✅ Frontend Setup (Completed)
- [x] FormData handling in `api.ts`
- [x] updateAvatar() properly sends FormData
- [x] No manual Content-Type header (browser sets it with boundary)

### 4. 🔍 Debugging Steps

#### Check Current CORS Origins:
```bash
# SSH into Render
cd /app
php artisan tinker

# Run in tinker:
config('cors.allowed_origins')
# Should output array with: https://nxoland.com, https://www.nxoland.com, null/localhost

# Check FRONTEND_URL
env('FRONTEND_URL')
# Should output: https://nxoland.com
```

#### Check Laravel Logs:
```bash
tail -f storage/logs/laravel.log | grep -i cors
```

You should see logs like:
```
[DEBUG] CORS request: method=OPTIONS, origin=https://nxoland.com
[DEBUG] CORS preflight response: origin=https://nxoland.com
[DEBUG] CORS request: method=POST, origin=https://nxoland.com
[DEBUG] CORS response: status=200, origin=https://nxoland.com
```

### 5. 🔧 Quick Fix (If Above Doesn't Work)

If CORS still fails, temporarily use wildcard (NOT RECOMMENDED FOR PRODUCTION):

Edit `backend/config/cors.php`:
```php
'allowed_origins' => ['*'],  // TEMPORARY - allows all origins
```

Then revert back to:
```php
'allowed_origins' => array_filter([
    'https://nxoland.com',
    'https://www.nxoland.com',
    env('FRONTEND_URL'),
    'http://localhost:5173',
    'http://localhost:3000',
]),
```

### 6. 📝 Common CORS Issues

#### Issue: "No 'Access-Control-Allow-Origin' header present"
**Cause:** Origin not in allowed_origins list
**Fix:** Add `FRONTEND_URL=https://nxoland.com` to Render environment

#### Issue: CORS works for GET but not POST
**Cause:** Missing OPTIONS preflight handling
**Fix:** Already handled in HandleCors middleware (line 30-64)

#### Issue: CORS works for JSON but not multipart/form-data
**Cause:** Content-Type header not in allowed headers
**Fix:** Already added 'Content-Type' to allowed headers (line 49)

### 7. ✅ Verification

After adding `FRONTEND_URL` to Render:

1. Wait for redeploy (~2-3 mins)
2. Try avatar upload again
3. Check browser console - should see:
   ```
   Status: 200 OK
   Access-Control-Allow-Origin: https://nxoland.com
   ```

### 8. 🎯 Expected Flow

```
Browser → OPTIONS /api/v1/user/avatar
        ← 200 OK + CORS headers

Browser → POST /api/v1/user/avatar + FormData
        ← 200 OK + CORS headers + avatar URL
```

## Next Steps

1. **Add `FRONTEND_URL` to Render environment**
2. Wait for redeploy
3. Try upload
4. Check logs: `tail -f storage/logs/laravel.log | grep CORS`
5. If still fails, share the CORS debug logs

## Commits
- Backend CORS: `f6cfd13`
- Frontend FormData: `8f53600`

