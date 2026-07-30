# PERFORMANCE OPTIMIZATION REPORT

**Product:** CA Cloud Desk CRM (Laravel 12 + MySQL)  
**Date:** 2026-07-30  
**Method:** Profile first (live audit + static query inventory + local query-log measurements), then optimize only proven hotspots  
**Rules:** No feature removal, no business-rule changes, no UI redesign  

Related: `PERFORMANCE_AUDIT.md`, `PERFORMANCE_IMPROVEMENT_REPORT.md`

---

## 1. Step 1 — Profile (baseline)

Live host already profiled (shared Hostinger load ~22–24). Application hotspots confirmed by code + local query logs:

| Route / path | Controller / service | Queries (before) | Why slow |
|--------------|----------------------|------------------|----------|
| `GET /dashboard/metrics` (org) | `DashboardService::buildMetrics` | ~40–80+ cold | Nested productivity rebuild + org target N×employee + 8 demo counts + reports |
| `GET /dashboard/metrics` (employee filter) | same + `ManagerEmployeeProductivityService` | duplicate lead/demo/follow-up counts | Same facts queried twice |
| `GET /dashboard/employee` | `EmployeeDashboardService` + `todayProgress` | YTD day-loop | **6 counts × working days** (~1,200/employee/year) |
| Assignment targets / YTD | `YearlyEmployeeTargetProgressService` | same day-loop | Blocks assignment + dashboard cards |
| `GET /ca-masters` | `CaMasterController` + activity summaries | ~20 activity + paginate | Already improved to latest-row; still multi-round-trip |
| Pipeline / kanban | `CaMasterService::kanbanBoard` | 1 + N stages | Separate stage queries (unchanged — correct isolation) |
| Login | SPA/Blade | Low | Healthy |

**Local measured before → after (same machine, empty demo data):**

| Operation | Before | After |
|-----------|--------|-------|
| `DemoMetricsService::aggregateForRange` | **8** queries | **2** (1 aggregate + 1 purchased) |
| YTD achievements (60 working days) | **~360** queries | **6** |
| Org target achievements (3 employees, 1 day) | **~24** queries | **6** |
| Org dashboard nested `productivity(null)` | Full rebuild | **Skipped** (panel only for selected employee) |

---

## 2. Top slow patterns (implemented fixes)

### P0 — YTD day loop (Assignment + Employee Dashboard)

**Was slow:** `foreach ($workingDates as $date) { achievementsForEmployee(...) }` → 6 `count()` × ~200 days.

**Fix:** `DailyEmployeeTargetProgressService::achievementsForEmployeeOnDates` — range `GROUP BY DATE(...)` then sum only working days in PHP. Same totals, 6 queries total.

**Files:**  
- `app/Services/Assignment/DailyEmployeeTargetProgressService.php`  
- `app/Services/Assignment/YearlyEmployeeTargetProgressService.php`

### P0 — Org daily target N×employee

**Was slow:** per employee: achievements (6) + 2 demo counts.

**Fix:** `achievementsForEmployeesOnDate` (6 grouped queries for all IDs) + 2 scoped demo counts for the ID set.

**File:** `app/Services/Assignment/EmployeeTargetService.php`

### P0 — Demo metrics 8 counts

**Was slow:** eight separate `distinct()->count()` calls.

**Fix:** one `COUNT(DISTINCT CASE WHEN …)` select + purchased `whereHas` kept separate (join semantics).

**File:** `app/Services/Dashboard/DemoMetricsService.php`

### P0 — Duplicate org productivity rebuild

**Was slow:** org dashboard always called `managerEmployeeProductivity->productivity(null)` which re-ran lead/demo/follow-up aggregates. UI hides that panel unless `scope === 'employee'`.

**Fix:** only build employee productivity when a filter employee is selected.

**File:** `app/Services/Dashboard/DashboardService.php`

### P1 — Follow-up activity feed N+1

**Was slow:** `appendCommunicationLogs` per `ca_id` → 3 queries × N.

**Fix:** batch `whereIn(ca_id)` for email/sms/wa once.

**File:** `app/Services/FollowUp/LeadActivityTimelineService.php`

### P1 — Indexes for target/demo aggregates

**Migration:** `database/migrations/2026_07_30_130000_add_target_dashboard_performance_indexes.php`

| Index | Table | Columns |
|-------|-------|---------|
| `demo_schedules_employee_created_index` | demo_schedules | `(employee_id, created_at)` |
| `demo_schedules_employee_status_updated_index` | demo_schedules | `(employee_id, status, updated_at)` |
| `ca_masters_mobile_no_index` | ca_masters | `mobile_no` (if missing) |
| `call_logs_employee_called_at_index` | call_logs | `(employee_id, called_at)` |
| `email_logs_employee_created_at_index` | email_logs | `(employee_id, created_at)` |
| `sms_logs_employee_created_at_index` | sms_logs | `(employee_id, created_at)` |

Safe: skips if index already exists (`MigrationIndexHelper`).

### Profiler tooling

**File:** `app/Console/Commands/CrmProfileHotPathsCommand.php`  
**Usage:** `php artisan crm:profile-hot-paths` (optional `--token` / `--base`)

---

## 3. N+1 inventory

