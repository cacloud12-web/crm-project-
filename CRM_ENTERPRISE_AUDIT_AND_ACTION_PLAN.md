# CA Cloud Desk CRM — Enterprise Investigation Report & Final Action Plan

**Date:** 30 Jul 2026  
**Environment:** Laravel 13.19 · PHP 8.5.6 (local) / Production live Hostinger  
**Scope:** Full CRM audit (Dashboard, Master Data, Leads, Assignment, Reports, Auth, RBAC, DB, UI, Security, Perf)  
**Phase status:** Phase 1 (Investigation) ✅ · Phase 2 (Action Plan) ✅ · Phase 3 (Fixes) — starting Critical only after this document  

**Rules honored:** No random rewrites · No business-logic changes unless required · No data changes · Preserve features · Backward compatible  

**Limits:** Authenticated page timings and live EXPLAIN not run in this pass (require staging session + prod-size data). Query counts below are from code-path analysis.

---

# PHASE 1 — INVESTIGATION REPORT

## Issue Catalog

### ISS-001 — Manager cannot Assign / Reassign in UI

| Field | Detail |
|-------|--------|
| **Issue Name** | Manager Assignment UI permission mismatch |
| **Module** | Assignment / RBAC |
| **Root Cause** | Frontend gates Assign on `assignment.create` and Reassign/Pause on `create`/`edit`. Manager matrix grants only `assign` + `reassign`. Backend API correctly maps POST assign → `assign`, PUT → `reassign`, but status PATCH still requires `edit`. Backend aliases make `assign` accept `create`, but **not** the reverse — so FE `create` checks fail for Managers. |
| **Files** | `public/crm-ui/src/services/rbac.js`; `public/crm-ui/src/api/crm.js`; `config/rbac.php`; `app/Services/Rbac/RbacService.php` |
| **Functions** | `ACTION_RULES`; `can()` / `crmCanAction()`; `getAssignmentActionItems()`; inbox assign gates (~1432, ~3754); `resolveRequestPermission()` status PATCH |
| **Impact** | Managers with legitimate assign rights cannot use Assign Lead / Reassign / Pause in UI. Super Admin unaffected (`*`). |
| **Risk** | Critical product defect for Manager role |
| **Estimated Fix** | 2–4 hours |
| **Priority** | **Critical** |

---

### ISS-002 — Assignment Active KPI falls through on zero

| Field | Detail |
|-------|--------|
| **Issue Name** | Active Assignments KPI uses truthy `\|\|` |
| **Module** | Assignment |
| **Root Cause** | `activeCount \|\| metrics.assigned_leads` treats `0` as falsy and shows org assigned-leads instead. |
| **Files** | `public/crm-ui/src/api/crm.js` |
| **Functions** | `renderAssignmentKpis()` |
| **Impact** | Wrong KPI when there are zero active rows |
| **Risk** | Misleading ops metrics |
| **Estimated Fix** | 15 minutes |
| **Priority** | **High** |

---

### ISS-003 — Dashboard “Manual” KPI bound to unassigned_leads

| Field | Detail |
|-------|--------|
| **Issue Name** | Manual assignment KPI shows Unassigned count |
| **Module** | Super Admin / Manager Dashboard |
| **Root Cause** | KPI card `label: 'Manual'` uses `key: 'unassigned_leads'`. |
| **Files** | `public/crm-ui/src/api/crm.js` (`ADMIN_DASHBOARD_KPI_SECTIONS`) |
| **Functions** | Dashboard KPI render / `activateDashboardCardNav` |
| **Impact** | Managers see wrong number for “Manual” |
| **Risk** | Decision errors |
| **Estimated Fix** | 30 minutes (relabel to Unassigned **or** add real manual metric) |
| **Priority** | **High** |

---

### ISS-004 — Activity feed PHP pagination

| Field | Detail |
|-------|--------|
| **Issue Name** | Activity timeline loads then paginates in memory |
| **Module** | Activity / Follow-ups / Lead detail |
| **Root Cause** | `LeadActivityTimelineService::feed()` builds multi-source collections (history, calls, actions, email, SMS, WA), filters/sorts in PHP, then `slice()` for page. |
| **Files** | `app/Services/FollowUp/LeadActivityTimelineService.php` |
| **Functions** | `feed()`, `buildLeadItems()`, `buildFeedItemsForCaIds()`, `sortItems()` |
| **Impact** | Latency + memory grow with lead history; cannot hit &lt;1s under load |
| **Risk** | High at 100+ concurrent users |
| **Estimated Fix** | 2–4 days |
| **Priority** | **Critical** (perf) |

