# CA Cloud Desk CRM — Complete QA Audit Report

**Audit date:** 30 Jul 2026  
**Auditor role:** Senior QA + UI/UX Reviewer + Laravel Performance Engineer  
**Scope:** Full CRM (Super Admin, Manager, Employee) — static code audit + limited local timing  
**Code base:** Laravel 13.19 / PHP 8.5.6 / Vanilla JS SPA (`public/crm-ui`)  
**Git HEAD (local):** `e573891` (report auto-filter + profile work type)  
**Status:** **READ-ONLY** — no code, UI, or database changes were made  

---

## Audit method & limits

| Method | Coverage |
|--------|----------|
| Static analysis of `app/`, `config/`, `routes/`, `public/crm-ui/`, Blade views | Full module map |
| Cross-check RBAC matrix vs frontend gates | Super Admin / Manager / Employee |
| Local HTTP timing (`php artisan serve`) | Login HTML only |
| Browser click-through per role | **Not executed** — findings are code-evidence based |
| Production EXPLAIN / APM | **Not executed** — DB findings are migration/query-shape based |

**Measured (local):**

| Path | HTTP | Time |
|------|------|------|
| `GET /login` | 200 | **~1.00 s** (within ≤3 s target for HTML shell) |
| `GET /` | 302 | ~0.08 s |

**Not measured in this pass:** authenticated Dashboard, Master Data, Reports, Export, Import (require logged-in browser session + large prod-like data). Treat dashboard/report latency findings as **architecture risk** until timed on staging/live.

**Asset weight (maintainability risk):** `crm.js` ~27.5k lines; `styles.css` ~17.6k lines.

---

## 1. Executive Summary

The CRM is a large, mature SPA with a strong enterprise table system on Master Data / Leads, solid CSRF session auth, and recent UX work (report auto-filter, inline sales remarks, manage columns). The audit found **critical role-permission mismatches** (Managers cannot Assign Lead in UI despite having `assign`), **high-severity performance risks** (activity feed in-memory pagination; dashboard COUNT fan-out; reports summary generating all slugs), **date/format fragmentation**, and **copy/UI consistency debt**.

| Category | High / Critical | Medium | Low / Info |
|----------|-----------------|--------|------------|
| Functional / RBAC | 5 | 6 | 4 |
| Performance / DB | 4 | 6 | 2 |
| Security | 3 | 2 | 2 |
| UI / UX / Consistency | 3 | 10 | 8 |
| Spelling / Dates | 2 | 6 | 5 |
| Responsive | 1 | 2 | 2 |

**Verdict:** Safe for continued use with Super Admin, but **Manager Assignment workflows are broken in the UI**, and **scale risks** will worsen as lead volume grows. Fix Critical + High items before a large-team push.

**No fixes applied.** Awaiting approval before remediation.

---

## 2. UI Issues

| ID | Module | Page | Issue | Root cause | Severity | Recommendation |
|----|--------|------|-------|------------|----------|----------------|
| UI-01 | Global | Buttons | Multiple button systems (`btn-*`, `crm-toolbar-icon-btn`, `ca-icon-btn`, `crm-bulk-icon-btn`, `mgr-link-btn`) | Organic growth | High | Document one primary + icon-toolbar pattern; migrate leftovers |
| UI-02 | Global | Brand colors | Split teal: `#4CB4D4`, `#25B7A7`, `#2E7F96` hardcoded | No single `--crm-brand` token | High | CSS variables; replace hex |
| UI-03 | Global | `.btn-danger` | Different radius/font vs `.btn-primary` (`styles.css`) | Token drift | Med | Align danger to primary tokens |
| UI-04 | Auth | `verify-login-email.blade.php` | System font + inline styles vs CRM chrome | Standalone blade | Med | Reuse CRM button/typography classes |
| UI-05 | Icons | Call/status UIs | Emoji (🔒 ⚪ 💬) mixed with Lucide | Ad-hoc affordances | Med | Prefer Lucide |
| UI-06 | Tables | Tickets / dashboards vs Master Data | Non-enterprise tables lack sticky columns / shared pager | Different table eras | Med | Extend `CATablePagination` + enterprise chrome |
| UI-07 | Modals | Destructive actions | Many `window.confirm` vs styled `master-delete-guard` | Incremental UX | Med | Unified confirm modal |
| UI-08 | Pagination | Attendance / tickets / OCR | “Prev” vs “Previous” vs icon-only | Ad-hoc pagers | Med | One pager component + label |
| UI-09 | Charts | Reports / funnel | Inline hex `style="background:…"` | Dynamic colors | Low | Stage → CSS var map |
| UI-10 | Typography | `typography.css` | System stack (incl. Roboto) | Explicit choice | Low | Keep or load brand font once |

