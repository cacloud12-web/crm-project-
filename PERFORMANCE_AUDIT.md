# CA Cloud Desk CRM — Performance Audit Report

**Audit type:** Read-only (no code or server configuration changes)  
**Audit date:** 2026-07-30  
**Environment audited:** Live production (`crm.caclouddesk.com` / Hostinger `us-bos-web1999`)  
**Codebase:** Local mirror used for File / Function / Query attribution  
**Method:** Live SSH diagnostics (counts, indexes, env keys, PHP/OPcache, workers) + static analysis of Laravel + CRM UI

---

## Executive verdict

Page slowness is **not** primarily from `APP_DEBUG`, Telescope, or runaway logs. Live production already has `APP_DEBUG=false` and no Debugbar/Telescope.

The dominant causes are:

1. **Hostinger shared-server load average ~22–24** (severe contention; every request waits on CPU).
2. **`QUEUE_CONNECTION=sync`** — heavy jobs run inside HTTP requests; no dedicated workers.
3. **Master / leads listing** — every page of results runs ~10 unbounded activity-history queries plus OCR geo fallback, on top of ~137k `ca_masters` rows.
4. **Frontend** — ~2.5MB of unminified JS/CSS loaded on every authenticated page, plus frequent `?all=1` catalog reloads (up to 5,000 rows).

---

## Live server snapshot (read-only)

| Check | Live value |
|-------|------------|
| PHP | 8.3.30 (`/opt/alt/php83/usr/bin/php`) |
| APP_ENV | `production` |
| APP_DEBUG | `false` |
| CACHE_STORE | `file` |
| SESSION_DRIVER | `file` |
| QUEUE_CONNECTION | `sync` |
| DB | `mysql` |
| OPcache | enabled (~32.9 MB used) |
| bootstrap/cache | `packages.php`, `services.php` only — **no** `config.php` / `routes-v7.php` / `events.php` |
| Queue workers (`queue:work` / Horizon) | **none** |
| `storage/logs` | ~344 KB (not excessive) |
| Load average | **22.34 / 23.76 / 23.36** (critical for shared hosting) |

### Table sizes (live)

| Table | Rows |
|-------|------|
| `ca_masters` | **137,065** |
| `ocr_parsed_firms` | **114,327** |
| `activity_logs` | **33,647** |
| `lead_assignment_engines` | 107 |
| `employees` | 9 |
| `call_logs` | 5 |
| `follow_ups` | 2 |
| `sales_import_rows` | 0 (empty now; code path still risky when refilled) |
| `jobs` / `failed_jobs` / `email_logs` | 0 |

### Index notes (live)

- `ca_masters` is heavily indexed (status, city, normalized phones/emails, GST/FRN, etc.).
- **Duplicate composite index:** `ca_masters_status_created_at_index` and `ca_masters_status_created_index` both cover `(status, created_at)`.
- Redundant single-column indexes exist where composites already lead with the same column (`status`, `city_id`, `source_ocr_row_id`).
- `activity_logs` indexes: `(module_name, action, created_at)`, `performed_by`, `created_at` — no dedicated subject/`ca_id` style column index if filtered that way in app code.
- `call_logs` has useful `(ca_id, called_at)` — good for per-lead call lookups.
- Missing denormalized **`last_activity_at`** on `ca_masters` forces multi-table activity work on every list/sort.

### Healthy findings

- `APP_DEBUG=false` in production.
- No Telescope / Debugbar packages detected in the codebase.
- Laravel log volume is small (~344 KB).
- OPcache is enabled.
- Many lookup indexes already exist on `ca_masters` / `ocr_parsed_firms` / `sales_import_rows`.

---

## Critical Issues

### C1 — Shared host CPU saturation (load ~22–24)

| Field | Detail |
|-------|--------|
| **File** | N/A (Hostinger shared node `us-bos-web1999`) |
| **Function** | OS scheduler / PHP-FPM pool / neighboring tenants |
| **Query** | N/A |
| **Estimated impact** | **Severe.** Load >20 means requests queue for CPU even when SQL is fast. Multiplies every other issue below. Typical TTFB inflation: 2–10× under contention. |
| **Recommended fix** | Move to a dedicated/VPS plan with reserved CPU/RAM; or reduce concurrent cron/worker load. Monitor `uptime` after app fixes — if load stays high off-peak, the host is the bottleneck. |

### C2 — `QUEUE_CONNECTION=sync` with no workers

