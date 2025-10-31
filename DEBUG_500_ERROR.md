# Debugging 500 Error on Registration

## Steps to Debug

1. **Check Laravel logs** (on Render):
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. **Enable debug mode temporarily** (in Render Dashboard):
   ```
   APP_DEBUG=true
   ```
   This will show detailed error messages. **Remember to set it back to false after debugging!**

3. **Check if password_confirmation is being sent**:
   The validation requires `password_confirmation` field. Check the frontend request payload.

4. **Verify database connection**:
   ```bash
   php artisan tinker
   > DB::connection()->getPdo();
   ```

5. **Check if tables exist**:
   ```bash
   php artisan tinker
   > Schema::hasTable('users')
   > Schema::hasTable('wallets')
   ```

6. **Test registration directly**:
   ```bash
   curl -X POST https://backend-piz0.onrender.com/api/register \
     -H "Content-Type: application/json" \
     -H "Origin: https://nxoland.com" \
     -d '{
       "name": "Test User",
       "email": "test@example.com",
       "password": "password123",
       "password_confirmation": "password123"
     }'
   ```

## Common Issues

1. **Missing password_confirmation**: Frontend must send `password_confirmation` field
2. **Database connection**: PostgreSQL might not be configured correctly
3. **Missing migrations**: Tables might not exist
4. **Column type mismatch**: Database schema might not match model expectations

## Recent Changes

- Added better error logging with file and line numbers
- Added REGISTRATION_ERROR and LOGIN_ERROR constants to MessageHelper
- Improved error messages in AuthController