---

### ISS-005 — Dashboard metrics COUNT fan-out

| Field | Detail |
|-------|--------|
| **Issue Name** | Dashboard metrics run many separate COUNTs |
| **Module** | Dashboard |
| **Root Cause** | `DashboardService` aggregates follow-ups, campaigns, DND, reports insights, productivity via multiple queries per request. |
| **Files** | `app/Services/Dashboard/DashboardService.php`; related metric services |
| **Functions** | Metrics payload builders / `aggregate*` helpers |
| **Impact** | Dashboard &gt;1s target missed as data grows |
| **Risk** | High |
| **Estimated Fix** | 2–3 days |
| **Priority** | **High** |

---

### ISS-006 — Reports summary builds all slugs

| Field | Detail |
|-------|--------|
| **Issue Name** | Reports hub computes every report upfront |
| **Module** | Reports |
| **Root Cause** | `ReportsService::summary()` (or equivalent) materializes all report definitions/aggregations. |
| **Files** | `app/Services/Reports/ReportsService.php` |
| **Functions** | `summary()`, per-slug builders |
| **Impact** | Slow Reports hub open |
| **Risk** | High |
| **Estimated Fix** | 1–2 days |
| **Priority** | **High** |

---

### ISS-007 — JSON 500 exception message leak

| Field | Detail |
|-------|--------|
| **Issue Name** | API 500 responses expose `$e->getMessage()` |
| **Module** | Global / Security |
| **Root Cause** | `bootstrap/app.php` JSON exception renderer returns exception message for non-403 errors. |
| **Files** | `bootstrap/app.php` |
| **Functions** | Exception `renderable` / JSON handler |
| **Impact** | Internal paths/SQL hints may leak to clients |
| **Risk** | High (security) |
| **Estimated Fix** | 1–2 hours |
| **Priority** | **High** |

---

### ISS-008 — Demo Calendar `$request->all()` without FormRequest

| Field | Detail |
|-------|--------|
| **Issue Name** | Unvalidated mass input on demo mutations |
| **Module** | Demo Calendar / Security |
| **Root Cause** | Controller passes `$request->all()` into schedule/reschedule/provider services. |
| **Files** | `app/Http/Controllers/Demo/DemoCalendarController.php` |
| **Functions** | `checkConflict()`, `schedule()`, `reschedule()`, provider create/update |
| **Impact** | Unexpected fields may reach services; weaker validation boundary |
| **Risk** | High |
| **Estimated Fix** | 1 day |
| **Priority** | **High** |

---

### ISS-009 — FE PAGE_ACCESS default-allow unknown pages

| Field | Detail |
|-------|--------|
| **Issue Name** | Unknown SPA pages fall back to `dashboard.view` |
| **Module** | RBAC |
| **Root Cause** | `canAccessPage()` returns `can('dashboard','view')` when page missing from `PAGE_ACCESS`. |
| **Files** | `public/crm-ui/src/services/rbac.js`; `config/rbac.php` `spa_pages` |
| **Functions** | `canAccessPage()`, `applyNavAccess()` |
| **Impact** | Employees may reach pages only intended for managers if routed |
| **Risk** | High |
| **Estimated Fix** | 2–4 hours |
| **Priority** | **High** |

---

### ISS-010 — Recycle Bin FE vs BE mismatch for Employees

| Field | Detail |
|-------|--------|
| **Issue Name** | Employee always sees Recycle Bin; API may 403 |
| **Module** | Recycle Bin / RBAC |
| **Root Cause** | `canAccessRecycleBin()` always true for employees; backend requires `ca_master.delete`. |
| **Files** | `rbac.js`; `config/rbac.php`; CaMaster recycle endpoints |
| **Functions** | `canAccessRecycleBin()` |
| **Impact** | Confusing UX / failed restores |
| **Risk** | Medium–High |
| **Estimated Fix** | 2 hours (align product decision first) |
| **Priority** | **High** |

---

### ISS-011 — Date format fragmentation + unused Settings format