| Field | Detail |
|-------|--------|
| **File** | Live `.env` (`QUEUE_CONNECTION=sync`); `app/Support/Queue/QueueDispatcher.php`; `routes/console.php` |
| **Function** | Job dispatch path; scheduled `queue:work` gated partly by sync |
| **Query** | N/A (jobs table empty: `jobs=0`) |
| **Estimated impact** | **Critical.** OCR, bulk import, campaign, and other `ShouldQueue` jobs run **inline in the HTTP request**. One employee action can block a PHP worker for minutes and freeze the UI for everyone on the same FPM pool. |
| **Recommended fix** | Set `QUEUE_CONNECTION=database` (or Redis), run a persistent `queue:work` (or cron drain with capacity), keep `APP_DEBUG=false`. Do **not** leave sync on multi-user production. |

### C3 — Master/leads list: 10+ unbounded activity queries per page

| Field | Detail |
|-------|--------|
| **File** | `app/Services/Leads/LeadActivityTimelineService.php` |
| **Function** | `collectEventsForCaIds()` ← `summariesForCaIds()` ← `CaMasterResource::prepareCollection()` |
| **Query** | For each page of CA IDs (e.g. 25–100): `SELECT * FROM call_logs WHERE ca_id IN (...)`; same pattern for `follow_up_histories`, `follow_ups`, `lead_actions`, `assignment_histories`, `email_logs`, `email_inbound_messages`, `wa_message_logs`, `sms_logs`, `lead_quality_histories`, plus `ca_masters` again — **all `->get()` with no LIMIT** (full history per lead, then PHP picks latest). |
| **Estimated impact** | **Critical on every Master Data / Leads listing.** ~10–11 extra round-trips + large row hydration + PHP sort per request. With concurrent employees, DB + PHP CPU spike together. |
| **Recommended fix** | Denormalize `last_activity_at` / `last_activity_type` on `ca_masters` (update on write); or one SQL `UNION ALL … GROUP BY ca_id` with `MAX(occurred_at)` only; never load full histories for list summaries. |

### C4 — Sorting by Last Activity runs a 12-branch correlated subquery per row

| Field | Detail |
|-------|--------|
| **File** | `app/Support/Listing/ListingQueryApplier.php` |
| **Function** | `applySort()` when `sort_by=last_activity_at` |
| **Query** | `ORDER BY (SELECT MAX(v) FROM ( SELECT called_at FROM call_logs WHERE ca_id = ca_masters.ca_id UNION ALL … 11 more sources … ))` — correlated **per candidate row** during sort/pagination. |
| **Estimated impact** | **Critical** when users sort Master Data by Last Activity (default column is sortable in UI). On 137k rows this can time out or take multi-seconds even for page 1 (MySQL must compute for ordering). |
| **Recommended fix** | Persist `last_activity_at` indexed column; `ORDER BY last_activity_at`. Drop the UNION subquery sort. |

### C5 — Unminified ~2.5MB CRM UI payload on every page

| Field | Detail |
|-------|--------|
| **File** | `resources/views/components/crm/scripts.blade.php`; `public/crm-ui/src/api/crm.js` (~1.1MB), `pages/pages.js` (~219KB), `styles.css` (~440KB), plus many component scripts |
| **Function** | Full SPA script include list (all modules on every authenticated page) |
| **Query** | N/A |
| **Estimated impact** | **Critical for perceived load**, especially on office networks. Parse/compile cost of 1MB+ of JS blocks interactivity; multiplies with Hostinger latency. |
| **Recommended fix** | Bundle + minify + code-split by route; gzip/brotli at edge; defer non-critical modules (OCR, demos, reports) until those pages open. |

---

## High Priority Issues

### H1 — Config / route / view caches not built on live

| Field | Detail |
|-------|--------|
| **File** | Live `bootstrap/cache/` (only `packages.php`, `services.php`) |
| **Function** | Laravel bootstrap (`config()`, route registration, Blade compile) |
| **Query** | N/A |
| **Estimated impact** | **High.** Every request reparses config files and routes. On shared PHP this adds tens–hundreds of ms under load. |
| **Recommended fix** | After deploy: `php artisan config:cache`, `route:cache`, `view:cache` (already documented in `docs/HOSTINGER_DEPLOY.md`). |

### H2 — File session + file cache drivers

