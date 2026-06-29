# CA Cloud Desk CRM — Developer Guide

## Requirements

| Component | Version |
|-----------|---------|
| PHP | 8.3+ |
| Composer | 2.x |
| PostgreSQL | 14+ (recommended) |
| Node.js | 18+ (optional, for Vite assets) |

Extensions: `pdo_pgsql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `zip`

## Windows setup

1. Install [PHP 8.3](https://windows.php.net/download/) and add PHP to `PATH`.
2. Install [Composer](https://getcomposer.org/download/).
3. Install [PostgreSQL for Windows](https://www.postgresql.org/download/windows/).
4. Clone or extract the project and open a terminal in `crm-project/`.

```powershell
cd crm-project
copy .env.example .env
composer install
php artisan key:generate
```

Edit `.env`:

```env
APP_DEBUG=false
APP_ENV=local
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=crm_project
DB_USERNAME=postgres
DB_PASSWORD=your_password
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

Create the database in pgAdmin or psql:

```sql
CREATE DATABASE crm_project;
```

Run migrations and seeders:

```powershell
php artisan migrate
php artisan db:seed
```

## Running the project

Terminal 1 — web server:

```powershell
php artisan serve
```

Terminal 2 — queue worker (required for bulk import/export, campaigns, report exports):

```powershell
php artisan queue:work
```

Optional all-in-one dev script (Unix/macOS; requires `npm install`):

```bash
composer dev
```

Open: [http://127.0.0.1:8000/login](http://127.0.0.1:8000/login)

## Demo credentials

| Role | Email | Password |
|------|-------|----------|
| Super Admin | superadmin@ca.local | password |
| Admin | admin@ca.local | password |
| Manager | manager@ca.local | password |
| Sales Executive | employee@ca.local | password |

The Sales Executive (`employee@ca.local`) is row-scoped to assigned leads only.

## Important modules

| Module | Path | Notes |
|--------|------|-------|
| Dashboard | `/dashboard` | Cached metrics (60s TTL) |
| CA Master / Leads | `/ca-master` | CRUD + listing search |
| Bulk import/export | `/bulk` | Async above row thresholds |
| Assignment | `/assignment` | Lead assignment engine |
| Follow-ups | `/followups` | Scheduled follow-up tracking |
| Campaigns | `/whatsapp`, `/email`, `/sms` | Large audiences queued |
| Reports | `/reports`, `/analytics` | Cached summary + export queue |
| Activity log | `/activity` | Audit trail with before/after |
| Queue status | `/queue` | `GET /admin/queue-status` API |
| Security | `/security` | RBAC + access denied logs |

RBAC is config-driven in `config/rbac.php`.

## Queue operations

See [QUEUE_OPERATIONS.md](./QUEUE_OPERATIONS.md).

Quick reference:

```bash
php artisan queue:work
php artisan queue:failed
php artisan queue:retry all
php artisan crm:queue-audit
```

Background thresholds (`config/crm_queue.php`):

- Bulk import: > 100 valid rows
- Bulk export: > 200 rows (existing)
- Campaign logs: > 50 recipients
- Report export: > 500 rows

## Production / demo checklist

- Set `APP_DEBUG=false`
- Set a strong `APP_KEY` (`php artisan key:generate`)
- Run queue worker via Supervisor or systemd
- Use PostgreSQL (not SQLite) for production
- Schedule: `php artisan notifications:scan-due-followups` (every 15 min via cron)

## Common troubleshooting

| Issue | Fix |
|-------|-----|
| 419 / session expired | Clear browser cookies; ensure `SESSION_DRIVER=database` and migrations ran |
| Bulk export stuck on Processing | Start `php artisan queue:work` |
| Login locked out | Wait 1 minute (rate limit: 5 attempts/min per email+IP) |
| Employee sees no leads | Assign leads in Assignment module; employee must match `employees.email_id` |
| Dashboard numbers stale | Cache TTL is 60s; CRUD invalidates scoped cache |
| PostgreSQL connection refused | Verify `DB_*` in `.env` and PostgreSQL service is running |
| `SQLSTATE[42P01]` missing table | Run `php artisan migrate` |

## Manager demo flow

1. Log in as **admin@ca.local** / `password`.
2. Open **Dashboard** — review lead counts, follow-ups due, campaign metrics.
3. **CA Master** — add a lead; confirm it appears in listing.
4. **Assignment** — assign lead to Priya Sharma (Sales Executive).
5. Log out; log in as **employee@ca.local** — verify only assigned lead is visible.
6. **Follow-ups** — create a follow-up on assigned lead.
7. **Bulk** — upload sample CSV (`/ca-masters/bulk-import/sample.csv`).
8. **WhatsApp** — create a small campaign; verify message logs.
9. **Reports** — open Lead Conversion; export CSV.
10. **Activity** — confirm audit entries show performer, action, timestamp.
11. **Queue** page — call `/admin/queue-status` to verify worker health.

## Tests

```bash
php artisan test
```
