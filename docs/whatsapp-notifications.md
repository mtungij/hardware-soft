# HARDEX WhatsApp notifications

HARDEX uses the unofficial GOWA multi-device service for expected operational and transactional notifications. This is not the official WhatsApp Business API. Rate limits, quiet hours, and serialization reduce bursts; they do not guarantee that a WhatsApp account will not be restricted or banned.

## Environment

Configure global server credentials only in the deployment environment:

```dotenv
GOWA_URL=https://notify.buildcore.site
GOWA_USERNAME=...
GOWA_PASSWORD=...
GOWA_TIMEOUT=30
GOWA_CONNECT_TIMEOUT=10
```

Do not put a global device ID in the environment. Each company configures its own Device ID under **Settings → WhatsApp Notifications**. The application includes that exact ID in `X-Device-Id` on every device-scoped GOWA request.

## Queue and scheduler

WhatsApp delivery uses the `whatsapp` queue on the application's configured queue connection. Production must run a persistent worker and Laravel scheduler, for example:

```bash
php artisan queue:work --queue=whatsapp,default --tries=3 --timeout=90
php artisan schedule:work
```

Use the repository/deployment platform's existing process supervisor to keep both processes running. The database queue driver is the repository default, so queued notifications survive web-request completion and worker restarts.

Scheduled tasks:

- device health check every five minutes;
- company-timezone daily summaries every minute (only the configured minute creates an idempotent outbox entry);
- aggregated low/out-of-stock checks every thirty minutes.
- grouped due/overdue debt reminders hourly (only 08:00 in each company timezone creates an idempotent outbox entry).

## Operational behavior

- Business changes commit before their observers create outbox entries.
- Workers serialize sends per hashed Device ID and apply company-configured minimum intervals, per-minute limits, and per-hour limits.
- Non-urgent messages remain pending during quiet hours or while a device is disconnected.
- Phone availability checks are cached for 24 hours by device and number.
- Temporary HTTP failures retry after 10, 60, and 300 seconds; terminal failures remain visible for authorized manual retry.
- The notification log distinguishes pending, queued, sending, sent, failed, suppressed, and cancelled records.
- There is no unrestricted customer mass-blast feature and no anti-detection or policy-bypass behavior.

Global GOWA credentials are never stored in company records, Livewire state, notification metadata, or audit records.
