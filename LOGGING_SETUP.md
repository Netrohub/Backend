# Laravel Logging Setup Guide

## Issue: Log File Not Found

If you see `cannot open 'storage/logs/laravel.log' for reading: No such file or directory`, follow these steps:

## 1. Check if Storage Directory Exists

```bash
# Check if storage/logs directory exists
ls -la storage/logs/

# If it doesn't exist, create it
mkdir -p storage/logs
```

## 2. Set Proper Permissions

```bash
# Ensure storage directories are writable
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# On some systems, you might need:
chown -R www-data:www-data storage bootstrap/cache
# Or for your user:
chown -R $USER:$USER storage bootstrap/cache
```

## 3. Create Log File Manually (if needed)

```bash
# Create the log file
touch storage/logs/laravel.log

# Set permissions
chmod 664 storage/logs/laravel.log
```

## 4. Check Laravel Logging Configuration

Verify that `config/logging.php` has:

```php
'single' => [
    'driver' => 'single',
    'path' => storage_path('logs/laravel.log'),
    'level' => env('LOG_LEVEL', 'debug'),
],
```

## 5. Check Environment Variables

Ensure your `.env` file has:

```env
LOG_CHANNEL=stack
LOG_LEVEL=debug
```

## 6. Test Logging

Run this to test if logging works:

```bash
php artisan tinker
```

Then in tinker:
```php
Log::info('Test log message');
exit
```

Then check:
```bash
tail -f storage/logs/laravel.log
```

## 7. For Render/Production Servers

On Render, the storage directory should be writable. If logs aren't appearing:

1. **Check Render Logs**: Use Render's dashboard logs first
2. **Verify Storage Path**: Ensure `storage/logs` directory exists
3. **Check Disk Space**: `df -h` to ensure there's space
4. **Use Render's Log Stream**: Render provides log streaming in the dashboard

## 8. Alternative: View Render Logs

Instead of checking `storage/logs/laravel.log`, use Render's built-in logging:

- Go to your Render dashboard
- Click on your backend service
- Click "Logs" tab
- View real-time logs there

## 9. Laravel Log Channels

The default channel is `stack`, which logs to:
- `storage/logs/laravel.log` (single file)
- Or daily files if configured

To change logging behavior, update `.env`:
```env
LOG_CHANNEL=daily  # Creates daily log files: laravel-YYYY-MM-DD.log
```

## 10. Quick Fix Script

Run this one-liner to ensure everything is set up:

```bash
mkdir -p storage/logs bootstrap/cache && \
touch storage/logs/laravel.log && \
chmod -R 775 storage bootstrap/cache && \
chmod 664 storage/logs/laravel.log
```

## Troubleshooting

### Permission Denied
```bash
# Fix ownership (replace www-data with your web server user if different)
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Still Not Working
1. Check if PHP can write: `php -r "file_put_contents('storage/logs/test.log', 'test');"`
2. Check SELinux (if enabled): `getenforce`
3. Check disk space: `df -h`
4. Check Laravel cache: `php artisan config:clear && php artisan cache:clear`

## Render-Specific Notes

On Render, logs are typically accessed via:
1. **Dashboard Logs** (recommended)
2. **SSH into service** and check `storage/logs/`
3. **Set LOG_CHANNEL=errorlog** to use PHP error log (available in Render logs)

To see logs in Render dashboard:
- Navigate to your service
- Click "Logs" tab
- Real-time logs appear there