---

## 3. UX Issues

| ID | Module | Page | Issue | Root cause | Severity | Recommendation |
|----|--------|------|-------|------------|----------|----------------|
| UX-01 | Naming | Master Data | “Add Firm” CTA vs “Add Lead” modal title | Firm/lead vocabulary mix | Med | Align CTA and modal (`crm.js` ~9359 / overlays) |
| UX-02 | Communication | Hub card | Card labeled “Appointments” navigates to Follow-ups | Soft alias | Med | Rename or split concept |
| UX-03 | Assignment | KPI “Assigned Leads” | Leaves Assignment hub to Master Data | Navigation choice | Med | In-page filter or relabel |
| UX-04 | Assignment | KPI “Manual” | Value uses `unassigned_leads` while label says Manual | Wrong metric key | High | Fix metric or split Unassigned vs Manual |
| UX-05 | Dashboards | Emp vs Admin | Shared shell but different KPI destinations (`leads` vs `ca-master` / `analytics`) | Role design | Med | Document intentional; align labels |
| UX-06 | Settings | Filter Preferences | Inputs not wired / not persisted | Incomplete tab | Med | Wire save or remove tab |
| UX-07 | Settings | Integrations | Cashfree “Not configured” static | Placeholder | Low | Hide until ready |
| UX-08 | Profile | Change password | Hidden for Manager/Employee in menu; employees lack API grant | Over-restricted UI | High | Self-service password for all authenticated users |
| UX-09 | Empty values | Tables | Mix of `-` and `—` | Formatter inconsistency | Low | Standardize on `—` |
| UX-10 | Toasts | Global | Mixed punctuation / “successfully” phrasing | No copy guide | Low | Short past tense, consistent period |

---

## 4. Functional Bugs

| ID | Module | Page | Issue | Root cause | Severity | Recommendation |
|----|--------|------|-------|------------|----------|----------------|
| FN-01 | Assignment | Assign Lead / Inbox | UI requires `assignment.create`; Manager matrix has `assign`/`reassign` only | FE/BE permission key mismatch (`rbac.js:71,95` vs `rbac.php:227`) | **Critical** | Gate Assign on `assign`; Reassign on `reassign` |
| FN-02 | Assignment | Row pause/reassign | Actions require `create`/`edit` Managers lack | Same mismatch (`crm.js` ~9595) | **Critical** | Align ACTION_RULES + backend PATCH |
| FN-03 | Assignment | Active Assignments KPI | `activeCount \|\| metrics.assigned_leads` — `0` falls through | Truthy coalesce bug (`crm.js` ~11364) | High | Use nullish coalescing |
| FN-04 | RBAC | SPA pages | Unknown page IDs fall back to `dashboard.view` | Incomplete `PAGE_ACCESS` (`rbac.js:140–143`) | High | Default deny; mirror `spa_pages` |
| FN-05 | Recycle Bin | Employee | FE always shows Recycle; BE needs `ca_master.delete` | Divergent rules | High | Align FE with BE (scoped restore if product wants employee access) |
| FN-06 | Profile | Work Type | Any user with/creating employee can set Demo Provider | No role gate on Edit Profile | High | Restrict demo work_type to SA/Admin/Manager |
| FN-07 | Settings | Date Format | Saved `DD/MM/YYYY` but formatters ignore it | Storage-only setting | High | Wire formatters or remove control |
| FN-08 | Settings | Google API nav | Visible without matching permission filter | Catalog incomplete | Med | Hide without `google_api.view` |
| FN-09 | Bulk | Manager import | Matrix lacks `bulk.import`; cards gated correctly | May be intentional | Med | Confirm product: grant or keep hidden |
| FN-10 | Reports | Auto-filter | Incomplete date range silently skipped | By design in `dateRangeAutoReady` | Med | Optional inline hint |
| FN-11 | Manage columns | Employee column | Still checks `assignment.create` for some columns | Stale permission | Low–Med | Use `assignment.view` / role-agnostic |

