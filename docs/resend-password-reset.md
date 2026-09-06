# HARDEX password-reset email deployment

HARDEX uses Laravel's standard password broker for reset-token creation, expiry, throttling, validation, and invalidation. Resend is only the synchronous mail transport, so the request page can report delivery failures immediately. It does not use the WhatsApp queue or WhatsApp language setting.

## Production configuration

Before enabling delivery, add and verify the production sender domain in Resend. `MAIL_FROM_ADDRESS` must use that verified domain; an arbitrary address may be rejected. Create a Resend API key for the production environment and store it only in the server's environment or secret manager.

Set these production environment values:

```dotenv
APP_URL=https://your-hardex-domain.example
MAIL_MAILER=resend
RESEND_KEY=re_xxxxxxxxxxxxxxxxx
MAIL_FROM_ADDRESS=no-reply@your-verified-hardex-domain.example
MAIL_FROM_NAME="HARDEX Hardware ERP"
```

`APP_URL` must be the public HTTPS HARDEX URL so password-reset buttons return to the correct application. Never commit the real `RESEND_KEY` or expose it to frontend JavaScript.

## Deployment commands

After deploying the application files and setting the environment values, run:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

No queue worker is required specifically for password-reset email delivery because this notification is intentionally sent synchronously for reliable user feedback.
