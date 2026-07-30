# Dashboard Statistic Card Navigation Report

**Date:** 2026-07-14  
**Scope:** Make all dashboard statistic cards clickable with filtered navigation, without changing visual design.

## Summary

Statistic cards on the manager and employee dashboards now act as buttons: pointer cursor, hover elevation, focus ring, tooltips, ARIA labels, and Enter activation. Clicking stores a **nav intent** (`window._dashboardNavIntent`), navigates via existing SPA routes, then applies listing filters on the destination page. No new backend routes were required.

---

## Reusable code added

| Helper | File | Purpose |
|--------|------|---------|
| `setDashboardNavIntent` / `peekDashboardNavIntent` / `consumeDashboardNavIntent` | `public/crm-ui/src/api/crm.js` | Store/read/clear one-shot navigation intent |
| `buildDashboardNavContext` | same | Preserve selected employee + date preset |
| `activateDashboardCardNav` | same | Build intent from `data-*` attrs, show loader (>300ms), `navigateTo` |
| `applyDashboardNavIntentToLeadsListing` | same | Apply `segment` / `status` / `executive` on `ca_masters` |
| `applyDashboardNavIntentToFollowups` | same | Apply `followup_due` / `followup_type` / `status` / `employee_id` |
| `applyDashboardNavIntentToAssignments` | same | Apply `status` / `assignment_type` / `employee_id` |
| `showDashboardNavLoading` / `hideDashboardNavLoading` | same | Loading chip after 300ms |
| `renderDashboardKpiCard` | same | Button markup with nav attrs + a11y |
| Listing segments `warm`, `converted`, `status_new` | `app/Support/Listing/ListingQueryApplier.php` | Server-side lead filters |
| `converted_leads` metric | `app/Services/Dashboard/DashboardService.php` | KPI value for Converted / Won |

**CSS:** `.dash-kpi-card--nav`, `.mgr-emp-prod-card--nav`, `.dash-prod-stat--nav`, `.dash-fu-kpi--nav`, `.dash-nav-loading` in `public/crm-ui/src/styles.css`.

**New routes created:** none.

**APIs reused:** existing listing endpoints (`/ca-masters`, `/follow-ups`, `/lead-assignments`) and SPA pages (`analytics`, `email`, `sms`, `whatsapp`, `employees`, `assignment`).

---

## Controllers / APIs used

| Destination | Listing key / page | API |
|-------------|-------------------|-----|
| Master Data / Leads | `ca_masters` | `GET /api/ca-masters` (via `CA_LISTING_SEARCH`) |
| Follow-ups | `follow_ups` | `GET /api/follow-ups` |
| Assignments | `lead_assignments` | `GET /api/lead-assignments` |
| Analytics | page `analytics` | existing analytics page / report APIs |
| Email / SMS / WhatsApp | campaign pages | existing campaign renderers |
| Employees | page `employees` | existing employees listing |

---

## Manager dashboard — KPI strip (`ADMIN_DASHBOARD_KPI_SECTIONS`)

| Card | Route (page id) | Applied filters |
|------|-----------------|-----------------|
| Total Leads | `ca-master` | `segment=all` (+ `executive` if employee selected) |
| Assigned Leads | `ca-master` | same + employee name as `executive` when filtered |
| New Leads | `ca-master` | `segment=status_new` |
| Hot Leads | `ca-master` | `segment=hot` |
| Warm Leads | `ca-master` | `segment=warm` |
| Cold Leads | `ca-master` | `segment=cold` |
| In Pipeline | `ca-master` | `segment=pipeline` |
| Converted / Won | `ca-master` | `segment=converted` |
| Lost / Dead | `ca-master` | `segment=lost` |
| Conversion Rate | `analytics` | (page open; employee/date context in intent) |
| Today's Calls | `followups` | `followup_due=today`, `followup_type=Call` |
| Today's Follow-ups | `followups` | `followup_due=today` |
| Today's Meetings | `followups` | `followup_due=today`, `followup_type=Demo Scheduled` |
| Overdue Follow-ups | `followups` | `followup_due=overdue` |
| Demos Scheduled | `followups` | `followup_type=Demo Scheduled` |
| Demos Scheduled Today | `followups` | `followup_due=today`, `followup_type=Demo Scheduled` |
| Demos Completed | `followups` | `followup_type=Demo Completed` |
| Demos Completed Today | `followups` | `followup_due=today`, `followup_type=Demo Completed` |
| Demo Conversion | `analytics` | — |
| Pending Confirmation | `followups` | `followup_type=Demo Scheduled` |
| Missed Demos | `followups` | `followup_type=Demo Scheduled`, `status=Missed` |
| Cancelled | `followups` | `followup_type=Demo Scheduled`, `status=Cancelled` |
| Rescheduled | `followups` | `followup_type=Demo Scheduled` |
| Purchased | `ca-master` | `segment=converted` |
| Emails Sent | `email` | — |
| SMS Sent | `sms` | — |
| WhatsApp Sent | `whatsapp` | — |
| Customer Replies | `email` | — |
| Daily Demo Target | `employees` | — |
| Target Achieved | `analytics` | — |
| Remaining Target | `analytics` | — |
| Achievement % | `analytics` | — |
| Employees | `employees` | — |
| Active Assignments | `assignment` | `status=Active` |
| Auto (Rotation) | `assignment` | `assignment_type=Auto` |
| Manual | `assignment` | `assignment_type=Manual` |
| Assigned Leads (bottom) | `ca-master` | `segment=all` + employee |