| Field | Detail |
|-------|--------|
| **Issue Name** | Multiple date formatters; Settings date format unused |
| **Module** | Global UI / Settings |
| **Root Cause** | Mix of `en-IN` helpers, bare `toLocaleString()`, picker `DD/MM/YYYY`; Settings stores format but tables ignore it. |
| **Files** | `crm.js`, `tickets-page.js`, `ocr-*.js`, `datetime-picker.js`, `pages.js` Settings |
| **Functions** | `formatDate`, `formatDateTime`, module-local formatters |
| **Impact** | Inconsistent dates across modules |
| **Risk** | Medium |
| **Estimated Fix** | 1–2 days |
| **Priority** | **Medium** |

---

### ISS-012 — Copy / spelling inconsistencies

| Field | Detail |
|-------|--------|
| **Issue Name** | Grammar and label drift |
| **Module** | Global |
| **Root Cause** | Organic copy without style guide |
| **Files** | `crm.js`, `pages.js`, `constants/data.js`, `sidebar.blade.php` |
| **Functions** | N/A (string literals) |
| **Impact** | Unprofessional UI (“Payment Receive”, “Today Follow-ups”, “All Status”) |
| **Risk** | Low–Medium |
| **Estimated Fix** | 0.5 day |
| **Priority** | **Medium** |

---

### ISS-013 — N+1 employee target resolution

| Field | Detail |
|-------|--------|
| **Issue Name** | Org demo totals N+1 |
| **Module** | Assignment / Targets |
| **Root Cause** | Loop employees → `resolvedTargetsForDate` per ID |
| **Files** | `app/Services/Assignment/EmployeeTargetService.php` |
| **Functions** | Org aggregate helpers / `resolvedTargetsForDate` |
| **Impact** | Slow Targets / Assignment dashboard |
| **Risk** | Medium |
| **Estimated Fix** | 0.5–1 day |
| **Priority** | **Medium** |

---

### ISS-014 — Bulk assignment notify N+1

| Field | Detail |
|-------|--------|
| **Issue Name** | Per-assignee Employee::first in loop |
| **Module** | Bulk Assignment |
| **Root Cause** | Lookup inside foreach |
| **Files** | `app/Services/Assignment/BulkAssignmentWriter.php` |
| **Functions** | Notify / write loop |
| **Impact** | Slow bulk assign |
| **Risk** | Medium |
| **Estimated Fix** | 1–2 hours |
| **Priority** | **Medium** |

---

### ISS-015 — Profile work_type too open; password UI too closed

| Field | Detail |
|-------|--------|
| **Issue Name** | Profile capability asymmetry |
| **Module** | Auth / Profile |
| **Root Cause** | Work Type editable for any linked employee; Change Password menu only Super Admin/Admin. |
| **Files** | `overlays.blade.php`, `ProfileService.php`, `rbac.js` |
| **Functions** | `update()`, profile menu gates |
| **Impact** | Employees can self-declare demo provider; Managers can’t change password in UI |
| **Risk** | Medium–High |
| **Estimated Fix** | 0.5 day |
| **Priority** | **High** |

---

### ISS-016 — UI system fragmentation (buttons/colors/tables)

| Field | Detail |
|-------|--------|
| **Issue Name** | Multiple button/icon/table systems |
| **Module** | Global UI |
| **Root Cause** | Organic growth across feature eras |
| **Files** | `styles.css`, `pages.js`, `crm.js` |
| **Functions** | N/A |
| **Impact** | Inconsistent UX; harder maintenance |
| **Risk** | Medium (UX) |
| **Estimated Fix** | 3–5 days phased |
| **Priority** | **Medium** |

---

### ISS-017 — Large monolith JS/CSS

| Field | Detail |
|-------|--------|
| **Issue Name** | `crm.js` ~27.5k LOC · `styles.css` ~17.6k LOC |
| **Module** | Frontend assets |
| **Root Cause** | Single-file SPA growth |
| **Files** | `public/crm-ui/src/api/crm.js`, `styles.css` |
| **Functions** | Entire client |
| **Impact** | Parse cost; hard to optimize per route |
| **Risk** | Medium long-term |
| **Estimated Fix** | Multi-sprint split |
| **Priority** | **Low** (plan) / **Medium** (perf at scale) |

---

### ISS-018 — Status PATCH requires edit (Managers blocked)

| Field | Detail |
|-------|--------|
| **Issue Name** | Pause/Resume assignment API needs `edit` |
| **Module** | Assignment API |
| **Root Cause** | `lead-assignments/{id}/status` PATCH → `permission => edit`; Managers have `reassign` not `edit`. |
| **Files** | `app/Services/Rbac/RbacService.php` |
| **Functions** | `resolveRequestPermission()` |
| **Impact** | Even after FE fix, Pause/Resume API 403 for Managers |
| **Risk** | Critical (paired with ISS-001) |
| **Estimated Fix** | 30 minutes |
| **Priority** | **Critical** |

