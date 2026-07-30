# Dashboard Clickable Statistic Cards — Final Report

**Date:** 2026-07-30  
**Scope:** Super Admin, Admin, Manager, and Employee dashboards  
**Rule:** Reuse existing SPA pages + listing filters; no new backend routes; RBAC via `CA_RBAC.canAccessPage`

---

## Architecture (unchanged)

| Role | Dashboard shell | KPI renderer |
|------|-----------------|--------------|
| Super Admin / Admin / Manager | `.mgr-dashboard` via `paintManagerDashboard` | `ADMIN_DASHBOARD_KPI_SECTIONS` + productivity / org target / follow-up strips |
| Employee | `.emp-dashboard` via `paintEmployeeDashboard` | `EMPLOYEE_DASHBOARD_KPI_SECTIONS` + productivity stats |

Click path: card `data-*` → `activateDashboardCardNav` → `_dashboardNavIntent` → `navigateTo(page)` → `onPage` applies filters via existing listing APIs.

Permissions: destination pages already gated by `CA_RBAC.canAccessPage`; cards also pre-check before navigate.

Lead hub: employees → `leads`; managers/admins/super-admins → `ca-master`.

---

## 1. Clickable cards → route → filters → permission

### Manager / Admin / Super Admin — KPI strip

| Card | Page id | Filters / behavior | Permission (page access) |
|------|---------|--------------------|--------------------------|
| Total Leads | `ca-master` | all (+ executive if employee selected) | `ca_master` view |
| Assigned Leads | `ca-master` | all + executive | `ca_master` view |
| New Leads | `ca-master` | `segment=status_new` | `ca_master` view |
| Hot / Warm / Cold | `ca-master` | `hot` / `warm` / `cold` | `ca_master` view |
| In Pipeline | `ca-master` | `segment=pipeline` + **Kanban view** | `ca_master` view |
| Converted / Won | `ca-master` | `segment=converted` | `ca_master` view |
| Lost / Dead | `ca-master` | `segment=lost` | `ca_master` view |
| Conversion Rate | `analytics` | — | reports/analytics |
| Today's Calls | `followups` | today + type Call | `followups` view |
| Today's Follow-ups | `followups` | today | `followups` view |
| Today's Meetings | `followups` | today + Demo Scheduled | `followups` view |
| Overdue Follow-ups | `followups` | overdue | `followups` view |
| Demos Scheduled / Today / Completed / Today | `followups` | type ± due | `followups` view |
| Demo Conversion | `analytics` | — | analytics |
| Missed / Cancelled / Rescheduled | `followups` | type + status where set | `followups` view |
| Purchased | `ca-master` | `converted` | `ca_master` view |
| Emails / SMS / WhatsApp / Replies | `email` / `sms` / `whatsapp` / `email` | — | communication modules |
| Daily Demo Target | `employees` | — | `employees` / assignment hub |
| Target Achieved / Remaining / Achievement % | `analytics` | — | analytics |
| Active Assignments | `assignment` | `status=Active` | `assignment` view |
| Auto (Rotation) | `assignment` | `assignment_type=Auto` + **scroll to rotation rules** | `assignment` view |
| Manual | `assignment` | `assignment_type=Manual` | `assignment` view |
| Assigned Leads (bottom) | `ca-master` | all + executive | `ca_master` view |

### Employee productivity panel (selected employee)

Same destinations as above (Hot/Warm/Cold are **separate** cards). Data remains scoped by dashboard employee filter + listing RBAC.

### Organization target + Follow-up Status strip

Also clickable → `employees` / `analytics` / `followups` as mapped in UI.

### Employee dashboard KPI strip

| Card | Page | Filters | Permission |
|------|------|---------|------------|
| My / Assigned Leads | `leads` | all | `leads` view |
| New / Hot / Warm / Cold | `leads` | matching segment | `leads` view |
| In Pipeline | `leads` | pipeline + Kanban | `leads` view |
| Converted / Lost | `leads` | converted / lost | `leads` view |
| Conversion Rate | `leads` | converted | `leads` view |
| Daily work / demos / targets | `followups` or `assignment` | due/type as labeled | module view |

Employee productivity mini-cards also navigate (leads / followups).

---

## 2. New routes added

**None.** All destinations reuse existing SPA page ids and listing filters.

---

## 3. Files modified

| File | Change |
|------|--------|
| `public/crm-ui/src/api/crm.js` | Nav intent, KPI configs, productivity panel, pipeline view + assignment rotation focus, role-aware lead hub, RBAC pre-check |
| `public/crm-ui/src/pages/pages.js` | Follow-up strip buttons (prior) |
| `public/crm-ui/src/styles.css` | Pointer, hover shadow + **scale(1.02)**, focus ring |
| `app/Support/Listing/ListingQueryApplier.php` | Segments `warm` / `converted` / `status_new` (prior) |
| `app/Services/Dashboard/DashboardService.php` | `converted_leads` metric (prior) |
| `app/Services/Dashboard/EmployeeDashboardService.php` | Extra lead counts in **one** aggregate query (new/pipeline/converted/lost) |

---

## 4. Performance confirmation

- No extra dashboard metric queries for navigation (client-side only).
- Employee lead cards use **one** expanded `COUNT FILTER` query (not N+1).
- Destination pages reuse existing `CA_LISTING_SEARCH` / `reloadListing` — same as manual filter use.
- Loader only if navigation exceeds 300ms.

---

## 5. Existing functionality

- Cards remain buttons; values unchanged.
- `navigateTo` still enforces RBAC; unauthorized → toast, stay put.
- Browser Back works via SPA history.
- No dummy pages or fake data.

---

## 6. UI behavior (common)

- Pointer cursor  
- Hover elevation + shadow + slight scale  
- Focus ring + Enter (native button)  
- Same-tab navigation  
- Tooltips / `aria-label` describe destination  
- Employee / date context preserved on intent when applicable  
