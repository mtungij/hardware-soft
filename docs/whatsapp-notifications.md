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
php artisan queue:work database --queue=whatsapp,default --sleep=3 --tries=3 --timeout=90
php artisan schedule:work
```

Locally, `composer dev` starts a queue listener for `whatsapp,default` alongside the web/Vite processes. It uses the configured connection, so Redis is not required when `QUEUE_CONNECTION=database`. Run either this launcher or the standalone worker above. Starting only `php artisan serve` does not start a worker. A default-only worker does not consume WhatsApp jobs.

Production process definitions are in `deploy/supervisor/hardex-queue.conf`. Adjust `/var/www/hardex`, `/usr/bin/php`, and `www-data` to match the host, then install the file in `/etc/supervisor/conf.d/` and run `supervisorctl reread` followed by `supervisorctl update`. Both worker and scheduler autostart and restart on failure. Run one scheduler mechanism; omit the scheduler program if cron already invokes it. The production worker omits an explicit connection and therefore respects `QUEUE_CONNECTION` (database or Redis). Use a shared persistent cache for worker locks/rate limits on multi-host deployments.

Set `DB_QUEUE_RETRY_AFTER=120` for database, or `REDIS_QUEUE_RETRY_AFTER=120` for Redis. The reservation timeout must exceed the worker's 90-second timeout. The WhatsApp job itself has a 60-second timeout, a fixed 24-hour retry deadline, and at most three thrown delivery exceptions. Quiet-hour, device-lock and rate-limit releases do not consume that exception budget. Existing serialized jobs retain their original retry policy; do not retry historical failed jobs indiscriminately.

After configuration changes, refresh the deployment configuration cache and run `php artisan queue:restart`. After code changes, restart existing long-lived workers so they load the new code. Do not activate a worker against an unreviewed historical backlog: it will process already-enqueued jobs. `debug_after_commit` notifications are blocked at delivery, even if their jobs already exist.

### Missing-job recovery

Inspect without changing or delivering messages:

```bash
php artisan whatsapp:recover-queued --company=3
```

After reviewing the eligible records, use the same command with `--dispatch`. It handles up to 100 records per run, only for that company: queued for at least five minutes, created within the last hour, zero delivery attempts, available now, current enabled/unpaused company/device/category/recipient settings. It excludes debug, failed, sent, sending, suppressed, cancelled, attempted, scheduled-future and historical records. It uses the existing retry/after-commit dispatch path.

Database ready/delayed/reserved jobs and Redis ready/delayed/reserved lists are checked before dispatch. An existing job is never replaced or duplicated. Unknown drivers, unavailable backends, unreadable payloads or a scan above 1,000 jobs fail closed. A row lock and refreshed queue timestamp protect concurrent recovery. Event idempotency only dispatches newly-created outbox rows; workers atomically claim eligible rows, and sent/cancelled/suppressed/failed rows are not delivered by duplicate jobs. No `queue:retry all` is needed.

Automatic recovery is disabled by default. To explicitly enable it for a reviewed company, set `WHATSAPP_QUEUE_RECOVERY_ENABLED=true` and `WHATSAPP_QUEUE_RECOVERY_COMPANY_ID=<company-id>`. The scheduler checks that company's recent eligible rows every five minutes. Repeat manual recovery for other companies as needed. Drain/review the old backend before switching connections; recovery can inspect only the active connection.

The log warns when available queued rows have waited over five minutes with zero attempts; this is a backlog indicator, not proof that a worker is stopped. Attempts increment immediately before the GOWA number check/send phase, never for worker reservations, throttle releases, or recovery. Timeout/exhausted exceptions mark the notification failed with a reason and timestamp. Unexpected termination during a send can leave an ambiguous `sending` result: recovery deliberately does not resend it. GOWA does not provide a documented provider-side idempotency guarantee, so delivery after an ambiguous network failure cannot guarantee exactly once.

Scheduled tasks:

- device health check every five minutes;
- company-timezone daily summaries every minute (only the configured minute creates an idempotent outbox entry);
- aggregated low/out-of-stock checks every thirty minutes.
- grouped debt summaries and customer due/overdue reminders every minute (only the company-configured minute creates idempotent outbox entries).

The Laravel scheduler itself must be invoked once per minute in production. When `schedule:work` is not managed as a persistent process, use the standard cron entry:

```cron
* * * * * cd /path/to/hardex && php artisan schedule:run >> /dev/null 2>&1
```

## Daily PDFs and debt reminders

- Daily PDF data is built separately for every recipient. Company isolation is applied first, then the linked user's sales/report scope and existing permissions. Phone-only recipients get only the restrictive operational dataset and never inherit financial permissions.
- Debt reminders use the existing completed credit sale `balance_amount` and `expected_payment_date`; customer payments already update that balance transactionally through `AccountingService` and `CustomerPaymentAllocation`.
- Management summaries require a linked HARDEX user with an existing receivables permission and are aggregated into one message per recipient/day. An optional debtor PDF uses the same scoped rows.
- Customer messages are one-receivable transactional reminders. Due-tomorrow, due-today, overdue, reminder time, and overdue interval are company configurable. Customers can be opted out on their customer record.
- The worker reloads the authoritative sale immediately before delivery. Settled, disabled, rescheduled, or no-longer-due reminders are suppressed; partially paid balances cause the customer-only message to be rebuilt with the current remainder.

## Operational behavior

- Business changes commit before their observers create outbox entries.
- Workers serialize sends per hashed Device ID and apply company-configured minimum intervals, per-minute limits, and per-hour limits.
- Non-urgent messages remain pending during quiet hours or while a device is disconnected.
- Phone availability checks are cached for 24 hours by device and number.
- Temporary HTTP failures retry after 10, 60, and 300 seconds; terminal failures remain visible for authorized manual retry.
- The notification log distinguishes pending, queued, sending, sent, failed, suppressed, and cancelled records.
- There is no unrestricted customer mass-blast feature and no anti-detection or policy-bypass behavior.

Global GOWA credentials are never stored in company records, Livewire state, notification metadata, or audit records.