---

## Manager — Employee productivity panel

Same destinations as the user brief (Lead / Daily / Demo / Communication / Performance / Follow-up). Combined **Hot / Warm / Cold** opens Hot (`segment=hot`); use KPI strip for Warm/Cold specifically.

---

## Manager — Organization target panel

| Card | Route | Filters |
|------|-------|---------|
| Daily Target | `employees` | — |
| Achieved | `analytics` | — |
| Remaining | `analytics` | — |
| Achievement % | `analytics` | — |
| Demos Scheduled Today | `followups` | today + Demo Scheduled |
| Demos Completed Today | `followups` | today + Demo Completed |

---

## Manager — Follow-up Status strip (`dash-fu-kpi`)

| Card | Route | Filters |
|------|-------|---------|
| Today | `followups` | `followup_due=today` |
| Upcoming | `followups` | `followup_due=pending` |
| Completed Today | `followups` | `followup_due=completed` |
| Missed | `followups` | `followup_due=overdue` |
| Overdue | `followups` | `followup_due=overdue` |
| Follow-up Conv. | `analytics` | — |
| Demo Conv. | `analytics` | — |

---

## Manager — Pipeline funnel rows

| Control | Route | Filters |
|---------|-------|---------|
| Funnel stage row | `ca-master` | `segment=pipeline` |

---

## Employee dashboard (`EMPLOYEE_DASHBOARD_KPI_SECTIONS`)

| Card | Route | Filters |
|------|-------|---------|
| My Leads / Assigned Leads | `leads` | `segment=all` |
| New Leads | `leads` | `segment=status_new` |
| Hot / Warm / Cold | `leads` | `hot` / `warm` / `cold` |
| Conversion Rate | `leads` | `segment=converted` |
| Today's Calls / Follow-ups / Meetings / Tasks | `followups` | today (+ type where set) |
| Overdue / My Follow-ups / Upcoming | `followups` | overdue / none / pending |
| Today's Demos / My Meetings | `followups` | Demo Scheduled (+ today) |
| Today's Target | `assignment` | — |
| Today's Achieved | `followups` | today + Demo Scheduled |

---

## Behavior preserved

- Selected dashboard **employee** → `executive` (leads) or `employee_id` (follow-ups / assignments)
- Selected **date preset** stored on intent (destination pages that already use listing “due” filters apply today/overdue directly)
- Org/branch scoping unchanged (existing listing RBAC)
- Master Data no longer clears hot/pipeline/lost/new segments on KPI toolbar paint (bugfix so dashboard filters stick)
- `resetCamListingDefaults` skipped when landing from a dashboard lead card

---

## Testing checklist

- [ ] Every manager KPI card navigates and listing count roughly matches the card
- [ ] Employee KPI cards navigate to Leads / Follow-ups / Assignment
- [ ] Productivity panel + org target + follow-up strip cards navigate
- [ ] Employee filter on dashboard carries to Master Data (`executive`) and follow-ups (`employee_id`)
- [ ] Browser Back returns to dashboard without JS errors
- [ ] Hover / focus ring / tooltip / Enter on keyboard
- [ ] Loader appears only if navigation takes >300ms
- [ ] No duplicate listing fetches beyond normal page load

---

## Files touched

- `public/crm-ui/src/api/crm.js`
- `public/crm-ui/src/pages/pages.js`
- `public/crm-ui/src/styles.css`
- `app/Support/Listing/ListingQueryApplier.php` (segments; earlier in this work)
- `app/Services/Dashboard/DashboardService.php` (`converted_leads`)
- `DASHBOARD_CARD_NAV_REPORT.md` (this file)