---

## 5. Performance Problems

| ID | Module | Evidence | Issue | Severity | Recommendation |
|----|--------|----------|-------|----------|----------------|
| PF-01 | Activity feed | `LeadActivityTimelineService.php:69–138,390+` | Load multi-source rows into PHP → merge → `slice` paginate | **High** | SQL UNION / cursor pagination |
| PF-02 | Dashboard | `DashboardService.php:180–258` | Many separate COUNT queries per metrics request | **High** | Consolidate COUNTs; split endpoints; cache |
| PF-03 | Reports hub | `ReportsService.php:40–59` | `summary()` builds every report slug | **High** | Lazy-load per slug |
| PF-04 | Assignment targets | `EmployeeTargetService.php:201–205` | N+1 `resolvedTargetsForDate` per employee | Med | Batch load overrides/calendar |
| PF-05 | Bulk notify | `BulkAssignmentWriter.php:193–194` | `Employee::first()` in loop | Med | `whereIn` + keyBy |
| PF-06 | Frontend | `crm.js` mutation paths | Reload assignments + full leads after edits | Med | Patch row / reload current listing page |
| PF-07 | Listings | `listing-search.js` + `config/listing.php` | `all=1` up to 500–1000 rows | Med | Typeahead; lower max_all |
| PF-08 | Frontend size | `crm.js` 27k+ LOC monolith | Parse/compile cost on every page | Med | Split by route/module over time |
| PF-09 | Login HTML | Local curl | ~1.0 s TTFB | Info | Within ≤3 s target (HTML only) |

---

## 6. Security Findings

| ID | Module | Evidence | Issue | Severity | Recommendation |
|----|--------|----------|-------|----------|----------------|
| SEC-01 | Exceptions | `bootstrap/app.php:92–98` | JSON errors return `$e->getMessage()` for 500s | **High** | Generic client message; log server-side |
| SEC-02 | Demo Calendar | `DemoCalendarController.php:65–169` | Mutations use `$request->all()` (no FormRequest) | **High** | Whitelist + FormRequest |
| SEC-03 | WhatsApp webhook | Webhook controller | Signature check can fail-open if secret empty (non-prod path) | High (misconfig) | Fail closed when secret missing |
| SEC-04 | CSRF / Auth | `bootstrap/app.php`, `crm.js` | Session + CSRF meta; webhook exempt only | OK | Keep |
| SEC-05 | Mass assignment | Models `$fillable` | Mostly validated() | OK | Keep FormRequests on all writes |
| SEC-06 | PII | CaMaster list APIs | Mobile/email in list payloads | Low | Role-based redaction if required |
| SEC-07 | WhatsApp campaign button | `rbac.js:90` | Uses `send_sms` for WhatsApp modal | Low | Dedicated WA permission |

---

## 7. Database Findings

| ID | Area | Issue | Severity | Recommendation |
|----|------|-------|----------|----------------|
| DB-01 | Activity | Multi-table fan-out then PHP sort/paginate | High | Push limit into SQL |
| DB-02 | Dashboard | Repeated COUNT / status filters | High | Single aggregated query or materialized counters |
| DB-03 | Reports employee perf | Heavy joins + GROUP BY | Med | Pre-aggregate subqueries |
| DB-04 | CaMaster | Recent indexes on status/city/mobile/deleted_at look solid | OK | Confirm `2026_07_30_*` applied on live |
| DB-05 | Search/sort | Raw `firm_name` / `rating` may lack ideal indexes | Med | EXPLAIN on live; add rating / trigram if needed |
| DB-06 | Eager loading | CaMaster / FollowUp listings use listingRelations | OK | Keep pattern |
| DB-07 | Activity backfill | Loads large collections into memory if invoked | High if exposed | Chunk only; never on request path |