| Field | Detail |
|-------|--------|
| **File** | Live `.env`: `SESSION_DRIVER=file`, `CACHE_STORE=file`; `app/Services/Cache/CrmCacheService.php` |
| **Function** | Session read/write; `Cache::remember` / version bumps |
| **Query** | Filesystem I/O under `storage/framework/sessions` and `storage/framework/cache` |
| **Estimated impact** | **High** with concurrent employees — lock contention and disk I/O on shared storage. Dashboard version bumps (`bumpDashboardCacheVersion`) leave orphaned file cache entries. |
| **Recommended fix** | Prefer `database` or Redis for session + cache on multi-user Hostinger; or Redis if available. |

### H3 — `?all=1` / `max_all=5000` catalog loads from frontend

| Field | Detail |
|-------|--------|
| **File** | `public/crm-ui/src/api/crm.js` (`listingAllQuery`); `config/listing.php` (`max_all` => 5000); `app/Support/Listing/ListingQueryApplier.php` |
| **Function** | `listingAllQuery()`; `ListingQueryApplier::apply()` when `all=true` → `$query->limit($maxAll)->get()` |
| **Query** | e.g. `GET /api/.../ca-masters?all=1`, `/employees?all=1`, `/lead-assignments?all=1`, `/follow-ups?all=1`, masters lookups — up to **5000** fully serialized resources |
| **Estimated impact** | **High.** Multi-MB JSON + heavy PHP serialization; triggered after many mutations via `invalidateDataCaches` + reload. |
| **Recommended fix** | Cap `max_all` much lower for heavy resources; use typeahead/search endpoints; page catalogs; avoid reloading full CA master set for dropdowns. |

### H4 — `max_per_page` allows up to 1000

| Field | Detail |
|-------|--------|
| **File** | `config/listing.php` (`max_per_page` => 1000) |
| **Function** | `ListingQueryApplier::resolvePerPage()` |
| **Query** | Paginated listing with `per_page=1000` plus C3 activity fan-out |
| **Estimated impact** | **High.** One user choosing 1000 rows can monopolize DB/PHP and amplify C3 (~10k+ activity queries worth of rows). |
| **Recommended fix** | Cap UI + API at 50–100 for `ca_masters`. |

### H5 — OCR geo fallback scans mapped firms for each list page

| Field | Detail |
|-------|--------|
| **File** | `app/Http/Resources/CaMasterResource.php` |
| **Function** | `loadOcrGeoFallback()` |
| **Query** | `SELECT crm_ca_id, matched_ca_id, city, state FROM ocr_parsed_firms WHERE crm_ca_id IN (…) OR matched_ca_id IN (…) ORDER BY mapped_at DESC, id DESC` against **114k** rows table |
| **Estimated impact** | **High** when city/state missing on masters — extra large OR scan per listing. |
| **Recommended fix** | Copy city/state onto `ca_masters` at map/approve time; remove list-time OCR fallback or index/limit to one row per ca_id. |

### H6 — Global dashboard cache invalidation on writes

| Field | Detail |
|-------|--------|
| **File** | `app/Services/Cache/CrmCacheService.php` |
| **Function** | `bumpDashboardCacheVersion()` / `forgetDashboardMetrics()` |
| **Query** | N/A (cache key version increment) |
| **Estimated impact** | **High under concurrent use.** Any lead/assignment write forces all users to recompute dashboard metrics (full aggregates on `ca_masters`). With file cache, old version keys linger on disk. |
| **Recommended fix** | Scope invalidation by employee/org; use Redis with tag TTL; avoid org-wide version bump for single-lead edits. |

### H7 — Case-insensitive search cannot use normal indexes

| Field | Detail |
|-------|--------|
| **File** | `app/Support/Listing/ListingQueryApplier.php` |
| **Function** | `whereIlike()` → `LOWER(column) LIKE LOWER(?)` with leading `%` |
| **Query** | e.g. `WHERE LOWER(firm_name) LIKE LOWER('%foo%')` plus `whereHas` on city/state/source/team assignments |
| **Estimated impact** | **High** on 137k-row Master search — full table / index scans + EXISTS subqueries. |
| **Recommended fix** | Prefer prefixed search on `normalized_*` indexed columns; FULLTEXT for free-text; avoid `LOWER()` wrapping indexed columns. |

### H8 — Partners eager-loaded on every listing row

