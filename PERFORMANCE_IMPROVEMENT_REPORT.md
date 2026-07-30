# PERFORMANCE IMPROVEMENT REPORT

**Product:** CA Cloud Desk CRM (Laravel)  
**Date:** 2026-07-30  
**Method:** Live production diagnostics + static profiling + targeted safe code optimizations  
**Rules followed:** No feature removal, no UI redesign, no business-rule changes, no data mutation  

Companion investigation: `PERFORMANCE_AUDIT.md` / `PERFORMANCE_AUDIT.json`

---

## Executive summary

| Rank | Bottleneck | Proof | Status |
|------|------------|-------|--------|
| 1 | Shared Hostinger load ~22–24 | Live `uptime` | Ops (host plan) — not code |
| 2 | Master list activity fan-out (~11 full `->get()`s) | `LeadActivityTimelineService` | **Fixed** — latest-row only for list summaries |
| 3 | `QUEUE_CONNECTION=sync` | Live `.env` | **Documented** — switch to `database` + workers |
| 4 | Unminified ~2.4MB CRM UI every page | Blade script includes | Pending (bundle/split) — UI unchanged |
| 5 | `per_page` up to 1000 / `all=1` up to 5000 | `config/listing.php` | **Capped** safely |
| 6 | OCR geo scan on every list page | `CaMasterResource::loadOcrGeoFallback` | **Gated** — only when city/state missing |
| 7 | Missing Laravel config/route/view cache | Live `bootstrap/cache` | Ops deploy step |
| 8 | `last_activity_at` UNION sort | `ListingQueryApplier` | Documented — needs denormalized column (next) |

---

## Phase 1 — Page profile (evidence-based)

Live HTTP timings were not instrumented with Debugbar (correctly absent in production). Estimates combine live table sizes, measured asset sizes, and code-path query counts.

| Page | Dominant cost | Queries (order of magnitude) | Notes |
|------|---------------|------------------------------|-------|
| Login | Blade + session | Low | Healthy |
| Dashboard | Cached COUNTs (120s) | Cold: ~15–25 aggregates | Cache hit: fast |
| **Master Data** | Activity summaries + paginate COUNT + eager loads + OCR geo | Was ~20+ heavy; activity was worst | **Primary fix applied** |
| Leads | Same `ca_masters` listing path | Same as Master | Same fix |
| Sales List | Listing + filters | Medium | `sales_list_entries` empty live |
| Call Log | Indexed `ca_id` | Low volume live | Fine |
| Google research | External HTTP | 1+ Places calls when uncached | DB JSON short-circuit exists |
| Bulk Import | Jobs inline if sync | High when running | Queue ops fix |
| OCR | Jobs + large `ocr_parsed_firms` | High | Must be async |
| Employees | Tiny table (9) | Low | Fine |
| Reports | Cached summaries | Medium cold | Existing TTL |

**Frontend cost (every authenticated page):** ~2.39MB JS/CSS + Lucide CDN — parse/compile dominates TTI on slow networks.

---

## Phase 2 — Database hotspots

### Critical query pattern (fixed)

**Before:** For each Master/Leads page of N CAs:

```text
SELECT * FROM call_logs WHERE ca_id IN (...);          -- ALL history
SELECT * FROM follow_up_histories WHERE ca_id IN (...);
… (9 more unbounded gets)
```

**After:** Window `ROW_NUMBER() … WHERE activity_rn = 1` → at most **one row per CA per source table**, then same `formatSummary()` shape.

| Field | Detail |
|-------|--------|
| File | `app/Services/Leads/LeadActivityTimelineService.php` |
| Function | `summariesForCaIds` → `collectLatestEventsForCaIds` |
| Timeline | `timelineForLead` still uses full `collectEventsForCaIds` (unchanged) |
| Estimated improvement | **50–90%** less DB/PHP work on Master list when histories grow; large reduction in memory |

### Still open (do not “fake” sort)

| Query | File | Impact |
|-------|------|--------|
| Correlated 12-way `UNION ALL` for `ORDER BY last_activity_at` | `ListingQueryApplier::applySort` | Severe when users sort by Last Activity |
| `LOWER(col) LIKE '%…%'` search | `ListingQueryApplier::whereIlike` | Full scan on 137k rows |
| Duplicate `(status, created_at)` index | Live schema | Write amplification |

**Recommended next index/feature (not applied — needs migration + write hooks):**

```sql
ALTER TABLE ca_masters
  ADD COLUMN last_activity_at DATETIME NULL,
  ADD INDEX ca_masters_last_activity_at_index (last_activity_at);
```

Update on call/follow-up/email/etc. writes; replace UNION sort.

---

## Phase 3 — Master Data (completed work)

| Change | Preserves |
|--------|-----------|
| Latest-only activity summaries | Identical `last_activity` JSON shape |
| OCR geo only for missing city/state | Same fallback when needed |
| `max_per_page=100`, `allowed=[10,25,50,100]`, `max_all=500` for `ca_masters` | Same UI; API rejects pathological 1000-row pages |
| Eager loads unchanged (city/state/partners/assignments) | Expand/partner UI intact |

**Why search/filters feel slow:** `LOWER(column) LIKE '%text%'` cannot use normal B-tree indexes on 137k rows + `whereHas` on relations. Fix later via `normalized_*` prefix / FULLTEXT — not applied (would need careful search semantics review).

---

## Phase 4 — Dashboard

