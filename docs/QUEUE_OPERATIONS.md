# Queue operations

## Process jobs

```bash
php artisan queue:work
php artisan queue:work --once
php artisan queue:work --tries=3 --timeout=120
```

Run a worker continuously in production (Supervisor recommended).

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

Report export async status:

```http
GET /reports/exports/{exportId}/status
GET /reports/exports/{exportId}/download
```

## Background job types

| Job | Trigger |
|-----|---------|
| `ProcessBulkCaMasterExportJob` | Bulk export > 200 rows |
| `ProcessBulkCaMasterImportJob` | Bulk import > 100 valid rows |
| `ProcessCampaignMessageLogsJob` | Campaign audience > 50 leads |
| `ProcessReportExportJob` | Report export > 500 rows |

Thresholds: `config/crm_queue.php`, `config/bulk.php`.

## CRM demo audit (local/testing)

```bash
php artisan crm:queue-audit
php artisan crm:queue-audit --clear-stale --force
```

`crm:queue-audit` reports row counts in `jobs`, `failed_jobs`, `queue_jobs`, and `queue_logs`.
Use `--clear-stale --force` only in **local** or **testing** to empty `jobs` and `failed_jobs`.

## Windows note

Run the queue worker in a separate PowerShell window alongside `php artisan serve`:

```powershell
php artisan queue:work
```
