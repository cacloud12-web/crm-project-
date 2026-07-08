# Queue operations

Background processing for email/SMS/WhatsApp campaigns, IMAP inbox sync, bulk import/export, and report exports.

## Local development

### Option 1 — Inline jobs (simplest)

Set in `.env`:

```env
QUEUE_CONNECTION=sync
```

Jobs run immediately during the HTTP request or Artisan command. **No `queue:work` required.**

Campaign send and inbox sync still return quickly when the service dispatches work through `QueueDispatcher::dispatchOrRun()` — with `sync`, the job runs before the response is sent, but the UI does not block on long IMAP scans because inbox sync is dispatched as a job from the controller.

### Option 2 — Database queue + scheduler auto-drain (recommended for local)

```env
QUEUE_CONNECTION=database
CRM_QUEUE_AUTO_DRAIN=true
```

Run the scheduler in a second terminal:

```bash
php artisan schedule:work
```

Every minute the scheduler:

- Drains pending jobs via `queue:work --stop-when-empty`
- Runs `campaigns:process-scheduled` for due campaigns
- Runs `email:sync` every 5 minutes (IMAP background job)

### Option 3 — Database queue + manual worker

```env
QUEUE_CONNECTION=database
CRM_QUEUE_AUTO_DRAIN=false
```

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=120
```

## Production

### Queue worker (Supervisor)

Prefer a dedicated long-running worker over scheduler auto-drain.

```ini
[program:crm-queue-worker]
command=php /path-to-project/artisan queue:work --sleep=3 --tries=3 --timeout=120
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path-to-project/storage/logs/queue-worker.log
```

Set:

```env
QUEUE_CONNECTION=database
CRM_QUEUE_AUTO_DRAIN=false
```

Use Redis instead of database if you prefer:

```env
QUEUE_CONNECTION=redis
```

### Scheduler (cron)

```cron
* * * * * php /path-to-project/artisan schedule:run >> /dev/null 2>&1
```

Scheduled tasks include:

| Task | Interval |
|------|----------|
| `campaigns:process-scheduled` | Every minute |
| `email:sync` | Every 5 minutes |
| `queue:work --stop-when-empty` | Every minute (only if `CRM_QUEUE_AUTO_DRAIN=true`) |

## Process jobs manually

```bash
php artisan queue:work
php artisan queue:work --once
php artisan queue:work --tries=3 --timeout=120
```

## Inspect failures

```bash
php artisan queue:failed
php artisan queue:retry all
php artisan queue:retry {uuid}
php artisan queue:flush
```

Failed job handlers update bulk imports, exports, campaigns, and report exports to `Failed` status.

## Clear database queue

```bash
php artisan queue:clear database
```

## CRM queue status API

Authenticated admins/managers with reports permission:

```http
GET /admin/queue-status
```

Returns pending/failed job counts and recent failure summaries.

## Background job types

| Job | Trigger |
|-----|---------|
| `ProcessEmailCampaignDeliveryJob` | Email campaign send / retry |
| `ProcessCampaignMessageLogsJob` | Campaign audience > `CRM_CAMPAIGN_LOG_SYNC_LIMIT` (default 50) |
| `ProcessSmsCampaignJob` | SMS campaign send |
| `ProcessWhatsAppCampaignJob` | WhatsApp campaign send |
| `SyncEmailImapJob` | Manual inbox sync, scheduled `email:sync` |
| `ProcessBulkCaMasterExportJob` | Bulk export > 200 rows |
| `ProcessBulkCaMasterImportJob` | Bulk import > 100 valid rows |
| `ProcessReportExportJob` | Report export > 500 rows |

Thresholds: `config/crm_queue.php`, `config/bulk.php`.

## IMAP sync

- Manual **Sync Latest Emails** dispatches `SyncEmailImapJob` (quick mode, ~25 newest messages).
- Scheduler `email:sync` dispatches the same job in `scheduled` mode (incremental UID/date window).
- UI polls `/email-inbox/metrics` until `sync_in_progress` is false.

## Campaign status flow

`Pending` → `Processing` → `Completed` / `Partial` / `Failed`

Jobs use `ShouldBeUnique` per campaign ID to prevent duplicate sends on double-click or retries.

## CRM demo audit (local/testing)

```bash
php artisan crm:queue-audit
php artisan crm:queue-audit --clear-stale --force
```

## Windows note

Run the queue worker or scheduler in a separate PowerShell window alongside `php artisan serve`:

```powershell
php artisan schedule:work
# or
php artisan queue:work
```
