# Backend Deployment Trigger

**Date**: November 5, 2025

This file triggers a backend redeployment to run pending migrations.

## Pending Migrations
- `2025_11_05_create_site_settings_table.php` - Creates site_settings table for Terms & Privacy

## Render Auto-Deploy
Render will:
1. Pull latest code
2. Run `composer install`
3. Run `php artisan migrate --force` (if configured)
4. Restart application

## Manual Migration (if needed)
If migrations don't run automatically:

```bash
# SSH into Render console
php artisan migrate --force

# Verify table exists
php artisan tinker
>>> \DB::table('site_settings')->count()
```

## Expected Result
After deployment, these endpoints should work:
- `GET /api/v1/site-settings/terms_and_conditions`
- `GET /api/v1/site-settings/privacy_policy`