---

## Measured baselines (local, unauthenticated)

| Page | Result |
|------|--------|
| `GET /login` | ~1.0 s HTML (≤3 s shell OK; target interactive &lt;1 s needs auth measure) |
| Authenticated Dashboard / Master Data / Reports | **Not measured this pass** |

---

# PHASE 2 — FINAL ACTION PLAN

## Order of execution (mandatory)

| Step | Priority | Issues | Est. effort | Expected gain |
|------|----------|--------|-------------|---------------|
| 1 | Critical | ISS-001, ISS-018 | 0.5 day | Managers can assign/reassign/pause |
| 2 | High | ISS-002, ISS-003 | 0.5 day | Correct KPIs |
| 3 | High | ISS-007 | 1–2 h | Stop exception leaks |
| 4 | High | ISS-009, ISS-010, ISS-015 | 1 day | RBAC alignment |
| 5 | High | ISS-008 | 1 day | Demo Calendar validation |
| 6 | High | ISS-005 | 2–3 days | Dashboard → &lt;1 s path |
| 7 | Critical/High | ISS-004 | 2–4 days | Activity feed scalable |
| 8 | High | ISS-006 | 1–2 days | Reports hub &lt;1 s |
| 9 | Medium | ISS-013, ISS-014 | 1 day | Targets/bulk faster |
| 10 | Medium | ISS-011, ISS-012 | 1–2 days | Date + copy consistency |
| 11 | Medium | ISS-016 | 3–5 days | UI consistency |
| 12 | Low | ISS-017 | Ongoing | Bundle split |

## Performance gain estimates (directional)

| Fix | Query reduction | Page speed |
|-----|-----------------|------------|
| Activity SQL pagination | 60–90% less rows loaded | Activity 3–10s → &lt;1s (typical) |
| Dashboard COUNT consolidate | 10–20 queries → 2–5 | Dashboard 2–5s → &lt;1s |
| Reports lazy slug | Skip N unused report builds | Hub open 50–80% faster |
| Target N+1 batch | N → 1–2 | Targets panel much faster |

## Regression risk policy

- Prefer permission **aliases** and FE gate fixes over matrix rewrites  
- No schema data changes  
- Add/adjust Feature tests for Manager assign + profile + exception JSON  
- Ship Critical RBAC first; measure before large perf rewrites  

---

# PHASE 3 — EXECUTION LOG

## Batch A completed (Critical RBAC + High KPI + Security leak)

| Issue | Status | Files |
|-------|--------|-------|
| ISS-001 Manager Assign UI | **Fixed** | `rbac.js`, `crm.js` |
| ISS-018 Status PATCH edit→reassign | **Fixed** | `RbacService.php` + test |
| ISS-002 Active KPI `\|\|` | **Fixed** | `crm.js` `renderAssignmentKpis` |
| ISS-003 Manual→Unassigned label | **Fixed** | `crm.js` dashboard KPI |
| ISS-007 500 message leak | **Fixed** | `bootstrap/app.php` (prod hides message) |
| ISS-009 PAGE_ACCESS gaps + default deny | **Fixed** | `rbac.js` synced to spa_pages |
| ISS-010 Recycle Bin | **Aligned** | FE matches backend employee leads.view rule |

**Tests:** `ManagerAssignmentPermissionTest`, `LeadAssignmentStatusTest` (incl. manager pause), `ProfileUpdateTest` — passed.

### Batch A — per-fix notes

**ISS-001**  
- Problem: Managers blocked from Assign UI  
- Root cause: FE checked `create`/`edit`  
- Solution: Check `assign`/`reassign`; FE aliases assign←create, reassign←edit (one-way, like backend)  
- Risk: Low · Safe: API already used assign/reassign  

**ISS-018**  
- Problem: Pause API 403 for Managers  
- Solution: status PATCH permission `reassign`  
- Risk: Low · Admins with edit still pass via alias  

**Next batches (not started):** ISS-004 Activity SQL · ISS-005 Dashboard COUNTs · ISS-006 Reports lazy · ISS-008 Demo FormRequests  

---

*Continue Phase 3 after Batch A review / deploy preference.*
