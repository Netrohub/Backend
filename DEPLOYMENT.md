# NXOLand Backend Deployment Guide

## Render Deployment

### Prerequisites
- PostgreSQL database on Render
- GitHub repository connected
- Environment variables configured

### Steps

1. **Create PostgreSQL Database**
   - Go to Render Dashboard → New → PostgreSQL
   - Name: `nxoland-db`
   - Region: Choose closest to your app
   - Note the connection details

2. **Create Web Service**
   - Go to Render Dashboard → New → Web Service
   - Connect your GitHub repository
   - Name: `nxoland-api`
   - Environment: PHP
   - Build Command: `composer install --no-dev --optimize-autoloader`
   - Start Command: `php artisan serve --host=0.0.0.0 --port=$PORT`
   - Health Check Path: `/up`

3. **Set Environment Variables**

   ```
   APP_NAME=NXOLand
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://api.nxoland.com
   APP_KEY=<generate with: php artisan key:generate>
   
   DB_CONNECTION=pgsql
   DB_HOST=<from PostgreSQL service>
   DB_PORT=5432
   DB_DATABASE=<from PostgreSQL service>
   DB_USERNAME=<from PostgreSQL service>
   DB_PASSWORD=<from PostgreSQL service>
   
   SANCTUM_STATEFUL_DOMAINS=nxoland.com,www.nxoland.com
   SESSION_DOMAIN=.nxoland.com
   
   TAP_PUBLIC_KEY=<your_tap_public_key>
   TAP_SECRET_KEY=<your_tap_secret_key>
   TAP_WEBHOOK_SECRET=<your_tap_webhook_secret>
   
   PERSONA_API_KEY=<your_persona_api_key>
   PERSONA_TEMPLATE_ID=<your_persona_template_id>
   PERSONA_ENVIRONMENT_ID=<your_persona_env_id>
   PERSONA_WEBHOOK_SECRET=<your_persona_webhook_secret>
   
   FRONTEND_URL=https://nxoland.com
   ```

4. **Create Queue Worker Service** (for escrow auto-release)
   - Go to Render Dashboard → New → Background Worker
   - Connect same repository
   - Name: `nxoland-worker`
   - Start Command: `php artisan queue:work --sleep=3 --tries=3`
   - Use same environment variables as web service

5. **Configure Webhooks**

   **Tap Payments Webhook:**
   - URL: `https://api.nxoland.com/api/webhook/tap`
   - Events: `charge.captured`, `charge.failed`

   **Persona Webhook:**
   - URL: `https://api.nxoland.com/api/webhook/persona`
   - Events: `inquiry.completed`, `inquiry.expired`

6. **Run Migrations**
   - SSH into Render shell or use Render Shell
   - Run: `php artisan migrate --force`

7. **Configure Custom Domain**
   - In Render service settings → Custom Domains
   - Add: `api.nxoland.com`
   - Configure DNS CNAME record

## Post-Deployment Checklist

- [ ] Database migrations completed
- [ ] Environment variables set
- [ ] Webhooks configured and tested
- [ ] CORS configured for frontend domain
- [ ] Queue worker running
- [ ] SSL certificate active
- [ ] Health check endpoint responding
- [ ] Test API endpoints from frontend
- [ ] Verify GTM tracking in production

## Monitoring

- Check Render logs for errors
- Monitor queue worker logs
- Verify webhook deliveries
- Check database connections
- Monitor API response times