---

## 8. Spelling Mistakes / Copy Issues

| Exact string | Location | Fix |
|--------------|----------|-----|
| `Payment Receive` | `crm.js:7`, `constants/data.js:64,389` | **Payment Received** |
| `Today Follow-ups` | `pages.js:335`, `crm.js:6486` | **Today's Follow-ups** |
| `All Status` | `pages.js:785,811,1295` | **All Statuses** (campaigns already use this) |
| `Follow Up` (tooltip) | `app.js:93–95`, `pages.js:73` | **Follow-up** |
| `Follow Up Scheduled` / `Follow Up Reminder` | `crm.js:129–130`, `pages.js:1527` | Hyphenate to **Follow-up …** |
| `Recycle bin` | `sidebar.blade.php:60`, empty state `crm.js:12826` | **Recycle Bin** |
| `Call Back Later` vs `Call Later` | Call-log enums in `crm.js` | Collapse to one label |
| Auth H1 vs title casing | login / forgot / reset blades | One casing system |
| `View All` vs `View all` | Dashboard panels | One casing |

No classic misspellings (`seperate`, `occured`, `recieve`) found in scanned user-facing strings.

---

## 9. Date Format Issues

| Pattern | Where | Problem |
|---------|-------|---------|
| `en-IN` short month (`30 Jul 2026, 03:50 pm`) | Canonical `formatDateTime` in `crm.js` | Intended standard |
| Browser `toLocaleString()` / `undefined` locale | Duplicate dates, OCR, templates, tickets | Locale drift |
| Picker display `DD/MM/YYYY` | `datetime-picker.js` | Differs from table `30 Jul 2026` |
| Settings `DD/MM/YYYY` | Settings General | **Not applied** to formatters |
| Relative (`Today`, `3m ago`) | Activity | OK if labeled |
| Empty `-` vs `—` | Formatters | Inconsistent |

**Standard recommendation:** One shared `CA_DATE` helper; force `en-IN` (or Settings); picker display can stay numeric if documented.

---

## 10. Loading Time Issues

| Surface | Target | Status |
|---------|--------|--------|
| Login page HTML | ≤3 s | **Pass** locally (~1.0 s) |
| Dashboard metrics | ≤3 s interactive | **At risk** — COUNT fan-out (PF-02); not timed authenticated |
| Master Data / Leads | ≤3 s first page | At risk on large datasets; indexes recently improved |
| Reports hub | ≤3 s | **At risk** — all-slug summary (PF-03) |
| Activity / timeline | ≤3 s | **At risk** — PHP pagination (PF-01) |
| Export / Import | Soft | Download via navigation — OK pattern; large imports need progress UX (existing) |
| Global search / filter | Soft | Debounce present on reports; listing filters vary |

**Follow-up measurement (after approval):** Use browser Performance panel + Laravel Telescope/Debugbar on staging with production-sized data for: login→dashboard, Master Data 1st page, Lead Conversion report, activity drawer, export CSV.

---

## 11. Responsive Issues

| ID | Module | Issue | Severity | Recommendation |
|----|--------|-------|----------|----------------|
| RS-01 | Kanban | `min-width: 960px` / `minmax(240px)` forces horizontal scroll on mobile | Med | Stack or snap columns &lt;768px |
| RS-02 | Manage columns popover | No max-width media rules | Low | Constrain / full-bleed on small screens |
| RS-03 | Inline sales remarks | No dedicated mobile styles for editor | Low | Narrow-viewport CSS |
| RS-04 | Report filter toolbar | `nowrap` + overflow-x on desktop; wraps &lt;1024px | Info | Verify on tablet after Apply removal |
| RS-05 | Tables | Enterprise tables scroll horizontally (expected) | Info | Keep sticky left columns |