| Finding | Detail |
|---------|--------|
| Metrics | Already cached `crm:dashboard:metrics:v{n}:…` TTL **120s** |
| Segment counts | `crm:leads:segment_counts:{scope}` TTL **120s** |
| Cold build | Multiple `COUNT` / filtered aggregates on `ca_masters` / follow-ups / demos |
| Risk | Org-wide `bumpDashboardCacheVersion` on writes forces cold rebuilds |

**No incorrect caching of other users’ data found** — keys include `scopeKey` / employee id.

**Recommendation (ops):** Prefer Redis/database cache over `file` under concurrency; keep TTL.

---

## Phase 5 — Background jobs

| Live | `QUEUE_CONNECTION=sync`, **no** workers |
|------|------------------------------------------|
| Impact | OCR, bulk import/export, campaigns, IMAP sync block PHP-FPM |
| Safe fix | `QUEUE_CONNECTION=database` + cron `schedule:run` (already drains when not sync) |
| Example updated | `.env.hostinger.example` |

Did **not** change live `.env` from this session (requires deploy access + worker confirmation).

---

## Phase 6 — Frontend

| Asset | Size |
|-------|------|
| `crm.js` | ~1.11 MB |
| `styles.css` | ~440 KB |
| `pages.js` | ~219 KB |
| Total crm-ui scripts+CSS | ~2.39 MB |
| Delivery | Raw Blade `<script>` tags — **not Vite** for CRM UI |
| Vite | Only Laravel default welcome assets |

**Safe next steps (not done — large UI build change):** minify + code-split OCR/demo/report modules; self-host Lucide; gzip at edge.

---

## Phase 7 — Laravel production config

| Check | Live | Action |
|-------|------|--------|
| APP_DEBUG | `false` | OK |
| OPcache | enabled | OK |
| config/route/view/event cache | **Missing** | Run after deploy |
| Composer `-o` | Unknown on live | `composer install --optimize-autoloader --no-dev` |

---

## Phase 8 — Cache / session / queue recommendation

| Driver | Current live | Recommended production |
|--------|--------------|------------------------|
| Cache | `file` | `database` or Redis |
| Session | `file` | `database` (or Redis) |
| Queue | `sync` | **`database`** (+ worker/cron) |

---

## Phase 9 — API

| Guard | Change |
|-------|--------|
| `max_all` global | 5000 → **1000** |
| `ca_masters` `max_all` | **500** |
| `ca_masters` `max_per_page` | **100** |
| Master list columns | Still excludes `google_places_cache` via listing config |

---

## Phase 10 — Index audit (recommend only)

| Table | Recommendation | Why |
|-------|----------------|-----|
| `ca_masters` | Add `last_activity_at` + index | Kill UNION sort |
| `ca_masters` | Drop duplicate `(status,created_at)` index | Write cost |
| Activity tables | Ensure `(ca_id, <time>)` where missing | Latest-row window |
| `activity_logs` | Index real filter columns if subject lookup used | 33k+ growth |
| Do **not** add speculative indexes | — | Avoid write bloat |

No new indexes applied in this pass (avoid unproven DDL on production without EXPLAIN of denormalized path).

---

## Phase 11 — Google API

| Finding | Location |
|---------|----------|
| No Laravel Cache layer on Places HTTP | `GooglePlacesApiService` |
| Skips API when `google_place_id` set | `LeadResearchService` |
| Stores JSON on lead | `google_places_cache` |

**Safe later:** short TTL cache of searchText responses keyed by query hash; never block list pages (research is explicit action).

---

## Phase 12–13 — Implemented optimizations (this pass)

1. **`LeadActivityTimelineService`** — list summaries use latest-row SQL; timeline unchanged.  
2. **`CaMasterResource::prepareCollection`** — OCR geo fallback only when city/state missing.  
3. **`config/listing.php`** — safer pagination ceilings.  
4. **`.env.hostinger.example`** — queue=`database` + cache artisan reminder.  
5. **Tests** — `LeadLastActivityTest` **3/3 passed**.

---

## Before vs after (expected)

| Metric | Before | After (code) | After (ops still needed) |
|--------|--------|--------------|---------------------------|
| Master list activity rows loaded | All histories for page CAs | ≤1 per source × CA | Same |
| Master `per_page` max | 1000 | 100 | — |
| Master `?all=1` max | 5000 | 500 | — |
| OCR geo on list | Always for page | Only missing geo | — |
| Queue blocking HTTP | Yes (sync) | Unchanged until deploy | Switch to database |
| JS/CSS payload | ~2.39 MB | Unchanged | Bundle/split |
| Host load | ~22–24 | Unchanged | Upgrade plan |
| `last_activity` API shape | — | **Preserved** | — |
| Features / UI | — | **Preserved** | — |

**Realistic user-visible Master Data improvement after deploy of this code:** often **2–5×** faster list API on activity-heavy pages; more if ops fixes queue + Laravel caches + host load.

---

## Deploy checklist (ops — no app logic change)

```bash
# On production after pulling code:
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
# Set QUEUE_CONNECTION=database in .env, then:
php artisan config:cache
# Ensure cron: * * * * * php artisan schedule:run
```

---

## Validation performed

| Check | Result |
|-------|--------|
| `LeadLastActivityTest` | Passed (3 tests) |
| Timeline path | Untouched full collector |
| List summary shape | Same `formatSummary` fields |
| UI templates | Not modified |
| Business rules | Not modified |
| Database data | Not modified |

---

## Next highest-impact items (not in this PR)

1. Denormalize `last_activity_at` + replace UNION sort.  
2. Production queue off `sync`.  
3. Build Laravel caches on deploy.  
4. Minify/code-split crm-ui.  
5. Hostinger plan with reserved CPU (load 22+ dominates everything).