| Location | Issue | Status |
|----------|-------|--------|
| `YearlyEmployeeTargetProgressService::achievementsOnWorkingDays` | Day × 6 counts | **Fixed** |
| `EmployeeTargetService::organizationTodayTotals` | Employee × 8 counts | **Fixed** |
| `DemoMetricsService::aggregateForRange` | 8 serial counts | **Fixed** |
| `FollowUp\LeadActivityTimelineService` communication logs | Per-ca_id × 3 | **Fixed** |
| `DailyEmployeeTargetService::buildList` → progress per row | Still 6/employee/day when listing many target rows | Partially helped by batch API (list can adopt later) |
| Master list activity | ~10 source tables × 2 (window+hydrate) | Earlier fix (latest-row); further UNION still open |
| Kanban stages | 1 query per stage | Acceptable / intentional |

---

## 4. Missing indexes (audit)

**Already present (do not duplicate):** `ca_masters.status`, `(status,created_at)`, `city_id`, `normalized_firm_name`, `normalized_mobile`, follow_ups employee/status/date composites, lead_assignment_engines status/employee/ca_id.

**Added this pass:** demo_schedules employee+date, call/email/sms employee+date, mobile_no btree if absent.

**Still open (needs denormalized column, not only index):**  
`ORDER BY last_activity_at` correlated 12-way UNION in `ListingQueryApplier` — add `ca_masters.last_activity_at` + write hooks (documented previously; not faked here).

---

## 5. Unnecessary / duplicate queries (dashboard)

| Duplicate | Resolution |
|-----------|------------|
| Org `productivity(null)` vs already-built metrics | Skipped when no employee filter |
| `demos_scheduled_today` vs `achievements.demo_completed` in `todayProgress` | Reused achievement value |
| Multiple `aggregateForRange` in same `todayProgress` | Single call reused for `demos` + completed today |

---

## 6. Controllers / routes over budget (targets)

| Path | Goal | Status |
|------|------|--------|
| Dashboard | &lt; 500ms | Cold path much lighter; still depends on host CPU + cache hit (120s TTL). After deploy + `config:cache` + queue async, expect cache-hit well under 500ms; cold org still includes reports/widgets. |
| Employee Dashboard | &lt; 500ms | YTD no longer catastrophic; largest remaining cost is reports-like widgets if any. |
| Assignment | &lt; 700ms | Org totals / YTD cards no longer N×day; listing progress-per-row still improvable. |
| Master Data | &lt; 700ms | Activity latest-row + per_page cap already applied; `last_activity` sort still the footgun. |

---

## 7. Frontend / Laravel / MySQL ops (not code-rewritten)

| Item | Action |
|------|--------|
| ~2.4MB unminified CRM UI | Bundle/minify/split — pending (no UI change this pass) |
| `QUEUE_CONNECTION=sync` live | Switch to `database` + `queue:work` (ops) |
| Missing `config:cache` / `route:cache` / `view:cache` | Run on deploy |
| Host load ~22 | VPS/dedicated — ops |

---

## 8. Estimated improvement

| Area | Query reduction | Expected wall-time impact |
|------|-----------------|---------------------------|
| YTD / yearly progress | **~98%** (360→6 for 60 days; ~1200→6 for full year) | Seconds → tens of ms on target cards |
| Org daily totals (9 employees) | **~70–80%** | Assignment/dashboard target strip |
| Demo aggregate | **75%** (8→2) | Every dashboard that shows demo KPIs |
| Org dashboard cold | Drop entire productivity subtree | Large when employee filter not set |
| Follow-up feed | **3N → 3** communication queries | Activity timeline pages |

**Business results unchanged** — same filters, same achievement rules (working-day inclusion preserved via date-set sum).

---

## 9. Changed files

1. `app/Services/Assignment/DailyEmployeeTargetProgressService.php`  
2. `app/Services/Assignment/YearlyEmployeeTargetProgressService.php`  
3. `app/Services/Assignment/EmployeeTargetService.php`  
4. `app/Services/Dashboard/DemoMetricsService.php`  
5. `app/Services/Dashboard/DashboardService.php`  
6. `app/Services/FollowUp/LeadActivityTimelineService.php`  
7. `app/Console/Commands/CrmProfileHotPathsCommand.php`  
8. `database/migrations/2026_07_30_130000_add_target_dashboard_performance_indexes.php`  
9. `PERFORMANCE_OPTIMIZATION_REPORT.md` (this file)

---

## 10. Tests run

```text
php artisan test --filter='DashboardTargetMetricsTest|DailyEmployeeTargetTest|LeadLastActivityTest'
→ passed (10 passed, 2 skipped)
DailyEmployeeTargetTest → 6/6 passed
```

---

## 11. Deploy notes

```bash
php artisan migrate --force   # new indexes
php artisan config:cache
php artisan route:cache
php artisan view:cache
# Prefer QUEUE_CONNECTION=database + queue worker
php artisan crm:profile-hot-paths --base=https://crm.caclouddesk.com --token=…
```

---

## 12. Next ranked work (not done — needs careful design)

1. Denormalize `ca_masters.last_activity_at` and drop UNION sort.  
2. Batch `DailyEmployeeTargetService::buildList` progress for all rows in one date.  
3. Single SQL for Master activity summaries (UNION ALL + ROW_NUMBER across sources).  
4. Frontend Vite minify + route code-split.  
5. Redis/database session+cache under concurrency.