---

## 12. High Priority (fix first after approval)

1. **FN-01 / FN-02** — Manager Assign/Reassign UI permission mismatch (**Critical**)  
2. **FN-03 / UX-04** — Assignment KPI correctness  
3. **PF-01** — Activity feed SQL pagination  
4. **PF-02 / PF-03** — Dashboard COUNT fan-out + Reports summary lazy-load  
5. **SEC-01 / SEC-02** — Exception message leakage + Demo Calendar validation  
6. **FN-04 / FN-05** — PAGE_ACCESS default-deny + Recycle Bin RBAC align  
7. **FN-07 / Date** — Wire or remove Date Format setting; unify formatters  
8. **UX-08 / FN-06** — Password self-service + restrict profile work_type  

---

## 13. Medium Priority

- Button/color token consolidation (UI-01, UI-02)  
- Firm/Lead naming, Follow-up hyphenation, Payment Received  
- Table/pager unification  
- N+1 targets + bulk notify  
- Settings incomplete tabs  
- Kanban mobile layout  
- WhatsApp button permission semantic  
- Report incomplete-range UX hint  

---

## 14. Low Priority

- Toast copy guide  
- Empty glyph standardization  
- Auth casing  
- Emoji → Lucide  
- Cashfree placeholder  
- Chart CSS vars  
- Inline remarks mobile polish  

---

## 15. Estimated Fix Effort

| Priority band | Est. effort | Notes |
|---------------|-------------|-------|
| Critical RBAC (FN-01/02) | **0.5–1 day** | FE ACTION_RULES + a few crm.js gates; regression test Manager |
| KPI bugs (FN-03, UX-04) | **0.5 day** | Metric key + coalesce |
| Security (SEC-01, SEC-02) | **1–2 days** | FormRequests + exception handler |
| Performance PF-01–03 | **3–5 days** | Activity SQL rewrite + dashboard/report thinning |
| Date unification | **1–2 days** | Shared helper + Settings wiring |
| UI consistency (buttons/colors/tables) | **3–5 days** | Incremental; large surface |
| Copy / spelling sweep | **0.5 day** | String replacements + status enum check |
| Responsive polish | **1–2 days** | Kanban + popovers |
| **Total (phased)** | **~2–3 weeks** | Critical path first week; perf + design system next |

---

## Module coverage checklist

| Module | Audited (code) | Browser E2E |
|--------|----------------|-------------|
| Login / Forgot / Reset password | Yes | No |
| Dashboard (Admin / Employee) | Yes | No |
| Master Data / Lead Management | Yes | No |
| Assignment / Employees / Targets | Yes | No |
| Follow-ups / Call logs | Yes | No |
| Tickets | Partial | No |
| Communication / Campaigns / WhatsApp | Partial | No |
| Reports / Analytics / Activity / Audit / Dupes | Yes | No |
| Bulk / OCR / Employee Imports | Partial | No |
| Demo Calendar | Yes (security/validation) | No |
| Settings / RBAC / Profile | Yes | No |
| Notifications | Surface only | No |
| Queue / DB Health | Surface only | No |
| Recycle Bin | Yes | No |

---

## Role matrix snapshot

| Capability | Super Admin | Manager | Employee |
|------------|-------------|---------|----------|
| Master Data | Yes | Yes | No (uses Lead Management) |
| Assignment UI Assign button | Yes | **Broken (hidden)** | N/A |
| Reports | Yes | Yes | No |
| Settings | Yes | Partial | No |
| Recycle Bin UI | Yes | If delete | Always shown (API may 403) |
| Profile work_type | Yes | Yes | Yes (too open) |
| Change password menu | Yes | Hidden | Hidden |

---

## Next step

**Awaiting your approval.**

Reply with:

1. **Approve all High/Critical** — start remediation in priority order, or  
2. **Approve subset** (e.g. “fix Critical RBAC + security only”), or  
3. **Request deeper measurement** (authenticated timing / live EXPLAIN) before fixes.

No code will be changed until you approve.