| Field | Detail |
|-------|--------|
| **File** | `app/Services/Leads/CaMasterService.php` |
| **Function** | `listingRelations()` includes `'partners'` |
| **Query** | Extra `SELECT * FROM ca_master_partners WHERE ca_id IN (…)` (and large JSON payload) |
| **Estimated impact** | **Medium–High** when many partners per firm — bloated list API responses. |
| **Recommended fix** | Load partners only on detail/drawer endpoints, not list. |

### H9 — Minute-level scheduler load on shared hosting

| Field | Detail |
|-------|--------|
| **File** | `routes/console.php` |
| **Function** | `workflow:process-demo-reminders`, `campaigns:process-scheduled` every minute; `email:sync` every 5 minutes; conditional `queue:work` |
| **Query** | Depends on command (follow-ups, campaigns, inbound mail) |
| **Estimated impact** | **High on load=20+ hosts.** Cron + sync HTTP compete for the same CPU. |
| **Recommended fix** | Ensure cron exists and is healthy; with async queues, keep drains; reduce every-minute jobs if unused; never run heavy OCR sync in web requests. |

---

## Medium Priority Issues

### M1 — Sales import files endpoint: aggregate all rows then PHP-paginate

| Field | Detail |
|-------|--------|
| **File** | `app/Http/Controllers/Mapping/SalesImportController.php` |
| **Function** | `files()` |
| **Query** | `SELECT source_file_name, … COUNT(*), SUM(CASE…) FROM sales_import_rows GROUP BY source_file_name, import_batch_id` then `->get()` + Collection `slice()`; plus full-table status counts and distinct employees |
| **Estimated impact** | **Medium now** (`sales_import_rows=0` live), **High when CSV batches return** (tens of thousands of rows). |
| **Recommended fix** | Paginate in SQL (`GROUP BY` subquery + LIMIT/OFFSET); materialize file stats on `master_import_batches`. |

### M2 — Duplicate / redundant indexes on `ca_masters`

| Field | Detail |
|-------|--------|
| **File** | Live schema / migrations creating `ca_masters_*` indexes |
| **Function** | N/A (DDL) |
| **Query** | Index maintenance on INSERT/UPDATE for 137k rows |
| **Estimated impact** | **Medium** write amplification; slight read planner confusion. |
| **Recommended fix** | Drop duplicate `(status, created_at)` index; review redundant single-column indexes covered by composites. |

### M3 — Lucide loaded from unpkg CDN every page

| Field | Detail |
|-------|--------|
| **File** | `resources/views/components/crm/scripts.blade.php` |
| **Function** | External `<script src="https://unpkg.com/lucide@0.468.0/...">` |
| **Query** | N/A |
| **Estimated impact** | **Medium** — extra DNS/TLS + third-party latency; blocks icon render. |
| **Recommended fix** | Self-host Lucide (or subset of icons) on same origin. |

### M4 — Large JSON resources for list cells (last_activity object, partners, OCR geo)

| Field | Detail |
|-------|--------|
| **File** | `app/Http/Resources/CaMasterResource.php` |
| **Function** | `toArray()` / `prepareCollection()` |
| **Query** | Driven by C3/H5/H8 |
| **Estimated impact** | **Medium** — larger responses slow mobile/office clients and DataTable paint. |
| **Recommended fix** | Slim list DTO: ids + display strings only; enrich on drawer open. |

### M5 — Activity log growth without subject index

| Field | Detail |
|-------|--------|
| **File** | Live `activity_logs` indexes; `app/Services/Activity/ActivityLogService.php` |
| **Function** | Listing/filter helpers |
| **Query** | Filters on module/action/date use existing indexes; subject/`ca_id`-style filters may scan |
| **Estimated impact** | **Medium** as table grows past 33k. |
| **Recommended fix** | Confirm filter columns; add composite indexes matching real WHERE clauses; archive old logs. |

### M6 — Mail/SMTP during request path (when used)

| Field | Detail |
|-------|--------|
| **File** | `app/Services/Email/EmailSettingsService.php`, campaign/login-email flows; sync queue |
| **Function** | SMTP send / test |
| **Query** | N/A |
| **Estimated impact** | **Medium** — SMTP RTT blocks the request under `sync`. |
| **Recommended fix** | Always queue outbound mail; keep SMTP off the request thread. |

### M7 — `ocr_parsed_firms` size vs master mapping

