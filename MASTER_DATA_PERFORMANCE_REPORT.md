# Master Data (`/ca-masters`) Performance Report

Date: 2026-07-30  
Environment profiled: local SQLite (~20.4k active `ca_masters`)  
Production scale (prior audit): ~137k rows on Hostinger MySQL — same code path applies, absolute times will be higher under load.

## 1. Root cause (measured, not guessed)

Opening Master Data hits `GET /ca-masters` (SPA listing). Profiling that API showed time spent in:

| Bottleneck | Evidence (BEFORE) | Impact |
|---|---|---|
| **Activity summaries** | ~83 schema/`pragma` queries + ~10 window queries + full Eloquent hydrates per source | Dominated query count; on MySQL each `information_schema` hit is expensive |
| **`COUNT(*)` pagination** | Cold `select count(*) … deleted_at is null` ~**241ms**; `EXPLAIN` was **SCAN ca_masters** (no soft-delete index) | Dominant wall time on cold/large tables |
| **Employee ID N+1** | **50** identical `employees` lookups (2× per row via `LeadLockService` → `resolveEmployeeId`) | ~50 wasted round-trips every page |
| **OCR geo N+1** | Per-row `ocr_parsed_firms` when city was placeholder but `city_id` set | Extra queries for incomplete geo rows |
| **Duplicate assignment eager load** | `activeAssignment` + `activeTeamAssignments` both queried `lead_assignment_engines` | Duplicate relation query |
| **Partners `SELECT *`** | Full partner rows for list payload | Extra I/O vs columns the UI needs |

Default sort is `created_at` (not `last_activity_at`). Sorting by **Last Activity** still uses a correlated 12-way UNION per row — **remaining bottleneck** if users sort that way.

## 2. Before vs after (same machine, same dataset)

| Metric | BEFORE | AFTER (cold) | AFTER (warm) |
|---|---|---|---|
| API wall time | **298.9 ms** | **89.3 ms** (~3.3×) | **16.7–29 ms** |
| Query count | **105** | **16** | **13** |
| Schema introspection | **~83** | **5** | **5** |
| Employee ID lookups | **~50** | **2** | **0** (memoized) |
| Activity path | ~10× (schema+window+hydrate) | **1 UNION** | **1 UNION** |
| Slowest query | COUNT ~**241 ms** (full SCAN) | COUNT ~**11 ms** (covering index) | Activity UNION ~0.7–1.7 ms |
| COUNT on repeat open | every request | every cold / cache miss | **0** (30s unfiltered cache) |
| `last_activity` cells | present when authed | **25/25** | **25/25** |
| Memory peak | ~34–36 MB | ~34 MB | ~36 MB |

## 3. Files modified

- `app/Services/Leads/LeadActivityTimelineService.php` — union-based list summaries; correct non-`id` PKs
- `app/Support/Database/SchemaMemo.php` — request memo for schema/column listing
- `app/Support/Listing/ListingQueryApplier.php` — SchemaMemo + optional unfiltered total cache
- `app/Services/Concerns/SearchesListings.php` — inject scope key for total cache
- `app/Services/Leads/CaMasterService.php` — drop duplicate assignment eager load; project partner columns
- `app/Http/Resources/CaMasterResource.php` — team-assignment skip for executive prefetch; OCR batch/placeholder parity
- `app/Services/Rbac/EmployeeDataScopeService.php` — memoize `resolveEmployeeId`
- `app/Services/Leads/LeadOwnershipService.php` — use memoized employee resolve
- `config/listing.php` — `cache_unfiltered_total` for `ca_masters`
- `database/migrations/2026_07_30_180000_add_master_data_listing_performance_indexes.php`

## 4. Queries optimized

1. List `last_activity`: multi-source fan-out → **one UNION ALL + outer ROW_NUMBER**
2. Pagination COUNT on default open → **30s scoped cache** when no search/filters/dates
3. Soft-delete COUNT → uses **`ca_masters_deleted_created_index`** (EXPLAIN: COVERING INDEX)
4. Lock/ownership employee lookup → **1 resolve per request** (memoized)
5. OCR geo → batch + sentinel map (no per-row reload)
6. Assignments → single `activeTeamAssignments` eager load
7. Partners → explicit column list

## 5. Indexes added

| Index | Purpose |
|---|---|
| `ca_masters_deleted_created_index` (`deleted_at`, `created_at`) | Soft-delete COUNT + default sort |
| `lead_actions_ca_action_at_index` (`ca_id`, `action_at`) | Activity window (table previously unindexed) |
| `email_logs_ca_sent_at_index` | Activity |
| `sms_logs_ca_sent_at_index` | Activity |
| `wa_message_logs_ca_sent_at_index` | Activity |

## 6. Remaining bottlenecks

- **Sort by Last Activity** still uses correlated multi-UNION — denormalize `ca_masters.last_activity_at` for a durable fix
- **Hostinger load / `QUEUE_CONNECTION=sync` / Cloudflare** still affect page open independently of this API
- **Unminified CRM JS/CSS (~2MB+)** still on first SPA paint — frontend asset work separate from this API path
- Lookup bootstrap (`ensureMasterData`: sources/states/roles) still runs in parallel on first Master Data visit

## 7. Functionality confirmation

Preserved (no business-logic removals):

- Filters, search, pagination, permissions / employee scope  
- Remarks / call logs / activity cell (`last_activity`)  
- Partners, merge UI data, employee assignment (`activeTeamAssignments` + executive fields)  
- Default sort `created_at desc`

Deploy: run migration `2026_07_30_180000_add_master_data_listing_performance_indexes` on production, then `php artisan optimize` (config/route/view cache) if safe on Hostinger.