| Field | Detail |
|-------|--------|
| **File** | Table `ocr_parsed_firms` (114k) |
| **Function** | OCR review/mapping services |
| **Query** | Match/review listings joining/filtering large OCR set |
| **Estimated impact** | **Medium** for OCR screens; secondary for Master list via H5. |
| **Recommended fix** | Archive mapped rows; keep hot indexes on `(match_status, review_status)`; avoid OR across two CA id columns without covering indexes. |

---

## Low Priority Issues

### L1 — Laravel log not a problem today

| Field | Detail |
|-------|--------|
| **File** | `storage/logs/laravel.log` (~315 KB live) |
| **Function** | Monolog |
| **Query** | N/A |
| **Estimated impact** | **Low** currently. |
| **Recommended fix** | Keep `LOG_LEVEL=error` in production; rotate if debug is ever re-enabled. |

### L2 — Telescope / Debugbar absent (good)

| Field | Detail |
|-------|--------|
| **File** | Composer packages (none found) |
| **Function** | N/A |
| **Query** | N/A |
| **Estimated impact** | None — healthy. |
| **Recommended fix** | Keep them out of production. |

### L3 — Empty `sales_import_rows` / `call_logs` today

| Field | Detail |
|-------|--------|
| **File** | Live counts |
| **Function** | N/A |
| **Query** | N/A |
| **Estimated impact** | **Low now**; patterns in C3/M1 still matter when data returns. |
| **Recommended fix** | Treat as latent risk; fix code paths before next large import. |

### L4 — OPcache memory ~33 MB

| Field | Detail |
|-------|--------|
| **File** | PHP OPcache status |
| **Function** | Zend OPcache |
| **Query** | N/A |
| **Estimated impact** | **Low** if hit rate is high; watch for eviction under many PHP files. |
| **Recommended fix** | Raise `opcache.memory_consumption` if hit rate drops (hosting panel / php.ini — ops change, not app code). |

### L5 — PHP-FPM / MySQL knobs not directly inspectable

| Field | Detail |
|-------|--------|
| **File** | Hostinger managed PHP-FPM / MySQL (shared) |
| **Function** | Pool size, `innodb_buffer_pool`, etc. |
| **Query** | N/A |
| **Estimated impact** | Unknown precisely; shared plans usually under-provision vs 137k-row CRM. |
| **Recommended fix** | Request Hostinger metrics / upgrade plan; enable slow-query log temporarily for ops (not done in this audit). |

---

## Frontend-specific summary

| Issue | Severity | Notes |
|-------|----------|-------|
| Monolithic unminified JS/CSS (~2.5MB) | Critical | Every page |
| Lucide from unpkg | Medium | Extra RTT |
| Duplicate / cascading API reloads after mutations | High | `invalidateDataCaches` + `listingAllQuery` |
| Large list payloads (`last_activity`, partners) | Medium | Slow paint |
| DataTables-style DOM rebuild of large pages | High when per_page large | Cap rows |

No Vue/React SPA framework detected — vanilla JS modules. “Unnecessary re-renders” map to full table HTML rebuilds in `crm.js` after each fetch.

---

## Database summary (requested tables)

| Table | Live rows | Index health | Performance risk |
|-------|-----------|--------------|------------------|
| `ca_masters` | 137,065 | Many indexes; **duplicate** `(status,created_at)`; missing `last_activity_at` | List + search + sort |
| `ocr_parsed_firms` | 114,327 | Good doc/match indexes | Geo fallback OR queries |
| `sales_import_rows` | 0 | Well indexed | `files()` full group-by when populated |
| `employees` | 9 | Fine | Low |
| `activity_logs` | 33,647 | Module/action/date | Growth; subject filters |
| `call_logs` | 5 | `ca_id`, `(ca_id,called_at)` | Low volume; still queried unbounded in C3 |

---

## Priority remediation order (recommendations only — not applied)

1. Address host load / plan capacity (C1).
2. Switch queue off `sync` + run workers (C2).
3. Denormalize last activity; remove list fan-out + UNION sort (C3, C4).
4. Build Laravel caches; move session/cache off pure files (H1, H2).
5. Minify/split frontend; kill `all=1` for heavy resources (C5, H3, H4).
6. Slim list resources; fix search to use normalized columns (H7, H8, M4).

---

## Artifacts

- `PERFORMANCE_AUDIT.md` (this file)
- `PERFORMANCE_AUDIT.json` (machine-readable twin)

**No application code, migrations, `.env`, or server configuration were modified as part of this audit.**
