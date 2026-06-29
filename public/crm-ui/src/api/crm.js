/* CA Cloud Desk — Manager CRM UI (frontend only, ER-aligned) */
window.CA_CRM = (function () {
  'use strict';

  var USE_DEMO_FALLBACKS = window.CRM_USE_DEMO_FALLBACKS === true;
  var CAMPAIGN_TYPE_OPTIONS = {
    whatsapp: ['Demo Confirmation', 'Demo Reminder', 'Brochure Sharing', 'Feature Videos', 'Webinar Invitation', 'Trial Activation', 'Payment Receive', 'Renewal Reminder', 'Festival Greeting'],
    sms: ['Demo Reminder', 'Payment Link', 'Festival Greeting', 'OTP Alert', 'Follow-up Reminder'],
    email: ['Bulk Email', 'Proposal Templates', 'Demo Follow-up Sequence', 'Newsletter', 'Renewal Notice'],
  };
  var PIPELINE_STAGES = ['New Lead', 'Details Shared', 'Demo Scheduled', 'Demo Completed', 'Negotiation', 'Won', 'Lost'];

  var API_MSG = 'Backend API required — data saved in demo mode only.';

  function icons() {
    if (typeof lucide !== 'undefined') lucide.createIcons();
  }

  function toast(msg, type) {
    if (typeof showToast === 'function') showToast(msg, type || 'info');
  }

  function openModal(el) {
    if (typeof window.openModal === 'function') window.openModal(el);
    else if (el) {
      el.classList.add('open');
      document.getElementById('overlay')?.classList.add('active');
      document.body.style.overflow = 'hidden';
    }
  }

  function closeModal(el) {
    if (el) el.classList.remove('open');
    document.getElementById('overlay')?.classList.remove('active');
    document.body.style.overflow = '';
  }

  function closeAllCrmModals() {
    document.querySelectorAll('.ca-modal.open').forEach(function (m) { m.classList.remove('open'); });
  }

  function stars(n) {
    n = Math.min(5, Math.max(1, n || 3));
    return '★'.repeat(n) + '☆'.repeat(5 - n);
  }

  function statusBadge(s) {
    var map = { Hot: 'bg-amber-50 text-amber-700', 'Demo Scheduled': 'badge-brand', Active: 'badge-success', Inactive: 'bg-slate-100 text-slate-600', Lost: 'badge-danger', New: 'badge-brand', Pipeline: 'badge-brand', Warm: 'bg-blue-50 text-blue-700' };
    return '<span class="badge ' + (map[s] || 'badge-brand') + '">' + s;
  }
  var realLeadsLoaded = false;
  var realEmployeesLoaded = false;
  var realAssignmentsLoaded = false;
  var realFollowUpsLoaded = false;
  var masterDataLoaded = false;
  var dashboardMetricsLoaded = false;
  var dashboardMetricsPromise = null;
  var employeeDashboardLoaded = false;
  var employeeDashboardPromise = null;
  var employeeDashboardData = null;
  var activityLogsCache = [];
  var activityFilterOptions = { modules: [], actions: [], users: [] };
  var activityFilters = { module_name: '', action: '', date: '', user: '' };
  var whatsappCampaignsLoaded = false;
  var waMessageLogsLoaded = false;
  var emailCampaignsLoaded = false;
  var emailLogsLoaded = false;
  var smsCampaignsLoaded = false;
  var smsLogsLoaded = false;
  var notificationsCache = [];
  var notificationsUnreadCount = 0;
  var notificationsLatestId = 0;
  var notificationPollTimer = null;
  var notificationPollIntervalMs = 30000;
  var notificationPollFailures = 0;
  var notificationPollMaxFailures = 3;
  var notificationPollStopped = false;

  var ACTIVITY_MODULE_LABELS = {
    CA_MASTER: 'Leads',
    LEAD_ACTION: 'Leads',
    EMPLOYEE_MASTER: 'Employees',
    LEAD_ASSIGNMENT_ENGINE: 'Assignment',
    BULK_ACTIONS: 'Bulk Operations',
    FOLLOW_UP_MANAGEMENT: 'Follow-ups',
    DEMO_CONFIRMATION: 'Demo Confirmation',
    CONSENT_TRACKING: 'Consent',
    DND_MANAGEMENT: 'DND',
    WHATSAPP_CAMPAIGN: 'WhatsApp',
    EMAIL_CAMPAIGN: 'Email',
    SMS_CAMPAIGN: 'SMS',
    ACTIVITY_LOGS: 'Activity',
    NOTIFICATION_MODULE: 'Notifications',
    RECEPTION_HUB: 'Reception',
    COMMUNICATION_MODULE: 'Communication',
    STATE_MASTER: 'States',
    CITY_MASTER: 'Cities',
    SOURCE_OF_LEAD: 'Lead Sources',
    ROLE_MASTER: 'Roles',
    LEAD_LOCKING: 'Lead Locking',
    SECURITY: 'Security',
  };

  var RBAC_MODULE_LABELS = {
    dashboard: 'Dashboard',
    ca_master: 'CA Master',
    leads: 'Lead Management',
    employees: 'Employees',
    assignment: 'Assignment',
    followups: 'Follow-ups',
    demo_confirmation: 'Demo Confirmation',
    bulk: 'Bulk Operations',
    campaigns: 'Campaigns',
    consent: 'Consent & DND',
    activity: 'Activity Logs',
    reports: 'Reports',
    admin: 'Administration',
    security: 'Security',
    settings: 'Settings',
  };

  var ACTIVITY_ACTION_META = {
    'Add Lead': { icon: 'user-plus', color: 'bg-brand' },
    'Update Lead': { icon: 'edit-3', color: 'bg-violet-500' },
    'Delete Lead': { icon: 'trash-2', color: 'bg-red-500' },
    'Add Employee': { icon: 'user-plus', color: 'bg-indigo-500' },
    'Update Employee': { icon: 'user-cog', color: 'bg-blue-500' },
    'Delete Employee': { icon: 'user-x', color: 'bg-red-500' },
    'Lead Assignment': { icon: 'user-check', color: 'bg-indigo-500' },
    'Bulk Assignment': { icon: 'users', color: 'bg-purple-500' },
    'Bulk Import': { icon: 'import', color: 'bg-amber-500' },
    'Follow-up Create': { icon: 'calendar-plus', color: 'bg-blue-500' },
    'Follow-up Update': { icon: 'calendar-clock', color: 'bg-sky-500' },
    'Follow-up Delete': { icon: 'calendar-x', color: 'bg-red-500' },
    'Demo Scheduled': { icon: 'calendar-plus', color: 'bg-brand' },
    'Demo Rescheduled': { icon: 'calendar-clock', color: 'bg-amber-500' },
    'Confirmation SMS Sent': { icon: 'message-square', color: 'bg-sky-500' },
    'Confirmation SMS Skipped': { icon: 'message-square-off', color: 'bg-slate-400' },
    'Customer Confirmed': { icon: 'badge-check', color: 'bg-emerald-500' },
    'Customer Rejected': { icon: 'badge-x', color: 'bg-rose-500' },
    'Consent Add': { icon: 'fingerprint', color: 'bg-emerald-500' },
    'Consent Update': { icon: 'fingerprint', color: 'bg-teal-500' },
    'DND Add': { icon: 'ban', color: 'bg-orange-500' },
    'DND Remove': { icon: 'shield-off', color: 'bg-amber-500' },
    'Campaign Skip': { icon: 'shield-alert', color: 'bg-rose-500' },
  };

  function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
  }

  function unwrapList(payload) {
    if (!payload) return [];
    if (Array.isArray(payload)) return payload;
    if (window.CA_LISTING_SEARCH) {
      var listing = CA_LISTING_SEARCH.unwrapListingBody(payload);
      if (listing.items && listing.items.length) return listing.items;
    }
    if (payload.data !== undefined) {
      if (Array.isArray(payload.data)) return payload.data;
      if (payload.data && Array.isArray(payload.data.data)) return payload.data.data;
      if (payload.data && Array.isArray(payload.data.items)) {
        var items = payload.data.items;
        if (items.length && items[0] && items[0].data !== undefined) {
          return items.map(function (item) { return item.data || item; });
        }
        return items;
      }
      if (payload.data && Array.isArray(payload.data.logs)) {
        var logs = payload.data.logs;
        if (logs.length && logs[0] && logs[0].data !== undefined) {
          return logs.map(function (item) { return item.data || item; });
        }
        return logs;
      }
    }
    return [];
  }

  function listingAllQuery(key) {
    if (window.CA_LISTING_SEARCH) return CA_LISTING_SEARCH.buildAllQueryString(key);
    return '?all=1';
  }

  function listingPageQuery(key, extra) {
    if (!window.CA_LISTING_SEARCH) return extra ? '?' + new URLSearchParams(extra).toString() : '';
    return CA_LISTING_SEARCH.buildQueryString(key, extra || {});
  }

  function applyListingPagination(key, tableId, body) {
    if (!window.CA_LISTING_SEARCH || !body) return;
    var parsed = CA_LISTING_SEARCH.unwrapListingBody(body);
    if (parsed.pagination) CA_LISTING_SEARCH.renderPaginationBar(key, tableId, parsed.pagination);
  }

  function apiFetch(url, options) {
    options = options || {};
    options.headers = Object.assign({
      'X-CSRF-TOKEN': csrfToken(),
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    }, options.headers || {});
    return fetch(url, options).then(function (response) {
      if (response.status === 401) {
        window.location.href = '/login';
        throw new Error('Session expired. Please sign in again.');
      }
      return response.text().then(function (text) {
        var body = {};
        if (text) {
          try {
            body = JSON.parse(text);
          } catch (parseError) {
            throw new Error('Something went wrong. Please try again.');
          }
        }
        if (!response.ok) {
          var message = body.message || 'Unable to complete the request. Please try again.';
          if (response.status === 403) {
            message = body.message || 'You do not have permission for this action.';
          } else if (response.status === 422 && body.errors) {
            var firstKey = Object.keys(body.errors)[0];
            if (firstKey && body.errors[firstKey][0]) message = body.errors[firstKey][0];
          } else if (response.status === 500) {
            message = body.message || 'A server error occurred. Please try again or contact support.';
          }
          var err = new Error(message);
          err.status = response.status;
          err.errors = body.errors || null;
          throw err;
        }
        return body;
      });
    });
  }

  function mapStatusToStage(status) {
    var map = {
      New: 'New Lead',
      Hot: 'Negotiation',
      'Demo Scheduled': 'Demo Scheduled',
      Pipeline: 'Details Shared',
      Warm: 'Details Shared',
      Lost: 'Lost',
      Inactive: 'Lost',
      Active: 'Won',
    };
    return map[status] || 'New Lead';
  }

  function mapStageToStatus(stage) {
    var map = {
      'New Lead': 'New',
      'Details Shared': 'Pipeline',
      'Demo Scheduled': 'Demo Scheduled',
      'Demo Completed': 'Warm',
      'Negotiation': 'Hot',
      'Won': 'Active',
      'Lost': 'Lost',
    };
    return map[stage] || 'New';
  }

  function updateLeadStatus(caId, status) {
    return apiFetch('/ca-masters/' + encodeURIComponent(caId) + '/status', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ status: status }),
    });
  }

  function formatTimeAgo(value) {
    if (!value) return '—';
    var d = new Date(value);
    if (isNaN(d.getTime())) return '—';
    var diff = Date.now() - d.getTime();
    var mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return mins + 'm ago';
    var hours = Math.floor(mins / 60);
    if (hours < 24) return hours + 'h ago';
    var days = Math.floor(hours / 24);
    if (days < 7) return days + 'd ago';
    return d.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function formatActivityTimestamp(value) {
    if (!value) return '—';
    var d = new Date(value);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatPhoneDisplay(raw) {
    if (!raw || raw === '—') return '';
    var digits = String(raw).replace(/\D/g, '');
    return digits || '';
  }

  function renderPhoneCell(raw) {
    var display = formatPhoneDisplay(raw);
    if (!display) {
      return '<span class="text-slate-400">Not Available</span>';
    }
    return '<a href="tel:' + escapeHtml(display) + '" class="text-brand hover:underline" onclick="event.stopPropagation();">' + escapeHtml(display) + '</a>';
  }

  function leadHasPhone(raw) {
    return !!formatPhoneDisplay(raw);
  }

  function leadHasEmail(lead) {
    return !!(lead && lead.email_id && lead.email_id !== '—');
  }

  function leadHasWebsite(lead) {
    return !!(lead && lead.website && lead.website !== '—');
  }

  function leadFieldText(value) {
    if (!value || value === '—') return '';
    return String(value).trim();
  }

  function buildLeadGoogleSearchQuery(lead) {
    var parts = [];
    var firm = leadFieldText(lead.firm_name);
    var city = leadFieldText(lead.city);
    if (firm) parts.push(firm);
    if (city) parts.push(city);
    parts.push('CA');
    return parts.join(' ');
  }

  function buildLeadGoogleMapsQuery(lead) {
    var parts = [];
    var firm = leadFieldText(lead.firm_name);
    var city = leadFieldText(lead.city);
    if (firm) parts.push(firm);
    if (city) parts.push(city);
    return parts.join(' ');
  }

  function googleChromeIconSvg() {
    return '<svg class="lead-quick-icon" viewBox="0 0 48 48" aria-hidden="true" focusable="false">' +
      '<circle cx="24" cy="24" r="22" fill="#fff"/>' +
      '<path fill="#EA4335" d="M24 4a20 20 0 0 1 17.32 10H24V4z"/>' +
      '<path fill="#34A853" d="M41.32 14A20 20 0 0 1 24 44V24H41.32z"/>' +
      '<path fill="#FBBC05" d="M24 44A20 20 0 0 1 6.68 34H24V44z"/>' +
      '<path fill="#4285F4" d="M6.68 34A20 20 0 0 1 24 4v20H6.68z"/>' +
      '<circle cx="24" cy="24" r="8.5" fill="#fff"/>' +
      '<circle cx="24" cy="24" r="6.5" fill="#4285F4"/>' +
    '</svg>';
  }

  function googleMapsIconSvg() {
    return '<svg class="lead-quick-icon" viewBox="0 0 48 48" aria-hidden="true" focusable="false">' +
      '<path fill="#EA4335" d="M24 4c-7.18 0-13 5.82-13 13 0 9.75 13 27 13 27s13-17.25 13-27c0-7.18-5.82-13-13-13z"/>' +
      '<circle cx="24" cy="17" r="5.5" fill="#fff"/>' +
      '<path fill="#34A853" d="M8 38l6-10 4 6 6-9 6 9 4-6 6 10H8z" opacity="0.9"/>' +
      '<path fill="#FBBC05" d="M14 28l4 6 6-9 2 3V38H14z" opacity="0.55"/>' +
      '<path fill="#4285F4" d="M28 25l6 9h8L34 30l-6-5z" opacity="0.55"/>' +
    '</svg>';
  }

  function getLeadQuickActionDefs() {
    return [
      { action: 'google', label: 'Search on Google', iconHtml: googleChromeIconSvg() },
      { action: 'maps', label: 'Open in Google Maps', iconHtml: googleMapsIconSvg() },
    ];
  }

  function renderLeadQuickActionButton(leadId, def) {
    return '<button type="button" class="lead-quick-btn lead-quick-btn--' + def.action + '" data-lead-quick="' + def.action + '" data-lead-id="' + escapeHtml(leadId) + '" title="' + escapeHtml(def.label) + '" aria-label="' + escapeHtml(def.label) + '">' +
      def.iconHtml + '</button>';
  }

  function renderLeadQuickActionsCell(lead) {
    var defs = getLeadQuickActionDefs();
    return '<td class="lead-quick-actions-cell">' +
      '<div class="lead-quick-actions--bar">' +
      defs.map(function (d) { return renderLeadQuickActionButton(lead.ca_id, d); }).join('') +
      '</div></td>';
  }

  function handleLeadQuickAction(action, leadId) {
    var lead = getLeadRecord(leadId);
    if (!lead) {
      toast('Lead not found', 'warning');
      return;
    }
    if (action === 'google') {
      var gq = buildLeadGoogleSearchQuery(lead);
      if (!gq) { toast('Insufficient lead data for search', 'warning'); return; }
      window.open('https://www.google.com/search?q=' + encodeURIComponent(gq), '_blank', 'noopener,noreferrer');
      return;
    }
    if (action === 'maps') {
      var mq = buildLeadGoogleMapsQuery(lead);
      if (!mq) { toast('Insufficient lead data for maps', 'warning'); return; }
      window.open('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(mq), '_blank', 'noopener,noreferrer');
    }
  }

  function bindLeadQuickActions(container) {
    if (!container) return;
    container.querySelectorAll('[data-lead-quick]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        handleLeadQuickAction(btn.getAttribute('data-lead-quick'), btn.getAttribute('data-lead-id'));
      });
    });
  }

  function activityModuleLabel(moduleName) {
    if (!moduleName) return '—';
    if (ACTIVITY_MODULE_LABELS[moduleName]) return ACTIVITY_MODULE_LABELS[moduleName];
    if (RBAC_MODULE_LABELS[moduleName]) return RBAC_MODULE_LABELS[moduleName];
    return String(moduleName).replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  function rbacPermissionLabel(permission) {
    if (!permission) return '—';
    if (permission === '*') return 'All';
    return String(permission).replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  function rbacRoleLabel(role) {
    var labels = { super_admin: 'Super Admin', admin: 'Admin', manager: 'Manager', employee: 'Employee' };
    return labels[role] || rbacPermissionLabel(role);
  }

  function humanizeDataSetName(name) {
    var map = {
      ca_masters: 'Leads',
      lead_assignment_engines: 'Assignments',
      follow_up_managements: 'Follow-ups',
      employee_masters: 'Employees',
      activity_logs: 'Activity Logs',
      bulk_actions: 'Bulk Operations',
    };
    return map[name] || activityModuleLabel(String(name || '').toUpperCase()) || rbacPermissionLabel(name);
  }

  function unwrapActivityPayload(body) {
    var data = body && body.data ? body.data : body;
    if (!data) return { logs: [], filter_options: { modules: [], actions: [], users: [] } };
    return {
      logs: unwrapList(data.logs),
      filter_options: data.filter_options || { modules: [], actions: [], users: [] },
    };
  }

  function buildActivityLogsQuery(filters, limit) {
    var params = new URLSearchParams();
    if (filters.module_name) params.set('module_name', filters.module_name);
    if (filters.action) params.set('action', filters.action);
    if (filters.date) params.set('date', filters.date);
    if (filters.user) params.set('user', filters.user);
    if (filters.search) params.set('search', filters.search);
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.sort_by) params.set('sort_by', filters.sort_by);
    if (filters.sort_dir) params.set('sort_dir', filters.sort_dir);
    if (limit) params.set('limit', String(limit));
    else if (window.CA_LISTING_SEARCH) {
      var state = CA_LISTING_SEARCH.getState('activity_logs');
      if (state.page) params.set('page', String(state.page));
      if (state.per_page) params.set('per_page', String(state.per_page));
      if (state.search) params.set('search', state.search);
    }
    var qs = params.toString();
    return '/activity-logs' + (qs ? '?' + qs : '');
  }

  function loadActivityLogsFromDatabase(filters, callback) {
    var queryFilters = filters || activityFilters;
    apiFetch(buildActivityLogsQuery(queryFilters))
      .then(function (body) {
        var payload = unwrapActivityPayload(body);
        activityLogsCache = payload.logs;
        activityFilterOptions = payload.filter_options;
        if (callback) callback(payload.logs);
      })
      .catch(function () {
        activityLogsCache = [];
        if (callback) callback([]);
      });
  }

  function populateActivityFilterOptions() {
    var moduleSel = document.getElementById('activity-filter-module');
    var actionSel = document.getElementById('activity-filter-action');
    if (moduleSel) {
      var moduleValue = moduleSel.value;
      moduleSel.innerHTML = '<option value="">All modules</option>' +
        (activityFilterOptions.modules || []).map(function (m) {
          return '<option value="' + escapeHtml(m) + '">' + escapeHtml(activityModuleLabel(m)) + '</option>';
        }).join('');
      moduleSel.value = moduleValue;
    }
    if (actionSel) {
      var actionValue = actionSel.value;
      actionSel.innerHTML = '<option value="">All actions</option>' +
        (activityFilterOptions.actions || []).map(function (a) {
          return '<option value="' + escapeHtml(a) + '">' + escapeHtml(a) + '</option>';
        }).join('');
      actionSel.value = actionValue;
    }
  }

  function readActivityFiltersFromForm() {
    return {
      module_name: document.getElementById('activity-filter-module')?.value || '',
      action: document.getElementById('activity-filter-action')?.value || '',
      date: document.getElementById('activity-filter-date')?.value || '',
      user: document.getElementById('activity-filter-user')?.value.trim() || '',
    };
  }

  function renderActivityLogsTable(logs) {
    var tbody = document.getElementById('activity-logs-table');
    if (!tbody) return;
    if (!logs || !logs.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-slate-400 py-8">No activity logs match the selected filters.</td></tr>';
      return;
    }
    tbody.innerHTML = logs.map(function (log) {
      return '<tr>' +
        '<td class="whitespace-nowrap">' + escapeHtml(formatActivityTimestamp(log.timestamp)) + '</td>' +
        '<td>' + escapeHtml(log.performed_by || 'System') + '</td>' +
        '<td>' + escapeHtml(activityModuleLabel(log.module_name)) + '</td>' +
        '<td>' + (log.record_id ? escapeHtml(log.record_id) : '—') + '</td>' +
        '<td>' + escapeHtml(log.action) + '</td>' +
        '<td class="max-w-md truncate" title="' + escapeHtml(log.description) + '">' + escapeHtml(log.description || '—') + '</td>' +
      '</tr>';
    }).join('');
  }

  function renderActivityTimeline(logs) {
    var container = document.getElementById('activity-timeline');
    if (!container) return;
    var items = (logs || activityLogsCache || []).slice(0, 8);
    if (!items.length) {
      container.innerHTML = '<p class="text-caption text-slate-400">No activity recorded yet.</p>';
      return;
    }
    container.innerHTML = items.map(function (log) {
      var meta = ACTIVITY_ACTION_META[log.action] || { icon: 'activity', color: 'bg-slate-500' };
      return '<div class="timeline-item">' +
        '<div class="timeline-icon ' + meta.color + '"><i data-lucide="' + meta.icon + '" class="h-3 w-3"></i></div>' +
        '<div class="flex-1 card p-4 min-w-0">' +
          '<div class="flex justify-between gap-2"><p class="text-body font-semibold">' + escapeHtml(log.action) + '</p>' +
          '<span class="text-caption text-slate-400 shrink-0">' + escapeHtml(formatTimeAgo(log.timestamp)) + '</span></div>' +
          '<p class="text-caption text-slate-500 mt-1">' + escapeHtml(formatActivityDescription(log.description) || activityModuleLabel(log.module_name)) + '</p>' +
          '<p class="text-caption text-slate-400 mt-1">' + escapeHtml(log.performed_by || 'System') + ' · ' + escapeHtml(activityModuleLabel(log.module_name)) + '</p>' +
        '</div></div>';
    }).join('');
    icons();
  }

  function initActivityLogsPage() {
    var applyBtn = document.getElementById('activity-filter-apply');
    var clearBtn = document.getElementById('activity-filter-clear');
    if (applyBtn && !applyBtn._activityBound) {
      applyBtn._activityBound = true;
      applyBtn.addEventListener('click', function () {
        activityFilters = readActivityFiltersFromForm();
        if (window.CA_LISTING_SEARCH) {
          CA_LISTING_SEARCH.setState('activity_logs', { page: 1, filters: activityFilters });
          reloadListing('activity_logs').then(function () {
            populateActivityFilterOptions();
            renderActivityTimeline(activityLogsCache);
          });
          return;
        }
        loadActivityLogsFromDatabase(activityFilters, function (logs) {
          populateActivityFilterOptions();
          renderActivityLogsTable(logs);
          renderActivityTimeline(logs);
        });
      });
    }
    if (clearBtn && !clearBtn._activityBound) {
      clearBtn._activityBound = true;
      clearBtn.addEventListener('click', function () {
        activityFilters = { module_name: '', action: '', date: '', user: '' };
        var moduleSel = document.getElementById('activity-filter-module');
        var actionSel = document.getElementById('activity-filter-action');
        var dateInput = document.getElementById('activity-filter-date');
        var userInput = document.getElementById('activity-filter-user');
        if (moduleSel) moduleSel.value = '';
        if (actionSel) actionSel.value = '';
        if (dateInput) dateInput.value = '';
        if (userInput) userInput.value = '';
        if (window.CA_LISTING_SEARCH) {
          CA_LISTING_SEARCH.clearFilters('activity_logs');
          reloadListing('activity_logs').then(function () {
            populateActivityFilterOptions();
            renderActivityTimeline(activityLogsCache);
          });
          return;
        }
        loadActivityLogsFromDatabase(activityFilters, function (logs) {
          populateActivityFilterOptions();
          renderActivityLogsTable(logs);
          renderActivityTimeline(logs);
        });
      });
    }
    loadActivityLogsFromDatabase(activityFilters, function (logs) {
      populateActivityFilterOptions();
      renderActivityLogsTable(logs);
      renderActivityTimeline(logs);
    });
  }

  function getActivityLogsForExport() {
    return (activityLogsCache || []).map(function (log) {
      return {
        id: log.id,
        module_name: log.module_name,
        action: log.action,
        record_id: log.record_id,
        performed_by: log.performed_by,
        timestamp: formatActivityTimestamp(log.timestamp),
        description: log.description,
      };
    });
  }

  function formatRelativeDate(value) {
    if (!value) return '—';
    var d = new Date(value);
    if (isNaN(d.getTime())) return '—';
    var now = new Date();
    var diff = now - d;
    if (diff < 86400000) return 'Today';
    if (diff < 172800000) return 'Yesterday';
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  function mapLeadRecord(l) {
    return {
      ca_id: String(l.ca_id),
      firm_name: l.firm_name || '—',
      ca_name: l.ca_name || '—',
      mobile_no: l.mobile_no || '—',
      alternate_mobile_no: l.alternate_mobile_no || '—',
      email_id: l.email_id || '—',
      gst_no: l.gst_no || '—',
      state: l.state || l.state_name || '—',
      state_id: l.state_id ? String(l.state_id) : null,
      city: l.city || l.city_name || '—',
      city_id: l.city_id ? String(l.city_id) : null,
      team_size: l.team_size || 0,
      existing_software: l.existing_software || '—',
      website: l.website || '—',
      rating: l.rating || 1,
      is_newly_established: !!l.is_newly_established,
      status: l.status || 'Active',
      source: l.source || l.source_name || '—',
      source_id: l.source_id ? String(l.source_id) : null,
      stage: mapStatusToStage(l.status),
      executive_id: l.executive_id ? String(l.executive_id) : null,
      executive: l.executive || l.employee_name || 'Unassigned',
      priority: l.rating || 1,
      last_action: l.last_action || '—',
      created_at: l.created_at,
      updated_at: l.updated_at,
      updated: formatRelativeDate(l.updated_at),
    };
  }

  function mapEmployeeRecord(e) {
    return {
      employee_id: String(e.employee_id),
      name: e.name || '—',
      email_id: e.email_id || '—',
      mobile_no: e.mobile_no || '—',
      role: e.role || 'Sales Executive',
      crm_role: e.crm_role || 'employee',
      login_status: e.login_status || 'none',
      login_status_label: e.login_status_label || 'No Login Created',
      manager: '—',
      city: e.city || e.city_name || '—',
      date_of_joining: e.date_of_joining || '—',
      status: e.status || 'Active',
      target_leads: e.target_leads || '—',
      achieved_leads: e.achieved_leads || '—',
      revenue: '—',
      conversion: '—',
    };
  }

  function loginStatusBadge(status, label) {
    var cls = status === 'active' ? 'login-status-active' : status === 'inactive' ? 'login-status-inactive' : 'login-status-none';
    return '<span class="badge ' + cls + '">' + escapeHtml(label || 'No Login Created');
  }

  function configureEmployeeCrmRoleSelect() {
    var select = document.getElementById('employee-crm-role');
    if (!select) return;
    var actorRole = (window.__CRM_USER__ || {}).role || 'employee';
    var options = [{ value: 'employee', label: 'Sales Executive (Employee)' }];
    if (actorRole === 'admin' || actorRole === 'super_admin') {
      options.push({ value: 'manager', label: 'Manager' });
    }
    if (actorRole === 'super_admin') {
      options.push({ value: 'admin', label: 'Admin' });
    }
    select.innerHTML = options.map(function (opt) {
      return '<option value="' + opt.value + '">' + opt.label + '</option>';
    }).join('');
    select.value = 'employee';
  }

  function configureEmployeeModalMode(mode, employee) {
    var loginFields = document.getElementById('employee-login-fields');
    var statusNote = document.getElementById('employee-login-status-note');
    var title = document.getElementById('add-employee-title');
    var passwordInputs = loginFields ? loginFields.querySelectorAll('[name="password"], [name="password_confirmation"]') : [];

    if (mode === 'edit') {
      if (loginFields) loginFields.classList.add('hidden');
      passwordInputs.forEach(function (input) {
        input.required = false;
        input.value = '';
      });
      if (statusNote && employee) {
        statusNote.textContent = 'Login status: ' + (employee.login_status_label || 'No Login Created');
        statusNote.classList.remove('hidden');
      }
      if (title) title.innerHTML = title.innerHTML.replace('Add Sales Executive', 'Edit Sales Executive');
    } else {
      if (loginFields) loginFields.classList.remove('hidden');
      passwordInputs.forEach(function (input) { input.required = true; });
      if (statusNote) statusNote.classList.add('hidden');
      configureEmployeeCrmRoleSelect();
      if (title && title.textContent.indexOf('Edit') >= 0) {
        title.innerHTML = title.innerHTML.replace('Edit Sales Executive', 'Add Sales Executive');
      }
    }
  }

  function initPasswordToggleButtons(root) {
    (root || document).querySelectorAll('[data-password-toggle]').forEach(function (btn) {
      if (btn._pwdToggleBound) return;
      btn._pwdToggleBound = true;
      btn.addEventListener('click', function () {
        var input = btn.parentElement && btn.parentElement.querySelector('input');
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.innerHTML = show
          ? '<i data-lucide="eye-off" class="h-4 w-4"></i>'
          : '<i data-lucide="eye" class="h-4 w-4"></i>';
        icons();
      });
    });
  }

  function populateResetPasswordEmployeeSelect() {
    var select = document.getElementById('reset-password-employee-select');
    var emailField = document.getElementById('reset-password-employee-email');
    if (!select) return;
    var employees = (window.realEmployees || []).filter(function (e) {
      return e.login_status === 'active' || e.login_status === 'inactive';
    });
    select.innerHTML = '<option value="">Select employee</option>' + employees.map(function (e) {
      return '<option value="' + e.employee_id + '" data-email="' + escapeHtml(e.email_id) + '">' +
        escapeHtml(e.name) + ' (' + escapeHtml(e.email_id) + ')</option>';
    }).join('');
    select.onchange = function () {
      var opt = select.options[select.selectedIndex];
      if (emailField) emailField.value = opt && opt.dataset.email ? opt.dataset.email : '';
    };
    if (emailField) emailField.value = '';
  }

  function getLeadRecord(leadId) {
    var id = String(leadId);
    if (window.realLeads && window.realLeads.length) {
      var found = window.realLeads.find(function (l) { return String(l.ca_id) === id; });
      if (found) return found;
    }
    return USE_DEMO_FALLBACKS ? CAData.getLeadById(leadId) : null;
  }

  function loadLeadsForSelects(callback) {
    if (window._selectLeadsLoaded) {
      if (callback) callback();
      return;
    }
    apiFetch('/ca-masters?per_page=100&sort_by=firm_name&sort_dir=asc')
      .then(function (body) {
        window._selectLeads = unwrapList(body).map(mapLeadRecord);
        window._selectLeadsLoaded = true;
        if (callback) callback();
      })
      .catch(function () {
        window._selectLeads = [];
        window._selectLeadsLoaded = true;
        if (callback) callback();
      });
  }

  function loadEmployeesForSelects(callback) {
    if (window._selectEmployeesLoaded) {
      if (callback) callback();
      return;
    }
    apiFetch('/employees?per_page=100&sort_by=name&sort_dir=asc&status=Active')
      .then(function (body) {
        window._selectEmployees = unwrapList(body).map(mapEmployeeRecord);
        window._selectEmployeesLoaded = true;
        if (callback) callback();
      })
      .catch(function () {
        window._selectEmployees = [];
        window._selectEmployeesLoaded = true;
        if (callback) callback();
      });
  }

  function ensureMasterData(callback) {
    if (masterDataLoaded) {
      if (callback) callback();
      return;
    }
    loadMasterDataFromDatabase(callback);
  }

  function invalidateDataCaches(keys) {
    keys = keys || [];
    if (keys.indexOf('metrics') >= 0) {
      dashboardMetricsLoaded = false;
      dashboardMetricsPromise = null;
      window.dashboardMetrics = null;
    }
    if (keys.indexOf('employee_dashboard') >= 0 || keys.indexOf('metrics') >= 0) {
      employeeDashboardLoaded = false;
      employeeDashboardPromise = null;
      employeeDashboardData = null;
    }
    if (keys.indexOf('leads') >= 0) {
      realLeadsLoaded = false;
      window._selectLeadsLoaded = false;
    }
    if (keys.indexOf('employees') >= 0) {
      realEmployeesLoaded = false;
      window._selectEmployeesLoaded = false;
    }
    if (keys.indexOf('assignments') >= 0) realAssignmentsLoaded = false;
    if (keys.indexOf('followups') >= 0) realFollowUpsLoaded = false;
    if (keys.indexOf('masters') >= 0) masterDataLoaded = false;
  }

  function loadLeadsFromDatabase(callback) {
    apiFetch('/ca-masters' + listingAllQuery('ca_masters'))
      .then(function (body) {
        window.realLeads = unwrapList(body).map(mapLeadRecord);
        realLeadsLoaded = true;
        if (callback) callback();
      })
      .catch(function () {
        window.realLeads = [];
        realLeadsLoaded = true;
        if (callback) callback();
      });
  }

  function loadEmployeesFromDatabase(callback) {
    apiFetch('/employees' + listingAllQuery('employees'))
      .then(function (body) {
        window.realEmployees = unwrapList(body).map(mapEmployeeRecord);
        realEmployeesLoaded = true;
        if (callback) callback();
      })
      .catch(function () {
        window.realEmployees = [];
        realEmployeesLoaded = true;
        if (callback) callback();
      });
  }

  function loadAssignmentsFromDatabase(callback) {
    apiFetch('/lead-assignments' + listingAllQuery('lead_assignments'))
      .then(function (body) {
        window.realAssignments = unwrapList(body);
        realAssignmentsLoaded = true;
        if (callback) callback();
      })
      .catch(function () {
        window.realAssignments = [];
        realAssignmentsLoaded = true;
        if (callback) callback();
      });
  }

  function loadFollowUpsFromDatabase(callback) {
    apiFetch('/follow-ups' + listingAllQuery('follow_ups'))
      .then(function (body) {
        window.realFollowUps = unwrapList(body);
        realFollowUpsLoaded = true;
        if (callback) callback();
      })
      .catch(function () {
        window.realFollowUps = [];
        realFollowUpsLoaded = true;
        if (callback) callback();
      });
  }

  function loadMasterDataFromDatabase(callback) {
    var requests = [
      apiFetch('/source-leads' + listingAllQuery('source_leads')),
      apiFetch('/team-sizes' + listingAllQuery('team_sizes')),
      apiFetch('/role-masters' + listingAllQuery('role_masters')),
    ];

    var statePromise = window.CA_STATE_CITY
      ? window.CA_STATE_CITY.loadStates().then(function (states) {
        window.realStates = states;
        return states;
      })
      : apiFetch('/states' + listingAllQuery('states')).then(function (body) {
        window.realStates = unwrapList(body);
        return window.realStates;
      });

    Promise.all(requests.concat([statePromise]))
      .then(function (results) {
        window.realSourceLeads = unwrapList(results[0]);
        window.realTeamSizes = unwrapList(results[1]);
        window.realRoleMasters = unwrapList(results[2]);
        window.realRoles = window.realRoleMasters;
        window.realCities = [];
        masterDataLoaded = true;
        if (callback) callback();
      })
      .catch(function () {
        window.realStates = window.realStates || [];
        window.realCities = [];
        window.realSourceLeads = [];
        window.realTeamSizes = [];
        window.realRoles = [];
        window.realRoleMasters = [];
        masterDataLoaded = true;
        if (callback) callback();
      });
  }

  function buildMasterSelectOptions(items, valueKey, labelKey) {
    return (items || []).map(function (item) {
      return '<option value="' + item[valueKey] + '">' + item[labelKey] + '</option>';
    }).join('');
  }

  function populateMasterDropdowns() {
    if (!masterDataLoaded) return;

    var sources = window.realSourceLeads || [];

    document.querySelectorAll('select[name="source_id"]').forEach(function (sel) {
      var current = sel.value;
      sel.innerHTML = buildMasterSelectOptions(sources, 'source_id', 'source_name');
      setSelectValueIfValid(sel, current);
    });

    if (window.CA_STATE_CITY) {
      window.CA_STATE_CITY.initAllPairs();
    }
  }

  function masterActionButtons(entity, id) {
    var canEdit = !window.CA_RBAC || CA_RBAC.can('ca_master', 'edit');
    var canDelete = !window.CA_RBAC || CA_RBAC.can('ca_master', 'delete');
    if (!canEdit && !canDelete) return '—';
    var html = '';
    if (canEdit) {
      html += '<button type="button" class="btn-secondary btn-sm" data-master-edit="' + entity + '" data-master-id="' + id + '">Edit</button> ';
    }
    if (canDelete) {
      html += '<button type="button" class="btn-secondary btn-sm" data-master-delete="' + entity + '" data-master-id="' + id + '">Delete</button>';
    }
    return html.trim();
  }

  function applyMasterDataRbac() {
    var canCreate = !window.CA_RBAC || CA_RBAC.can('ca_master', 'create');
    document.querySelectorAll('[data-master-add]').forEach(function (btn) {
      btn.classList.toggle('hidden', !canCreate);
    });
  }

  function refreshMasterDataCaches() {
    masterDataLoaded = false;
    if (window.CA_STATE_CITY && window.CA_STATE_CITY.resetCache) {
      window.CA_STATE_CITY.resetCache();
    }
    ensureMasterData(function () {
      renderMasterTables();
      populateSelects();
      populateMasterDropdowns();
      if (window.CA_STATE_CITY) {
        window.CA_STATE_CITY.loadStates(true).catch(function () {});
      }
    });
  }

  function masterEntityConfig(entity) {
    var map = {
      state: { endpoint: '/states', idKey: 'state_id', title: 'State', fields: ['state_name'] },
      city: { endpoint: '/cities', idKey: 'city_id', title: 'City', fields: ['city_name', 'state_id'] },
      source: { endpoint: '/source-leads', idKey: 'source_id', title: 'Source', fields: ['source_name'] },
      team: { endpoint: '/team-sizes', idKey: 'id', title: 'Team Size', fields: ['team_size_min', 'team_size_max', 'team_size_label'] },
      role: { endpoint: '/role-masters', idKey: 'id', title: 'Role', fields: ['role_name', 'description'] },
    };
    return map[entity] || null;
  }

  function openMasterRecordModal(entity, record) {
    var cfg = masterEntityConfig(entity);
    var modal = document.getElementById('modal-master-record');
    var form = document.getElementById('form-master-record');
    if (!cfg || !modal || !form) return;
    document.getElementById('master-record-entity').value = entity;
    document.getElementById('master-record-id').value = record ? record[cfg.idKey] : '';
    document.getElementById('master-record-title').lastChild.textContent = (record ? 'Edit ' : 'Add ') + cfg.title;
    ['state_name', 'city_name', 'state_id', 'source_name', 'team_size_min', 'team_size_max', 'team_size_label', 'role_name', 'description'].forEach(function (field) {
      var wrap = document.getElementById('master-field-' + field);
      if (wrap) wrap.classList.toggle('hidden', cfg.fields.indexOf(field) < 0);
    });
    form.reset();
    document.getElementById('master-record-entity').value = entity;
    if (record) {
      cfg.fields.forEach(function (field) {
        var input = form.elements[field];
        if (!input) return;
        if (field === 'state_id') input.value = record.state_id || (record.state && record.state.state_id) || '';
        else input.value = record[field] != null ? record[field] : '';
      });
    }
    if (entity === 'city' && window.CA_STATE_CITY) {
      window.CA_STATE_CITY.initStandaloneStates(form);
    }
    openModal(modal);
  }

  function findMasterRecord(entity, id) {
    var cfg = masterEntityConfig(entity);
    if (!cfg) return null;
    var pools = {
      state: window.realStates || [],
      city: window.realCitiesCache || [],
      source: window.realSourceLeads || [],
      team: window.realTeamSizes || [],
      role: window.realRoleMasters || [],
    };
    return (pools[entity] || []).find(function (row) {
      return String(row[cfg.idKey]) === String(id);
    }) || null;
  }

  function initMasterDataActions() {
    if (window._masterActionsBound) return;
    window._masterActionsBound = true;

    document.addEventListener('click', function (e) {
      var addBtn = e.target.closest('[data-master-add]');
      if (addBtn) {
        e.preventDefault();
        openMasterRecordModal(addBtn.getAttribute('data-master-add'), null);
        return;
      }
      var editBtn = e.target.closest('[data-master-edit]');
      if (editBtn) {
        e.preventDefault();
        var entity = editBtn.getAttribute('data-master-edit');
        var record = findMasterRecord(entity, editBtn.getAttribute('data-master-id'));
        if (!record) {
          toast('Record not found — refresh and try again', 'warning');
          return;
        }
        openMasterRecordModal(entity, record);
        return;
      }
      var deleteBtn = e.target.closest('[data-master-delete]');
      if (deleteBtn) {
        e.preventDefault();
        var delEntity = deleteBtn.getAttribute('data-master-delete');
        var delCfg = masterEntityConfig(delEntity);
        var delId = deleteBtn.getAttribute('data-master-id');
        if (!delCfg || !window.confirm('Delete this ' + delCfg.title.toLowerCase() + '?')) return;
        apiFetch(delCfg.endpoint + '/' + encodeURIComponent(delId), { method: 'DELETE' })
          .then(function () {
            toast(delCfg.title + ' deleted', 'success');
            refreshMasterDataCaches();
            if (window.CA_LISTING_SEARCH) {
              reloadListing('states');
              reloadListing('cities');
            }
          })
          .catch(function (err) {
            toast(err.message || 'Unable to delete record', 'error');
          });
      }
    });

    document.getElementById('form-master-record')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var entity = fd.get('entity');
      var cfg = masterEntityConfig(entity);
      if (!cfg) return;
      var recordId = fd.get('record_id');
      var payload = {};
      cfg.fields.forEach(function (field) {
        var val = fd.get(field);
        if (val !== null && val !== '') payload[field] = val;
      });
      if (entity === 'team') {
        payload.team_size_min = parseInt(payload.team_size_min, 10);
        payload.team_size_max = parseInt(payload.team_size_max, 10);
      }
      var url = cfg.endpoint + (recordId ? '/' + encodeURIComponent(recordId) : '');
      var method = recordId ? 'PUT' : 'POST';
      apiFetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function () {
          closeModal(document.getElementById('modal-master-record'));
          toast(cfg.title + ' saved', 'success');
          refreshMasterDataCaches();
          if (window.CA_LISTING_SEARCH) {
            reloadListing('states');
            reloadListing('cities');
          }
        })
        .catch(function (err) {
          toast(err.message || 'Unable to save master record', 'error');
        });
    });
  }

  function renderMasterTables() {
    var statesEl = document.getElementById('master-states-table');
    if (statesEl) {
      var states = window.realStates || [];
      statesEl.innerHTML = states.length ? states.map(function (s) {
        return '<tr class="ca-table-row">' +
          '<td class="font-medium">' + s.state_name + '</td>' +
          '<td>' + (s.cities_count != null ? s.cities_count : '—') + '</td>' +
          '<td class="text-caption">' + formatRelativeDate(s.created_at) + '</td>' +
          '<td class="text-right whitespace-nowrap">' + masterActionButtons('state', s.state_id) + '</td>' +
        '</tr>';
      }).join('') : '<tr><td colspan="4" class="text-center text-slate-500 p-4">No states yet.</td></tr>';
    }

    var citiesEl = document.getElementById('master-cities-table');
    if (citiesEl && window.CA_LISTING_SEARCH) {
      reloadListing('cities');
    } else if (citiesEl) {
      citiesEl.innerHTML = '<tr><td colspan="5" class="text-center text-slate-500 p-4">Open Cities tab to load records.</td></tr>';
    }

    var sourcesEl = document.getElementById('master-sources-table');
    if (sourcesEl) {
      var sources = window.realSourceLeads || [];
      sourcesEl.innerHTML = sources.length ? sources.map(function (s) {
        return '<tr class="ca-table-row">' +
          '<td class="font-medium">' + s.source_name + '</td>' +
          '<td>—</td>' +
          '<td>—</td>' +
          '<td class="text-right whitespace-nowrap">' + masterActionButtons('source', s.source_id) + '</td>' +
        '</tr>';
      }).join('') : '<tr><td colspan="4" class="text-center text-slate-500 p-4">No lead sources yet.</td></tr>';
    }

    var teamSizesEl = document.getElementById('master-team-sizes-table');
    if (teamSizesEl) {
      var teamSizes = window.realTeamSizes || [];
      teamSizesEl.innerHTML = teamSizes.length ? teamSizes.map(function (t) {
        var id = t.team_size_id || t.id;
        return '<tr class="ca-table-row">' +
          '<td>' + (t.team_size_min != null ? t.team_size_min : '—') + '</td>' +
          '<td>' + (t.team_size_max != null ? t.team_size_max : '—') + '</td>' +
          '<td>' + (t.team_size_label || '—') + '</td>' +
          '<td>—</td>' +
          '<td class="text-right whitespace-nowrap">' + masterActionButtons('team', id) + '</td>' +
        '</tr>';
      }).join('') : '<tr><td colspan="5" class="text-center text-slate-500 p-4">No team size ranges yet.</td></tr>';
    }

    var rolesEl = document.getElementById('master-roles-table');
    if (rolesEl) {
      var roles = window.realRoleMasters || [];
      rolesEl.innerHTML = roles.length ? roles.map(function (r) {
        return '<tr class="ca-table-row">' +
          '<td class="font-medium">' + escapeHtml(r.role_name) + '</td>' +
          '<td>' + escapeHtml(r.description || '—') + '</td>' +
          '<td class="text-right whitespace-nowrap">' + masterActionButtons('role', r.id) + '</td>' +
        '</tr>';
      }).join('') : '<tr><td colspan="3" class="text-center text-slate-500 p-4">No roles yet.</td></tr>';
    }
  }

  function enrichLeadsWithAssignments() {
    if (!window.realLeads || !window.realAssignments) return;
    var latestByCa = {};
    window.realAssignments.forEach(function (a) {
      var caId = String(a.ca_id);
      if (!latestByCa[caId] || Number(a.assignment_id) > Number(latestByCa[caId].assignment_id)) {
        latestByCa[caId] = a;
      }
    });
    window.realLeads = window.realLeads.map(function (lead) {
      var asgn = latestByCa[String(lead.ca_id)];
      if (!asgn) return lead;
      return Object.assign({}, lead, {
        executive_id: String(asgn.employee_id),
        executive: asgn.executive || asgn.employee_name || 'Assigned',
        assignment_type: asgn.assignment_type || lead.assignment_type,
      });
    });
  }

  function enrichFollowUpsWithAssignments() {
    if (!window.realFollowUps) return;
    window.realFollowUps = window.realFollowUps.map(function (followUp) {
      if (followUp.executive || followUp.employee_name) return followUp;
      var asgn = (window.realAssignments || []).find(function (a) {
        return String(a.ca_id) === String(followUp.ca_id);
      });
      if (!asgn) return followUp;
      return Object.assign({}, followUp, {
        executive: asgn.executive || asgn.employee_name || '—',
        employee_name: asgn.employee_name || asgn.executive || '—',
      });
    });
  }

  function afterCoreDataLoaded(callback) {
    enrichLeadsWithAssignments();
    enrichFollowUpsWithAssignments();
    if (callback) callback();
  }

  function finishDataLoading(callback) {
    afterCoreDataLoaded(function () {
      populateMasterDropdowns();
      if (document.getElementById('master-states-table')) {
        renderMasterTables();
      }
      if (callback) callback();
    });
  }

  function ensureFormSelectData(callback) {
    var pending = 0;
    function done() {
      pending--;
      if (pending <= 0) {
        populateSelects();
        populateMasterDropdowns();
        if (callback) callback();
      }
    }
    if (!masterDataLoaded) {
      pending++;
      loadMasterDataFromDatabase(done);
    }
    if (!window._selectLeadsLoaded) {
      pending++;
      loadLeadsForSelects(done);
    }
    if (!window._selectEmployeesLoaded) {
      pending++;
      loadEmployeesForSelects(done);
    }
    if (pending === 0) {
      populateSelects();
      if (callback) callback();
    }
  }

  function setSelectValueIfValid(select, value) {
    if (!select || value === undefined || value === null || value === '') return;
    var strValue = String(value);
    var option = Array.prototype.find.call(select.options, function (opt) {
      return opt.value === strValue || opt.text === strValue;
    });
    if (option) select.value = option.value;
  }

  function buildLeadOptionsHtml(leads) {
    if (!leads || !leads.length) {
      return '<option value="">No data available</option>';
    }
    return leads.map(function (l) {
      return '<option value="' + l.ca_id + '">' + l.firm_name + ' · ' + (l.city || '—') + '</option>';
    }).join('');
  }

  function buildExecOptionsHtml(executives) {
    if (!executives || !executives.length) {
      return '<option value="">No data available</option>';
    }
    return executives.map(function (e) {
      return '<option value="' + e.employee_id + '">' + e.name + ' · ' + (e.city || '—') + '</option>';
    }).join('');
  }
  function mapEmployeeDashboardToLeadMetrics(data) {
    var summary = (data && data.summary) || {};
    var warm = summary.warm_leads || 0;
    return {
      total_leads: summary.my_leads || 0,
      new_leads: 0,
      hot_leads: summary.hot_leads || 0,
      warm_leads: warm,
      cold_leads: summary.cold_leads || 0,
      pipeline_leads: warm,
      lost_leads: 0,
    };
  }

  function loadDashboardMetricsFromDatabase(callback) {
    if (isEmployeeUser()) {
      loadEmployeeDashboardFromDatabase(function (data) {
        if (!data) {
          if (callback) callback(null);
          return;
        }
        var metrics = mapEmployeeDashboardToLeadMetrics(data);
        window.dashboardMetrics = metrics;
        dashboardMetricsLoaded = true;
        if (callback) callback(metrics);
      }, true);
      return;
    }

    if (dashboardMetricsLoaded && window.dashboardMetrics) {
      if (callback) callback(window.dashboardMetrics);
      return;
    }

    if (dashboardMetricsPromise) {
      dashboardMetricsPromise.then(function (metrics) {
        if (callback) callback(metrics);
      }).catch(function () {
        if (callback) callback(null);
      });
      return;
    }

    dashboardMetricsPromise = apiFetch('/dashboard/metrics')
      .then(function (body) {
        window.dashboardMetrics = body.data || {};
        dashboardMetricsLoaded = true;
        return window.dashboardMetrics;
      })
      .catch(function () {
        window.dashboardMetrics = null;
        dashboardMetricsLoaded = true;
        return null;
      })
      .finally(function () {
        dashboardMetricsPromise = null;
      });

    dashboardMetricsPromise.then(function (metrics) {
      if (callback) callback(metrics);
    });
  }

  function getDashboardLeads() {
    return realLeadsLoaded && window.realLeads ? window.realLeads.slice() : [];
  }

  function getEmployeeAssignmentCounts() {
    var counts = {};
    (window.realAssignments || []).forEach(function (assignment) {
      if (assignment.status !== 'Active') return;
      var employeeId = String(assignment.employee_id);
      counts[employeeId] = (counts[employeeId] || 0) + 1;
    });
    return counts;
  }

  function getDashboardExecutives() {
    var employees = realEmployeesLoaded && window.realEmployees ? window.realEmployees.slice() : [];
    if (!employees.length) return USE_DEMO_FALLBACKS ? CAData.getExecutives() : [];
    var assignmentCounts = getEmployeeAssignmentCounts();
    return employees.map(function (employee) {
      var achieved = assignmentCounts[String(employee.employee_id)] || 0;
      var target = Math.max(20, achieved || 20);
      return Object.assign({}, employee, {
        achieved_leads: achieved,
        target_leads: target,
        daily_calls: 0,
        demos: 0,
      });
    });
  }

  function buildDashboardDisplayMetrics(metrics) {
    var leads = getDashboardLeads();
    var demoCount = leads.filter(function (lead) { return lead.status === 'Demo Scheduled'; }).length;
    var totalLeads = metrics ? metrics.total_leads : leads.length;
    var assignedLeads = metrics ? metrics.assigned_leads : 0;
    var reports = metrics && metrics.reports ? metrics.reports : {};
    var conversion = reports.conversion_summary || {};
    return {
      total_leads: totalLeads,
      total_calls: metrics ? (metrics.followups_due_today + metrics.overdue_followups) : 0,
      demo_count: conversion.demo_scheduled !== undefined ? conversion.demo_scheduled : demoCount,
      demo_ratio: conversion.demo_ratio_pct !== undefined
        ? conversion.demo_ratio_pct + '%'
        : (totalLeads ? ((demoCount / totalLeads) * 100).toFixed(1) + '%' : '0%'),
      conversion: conversion.conversion_rate_pct !== undefined
        ? conversion.conversion_rate_pct + '%'
        : (totalLeads ? Math.round((assignedLeads / totalLeads) * 100) + '%' : '0%'),
      hot_leads: metrics ? metrics.hot_leads : 0,
      pipeline: metrics ? metrics.pipeline_leads : 0,
      lost_leads: metrics ? metrics.lost_leads : 0,
      warm_leads: metrics ? metrics.warm_leads : 0,
      cold_leads: metrics ? metrics.cold_leads : 0,
      active_employees: metrics ? metrics.active_employees : getDashboardExecutives().length,
      assigned_leads: assignedLeads,
      assignments: assignedLeads,
      unassigned_leads: metrics ? metrics.unassigned_leads : Math.max(0, totalLeads - assignedLeads),
      followups_due_today: metrics ? metrics.followups_due_today : 0,
      overdue_followups: metrics ? metrics.overdue_followups : 0,
      calls_total: metrics ? metrics.calls_total : 0,
      meetings_today: metrics ? metrics.meetings_today : 0,
      bulk_import_total: metrics ? metrics.bulk_import_total : 0,
      bulk_assignment_total: metrics ? metrics.bulk_assignment_total : 0,
      whatsapp_campaigns_total: metrics ? metrics.whatsapp_campaigns_total : 0,
      whatsapp_messages_total: metrics ? metrics.whatsapp_messages_total : 0,
      whatsapp_delivered: metrics ? metrics.whatsapp_delivered : 0,
      whatsapp_failed: metrics ? metrics.whatsapp_failed : 0,
      whatsapp_queued: metrics ? metrics.whatsapp_queued : 0,
      email_campaigns_total: metrics ? metrics.email_campaigns_total : 0,
      email_messages_total: metrics ? metrics.email_messages_total : 0,
      email_delivered: metrics ? metrics.email_delivered : 0,
      email_failed: metrics ? metrics.email_failed : 0,
      email_queued: metrics ? metrics.email_queued : 0,
      sms_campaigns_total: metrics ? metrics.sms_campaigns_total : 0,
      sms_messages_total: metrics ? metrics.sms_messages_total : 0,
      sms_delivered: metrics ? metrics.sms_delivered : 0,
      sms_failed: metrics ? metrics.sms_failed : 0,
      sms_queued: metrics ? metrics.sms_queued : 0,
      dnd_contacts: metrics ? metrics.dnd_contacts : 0,
      consent_approved: metrics ? metrics.consent_approved : 0,
      consent_denied: metrics ? metrics.consent_denied : 0,
      skipped_due_to_dnd: metrics ? metrics.skipped_due_to_dnd : 0,
      skipped_due_to_no_consent: metrics ? metrics.skipped_due_to_no_consent : 0,
      demo_confirmation_pending: metrics && metrics.demo_confirmations ? metrics.demo_confirmations.demo_confirmation_pending : 0,
      demo_confirmation_confirmed: metrics && metrics.demo_confirmations ? metrics.demo_confirmations.demo_confirmation_confirmed : 0,
      demo_confirmation_rejected: metrics && metrics.demo_confirmations ? metrics.demo_confirmations.demo_confirmation_rejected : 0,
      demo_confirmation_rescheduled: metrics && metrics.demo_confirmations ? metrics.demo_confirmations.demo_confirmation_rescheduled : 0,
      demo_confirmation_rejected_after_reschedule: metrics && metrics.demo_confirmations ? metrics.demo_confirmations.demo_confirmation_rejected_after_reschedule : 0,
      target_achievement: totalLeads ? Math.round((assignedLeads / totalLeads) * 100) + '%' : '0%',
    };
  }

  function getDashboardPipelineBreakdown() {
    var leads = getDashboardLeads();
    if (!leads.length) {
      if (USE_DEMO_FALLBACKS) return CAData.getPipelineBreakdown();
      return PIPELINE_STAGES.map(function (stage) { return { stage: stage, count: 0 }; });
    }
    return PIPELINE_STAGES.map(function (stage) {
      return {
        stage: stage,
        count: leads.filter(function (lead) { return lead.stage === stage; }).length,
      };
    });
  }

  function getDashboardCityBreakdown() {
    var leads = getDashboardLeads();
    if (!leads.length) return USE_DEMO_FALLBACKS ? CAData.getCityBreakdown() : [];
    var map = {};
    leads.forEach(function (lead) {
      var city = lead.city || '—';
      map[city] = (map[city] || 0) + 1;
    });
    return Object.keys(map).map(function (city) {
      return { city: city, count: map[city] };
    }).sort(function (a, b) { return b.count - a.count; });
  }

  function getDashboardSourceBreakdown() {
    var leads = getDashboardLeads();
    if (!leads.length) return USE_DEMO_FALLBACKS ? CAData.getSourceBreakdown() : [];
    var map = {};
    leads.forEach(function (lead) {
      var source = lead.source || '—';
      map[source] = (map[source] || 0) + 1;
    });
    return Object.keys(map).map(function (source) {
      return { source: source, count: map[source] };
    }).sort(function (a, b) { return b.count - a.count; });
  }

  function getDashboardPriorityLeads() {
    var leads = getDashboardLeads();
    if (!leads.length) return USE_DEMO_FALLBACKS ? CAData.getPriorityLeads() : [];
    return leads.filter(function (lead) {
      return lead.status === 'Hot' || lead.stage === 'Demo Scheduled' || lead.stage === 'Negotiation';
    }).slice(0, 5);
  }

  function activityModuleIcon(moduleName) {
    var map = {
      CA_MASTER: 'building-2',
      LEAD_ASSIGNMENT_ENGINE: 'user-check',
      FOLLOW_UP_MANAGEMENT: 'phone',
      WHATSAPP_CAMPAIGN: 'message-circle',
      EMAIL_CAMPAIGN: 'mail',
      SMS_CAMPAIGN: 'smartphone',
      EMPLOYEE_MASTER: 'users',
      SECURITY: 'shield',
      STATE_MASTER: 'map-pin',
      CITY_MASTER: 'map',
      ACTIVITY_LOGS: 'activity',
    };
    return map[moduleName] || 'file-text';
  }

  function userInitials(name) {
    return String(name || 'U').split(' ').map(function (part) { return part[0] || ''; }).join('').slice(0, 2).toUpperCase();
  }

  function navigateActivityRecord(log) {
    if (!log) return;
    var module = log.module_name || '';
    var recordId = log.record_id || '';
    if (module === 'CA_MASTER' && recordId) {
      window._leadSegmentFilter = 'all';
      if (typeof navigateTo === 'function') navigateTo('leads');
      return;
    }
    if (module === 'LEAD_ASSIGNMENT_ENGINE') {
      if (typeof navigateTo === 'function') navigateTo('assignment');
      return;
    }
    if (module === 'FOLLOW_UP_MANAGEMENT') {
      if (typeof navigateTo === 'function') navigateTo('followups');
      return;
    }
    if (module.indexOf('CAMPAIGN') >= 0) {
      if (typeof navigateTo === 'function') navigateTo('communication');
      return;
    }
    if (typeof navigateTo === 'function') navigateTo('activity');
  }

  function renderDashboardQuickActions() {
    var el = document.getElementById('dash-quick-actions');
    if (!el) return;
    var actions = [
      { label: 'Add Lead', icon: 'user-plus', modal: 'add-lead' },
      { label: 'Add Employee', icon: 'user', modal: 'add-employee' },
      { label: 'Assign Lead', icon: 'user-check', modal: 'assign-lead' },
      { label: 'Create Follow-up', icon: 'calendar-clock', modal: 'followup' },
      { label: 'WhatsApp Campaign', icon: 'message-circle', page: 'whatsapp', campaign: 'whatsapp' },
      { label: 'Email Campaign', icon: 'mail', page: 'email', campaign: 'email' },
      { label: 'SMS Campaign', icon: 'smartphone', page: 'sms', campaign: 'sms' },
      { label: 'Bulk Import', icon: 'upload', page: 'bulk' },
      { label: 'Reports', icon: 'bar-chart-3', page: 'reports' },
    ];
    el.innerHTML = actions.map(function (action) {
      if (action.modal) {
        return '<button type="button" class="dash-quick-action-btn" data-open-modal="' + action.modal + '">' +
          '<i data-lucide="' + action.icon + '" class="h-4 w-4"></i><span>' + action.label + '</span></button>';
      }
      if (action.campaign) {
        return '<button type="button" class="dash-quick-action-btn" data-nav-page="' + action.page + '" data-open-campaign="' + action.campaign + '">' +
          '<i data-lucide="' + action.icon + '" class="h-4 w-4"></i><span>' + action.label + '</span></button>';
      }
      return '<button type="button" class="dash-quick-action-btn" data-nav-page="' + action.page + '">' +
        '<i data-lucide="' + action.icon + '" class="h-4 w-4"></i><span>' + action.label + '</span></button>';
    }).join('');
    el.querySelectorAll('[data-open-campaign]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (typeof navigateTo === 'function') navigateTo(btn.getAttribute('data-nav-page'));
        setTimeout(function () {
          var modal = document.getElementById('modal-add-campaign');
          if (modal && typeof openModal === 'function') {
            configureCampaignModal(btn.getAttribute('data-open-campaign'));
            openModal(modal);
          }
        }, 150);
      });
    });
  }

  function renderDashboardFilterChips(m) {
    var el = document.getElementById('dash-filter-chips');
    if (!el) return;
    var chips = [
      { label: 'Follow-ups', icon: 'calendar-clock', page: 'followups', filter: 'today' },
      { label: 'Hot Leads', icon: 'flame', page: 'leads', leadFilter: 'hot' },
      { label: 'Do Not Disturb', icon: 'ban', page: 'consent-dnd', tab: 'dnd' },
      { label: 'Delivered', icon: 'send', page: 'whatsapp', commLogStatus: 'Delivered' },
      { label: 'WhatsApp', icon: 'message-circle', page: 'whatsapp' },
      { label: 'Consented', icon: 'fingerprint', page: 'consent-dnd', tab: 'consent' },
    ];
    el.innerHTML = chips.map(function (chip) {
      return '<button type="button" class="dash-filter-chip" data-nav-page="' + chip.page + '"' +
        (chip.leadFilter ? ' data-lead-filter="' + chip.leadFilter + '"' : '') +
        (chip.tab ? ' data-consent-tab="' + chip.tab + '"' : '') +
        (chip.filter ? ' data-followup-filter="' + chip.filter + '"' : '') +
        (chip.commLogStatus ? ' data-comm-log-status="' + chip.commLogStatus + '"' : '') + '>' +
        '<i data-lucide="' + chip.icon + '" class="h-3.5 w-3.5"></i><span>' + chip.label + '</span></button>';
    }).join('');
  }

  function formatActivityDescription(description) {
    if (!description) return '';
    return String(description).replace(/\(([A-Z_]+)\)/g, function (_match, code) {
      var label = bulkAssignReasonLabel(code);
      return label && label !== '—' ? '(' + label + ')' : _match;
    });
  }

  function renderActivityFeedItem(log, opts) {
    opts = opts || {};
    var userLabel = escapeHtml(log.performed_by || opts.userFallback || 'System');
    var actionLabel = escapeHtml(log.action || 'Update');
    var detail = escapeHtml(formatActivityDescription(log.description || ''));
    var timeLabel = escapeHtml(formatTimeAgo(log.timestamp));
    var activityId = escapeHtml(log.id || log.log_id || '');
    var extraClass = opts.extraClass ? ' ' + opts.extraClass : '';
    var idAttr = opts.includeId !== false && activityId
      ? ' data-activity-id="' + activityId + '"'
      : '';
    return '<button type="button" class="dash-activity-item' + extraClass + '"' + idAttr + '>' +
      '<span class="dash-activity-avatar">' + escapeHtml(userInitials(log.performed_by || opts.userFallback || 'System')) + '</span>' +
      '<span class="dash-activity-icon"><i data-lucide="' + activityModuleIcon(log.module_name) + '" class="h-4 w-4"></i></span>' +
      '<span class="dash-activity-body">' +
        '<span class="dash-activity-user">' + userLabel + '</span>' +
        '<span class="dash-activity-action">' + actionLabel + '</span>' +
        '<span class="dash-activity-detail">' + detail + '</span>' +
      '</span>' +
      '<span class="dash-activity-time">' + timeLabel + '</span>' +
    '</button>';
  }

  function paintDashboardBarChart(el, rows, labelKey, valueKey) {
    if (!el) return;
    if (!rows || !rows.length) {
      el.innerHTML = '<p class="text-caption text-slate-400 p-3">No data available yet.</p>';
      return;
    }
    var max = Math.max.apply(null, rows.map(function (row) { return row[valueKey] || 0; }).concat([1]));
    el.innerHTML = rows.slice(0, 8).map(function (row) {
      var val = row[valueKey] || 0;
      return '<div class="mgr-bar-row">' +
        '<span class="mgr-bar-label">' + escapeHtml(row[labelKey] || '—') + '</span>' +
        '<div class="mgr-bar-track"><div class="mgr-bar-fill" style="width:' + Math.round((val / max) * 100) + '%"></div></div>' +
        '<span class="mgr-bar-val">' + val + '</span></div>';
    }).join('');
  }

  function renderDashboardCharts(reports) {
    reports = reports || (window.dashboardMetrics && window.dashboardMetrics.reports) || {};
    paintDashboardBarChart(
      document.getElementById('dash-chart-source'),
      (reports.source_breakdown || []).map(function (d) { return { source: d.source, count: d.count }; }),
      'source',
      'count',
    );
    paintDashboardBarChart(
      document.getElementById('dash-chart-status'),
      (reports.status_breakdown || []).map(function (d) { return { status: d.status, count: d.lead_count }; }),
      'status',
      'count',
    );
    paintDashboardBarChart(
      document.getElementById('dash-chart-city'),
      (reports.city_breakdown || []).map(function (d) { return { city: d.city, count: d.total_leads }; }),
      'city',
      'count',
    );
    paintDashboardBarChart(
      document.getElementById('dash-chart-campaign'),
      (reports.campaign_channels || []).map(function (d) { return { channel: d.channel, count: d.delivered || d.messages_total || 0 }; }),
      'channel',
      'count',
    );
    var monthlyEl = document.querySelector('[data-chart="monthly"]');
    if (monthlyEl) {
      paintReportChart(monthlyEl, (reports.monthly_trends || []).map(function (row) {
        return { label: row.month, value: row.new_leads || 0 };
      }));
    }
    var employeeEl = document.querySelector('[data-chart="employee"]');
    if (employeeEl) {
      paintReportChart(employeeEl, (reports.employee_performance || []).slice(0, 8).map(function (row) {
        return { label: (row.employee_name || 'Exec').split(' ')[0], value: row.achievement_pct || 0 };
      }));
    }
  }

  function renderRecentActivity() {
    var el = document.getElementById('recent-activity-list');
    if (!el) return;
    apiFetch('/activity-logs?limit=8')
      .then(function (body) {
        var logs = unwrapActivityPayload(body).logs;
        if (!logs.length) {
          el.innerHTML = '<p class="text-caption text-slate-400 p-3">No activity yet — actions will appear here in real time.</p>';
          return;
        }
        el.innerHTML = logs.map(function (a) {
          return renderActivityFeedItem(a);
        }).join('');
        el.querySelectorAll('.dash-activity-item').forEach(function (btn, idx) {
          btn.addEventListener('click', function () {
            navigateActivityRecord(logs[idx]);
          });
        });
        icons();
      })
      .catch(function () {
        el.innerHTML = '<p class="text-caption text-slate-400 p-3">Unable to load activity feed.</p>';
      });
  }

  function isEmployeeUser() {
    var u = window.__CRM_USER__ || {};
    return u.role === 'employee';
  }

  function loadEmployeeDashboardFromDatabase(callback, forceRefresh) {
    if (forceRefresh) {
      employeeDashboardLoaded = false;
      employeeDashboardData = null;
      employeeDashboardPromise = null;
    }
    if (employeeDashboardLoaded && employeeDashboardData && !forceRefresh) {
      if (callback) callback(employeeDashboardData);
      return;
    }
    if (employeeDashboardPromise) {
      employeeDashboardPromise.then(function (data) {
        if (callback) callback(data);
      });
      return;
    }
    employeeDashboardPromise = apiFetch('/dashboard/employee')
      .then(function (body) {
        employeeDashboardData = body.data || {};
        employeeDashboardLoaded = true;
        return employeeDashboardData;
      })
      .catch(function () {
        employeeDashboardData = null;
        employeeDashboardLoaded = true;
        return null;
      })
      .finally(function () {
        employeeDashboardPromise = null;
      });
    employeeDashboardPromise.then(function (data) {
      if (callback) callback(data);
    });
  }

  function renderEmployeeDashboard() {
    loadEmployeeDashboardFromDatabase(function (data) {
      if (!data) {
        toast('Unable to load your dashboard', 'error');
        return;
      }
      paintEmployeeDashboard(data);
      if (window.CA_RBAC && typeof CA_RBAC.enforce === 'function') CA_RBAC.enforce();
      icons();
    }, true);
  }

  function paintEmployeeDashboard(data) {
    var crmUser = window.__CRM_USER__ || {};
    var welcome = data.welcome || {};
    var summary = data.summary || {};
    var todayWork = data.today_work || {};
    var now = new Date();
    var greeting = now.getHours() < 12 ? 'Good morning' : now.getHours() < 17 ? 'Good afternoon' : 'Good evening';
    var timeStr = now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
    var dateStr = now.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

    var top = document.getElementById('emp-top-header');
    if (top) {
      top.innerHTML =
        '<div class="emp-top-left">' +
          '<span class="emp-role-badge"><i data-lucide="briefcase" class="h-3.5 w-3.5"></i> ' + escapeHtml(welcome.designation || crmUser.designation || 'Sales Executive') + '</span>' +
          '<h1 class="emp-greeting">' + escapeHtml(dashboardGreetingText(greeting, welcome.name || crmUser.name, welcome.designation || crmUser.designation)) + '</h1>' +
          '<p class="emp-top-meta">' + escapeHtml(dateStr) + ' · ' + escapeHtml(timeStr) + ' · ' + escapeHtml(welcome.working_status || 'On track') + '</p>' +
        '</div>';
    }

    var kpiDefs = [
      { icon: 'users', label: 'My Leads', key: 'my_leads', nav: 'leads' },
      { icon: 'calendar-clock', label: 'My Follow-ups', key: 'my_followups', nav: 'followups' },
      { icon: 'presentation', label: 'My Demos', key: 'my_demos', nav: 'followups' },
      { icon: 'video', label: 'My Meetings', key: 'my_meetings', nav: 'followups' },
      { icon: 'phone', label: "Today's Calls", key: 'todays_calls', nav: 'followups' },
      { icon: 'list-checks', label: "Today's Tasks", key: 'todays_tasks', nav: 'followups' },
      { icon: 'flame', label: 'Hot Leads', key: 'hot_leads', nav: 'leads', filter: 'hot' },
      { icon: 'thermometer', label: 'Warm Leads', key: 'warm_leads', nav: 'leads', filter: 'pipeline' },
      { icon: 'snowflake', label: 'Cold Leads', key: 'cold_leads', nav: 'leads', filter: 'cold' },
      { icon: 'trending-up', label: 'Conversion', key: 'conversion_pct', nav: 'leads', suffix: '%' },
      { icon: 'target', label: "Today's Target", key: 'todays_target', nav: 'leads' },
      { icon: 'award', label: "Today's Achievement", key: 'todays_achievement', nav: 'leads' },
    ];
    var kpiGrid = document.getElementById('emp-kpi-grid');
    if (kpiGrid) {
      kpiGrid.innerHTML = kpiDefs.map(function (k) {
        var val = summary[k.key];
        if (k.suffix && val !== undefined && val !== null) val = val + k.suffix;
        return '<button type="button" class="emp-kpi-card" data-emp-nav="' + k.nav + '"' +
          (k.filter ? ' data-emp-lead-filter="' + k.filter + '"' : '') + '>' +
          '<span class="emp-kpi-icon"><i data-lucide="' + k.icon + '" class="h-4 w-4"></i></span>' +
          '<span class="emp-kpi-value">' + (val !== undefined ? val : '0') +
          '<span class="emp-kpi-label">' + k.label + '</span></button>';
      }).join('');
    }

    var todayGrid = document.getElementById('emp-today-grid');
    if (todayGrid) {
      var todayItems = [
        { label: "Today's Follow-ups", value: todayWork.followups_due, nav: 'followups', filter: 'today' },
        { label: 'Overdue Follow-ups', value: todayWork.followups_overdue, nav: 'followups', filter: 'overdue' },
        { label: "Today's Meetings", value: todayWork.meetings_today, nav: 'followups' },
        { label: "Today's Assigned Leads", value: todayWork.assigned_leads_today, nav: 'leads' },
        { label: "Today's Calls", value: todayWork.calls_today, nav: 'followups' },
        { label: 'Upcoming Tasks', value: todayWork.upcoming_tasks, nav: 'followups' },
      ];
      todayGrid.innerHTML = todayItems.map(function (item) {
        return '<button type="button" class="emp-today-card" data-emp-nav="' + item.nav + '"' +
          (item.filter ? ' data-emp-followup-filter="' + item.filter + '"' : '') + '>' +
          '<span class="emp-today-value">' + (item.value || 0) +
          '<span class="emp-today-label">' + item.label + '</span></button>';
      }).join('');
    }

    renderEmployeeAssignedLeads(data.assigned_leads || []);
    renderEmployeeFollowups(data.followups || {});
    renderEmployeeCalendar(data.calendar || []);
    renderEmployeeActivity(data.recent_activity || []);
    renderEmployeeQuickActions();
    initEmployeeDashboardInteractions();
  }

  function renderEmployeeAssignedLeads(leads) {
    var el = document.getElementById('emp-assigned-leads');
    if (!el) return;
    if (!leads.length) {
      el.innerHTML = '<p class="text-caption text-slate-400 p-3">No assigned leads yet.</p>';
      return;
    }
    el.innerHTML = leads.map(function (lead) {
      return '<button type="button" class="emp-list-item" data-emp-open-lead="' + escapeHtml(lead.ca_id) + '">' +
        '<span class="emp-list-main"><strong>' + escapeHtml(lead.firm_name) + '</strong><span class="text-caption text-slate-500">' + escapeHtml(lead.status) + '</span></span>' +
        '<span class="emp-list-meta">P' + (lead.priority_score || 1) + ' · ' + escapeHtml(formatDate(lead.assigned_date)) + '</span></button>';
    }).join('');
  }

  function renderEmployeeFollowups(followups) {
    var tabsEl = document.getElementById('emp-followups-tabs');
    var listEl = document.getElementById('emp-followups-list');
    if (!tabsEl || !listEl) return;
    var tabs = [
      { id: 'today', label: 'Today', items: followups.today || [] },
      { id: 'pending', label: 'Pending', items: followups.pending || [] },
      { id: 'completed', label: 'Completed', items: followups.completed || [] },
      { id: 'overdue', label: 'Overdue', items: followups.overdue || [] },
    ];
    window._empFollowupTab = window._empFollowupTab || 'today';
    tabsEl.innerHTML = tabs.map(function (tab) {
      return '<button type="button" class="emp-tab' + (window._empFollowupTab === tab.id ? ' active' : '') + '" data-emp-fu-tab="' + tab.id + '">' +
        tab.label + ' (' + tab.items.length + ')</button>';
    }).join('');
    var active = tabs.find(function (t) { return t.id === window._empFollowupTab; }) || tabs[0];
    listEl.innerHTML = active.items.length ? active.items.map(function (f) {
      return '<button type="button" class="emp-list-item' + (f.status === 'Overdue' ? ' emp-list-item-overdue' : '') + '" data-emp-open-followup="' + f.followup_id + '">' +
        '<span class="emp-list-main"><strong>' + escapeHtml(f.firm_name) + '</strong><span class="text-caption text-slate-500">' + escapeHtml(f.followup_type) + (f.is_rescheduled ? ' · Rescheduled' : '') + '</span></span>' +
        '<span class="emp-list-meta">' + escapeHtml(formatDateTime(f.scheduled_date)) + '</span></button>';
    }).join('') : '<p class="text-caption text-slate-400 p-3">No follow-ups in this view.</p>';
  }

  function renderEmployeeCalendar(items) {
    var el = document.getElementById('emp-calendar-list');
    if (!el) return;
    if (!items.length) {
      el.innerHTML = '<p class="text-caption text-slate-400 p-3">No upcoming schedule.</p>';
      return;
    }
    el.innerHTML = items.map(function (item) {
      return '<button type="button" class="emp-list-item" data-emp-open-followup="' + item.followup_id + '">' +
        '<span class="emp-list-main"><strong>' + escapeHtml(item.title) + '</strong><span class="text-caption text-slate-500">' + escapeHtml(item.followup_type) + '</span></span>' +
        '<span class="emp-list-meta">' + escapeHtml(formatDate(item.scheduled_date)) + (item.scheduled_time ? ' ' + item.scheduled_time : '') + '</span></button>';
    }).join('');
  }

  function renderEmployeeActivity(logs) {
    var el = document.getElementById('emp-activity-list');
    if (!el) return;
    if (!logs.length) {
      el.innerHTML = '<p class="text-caption text-slate-400 p-3">No recent activity yet.</p>';
      return;
    }
    el.innerHTML = logs.map(function (a) {
      return renderActivityFeedItem(a, { extraClass: 'emp-activity-item', userFallback: 'Me' });
    }).join('');
  }

  function renderEmployeeQuickActions() {
    var el = document.getElementById('emp-quick-actions');
    if (!el) return;
    var actions = [
      { label: 'Open My Leads', icon: 'users', nav: 'leads' },
      { label: 'Create Follow-up', icon: 'calendar-plus', modal: 'followup' },
      { label: 'Update Lead Status', icon: 'edit-3', nav: 'leads' },
      { label: 'Complete Task', icon: 'check-circle', nav: 'followups' },
      { label: 'Schedule Demo', icon: 'presentation', modal: 'followup' },
      { label: 'View Calendar', icon: 'calendar', nav: 'followups' },
      { label: 'Log Call', icon: 'phone', modal: 'followup' },
    ];
    el.innerHTML = actions.map(function (action) {
      if (action.modal) {
        return '<button type="button" class="emp-quick-btn" data-open-modal="' + action.modal + '">' +
          '<i data-lucide="' + action.icon + '" class="h-4 w-4"></i><span>' + action.label + '</span></button>';
      }
      return '<button type="button" class="emp-quick-btn" data-emp-nav="' + action.nav + '">' +
        '<i data-lucide="' + action.icon + '" class="h-4 w-4"></i><span>' + action.label + '</span></button>';
    }).join('');
  }

  function initEmployeeDashboardInteractions() {
    var root = document.querySelector('.emp-dashboard');
    if (!root || root._empDashBound) return;
    root._empDashBound = true;
    root.addEventListener('click', function (e) {
      var navBtn = e.target.closest('[data-emp-nav]');
      if (navBtn) {
        if (navBtn.dataset.empLeadFilter) window._leadSegmentFilter = navBtn.dataset.empLeadFilter;
        if (navBtn.dataset.empFollowupFilter) window._followupDateFilter = navBtn.dataset.empFollowupFilter;
        if (typeof navigateTo === 'function') navigateTo(navBtn.dataset.empNav);
        return;
      }
      var fuTab = e.target.closest('[data-emp-fu-tab]');
      if (fuTab) {
        window._empFollowupTab = fuTab.dataset.empFuTab;
        renderEmployeeFollowups((employeeDashboardData && employeeDashboardData.followups) || {});
        icons();
        return;
      }
      if (e.target.closest('[data-emp-open-lead]')) {
        if (typeof navigateTo === 'function') navigateTo('leads');
        return;
      }
      if (e.target.closest('[data-emp-open-followup]')) {
        if (typeof navigateTo === 'function') navigateTo('followups');
      }
    });
    bindModalTriggers(root);
  }

  function renderManagerDashboard() {
    Promise.all([
      new Promise(function (resolve) { loadDashboardMetricsFromDatabase(resolve); }),
      new Promise(function (resolve) { loadLeadsFromDatabase(resolve); }),
      new Promise(function (resolve) { loadEmployeesFromDatabase(resolve); }),
      new Promise(function (resolve) { loadAssignmentsFromDatabase(resolve); }),
      new Promise(function (resolve) { loadFollowUpsFromDatabase(resolve); }),
    ]).then(function (results) {
      var metrics = results[0];
      paintManagerDashboard(buildDashboardDisplayMetrics(metrics));
      if (window.CA_RBAC && typeof CA_RBAC.enforce === 'function') CA_RBAC.enforce();
    });
  }

  function dashboardGreetingText(greeting, name, roleLabel) {
    name = (name || '').trim();
    if (!name) {
      return greeting;
    }
    var role = (roleLabel || '').trim();
    var parts = name.split(/\s+/);
    var displayName = (parts.length === 1 || name.toLowerCase() === role.toLowerCase())
      ? name
      : parts[0];
    return greeting + ', ' + displayName;
  }

  function paintManagerDashboard(m) {
    var crmUser = window.__CRM_USER__ || {};
    var displayName = crmUser.name || 'User';
    var roleLabel = crmUser.role_label || crmUser.role || 'User';
    var now = new Date();
    var greeting = now.getHours() < 12 ? 'Good morning' : now.getHours() < 17 ? 'Good afternoon' : 'Good evening';
    var dateStr = now.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    var greetingLine = dashboardGreetingText(greeting, displayName, roleLabel);

    var top = document.getElementById('mgr-top-header');
    if (top) {
      top.innerHTML =
        '<div class="mgr-top-left">' +
          '<span class="manager-role-badge"><i data-lucide="layout-dashboard" class="h-3.5 w-3.5"></i> ' + escapeHtml(roleLabel) + '</span>' +
          '<h1 class="mgr-greeting">' + escapeHtml(greetingLine) + '</h1>' +
          '<p class="mgr-top-meta">' + escapeHtml(dateStr) + ' · ' + m.active_employees + ' executives · ' + m.total_leads + ' leads · ' + m.assigned_leads + ' assigned · ' + m.followups_due_today + ' follow-ups today</p>' +
        '</div>';
    }

    var kpiDefs = [
      { icon: 'users', label: 'Total Leads', key: 'total_leads', nav: 'leads', leadFilter: 'all' },
      { icon: 'flame', label: 'Hot Leads', key: 'hot_leads', nav: 'leads', leadFilter: 'hot' },
      { icon: 'thermometer', label: 'Warm Leads', key: 'warm_leads', nav: 'leads', leadFilter: 'pipeline' },
      { icon: 'snowflake', label: 'Cold Leads', key: 'cold_leads', nav: 'leads', leadFilter: 'cold' },
      { icon: 'user-check', label: 'Employees', key: 'active_employees', nav: 'employees' },
      { icon: 'git-branch', label: 'Assignments', key: 'assignments', nav: 'assignment' },
      { icon: 'calendar-clock', label: "Today's Follow-ups", key: 'followups_due_today', nav: 'followups', followupFilter: 'today' },
      { icon: 'alert-circle', label: 'Overdue Follow-ups', key: 'overdue_followups', nav: 'followups', followupFilter: 'overdue' },
      { icon: 'trending-up', label: 'Conversion Rate', key: 'conversion', nav: 'analytics' },
      { icon: 'percent', label: 'Demo Ratio', key: 'demo_ratio', nav: 'analytics' },
      { icon: 'phone', label: 'Calls', key: 'calls_total', nav: 'followups' },
      { icon: 'video', label: 'Meetings', key: 'meetings_today', nav: 'followups' },
      { icon: 'clock', label: 'Pending Confirmation', key: 'demo_confirmation_pending', nav: 'followups' },
      { icon: 'badge-check', label: 'Confirmed', key: 'demo_confirmation_confirmed', nav: 'followups' },
      { icon: 'badge-x', label: 'Rejected', key: 'demo_confirmation_rejected', nav: 'followups' },
      { icon: 'calendar-clock', label: 'Rescheduled', key: 'demo_confirmation_rescheduled', nav: 'followups' },
      { icon: 'shield-alert', label: 'Rejected After Reschedule', key: 'demo_confirmation_rejected_after_reschedule', nav: 'followups' },
    ];
    var kpiGrid = document.getElementById('mgr-kpi-grid');
    if (kpiGrid) {
      kpiGrid.innerHTML = kpiDefs.map(function (k) {
        return '<button type="button" class="mgr-kpi-card dash-kpi-card" data-nav-page="' + k.nav + '"' +
          (k.leadFilter ? ' data-lead-filter="' + k.leadFilter + '"' : '') +
          (k.followupFilter ? ' data-followup-filter="' + k.followupFilter + '"' : '') +
          ' data-kpi="' + k.label + '">' +
          '<div class="mgr-kpi-top"><span class="mgr-kpi-icon"><i data-lucide="' + k.icon + '" class="h-4 w-4"></i></span></div>' +
          '<p class="mgr-kpi-value" data-metric="' + k.key + '">' + (m[k.key] !== undefined ? m[k.key] : '—') + '</p>' +
          '<p class="mgr-kpi-label">' + k.label + '</p></button>';
      }).join('');
    }

    renderDashboardFilterChips(m);
    renderSmsDashboardWidgets(m);
    loadManagerFollowUpMetrics();
    renderDashboardCharts(window.dashboardMetrics && window.dashboardMetrics.reports);
    renderPipelineFunnel();
    renderPriorityList();
    renderTeamCards(getDashboardExecutives());
    renderTeamOverview(getDashboardExecutives());
    renderDashboardLeads();
    renderRecentActivity();
    initDashboardInteractions(top);
    icons();
  }

  function loadManagerFollowUpMetrics() {
    var panel = document.getElementById('mgr-followup-automation-panel');
    if (!panel) return;
    apiFetch('/follow-ups/manager-metrics')
      .then(function (body) {
        var d = body.data || {};
        setText('mgr-fu-today', d.today);
        setText('mgr-fu-upcoming', d.upcoming);
        setText('mgr-fu-completed-today', d.completed_today);
        setText('mgr-fu-missed', d.missed);
        setText('mgr-fu-overdue', d.overdue);
        setText('mgr-fu-conversion', (d.followup_conversion_pct || 0) + '%');
        setText('mgr-fu-demo-conversion', (d.demo_conversion_pct || 0) + '%');
        var list = document.getElementById('mgr-fu-employee-list');
        if (list) {
          list.innerHTML = (d.employees || []).map(function (row) {
            return '<div class="mgr-fu-emp-row"><strong>' + escapeHtml(row.name) + '</strong>' +
              '<span>Pending: ' + row.pending_followups + '</span>' +
              '<span class="text-rose-600">Overdue: ' + row.overdue_followups + '</span>' +
              '<span>Tasks: ' + row.pending_tasks + '</span></div>';
          }).join('') || '<p class="text-caption text-slate-500">No active employees.</p>';
        }
      })
      .catch(function () {});
  }

  function renderPipelineFunnel() {
    var el = document.getElementById('mgr-pipeline-funnel');
    if (!el) return;
    var stages = getDashboardPipelineBreakdown();
    var max = Math.max.apply(null, stages.map(function (s) { return s.count; }).concat([1]));
    var colors = { 'New Lead': '#94a3b8', 'Details Shared': '#60a5fa', 'Demo Scheduled': '#25b7a7', 'Demo Completed': '#6366f1', 'Negotiation': '#f59e0b', 'Won': '#10b981', 'Lost': '#ef4444' };
    el.innerHTML = stages.map(function (s) {
      var pct = Math.round((s.count / max) * 100);
      return '<button type="button" class="mgr-funnel-row" data-stage="' + s.stage + '" title="View ' + s.stage + ' leads">' +
        '<span class="mgr-funnel-label">' + s.stage +
        '<div class="mgr-funnel-track"><div class="mgr-funnel-fill" style="width:' + Math.max(pct, s.count ? 12 : 4) + '%;background:' + (colors[s.stage] || '#25b7a7') + '"></div></div>' +
        '<span class="mgr-funnel-count">' + s.count + '</span></button>';
    }).join('');
  }

  function renderPriorityList() {
    var el = document.getElementById('mgr-priority-list');
    if (!el) return;
    var items = getDashboardPriorityLeads();
    if (!items.length) {
      el.innerHTML = '<p class="mgr-empty">No priority items — you\'re all caught up!</p>';
      return;
    }
    el.innerHTML = items.map(function (l) {
      var icon = l.status === 'Hot' ? 'flame' : l.stage.indexOf('Demo') >= 0 ? 'video' : 'message-square';
      return '<button type="button" class="mgr-priority-item" data-lead-id="' + l.ca_id + '">' +
        '<span class="mgr-priority-icon"><i data-lucide="' + icon + '" class="h-4 w-4"></i></span>' +
        '<span class="mgr-priority-body">' +
          '<span class="mgr-priority-firm">' + l.firm_name + '</span>' +
          '<span class="mgr-priority-meta">' + l.city + ' · ' + l.executive + ' · ' + l.stage + '</span>' +
        '</span>' +
        statusBadge(l.status) + '</button>';
    }).join('');
    el.querySelectorAll('.mgr-priority-item').forEach(function (btn) {
      btn.addEventListener('click', function () {
        window._selectedLeadId = btn.dataset.leadId;
        if (typeof CAData !== 'undefined' && CAData.setSelectedLeadId) CAData.setSelectedLeadId(btn.dataset.leadId);
        if (typeof navigateTo === 'function') navigateTo('leads');
      });
    });
  }

  function renderTeamCards(executives) {
    var el = document.getElementById('mgr-team-cards');
    if (!el) return;
    executives = executives || getDashboardExecutives();
    el.innerHTML = executives.map(function (e) {
      var pct = e.target_leads ? Math.round((e.achieved_leads / e.target_leads) * 100) : 0;
      var initials = e.name.split(' ').map(function (n) { return n[0]; }).join('').slice(0, 2);
      return '<button type="button" class="mgr-team-card" data-employee-id="' + e.employee_id + '">' +
        '<span class="mgr-team-avatar">' + initials + '</span>' +
        '<span class="mgr-team-name">' + e.name.split(' ')[0] + '</span>' +
        '<span class="mgr-team-city">' + e.city + '</span>' +
        '<div class="ca-progress"><div class="ca-progress-bar" style="width:' + pct + '%"></div></div>' +
        '<span class="mgr-team-pct">' + pct + '% of target</span></button>';
    }).join('');
    el.querySelectorAll('.mgr-team-card').forEach(function (card) {
      card.addEventListener('click', function () {
        if (typeof navigateTo === 'function') navigateTo('assignment');
      });
    });
  }

  function renderCityBars() {
    var el = document.getElementById('mgr-city-bars');
    if (!el) return;
    var reports = window.dashboardMetrics && window.dashboardMetrics.reports;
    var data = reports && reports.city_breakdown && reports.city_breakdown.length
      ? reports.city_breakdown.map(function (d) { return { city: d.city, count: d.total_leads }; })
      : getDashboardCityBreakdown();
    var max = Math.max.apply(null, data.map(function (d) { return d.count; }).concat([1]));
    el.innerHTML = data.map(function (d) {
      return '<div class="mgr-bar-row">' +
        '<span class="mgr-bar-label">' + escapeHtml(d.city) + '</span>' +
        '<div class="mgr-bar-track"><div class="mgr-bar-fill" style="width:' + Math.round((d.count / max) * 100) + '%"></div></div>' +
        '<span class="mgr-bar-val">' + d.count + '</span></div>';
    }).join('');
  }

  function renderSourceBars() {
    var el = document.getElementById('mgr-source-bars');
    if (!el) return;
    var reports = window.dashboardMetrics && window.dashboardMetrics.reports;
    var data = reports && reports.source_breakdown && reports.source_breakdown.length
      ? reports.source_breakdown.map(function (d) { return { source: d.source, count: d.count }; })
      : getDashboardSourceBreakdown();
    var max = Math.max.apply(null, data.map(function (d) { return d.count; }).concat([1]));
    el.innerHTML = data.map(function (d) {
      return '<div class="mgr-bar-row">' +
        '<span class="mgr-bar-label">' + escapeHtml(d.source) + '</span>' +
        '<div class="mgr-bar-track"><div class="mgr-bar-fill mgr-bar-fill-alt" style="width:' + Math.round((d.count / max) * 100) + '%"></div></div>' +
        '<span class="mgr-bar-val">' + d.count + '</span></div>';
    }).join('');
  }

  function initDashboardInteractions(top) {
    var root = document.querySelector('.mgr-dashboard');
    if (!root) return;
    root.querySelectorAll('[data-nav-page]').forEach(function (btn) {
      if (btn._dashNavBound) return;
      btn._dashNavBound = true;
      btn.addEventListener('click', function () {
        if (btn.dataset.leadFilter) window._leadSegmentFilter = btn.dataset.leadFilter;
        if (btn.dataset.consentTab) window._consentDndTab = btn.dataset.consentTab;
        if (btn.dataset.followupFilter) window._followupDateFilter = btn.dataset.followupFilter;
        if (btn.dataset.commLogStatus) window._commLogStatusFilter = btn.dataset.commLogStatus;
        var page = btn.dataset.navPage;
        if (page === 'bulk') {
          if (typeof navigateTo === 'function') navigateTo('bulk');
          setTimeout(function () {
            if (typeof window.openBulkImportWizard === 'function') window.openBulkImportWizard();
          }, 120);
          return;
        }
        if (typeof navigateTo === 'function') navigateTo(page);
      });
    });
    root.querySelectorAll('.mgr-funnel-row').forEach(function (row) {
      row.addEventListener('click', function () {
        window._leadSegmentFilter = 'pipeline';
        if (typeof navigateTo === 'function') navigateTo('leads');
      });
    });
    if (top) bindModalTriggers(top);
    bindModalTriggers(root);
  }

  function renderTeamOverview(executives) {
    var el = document.getElementById('team-overview-table');
    if (!el) return;
    var reports = window.dashboardMetrics && window.dashboardMetrics.reports;
    var apiExecs = reports && reports.employee_performance;
    if (apiExecs && apiExecs.length) {
      el.innerHTML = apiExecs.map(function (e) {
        var pct = e.achievement_pct || 0;
        return '<tr class="ca-table-row mgr-table-row" data-employee-id="' + e.employee_id + '">' +
          '<td><span class="mgr-table-name">' + escapeHtml(e.employee_name) + '</span></td>' +
          '<td>' + escapeHtml(e.city || '—') + '</td>' +
          '<td>' + e.achieved_leads + '/' + e.target_leads + '</td>' +
          '<td><div class="ca-progress"><div class="ca-progress-bar" style="width:' + pct + '%"></div></div><span class="text-caption">' + pct + '%</span></td>' +
          '<td>' + e.completed_followups + '</td>' +
          '<td>' + e.demo_followups + '</td>' +
        '</tr>';
      }).join('');
      return;
    }
    var execs = executives || getDashboardExecutives();
    el.innerHTML = execs.map(function (e) {
      var pct = e.target_leads ? Math.round((e.achieved_leads / e.target_leads) * 100) : 0;
      return '<tr class="ca-table-row mgr-table-row" data-employee-id="' + e.employee_id + '">' +
        '<td><span class="mgr-table-name">' + e.name + '</span></td>' +
        '<td>' + e.city + '</td>' +
        '<td>' + e.achieved_leads + '/' + e.target_leads + '</td>' +
        '<td><div class="ca-progress"><div class="ca-progress-bar" style="width:' + pct + '%"></div></div><span class="text-caption">' + pct + '%</span></td>' +
        '<td>' + e.daily_calls + '</td>' +
        '<td>' + e.demos + '</td>' +
      '</tr>';
    }).join('');
  }

  function renderDashboardLeads() {
    var el = document.getElementById('dashboard-leads-table');
    if (!el) return;
    var leads = getDashboardLeads();
    if (!leads.length) {
      el.innerHTML = emptyTableRow(4, 'No leads yet — add firms from CA Master.');
      return;
    }
    el.innerHTML = leads.slice(0, 6).map(function (l) {
      var data = JSON.stringify(CAData.leadToRowData(l)).replace(/'/g, '&#39;');
      return '<tr class="ca-table-row mgr-table-row" data-lead-id="' + l.ca_id + '" data-row=\'' + data + '\'>' +
        '<td><span class="mgr-table-name">' + l.firm_name + '</span><span class="mgr-table-sub">' + l.city + '</span></td>' +
        '<td>' + statusBadge(l.status) + '</td>' +
        '<td>' + l.executive + '</td>' +
        '<td class="text-caption text-slate-500">' + l.updated + '</td>' +
      '</tr>';
    }).join('');
    bindLeadRows(el);
  }

  function getLeadFilter() {
    return (typeof window._leadSegmentFilter !== 'undefined' && window._leadSegmentFilter) || 'all';
  }

  function getRealLeadsFiltered() {
    var leads = (window.realLeads || []).slice();
    var segment = getLeadFilter();
    if (!segment || segment === 'all') return leads;
    if (segment === 'new') {
      return leads.filter(function (l) { return l.status === 'New' || l.stage === 'New Lead'; });
    }
    if (segment === 'hot') {
      return leads.filter(function (l) { return l.status === 'Hot'; });
    }
    if (segment === 'cold') {
      return leads.filter(function (l) { return l.status === 'Cold'; });
    }
    if (segment === 'lost') {
      return leads.filter(function (l) { return l.status === 'Lost' || l.stage === 'Lost'; });
    }
    if (segment === 'pipeline') {
      return leads.filter(function (l) {
        return ['Pipeline', 'Warm', 'Details Shared', 'Demo Scheduled', 'Demo Completed', 'Negotiation'].indexOf(l.status) >= 0 ||
          ['Details Shared', 'Demo Scheduled', 'Demo Completed', 'Negotiation'].indexOf(l.stage) >= 0;
      });
    }
    return leads;
  }

  function getRealLeadCounts() {
    var leads = window.realLeads || [];
    return {
      all: leads.length,
      new: leads.filter(function (l) { return l.status === 'New' || l.stage === 'New Lead'; }).length,
      hot: leads.filter(function (l) { return l.status === 'Hot'; }).length,
      cold: leads.filter(function (l) { return l.status === 'Cold'; }).length,
      pipeline: leads.filter(function (l) {
        return ['Pipeline', 'Warm', 'Details Shared', 'Demo Scheduled', 'Demo Completed', 'Negotiation'].indexOf(l.status) >= 0 ||
          ['Details Shared', 'Demo Scheduled', 'Demo Completed', 'Negotiation'].indexOf(l.stage) >= 0;
      }).length,
      lost: leads.filter(function (l) { return l.status === 'Lost' || l.stage === 'Lost'; }).length,
    };
  }

  function emptyTableRow(colspan, message) {
    return '<tr><td colspan="' + colspan + '" class="text-center text-slate-500 p-4">' + message + '</td></tr>';
  }

  function openProfileEditModal() {
    var u = window.__CRM_USER__ || {};
    var form = document.getElementById('form-edit-profile');
    var modal = document.getElementById('modal-edit-profile');
    if (!form || !modal) return;
    var nameInput = form.querySelector('[name="name"]');
    var emailInput = form.querySelector('[name="email"]');
    var designationInput = form.querySelector('[name="designation"]');
    var mobileInput = form.querySelector('[name="mobile_no"]');
    if (nameInput) nameInput.value = u.name || '';
    if (emailInput) emailInput.value = u.email || '';
    var roleDisplay = document.getElementById('profile-edit-role-display');
    if (roleDisplay) roleDisplay.textContent = u.role_label || u.role || '—';
    var desigWrap = document.getElementById('profile-field-designation');
    var mobileWrap = document.getElementById('profile-field-mobile');
    if (desigWrap) {
      if (u.employee_id) {
        desigWrap.classList.remove('hidden');
        if (designationInput) designationInput.value = u.designation || '';
      } else {
        desigWrap.classList.add('hidden');
        if (designationInput) designationInput.value = '';
      }
    }
    if (mobileWrap) {
      if (u.employee_id) {
        mobileWrap.classList.remove('hidden');
        if (mobileInput) mobileInput.value = u.mobile || '';
      } else {
        mobileWrap.classList.add('hidden');
        if (mobileInput) mobileInput.value = '';
      }
    }
    if (typeof closeDetailDrawer === 'function') closeDetailDrawer();
    openModal(modal);
    icons();
  }

  function demoConfirmationStatusBadge(status) {
    if (status === 'confirmed') return '<span class="badge-success">Confirmed</span>';
    if (status === 'rejected') return '<span class="badge-danger">Rejected</span>';
    if (status === 'pending') return '<span class="badge-warning">Pending</span>';
    if (status === 'superseded') return '<span class="text-slate-400">Superseded</span>';
    return escapeHtml(status || '—');
  }

  function renderLeadDemoConfirmationSection(summary) {
    if (!summary || !summary.has_confirmation) {
      return '<div class="detail-section mt-6 pt-4 border-t border-slate-200">' +
        '<p class="text-caption font-semibold text-slate-700 mb-3">Customer Confirmation</p>' +
        '<p class="text-caption text-slate-400">No demo confirmation yet. Schedule a demo to send confirmation SMS.</p>' +
      '</div>';
    }
    return '<div class="detail-section mt-6 pt-4 border-t border-slate-200">' +
      '<p class="text-caption font-semibold text-slate-700 mb-3">Customer Confirmation</p>' +
      '<div class="grid sm:grid-cols-2 gap-3">' +
        '<div class="detail-field"><span class="detail-field-label">Status</span><span class="detail-field-value">' + demoConfirmationStatusBadge(summary.status) + '</span></div>' +
        '<div class="detail-field"><span class="detail-field-label">Demo Slot</span><span class="detail-field-value">' + escapeHtml(summary.demo_slot || '—') + '</span></div>' +
        '<div class="detail-field"><span class="detail-field-label">Last SMS Sent</span><span class="detail-field-value">' + escapeHtml(summary.last_sms_sent_at ? formatDateTime(summary.last_sms_sent_at) : '—') + '</span></div>' +
        '<div class="detail-field"><span class="detail-field-label">Confirmation Time</span><span class="detail-field-value">' + escapeHtml(summary.confirmed_at ? formatDateTime(summary.confirmed_at) : '—') + '</span></div>' +
        '<div class="detail-field sm:col-span-2"><span class="detail-field-label">Confirmed By</span><span class="detail-field-value">' + escapeHtml(summary.confirmed_by || '—') + '</span></div>' +
      '</div>' +
      (summary.timeline && summary.timeline.length ? renderLeadDemoConfirmationTimeline(summary.timeline) : '') +
    '</div>';
  }

  function renderLeadDemoConfirmationTimeline(items) {
    return '<div class="mt-4"><p class="text-caption font-semibold text-slate-600 mb-2">Confirmation Timeline</p>' +
      '<div class="space-y-2">' +
      items.map(function (item) {
        var meta = ACTIVITY_ACTION_META[item.action] || { icon: 'activity', color: 'bg-slate-500' };
        return '<div class="flex items-start gap-2 text-caption">' +
          '<span class="inline-flex h-6 w-6 items-center justify-center rounded-full text-white shrink-0 ' + meta.color + '"><i data-lucide="' + meta.icon + '" class="h-3 w-3"></i></span>' +
          '<div class="min-w-0"><p class="font-medium text-slate-700">' + escapeHtml(item.action) + '</p>' +
          '<p class="text-slate-500">' + escapeHtml(item.description || '') + '</p>' +
          '<p class="text-slate-400">' + escapeHtml(item.timestamp ? formatDateTime(item.timestamp) : '') + '</p></div></div>';
      }).join('') +
      '</div></div>';
  }

  function openLeadDrawer(lead) {
    if (!lead || typeof openDetailDrawer !== 'function') return;
    var data = CAData.leadToRowData(lead);
    openDetailDrawer({
      firm: data.firm,
      fields: [
        { label: 'Reference', value: data.id },
        { label: 'Firm Name', value: data.firm },
        { label: 'CA Name', value: data.ca },
        { label: 'Mobile', value: renderPhoneCell(data.mobile) },
        { label: 'Alternate Mobile', value: renderPhoneCell(data.alternateMobile) },
        { label: 'Email', value: data.email },
        { label: 'GST No.', value: data.gst },
        { label: 'State / City', value: data.state + ' / ' + data.city },
        { label: 'Team Size', value: data.team },
        { label: 'Software', value: data.software },
        { label: 'Website', value: data.website },
        { label: 'Rating', value: data.rating + ' / 5' },
        { label: 'New Firm', value: data.newFirm ? 'Yes' : 'No' },
        { label: 'Executive', value: data.executive },
        { label: 'Stage', value: data.stage },
        { label: 'Status', value: data.status },
        { label: 'Source', value: data.source },
        { label: 'Last Action', value: data.last_action },
      ],
      extraHtml: '<div id="lead-demo-confirmation-section"><p class="text-caption text-slate-400 mt-4">Loading confirmation…</p></div>',
    });
    apiFetch('/ca-masters/' + encodeURIComponent(lead.ca_id) + '/demo-confirmation')
      .then(function (body) {
        var payload = body.data || {};
        var section = document.getElementById('lead-demo-confirmation-section');
        if (!section) return;
        var summary = payload.summary || {};
        summary.timeline = payload.timeline || [];
        section.outerHTML = renderLeadDemoConfirmationSection(summary);
        icons();
      })
      .catch(function () {
        var section = document.getElementById('lead-demo-confirmation-section');
        if (section) {
          section.outerHTML = renderLeadDemoConfirmationSection(null);
        }
      });
  }

  function updateSelectedLeadBar(lead) {
    var bar = document.getElementById('leads-selected-bar');
    var name = document.getElementById('leads-selected-name');
    var meta = document.getElementById('leads-selected-meta');
    if (!bar) return;
    if (!lead) {
      bar.classList.add('hidden');
      return;
    }
    bar.classList.remove('hidden');
    if (name) name.textContent = lead.firm_name;
    if (meta) meta.textContent = lead.city + ' · ' + lead.status + ' · ' + lead.stage + ' · ' + lead.executive;
  }

  function highlightLeadSelection(leadId) {
    document.querySelectorAll('.ca-table-row[data-lead-id]').forEach(function (r) {
      r.classList.toggle('selected', r.dataset.leadId === leadId);
    });
    document.querySelectorAll('.kanban-card[data-lead-id]').forEach(function (c) {
      c.classList.toggle('kanban-card-selected', c.dataset.leadId === leadId);
    });
  }

  function selectLead(leadId, openDrawer) {
    var lead = getLeadRecord(leadId);
    if (!lead) return;
    CAData.setSelectedLeadId(leadId);
    highlightLeadSelection(leadId);
    updateSelectedLeadBar(lead);
    if (openDrawer) openLeadDrawer(lead);
  }

  function renderLeadKpis() {
    var el = document.getElementById('leads-kpi-strip');
    if (!el) return;
    var m = window.dashboardMetrics || {};
    if (!dashboardMetricsLoaded && m.total_leads === undefined) {
      el.innerHTML = '<p class="text-caption text-slate-400 px-2 py-3">Loading KPIs…</p>';
      loadDashboardMetricsFromDatabase(function (metrics) {
        window.dashboardMetrics = metrics || {};
        renderLeadKpis();
      });
      return;
    }
    var counts = {
      all: m.total_leads || 0,
      new: m.new_leads || 0,
      hot: m.hot_leads || 0,
      pipeline: m.pipeline_leads || 0,
      lost: m.lost_leads || 0,
    };
    var active = getLeadFilter();
    var chips = [
      { id: 'all', label: 'All Leads', count: counts.all, icon: 'users' },
      { id: 'new', label: 'New', count: counts.new, icon: 'sparkles' },
      { id: 'hot', label: 'Hot', count: counts.hot, icon: 'flame' },
      { id: 'pipeline', label: 'In Pipeline', count: counts.pipeline, icon: 'git-branch' },
      { id: 'lost', label: 'Lost', count: counts.lost, icon: 'user-x' },
    ];
    el.innerHTML = chips.map(function (c) {
      return '<button type="button" class="leads-kpi-chip' + (active === c.id ? ' active' : '') + '" data-lead-segment="' + c.id + '">' +
        '<i data-lucide="' + c.icon + '" class="h-4 w-4"></i>' +
        '<span class="leads-kpi-label">' + c.label +
        '<span class="leads-kpi-count">' + c.count + '</span></button>';
    }).join('');
    el.querySelectorAll('[data-lead-segment]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        window._leadSegmentFilter = btn.dataset.leadSegment;
        if (window.CA_LISTING_SEARCH) CA_LISTING_SEARCH.setState('ca_masters', { page: 1, filters: { segment: btn.dataset.leadSegment === 'all' ? '' : btn.dataset.leadSegment } });
        renderLeadsHub();
        toast('Showing ' + btn.querySelector('.leads-kpi-label').textContent + ' (' + btn.querySelector('.leads-kpi-count').textContent + ')', 'info');
      });
    });
    icons();
  }

  function renderLeadsHub() {
    renderLeadKpis();
    renderLeadsTable();
    renderKanbanFromData();
    initLeadActions();
    bindModalTriggers(document.getElementById('leads-selected-bar') || document);
    var selId = CAData.getSelectedLeadId();
    if (selId) {
      highlightLeadSelection(selId);
      updateSelectedLeadBar(getLeadRecord(selId));
    } else {
      updateSelectedLeadBar(null);
    }
    icons();
  }

  function renderLeadsTable(pageLeads) {
    var el = document.getElementById('leads-data-table');
    if (!el) return;

    if (pageLeads === undefined && window.CA_LISTING_SEARCH) {
      reloadListing('ca_masters');
      return;
    }

    var leads = pageLeads || getRealLeadsFiltered();

    el.innerHTML = leads.length ? leads.map(function (l) {
      var data = JSON.stringify(CAData.leadToRowData(l)).replace(/'/g, '&#39;');
      return '<tr class="ca-table-row" data-lead-id="' + l.ca_id + '" data-row=\'' + data + '\'>' +
        '<td class="font-medium">' + l.firm_name + '</td>' +
        '<td>' + l.ca_name + '</td>' +
        '<td>' + renderPhoneCell(l.mobile_no) + '</td>' +
        '<td>' + renderPhoneCell(l.alternate_mobile_no) + '</td>' +
        '<td>' + l.city + '</td>' +
        '<td>' + l.stage + '</td>' +
        '<td>' + statusBadge(l.status) + '</td>' +
        '<td>' + l.executive + '</td>' +
        '<td>' + l.source + '</td>' +
        '<td>' + stars(l.priority) + '</td>' +
        '<td class="text-caption">' + l.updated + '</td>' +
        renderLeadQuickActionsCell(l) +
      '</tr>';
    }).join('') : emptyTableRow(12, 'No leads yet. Click Add Lead to create one.');
    bindLeadRows(el);
    bindLeadQuickActions(el);
    icons();
  }

  function renderEmployeesTable(pageEmployees) {
    var el = document.getElementById('employees-data-table');
    if (!el) return;
    if (pageEmployees === undefined && window.CA_LISTING_SEARCH) {
      reloadListing('employees');
      return;
    }
    var employees = pageEmployees || window.realEmployees || [];
    el.innerHTML = employees.length ? employees.map(function (e) {
      return '<tr class="ca-table-row" data-employee-id="' + e.employee_id + '">' +
        '<td class="font-medium">' + e.name + '</td>' +
        '<td>' + e.email_id + '</td>' +
        '<td>' + e.mobile_no + '</td>' +
        '<td>' + e.role + '</td>' +
        '<td>' + loginStatusBadge(e.login_status, e.login_status_label) + '</td>' +
        '<td>' + e.city + '</td>' +
        '<td>' + formatDate(e.date_of_joining) + '</td>' +
        '<td><span class="badge-success">' + e.status + '</span></td>' +
        '<td class="text-right whitespace-nowrap">' +
          '<button type="button" class="btn-secondary btn-sm" data-employee-edit="' + e.employee_id + '">Edit</button> ' +
          '<button type="button" class="btn-secondary btn-sm" data-employee-delete="' + e.employee_id + '">Delete</button>' +
        '</td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="9" class="text-center text-slate-500 p-4">No employees yet.</td></tr>';
    bindEmployeeRowActions(el);
  }

  function bindEmployeeRowActions(container) {
    if (!container) return;
    container.querySelectorAll('[data-employee-edit]').forEach(function (btn) {
      if (btn._empEditBound) return;
      btn._empEditBound = true;
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        openEmployeeFormForEdit(btn.getAttribute('data-employee-edit'));
      });
    });
    container.querySelectorAll('[data-employee-delete]').forEach(function (btn) {
      if (btn._empDelBound) return;
      btn._empDelBound = true;
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var id = btn.getAttribute('data-employee-delete');
        if (!window.confirm('Archive this employee?')) return;
        apiFetch('/employees/' + encodeURIComponent(id), { method: 'DELETE' })
          .then(function () {
            toast('Employee archived', 'success');
            realEmployeesLoaded = false;
            refreshAll();
          })
          .catch(function (err) {
            toast(err.message || 'Unable to delete employee', 'error');
          });
      });
    });
  }

  function openEmployeeFormForEdit(employeeId) {
    var employee = (window.realEmployees || []).find(function (e) {
      return String(e.employee_id) === String(employeeId);
    });
    if (!employee) {
      toast('Employee not found', 'error');
      return;
    }
    window._editingEmployeeId = employee.employee_id;
    var form = document.getElementById('form-add-employee');
    var modal = document.getElementById('modal-add-employee');
    if (!form || !modal) return;
    form.querySelector('[name="name"]').value = employee.name || '';
    form.querySelector('[name="email_id"]').value = employee.email_id || '';
    form.querySelector('[name="mobile_no"]').value = employee.mobile_no || '';
    form.querySelector('[name="date_of_joining"]').value = (employee.date_of_joining || '').slice(0, 10);
    configureEmployeeModalMode('edit', employee);
    if (window.CA_STATE_CITY) {
      window.CA_STATE_CITY.prepareModal(modal);
    }
    openModal(modal);
  }

  function renderAssignmentTable(pageAssignments) {
    var el = document.getElementById('assignment-data-table');
    if (!el) return;
    if (pageAssignments === undefined && window.CA_LISTING_SEARCH) {
      reloadListing('lead_assignments');
      return;
    }
    var assignments = pageAssignments || window.realAssignments || [];
    el.innerHTML = assignments.length ? assignments.map(function (a) {
      return '<tr class="ca-table-row">' +
        '<td class="font-medium">' + (a.firm_name || '—') + '</td>' +
        '<td>' + (a.executive || a.employee_name || '—') + '</td>' +
        '<td><span class="badge' + (a.assignment_type === 'Manual' ? ' bg-amber-50 text-amber-700' : '-brand') + '">' + (a.assignment_type || 'Manual') + '</span></td>' +
        '<td>' + escapeHtml(bulkAssignReasonLabel(a.reason) !== '—' ? bulkAssignReasonLabel(a.reason) : (a.rotation_logic_used || '—')) + '</td>' +
        '<td>' + stars(a.priority_score || 1) + '</td>' +
        '<td>' + (a.target_leads || '—') + '</td>' +
        '<td>' + (a.achieved_leads || '—') + '</td>' +
        '<td><span class="badge-success">' + (a.status || 'Active') + '</span></td>' +
        '<td>' + formatDate(a.assigned_date) + '</td>' +
        '<td class="text-right whitespace-nowrap">' +
          '<button type="button" class="btn-secondary btn-sm" data-assignment-edit="' + a.assignment_id + '">Edit</button> ' +
          '<button type="button" class="btn-secondary btn-sm" data-assignment-delete="' + a.assignment_id + '">Delete</button>' +
        '</td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="10" class="text-center text-slate-500 p-4">No assignments yet.</td></tr>';
    bindAssignmentRowActions(el);
  }

  function bindAssignmentRowActions(container) {
    if (!container) return;
    container.querySelectorAll('[data-assignment-edit]').forEach(function (btn) {
      if (btn._assignEditBound) return;
      btn._assignEditBound = true;
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        openAssignmentFormForEdit(btn.getAttribute('data-assignment-edit'));
      });
    });
    container.querySelectorAll('[data-assignment-delete]').forEach(function (btn) {
      if (btn._assignDelBound) return;
      btn._assignDelBound = true;
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var id = btn.getAttribute('data-assignment-delete');
        if (!window.confirm('Remove this assignment?')) return;
        apiFetch('/lead-assignments/' + encodeURIComponent(id), { method: 'DELETE' })
          .then(function () {
            toast('Assignment removed', 'success');
            realAssignmentsLoaded = false;
            refreshAll();
          })
          .catch(function (err) {
            toast(err.message || 'Unable to delete assignment', 'error');
          });
      });
    });
  }

  function openAssignmentFormForEdit(assignmentId) {
    var assignment = (window.realAssignments || []).find(function (a) {
      return String(a.assignment_id) === String(assignmentId);
    });
    if (!assignment) {
      toast('Assignment not found', 'error');
      return;
    }
    window._editingAssignmentId = assignmentId;
    var modal = document.getElementById('modal-assign-lead');
    var form = document.getElementById('form-assign-lead');
    if (!modal || !form) return;
    ensureFormSelectData(function () {
      populateSelects();
      var leadSelect = document.getElementById('form-assign-lead-select');
      var execSelect = document.getElementById('form-assign-executive');
      if (leadSelect) leadSelect.value = assignment.ca_id || '';
      if (execSelect) execSelect.value = assignment.employee_id || '';
      openModal(modal);
    });
  }

  function renderAssignmentHistoryTable(rows) {
    var el = document.getElementById('assignment-history-table');
    if (!el) return;
    if (rows === undefined && window.CA_LISTING_SEARCH) {
      reloadListing('assignment_histories');
      return;
    }
    var histories = rows || [];
    el.innerHTML = histories.length ? histories.map(function (h) {
      return '<tr class="ca-table-row">' +
        '<td>' + escapeHtml(h.previous_employee || 'Unassigned') + '</td>' +
        '<td>' + escapeHtml(h.new_employee || '—') + '</td>' +
        '<td>' + escapeHtml(h.firm_name || '—') + '</td>' +
        '<td>' + escapeHtml(h.assigned_by_name || h.assigned_by || 'System') + '</td>' +
        '<td>' + escapeHtml(bulkAssignReasonLabel(h.reason) !== '—' ? bulkAssignReasonLabel(h.reason) : (h.assignment_type || '—')) + '</td>' +
        '<td>' + formatDateTime(h.assigned_at) + '</td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="6" class="text-center text-slate-500 p-4">No assignment history yet.</td></tr>';
  }

  function renderAssignmentKpis() {
    var metrics = window.dashboardMetrics || {};
    var assignments = window.realAssignments || [];
    var activeCount = assignments.filter(function (a) { return (a.status || 'Active') === 'Active'; }).length;
    var autoCount = assignments.filter(function (a) { return (a.assignment_type || '').toLowerCase() !== 'manual'; }).length;
    var manualCount = assignments.filter(function (a) { return (a.assignment_type || '').toLowerCase() === 'manual'; }).length;
    var setKpi = function (id, val) {
      var el = document.getElementById(id);
      if (el) el.textContent = String(val);
    };
    setKpi('assign-kpi-active', activeCount || metrics.assigned_leads || 0);
    setKpi('assign-kpi-auto', autoCount);
    setKpi('assign-kpi-manual', manualCount);
    setKpi('assign-kpi-target', metrics.assigned_leads != null ? metrics.assigned_leads : activeCount);
  }

  function renderFollowupKpis() {
    var metrics = window.dashboardMetrics || {};
    var followups = window.realFollowUps || [];
    var pending = followups.filter(function (f) {
      return ['Pending', 'Scheduled', 'Open'].indexOf(f.status || '') >= 0;
    }).length;
    var completed = followups.filter(function (f) {
      return ['Completed', 'Closed'].indexOf(f.status || '') >= 0;
    }).length;
    var setKpi = function (id, val) {
      var el = document.getElementById(id);
      if (el) el.textContent = String(val);
    };
    setKpi('fu-kpi-due-today', metrics.followups_due_today != null ? metrics.followups_due_today : 0);
    setKpi('fu-kpi-pending', pending);
    setKpi('fu-kpi-overdue', metrics.overdue_followups != null ? metrics.overdue_followups : 0);
    setKpi('fu-kpi-completed', completed);
  }

  function renderFollowupCalendarFromData() {
    var container = document.getElementById('followup-calendar');
    if (!container) return;
    var followups = window.realFollowUps || [];
    var now = new Date();
    var year = now.getFullYear();
    var month = now.getMonth();
    var firstDay = new Date(year, month, 1).getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var eventsByDay = {};
    followups.forEach(function (f) {
      if (!f.scheduled_date) return;
      var d = new Date(f.scheduled_date);
      if (d.getFullYear() === year && d.getMonth() === month) {
        var day = d.getDate();
        eventsByDay[day] = (eventsByDay[day] || 0) + 1;
      }
    });
    var days = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    var monthLabel = now.toLocaleString('en-IN', { month: 'long', year: 'numeric' });
    var html = '<div class="ca-cal-grid mb-2">' + days.map(function (d) {
      return '<div class="text-caption font-medium text-slate-400 py-1">' + d + '</div>';
    }).join('') + '</div><div class="ca-cal-grid">';
    for (var blank = 0; blank < firstDay; blank++) html += '<div></div>';
    for (var i = 1; i <= daysInMonth; i++) {
      var cls = 'ca-cal-day';
      if (i === now.getDate()) cls += ' today';
      if (eventsByDay[i]) cls += ' has-event';
      html += '<div class="' + cls + '" data-day="' + i + '" title="' + (eventsByDay[i] || 0) + ' follow-up(s)">' + i + '</div>';
    }
    html += '</div><p class="text-caption text-slate-500 mt-3 text-center">' + monthLabel + ' — ' + followups.length + ' follow-ups loaded</p>';
    container.innerHTML = html;
  }

  function readAuditFiltersFromForm() {
    return {
      module_name: (document.getElementById('audit-filter-module') || {}).value || '',
      action: (document.getElementById('audit-filter-action') || {}).value || '',
      user: (document.getElementById('audit-filter-user') || {}).value || '',
      date_from: (document.getElementById('audit-filter-from') || {}).value || '',
      date_to: (document.getElementById('audit-filter-to') || {}).value || '',
    };
  }

  function renderAuditLogsTable(logs) {
    var el = document.getElementById('audit-logs-table');
    if (!el) return;
    var items = logs || activityLogsCache || [];
    el.innerHTML = items.length ? items.map(function (log) {
      return '<tr class="ca-table-row">' +
        '<td>' + formatActivityTimestamp(log.timestamp) + '</td>' +
        '<td>' + escapeHtml(log.performed_by || 'System') + '</td>' +
        '<td>' + escapeHtml(activityModuleLabel(log.module_name)) + '</td>' +
        '<td>' + (log.record_id ? escapeHtml(log.record_id) : '—') + '</td>' +
        '<td>' + escapeHtml(log.action) + '</td>' +
        '<td class="text-caption max-w-[8rem] truncate" title="' + escapeHtml(log.before_value) + '">' + escapeHtml(log.before_value || '—') + '</td>' +
        '<td class="text-caption max-w-[8rem] truncate" title="' + escapeHtml(log.after_value) + '">' + escapeHtml(log.after_value || '—') + '</td>' +
        '<td>' + escapeHtml(log.ip_address || '—') + '</td>' +
        '<td class="max-w-md truncate" title="' + escapeHtml(log.description) + '">' + escapeHtml(log.description || '—') + '</td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="9" class="text-center text-slate-500 p-4">No audit records yet.</td></tr>';
  }

  function populateAuditFilterOptions() {
    var moduleSel = document.getElementById('audit-filter-module');
    var actionSel = document.getElementById('audit-filter-action');
    if (!moduleSel || !actionSel) return;
    var modules = activityFilterOptions.modules || [];
    var actions = activityFilterOptions.actions || [];
    var currentModule = moduleSel.value;
    var currentAction = actionSel.value;
    moduleSel.innerHTML = '<option value="">All modules</option>' + modules.map(function (m) {
      return '<option value="' + escapeHtml(m) + '">' + escapeHtml(activityModuleLabel(m)) + '</option>';
    }).join('');
    actionSel.innerHTML = '<option value="">All actions</option>' + actions.map(function (a) {
      return '<option value="' + escapeHtml(a) + '">' + escapeHtml(a) + '</option>';
    }).join('');
    if (currentModule) moduleSel.value = currentModule;
    if (currentAction) actionSel.value = currentAction;
  }

  function initAuditPage() {
    var applyBtn = document.getElementById('audit-filter-apply');
    var clearBtn = document.getElementById('audit-filter-clear');
    if (applyBtn && !applyBtn._auditBound) {
      applyBtn._auditBound = true;
      applyBtn.addEventListener('click', function () {
        if (window.CA_LISTING_SEARCH) {
          CA_LISTING_SEARCH.setState('activity_logs', { page: 1, filters: readAuditFiltersFromForm() });
          reloadListing('activity_logs').then(function () {
            populateAuditFilterOptions();
            renderAuditLogsTable(activityLogsCache);
          });
        }
      });
    }
    if (clearBtn && !clearBtn._auditBound) {
      clearBtn._auditBound = true;
      clearBtn.addEventListener('click', function () {
        ['audit-filter-module', 'audit-filter-action', 'audit-filter-from', 'audit-filter-to', 'audit-filter-user'].forEach(function (id) {
          var el = document.getElementById(id);
          if (el) el.value = '';
        });
        if (window.CA_LISTING_SEARCH) {
          CA_LISTING_SEARCH.clearFilters('activity_logs');
          reloadListing('activity_logs').then(function () {
            populateAuditFilterOptions();
            renderAuditLogsTable(activityLogsCache);
          });
        }
      });
    }
    if (window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.setState('activity_logs', { page: 1, filters: readAuditFiltersFromForm() });
      reloadListing('activity_logs').then(function () {
        populateAuditFilterOptions();
        renderAuditLogsTable(activityLogsCache);
      });
    }
  }

  function formatDateTime(value) {
    if (!value) return '—';
    var d = new Date(value);
    if (isNaN(d.getTime())) return value;
    return d.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function formatDate(value) {
    if (!value) return '—';
    var d = new Date(value);
    if (isNaN(d.getTime())) return value;
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  function followupStatusBadge(status) {
    var map = {
      Pending: 'badge-warning',
      Scheduled: 'badge-brand',
      Overdue: 'badge-danger',
      Completed: 'badge-success',
      Closed: 'bg-slate-100 text-slate-600',
    };
    return '<span class="badge ' + (map[status] || 'badge-brand') + '">' + (status || 'Pending');
  }

  function renderFollowupsTable(pageFollowups) {
    var el = document.getElementById('followups-data-table');
    if (!el) return;
    if (pageFollowups === undefined && window.CA_LISTING_SEARCH) {
      reloadListing('follow_ups');
      return;
    }
    var followups = pageFollowups || window.realFollowUps || [];
    el.innerHTML = followups.length ? followups.map(function (f) {
      var rowClass = 'ca-table-row' + (f.status === 'Overdue' ? ' followup-row-overdue' : '');
      var rescheduled = f.is_rescheduled ? ' <span class="badge badge-brand">Rescheduled</span>' : '';
      return '<tr class="' + rowClass + '">' +
        '<td>' + (f.followup_type || '—') + rescheduled + '</td>' +
        '<td>' + (f.firm_name || '—') + '</td>' +
        '<td>' + (f.executive || f.employee_name || '—') + '</td>' +
        '<td>' + (f.remarks || '—') + '</td>' +
        '<td>' + formatDateTime(f.scheduled_date) + '</td>' +
        '<td>' + formatDate(f.next_followup_date) + '</td>' +
        '<td>' + followupStatusBadge(f.status) + '</td>' +
        '<td class="text-right whitespace-nowrap">' +
          '<button type="button" class="btn-secondary btn-sm" data-followup-edit="' + f.followup_id + '">Edit</button> ' +
          (['Completed', 'Closed'].indexOf(f.status || '') < 0
            ? '<button type="button" class="btn-primary btn-sm" data-followup-outcome="' + f.followup_id + '" data-ca-id="' + f.ca_id + '">Log Outcome</button> '
            : '') +
          '<button type="button" class="btn-secondary btn-sm" data-followup-delete="' + f.followup_id + '">Delete</button>' +
        '</td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="8" class="text-center text-slate-500 p-4">No follow-ups yet.</td></tr>';
    bindFollowupRowActions(el);
  }

  function bindFollowupRowActions(container) {
    if (!container) return;
    container.querySelectorAll('[data-followup-edit]').forEach(function (btn) {
      if (btn._fuEditBound) return;
      btn._fuEditBound = true;
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        openFollowupFormForEdit(btn.getAttribute('data-followup-edit'));
      });
    });
    container.querySelectorAll('[data-followup-outcome]').forEach(function (btn) {
      if (btn._fuOutcomeBound) return;
      btn._fuOutcomeBound = true;
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        openCallOutcomeModal(btn.getAttribute('data-followup-outcome'), btn.getAttribute('data-ca-id'));
      });
    });
    container.querySelectorAll('[data-followup-complete]').forEach(function (btn) {
      if (btn._fuCompleteBound) return;
      btn._fuCompleteBound = true;
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var id = btn.getAttribute('data-followup-complete');
        apiFetch('/follow-ups/' + encodeURIComponent(id), {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ status: 'Completed' }),
        })
          .then(function () {
            toast('Follow-up marked completed', 'success');
            realFollowUpsLoaded = false;
            refreshAll();
          })
          .catch(function (err) {
            toast(err.message || 'Unable to complete follow-up', 'error');
          });
      });
    });
    container.querySelectorAll('[data-followup-delete]').forEach(function (btn) {
      if (btn._fuDelBound) return;
      btn._fuDelBound = true;
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var id = btn.getAttribute('data-followup-delete');
        if (!window.confirm('Delete this follow-up?')) return;
        apiFetch('/follow-ups/' + encodeURIComponent(id), { method: 'DELETE' })
          .then(function () {
            toast('Follow-up deleted', 'success');
            realFollowUpsLoaded = false;
            refreshAll();
          })
          .catch(function (err) {
            toast(err.message || 'Unable to delete follow-up', 'error');
          });
      });
    });
  }

  function openCallOutcomeModal(followupId, caId) {
    var modal = document.getElementById('modal-call-outcome');
    var form = document.getElementById('form-call-outcome');
    if (!modal || !form) return;
    form.reset();
    var idEl = document.getElementById('call-outcome-followup-id');
    var caEl = document.getElementById('call-outcome-ca-id');
    if (idEl) idEl.value = followupId || '';
    if (caEl) caEl.value = caId || '';
    openModal(modal);
    icons();
  }

  function openFollowupFormForEdit(followupId) {
    var followup = (window.realFollowUps || []).find(function (f) {
      return String(f.followup_id) === String(followupId);
    });
    if (!followup) {
      toast('Follow-up not found', 'error');
      return;
    }
    window._editingFollowUpId = followupId;
    var modal = document.getElementById('modal-followup');
    var form = document.getElementById('form-followup');
    if (!modal || !form) return;
    ensureFormSelectData(function () {
      populateSelects();
      if (form.elements.ca_id) form.elements.ca_id.value = followup.ca_id || '';
      if (form.elements.followup_type) form.elements.followup_type.value = followup.followup_type || 'Call Status';
      if (form.elements.remarks) form.elements.remarks.value = followup.remarks || '';
      if (form.elements.scheduled_date && followup.scheduled_date) {
        form.elements.scheduled_date.value = String(followup.scheduled_date).slice(0, 16);
      }
      if (form.elements.next_followup_date && followup.next_followup_date) {
        form.elements.next_followup_date.value = String(followup.next_followup_date).slice(0, 10);
      }
      if (form.elements.priority) form.elements.priority.value = followup.priority || 'Normal';
      window._followupOriginalScheduled = followup.scheduled_date ? String(followup.scheduled_date).slice(0, 16) : '';
      openModal(modal);
    });
  }

  function renderCaMasterTable(pageLeads) {
    var el = document.getElementById('ca-master-data-table');
    if (!el) return;
    if (pageLeads === undefined && window.CA_LISTING_SEARCH) {
      var cached = window._listingLeadsPage || window.realLeads || [];
      if (cached.length) {
        renderCaMasterTable(cached);
      } else {
        el.innerHTML = emptyTableRow(18, 'Loading firms…');
      }
      reloadListing('ca_masters').catch(function () {
        if (window.realLeads && window.realLeads.length) {
          renderCaMasterTable(window.realLeads);
        }
      });
      return;
    }
    var leads = pageLeads || window._listingLeadsPage || window.realLeads || [];
    el.innerHTML = leads.length ? leads.map(function (l) {
      var data = JSON.stringify(CAData.leadToRowData(l)).replace(/'/g, '&#39;');
      return '<tr class="ca-table-row" data-lead-id="' + l.ca_id + '" data-row=\'' + data + '\'>' +
        '<td class="font-medium">' + l.firm_name + '</td>' +
        '<td>' + l.ca_name + '</td>' +
        '<td>' + renderPhoneCell(l.mobile_no) + '</td>' +
        '<td>' + renderPhoneCell(l.alternate_mobile_no) + '</td>' +
        '<td>' + l.email_id + '</td>' +
        '<td>' + l.gst_no + '</td>' +
        '<td>' + l.state + '</td>' +
        '<td>' + l.city + '</td>' +
        '<td>' + l.team_size + '</td>' +
        '<td>' + l.existing_software + '</td>' +
        '<td>' + l.website + '</td>' +
        '<td>' + stars(l.rating) + '</td>' +
        '<td>' + (l.is_newly_established ? '<span class="badge bg-amber-50 text-amber-700">Yes</span>' : '<span class="badge-brand">No</span>') + '</td>' +
        '<td>' + statusBadge(l.status) + '</td>' +
        '<td>' + l.source + '</td>' +
        '<td>' + formatRelativeDate(l.created_at) + '</td>' +
        '<td>' + l.updated + '</td>' +
      '</tr>';
    }).join('') : emptyTableRow(17, 'No firms yet. Click Add Firm to create one.');

    var newEl = document.getElementById('ca-master-new-data-table');
    if (newEl) {
      var newLeads = leads.filter(function (l) { return l.is_newly_established; });
      newEl.innerHTML = newLeads.length ? newLeads.map(function (l) {
        var data = JSON.stringify(CAData.leadToRowData(l)).replace(/'/g, '&#39;');
        return '<tr class="ca-table-row" data-lead-id="' + l.ca_id + '" data-row=\'' + data + '\'>' +
          '<td>' +  CAData.shortId(l.ca_id) + '</td>' +
          '<td class="font-medium">' + l.firm_name + '</td>' +
          '<td>' + l.ca_name + '</td>' +
          '<td>' + renderPhoneCell(l.mobile_no) + '</td>' +
          '<td>' + renderPhoneCell(l.alternate_mobile_no) + '</td>' +
          '<td>' + l.email_id + '</td>' +
          '<td>' + l.gst_no + '</td>' +
          '<td>' + l.state + '</td>' +
          '<td>' + l.city + '</td>' +
          '<td>' + l.team_size + '</td>' +
          '<td>' + l.existing_software + '</td>' +
          '<td>' + l.website + '</td>' +
          '<td>' + stars(l.rating) + '</td>' +
          '<td><span class="badge bg-amber-50 text-amber-700">Yes</span></td>' +
          '<td>' + statusBadge(l.status) + '</td>' +
          '<td>' + l.source + '</td>' +
          '<td>' + formatRelativeDate(l.created_at) + '</td>' +
          '<td>' + l.updated + '</td>' +
        '</tr>';
      }).join('') : emptyTableRow(18, 'No newly established firms yet.');
      bindLeadRows(newEl);
    }

    bindLeadRows(el);
  }

  function renderLeaderboard() {
    var el = document.getElementById('leaderboard');
    if (!el) return;
    var execs = getDashboardExecutives().slice().sort(function (a, b) {
      return (b.achieved_leads || 0) - (a.achieved_leads || 0);
    });
    if (!execs.length) {
      el.innerHTML = '<h3 class="text-card-heading mb-4 flex items-center gap-2"><i data-lucide="trophy" class="h-5 w-5 text-amber-500"></i> Team Leaderboard</h3>' +
        '<p class="text-caption text-slate-400">No active executives yet.</p>';
      icons();
      return;
    }
    var badges = ['trophy', 'medal', 'award'];
    el.innerHTML = '<h3 class="text-card-heading mb-4 flex items-center gap-2"><i data-lucide="trophy" class="h-5 w-5 text-amber-500"></i> Team Leaderboard</h3>' +
      execs.map(function (e, i) {
        return '<div class="flex items-center gap-4 p-3 rounded-xl hover:bg-slate-50 cursor-pointer team-row" data-employee-id="' + e.employee_id + '">' +
          '<div class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-50 text-brand"><i data-lucide="' + (badges[i] || 'user') + '" class="h-4 w-4"></i></div>' +
          '<div class="flex-1"><p class="font-semibold">' + e.name + '</p><p class="text-caption text-slate-500">' + (e.city || '—') + ' · ' + (e.achieved_leads || 0) + ' leads</p></div>' +
          '<div class="text-right"><p class="font-bold">' + (e.revenue || '—') + '</p><p class="text-caption text-emerald-600">' + (e.conversion || '—') + '</p></div></div>';
      }).join('');
    icons();
  }

  function renderKanbanFromData() {
    var container = document.getElementById('kanban-board');
    if (!container) return;
    var stages = [
      { name: 'New Lead', key: 'New Lead', color: 'bg-slate-400' },
      { name: 'Details Shared', key: 'Details Shared', color: 'bg-blue-400' },
      { name: 'Demo Scheduled', key: 'Demo Scheduled', color: 'bg-brand' },
      { name: 'Demo Completed', key: 'Demo Completed', color: 'bg-indigo-400' },
      { name: 'Negotiation', key: 'Negotiation', color: 'bg-amber-400' },
      { name: 'Won', key: 'Won', color: 'bg-emerald-500' },
      { name: 'Lost', key: 'Lost', color: 'bg-red-400' },
    ];
    var filtered = getRealLeadsFiltered();
    container.innerHTML = stages.map(function (col) {
      var items = filtered.filter(function (l) { return l.stage === col.key; });
      return '<div class="kanban-column" data-stage="' + col.key + '"><div class="flex items-center justify-between mb-3">' +
        '<div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full ' + col.color + '"></span>' +
        '<h4 class="text-card-heading">' + col.name + '</h4></div><span class="badge bg-white text-slate-600">' + items.length + '</span></div>' +
        '<div class="kanban-column-cards min-h-[80px]">' +
        items.map(function (l) {
          var hot = l.status === 'Hot' ? ' kanban-card-hot' : '';
          return '<div class="kanban-card mb-2' + hot + '" draggable="true" data-lead-id="' + l.ca_id + '">' +
            '<div class="flex justify-between gap-2"><p class="text-body font-medium">' + l.firm_name + '</p>' +
            (l.status === 'Hot' ? '<span class="badge bg-amber-50 text-amber-700 text-xs">Hot</span>' : '') + '</div>' +
            '<p class="text-caption text-slate-400">' + l.city + ' · ' + l.executive + '</p></div>';
        }).join('') + '</div></div>';
    }).join('');
    container.querySelectorAll('.kanban-card').forEach(function (card) {
      card.addEventListener('click', function () {
        selectLead(card.dataset.leadId, true);
      });
      card.addEventListener('dragstart', function (e) {
        e.dataTransfer.setData('text/plain', card.dataset.leadId);
        card.classList.add('opacity-60');
      });
      card.addEventListener('dragend', function () {
        card.classList.remove('opacity-60');
      });
    });
    container.querySelectorAll('.kanban-column').forEach(function (col) {
      col.addEventListener('dragover', function (e) {
        e.preventDefault();
        col.classList.add('ring-2', 'ring-brand/30');
      });
      col.addEventListener('dragleave', function () {
        col.classList.remove('ring-2', 'ring-brand/30');
      });
      col.addEventListener('drop', function (e) {
        e.preventDefault();
        col.classList.remove('ring-2', 'ring-brand/30');
        var leadId = e.dataTransfer.getData('text/plain');
        var stage = col.dataset.stage;
        if (!leadId || !stage) return;
        var status = mapStageToStatus(stage);
        updateLeadStatus(leadId, status)
          .then(function () {
            toast('Lead moved to ' + stage, 'success');
            refreshAll();
          })
          .catch(function (err) {
            toast(err.message || 'Unable to update lead stage', 'error');
          });
      });
    });
    var selId = CAData.getSelectedLeadId();
    if (selId) highlightLeadSelection(selId);
  }

  function populateSelects() {
    var leadSel = document.getElementById('form-lead-select');
    var execSel = document.getElementById('form-executive-select');
    var assignLead = document.getElementById('form-assign-lead-select');
    var assignExec = document.getElementById('form-assign-executive');
    var fuLead = document.getElementById('form-fu-lead');
    var consentLead = document.getElementById('form-consent-lead');
    var dndLead = document.getElementById('form-dnd-lead');
    var leads = window._selectLeadsLoaded ? (window._selectLeads || []) : (realLeadsLoaded ? (window.realLeads || []) : []);
    var executives = window._selectEmployeesLoaded ? (window._selectEmployees || []) : (realEmployeesLoaded ? (window.realEmployees || []) : []);
    var leadOpts = buildLeadOptionsHtml(leads);
    var execOpts = buildExecOptionsHtml(executives);
    [leadSel, assignLead, fuLead, consentLead, dndLead].forEach(function (s) {
      if (s) s.innerHTML = leadOpts;
    });
    [execSel, assignExec].forEach(function (s) {
      if (s) s.innerHTML = execOpts;
    });
  }

  function bindLeadRows(container) {
    if (!container) return;
    container.querySelectorAll('.ca-table-row[data-lead-id]').forEach(function (row) {
      row.addEventListener('click', function (e) {
        if (e.target.closest('.lead-quick-actions-cell')) return;
        selectLead(row.dataset.leadId, true);
      });
    });
  }

  function setLeadFormMode(mode) {
    var titleText = document.getElementById('add-lead-title-text');
    var titleIcon = document.getElementById('add-lead-title-icon');
    var submitBtn = document.getElementById('add-lead-submit-btn');
    var isEdit = mode === 'edit';

    if (titleText) titleText.textContent = isEdit ? 'Edit Lead' : 'Add Lead';
    if (titleIcon) titleIcon.setAttribute('data-lucide', isEdit ? 'edit-3' : 'user-plus');
    if (submitBtn) submitBtn.innerHTML = '<i data-lucide="save" class="h-4 w-4"></i> ' + (isEdit ? 'Update Lead' : 'Save Lead');
    if (!isEdit) window._editingLeadId = '';
  }

  function resetLeadForm() {
    var form = document.getElementById('form-add-lead');
    if (!form) return;
    form.reset();
    var caIdField = document.getElementById('form-lead-ca-id');
    if (caIdField) caIdField.value = '';
    if (form.elements.team_size) form.elements.team_size.value = '8';
    if (form.elements.rating) form.elements.rating.value = '3';
    setLeadFormMode('add');
    if (window.CA_STATE_CITY) {
      window.CA_STATE_CITY.resetFormLocations(form);
    }
    icons();
  }

  function fillLeadForm(lead) {
    var form = document.getElementById('form-add-lead');
    if (!form || !lead) return;

    form.elements.firm_name.value = lead.firm_name || '';
    form.elements.ca_name.value = lead.ca_name || '';
    form.elements.mobile_no.value = lead.mobile_no && lead.mobile_no !== '—' ? lead.mobile_no : '';
    if (form.elements.alternate_mobile_no) {
      form.elements.alternate_mobile_no.value = lead.alternate_mobile_no && lead.alternate_mobile_no !== '—' ? lead.alternate_mobile_no : '';
    }
    form.elements.email_id.value = lead.email_id || '';
    form.elements.gst_no.value = lead.gst_no && lead.gst_no !== '—' ? lead.gst_no : '';
    if (window.CA_STATE_CITY) {
      window.CA_STATE_CITY.setLeadLocationValues(lead.state_id || lead.state, lead.city_id || lead.city);
    } else {
      if (form.elements.state_id) setSelectValueIfValid(form.elements.state_id, lead.state_id || lead.state);
      if (form.elements.city_id) setSelectValueIfValid(form.elements.city_id, lead.city_id || lead.city);
    }
    form.elements.team_size.value = lead.team_size || 8;
    form.elements.existing_software.value = lead.existing_software || 'Tally';
    form.elements.website.value = lead.website && lead.website !== '—' ? lead.website : '';
    form.elements.rating.value = String(lead.rating || 3);
    form.elements.is_newly_established.value = lead.is_newly_established ? 'yes' : 'no';
    if (form.elements.source_id) setSelectValueIfValid(form.elements.source_id, lead.source_id || lead.source);
    form.elements.status.value = lead.status || 'New';
    if (form.elements.executive_id) form.elements.executive_id.value = lead.executive_id || '';

    var caIdField = document.getElementById('form-lead-ca-id');
    if (caIdField) caIdField.value = lead.ca_id;
    window._editingLeadId = lead.ca_id;
    setLeadFormMode('edit');
  }

  function openLeadFormForAdd() {
    resetLeadForm();
    populateSelects();
    if (window.CA_STATE_CITY) {
      window.CA_STATE_CITY.prepareForm('form-add-lead');
    }
  }

  function openLeadFormForEdit(leadId) {
    var lead = getLeadRecord(leadId);
    if (!lead) {
      toast('Select a lead to edit', 'warning');
      return false;
    }
    populateSelects();
    fillLeadForm(lead);
    return true;
  }

  function setCampaignSectionVisible(el, visible) {
    if (!el) return;
    el.classList.toggle('hidden', !visible);
  }

  function configureCampaignModal(channel) {
    channel = channel || 'whatsapp';
    var titleMap = { whatsapp: 'New WhatsApp Campaign', sms: 'New SMS Campaign', email: 'New Email Campaign' };
    var channelInput = document.getElementById('form-campaign-channel');
    var typeSelect = document.getElementById('form-campaign-type');
    var whatsappFields = document.getElementById('form-campaign-whatsapp-fields');
    var emailFields = document.getElementById('form-campaign-email-fields');
    var smsFields = document.getElementById('form-campaign-sms-fields');
    var audienceFields = document.getElementById('form-campaign-audience-fields');
    var legacyFields = document.getElementById('form-campaign-legacy-fields');
    var messageTemplate = document.getElementById('form-campaign-message-template');
    var smsMessageTemplate = document.getElementById('form-campaign-sms-message-template');
    var bodyTemplate = document.getElementById('form-campaign-body-template');
    var emailSubject = document.getElementById('form-campaign-email-subject');
    var senderId = document.getElementById('form-campaign-sender-id');
    if (channelInput) channelInput.value = channel;
    var titleLabel = document.getElementById('add-campaign-title-label');
    if (titleLabel) titleLabel.textContent = titleMap[channel] || 'New Campaign';
    if (typeSelect) {
      var types = CAMPAIGN_TYPE_OPTIONS[channel] || [];
      typeSelect.innerHTML = types.map(function (t) {
        return '<option value="' + t + '">' + t + '</option>';
      }).join('');
    }
    setCampaignSectionVisible(whatsappFields, channel === 'whatsapp');
    setCampaignSectionVisible(emailFields, channel === 'email');
    setCampaignSectionVisible(smsFields, channel === 'sms');
    setCampaignSectionVisible(audienceFields, channel === 'whatsapp' || channel === 'email' || channel === 'sms');
    setCampaignSectionVisible(legacyFields, false);
    if (messageTemplate) messageTemplate.required = channel === 'whatsapp';
    if (smsMessageTemplate) smsMessageTemplate.required = channel === 'sms';
    if (bodyTemplate) bodyTemplate.required = channel === 'email';
    if (emailSubject) emailSubject.required = channel === 'email';
    var scheduledAt = document.getElementById('form-campaign-scheduled-at');
    if (scheduledAt) scheduledAt.disabled = channel === 'sms';
    var createBtn = document.getElementById('btn-create-campaign');
    var smsPreviewMsg = document.getElementById('btn-sms-preview-message');
    var smsSaveDraft = document.getElementById('btn-sms-save-draft');
    var smsPreviewPayload = document.getElementById('btn-sms-preview-payload');
    var smsSendDisabled = document.getElementById('btn-sms-send-disabled');
    var isSms = channel === 'sms';
    if (createBtn) createBtn.classList.toggle('hidden', isSms);
    if (smsPreviewMsg) smsPreviewMsg.classList.toggle('hidden', !isSms);
    if (smsSaveDraft) smsSaveDraft.classList.toggle('hidden', !isSms);
    if (smsPreviewPayload) smsPreviewPayload.classList.toggle('hidden', !isSms);
    if (smsSendDisabled) smsSendDisabled.classList.toggle('hidden', !isSms);
    if (channel === 'whatsapp' || channel === 'email' || channel === 'sms') {
      toggleCampaignAudienceFields();
      populateCampaignAudienceSelects(channel);
      if (channel === 'sms') updateSmsCampaignEstimates();
    }
  }

  function toggleCampaignAudienceFields() {
    var mode = document.getElementById('form-campaign-audience-mode')?.value || 'all_leads';
    var map = {
      selected_leads: 'form-campaign-audience-selected',
      city: 'form-campaign-audience-city',
      state: 'form-campaign-audience-state',
      source: 'form-campaign-audience-source',
      rating: 'form-campaign-audience-rating',
      team_size: 'form-campaign-audience-team-size',
      existing_software: 'form-campaign-audience-existing-software',
    };
    Object.keys(map).forEach(function (key) {
      var el = document.getElementById(map[key]);
      if (el) el.classList.toggle('hidden', mode !== key);
    });
  }

  function populateCampaignAudienceSelects(channel) {
    channel = channel || document.getElementById('form-campaign-channel')?.value || 'whatsapp';
    ensureFormSelectData(function () {
      var leads = window.realLeads || [];
      var sources = window.realSourceLeads || [];
      var leadSel = document.getElementById('form-campaign-ca-ids');
      if (leadSel) {
        leadSel.innerHTML = leads.map(function (l) {
          var contact = channel === 'email' ? (l.email_id || '—') : (l.mobile_no || '—');
          return '<option value="' + l.ca_id + '">' + l.firm_name + ' · ' + contact + '</option>';
        }).join('');
      }
      var sourceSel = document.getElementById('form-campaign-source-id');
      if (sourceSel) sourceSel.innerHTML = buildMasterSelectOptions(sources, 'source_id', 'source_name');
      if (window.CA_STATE_CITY) {
        window.CA_STATE_CITY.prepareForm('form-add-campaign');
      }
    });
  }

  function buildCampaignAudiencePayload(data) {
    var selectedIds = Array.from(document.getElementById('form-campaign-ca-ids')?.selectedOptions || [])
      .map(function (opt) { return parseInt(opt.value, 10); })
      .filter(Boolean);
    var payload = {
      campaign_name: data.name,
      campaign_type: data.campaign_type,
      audience_mode: data.audience_mode || 'all_leads',
      scheduled_at: data.scheduled_at || null,
    };
    if (payload.audience_mode === 'selected_leads') payload.ca_ids = selectedIds;
    if (payload.audience_mode === 'city') payload.city_id = parseInt(data.city_id, 10);
    if (payload.audience_mode === 'state') payload.state_id = parseInt(data.state_id, 10);
    if (payload.audience_mode === 'source') payload.source_id = parseInt(data.source_id, 10);
    if (payload.audience_mode === 'rating') payload.rating = parseInt(data.rating, 10);
    if (payload.audience_mode === 'team_size') payload.team_size = parseInt(data.team_size, 10);
    if (payload.audience_mode === 'existing_software') payload.existing_software = data.existing_software;
    return payload;
  }

  function loadWhatsAppCampaignsFromDatabase(callback) {
    apiFetch('/whatsapp-campaigns')
      .then(function (body) {
        window.realWhatsAppCampaigns = unwrapList(body);
        whatsappCampaignsLoaded = true;
        if (callback) callback(window.realWhatsAppCampaigns);
      })
      .catch(function () {
        window.realWhatsAppCampaigns = [];
        whatsappCampaignsLoaded = true;
        if (callback) callback([]);
      });
  }

  function loadWaMessageLogsFromDatabase(callback, campaignId) {
    var params = [];
    if (campaignId) params.push('campaign_id=' + encodeURIComponent(campaignId));
    if (window._commLogStatusFilter) {
      params.push('message_status=' + encodeURIComponent(window._commLogStatusFilter));
    }
    var url = '/wa-message-logs' + (params.length ? '?' + params.join('&') : '');
    apiFetch(url)
      .then(function (body) {
        window.realWaMessageLogs = unwrapList(body);
        waMessageLogsLoaded = true;
        if (callback) callback(window.realWaMessageLogs);
      })
      .catch(function () {
        window.realWaMessageLogs = [];
        waMessageLogsLoaded = true;
        if (callback) callback([]);
      });
  }

  function waStatusBadge(status) {
    var map = {
      Delivered: 'badge-success',
      Failed: 'badge-danger',
      Queued: 'badge-warning',
      Processing: 'badge-brand',
      Scheduled: 'bg-blue-50 text-blue-700',
      Completed: 'badge-success',
      Draft: 'bg-slate-100 text-slate-600',
    };
    return '<span class="badge ' + (map[status] || 'badge-brand') + '">' + escapeHtml(status || '—');
  }

  function campaignDeleteButton(channel, id, status) {
    if (status === 'Processing') return '';
    return '<button type="button" class="btn-secondary btn-sm" data-delete-' + channel + '-campaign="' + id + '">Delete</button>';
  }

  function whatsappCampaignCardHtml(c) {
    var sent = c.total_messages || 0;
    var delivered = c.delivered_count || 0;
    var pct = sent ? Math.round((delivered / sent) * 100) : 0;
    var launchBtn = c.status === 'Scheduled'
      ? '<button type="button" class="btn-primary btn-sm inline-flex items-center justify-center gap-2" data-launch-whatsapp-campaign="' + c.id + '"><i data-lucide="rocket" class="h-4 w-4"></i> Process</button>'
      : '';
    var actions = '<div class="flex flex-wrap gap-2 mt-3">' + launchBtn + campaignDeleteButton('whatsapp', c.id, c.status) + '</div>';
    return '<div class="card-interactive p-4 campaign-card" data-whatsapp-campaign-id="' + c.id + '">' +
      '<div class="flex justify-between mb-2"><p class="text-card-heading">' + escapeHtml(c.campaign_name) + '</p>' + waStatusBadge(c.status) + '</div>' +
      '<p class="text-caption text-slate-500">Type: ' + escapeHtml(c.campaign_type) + '</p>' +
      '<p class="text-caption text-slate-500 mt-1">Audience: ' + escapeHtml(c.audience_label || c.audience_mode) + ' · By: ' + escapeHtml(c.performed_by || 'System') + '</p>' +
      '<p class="text-caption text-slate-500 mt-1">Messages: ' + sent + ' · Delivered ' + delivered + ' · Failed ' + (c.failed_count || 0) + '</p>' +
      (sent ? '<div class="mt-3 h-2 rounded-full bg-slate-100"><div class="h-full rounded-full bg-green-500" style="width:' + pct + '%"></div></div>' +
        '<p class="text-caption text-slate-400 mt-1">' + pct + '% delivery rate</p>' + actions : actions) +
      '</div>';
  }

  function renderWhatsAppCampaignGrid() {
    var el = document.getElementById('campaigns-grid-whatsapp');
    if (!el) return;
    var list = window.realWhatsAppCampaigns || [];
    el.innerHTML = list.length
      ? list.map(whatsappCampaignCardHtml).join('')
      : '<p class="text-body text-slate-500 col-span-full p-4">No campaigns yet. Click <strong>New Campaign</strong> to create one.</p>';
    icons();
  }

  function renderWhatsAppKpis(metrics) {
    metrics = metrics || window.dashboardMetrics || {};
    setText('wa-kpi-campaigns', formatCampaignNumber(metrics.whatsapp_campaigns_total || 0));
    setText('wa-kpi-messages', formatCampaignNumber(metrics.whatsapp_messages_total || 0));
    setText('wa-kpi-delivered', formatCampaignNumber(metrics.whatsapp_delivered || 0));
    setText('wa-kpi-failed', formatCampaignNumber(metrics.whatsapp_failed || 0));
    setText('wa-kpi-queued', formatCampaignNumber(metrics.whatsapp_queued || 0));
  }

  function renderWaMessageLogsTable(logs) {
    var tbody = document.getElementById('wa-message-logs-table');
    if (!tbody) return;
    logs = logs || window.realWaMessageLogs || [];
    if (!logs.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-slate-400 py-8">No message logs yet.</td></tr>';
      return;
    }
    tbody.innerHTML = logs.map(function (log) {
      return '<tr>' +
        '<td>' + escapeHtml(log.campaign_name || log.campaign_id || '—') + '</td>' +
        '<td>' + escapeHtml(log.firm_name || log.lead_name || '—') + '</td>' +
        '<td>' + escapeHtml(log.mobile_no || '—') + '</td>' +
        '<td>' + waStatusBadge(log.message_status) + '</td>' +
        '<td class="max-w-xs truncate" title="' + escapeHtml(log.message) + '">' + escapeHtml(log.message || '—') + '</td>' +
        '<td class="whitespace-nowrap">' + escapeHtml(formatActivityTimestamp(log.queued_at)) + '</td>' +
        '<td class="whitespace-nowrap">' + escapeHtml(formatActivityTimestamp(log.delivered_at)) + '</td>' +
      '</tr>';
    }).join('');
  }

  function refreshWhatsAppPage() {
    loadDashboardMetricsFromDatabase(function (metrics) {
      renderWhatsAppKpis(metrics);
    });
    loadWhatsAppCampaignsFromDatabase(function () {
      renderWhatsAppCampaignGrid();
    });
    loadWaMessageLogsFromDatabase(function (logs) {
      renderWaMessageLogsTable(logs);
      if (window._commLogStatusFilter) window._commLogStatusFilter = '';
    });
  }

  function loadEmailCampaignsFromDatabase(callback) {
    apiFetch('/email-campaigns')
      .then(function (body) {
        window.realEmailCampaigns = unwrapList(body);
        emailCampaignsLoaded = true;
        if (callback) callback(window.realEmailCampaigns);
      })
      .catch(function () {
        window.realEmailCampaigns = [];
        emailCampaignsLoaded = true;
        if (callback) callback([]);
      });
  }

  function loadEmailLogsFromDatabase(callback, campaignId) {
    var url = '/email-logs' + (campaignId ? '?campaign_id=' + encodeURIComponent(campaignId) : '');
    apiFetch(url)
      .then(function (body) {
        window.realEmailLogs = unwrapList(body);
        emailLogsLoaded = true;
        if (callback) callback(window.realEmailLogs);
      })
      .catch(function () {
        window.realEmailLogs = [];
        emailLogsLoaded = true;
        if (callback) callback([]);
      });
  }

  function emailCampaignCardHtml(c) {
    var sent = c.total_emails || 0;
    var delivered = c.delivered_count || 0;
    var pct = sent ? Math.round((delivered / sent) * 100) : 0;
    var launchBtn = c.status === 'Scheduled'
      ? '<button type="button" class="btn-primary btn-sm inline-flex items-center justify-center gap-2" data-launch-email-campaign="' + c.id + '"><i data-lucide="rocket" class="h-4 w-4"></i> Process</button>'
      : '';
    var actions = '<div class="flex flex-wrap gap-2 mt-3">' + launchBtn + campaignDeleteButton('email', c.id, c.status) + '</div>';
    return '<div class="card-interactive p-5 campaign-card" data-email-campaign-id="' + c.id + '">' +
      '<i data-lucide="send" class="h-8 w-8 text-brand mb-3"></i>' +
      '<div class="flex justify-between gap-2 mb-1"><p class="text-card-heading">' + escapeHtml(c.campaign_name) + '</p>' + waStatusBadge(c.status) + '</div>' +
      '<p class="text-caption text-slate-500 mt-1">Subject: ' + escapeHtml(c.subject || '—') + '</p>' +
      '<p class="text-caption text-slate-500 mt-1">Type: ' + escapeHtml(c.campaign_type) + ' · Audience: ' + escapeHtml(c.audience_label || c.audience_mode) + '</p>' +
      '<p class="text-caption text-slate-500 mt-1">Emails: ' + sent + ' · Delivered ' + delivered + ' · Failed ' + (c.failed_count || 0) + '</p>' +
      (sent ? '<div class="mt-3 h-2 rounded-full bg-slate-100"><div class="h-full rounded-full bg-green-500" style="width:' + pct + '%"></div></div>' +
        '<p class="text-caption text-slate-400 mt-1">' + pct + '% delivery rate</p>' + actions : actions) +
      '</div>';
  }

  function renderEmailCampaignGrid() {
    var el = document.getElementById('campaigns-grid-email');
    if (!el) return;
    var list = window.realEmailCampaigns || [];
    el.innerHTML = list.length
      ? list.map(emailCampaignCardHtml).join('')
      : '<p class="text-body text-slate-500 col-span-full p-4">No campaigns yet. Click <strong>New Campaign</strong> to create one.</p>';
    icons();
  }

  function renderEmailKpis(metrics) {
    metrics = metrics || window.dashboardMetrics || {};
    setText('email-kpi-campaigns', formatCampaignNumber(metrics.email_campaigns_total || 0));
    setText('email-kpi-messages', formatCampaignNumber(metrics.email_messages_total || 0));
    setText('email-kpi-delivered', formatCampaignNumber(metrics.email_delivered || 0));
    setText('email-kpi-failed', formatCampaignNumber(metrics.email_failed || 0));
    setText('email-kpi-queued', formatCampaignNumber(metrics.email_queued || 0));
  }

  function renderEmailLogsTable(logs) {
    var tbody = document.getElementById('email-logs-table');
    if (!tbody) return;
    logs = logs || window.realEmailLogs || [];
    if (!logs.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-slate-400 py-8">No email logs yet.</td></tr>';
      return;
    }
    tbody.innerHTML = logs.map(function (log) {
      return '<tr>' +
        '<td>' + escapeHtml(log.campaign_name || log.campaign_id || '—') + '</td>' +
        '<td>' + escapeHtml(log.firm_name || log.lead_name || '—') + '</td>' +
        '<td>' + escapeHtml(log.recipient_email || '—') + '</td>' +
        '<td class="max-w-xs truncate" title="' + escapeHtml(log.subject) + '">' + escapeHtml(log.subject || '—') + '</td>' +
        '<td>' + waStatusBadge(log.email_status) + '</td>' +
        '<td class="max-w-xs truncate" title="' + escapeHtml(log.failed_reason) + '">' + escapeHtml(log.failed_reason || '—') + '</td>' +
      '</tr>';
    }).join('');
  }

  function renderEmailBounceTable(logs) {
    var tbody = document.getElementById('email-bounce-table');
    if (!tbody) return;
    logs = (logs || window.realEmailLogs || []).filter(function (log) {
      return (log.email_status || '').toLowerCase() === 'failed';
    });
    if (!logs.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-slate-400 py-8">No failed or bounced emails yet.</td></tr>';
      return;
    }
    tbody.innerHTML = logs.map(function (log) {
      var bounceType = /hard|invalid|not found/i.test(log.failed_reason || '') ? 'Hard' : 'Soft';
      return '<tr>' +
        '<td>' + escapeHtml(log.recipient_email || '—') + '</td>' +
        '<td>' + (bounceType === 'Hard' ? '<span class="badge-danger">Hard</span>' : '<span class="badge-warning">Soft</span>') + '</td>' +
        '<td class="max-w-xs truncate" title="' + escapeHtml(log.failed_reason) + '">' + escapeHtml(log.failed_reason || '—') + '</td>' +
        '<td class="whitespace-nowrap">' + escapeHtml(formatActivityTimestamp(log.sent_at || log.created_at)) + '</td>' +
        '<td><span class="badge-brand">Logged</span></td>' +
      '</tr>';
    }).join('');
  }

  function refreshEmailPage() {
    loadDashboardMetricsFromDatabase(function (metrics) {
      renderEmailKpis(metrics);
    });
    loadEmailCampaignsFromDatabase(function () {
      renderEmailCampaignGrid();
    });
    loadEmailLogsFromDatabase(function (logs) {
      renderEmailLogsTable(logs);
      renderEmailBounceTable(logs);
    });
  }

  function loadSmsCampaignsFromDatabase(callback) {
    apiFetch('/sms-campaigns')
      .then(function (body) {
        window.realSmsCampaigns = unwrapList(body);
        smsCampaignsLoaded = true;
        if (callback) callback(window.realSmsCampaigns);
      })
      .catch(function () {
        window.realSmsCampaigns = [];
        smsCampaignsLoaded = true;
        if (callback) callback([]);
      });
  }

  function loadSmsLogsFromDatabase(callback, campaignId) {
    var url = '/sms-logs' + (campaignId ? '?campaign_id=' + encodeURIComponent(campaignId) : '');
    apiFetch(url)
      .then(function (body) {
        window.realSmsLogs = unwrapList(body);
        smsLogsLoaded = true;
        if (callback) callback(window.realSmsLogs);
      })
      .catch(function () {
        window.realSmsLogs = [];
        smsLogsLoaded = true;
        if (callback) callback([]);
      });
  }

  function smsCampaignCardHtml(c) {
    var mapped = c.queued_count || 0;
    var total = c.total_sms || 0;
    var previewBtn = '<button type="button" class="btn-secondary btn-sm inline-flex items-center justify-center gap-2" data-preview-sms-payload="' + c.id + '"><i data-lucide="code-2" class="h-4 w-4"></i> Preview Payload</button>';
    var actions = '<div class="flex flex-wrap gap-2 mt-3">' + previewBtn + campaignDeleteButton('sms', c.id, c.status) + '</div>';
    return '<div class="card-interactive p-4 campaign-card" data-sms-campaign-id="' + c.id + '">' +
      '<div class="flex justify-between mb-2"><p class="text-card-heading">' + escapeHtml(c.campaign_name) + '</p>' + waStatusBadge(c.status) + '</div>' +
      '<p class="text-caption text-slate-500">Type: ' + escapeHtml(c.campaign_type) + ' · Audience: ' + escapeHtml(c.audience_label || c.audience_mode) + '</p>' +
      '<p class="text-caption text-slate-500 mt-1">Recipients: ' + total + ' · Mapped: ' + mapped + '</p>' +
      '<p class="text-caption text-slate-400 mt-1">Mapping phase — no SMS sent</p>' + actions +
      '</div>';
  }

  function renderSmsCampaignGrid() {
    var el = document.getElementById('campaigns-grid-sms');
    if (!el) return;
    var list = window.realSmsCampaigns || [];
    el.innerHTML = list.length
      ? list.map(smsCampaignCardHtml).join('')
      : '<p class="text-body text-slate-500 col-span-full p-4">No campaigns yet. Click <strong>New Campaign</strong> to create one.</p>';
    icons();
  }

  function renderSmsKpis(metrics) {
    metrics = metrics || window.dashboardMetrics || {};
    setText('sms-kpi-campaigns', formatCampaignNumber(metrics.sms_campaigns_total || 0));
    setText('sms-kpi-mapped', formatCampaignNumber(metrics.sms_mapped || 0));
    setText('sms-kpi-pending', formatCampaignNumber(metrics.sms_pending_campaigns || 0));
    var modeEl = document.getElementById('sms-kpi-mode');
    if (modeEl) {
      modeEl.textContent = metrics.sms_mode_live ? 'Live' : 'Simulation';
    }
  }

  function renderSmsDashboardWidgets(metrics) {
    metrics = metrics || window.dashboardMetrics || {};
    setText('dash-sms-mapped', formatCampaignNumber(metrics.sms_mapped || 0));
    setText('dash-sms-pending', formatCampaignNumber(metrics.sms_pending_campaigns || 0));
    setText('dash-sms-simulation', metrics.sms_mode_simulation ? 'Yes' : 'No');
    setText('dash-sms-live', metrics.sms_mode_live ? 'Yes' : 'No');
  }

  function showSmsPayloadPreviewPanel(preview) {
    var panel = document.getElementById('sms-payload-preview-panel');
    var jsonEl = document.getElementById('sms-payload-preview-json');
    var metaEl = document.getElementById('sms-payload-preview-meta');
    if (!panel || !jsonEl) return;
    var sample = preview.sample_payload || (preview.payloads && preview.payloads[0] && preview.payloads[0].display_payload) || {};
    jsonEl.textContent = JSON.stringify(sample, null, 2);
    if (metaEl) {
      metaEl.textContent = 'Recipients: ' + (preview.estimated_recipients || 0) +
        ' · Characters: ' + (preview.character_count || 0) +
        ' · SMS segments: ' + (preview.sms_count || 0) +
        (preview.logs_created ? ' · Logs saved: ' + preview.logs_created : '');
    }
    panel.classList.remove('hidden');
  }

  function renderSmsLogsTable(logs) {
    var tbody = document.getElementById('sms-logs-table');
    if (!tbody) return;
    logs = logs || window.realSmsLogs || [];
    if (!logs.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-slate-400 py-8">No SMS logs yet. Preview a campaign payload to create mapped audit logs.</td></tr>';
      return;
    }
    tbody.innerHTML = logs.map(function (log) {
      var provider = log.provider_response || '';
      if (provider.length > 80) provider = provider.slice(0, 80) + '…';
      return '<tr>' +
        '<td>' + escapeHtml(log.campaign_name || log.campaign_id || '—') + '</td>' +
        '<td>' + escapeHtml(log.firm_name || log.lead_name || '—') + '</td>' +
        '<td>' + escapeHtml(log.mobile_no || '—') + '</td>' +
        '<td class="max-w-xs truncate" title="' + escapeHtml(log.message) + '">' + escapeHtml(log.message || '—') + '</td>' +
        '<td>' + waStatusBadge(log.sms_status) + '</td>' +
        '<td class="max-w-xs truncate font-mono text-xs" title="' + escapeHtml(log.provider_response) + '">' + escapeHtml(provider || '—') + '</td>' +
        '<td class="whitespace-nowrap">' + escapeHtml(formatActivityTimestamp(log.created_at)) + '</td>' +
      '</tr>';
    }).join('');
  }

  function refreshSmsPage() {
    loadDashboardMetricsFromDatabase(function (metrics) {
      renderSmsKpis(metrics);
    });
    loadSmsCampaignsFromDatabase(function () {
      renderSmsCampaignGrid();
    });
    loadSmsLogsFromDatabase(function (logs) {
      renderSmsLogsTable(logs);
    });
  }

  function updateSmsCampaignEstimates() {
    var selected = Array.from(document.getElementById('form-campaign-ca-ids')?.selectedOptions || []);
    var mode = document.getElementById('form-campaign-audience-mode')?.value || 'all_leads';
    var count = mode === 'selected_leads' ? selected.length : (window.realLeads || []).length;
    var est = document.getElementById('form-campaign-sms-estimated-recipients');
    if (est) est.value = String(count);
    var template = document.getElementById('form-campaign-sms-message-template')?.value || '';
    var charEl = document.getElementById('form-campaign-sms-char-count');
    var smsEl = document.getElementById('form-campaign-sms-sms-count');
    if (charEl) charEl.value = String(template.length);
    if (smsEl) smsEl.value = String(template.length ? Math.ceil(template.length / 160) : 0);
  }

  var SMS_MOBILE_REQUIRED_MESSAGE = 'Mobile Number is required before sending SMS. Please update the lead first.';

  function normalizeLeadMobileDigits(mobile) {
    var digits = String(mobile || '').replace(/\D/g, '');
    if (digits.length > 10 && digits.indexOf('91') === 0) {
      digits = digits.slice(-10);
    }
    return digits;
  }

  function leadHasValidMobile(lead) {
    return normalizeLeadMobileDigits(lead && lead.mobile_no).length >= 10;
  }

  function findLeadById(leadId) {
    return (window.realLeads || []).find(function (l) {
      return parseInt(l.ca_id, 10) === parseInt(leadId, 10);
    }) || null;
  }

  function validateSmsCampaignPrerequisites(payload, onSuccess) {
    apiFetch('/sms-campaigns/validate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then(function (body) {
      var result = body.data || {};
      if (!result.valid) {
        toast((result.errors && result.errors[0]) || 'SMS campaign validation failed', 'error');
        return;
      }
      if (result.warnings && result.warnings.length) {
        result.warnings.forEach(function (warning) {
          toast(warning, 'warning');
        });
      }
      if (onSuccess) onSuccess(result);
    }).catch(function (error) {
      toast(error.message || 'SMS campaign validation failed', 'error');
    });
  }

  function submitSmsCampaignDraft(callback) {
    var form = document.getElementById('form-add-campaign');
    if (!form) return;
    var fd = new FormData(form);
    var data = Object.fromEntries(fd.entries());
    if (!(data.name && data.name.trim())) {
      toast('Campaign name is required', 'error');
      return;
    }
    if (!(data.campaign_type && data.campaign_type.trim())) {
      toast('Campaign type is required', 'error');
      return;
    }
    if (!(document.getElementById('form-campaign-sms-message-template')?.value.trim())) {
      toast('SMS message template is required', 'error');
      return;
    }
    var smsPayload = buildCampaignAudiencePayload(data);
    smsPayload.message_template = document.getElementById('form-campaign-sms-message-template').value.trim();
    validateSmsCampaignPrerequisites(smsPayload, function () {
      smsPayload.save_as_draft = true;
      apiFetch('/sms-campaigns', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(smsPayload),
      }).then(function (body) {
        var campaign = body.data || {};
        if (callback) callback(campaign);
        else {
          closeModal(document.getElementById('modal-add-campaign'));
          form.reset();
          configureCampaignModal('sms');
          refreshSmsPage();
          toast('SMS campaign draft saved', 'success');
        }
      }).catch(function (error) {
        toast(error.message || 'Failed to save SMS campaign draft', 'error');
      });
    });
  }

  var consentDndChannelFilter = '';

  function mapChannelToDndType(channel) {
    if (channel === 'WhatsApp') return 'WA';
    return channel || '';
  }

  function consentStatusBadge(status) {
    if (status === 'Yes') return '<span class="badge-success">Yes</span>';
    if (status === 'No') return '<span class="badge-danger">No</span>';
    return escapeHtml(status || '—');
  }

  function skipReasonLabel(reason) {
    var labels = {
      no_consent: 'No consent',
      dnd_optout: 'DND / opt-out',
      missing_mobile: 'Missing mobile',
      missing_email: 'Missing email',
      invalid_channel: 'Invalid channel',
    };
    return labels[reason] || reason || '—';
  }

  function renderSafetyKpis(metrics) {
    var map = {
      'safety-kpi-dnd': metrics ? metrics.dnd_contacts : 0,
      'safety-kpi-consent-yes': metrics ? metrics.consent_approved : 0,
      'safety-kpi-consent-no': metrics ? metrics.consent_denied : 0,
      'safety-kpi-skip-dnd': metrics ? metrics.skipped_due_to_dnd : 0,
      'safety-kpi-skip-consent': metrics ? metrics.skipped_due_to_no_consent : 0,
    };
    Object.keys(map).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.textContent = formatCampaignNumber(map[id] || 0);
    });
  }

  function loadConsentRecords(callback) {
    var extra = consentDndChannelFilter ? { consent_type: consentDndChannelFilter } : {};
    apiFetch('/consent-trackings' + listingPageQuery('consent_trackings', extra))
      .then(function (body) {
        window.realConsentRecords = unwrapList(body);
        if (callback) callback(window.realConsentRecords);
      })
      .catch(function () {
        window.realConsentRecords = [];
        if (callback) callback([]);
      });
  }

  function loadDndRecords(callback) {
    var dndType = mapChannelToDndType(consentDndChannelFilter);
    var extra = dndType ? { dnd_type: dndType } : {};
    apiFetch('/dnd-management' + listingPageQuery('dnd_management', extra))
      .then(function (body) {
        window.realDndRecords = unwrapList(body);
        if (callback) callback(window.realDndRecords);
      })
      .catch(function () {
        window.realDndRecords = [];
        if (callback) callback([]);
      });
  }

  function renderConsentRecordsTable(records) {
    var tbody = document.getElementById('consent-records-table');
    if (!tbody) return;
    if (!records.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-slate-500 p-4">No consent records yet</td></tr>';
      return;
    }
    tbody.innerHTML = records.map(function (row) {
      return '<tr class="ca-table-row">' +
        '<td class="font-medium">' + escapeHtml(row.firm_name || '—') + '</td>' +
        '<td>' + escapeHtml(row.consent_type) + '</td>' +
        '<td>' + consentStatusBadge(row.consent_status) + '</td>' +
        '<td class="whitespace-nowrap">' + escapeHtml(formatActivityTimestamp(row.consent_date)) + '</td>' +
        '<td class="whitespace-nowrap">' + escapeHtml(formatActivityTimestamp(row.updated_at)) + '</td>' +
      '</tr>';
    }).join('');
  }

  function renderDndRecordsTable(records) {
    var tbody = document.getElementById('dnd-records-table');
    if (!tbody) return;
    if (!records.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-slate-500 p-4">No DND entries yet</td></tr>';
      return;
    }
    tbody.innerHTML = records.map(function (row) {
      return '<tr class="ca-table-row">' +
        '<td class="font-medium">' + escapeHtml(row.firm_name || '—') + '</td>' +
        '<td>' + escapeHtml(row.mobile_no || '—') + '</td>' +
        '<td>' + escapeHtml(row.email_id || '—') + '</td>' +
        '<td>' + escapeHtml(row.dnd_type) + '</td>' +
        '<td class="max-w-xs truncate" title="' + escapeHtml(row.reason || '') + '">' + escapeHtml(row.reason || '—') + '</td>' +
        '<td class="whitespace-nowrap">' + escapeHtml(formatActivityTimestamp(row.added_at)) + '</td>' +
        '<td><button type="button" class="btn-secondary btn-sm" data-remove-dnd="' + row.id + '"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i></button></td>' +
      '</tr>';
    }).join('');
    tbody.querySelectorAll('[data-remove-dnd]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var id = btn.getAttribute('data-remove-dnd');
        if (!id || !confirm('Remove this DND entry?')) return;
        apiFetch('/dnd-management/' + id, { method: 'DELETE' })
          .then(function () {
            toast('DND entry removed', 'success');
            refreshConsentDndPage();
            if (document.getElementById('recent-activity-list')) renderRecentActivity();
          })
          .catch(function (error) {
            toast(error.message || 'Failed to remove DND entry', 'error');
          });
      });
    });
    icons();
  }

  function refreshConsentDndPage() {
    loadDashboardMetricsFromDatabase(function (metrics) {
      renderSafetyKpis(metrics);
    });
    loadConsentRecords(function (records) {
      renderConsentRecordsTable(records);
    });
    loadDndRecords(function (records) {
      renderDndRecordsTable(records);
    });
  }

  function activateTabPanel(group, tabId) {
    document.querySelectorAll('.ca-tab[data-tab-group="' + group + '"]').forEach(function (t) {
      t.classList.toggle('active', t.dataset.tab === tabId);
    });
    document.querySelectorAll('.ca-tab-panel[data-tab-group="' + group + '"]').forEach(function (p) {
      p.classList.toggle('active', p.dataset.panel === tabId);
    });
  }

  function initConsentDndPage() {
    var pendingTab = window._consentDndTab;
    if (pendingTab === 'dnd') {
      activateTabPanel('consent-tab', 'dnd-tab');
      window._consentDndTab = '';
    } else if (pendingTab === 'consent') {
      activateTabPanel('consent-tab', 'consent-tab');
      window._consentDndTab = '';
    }
    var filter = document.getElementById('consent-dnd-channel-filter');
    if (filter && !filter._consentDndBound) {
      filter._consentDndBound = true;
      filter.addEventListener('change', function () {
        consentDndChannelFilter = filter.value || '';
        refreshConsentDndPage();
      });
    }
    refreshConsentDndPage();
  }

  function formatCampaignNumber(n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function renderChannelKpis(channel) {
    if (channel === 'email' || channel === 'sms') {
      return;
    }
  }

  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function initCampaignActions() {
    if (window._campaignActionsBound) return;
    window._campaignActionsBound = true;

    document.addEventListener('click', function (e) {
      var launchEmailBtn = e.target.closest('[data-launch-email-campaign]');
      if (launchEmailBtn) {
        e.preventDefault();
        e.stopPropagation();
        var emailCampaignId = launchEmailBtn.dataset.launchEmailCampaign;
        apiFetch('/email-campaigns/' + emailCampaignId + '/process', { method: 'POST' })
          .then(function () {
            toast('Email campaign processed successfully', 'success');
            refreshEmailPage();
            if (document.getElementById('recent-activity-list')) renderRecentActivity();
          })
          .catch(function (error) {
            toast(error.message || 'Failed to process email campaign', 'error');
          });
        return;
      }

      var previewSmsBtn = e.target.closest('[data-preview-sms-payload]');
      if (previewSmsBtn) {
        e.preventDefault();
        e.stopPropagation();
        var smsPreviewId = previewSmsBtn.dataset.previewSmsPayload;
        apiFetch('/sms-campaigns/' + smsPreviewId + '/generate-payload-preview', { method: 'POST' })
          .then(function (body) {
            var preview = body.data || {};
            showSmsPayloadPreviewPanel(preview);
            toast('SMS payload mapped and saved to audit logs', 'success');
            refreshSmsPage();
            if (document.getElementById('recent-activity-list')) renderRecentActivity();
          })
          .catch(function (error) {
            toast(error.message || 'Failed to generate SMS payload preview', 'error');
          });
        return;
      }

      var deleteSmsBtn = e.target.closest('[data-delete-sms-campaign]');
      if (deleteSmsBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (!window.confirm('Delete this SMS campaign?')) return;
        apiFetch('/sms-campaigns/' + deleteSmsBtn.dataset.deleteSmsCampaign, { method: 'DELETE' })
          .then(function () {
            toast('SMS campaign deleted', 'success');
            refreshSmsPage();
          })
          .catch(function (error) {
            toast(error.message || 'Failed to delete SMS campaign', 'error');
          });
        return;
      }

      var deleteEmailBtn = e.target.closest('[data-delete-email-campaign]');
      if (deleteEmailBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (!window.confirm('Delete this email campaign?')) return;
        apiFetch('/email-campaigns/' + deleteEmailBtn.dataset.deleteEmailCampaign, { method: 'DELETE' })
          .then(function () {
            toast('Email campaign deleted', 'success');
            refreshEmailPage();
          })
          .catch(function (error) {
            toast(error.message || 'Failed to delete email campaign', 'error');
          });
        return;
      }

      var deleteWaBtn = e.target.closest('[data-delete-whatsapp-campaign]');
      if (deleteWaBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (!window.confirm('Delete this WhatsApp campaign?')) return;
        apiFetch('/whatsapp-campaigns/' + deleteWaBtn.dataset.deleteWhatsappCampaign, { method: 'DELETE' })
          .then(function () {
            toast('WhatsApp campaign deleted', 'success');
            refreshWhatsAppPage();
          })
          .catch(function (error) {
            toast(error.message || 'Failed to delete WhatsApp campaign', 'error');
          });
        return;
      }

      var launchWaBtn = e.target.closest('[data-launch-whatsapp-campaign]');
      if (launchWaBtn) {
        e.preventDefault();
        e.stopPropagation();
        var campaignId = launchWaBtn.dataset.launchWhatsappCampaign;
        apiFetch('/whatsapp-campaigns/' + campaignId + '/process', { method: 'POST' })
          .then(function () {
            toast('Campaign processed successfully', 'success');
            refreshWhatsAppPage();
            if (document.getElementById('recent-activity-list')) renderRecentActivity();
          })
          .catch(function (error) {
            toast(error.message || 'Failed to process campaign', 'error');
          });
        return;
      }

      var waCard = e.target.closest('.campaign-card[data-whatsapp-campaign-id]');
      if (waCard) {
        var campaign = (window.realWhatsAppCampaigns || []).find(function (item) {
          return String(item.id) === String(waCard.dataset.whatsappCampaignId);
        });
        if (!campaign) return;
        toast(
          campaign.campaign_name + ' · ' + campaign.status + ' · ' +
          (campaign.total_messages || 0) + ' messages · Delivered ' + (campaign.delivered_count || 0),
          'info',
        );
        loadWaMessageLogsFromDatabase(function (logs) {
          renderWaMessageLogsTable(logs);
        }, campaign.id);
        return;
      }

      var emailCard = e.target.closest('.campaign-card[data-email-campaign-id]');
      if (emailCard) {
        var emailCampaign = (window.realEmailCampaigns || []).find(function (item) {
          return String(item.id) === String(emailCard.dataset.emailCampaignId);
        });
        if (!emailCampaign) return;
        toast(
          emailCampaign.campaign_name + ' · ' + emailCampaign.status + ' · ' +
          (emailCampaign.total_emails || 0) + ' emails · Delivered ' + (emailCampaign.delivered_count || 0),
          'info',
        );
        loadEmailLogsFromDatabase(function (logs) {
          renderEmailLogsTable(logs);
        }, emailCampaign.id);
        return;
      }

      var smsCard = e.target.closest('.campaign-card[data-sms-campaign-id]');
      if (smsCard) {
        var smsCampaign = (window.realSmsCampaigns || []).find(function (item) {
          return String(item.id) === String(smsCard.dataset.smsCampaignId);
        });
        if (!smsCampaign) return;
        toast(
          smsCampaign.campaign_name + ' · ' + smsCampaign.status + ' · ' +
          (smsCampaign.total_sms || 0) + ' SMS · Delivered ' + (smsCampaign.delivered_count || 0),
          'info',
        );
        loadSmsLogsFromDatabase(function (logs) {
          renderSmsLogsTable(logs);
        }, smsCampaign.id);
      }
    });
  }

  function renderCampaignGrids() {
    renderWhatsAppCampaignGrid();
    renderEmailCampaignGrid();
    renderSmsCampaignGrid();
  }

  function renderCampaignPage(channel) {
    if (channel === 'whatsapp') {
      refreshWhatsAppPage();
      return;
    }
    if (channel === 'email') {
      refreshEmailPage();
      return;
    }
    if (channel === 'sms') {
      refreshSmsPage();
      return;
    }
    renderCampaignGrids();
  }

  function bindModalTriggers(root) {
    (root || document).querySelectorAll('[data-open-modal]').forEach(function (btn) {
      if (btn._crmBound) return;
      btn._crmBound = true;
      btn.addEventListener('click', function () {
        var modalKey = btn.dataset.openModal;
        var id = 'modal-' + modalKey;
        var modal = document.getElementById(id);

        function openPreparedModal() {
          populateSelects();
          if (modalKey === 'assign-lead' || modalKey === 'followup') {
            if (modalKey === 'assign-lead') window._editingAssignmentId = null;
            if (modalKey === 'followup') window._editingFollowUpId = null;
            var selId = CAData.getSelectedLeadId();
            var sel = document.getElementById(modalKey === 'assign-lead' ? 'form-assign-lead-select' : 'form-fu-lead');
            setSelectValueIfValid(sel, selId);
          }
          if (modalKey === 'add-campaign') {
            configureCampaignModal(btn.dataset.campaignChannel || 'whatsapp');
          }
          if (modalKey === 'add-lead') {
            openLeadFormForAdd();
          }
          if (modalKey === 'add-employee') {
            var empForm = document.getElementById('form-add-employee');
            if (empForm) {
              empForm.reset();
              window._editingEmployeeId = null;
              configureEmployeeModalMode('create');
              if (window.CA_STATE_CITY) {
                window.CA_STATE_CITY.resetFormLocations(empForm);
              }
            }
          }
          openModal(modal);
          icons();
        }

        if (modalKey === 'add-campaign' && ['whatsapp', 'email', 'sms'].indexOf(btn.dataset.campaignChannel || 'whatsapp') >= 0) {
          ensureFormSelectData(function () {
            populateSelects();
            var campaignChannel = btn.dataset.campaignChannel || 'whatsapp';
            var campaignForm = document.getElementById('form-add-campaign');
            if (campaignForm) campaignForm.reset();
            configureCampaignModal(campaignChannel);
            populateCampaignAudienceSelects(campaignChannel);
            openModal(modal);
            if (window.CA_STATE_CITY) {
              window.CA_STATE_CITY.prepareForm('form-add-campaign');
            }
            icons();
          });
        } else if (modalKey === 'assign-lead' || modalKey === 'followup' || modalKey === 'add-lead') {
          ensureFormSelectData(openPreparedModal);
        } else {
          openPreparedModal();
        }
      });
    });
  }

  function initForms() {
    document.getElementById('form-add-lead')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var editingId = fd.get('ca_id') || window._editingLeadId;
      var url = editingId ? '/ca-masters/' + editingId : '/ca-masters';

      if (editingId) {
        fd.append('_method', 'PUT');
      }

      apiFetch(url, { method: 'POST', body: fd })
        .then(function () {
          closeModal(document.getElementById('modal-add-lead'));
          resetLeadForm();
          realLeadsLoaded = false;
          window._leadSegmentFilter = 'all';
          window._selectLeadsLoaded = false;
          invalidateDataCaches(['metrics', 'leads']);
          if (window.CA_LISTING_SEARCH) {
            CA_LISTING_SEARCH.setState('ca_masters', { page: 1, filters: {}, search: '' });
          }
          loadLeadsFromDatabase(function () {
            reloadListing('ca_masters');
            renderMasterTables();
            if (document.getElementById('mgr-kpi-grid')) {
              dashboardMetricsLoaded = false;
              renderManagerDashboard();
            }
          });
          toast(editingId ? 'Lead updated successfully' : 'Lead saved successfully', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Error while saving lead', 'error');
        });
    });

    document.getElementById('form-lead-contact')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var caId = (fd.get('ca_id') || '').trim();
      if (!caId) {
        toast('Lead not found', 'warning');
        return;
      }
      var payload = {
        mobile_no: (fd.get('mobile_no') || '').trim(),
        alternate_mobile_no: (fd.get('alternate_mobile_no') || '').trim() || null,
        email_id: (fd.get('email_id') || '').trim() || null,
        website: (fd.get('website') || '').trim() || null,
      };
      apiFetch('/ca-masters/' + encodeURIComponent(caId) + '/contact', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function () {
          closeModal(document.getElementById('modal-lead-contact'));
          realLeadsLoaded = false;
          invalidateDataCaches(['metrics', 'leads']);
          reloadListing('ca_masters');
          toast('Contact details updated successfully', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Error while updating contact', 'error');
        });
    });

    document.getElementById('form-add-employee')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var editingId = window._editingEmployeeId;
      if (!editingId) {
        if ((fd.get('password') || '') !== (fd.get('password_confirmation') || '')) {
          toast('Password and Confirm Password must match', 'error');
          return;
        }
        if (!fd.get('crm_role')) fd.set('crm_role', 'employee');
        if (!fd.get('role')) fd.set('role', 'Sales Executive');
      } else {
        fd.delete('password');
        fd.delete('password_confirmation');
        fd.delete('crm_role');
      }
      var url = editingId ? '/employees/' + editingId : '/employees';
      var options = { method: 'POST', body: fd };
      if (editingId) {
        fd.append('_method', 'PUT');
      }

      apiFetch(url, options)
        .then(function () {
          closeModal(document.getElementById('modal-add-employee'));
          e.target.reset();
          window._editingEmployeeId = null;
          if (window.CA_STATE_CITY) {
            window.CA_STATE_CITY.resetFormLocations(e.target);
          }
          realEmployeesLoaded = false;
          refreshAll();
          toast(editingId ? 'Employee updated successfully' : 'Employee saved successfully', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Error while saving employee', 'error');
        });
    });

    document.getElementById('form-edit-profile')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var payload = {
        name: (fd.get('name') || '').trim(),
        email: (fd.get('email') || '').trim(),
      };
      var designationWrap = document.getElementById('profile-field-designation');
      if (designationWrap && !designationWrap.classList.contains('hidden')) {
        payload.designation = (fd.get('designation') || '').trim();
      }
      var mobileWrap = document.getElementById('profile-field-mobile');
      if (mobileWrap && !mobileWrap.classList.contains('hidden')) {
        payload.mobile_no = (fd.get('mobile_no') || '').trim();
      }
      apiFetch('/auth/profile', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function (res) {
          var updated = (res && res.data) ? res.data : res;
          if (updated && typeof updated === 'object') {
            window.__CRM_USER__ = Object.assign({}, window.__CRM_USER__ || {}, updated);
          }
          if (window.CA_RBAC && typeof window.CA_RBAC.enforce === 'function') {
            window.CA_RBAC.enforce();
          }
          closeModal(document.getElementById('modal-edit-profile'));
          toast('Profile updated successfully', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Unable to update profile', 'error');
        });
    });

    document.getElementById('form-change-password')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      if ((fd.get('password') || '') !== (fd.get('password_confirmation') || '')) {
        toast('New password and confirmation must match', 'error');
        return;
      }
      apiFetch('/auth/change-password', { method: 'POST', body: fd })
        .then(function () {
          closeModal(document.getElementById('modal-change-password'));
          e.target.reset();
          toast('Password updated successfully', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Unable to update password', 'error');
        });
    });

    document.getElementById('form-reset-employee-password')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var employeeId = fd.get('employee_id');
      if (!employeeId) {
        toast('Please select an employee', 'warning');
        return;
      }
      if ((fd.get('password') || '') !== (fd.get('password_confirmation') || '')) {
        toast('Password and confirmation must match', 'error');
        return;
      }
      apiFetch('/employees/' + encodeURIComponent(employeeId) + '/reset-password', { method: 'POST', body: fd })
        .then(function () {
          closeModal(document.getElementById('modal-reset-employee-password'));
          e.target.reset();
          toast('Employee password reset successfully', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Unable to reset employee password', 'error');
        });
    });

    initPasswordToggleButtons(document);

    document.getElementById('form-assign-lead')?.addEventListener('submit', function (e) {
      e.preventDefault();

      var fd = new FormData(e.target);
      var caId = fd.get('ca_id');
      var executiveId = fd.get('executive_id');

      if (!caId || !executiveId) {
        toast('Please select a lead and executive', 'warning');
        return;
      }

      fd.set('employee_id', executiveId);

      var editingAssignmentId = window._editingAssignmentId;
      var assignUrl = editingAssignmentId
        ? '/lead-assignments/' + encodeURIComponent(editingAssignmentId)
        : '/lead-assignments';
      var assignMethod = editingAssignmentId ? 'PUT' : 'POST';

      apiFetch(assignUrl, { method: assignMethod, body: fd })
        .then(function () {
          closeModal(document.getElementById('modal-assign-lead'));
          window._editingAssignmentId = null;
          e.target.reset();
          realAssignmentsLoaded = false;
          refreshAll();
          toast(editingAssignmentId ? 'Assignment updated successfully' : 'Lead assigned successfully', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Error while assigning lead', 'error');
        });
    });

    document.getElementById('form-followup')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var editingId = window._editingFollowUpId;
      var url = editingId ? '/follow-ups/' + encodeURIComponent(editingId) : '/follow-ups';
      var method = editingId ? 'PUT' : 'POST';
      var payload = Object.fromEntries(fd.entries());
      if (editingId && window._followupOriginalScheduled && payload.scheduled_date) {
        var newSlice = String(payload.scheduled_date).slice(0, 16);
        if (newSlice !== window._followupOriginalScheduled && !payload.reschedule_reason) {
          toast('Please provide a reschedule reason when changing the date.', 'warning');
          var wrap = document.getElementById('followup-reschedule-reason-wrap');
          if (wrap) wrap.classList.remove('hidden');
          return;
        }
      }

      apiFetch(url, { method: method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(function () {
          closeModal(document.getElementById('modal-followup'));
          e.target.reset();
          window._editingFollowUpId = null;
          window._followupOriginalScheduled = '';
          realFollowUpsLoaded = false;
          refreshAll();
          toast(editingId ? 'Follow-up updated successfully' : 'Follow-up saved successfully', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Error while saving follow-up', 'error');
        });
    });

    document.getElementById('form-call-outcome')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var payload = Object.fromEntries(fd.entries());
      if (!payload.outcome) {
        toast('Please select a call outcome.', 'warning');
        return;
      }
      apiFetch('/follow-ups/call-outcome', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function () {
          closeModal(document.getElementById('modal-call-outcome'));
          e.target.reset();
          realFollowUpsLoaded = false;
          refreshAll();
          toast('Call outcome saved. Next follow-up scheduled if applicable.', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Failed to save call outcome', 'error');
        });
    });

    var outcomeSelect = document.getElementById('call-outcome-select');
    if (outcomeSelect) {
      outcomeSelect.addEventListener('change', function () {
        var wrap = document.getElementById('call-outcome-schedule-wrap');
        if (!wrap) return;
        var manual = ['Call Later', 'Interested', 'Demo Scheduled'].indexOf(outcomeSelect.value) >= 0;
        wrap.classList.toggle('hidden', !manual);
      });
    }

    var fuScheduled = document.querySelector('#form-followup [name="scheduled_date"]');
    if (fuScheduled) {
      fuScheduled.addEventListener('change', function () {
        var wrap = document.getElementById('followup-reschedule-reason-wrap');
        if (!wrap || !window._editingFollowUpId) return;
        var changed = window._followupOriginalScheduled && fuScheduled.value !== window._followupOriginalScheduled;
        wrap.classList.toggle('hidden', !changed);
      });
    }

    document.getElementById('form-add-consent')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var payload = Object.fromEntries(fd.entries());
      apiFetch('/consent-trackings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function () {
          closeModal(document.getElementById('modal-add-consent'));
          e.target.reset();
          refreshConsentDndPage();
          if (document.getElementById('recent-activity-list')) renderRecentActivity();
          toast('Consent saved successfully', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Failed to save consent', 'error');
        });
    });

    document.getElementById('form-add-dnd')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var payload = Object.fromEntries(fd.entries());
      apiFetch('/dnd-management', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function () {
          closeModal(document.getElementById('modal-add-dnd'));
          e.target.reset();
          refreshConsentDndPage();
          if (document.getElementById('recent-activity-list')) renderRecentActivity();
          toast('DND entry added successfully', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Failed to add DND entry', 'error');
        });
    });

    document.getElementById('form-add-campaign')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var data = Object.fromEntries(fd.entries());
      var channel = data.channel || document.getElementById('form-campaign-channel')?.value || 'whatsapp';

      if (!(data.name && data.name.trim())) {
        toast('Campaign name is required', 'error');
        return;
      }
      if (!(data.campaign_type && data.campaign_type.trim())) {
        toast('Campaign type is required', 'error');
        return;
      }

      if (channel === 'whatsapp') {
        if (!(data.message_template && data.message_template.trim())) {
          toast('WhatsApp message template is required', 'error');
          return;
        }
        var payload = buildCampaignAudiencePayload(data);
        payload.message_template = data.message_template.trim();

        apiFetch('/whatsapp-campaigns', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        })
          .then(function (body) {
            var campaign = body.data || {};
            closeModal(document.getElementById('modal-add-campaign'));
            e.target.reset();
            configureCampaignModal('whatsapp');
            refreshWhatsAppPage();
            if (document.getElementById('recent-activity-list')) renderRecentActivity();
            toast(
              'Campaign "' + (campaign.campaign_name || data.name) + '" created · ' +
              (campaign.total_messages || 0) + ' messages · ' + (campaign.status || 'Completed'),
              'success',
            );
          })
          .catch(function (error) {
            toast(error.message || 'Failed to create WhatsApp campaign', 'error');
          });
        return;
      }
      if (channel === 'email') {
        if (!(data.subject && data.subject.trim())) {
          toast('Email subject is required', 'error');
          return;
        }
        if (!(data.body_template && data.body_template.trim())) {
          toast('Email body is required', 'error');
          return;
        }
        var emailPayload = buildCampaignAudiencePayload(data);
        emailPayload.subject = data.subject.trim();
        emailPayload.body_template = data.body_template.trim();

        apiFetch('/email-campaigns', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(emailPayload),
        })
          .then(function (body) {
            var campaign = body.data || {};
            closeModal(document.getElementById('modal-add-campaign'));
            e.target.reset();
            configureCampaignModal('email');
            refreshEmailPage();
            if (document.getElementById('recent-activity-list')) renderRecentActivity();
            toast(
              'Campaign "' + (campaign.campaign_name || data.name) + '" created · ' +
              (campaign.total_emails || 0) + ' emails · ' + (campaign.status || 'Completed'),
              'success',
            );
          })
          .catch(function (error) {
            toast(error.message || 'Failed to create email campaign', 'error');
          });
        return;
      }
      if (channel === 'sms') {
        submitSmsCampaignDraft();
        return;
      }
      toast('Unsupported campaign channel. Choose WhatsApp, Email, or SMS.', 'error');
    });

    document.getElementById('btn-create-campaign')?.addEventListener('click', function (e) {
      var form = document.getElementById('form-add-campaign');
      if (!form) return;
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
      }
    });

    document.getElementById('form-campaign-audience-mode')?.addEventListener('change', function () {
      toggleCampaignAudienceFields();
      updateSmsCampaignEstimates();
    });
    document.getElementById('form-campaign-ca-ids')?.addEventListener('change', updateSmsCampaignEstimates);
    document.getElementById('form-campaign-sms-message-template')?.addEventListener('input', updateSmsCampaignEstimates);

    document.getElementById('btn-sms-save-draft')?.addEventListener('click', function () {
      submitSmsCampaignDraft();
    });

    document.getElementById('btn-sms-preview-message')?.addEventListener('click', function () {
      var template = document.getElementById('form-campaign-sms-message-template')?.value || '';
      var leadOpt = document.getElementById('form-campaign-ca-ids')?.selectedOptions?.[0];
      var leadId = leadOpt ? parseInt(leadOpt.value, 10) : ((window.realLeads || [])[0] || {}).ca_id;
      if (!template.trim()) {
        toast('Enter a message template first', 'error');
        return;
      }
      if (!leadId) {
        toast('Select at least one lead to preview', 'error');
        return;
      }
      var previewLead = findLeadById(leadId);
      if (previewLead && !leadHasValidMobile(previewLead)) {
        toast(SMS_MOBILE_REQUIRED_MESSAGE, 'error');
        return;
      }
      apiFetch('/sms-campaigns/preview-message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message_template: template, lead_id: leadId }),
      }).then(function (body) {
        var data = body.data || {};
        var previewEl = document.getElementById('form-campaign-sms-preview-message');
        if (previewEl) previewEl.value = data.preview || '';
        var charEl = document.getElementById('form-campaign-sms-char-count');
        var smsEl = document.getElementById('form-campaign-sms-sms-count');
        if (charEl) charEl.value = String(data.character_count || 0);
        if (smsEl) smsEl.value = String(data.sms_count || 0);
      }).catch(function (error) {
        toast(error.message || 'Failed to preview message', 'error');
      });
    });

    document.getElementById('btn-sms-preview-payload')?.addEventListener('click', function () {
      submitSmsCampaignDraft(function (campaign) {
        apiFetch('/sms-campaigns/' + campaign.id + '/generate-payload-preview', { method: 'POST' })
          .then(function (body) {
            var preview = body.data || {};
            showSmsPayloadPreviewPanel(preview);
            closeModal(document.getElementById('modal-add-campaign'));
            document.getElementById('form-add-campaign')?.reset();
            configureCampaignModal('sms');
            refreshSmsPage();
            toast('Payload mapped and saved to sms_logs for audit', 'success');
          })
          .catch(function (error) {
            toast(error.message || 'Failed to generate payload preview', 'error');
          });
      });
    });

    document.querySelectorAll('[data-close-crm-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var modal = btn.closest('.ca-modal');
        closeModal(modal);
        if (modal && modal.id === 'modal-add-lead') resetLeadForm();
      });
    });

    document.getElementById('detail-followup-btn')?.addEventListener('click', function () {
      if (typeof closeDetailDrawer === 'function') closeDetailDrawer();
      ensureFormSelectData(function () {
        populateSelects();
        setSelectValueIfValid(document.getElementById('form-fu-lead'), CAData.getSelectedLeadId());
        openModal(document.getElementById('modal-followup'));
      });
    });
    document.getElementById('detail-edit-btn')?.addEventListener('click', function () {
      var drawer = document.getElementById('detail-drawer');
      if (drawer && drawer.dataset.mode === 'profile') {
        openProfileEditModal();
        return;
      }
      var leadId = CAData.getSelectedLeadId();
      if (!leadId) {
        toast('Select a lead from the table or kanban first', 'warning');
        return;
      }
      if (typeof closeDetailDrawer === 'function') closeDetailDrawer();
      if (openLeadFormForEdit(leadId)) {
        openModal(document.getElementById('modal-add-lead'));
        icons();
      }
    });
  }

  function initLeadActions() {
    document.querySelectorAll('[data-lead-action]').forEach(function (btn) {
      if (btn._crmActionBound) return;
      btn._crmActionBound = true;
      btn.addEventListener('click', function () {
        var id = CAData.getSelectedLeadId();
        if (!id) { toast('Select a lead from the table or kanban first', 'warning'); return; }
        apiFetch('/lead-actions', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            ca_id: parseInt(id, 10),
            action_type: btn.dataset.leadAction,
          }),
        }).then(function () {
          document.querySelectorAll('.ca-chip-action').forEach(function (c) { c.classList.remove('active'); });
          btn.classList.add('active');
          var msg = document.getElementById('lead-action-toast');
          if (msg) {
            msg.textContent = '✓ ' + btn.dataset.leadAction + ' applied successfully.';
            msg.classList.remove('hidden');
          }
          refreshAll();
          var lead = getLeadRecord(id);
          updateSelectedLeadBar(lead);
          toast('Lead action: ' + btn.dataset.leadAction, 'success');
        }).catch(function (err) {
          toast(err.message || 'Unable to apply lead action', 'error');
        });
      });
    });
  }

  function initQuickActions() {
    document.querySelectorAll('.ca-action-btn').forEach(function (btn) {
      if (btn._qaBound) return;
      btn._qaBound = true;
      if (btn.dataset.openModal) return;
      btn.addEventListener('click', function () {
        if (typeof closeAllOverlays === 'function') closeAllOverlays();
        var label = btn.querySelector('span')?.textContent || '';
        if (label === 'Bulk Import') {
          navigateTo('bulk');
          setTimeout(function () {
            if (typeof window.openBulkImportWizard === 'function') window.openBulkImportWizard();
          }, 120);
          return;
        }
        if (label === 'Export Report') {
          if (typeof exportReportsSummary === 'function') {
            exportReportsSummary();
          } else {
            navigateTo('reports');
          }
          return;
        }
        if (label === 'Send Message') {
          navigateTo('whatsapp');
          return;
        }
        if (USE_DEMO_FALLBACKS) {
          toast(label + ' — ' + API_MSG, 'info');
        }
      });
    });
    bindModalTriggers(document.getElementById('quick-actions-menu'));
  }
  function refreshCurrentPage() {
    dashboardMetricsLoaded = false;
    onPage(window._currentPageId || 'dashboard');
  }

  function refreshAll() {
    invalidateDataCaches(['metrics']);
    employeeDashboardLoaded = false;
    employeeDashboardData = null;
    refreshCurrentPage();
  }

  function onPage(pageId) {
    window._currentPageId = pageId;
    bindModalTriggers(document);

    if (pageId === 'dashboard') {
      if (isEmployeeUser()) renderEmployeeDashboard();
      else renderManagerDashboard();
      if (window.CA_CRM && typeof CA_CRM.startNotificationPoller === 'function') {
        CA_CRM.startNotificationPoller();
      }
      icons();
      return;
    }
    if (pageId === 'leads' || pageId === 'leads-segments') {
      renderLeadsHub();
      icons();
      return;
    }
    if (pageId === 'employees' || pageId === 'assignment') {
      loadDashboardMetricsFromDatabase(function () {
        renderEmployeesTable();
        renderLeaderboard();
        renderAssignmentTable();
        renderAssignmentHistoryTable();
        renderAssignmentKpis();
        icons();
      });
      return;
    }
    if (pageId === 'followups') {
      var dueFilter = window._followupDateFilter || '';
      if (dueFilter && window.CA_LISTING_SEARCH) {
        CA_LISTING_SEARCH.setState('follow_ups', { page: 1, filters: { followup_due: dueFilter } });
        window._followupDateFilter = '';
      }
      loadDashboardMetricsFromDatabase(function () {
        if (window.CA_LISTING_SEARCH) {
          reloadListing('follow_ups');
        } else {
          renderFollowupsTable();
          renderFollowupKpis();
          renderFollowupCalendarFromData();
        }
        icons();
      });
      return;
    }
    if (pageId === 'ca-master' || pageId === 'bulk') {
      window._leadSegmentFilter = 'all';
      if (pageId === 'ca-master' && window.CA_LISTING_SEARCH) {
        CA_LISTING_SEARCH.setState('ca_masters', { page: 1, filters: {}, search: '' });
      }
      ensureMasterData(function () {
        renderCaMasterTable();
        renderMasterTables();
        applyMasterDataRbac();
        if (window.CA_LISTING_SEARCH) {
          reloadListing('states');
          reloadListing('cities');
        }
        if (pageId === 'bulk') {
          var bulkWizard = document.getElementById('bulk-import-wizard');
          if (bulkWizard && bulkWizard.classList.contains('hidden') && typeof window.resetBulkImportWizard === 'function') {
            window.resetBulkImportWizard();
          }
          initBulkAssignmentPanel();
          initBulkImportWizard();
          initBulkExportPanel();
          initBulkStatusUpdatePanel();
          loadBulkOperationsHistory();
        }
        icons();
      });
      return;
    }
    if (pageId === 'whatsapp') {
      renderCampaignPage('whatsapp');
      icons();
      return;
    }
    if (pageId === 'sms') {
      renderCampaignPage('sms');
      icons();
      return;
    }
    if (pageId === 'email') {
      renderCampaignPage('email');
      icons();
      return;
    }
    if (pageId === 'consent-dnd') {
      initConsentDndPage();
      icons();
      return;
    }
    if (pageId === 'db-health') {
      initDbHealthPage();
      icons();
      return;
    }
    if (pageId === 'queue') {
      initQueuePage();
      icons();
      return;
    }
    if (pageId === 'settings') {
      initSettingsPage();
      icons();
      return;
    }
    if (pageId === 'security') {
      initSecurityPage();
      icons();
      return;
    }
    if (pageId === 'activity' || document.getElementById('activity-logs-table')) {
      initActivityLogsPage();
      icons();
      return;
    }
    if (pageId === 'reports' || pageId === 'analytics') {
      refreshReportsHub();
      icons();
      return;
    }
    if (pageId === 'audit') {
      initAuditPage();
      icons();
      return;
    }
    icons();
  }

  var bulkAssignmentState = {
    selectedBatchId: null,
    selectedBatch: null,
    selectedEmployeeIds: {},
    batchPage: 1,
    employeePage: 1,
    batchItems: [],
    employeeItems: [],
    batchPagination: {},
    employeePagination: {},
    previewSummary: null,
    batchesLoading: false,
    employeesLoading: false,
    employeeSearchTimer: null,
  };

  function bulkAssignSelectedEmployeeIds() {
    return Object.keys(bulkAssignmentState.selectedEmployeeIds).filter(function (id) {
      return bulkAssignmentState.selectedEmployeeIds[id];
    });
  }

  function bulkAssignMatchingLeadCount() {
    if (!bulkAssignmentState.selectedBatch) return 0;
    return bulkAssignmentState.selectedBatch.matching_leads || 0;
  }

  function bulkAssignBatchFilterParams() {
    return {
      state_id: (document.getElementById('bulk-assign-batch-state') || {}).value || '',
      city_id: (document.getElementById('bulk-assign-batch-city') || {}).value || '',
      source_id: (document.getElementById('bulk-assign-batch-source') || {}).value || '',
      assignment: (document.getElementById('bulk-assign-batch-assignment') || {}).value || '',
    };
  }

  function bulkAssignUpdateCounts() {
    var leadCount = bulkAssignMatchingLeadCount();
    var empCount = bulkAssignSelectedEmployeeIds().length;
    var batch = bulkAssignmentState.selectedBatch;
    var batchSummary = document.getElementById('bulk-assign-summary-batch');
    if (batchSummary) {
      batchSummary.innerHTML = 'Selected Batch: <strong>' + escapeHtml(batch ? (batch.batch_name || batch.file_name || 'Batch') : 'None') + '</strong>';
    }
    var leadSummary = document.getElementById('bulk-assign-summary-leads');
    if (leadSummary) {
      leadSummary.innerHTML = 'Leads to Assign: <strong>' + leadCount + '</strong>';
    }
    var empSummary = document.getElementById('bulk-assign-summary-employees');
    if (empSummary) {
      empSummary.innerHTML = 'Selected Employees: <strong>' + empCount + '</strong>';
    }
    bulkAssignUpdateActionButtons(leadCount, empCount);
    bulkAssignSyncModeForEmployeeSelection();
  }

  function bulkAssignUpdateActionButtons(leadCount, empCount) {
    leadCount = leadCount ?? bulkAssignMatchingLeadCount();
    empCount = empCount ?? bulkAssignSelectedEmployeeIds().length;
    var ready = !!bulkAssignmentState.selectedBatchId && leadCount > 0 && empCount > 0;
    var loadingEl = document.getElementById('bulk-assign-loading');
    var isLoading = loadingEl && !loadingEl.classList.contains('hidden');
    ['bulk-assign-preview-btn', 'bulk-assign-confirm-btn'].forEach(function (id) {
      var btn = document.getElementById(id);
      if (btn) btn.disabled = !ready || isLoading;
    });
  }

  function bulkAssignSyncModeForEmployeeSelection() {
    var modeSel = document.getElementById('bulk-assign-mode');
    if (!modeSel) return;
    var empCount = bulkAssignSelectedEmployeeIds().length;
    var manualOption = modeSel.querySelector('option[value="manual"]');
    if (manualOption) {
      manualOption.disabled = empCount > 1;
    }
    var reasonSel = document.getElementById('bulk-assign-reason');
    if (empCount === 1) {
      modeSel.value = 'manual';
      if (reasonSel) reasonSel.value = 'MANUAL_ASSIGN';
    } else if (empCount > 1 && modeSel.value === 'manual') {
      modeSel.value = 'round_robin';
      if (reasonSel) reasonSel.value = 'ROUND_ROBIN';
    }
  }

  function bulkAssignResolveAssignmentMode(mode, employeeCount) {
    if (employeeCount === 1) {
      return 'manual';
    }
    if (mode === 'manual') {
      return 'round_robin';
    }
    return mode;
  }

  function bulkAssignModeLabel(mode) {
    var map = {
      manual: 'Manual',
      round_robin: 'Round Robin',
      workload_balance: 'Workload Balance',
      city_match: 'City-wise',
      state_match: 'State-wise',
    };
    return map[mode] || mode || '—';
  }

  function bulkAssignReasonLabel(reason) {
    var map = {
      MANUAL_ASSIGN: 'Manual Assignment',
      ROUND_ROBIN: 'Round Robin',
      WORKLOAD_BALANCE: 'Workload Balance',
      CITY_MATCH: 'City Match',
      STATE_MATCH: 'State Match',
      HOT_LEAD_AUTO: 'Hot Lead Auto',
    };
    return map[reason] || (reason || '—').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  function bulkAssignBatchCardHtml(batch) {
    var selected = String(bulkAssignmentState.selectedBatchId) === String(batch.bulk_action_id);
    return '<div class="bulk-assign-batch-card' + (selected ? ' is-selected' : '') + '" data-batch-id="' + batch.bulk_action_id + '">' +
      '<div class="bulk-assign-batch-body">' +
        '<p class="bulk-assign-batch-name">' + escapeHtml(batch.batch_name || batch.file_name || 'Import batch') + '</p>' +
        '<div class="bulk-assign-batch-grid">' +
          '<div><span class="bulk-assign-batch-label">Total Leads</span><strong>' + (batch.total_leads || 0) + '</strong></div>' +
          '<div><span class="bulk-assign-batch-label">Unassigned</span><strong>' + (batch.unassigned_leads || 0) + '</strong></div>' +
          '<div><span class="bulk-assign-batch-label">Assigned</span><strong>' + (batch.assigned_leads || 0) + '</strong></div>' +
          '<div><span class="bulk-assign-batch-label">Matching</span><strong>' + (batch.matching_leads || 0) + '</strong></div>' +
        '</div>' +
        '<p class="bulk-assign-batch-meta">Source: ' + escapeHtml(batch.source || 'Bulk Import') + '</p>' +
        '<p class="bulk-assign-batch-meta">Imported By: ' + escapeHtml(batch.imported_by || '—') + '</p>' +
        '<p class="bulk-assign-batch-meta">Imported At: ' + escapeHtml(batch.imported_at_label || '—') + '</p>' +
      '</div>' +
      '<button type="button" class="btn-secondary btn-sm bulk-assign-batch-select-btn" data-batch-id="' + batch.bulk_action_id + '">' +
        (selected ? 'Selected' : 'Select Batch') +
      '</button>' +
    '</div>';
  }

  function bulkAssignEmployeeCardHtml(emp) {
    var selected = bulkAssignmentState.selectedEmployeeIds[String(emp.employee_id)];
    var availClass = (emp.availability || '').toLowerCase().replace(/\s+/g, '-');
    var disabled = emp.assignable === false ? ' bulk-assign-emp-disabled' : '';
    var disabledAttr = emp.assignable === false ? ' disabled' : '';
    return '<label class="bulk-assign-emp-card' + (selected ? ' is-selected' : '') + disabled + '">' +
      '<input type="checkbox" class="bulk-assign-emp-check" data-employee-id="' + emp.employee_id + '"' + (selected ? ' checked' : '') + disabledAttr + ' />' +
      '<div class="bulk-assign-emp-avatar">' + escapeHtml((emp.name || '?').charAt(0).toUpperCase()) + '</div>' +
      '<div class="bulk-assign-emp-body">' +
        '<p class="bulk-assign-emp-name">' + escapeHtml(emp.name || '—') + '</p>' +
        '<p class="bulk-assign-emp-role">' + escapeHtml(emp.designation || '—') + ' · ' + escapeHtml(emp.city || '—') + '</p>' +
        '<div class="bulk-assign-emp-stats">' +
          '<span>Today: ' + (emp.assigned_today || 0) + '</span>' +
          '<span>Active: ' + (emp.active_leads || 0) + '</span>' +
          '<span>Follow-ups: ' + (emp.followups_today || 0) + '</span>' +
        '</div>' +
        '<div class="bulk-assign-emp-footer">' +
          '<span class="bulk-assign-workload"><span style="width:' + Math.min(100, emp.workload_pct || 0) + '%"></span></span>' +
          '<span class="bulk-assign-avail bulk-assign-avail-' + availClass + '">' + escapeHtml(emp.availability || '—') + '</span>' +
        '</div>' +
      '</div></label>';
  }

  function bulkAssignRenderPagination(containerId, pagination, type) {
    var el = document.getElementById(containerId);
    if (!el || !pagination) return;
    var page = pagination.page || 1;
    var last = pagination.last_page || 1;
    if (last <= 1) {
      el.innerHTML = '<span class="text-caption text-slate-500">Page 1 of 1</span>';
      return;
    }
    el.innerHTML = '<button type="button" class="btn-secondary btn-sm bulk-assign-page-btn" data-type="' + type + '" data-page="' + (page - 1) + '" ' + (page <= 1 ? 'disabled' : '') + '>Prev</button>' +
      '<span class="text-caption text-slate-600">Page ' + page + ' of ' + last +
      '<button type="button" class="btn-secondary btn-sm bulk-assign-page-btn" data-type="' + type + '" data-page="' + (page + 1) + '" ' + (page >= last ? 'disabled' : '') + '>Next</button>';
  }

  function bulkAssignSelectBatch(batch) {
    if (!batch) return;
    bulkAssignmentState.selectedBatchId = String(batch.bulk_action_id);
    bulkAssignmentState.selectedBatch = batch;
    bulkAssignUpdateCounts();
    bulkAssignInvalidatePreview();
    var listEl = document.getElementById('bulk-assign-batches-list');
    if (listEl) {
      listEl.querySelectorAll('.bulk-assign-batch-card').forEach(function (card) {
        var isSelected = String(card.dataset.batchId) === bulkAssignmentState.selectedBatchId;
        card.classList.toggle('is-selected', isSelected);
        var btn = card.querySelector('.bulk-assign-batch-select-btn');
        if (btn) btn.textContent = isSelected ? 'Selected' : 'Select Batch';
      });
    }
  }

  function bulkAssignClearBatchSelection() {
    bulkAssignmentState.selectedBatchId = null;
    bulkAssignmentState.selectedBatch = null;
    loadBulkAssignBatches(bulkAssignmentState.batchPage || 1);
    bulkAssignUpdateCounts();
    bulkAssignInvalidatePreview();
  }

  function loadBulkAssignBatches(page) {
    if (bulkAssignmentState.batchesLoading) return;
    bulkAssignmentState.batchesLoading = true;
    bulkAssignmentState.batchPage = page || bulkAssignmentState.batchPage || 1;
    var list = document.getElementById('bulk-assign-batches-list');
    if (list && !list.querySelector('.bulk-assign-batch-card')) {
      list.innerHTML = '<div class="bulk-assign-skeleton">Loading import batches…</div>';
    }
    var params = new URLSearchParams(Object.assign({
      page: String(bulkAssignmentState.batchPage),
      per_page: '10',
    }, bulkAssignBatchFilterParams()));
    apiFetch('/lead-assignments/bulk/batches?' + params.toString())
      .then(function (body) {
        var data = body.data || {};
        bulkAssignmentState.batchItems = data.items || [];
        bulkAssignmentState.batchPagination = data.pagination || {};
        if (bulkAssignmentState.selectedBatchId) {
          var selected = bulkAssignmentState.batchItems.find(function (item) {
            return String(item.bulk_action_id) === bulkAssignmentState.selectedBatchId;
          });
          if (selected) bulkAssignmentState.selectedBatch = selected;
        }
        var listEl = document.getElementById('bulk-assign-batches-list');
        if (!listEl) return;
        if (!bulkAssignmentState.batchItems.length) {
          listEl.innerHTML = '<div class="bulk-assign-empty">No import batches found. Import leads first, then assign by batch.</div>';
        } else {
          listEl.innerHTML = bulkAssignmentState.batchItems.map(bulkAssignBatchCardHtml).join('');
        }
        bulkAssignRenderPagination('bulk-assign-batches-pagination', bulkAssignmentState.batchPagination, 'batches');
        bindBulkAssignBatchCards(listEl);
        bulkAssignUpdateCounts();
      })
      .catch(function (err) {
        var listEl = document.getElementById('bulk-assign-batches-list');
        if (listEl) listEl.innerHTML = '<div class="bulk-assign-empty text-rose-600">' + escapeHtml(err.message || 'Failed to load batches') + '</div>';
      })
      .finally(function () {
        bulkAssignmentState.batchesLoading = false;
      });
  }

  function bindBulkAssignBatchCards(root) {
    if (!root) return;
    root.querySelectorAll('.bulk-assign-batch-select-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var id = String(btn.dataset.batchId);
        var batch = bulkAssignmentState.batchItems.find(function (item) {
          return String(item.bulk_action_id) === id;
        });
        if (!batch) return;
        if (bulkAssignmentState.selectedBatchId === id) {
          bulkAssignClearBatchSelection();
          return;
        }
        bulkAssignSelectBatch(batch);
      });
    });
    root.querySelectorAll('.bulk-assign-batch-card').forEach(function (card) {
      card.addEventListener('click', function (e) {
        if (e.target.closest('.bulk-assign-batch-select-btn')) return;
        var id = String(card.dataset.batchId);
        var batch = bulkAssignmentState.batchItems.find(function (item) {
          return String(item.bulk_action_id) === id;
        });
        if (batch) bulkAssignSelectBatch(batch);
      });
    });
  }

  function loadBulkAssignEmployees(page) {
    if (bulkAssignmentState.employeesLoading) return;
    bulkAssignmentState.employeesLoading = true;
    bulkAssignmentState.employeePage = page || bulkAssignmentState.employeePage || 1;
    var list = document.getElementById('bulk-assign-employees-list');
    if (list && !list.querySelector('.bulk-assign-emp-card')) {
      list.innerHTML = '<div class="bulk-assign-skeleton">Loading employees…</div>';
    }
    var params = new URLSearchParams({
      page: String(bulkAssignmentState.employeePage),
      per_page: '25',
      search: (document.getElementById('bulk-assign-employee-search') || {}).value || '',
    });
    apiFetch('/lead-assignments/bulk/employees?' + params.toString())
      .then(function (body) {
        var data = body.data || {};
        bulkAssignmentState.employeeItems = data.items || [];
        bulkAssignmentState.employeePagination = data.pagination || {};
        var listEl = document.getElementById('bulk-assign-employees-list');
        if (!listEl) return;
        if (!bulkAssignmentState.employeeItems.length) {
          listEl.innerHTML = '<div class="bulk-assign-empty">No employees found.</div>';
        } else {
          listEl.innerHTML = bulkAssignmentState.employeeItems.map(bulkAssignEmployeeCardHtml).join('');
        }
        bulkAssignRenderPagination('bulk-assign-employees-pagination', bulkAssignmentState.employeePagination, 'employees');
        bindBulkAssignEmployeeCards(listEl);
      })
      .catch(function (err) {
        var listEl = document.getElementById('bulk-assign-employees-list');
        if (listEl) listEl.innerHTML = '<div class="bulk-assign-empty text-rose-600">' + escapeHtml(err.message || 'Failed to load employees') + '</div>';
      })
      .finally(function () {
        bulkAssignmentState.employeesLoading = false;
      });
  }

  function bindBulkAssignEmployeeCards(root) {
    if (!root) return;
    root.querySelectorAll('.bulk-assign-emp-check').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var id = String(cb.dataset.employeeId);
        if (cb.checked) {
          bulkAssignmentState.selectedEmployeeIds[id] = true;
        } else {
          delete bulkAssignmentState.selectedEmployeeIds[id];
        }
        cb.closest('.bulk-assign-emp-card')?.classList.toggle('is-selected', cb.checked);
        bulkAssignUpdateCounts();
        bulkAssignInvalidatePreview();
      });
    });
    root.querySelectorAll('.bulk-assign-emp-card:not(.bulk-assign-emp-disabled)').forEach(function (card) {
      card.addEventListener('click', function (e) {
        if (e.target.classList.contains('bulk-assign-emp-check')) return;
        var cb = card.querySelector('.bulk-assign-emp-check');
        if (!cb || cb.disabled) return;
        cb.checked = !cb.checked;
        cb.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  }

  function populateBulkAssignFilters() {
    ensureFormSelectData(function () {
      var wrap = document.querySelector('#bulk-assignment-panel .bulk-assign-card');
      if (wrap && window.CA_STATE_CITY) {
        window.CA_STATE_CITY.initAllPairs(wrap);
      }
      var sourceSel = document.getElementById('bulk-assign-batch-source');
      if (sourceSel && sourceSel.options.length <= 1 && window.realSources) {
        sourceSel.innerHTML = '<option value="">Any source</option>' +
          window.realSources.map(function (s) {
            return '<option value="' + s.source_id + '">' + escapeHtml(s.source_name) + '</option>';
          }).join('');
      }
    });
  }

  function initBulkAssignmentPanel() {
    if (document.getElementById('bulk-assignment-panel')?._bulkAssignInit) {
      loadBulkAssignBatches(1);
      loadBulkAssignEmployees(1);
      bulkAssignUpdateCounts();
      icons();
      return;
    }
    var panel = document.getElementById('bulk-assignment-panel');
    if (!panel) return;
    panel._bulkAssignInit = true;

    populateBulkAssignFilters();
    loadBulkAssignBatches(1);
    loadBulkAssignEmployees(1);
    bulkAssignUpdateCounts();

    var previewBtn = document.getElementById('bulk-assign-preview-btn');
    var confirmBtn = document.getElementById('bulk-assign-confirm-btn');
    if (previewBtn && !previewBtn._bulkAssignBound) {
      previewBtn._bulkAssignBound = true;
      previewBtn.addEventListener('click', function () { runBulkAssignment(true); });
    }
    if (confirmBtn && !confirmBtn._bulkAssignBound) {
      confirmBtn._bulkAssignBound = true;
      confirmBtn.addEventListener('click', function () { handleBulkAssignConfirmClick(); });
    }

    var confirmYes = document.getElementById('bulk-assign-confirm-yes');
    if (confirmYes && !confirmYes._bulkAssignBound) {
      confirmYes._bulkAssignBound = true;
      confirmYes.addEventListener('click', function () {
        closeBulkAssignConfirmModal();
        runBulkAssignment(false);
      });
    }

    panel.querySelectorAll('[data-close-bulk-assign-modal]').forEach(function (el) {
      el.addEventListener('click', closeBulkAssignConfirmModal);
    });

    var modeSel = document.getElementById('bulk-assign-mode');
    if (modeSel && !modeSel._bulkAssignBound) {
      modeSel._bulkAssignBound = true;
      modeSel.addEventListener('change', function () {
        var reasonSel = document.getElementById('bulk-assign-reason');
        var map = {
          manual: 'MANUAL_ASSIGN',
          round_robin: 'ROUND_ROBIN',
          workload_balance: 'WORKLOAD_BALANCE',
          city_match: 'CITY_MATCH',
          state_match: 'STATE_MATCH',
        };
        if (reasonSel && map[modeSel.value]) reasonSel.value = map[modeSel.value];
        if (modeSel.value === 'manual' && bulkAssignSelectedEmployeeIds().length > 1) {
          modeSel.value = 'round_robin';
          if (reasonSel) reasonSel.value = 'ROUND_ROBIN';
          toast('Manual mode requires one employee. Switched to Round Robin.', 'info');
        }
        bulkAssignInvalidatePreview();
      });
    }

    var empSearch = document.getElementById('bulk-assign-employee-search');
    if (empSearch && !empSearch._bulkAssignBound) {
      empSearch._bulkAssignBound = true;
      empSearch.addEventListener('input', function () {
        clearTimeout(bulkAssignmentState.employeeSearchTimer);
        bulkAssignmentState.employeeSearchTimer = setTimeout(function () { loadBulkAssignEmployees(1); }, 320);
      });
    }

    ['bulk-assign-batch-state', 'bulk-assign-batch-city', 'bulk-assign-batch-source', 'bulk-assign-batch-assignment'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el && !el._bulkAssignBound) {
        el._bulkAssignBound = true;
        el.addEventListener('change', function () {
          loadBulkAssignBatches(1);
          bulkAssignInvalidatePreview();
        });
      }
    });

    var clearBatch = document.getElementById('bulk-assign-batch-clear');
    if (clearBatch && !clearBatch._bulkAssignBound) {
      clearBatch._bulkAssignBound = true;
      clearBatch.addEventListener('click', bulkAssignClearBatchSelection);
    }

    var clearEmployees = document.getElementById('bulk-assign-employees-clear');
    if (clearEmployees && !clearEmployees._bulkAssignBound) {
      clearEmployees._bulkAssignBound = true;
      clearEmployees.addEventListener('click', function () {
        bulkAssignmentState.selectedEmployeeIds = {};
        loadBulkAssignEmployees(bulkAssignmentState.employeePage);
        bulkAssignUpdateCounts();
        bulkAssignInvalidatePreview();
      });
    }

    panel.addEventListener('click', function (e) {
      var pageBtn = e.target.closest('.bulk-assign-page-btn');
      if (!pageBtn || pageBtn.disabled) return;
      var type = pageBtn.dataset.type;
      var page = parseInt(pageBtn.dataset.page, 10);
      if (type === 'batches') loadBulkAssignBatches(page);
      if (type === 'employees') loadBulkAssignEmployees(page);
    });

    icons();
  }

  function populateBulkAssignmentSelects() {
    initBulkAssignmentPanel();
  }

  function getBulkAssignmentPayload() {
    var modeSel = document.getElementById('bulk-assign-mode');
    var reasonSel = document.getElementById('bulk-assign-reason');
    var employeeIds = bulkAssignSelectedEmployeeIds().map(function (id) { return parseInt(id, 10); }).filter(function (n) { return !isNaN(n); });
    var assignmentMode = bulkAssignResolveAssignmentMode(modeSel ? modeSel.value : 'round_robin', employeeIds.length);
    var reasonMap = {
      manual: 'MANUAL_ASSIGN',
      round_robin: 'ROUND_ROBIN',
      workload_balance: 'WORKLOAD_BALANCE',
      city_match: 'CITY_MATCH',
      state_match: 'STATE_MATCH',
    };
    var filters = bulkAssignBatchFilterParams();
    var payload = {
      bulk_action_id: bulkAssignmentState.selectedBatchId ? parseInt(bulkAssignmentState.selectedBatchId, 10) : null,
      employee_ids: employeeIds,
      assignment_mode: assignmentMode,
      reason: reasonSel ? reasonSel.value : (reasonMap[assignmentMode] || 'ROUND_ROBIN'),
    };
    if (filters.state_id) payload.state_id = parseInt(filters.state_id, 10);
    if (filters.city_id) payload.city_id = parseInt(filters.city_id, 10);
    if (filters.source_id) payload.source_id = parseInt(filters.source_id, 10);
    if (filters.assignment) payload.assignment = filters.assignment;
    return payload;
  }

  function renderBulkAssignmentPreview(summary) {
    var wrap = document.getElementById('bulk-assign-preview-wrap');
    var el = document.getElementById('bulk-assignment-preview-table');
    if (wrap) wrap.classList.remove('hidden');
    if (!el) return;
    var rows = (summary && summary.assignments) ? summary.assignments : [];
    el.innerHTML = rows.length ? rows.map(function (row) {
      var statusClass = row.status === 'failed' ? 'bulk-preview-failed' : (row.status === 'duplicate' ? 'bulk-preview-dup' : 'bulk-preview-ok');
      var changed = row.previous_employee_id && row.employee_id && String(row.previous_employee_id) !== String(row.employee_id);
      return '<tr class="ca-table-row ' + statusClass + (changed ? ' bulk-preview-changed' : '') + '">' +
        '<td>' + escapeHtml(row.firm_name || '—') + '</td>' +
        '<td>' + escapeHtml(row.previous_employee_name || 'Unassigned') + '</td>' +
        '<td>' + escapeHtml(row.employee_name || 'Unassigned') + '</td>' +
        '<td>' + escapeHtml(bulkAssignModeLabel(row.assignment_mode)) + '</td>' +
        '<td>' + escapeHtml(bulkAssignReasonLabel(row.reason)) + '</td>' +
        '<td><span class="badge ' + (row.status === 'failed' ? 'badge-danger' : (row.status === 'duplicate' ? 'badge-brand' : 'badge-success')) + '">' +
          escapeHtml(row.status || '—') + (row.message ? ' · ' + escapeHtml(row.message) : '') + '</td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="6" class="text-center text-slate-500 p-4">No assignments to preview</td></tr>';
  }

  function bulkAssignPendingCount(summary) {
    if (!summary || !summary.assignments) return 0;
    return summary.assignments.filter(function (row) {
      return row.status === 'preview' || row.status === 'pending';
    }).length;
  }

  function bulkAssignInvalidatePreview() {
    bulkAssignmentState.previewSummary = null;
    var wrap = document.getElementById('bulk-assign-preview-wrap');
    if (wrap) wrap.classList.add('hidden');
  }

  function bulkAssignValidatePayload() {
    var payload = getBulkAssignmentPayload();
    if (!payload.bulk_action_id) {
      toast('Please select an import batch.', 'warning');
      return null;
    }
    if (!bulkAssignMatchingLeadCount()) {
      toast('No leads match the selected batch and filters.', 'warning');
      return null;
    }
    if (!payload.employee_ids.length) {
      toast('Please select at least one employee.', 'warning');
      return null;
    }
    return payload;
  }

  function handleBulkAssignConfirmClick() {
    var payload = bulkAssignValidatePayload();
    if (!payload) return;
    if (!bulkAssignmentState.previewSummary) {
      toast('Please preview assignments before confirming.', 'warning');
      return;
    }
    var pending = bulkAssignPendingCount(bulkAssignmentState.previewSummary);
    if (pending <= 0) {
      toast('Nothing to assign. Run preview again — leads may be duplicates or have no matching employee.', 'warning');
      return;
    }
    openBulkAssignConfirmModal();
  }
  function openBulkAssignConfirmModal() {
    var payload = getBulkAssignmentPayload();
    var summary = bulkAssignmentState.previewSummary;
    var assignCount = bulkAssignPendingCount(summary);
    var text = document.getElementById('bulk-assign-confirm-text');
    if (text) {
      var batchName = bulkAssignmentState.selectedBatch ? (bulkAssignmentState.selectedBatch.batch_name || bulkAssignmentState.selectedBatch.file_name) : 'batch';
      text.innerHTML = 'Assign <strong>' + assignCount + '</strong> lead' + (assignCount === 1 ? '' : 's') + ' from <strong>' + escapeHtml(batchName || 'batch') + '</strong>?<br><br>' +
        'Mode: <strong>' + escapeHtml(bulkAssignModeLabel(payload.assignment_mode)) + '</strong><br>' +
        'Employees: <strong>' + payload.employee_ids.length + '</strong>';
    }
    var modal = document.getElementById('bulk-assign-confirm-modal');
    if (modal) openModal(modal);
  }

  function closeBulkAssignConfirmModal() {
    var modal = document.getElementById('bulk-assign-confirm-modal');
    if (modal) closeModal(modal);
  }

  function setBulkAssignLoading(on) {
    var el = document.getElementById('bulk-assign-loading');
    if (el) el.classList.toggle('hidden', !on);
    bulkAssignUpdateActionButtons();
  }

  function runBulkAssignment(preview) {
    var payload = bulkAssignValidatePayload();
    if (!payload) return;
    payload.preview = preview;
    setBulkAssignLoading(true);

    apiFetch('/lead-assignments/bulk', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(function (body) {
        var summary = body.data || {};
        renderBulkAssignmentPreview(summary);
        if (preview) {
          bulkAssignmentState.previewSummary = summary;
          toast(body.message || 'Preview ready', 'info');
        } else {
          bulkAssignmentState.previewSummary = null;
          var total = (summary.assigned_rows || 0) + (summary.reassigned_rows || 0);
          toast(total + ' leads assigned successfully', 'success');
          bulkAssignClearBatchSelection();
          bulkAssignmentState.selectedEmployeeIds = {};
          bulkAssignUpdateCounts();
          realAssignmentsLoaded = false;
          realLeadsLoaded = false;
          invalidateDataCaches(['metrics', 'leads', 'assignments', 'employee_dashboard']);
          loadBulkAssignBatches(1);
          loadBulkAssignEmployees(1);
          refreshAll();
        }
      })
      .catch(function (error) {
        toast(error.message || 'Bulk assignment failed', 'error');
      })
      .finally(function () {
        setBulkAssignLoading(false);
      });
  }

  var bulkImportWizardState = {
    step: 1,
    sessionId: null,
    fileName: '',
    totalRows: 0,
    fileSizeLabel: '',
    headers: [],
    hasMobileColumn: false,
    crmFields: [],
    mapping: {},
    validation: null,
    lastBulkActionId: null,
  };

  function initBulkImportWizard() {
    if (window._bulkImportWizardBound) return;
    window._bulkImportWizardBound = true;

    var wizard = document.getElementById('bulk-import-wizard');
    var zone = document.getElementById('bulk-upload-zone');
    var fileInput = document.getElementById('bulk-import-file');
    if (!wizard || !zone || !fileInput) return;

    var wizardSubtitle = wizard.querySelector('h3 + p');
    if (wizardSubtitle) wizardSubtitle.remove();

    function csrfToken() {
      return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    function setWizardStep(step) {
      bulkImportWizardState.step = step;
      document.querySelectorAll('.bulk-wizard-step').forEach(function (el) {
        el.classList.toggle('active', parseInt(el.dataset.step, 10) === step);
        el.classList.toggle('done', parseInt(el.dataset.step, 10) < step);
      });
      for (var i = 1; i <= 4; i++) {
        var panel = document.getElementById('bulk-wizard-panel-' + i);
        if (panel) panel.classList.toggle('hidden', i !== step);
      }
      var backBtn = document.getElementById('bulk-wizard-back-btn');
      var nextBtn = document.getElementById('bulk-wizard-next-btn');
      var importBtn = document.getElementById('bulk-wizard-import-btn');
      if (backBtn) backBtn.disabled = step === 1 || step === 4;
      if (nextBtn) {
        nextBtn.classList.toggle('hidden', step === 3 || step === 4);
        nextBtn.disabled = (step === 1 && !bulkImportWizardState.sessionId) || step === 4;
        nextBtn.textContent = step === 2 ? 'Validate Data' : 'Next';
      }
      if (importBtn) importBtn.classList.toggle('hidden', step !== 3);
      icons();
    }

    function resetWizard() {
      bulkImportWizardState = {
        step: 1,
        sessionId: null,
        fileName: '',
        totalRows: 0,
        fileSizeLabel: '',
        headers: [],
        hasMobileColumn: false,
        crmFields: [],
        mapping: {},
        validation: null,
        lastBulkActionId: null,
      };
      fileInput.value = '';
      document.getElementById('bulk-upload-meta')?.classList.add('hidden');
      document.getElementById('bulk-mapping-table').innerHTML = '';
      document.getElementById('bulk-validation-table').innerHTML = '';
      document.getElementById('bulk-import-summary').innerHTML = '';
      document.getElementById('bulk-validation-downloads')?.classList.add('hidden');
      document.getElementById('bulk-import-summary-downloads')?.classList.add('hidden');
      setWizardStep(1);
    }

    function triggerReupload() {
      resetWizard();
      wizard.classList.remove('hidden');
      setWizardStep(1);
      toast('Upload your corrected CSV/Excel file to start a new import', 'info');
      fileInput.click();
    }

    function showUploadMeta(data) {
      document.getElementById('bulk-upload-meta')?.classList.remove('hidden');
      setText('bulk-file-name', data.file_name || '—');
      setText('bulk-file-rows', String(data.total_rows || 0));
      setText('bulk-file-size', data.file_size_label || '—');
    }

    function renderMappingTable() {
      var tbody = document.getElementById('bulk-mapping-table');
      if (!tbody) return;
      var headers = bulkImportWizardState.headers || [];
      var options = '<option value="">— Ignore —</option>' +
        headers.map(function (h) {
          return '<option value="' + escapeHtml(h) + '">' + escapeHtml(h) + '</option>';
        }).join('');
      tbody.innerHTML = (bulkImportWizardState.crmFields || []).map(function (field) {
        var selected = bulkImportWizardState.mapping[field.key] || '';
        return '<tr><td>' + escapeHtml(field.label) + '</td>' +
          '<td>' + (field.required ? '<span class="badge-danger">Required</span>' : '<span class="badge-brand">Optional</span>') + '</td>' +
          '<td><select class="input-field bulk-mapping-select" data-field="' + field.key + '">' + options + '</select></td></tr>';
      }).join('');
      tbody.querySelectorAll('.bulk-mapping-select').forEach(function (sel) {
        if (bulkImportWizardState.mapping[sel.dataset.field]) {
          sel.value = bulkImportWizardState.mapping[sel.dataset.field];
        }
        sel.addEventListener('change', collectMappingFromForm);
      });
    }

    function collectMappingFromForm() {
      var mapping = {};
      document.querySelectorAll('.bulk-mapping-select').forEach(function (sel) {
        if (sel.value) mapping[sel.dataset.field] = sel.value;
      });
      bulkImportWizardState.mapping = mapping;
      return mapping;
    }

    function validateRequiredMappings() {
      collectMappingFromForm();
      var missing = (bulkImportWizardState.crmFields || [])
        .filter(function (field) { return field.required; })
        .filter(function (field) { return !bulkImportWizardState.mapping[field.key]; })
        .map(function (field) { return field.label; });
      if (missing.length) {
        toast('Map required fields: ' + missing.join(', '), 'warning');
        return false;
      }
      return true;
    }

    function populateTemplateSelect(templates) {
      var sel = document.getElementById('bulk-mapping-template-select');
      if (!sel) return;
      var current = sel.value;
      sel.innerHTML = '<option value="">Auto-detect mapping</option>' +
        (templates || []).map(function (tpl, idx) {
          return '<option value="' + idx + '">' + escapeHtml(tpl.template_name) + '</option>';
        }).join('');
      if (current) sel.value = current;
      sel.onchange = function () {
        var idx = sel.value;
        if (idx === '') return;
        var tpl = (templates || [])[parseInt(idx, 10)];
        if (tpl && tpl.field_mapping) {
          bulkImportWizardState.mapping = tpl.field_mapping;
          renderMappingTable();
        }
      };
    }

    function uploadFile(file) {
      if (!file) return;
      var name = (file.name || '').toLowerCase();
      if (!name.endsWith('.csv') && !name.endsWith('.xlsx')) {
        toast('Please upload a CSV or Excel (.xlsx) file', 'warning');
        return;
      }
      var fd = new FormData();
      fd.append('file', file);
      toast('Parsing ' + file.name + '…', 'info');
      fetch('/ca-masters/bulk-import/parse', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken(),
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: fd,
      })
        .then(function (response) {
          return response.json().then(function (body) {
            if (!response.ok) throw new Error(body.message || 'Parse failed');
            return body.data || {};
          });
        })
        .then(function (data) {
          bulkImportWizardState.sessionId = data.session_id;
          bulkImportWizardState.fileName = data.file_name;
          bulkImportWizardState.totalRows = data.total_rows;
          bulkImportWizardState.fileSizeLabel = data.file_size_label;
          bulkImportWizardState.headers = data.headers || [];
          bulkImportWizardState.hasMobileColumn = !!data.has_mobile_column;
          bulkImportWizardState.crmFields = data.crm_fields || [];
          bulkImportWizardState.mapping = data.suggested_mapping || {};
          showUploadMeta(data);
          renderMappingTable();
          populateTemplateSelect(data.saved_templates || []);
          document.getElementById('bulk-wizard-next-btn').disabled = false;
          toast('File ready — ' + data.total_rows + ' rows detected', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Failed to parse file', 'error');
        })
        .finally(function () {
          fileInput.value = '';
        });
    }

    function runValidation() {
      if (!bulkImportWizardState.sessionId) {
        toast('Upload a CSV or Excel file before validating.', 'warning');
        return Promise.resolve();
      }
      if (!validateRequiredMappings()) {
        return Promise.resolve();
      }
      var mapping = bulkImportWizardState.mapping;
      return fetch('/ca-masters/bulk-import/validate', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken(),
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          session_id: bulkImportWizardState.sessionId,
          mapping: mapping,
        }),
      })
        .then(function (response) {
          return response.json().then(function (body) {
            if (!response.ok) {
              var message = body.message || 'Validation failed';
              if (response.status === 422 && body.errors) {
                var firstKey = Object.keys(body.errors)[0];
                if (firstKey && body.errors[firstKey] && body.errors[firstKey][0]) {
                  message = body.errors[firstKey][0];
                }
                if (String(message).toLowerCase().indexOf('session') >= 0) {
                  bulkImportWizardState.sessionId = null;
                  setWizardStep(1);
                }
              }
              throw new Error(message);
            }
            return body.data || {};
          });
        })
        .then(function (data) {
          bulkImportWizardState.validation = data;
          bulkImportWizardState.hasMobileColumn = !!data.has_mobile_column;
          if (data.crm_fields) bulkImportWizardState.crmFields = data.crm_fields;
          setText('bulk-valid-count', String(data.valid_rows || 0));
          setText('bulk-invalid-count', String(data.invalid_rows || 0));
          setText('bulk-duplicate-count', String(data.duplicate_rows || 0));
          renderValidationPreview(data.preview_rows || []);
          var errorCount = (data.error_row_count || 0);
          document.getElementById('bulk-validation-downloads')?.classList.toggle('hidden', errorCount === 0);
          setWizardStep(3);
          toast('Validation complete — ' + data.valid_rows + ' valid rows', data.invalid_rows > 0 ? 'warning' : 'success');
        });
    }

    function renderValidationPreview(rows) {
      var tbody = document.getElementById('bulk-validation-table');
      if (!tbody) return;
      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-slate-400 py-8">No rows to preview</td></tr>';
        return;
      }
      tbody.innerHTML = rows.map(function (row) {
        var data = row.data || {};
        var status = row.status || 'invalid';
        var statusBadge = status === 'valid'
          ? '<span class="badge-success">Valid</span>'
          : (status === 'duplicate' ? '<span class="badge-brand">Duplicate</span>' : '<span class="badge-danger">Invalid</span>');
        var issues = (row.errors || []).join('; ') || '—';
        var fieldErrors = row.field_errors || {};
        var cells = ['ca_name', 'firm_name', 'mobile_no', 'email_id', 'gst_no', 'state_id', 'city_id'].map(function (key) {
          var cls = fieldErrors[key] ? 'text-rose-600 font-medium' : '';
          return '<td class="' + cls + '">' + escapeHtml(data[key] || '—') + '</td>';
        }).join('');
        return '<tr class="' + (status === 'valid' ? '' : 'bg-rose-50/60') + '">' +
          '<td>' + row.row_number + '</td><td>' + statusBadge + '</td>' + cells +
          '<td class="max-w-xs truncate" title="' + escapeHtml(issues) + '">' + escapeHtml(issues) + '</td></tr>';
      }).join('');
    }

    function runImport() {
      var mapping = collectMappingFromForm();
      var templateName = document.getElementById('bulk-mapping-template-name')?.value.trim() || '';
      var saveTemplate = templateName.length > 0;
      toast('Importing valid rows…', 'info');
      return fetch('/ca-masters/bulk-import', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken(),
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          session_id: bulkImportWizardState.sessionId,
          mapping: mapping,
          save_template: saveTemplate,
          template_name: templateName,
        }),
      })
        .then(function (response) {
          return response.json().then(function (body) {
            if (!response.ok) throw new Error(body.message || 'Import failed');
            return { body: body, summary: body.data || {} };
          });
        })
        .then(function (result) {
          var summary = result.summary;
          bulkImportWizardState.lastBulkActionId = summary.bulk_action_id || null;
          renderImportSummaryPanel(summary);
          renderBulkImportSummary(summary, false);
          loadBulkImportHistory();
          realLeadsLoaded = false;
          refreshAll();
          var hasErrors = (summary.error_row_count || 0) > 0 || (summary.failed_rows || 0) > 0;
          document.getElementById('bulk-import-summary-downloads')?.classList.toggle('hidden', !hasErrors);
          setWizardStep(4);
          toast(result.body.message || 'Import completed', summary.failed_rows > 0 ? 'warning' : 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Bulk import failed', 'error');
        });
    }

    function renderImportSummaryPanel(summary) {
      var el = document.getElementById('bulk-import-summary');
      if (!el) return;
      var cards = [
        { label: 'Total Rows', value: summary.total_rows || 0, cls: 'text-slate-900' },
        { label: 'Inserted', value: summary.inserted_rows || 0, cls: 'text-emerald-600' },
        { label: 'Duplicates Skipped', value: summary.duplicate_rows || 0, cls: 'text-amber-600' },
        { label: 'Failed / Invalid', value: summary.failed_rows || 0, cls: 'text-rose-600' },
      ];
      el.innerHTML = cards.map(function (card) {
        return '<div class="card p-5"><p class="text-caption text-slate-500">' + card.label + '</p>' +
          '<p class="text-2xl font-semibold ' + card.cls + '">' + card.value + '</p></div>';
      }).join('') +
        '<div class="card p-5 sm:col-span-2 lg:col-span-4"><p class="text-caption text-slate-500">Import Reference</p>' +
        '<p class="font-medium">' + (summary.bulk_action_id || '—') + ' · ' + escapeHtml(summary.file_name || '—') + '</p></div>';
    }

    zone.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
      if (fileInput.files && fileInput.files[0]) uploadFile(fileInput.files[0]);
    });
    zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', function () { zone.classList.remove('drag-over'); });
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      zone.classList.remove('drag-over');
      var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      uploadFile(file);
    });

    document.getElementById('bulk-reupload-btn')?.addEventListener('click', function () {
      resetWizard();
      fileInput.click();
    });
    document.getElementById('bulk-wizard-back-btn')?.addEventListener('click', function () {
      if (bulkImportWizardState.step > 1 && bulkImportWizardState.step < 4) {
        setWizardStep(bulkImportWizardState.step - 1);
      }
    });
    document.getElementById('bulk-wizard-cancel-btn')?.addEventListener('click', function () {
      wizard.classList.add('hidden');
      resetWizard();
    });
    document.getElementById('bulk-wizard-next-btn')?.addEventListener('click', function () {
      if (bulkImportWizardState.step === 1) {
        setWizardStep(2);
        return;
      }
      if (bulkImportWizardState.step === 2) {
        if (!bulkImportWizardState.sessionId) {
          toast('Upload a file before validating.', 'warning');
          return;
        }
        runValidation().catch(function (error) {
          toast(error.message || 'Validation failed', 'error');
        });
      }
    });
    document.getElementById('bulk-wizard-import-btn')?.addEventListener('click', function () {
      runImport();
    });

    window.openBulkImportWizard = function () {
      wizard.classList.remove('hidden');
      setWizardStep(bulkImportWizardState.sessionId ? bulkImportWizardState.step : 1);
      icons();
    };

    window.resetBulkImportWizard = resetWizard;

    document.getElementById('bulk-download-validation-errors')?.addEventListener('click', function () {
      if (!bulkImportWizardState.sessionId) return;
      window.location.href = '/ca-masters/bulk-import/session/' + encodeURIComponent(bulkImportWizardState.sessionId) + '/error-report.csv';
    });
    document.getElementById('bulk-download-validation-reimport')?.addEventListener('click', function () {
      if (!bulkImportWizardState.sessionId) return;
      window.location.href = '/ca-masters/bulk-import/session/' + encodeURIComponent(bulkImportWizardState.sessionId) + '/reimport-template.csv';
    });
    document.getElementById('bulk-download-import-errors')?.addEventListener('click', function () {
      if (!bulkImportWizardState.lastBulkActionId) return;
      window.location.href = '/ca-masters/bulk-import/history/' + encodeURIComponent(bulkImportWizardState.lastBulkActionId) + '/error-report.csv';
    });
    document.getElementById('bulk-download-import-reimport')?.addEventListener('click', function () {
      if (!bulkImportWizardState.lastBulkActionId) return;
      window.location.href = '/ca-masters/bulk-import/history/' + encodeURIComponent(bulkImportWizardState.lastBulkActionId) + '/reimport-template.csv';
    });
    document.getElementById('bulk-start-reimport-btn')?.addEventListener('click', triggerReupload);

    document.querySelectorAll('[data-close-bulk-import-detail]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeModal(document.getElementById('modal-bulk-import-detail'));
      });
    });

    setWizardStep(1);
  }

  var bulkImportDetailState = { bulkActionId: null };
  var bulkExportState = {
    columns: [],
    lastBulkActionId: null,
    pollTimer: null,
  };

  function bulkExportPayload() {
    var scopeEl = document.getElementById('bulk-export-scope');
    var formatEl = document.getElementById('bulk-export-format');
    var scope = scopeEl ? scopeEl.value : 'all';
    var format = formatEl ? formatEl.value : 'csv';
    var payload = {
      scope: scope,
      format: format,
      columns: bulkExportState.columns.filter(function (key) {
        var input = document.querySelector('.bulk-export-column-check[value="' + key + '"]');
        return input ? input.checked : true;
      }),
    };

    if (scope === 'selected') {
      var sel = document.getElementById('bulk-export-selected-ids');
      payload.ca_ids = sel ? Array.from(sel.selectedOptions).map(function (o) { return parseInt(o.value, 10); }).filter(Boolean) : [];
    }

    if (scope === 'filtered') {
      payload.filters = {
        status: document.getElementById('bulk-export-filter-status')?.value || '',
        state_id: document.getElementById('bulk-export-filter-state')?.value || '',
        city_id: document.getElementById('bulk-export-filter-city')?.value || '',
        source_id: document.getElementById('bulk-export-filter-source')?.value || '',
        is_newly_established: document.getElementById('bulk-export-filter-new')?.value || '',
        search: document.getElementById('bulk-export-filter-search')?.value || '',
      };
    }

    return payload;
  }

  function toggleBulkExportScopePanels() {
    var scope = document.getElementById('bulk-export-scope')?.value || 'all';
    document.getElementById('bulk-export-selected-wrap')?.classList.toggle('hidden', scope !== 'selected');
    document.getElementById('bulk-export-filters-wrap')?.classList.toggle('hidden', scope !== 'filtered');
  }

  function populateBulkExportSelects() {
    ensureFormSelectData(function () {
      var leads = window.realLeads || [];
      var leadSel = document.getElementById('bulk-export-selected-ids');
      if (leadSel) {
        leadSel.innerHTML = leads.map(function (l) {
          return '<option value="' + l.ca_id + '">' + escapeHtml(l.firm_name || l.ca_name) + ' · ' + escapeHtml(l.city || '—') + '</option>';
        }).join('');
      }

      var stateSel = document.getElementById('bulk-export-filter-state');
      if (stateSel && window.CA_STATE_CITY) {
        window.CA_STATE_CITY.initAllPairs(document.getElementById('bulk-export-filters-wrap'));
      }

      var sourceSel = document.getElementById('bulk-export-filter-source');
      if (sourceSel && window.realSources) {
        sourceSel.innerHTML = '<option value="">Any</option>' + window.realSources.map(function (s) {
          return '<option value="' + s.source_id + '">' + escapeHtml(s.source_name) + '</option>';
        }).join('');
      }
    });
  }

  function renderBulkExportColumns(columns) {
    var wrap = document.getElementById('bulk-export-columns');
    if (!wrap) return;
    bulkExportState.columns = columns.map(function (c) { return c.key; });
    wrap.innerHTML = columns.map(function (col) {
      return '<label class="inline-flex items-center gap-2 rounded-full border border-slate-200 px-3 py-1.5 text-caption">' +
        '<input type="checkbox" class="bulk-export-column-check" value="' + col.key + '" checked />' +
        '<span>' + escapeHtml(col.label) + '</span></label>';
    }).join('');
  }

  function setBulkExportProgress(percent, label) {
    var wrap = document.getElementById('bulk-export-progress-wrap');
    var bar = document.getElementById('bulk-export-progress-bar');
    var text = document.getElementById('bulk-export-progress-label');
    if (wrap) wrap.classList.remove('hidden');
    if (bar) bar.style.width = Math.max(0, Math.min(100, percent)) + '%';
    if (text) text.textContent = label || (percent + '%');
  }

  function clearBulkExportPoll() {
    if (bulkExportState.pollTimer) {
      clearInterval(bulkExportState.pollTimer);
      bulkExportState.pollTimer = null;
    }
  }

  function pollBulkExportStatus(bulkActionId) {
    clearBulkExportPoll();
    bulkExportState.lastBulkActionId = bulkActionId;
    var downloadBtn = document.getElementById('bulk-export-download-btn');
    if (downloadBtn) downloadBtn.classList.add('hidden');

    bulkExportState.pollTimer = setInterval(function () {
      apiFetch('/ca-masters/bulk-export/history/' + encodeURIComponent(bulkActionId) + '/status')
        .then(function (body) {
          var data = body.data || {};
          setBulkExportProgress(data.progress_percent || 0, (data.progress_percent || 0) + '% · ' + (data.status || 'Processing'));
          if (data.status === 'Completed' && data.download_ready) {
            clearBulkExportPoll();
            setBulkExportProgress(100, 'Completed');
            if (downloadBtn) downloadBtn.classList.remove('hidden');
            toast('Export completed — ready to download', 'success');
            loadBulkOperationsHistory();
          }
          if (data.status === 'Failed') {
            clearBulkExportPoll();
            toast('Export failed', 'error');
            loadBulkOperationsHistory();
          }
        })
        .catch(function () {
          clearBulkExportPoll();
        });
    }, 1500);
  }

  function previewBulkExport() {
    apiFetch('/ca-masters/bulk-export/preview', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(bulkExportPayload()),
    })
      .then(function (body) {
        var data = body.data || {};
        document.getElementById('bulk-export-preview-meta')?.classList.remove('hidden');
        setText('bulk-export-preview-count', String(data.total_rows || 0));
        setText('bulk-export-preview-bg', data.uses_background ? 'Yes (queued)' : 'No (immediate)');
        setText('bulk-export-preview-format', (data.format || 'csv').toUpperCase());
        toast(body.message || 'Export preview ready', 'info');
      })
      .catch(function (error) {
        toast(error.message || 'Export preview failed', 'error');
      });
  }

  function runBulkExport() {
    var payload = bulkExportPayload();
    setBulkExportProgress(0, 'Starting…');
    document.getElementById('bulk-export-download-btn')?.classList.add('hidden');

    apiFetch('/ca-masters/bulk-export', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(function (body) {
        var data = body.data || {};
        bulkExportState.lastBulkActionId = data.bulk_action_id;
        toast(body.message || 'Export started', data.uses_background ? 'info' : 'success');
        if (data.uses_background) {
          pollBulkExportStatus(data.bulk_action_id);
        } else {
          setBulkExportProgress(100, 'Completed');
          document.getElementById('bulk-export-download-btn')?.classList.remove('hidden');
          loadBulkOperationsHistory();
        }
      })
      .catch(function (error) {
        toast(error.message || 'Export failed', 'error');
      });
  }

  function initBulkExportPanel() {
    if (window._bulkExportPanelBound) return;
    window._bulkExportPanelBound = true;

    populateBulkExportSelects();
    toggleBulkExportScopePanels();

    apiFetch('/ca-masters/bulk-export/columns')
      .then(function (body) {
        renderBulkExportColumns((body.data && body.data.columns) || []);
      })
      .catch(function () {
        renderBulkExportColumns([]);
      });

    document.getElementById('bulk-export-scope')?.addEventListener('change', toggleBulkExportScopePanels);

    document.getElementById('bulk-export-preview-btn')?.addEventListener('click', previewBulkExport);
    document.getElementById('bulk-export-run-btn')?.addEventListener('click', runBulkExport);
    document.getElementById('bulk-export-download-btn')?.addEventListener('click', function () {
      if (!bulkExportState.lastBulkActionId) return;
      window.location.href = '/ca-masters/bulk-export/history/' + encodeURIComponent(bulkExportState.lastBulkActionId) + '/download';
    });
  }

  window.openBulkExportPanel = function () {
    var panel = document.getElementById('bulk-export-panel');
    if (panel) panel.classList.remove('hidden');
    populateBulkExportSelects();
    icons();
  };

  var bulkStatusUpdateState = {
    preview: null,
  };

  function populateBulkStatusSelects() {
    ensureFormSelectData(function () {
      var leadSel = document.getElementById('bulk-status-leads');
      var statusSel = document.getElementById('bulk-status-target');
      var leads = window.realLeads || [];
      if (leadSel) {
        leadSel.innerHTML = leads.map(function (l) {
          return '<option value="' + l.ca_id + '">' + escapeHtml(l.firm_name || '—') + ' · ' + escapeHtml(l.status || '—') + ' · ' + escapeHtml(l.city || '—') + '</option>';
        }).join('');
      }
      if (statusSel && !statusSel._statusOptionsLoaded) {
        var fallbackStatuses = ['New', 'Hot', 'Warm', 'Cold', 'Pipeline', 'Demo Scheduled', 'Active', 'Inactive', 'Lost'];
        var applyStatusOptions = function (statuses) {
          statusSel.innerHTML = '<option value="">Choose status…</option>' + statuses.map(function (s) {
            return '<option value="' + escapeHtml(s) + '">' + escapeHtml(s) + '</option>';
          }).join('');
          statusSel._statusOptionsLoaded = true;
        };
        if (window.CA_RBAC && !window.CA_RBAC.can('bulk', 'edit')) {
          applyStatusOptions(fallbackStatuses);
          return;
        }
        apiFetch('/ca-masters/bulk-status-update/statuses')
          .then(function (body) {
            var statuses = (body.data && body.data.statuses) || fallbackStatuses;
            applyStatusOptions(statuses);
          })
          .catch(function () {
            applyStatusOptions(fallbackStatuses);
          });
      }
    });
  }

  function getBulkStatusUpdatePayload(preview) {
    var leadSel = document.getElementById('bulk-status-leads');
    var statusSel = document.getElementById('bulk-status-target');
    return {
      ca_ids: leadSel ? Array.from(leadSel.selectedOptions).map(function (o) { return parseInt(o.value, 10); }).filter(Boolean) : [],
      status: statusSel ? statusSel.value : '',
      preview: !!preview,
    };
  }

  function renderBulkStatusPreview(summary) {
    var el = document.getElementById('bulk-status-preview-table');
    if (!el) return;
    var rows = (summary && summary.rows) ? summary.rows : [];
    el.innerHTML = rows.length ? rows.map(function (row) {
      var result = row.result || 'ready';
      var badgeClass = result === 'skipped' ? 'badge-brand' : (result === 'failed' ? 'badge-danger' : 'badge-success');
      var label = result === 'ready' ? 'Will update' : (result === 'updated' ? 'Updated' : (result === 'skipped' ? 'Skipped' : result));
      return '<tr class="ca-table-row">' +
        '<td>' + escapeHtml(row.firm_name || '—') + '</td>' +
        '<td>' + escapeHtml(row.ca_name || '—') + '</td>' +
        '<td>' + escapeHtml(row.current_status || '—') + '</td>' +
        '<td>' + escapeHtml(row.new_status || '—') + '</td>' +
        '<td><span class="badge ' + badgeClass + '">' + escapeHtml(label) + (row.message ? ' · ' + escapeHtml(row.message) : '') + '</td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="5" class="text-center text-slate-500 p-4">No records to preview</td></tr>';

    var meta = document.getElementById('bulk-status-preview-meta');
    if (meta) meta.classList.toggle('hidden', !rows.length);
    setText('bulk-status-preview-update', String(summary.updated_rows || 0));
    setText('bulk-status-preview-skip', String(summary.skipped_rows || 0));
    setText('bulk-status-preview-target', summary.target_status || '—');

    var applyBtn = document.getElementById('bulk-status-apply-btn');
    if (applyBtn) {
      applyBtn.disabled = !(summary.updated_rows > 0);
    }
  }

  function previewBulkStatusUpdate() {
    var payload = getBulkStatusUpdatePayload(true);
    if (!payload.ca_ids.length) {
      toast('Please select at least one record', 'warning');
      return;
    }
    if (!payload.status) {
      toast('Please choose a target status', 'warning');
      return;
    }

    apiFetch('/ca-masters/bulk-status-update', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(function (body) {
        var summary = body.data || {};
        bulkStatusUpdateState.preview = summary;
        renderBulkStatusPreview(summary);
        toast(body.message || 'Preview ready', 'info');
      })
      .catch(function (error) {
        toast(error.message || 'Preview failed', 'error');
      });
  }

  function openBulkStatusConfirmModal() {
    var summary = bulkStatusUpdateState.preview;
    if (!summary || !(summary.updated_rows > 0)) {
      toast('Preview changes before applying', 'warning');
      return;
    }
    var modal = document.getElementById('modal-bulk-status-confirm');
    var body = document.getElementById('bulk-status-confirm-body');
    if (!modal || !body) return;
    body.innerHTML =
      '<p class="text-body text-slate-700">You are about to update <strong>' + summary.updated_rows + '</strong> record(s) to status <strong>' + escapeHtml(summary.target_status || '') + '</strong>.</p>' +
      '<p class="text-caption text-slate-500">' + summary.skipped_rows + ' record(s) already at this status will be skipped. All changes run in one database transaction and roll back if any update fails.</p>';
    openModal(modal);
    icons();
  }

  function confirmBulkStatusUpdate() {
    var payload = getBulkStatusUpdatePayload(false);
    if (!payload.ca_ids.length || !payload.status) {
      toast('Invalid selection', 'warning');
      return;
    }

    var confirmBtn = document.getElementById('bulk-status-confirm-btn');
    if (confirmBtn) confirmBtn.disabled = true;

    apiFetch('/ca-masters/bulk-status-update', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(function (body) {
        var summary = body.data || {};
        bulkStatusUpdateState.preview = null;
        renderBulkStatusPreview(summary);
        toast(body.message || 'Bulk status update completed', 'success');
        document.querySelectorAll('[data-close-bulk-status-confirm]').forEach(function (btn) {
          btn.click();
        });
        var applyBtn = document.getElementById('bulk-status-apply-btn');
        if (applyBtn) applyBtn.disabled = true;
        realLeadsLoaded = false;
        refreshAll();
        loadBulkOperationsHistory();
      })
      .catch(function (error) {
        toast(error.message || 'Bulk status update failed', 'error');
      })
      .finally(function () {
        if (confirmBtn) confirmBtn.disabled = false;
      });
  }

  function initBulkStatusUpdatePanel() {
    if (window._bulkStatusPanelBound) return;
    window._bulkStatusPanelBound = true;

    populateBulkStatusSelects();

    document.getElementById('bulk-status-preview-btn')?.addEventListener('click', previewBulkStatusUpdate);
    document.getElementById('bulk-status-apply-btn')?.addEventListener('click', openBulkStatusConfirmModal);
    document.getElementById('bulk-status-confirm-btn')?.addEventListener('click', confirmBulkStatusUpdate);

    document.querySelectorAll('[data-close-bulk-status-confirm]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var modal = document.getElementById('modal-bulk-status-confirm');
        if (modal) modal.classList.remove('open');
      });
    });

    ['bulk-status-leads', 'bulk-status-target'].forEach(function (id) {
      document.getElementById(id)?.addEventListener('change', function () {
        bulkStatusUpdateState.preview = null;
        var applyBtn = document.getElementById('bulk-status-apply-btn');
        if (applyBtn) applyBtn.disabled = true;
        document.getElementById('bulk-status-preview-meta')?.classList.add('hidden');
        var el = document.getElementById('bulk-status-preview-table');
        if (el) {
          el.innerHTML = '<tr><td colspan="5" class="text-center text-slate-500 p-4">Preview changes before applying</td></tr>';
        }
      });
    });
  }

  window.openBulkStatusUpdatePanel = function () {
    var panel = document.getElementById('bulk-status-update-panel');
    if (panel) panel.classList.remove('hidden');
    populateBulkStatusSelects();
    icons();
  };

  function formatBulkImportDate(value) {
    if (!value) return '—';
    var date = new Date(value);
    if (isNaN(date.getTime())) return String(value);
    return date.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function loadBulkOperationsHistory() {
    apiFetch('/ca-masters/bulk-operations/history' + listingPageQuery('bulk_operations'))
      .then(function (body) {
        var items = unwrapList(body);
        window._bulkOperationsHistoryCache = items;
        renderBulkOperationsHistoryTable(items);
        applyListingPagination('bulk_operations', 'bulk-actions-data-table', body);
      })
      .catch(function () {
        renderBulkOperationsHistoryTable([]);
      });
  }

  function loadBulkImportHistory() {
    loadBulkOperationsHistory();
  }

  function bulkOperationLabel(item) {
    if (item.action_type === 'ca_master_export') return 'Bulk Export';
    if (item.action_type === 'ca_master_status_update') return 'Bulk Status Update';
    return 'Bulk Import';
  }

  function bulkOperationSuccess(item) {
    if (item.action_type === 'ca_master_export') return item.exported_rows ?? item.inserted_rows ?? 0;
    if (item.action_type === 'ca_master_status_update') return item.inserted_rows ?? 0;
    return item.inserted_rows || 0;
  }

  function renderBulkOperationsHistoryTable(items) {
    var el = document.getElementById('bulk-actions-data-table');
    if (!el) return;
    if (!items.length) {
      el.innerHTML = '<tr><td colspan="9" class="text-center text-slate-400 py-8">No bulk operations yet.</td></tr>';
      return;
    }
    el.innerHTML = items.map(function (item) {
      var failed = item.failed_rows || 0;
      var duplicate = item.duplicate_rows || 0;
      var statusClass = item.status === 'Failed' ? 'badge-danger'
        : (failed > 0 ? 'badge-warning' : (item.status === 'Processing' ? 'badge-brand' : (duplicate > 0 ? 'badge-brand' : 'badge-success')));
      var performer = item.exported_by || item.imported_by || 'System';
      var rowClass = item.action_type === 'ca_master_export' ? 'bulk-export-history-row'
        : (item.action_type === 'ca_master_status_update' ? 'bulk-status-history-row' : 'bulk-import-history-row');
      return '<tr class="ca-table-row ' + rowClass + ' cursor-pointer hover:bg-slate-50" data-bulk-action-id="' + item.bulk_action_id + '" data-action-type="' + item.action_type + '">' +
        '<td>' +  item.bulk_action_id + '</td>' +
        '<td>' + bulkOperationLabel(item) + '</td>' +
        '<td>' + escapeHtml(item.file_name || '—') + '</td>' +
        '<td>' + (item.total_rows || 0) + '</td>' +
        '<td>' + bulkOperationSuccess(item) + '</td>' +
        '<td>' + failed + '</td>' +
        '<td><span class="badge ' + statusClass + '">' + escapeHtml(item.status || 'Completed') + '</span></td>' +
        '<td>' + escapeHtml(performer) + '</td>' +
        '<td class="whitespace-nowrap">' + formatBulkImportDate(item.created_at) + '</td>' +
      '</tr>';
    }).join('');
    if (!el._bulkHistoryClickBound) {
      el._bulkHistoryClickBound = true;
      el.addEventListener('click', function (e) {
        var row = e.target.closest('[data-bulk-action-id]');
        if (!row) return;
        if (row.dataset.actionType === 'ca_master_export') {
          openBulkExportDetail(row.dataset.bulkActionId);
        } else if (row.dataset.actionType === 'ca_master_status_update') {
          openBulkStatusUpdateDetail(row.dataset.bulkActionId);
        } else {
          openBulkImportDetail(row.dataset.bulkActionId);
        }
      });
    }
  }

  function renderBulkImportHistoryTable(items) {
    renderBulkOperationsHistoryTable(items);
  }

  function openBulkStatusUpdateDetail(bulkActionId) {
    if (!bulkActionId) return;
    var items = window._bulkOperationsHistoryCache || [];
    var detail = items.find(function (item) { return String(item.bulk_action_id) === String(bulkActionId); });
    var modal = document.getElementById('modal-bulk-import-detail');
    var content = document.getElementById('bulk-import-detail-body');
    var title = document.getElementById('bulk-import-detail-title');
    if (!modal || !content) return;
    if (title) title.innerHTML = '<span class="ca-modal-icon"><i data-lucide="refresh-cw" class="h-5 w-5"></i></span> Status Update Details ';
    if (!detail) {
      content.innerHTML = '<p class="text-body text-slate-500">Details for bulk action #' + escapeHtml(String(bulkActionId)) + '.</p>';
    } else {
      content.innerHTML =
        '<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">' +
          [
            ['Description', detail.file_name || '—'],
            ['Performed By', detail.imported_by || 'System'],
            ['Created At', formatBulkImportDate(detail.created_at)],
            ['Total Records', detail.total_rows || 0],
            ['Updated', detail.inserted_rows || 0],
            ['Skipped', detail.skipped_rows || 0],
            ['Failed', detail.failed_rows || 0],
            ['Status', detail.status || '—'],
          ].map(function (pair) {
            return '<div class="card p-4"><p class="text-caption text-slate-500">' + pair[0] + '</p><p class="font-medium text-slate-900">' + escapeHtml(String(pair[1])) + '</p></div>';
          }).join('') +
        '</div>';
    }
    var errorBtn = document.getElementById('bulk-detail-error-report-btn');
    var reimportBtn = document.getElementById('bulk-detail-reimport-btn');
    var reuploadBtn = document.getElementById('bulk-detail-reupload-btn');
    if (errorBtn) { errorBtn.classList.add('hidden'); errorBtn.disabled = true; }
    if (reimportBtn) { reimportBtn.classList.add('hidden'); reimportBtn.disabled = true; }
    if (reuploadBtn) { reuploadBtn.classList.add('hidden'); reuploadBtn.disabled = true; }
    openModal(modal);
    icons();
  }

  function openBulkExportDetail(bulkActionId) {
    if (!bulkActionId) return;
    apiFetch('/ca-masters/bulk-export/history/' + encodeURIComponent(bulkActionId))
      .then(function (body) {
        var detail = body.data || {};
        var modal = document.getElementById('modal-bulk-import-detail');
        var content = document.getElementById('bulk-import-detail-body');
        var title = document.getElementById('bulk-import-detail-title');
        if (!modal || !content) return;
        if (title) title.innerHTML = '<span class="ca-modal-icon"><i data-lucide="download" class="h-5 w-5"></i></span> Export Details ';
        content.innerHTML =
          '<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">' +
            [
              ['File Name', detail.file_name || '—'],
              ['Exported By', detail.exported_by || 'System'],
              ['Created At', formatBulkImportDate(detail.created_at)],
              ['Total Rows', detail.total_rows || 0],
              ['Exported Rows', detail.exported_rows || 0],
              ['Format', (detail.format || 'csv').toUpperCase()],
              ['Status', detail.status || '—'],
              ['Progress', (detail.progress_percent || 0) + '%'],
            ].map(function (pair) {
              return '<div class="card p-4"><p class="text-caption text-slate-500">' + pair[0] + '</p><p class="font-medium text-slate-900">' + escapeHtml(String(pair[1])) + '</p></div>';
            }).join('') +
          '</div>' +
          '<p class="text-caption text-slate-500">Download the generated export file when status is Completed.</p>';
        var errorBtn = document.getElementById('bulk-detail-error-report-btn');
        var reimportBtn = document.getElementById('bulk-detail-reimport-btn');
        var reuploadBtn = document.getElementById('bulk-detail-reupload-btn');
        if (errorBtn) { errorBtn.classList.add('hidden'); errorBtn.disabled = true; }
        if (reimportBtn) { reimportBtn.classList.add('hidden'); reimportBtn.disabled = true; }
        if (reuploadBtn) {
          reuploadBtn.classList.remove('hidden');
          reuploadBtn.innerHTML = '<i data-lucide="download" class="h-4 w-4"></i> Download Export';
          reuploadBtn.onclick = function () {
            if (!detail.download_ready) {
              toast('Export file is not ready yet', 'warning');
              return;
            }
            window.location.href = '/ca-masters/bulk-export/history/' + encodeURIComponent(bulkActionId) + '/download';
          };
        }
        openModal(modal);
        icons();
      })
      .catch(function (error) {
        toast(error.message || 'Failed to load export details', 'error');
      });
  }

  function openBulkImportDetail(bulkActionId) {
    if (!bulkActionId) return;
    bulkImportDetailState.bulkActionId = bulkActionId;
    apiFetch('/ca-masters/bulk-import/history/' + encodeURIComponent(bulkActionId))
      .then(function (body) {
        var detail = body.data || {};
        var modal = document.getElementById('modal-bulk-import-detail');
        var content = document.getElementById('bulk-import-detail-body');
        var title = document.getElementById('bulk-import-detail-title');
        if (!modal || !content) return;
        if (title) title.innerHTML = '<span class="ca-modal-icon"><i data-lucide="file-text" class="h-5 w-5"></i></span> Import Details ';
        content.innerHTML =
          '<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">' +
            [
              ['File Name', detail.file_name || '—'],
              ['Imported By', detail.imported_by || 'System'],
              ['Created At', formatBulkImportDate(detail.created_at)],
              ['Total Rows', detail.total_rows || 0],
              ['Inserted Rows', detail.inserted_rows || 0],
              ['Duplicate Rows', detail.duplicate_rows || 0],
              ['Failed Rows', detail.failed_rows || 0],
              ['Status', detail.status || '—'],
              ['Error Rows', detail.error_row_count || 0],
            ].map(function (pair) {
              return '<div class="card p-4"><p class="text-caption text-slate-500">' + pair[0] + '</p><p class="font-medium text-slate-900">' + escapeHtml(String(pair[1])) + '</p></div>';
            }).join('') +
          '</div>' +
          '<p class="text-caption text-slate-500">Download failed rows, correct them using the sample template format, then re-upload via the import wizard.</p>';
        var errorBtn = document.getElementById('bulk-detail-error-report-btn');
        var reimportBtn = document.getElementById('bulk-detail-reimport-btn');
        var reuploadBtn = document.getElementById('bulk-detail-reupload-btn');
        if (errorBtn) { errorBtn.classList.remove('hidden'); errorBtn.disabled = !(detail.error_row_count > 0); }
        if (reimportBtn) { reimportBtn.classList.remove('hidden'); reimportBtn.disabled = !(detail.failed_rows > 0); }
        if (reuploadBtn) {
          reuploadBtn.classList.remove('hidden');
          reuploadBtn.innerHTML = '<i data-lucide="upload" class="h-4 w-4"></i> Re-upload Corrected File';
        }
        if (errorBtn && !errorBtn._detailBound) {
          errorBtn._detailBound = true;
          errorBtn.addEventListener('click', function () {
            window.location.href = '/ca-masters/bulk-import/history/' + encodeURIComponent(bulkImportDetailState.bulkActionId) + '/error-report.csv';
          });
        }
        if (reimportBtn && !reimportBtn._detailBound) {
          reimportBtn._detailBound = true;
          reimportBtn.addEventListener('click', function () {
            window.location.href = '/ca-masters/bulk-import/history/' + encodeURIComponent(bulkImportDetailState.bulkActionId) + '/reimport-template.csv';
          });
        }
        if (reuploadBtn && !reuploadBtn._detailBound) {
          reuploadBtn._detailBound = true;
          reuploadBtn.addEventListener('click', function () {
            closeModal(modal);
            if (typeof window.openBulkImportWizard === 'function') window.openBulkImportWizard();
            if (window.CA_CRM && typeof window.CA_CRM.initBulkImportWizard === 'function') {
              var fileInput = document.getElementById('bulk-import-file');
              if (fileInput) fileInput.click();
            }
            toast('Upload your corrected file to run the import wizard again', 'info');
          });
        }
        openModal(modal);
        icons();
      })
      .catch(function (error) {
        toast(error.message || 'Failed to load import details', 'error');
      });
  }

  function renderBulkImportSummary(summary, prepend) {
    if (summary && summary.bulk_action_id) {
      loadBulkImportHistory();
    }
  }

  function dbHealthStatusBadge(status) {
    var map = {
      healthy: 'badge-success',
      empty: 'badge-warning',
      future_module: 'badge-brand',
      missing: 'badge-danger',
      error: 'badge-danger',
      issue: 'badge-warning',
    };
    return '<span class="badge ' + (map[status] || 'badge-brand') + '">' + (status === 'future_module' ? 'future module' : (status || '—'));
  }

  function dbHealthRowClass(status) {
    if (status === 'future_module') return '';
    if (status === 'empty') return ' db-health-row-empty';
    if (status === 'missing' || status === 'error') return ' db-health-row-alert';
    return '';
  }

  function formatDbHealthDate(value) {
    if (!value) return '—';
    var date = new Date(value);
    if (isNaN(date.getTime())) return String(value);
    return date.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function renderDbHealthReport(report) {
    if (!report) return;
    var summary = report.summary || {};
    var database = report.database || {};

    var kpiGrid = document.getElementById('db-health-kpi-grid');
    if (kpiGrid) {
      var cards = [
        { label: 'Total Tables', value: summary.total_tables, icon: 'database' },
        { label: 'Healthy Tables', value: summary.healthy_tables, icon: 'check-circle-2' },
        { label: 'Future Modules', value: summary.future_module_tables, icon: 'layers' },
        { label: 'Empty Tables', value: summary.empty_tables, icon: 'circle-dashed' },
        { label: 'Missing Tables', value: summary.missing_tables, icon: 'alert-triangle' },
        { label: 'Duplicate Issues', value: summary.duplicate_issues, icon: 'copy' },
        { label: 'FK Issues', value: summary.fk_issues, icon: 'link-2' },
      ];
      kpiGrid.innerHTML = cards.map(function (card) {
        return '<div class="card-interactive p-4"><div class="flex items-center gap-3 mb-2"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand"><i data-lucide="' + card.icon + '" class="h-5 w-5"></i></span><p class="text-caption text-slate-500">' + card.label + '</p></div><p class="text-2xl font-bold text-slate-900">' + (card.value ?? '—') + '</p></div>';
      }).join('');
    }

    var dbName = document.getElementById('db-health-db-name');
    var dbSize = document.getElementById('db-health-db-size');
    var generatedAt = document.getElementById('db-health-generated-at');
    if (dbName) dbName.textContent = database.name || '—';
    if (dbSize) dbSize.textContent = database.total_size ? ('Total size: ' + database.total_size) : '—';
    if (generatedAt) generatedAt.textContent = formatDbHealthDate(report.generated_at);

    var tablesBody = document.getElementById('db-health-tables-body');
    if (tablesBody) {
      var tables = report.tables || [];
      tablesBody.innerHTML = tables.length ? tables.map(function (row) {
        return '<tr class="ca-table-row' + dbHealthRowClass(row.status) + '">' +
          '<td>' + escapeHtml(humanizeDataSetName(row.table_name)) + '</td>' +
          '<td>' + row.total_records + '</td>' +
          '<td>' + (row.latest_record_id ?? '—') + '</td>' +
          '<td>' + formatDbHealthDate(row.latest_created_at) + '</td>' +
          '<td>' + dbHealthStatusBadge(row.status) + (row.classification ? '<p class="text-caption text-slate-500 mt-1">' + row.classification + '</p>' : '') + (row.error ? '<p class="text-caption text-red-600 mt-1">' + row.error + '</p>' : '') + '</td>' +
        '</tr>';
      }).join('') : '<tr><td colspan="5" class="text-center text-slate-500 p-4">No table data</td></tr>';
    }

    var duplicatesBody = document.getElementById('db-health-duplicates-body');
    if (duplicatesBody) {
      var duplicates = report.duplicates || [];
      duplicatesBody.innerHTML = duplicates.length ? duplicates.map(function (row) {
        return '<tr class="ca-table-row' + (row.status === 'issue' ? ' db-health-row-empty' : '') + '">' +
          '<td>' + row.field + '</td>' +
          '<td>' + row.duplicate_count + '</td>' +
          '<td>' + row.duplicate_groups + '</td>' +
          '<td class="text-caption">' + ((row.sample_values || []).join(', ') || '—') + '</td>' +
          '<td>' + dbHealthStatusBadge(row.status) + '</td>' +
        '</tr>';
      }).join('') : '<tr><td colspan="5" class="text-center text-slate-500 p-4">No duplicate checks</td></tr>';
    }

    var fkBody = document.getElementById('db-health-fk-body');
    if (fkBody) {
      var foreignKeys = report.foreign_keys || [];
      fkBody.innerHTML = foreignKeys.length ? foreignKeys.map(function (row) {
        var sample = (row.sample_invalid_rows || []).map(function (item) {
          return JSON.stringify(item);
        }).join('<br>') || '—';
        return '<tr class="ca-table-row' + (row.status === 'issue' ? ' db-health-row-alert' : '') + '">' +
          '<td class="text-caption">' + row.check + '</td>' +
          '<td>' + row.invalid_count + '</td>' +
          '<td class="text-caption">' + sample + '</td>' +
          '<td>' + dbHealthStatusBadge(row.status) + '</td>' +
        '</tr>';
      }).join('') : '<tr><td colspan="4" class="text-center text-slate-500 p-4">No FK checks</td></tr>';
    }

    var apiBody = document.getElementById('db-health-api-body');
    if (apiBody) {
      var routes = report.api_routes || [];
      apiBody.innerHTML = routes.length ? routes.map(function (row) {
        return '<tr class="ca-table-row' + (row.route_exists ? '' : ' db-health-row-alert') + '">' +
          '<td>' + row.path + '</td>' +
          '<td>' +  row.method + '</td>' +
          '<td>' + (row.route_exists ? 'Yes' : 'No') + '</td>' +
          '<td>' + dbHealthStatusBadge(row.status) + '</td>' +
        '</tr>';
      }).join('') : '<tr><td colspan="4" class="text-center text-slate-500 p-4">No API checks</td></tr>';
    }

    icons();
  }

  function loadDbHealthReport(callback) {
    apiFetch('/admin/db-health')
      .then(function (body) {
        window.dbHealthReport = body.data || null;
        renderDbHealthReport(window.dbHealthReport);
        if (callback) callback(window.dbHealthReport);
      })
      .catch(function (error) {
        toast(error.message || 'Failed to load database health report', 'error');
        if (callback) callback(null);
      });
  }

  function initDbHealthPage() {
    var refreshBtn = document.getElementById('db-health-refresh-btn');
    if (refreshBtn && !refreshBtn._dbHealthBound) {
      refreshBtn._dbHealthBound = true;
      refreshBtn.addEventListener('click', function () {
        toast('Refreshing database health…', 'info');
        loadDbHealthReport(function () {
          toast('Database health report updated', 'success');
        });
      });
    }
    loadDbHealthReport();
  }

  function loadNotifications(force) {
    if (!force && notificationsCache.length) {
      return Promise.resolve({
        notifications: notificationsCache.slice(),
        unread_count: notificationsUnreadCount,
      });
    }

    return apiFetch('/notifications')
      .then(function (body) {
        var data = body.data || {};
        notificationsCache = data.notifications || [];
        notificationsUnreadCount = data.unread_count || 0;
        notificationsLatestId = notificationsCache.reduce(function (max, item) {
          var id = parseInt(item.notification_id, 10) || 0;
          return id > max ? id : max;
        }, 0);
        notificationPollIntervalMs = (data.poll_interval_seconds || 30) * 1000;
        return data;
      });
  }

  function pollNotifications() {
    var url = '/notifications/poll';
    if (notificationsLatestId) url += '?after_id=' + notificationsLatestId;

    return apiFetch(url).then(function (body) {
      var data = body.data || {};
      (data.notifications || []).forEach(function (item) {
        var exists = notificationsCache.some(function (n) { return n.notification_id === item.notification_id; });
        if (!exists) notificationsCache.unshift(item);
        var id = parseInt(item.notification_id, 10) || 0;
        if (id > notificationsLatestId) notificationsLatestId = id;
      });
      notificationsUnreadCount = data.unread_count || 0;
      if (typeof data.latest_id === 'number' && data.latest_id > notificationsLatestId) {
        notificationsLatestId = data.latest_id;
      }
      notificationPollIntervalMs = (data.poll_interval_seconds || 30) * 1000;
      return data;
    });
  }

  function markNotificationReadApi(id) {
    return apiFetch('/notifications/' + encodeURIComponent(id) + '/read', { method: 'POST' })
      .then(function (body) {
        notificationsCache.forEach(function (item) {
          if (item.notification_id === String(id)) item.read = true;
        });
        notificationsUnreadCount = (body.data && body.data.unread_count) || notificationsUnreadCount;
        return body;
      });
  }

  function markAllNotificationsReadApi() {
    return apiFetch('/notifications/mark-all-read', { method: 'POST' })
      .then(function (body) {
        var marked = (body.data && body.data.marked) || 0;
        notificationsCache.forEach(function (item) { item.read = true; });
        notificationsUnreadCount = 0;
        return marked;
      });
  }

  function stopNotificationPoller() {
    if (notificationPollTimer) {
      clearInterval(notificationPollTimer);
      notificationPollTimer = null;
    }
  }

  function startNotificationPoller(onUpdate) {
    if (notificationPollStopped || !window.__CRM_USER__ || !window.__CRM_USER__.authenticated) {
      return;
    }
    stopNotificationPoller();
    notificationPollTimer = setInterval(function () {
      if (document.hidden || notificationPollStopped) return;
      pollNotifications().then(function () {
        notificationPollFailures = 0;
        if (typeof onUpdate === 'function') onUpdate();
      }).catch(function () {
        notificationPollFailures += 1;
        if (notificationPollFailures >= notificationPollMaxFailures) {
          notificationPollStopped = true;
          stopNotificationPoller();
        }
      });
    }, notificationPollIntervalMs || 30000);
  }

  function getNotificationsCache() {
    return notificationsCache.slice();
  }

  function getUnreadNotificationCount() {
    return notificationsUnreadCount;
  }

  var reportsAnalyticsCache = null;

  function getReportsFilterQuery() {
    var from = document.getElementById('reports-filter-from');
    var to = document.getElementById('reports-filter-to');
    var parts = [];
    if (from && from.value) parts.push('from=' + encodeURIComponent(from.value));
    if (to && to.value) parts.push('to=' + encodeURIComponent(to.value));
    return parts.length ? '?' + parts.join('&') : '';
  }

  function initReportsFilters() {
    var from = document.getElementById('reports-filter-from');
    var to = document.getElementById('reports-filter-to');
    if (!from || !to || from._initialized) return;
    from._initialized = true;
    var end = new Date();
    var start = new Date();
    start.setDate(start.getDate() - 30);
    from.value = start.toISOString().slice(0, 10);
    to.value = end.toISOString().slice(0, 10);
  }

  function loadReportsAnalytics(callback) {
    return apiFetch('/reports/analytics' + getReportsFilterQuery())
      .then(function (body) {
        reportsAnalyticsCache = body.data || {};
        if (callback) callback(reportsAnalyticsCache);
        return reportsAnalyticsCache;
      });
  }

  function paintReportChart(el, series) {
    if (!el) return;
    if (!series || !series.length) {
      el.innerHTML = '<p class="text-caption text-slate-400 p-2">No data for selected range</p>';
      return;
    }
    var max = Math.max.apply(null, series.map(function (p) { return p.value; }).concat([1]));
    el.innerHTML = '<div class="flex items-end justify-between gap-1.5 h-full px-2 pb-2" style="height:100%">' +
      series.map(function (point, i) {
        var h = Math.max(8, Math.round((point.value / max) * 100));
        return '<div class="flex-1 flex flex-col items-center justify-end h-full" title="' + escapeHtml(String(point.label)) + ': ' + point.value + '">' +
          '<div class="ca-chart-bar w-full rounded-t-md" style="height:' + h + '%;transition-delay:' + (i * 40) + 'ms"></div></div>';
      }).join('') + '</div>';
  }

  function renderReportCharts() {
    loadReportsAnalytics(function (data) {
      var charts = (data && data.charts) || {};
      document.querySelectorAll('[data-chart-key]').forEach(function (el) {
        paintReportChart(el, charts[el.dataset.chartKey] || []);
      });
      icons();
    }).catch(function () {
      document.querySelectorAll('[data-chart-key]').forEach(function (el) {
        paintReportChart(el, []);
      });
    });
  }

  function openReport(slug) {
    apiFetch('/reports/' + encodeURIComponent(slug) + getReportsFilterQuery())
      .then(function (body) {
        var report = body.data || {};
        var summary = report.summary || {};
        var fields = Object.keys(summary).slice(0, 12).map(function (key) {
          return { label: key.replace(/_/g, ' '), value: String(summary[key]) };
        });
        if (typeof openDetailDrawer === 'function') {
          openDetailDrawer({
            firm: report.label || slug,
            fields: fields,
          });
        }
        toast((report.label || 'Report') + ' loaded — ' + (report.rows || []).length + ' rows', 'success');
      })
      .catch(function (err) {
        toast(err.message || 'Unable to load report', 'error');
      });
  }

  function exportReport(slug, format) {
    var query = getReportsFilterQuery();
    if (format === 'pdf') {
      query += (query.indexOf('?') >= 0 ? '&' : '?') + 'format=pdf';
    }
    runReportExport('/reports/' + encodeURIComponent(slug) + '/export' + query, slug);
  }

  function exportReportsSummary(format) {
    var query = getReportsFilterQuery();
    if (format === 'pdf') {
      query += (query.indexOf('?') >= 0 ? '&' : '?') + 'format=pdf';
    }
    runReportExport('/reports/export/summary' + query, 'Reports summary');
  }

  function triggerFileDownload(blob, filename) {
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = filename || 'export.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function fetchExportResponse(url) {
    return fetch(url, {
      credentials: 'same-origin',
      headers: {
        'X-CSRF-TOKEN': csrfToken(),
        'Accept': 'application/json, text/csv, application/pdf, */*',
        'X-Requested-With': 'XMLHttpRequest',
      },
    }).then(function (response) {
      if (response.status === 401) {
        window.location.href = '/login';
        throw new Error('Session expired. Please sign in again.');
      }
      var contentType = (response.headers.get('content-type') || '').toLowerCase();
      if (contentType.indexOf('application/json') >= 0) {
        return response.json().then(function (body) {
          if (!response.ok) {
            throw new Error(body.message || 'Export failed.');
          }
          return { kind: 'json', body: body };
        });
      }
      if (!response.ok) {
        throw new Error('Export failed.');
      }
      var disposition = response.headers.get('content-disposition') || '';
      var match = disposition.match(/filename=\"?([^\";]+)\"?/i);
      var filename = match ? match[1] : 'report-export.csv';
      return response.blob().then(function (blob) {
        return { kind: 'file', blob: blob, filename: filename };
      });
    });
  }

  function pollReportExport(exportId) {
    var attempts = 0;
    var maxAttempts = 60;
    return new Promise(function (resolve, reject) {
      function tick() {
        attempts += 1;
        apiFetch('/reports/exports/' + encodeURIComponent(exportId) + '/status')
          .then(function (body) {
            var data = body.data || {};
            if (data.status === 'Completed' && data.download_ready) {
              resolve(data);
              return;
            }
            if (data.status === 'Failed') {
              reject(new Error(data.error || 'Report export failed.'));
              return;
            }
            if (attempts >= maxAttempts) {
              reject(new Error('Report export timed out.'));
              return;
            }
            setTimeout(tick, 2000);
          })
          .catch(reject);
      }
      tick();
    });
  }

  function runReportExport(url, label) {
    toast((label || 'Report') + ' export started…', 'info');
    return fetchExportResponse(url)
      .then(function (result) {
        if (result.kind === 'file') {
          triggerFileDownload(result.blob, result.filename);
          toast((label || 'Report') + ' downloaded', 'success');
          return;
        }
        var payload = (result.body && result.body.data) || {};
        if (!payload.export_id) {
          throw new Error('Unexpected export response.');
        }
        toast('Large export queued — processing in background…', 'info');
        return pollReportExport(payload.export_id).then(function () {
          window.location.href = '/reports/exports/' + encodeURIComponent(payload.export_id) + '/download';
          toast((label || 'Report') + ' ready — download started', 'success');
        });
      })
      .catch(function (err) {
        toast(err.message || 'Export failed', 'error');
      });
  }

  function renderQueueStatus(data) {
    if (!data) return;
    var pendingEl = document.getElementById('queue-kpi-pending');
    var failedEl = document.getElementById('queue-kpi-failed');
    var connectionEl = document.getElementById('queue-kpi-connection');
    var workerEl = document.getElementById('queue-kpi-worker');
    if (pendingEl) pendingEl.textContent = String(data.pending_jobs ?? '—');
    if (failedEl) failedEl.textContent = String(data.failed_jobs ?? '—');
    if (connectionEl) connectionEl.textContent = data.connection || '—';
    if (workerEl) {
      if ((data.pending_jobs || 0) > 0 && data.worker_required) {
        workerEl.textContent = 'Pending';
      } else if ((data.failed_jobs || 0) > 0) {
        workerEl.textContent = 'Failures';
      } else {
        workerEl.textContent = data.healthy ? 'Healthy' : 'OK';
      }
    }

    var commandsEl = document.getElementById('queue-commands-list');
    if (commandsEl && data.commands) {
      commandsEl.innerHTML = Object.keys(data.commands).map(function (key) {
        return '<li>' + data.commands[key] + '</li>';
      }).join('');
    }

    var failuresBody = document.getElementById('queue-failed-body');
    if (failuresBody) {
      var failures = data.recent_failures || [];
      failuresBody.innerHTML = failures.length ? failures.map(function (row) {
        return '<tr class="ca-table-row">' +
          '<td>' +  (row.uuid || '—') + '</td>' +
          '<td>' + (row.queue || '—') + '</td>' +
          '<td>' + (row.job || '—') + '</td>' +
          '<td>' + formatActivityTimestamp(row.failed_at) + '</td>' +
          '<td class="text-caption">' + (row.exception || '—') + '</td>' +
        '</tr>';
      }).join('') : '<tr><td colspan="5" class="text-center text-slate-500 p-4">No failed jobs</td></tr>';
    }
    icons();
  }

  function loadQueueStatus(callback) {
    apiFetch('/admin/queue-status')
      .then(function (body) {
        renderQueueStatus(body.data || null);
        if (callback) callback(body.data);
      })
      .catch(function (error) {
        toast(error.message || 'Failed to load queue status', 'error');
        if (callback) callback(null);
      });
  }

  function initQueuePage() {
    var refreshBtn = document.getElementById('queue-refresh-btn');
    if (refreshBtn && !refreshBtn._queueBound) {
      refreshBtn._queueBound = true;
      refreshBtn.addEventListener('click', function () {
        toast('Refreshing queue status…', 'info');
        loadQueueStatus(function () {
          toast('Queue status updated', 'success');
        });
      });
    }
    loadQueueStatus();
  }

  function refreshReportsHub() {
    initReportsFilters();
    if (document.querySelector('[data-chart-key]')) renderReportCharts();
    if (document.getElementById('audit-logs-table')) initAuditPage();
    apiFetch('/reports' + getReportsFilterQuery())
      .then(function (body) {
        var reports = ((body.data || {}).reports) || [];
        reports.forEach(function (item) {
          var meta = document.querySelector('.report-card-meta[data-report-slug="' + item.slug + '"]');
          if (!meta) return;
          if (item.summary && item.summary.conversion_rate_pct !== undefined) {
            meta.textContent = item.row_count + ' rows · ' + item.summary.conversion_rate_pct + '% conversion';
          } else if (item.summary && item.summary.total_followups !== undefined) {
            meta.textContent = item.row_count + ' types · ' + item.summary.total_followups + ' follow-ups';
          } else {
            meta.textContent = item.row_count + ' rows · Live';
          }
        });
      })
      .catch(function () {});
  }

  function smsIntegrationStatusMeta(sms) {
    sms = sms || {};
    var status = sms.integration_status;
    if (!status) {
      if (sms.is_active === false) status = 'disabled';
      else if (sms.has_api_key && sms.sender_id) status = 'connected';
      else status = 'not_configured';
    }
    if (status === 'connected') return { label: 'Connected', badge: 'badge-success' };
    if (status === 'disabled') return { label: 'Disabled', badge: 'badge-warning' };
    return { label: 'Not Configured', badge: 'badge-neutral' };
  }

  function updateSmsIntegrationStatusBadge(sms) {
    var badge = document.getElementById('sms-integration-status-badge');
    if (!badge) return;
    var meta = smsIntegrationStatusMeta(sms);
    badge.textContent = meta.label;
    badge.className = meta.badge;
  }

  function showSmsSettingsError(err) {
    var box = document.getElementById('sms-settings-error-box');
    if (!box) return;
    if (!err) {
      box.classList.add('hidden');
      box.textContent = '';
      return;
    }
    var lines = [];
    if (err.errors) {
      Object.keys(err.errors).forEach(function (key) {
        var msgs = err.errors[key];
        if (Array.isArray(msgs)) lines.push(msgs[0]);
      });
    }
    if (!lines.length && err.message) lines.push(err.message);
    box.textContent = lines.join(' ');
    box.classList.remove('hidden');
  }

  function setSmsSettingsBusy(busy) {
    ['sms-settings-save-btn', 'sms-settings-test-btn', 'sms-settings-reset-btn'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.disabled = !!busy;
    });
  }

  function saveSmsSettings() {
    showSmsSettingsError(null);
    setSmsSettingsBusy(true);
    apiFetch('/sms-settings', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(buildSmsSettingsPayload()),
    }).then(function (body) {
      populateSmsSettingsForm(body.data || {});
      var apiKey = document.getElementById('sms-settings-api-key');
      if (apiKey) apiKey.value = '';
      toast('SMS settings saved', 'success');
    }).catch(function (err) {
      showSmsSettingsError(err);
      toast(err.message || 'Unable to save SMS settings', 'error');
    }).finally(function () {
      setSmsSettingsBusy(false);
    });
  }

  function validateSmsSettings() {
    showSmsSettingsError(null);
    setSmsSettingsBusy(true);
    apiFetch('/sms-settings/validate', { method: 'POST' })
      .then(function (body) {
        var result = body.data || {};
        if (result.valid) {
          showSmsSettingsError(null);
          toast('SMS configuration is valid (no API call made)', 'success');
        } else {
          showSmsSettingsError({ message: (result.errors || []).join(' ') });
          toast((result.errors || []).join(' ') || 'SMS configuration validation failed', 'error');
        }
        if (result.settings) populateSmsSettingsForm(result.settings);
      })
      .catch(function (err) {
        showSmsSettingsError(err);
        toast(err.message || 'Configuration validation failed', 'error');
      })
      .finally(function () {
        setSmsSettingsBusy(false);
      });
  }

  function resetSmsSettings() {
    if (!window.confirm('Reset SMS settings to defaults? Credentials will be cleared.')) return;
    showSmsSettingsError(null);
    setSmsSettingsBusy(true);
    apiFetch('/sms-settings/reset', { method: 'POST' })
      .then(function (body) {
        populateSmsSettingsForm(body.data || {});
        toast('SMS settings reset', 'success');
      })
      .catch(function (err) {
        showSmsSettingsError(err);
        toast(err.message || 'Unable to reset SMS settings', 'error');
      })
      .finally(function () {
        setSmsSettingsBusy(false);
      });
  }

  function openSmsIntegrationPanel() {
    var panel = document.getElementById('sms-settings-panel');
    if (panel) {
      panel.classList.remove('hidden');
      document.body.classList.add('sms-settings-open');
      var fabWrap = document.getElementById('fab-wrap');
      if (fabWrap) fabWrap.classList.add('hidden');
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    icons();
  }

  function closeSmsIntegrationPanel() {
    var panel = document.getElementById('sms-settings-panel');
    if (panel) panel.classList.add('hidden');
    document.body.classList.remove('sms-settings-open');
    showSmsSettingsError(null);
    var fabWrap = document.getElementById('fab-wrap');
    if (fabWrap && (window._currentPageId === 'settings')) fabWrap.classList.add('hidden');
  }

  function bindSmsSettingsHandlers() {
    if (window._smsSettingsDocDelegation) return;
    window._smsSettingsDocDelegation = true;

    document.addEventListener('click', function (e) {
      if (e.target.closest('#sms-integration-card')) {
        e.preventDefault();
        openSmsIntegrationPanel();
        return;
      }
      if (e.target.closest('#sms-settings-close-btn') || e.target.closest('#sms-settings-cancel-btn')) {
        e.preventDefault();
        apiFetch('/sms-settings').then(function (body) {
          populateSmsSettingsForm(body.data || {});
        }).catch(function () {}).finally(closeSmsIntegrationPanel);
        return;
      }
      if (e.target.closest('#sms-settings-save-btn')) {
        e.preventDefault();
        saveSmsSettings();
        return;
      }
      if (e.target.closest('#sms-settings-test-btn')) {
        e.preventDefault();
        validateSmsSettings();
        return;
      }
      if (e.target.closest('#sms-settings-reset-btn')) {
        e.preventDefault();
        resetSmsSettings();
      }
    });
  }

  function populateSmsSettingsForm(sms) {
    sms = sms || {};
    var setVal = function (id, val) {
      var el = document.getElementById(id);
      if (el && val != null && val !== '') el.value = val;
    };
    var setCheck = function (id, val) {
      var el = document.getElementById(id);
      if (el) el.checked = !!val;
    };
    setVal('sms-settings-provider-name', sms.provider_name || 'SMS Alert');
    setVal('sms-settings-api-url', sms.api_url || 'https://www.smsalert.co.in/api/push.json');
    setVal('sms-settings-sender-id', sms.sender_id || '');
    setVal('sms-settings-mode', sms.mode || 'simulation');
    setCheck('sms-settings-is-active', sms.is_active !== false);
    var apiKey = document.getElementById('sms-settings-api-key');
    if (apiKey) apiKey.value = '';
    var note = document.getElementById('sms-settings-api-key-note');
    if (note) {
      note.textContent = sms.has_api_key
        ? 'API key is configured (encrypted). Leave blank to keep current key.'
        : 'API key is encrypted at rest and never returned by the API.';
    }
    var badge = document.getElementById('sms-settings-mode-badge');
    if (badge) badge.textContent = (sms.mode || 'simulation') === 'live' ? 'Live' : 'Simulation';
    updateSmsIntegrationStatusBadge(sms);
    applySmsSettingsReadOnly(!sms.can_edit);
  }

  function applySmsSettingsReadOnly(readOnly) {
    ['sms-settings-provider-name', 'sms-settings-api-url', 'sms-settings-api-key', 'sms-settings-sender-id', 'sms-settings-mode', 'sms-settings-is-active'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.disabled = readOnly;
    });
    ['sms-settings-save-btn', 'sms-settings-test-btn', 'sms-settings-reset-btn'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.classList.toggle('hidden', readOnly);
    });
  }

  function buildSmsSettingsPayload() {
    var payload = {
      provider_name: (document.getElementById('sms-settings-provider-name') || {}).value || '',
      api_url: (document.getElementById('sms-settings-api-url') || {}).value || '',
      sender_id: (document.getElementById('sms-settings-sender-id') || {}).value || '',
      mode: (document.getElementById('sms-settings-mode') || {}).value || 'simulation',
      is_active: !!(document.getElementById('sms-settings-is-active') || {}).checked,
    };
    var keyVal = (document.getElementById('sms-settings-api-key') || {}).value || '';
    if (keyVal) payload.api_key = keyVal;
    return payload;
  }

  function initSmsSettingsModule() {
    bindSmsSettingsHandlers();
  }

  initSmsSettingsModule();

  function initSettingsPage() {
    var saveBtn = document.getElementById('settings-save-btn');
    apiFetch('/settings/data')
      .then(function (body) {
        var data = body.data || {};
        var general = data.general || {};
        var assignment = data.assignment || {};
        var setVal = function (id, val) {
          var el = document.getElementById(id);
          if (el && val != null) el.value = val;
        };
        var setCheck = function (id, val) {
          var el = document.getElementById(id);
          if (el) el.checked = !!val;
        };
        setVal('settings-company-name', general.company_name || 'CA Cloud Desk');
        setVal('settings-timezone', general.timezone || 'Asia/Kolkata');
        setVal('settings-date-format', general.date_format || 'DD/MM/YYYY');
        setVal('settings-default-city', general.default_city || 'Mumbai');
        setCheck('settings-auto-assignment', assignment.auto_assignment !== false);
        setCheck('settings-hot-lead-priority', assignment.hot_lead_priority !== false);
        setCheck('settings-workload-balancing', assignment.workload_balancing !== false);
        setCheck('settings-city-routing', assignment.city_routing !== false);
      })
      .catch(function () {});
    var smsIntegrationCard = document.getElementById('sms-integration-card');
    var crmUser = window.__CRM_USER__ || {};
    if (crmUser.role === 'employee' && smsIntegrationCard) {
      smsIntegrationCard.classList.add('hidden');
    }
    apiFetch('/sms-settings')
      .then(function (body) {
        populateSmsSettingsForm(body.data || {});
      })
      .catch(function () {
        if (smsIntegrationCard) smsIntegrationCard.classList.add('hidden');
      });
    apiFetch('/email-settings')
      .then(function (body) {
        var email = body.data || {};
        var setVal = function (id, val) {
          var el = document.getElementById(id);
          if (el && val != null && val !== '') el.value = val;
        };
        setVal('email-settings-provider-name', email.provider_name || 'GoDaddy SMTP');
        setVal('email-settings-smtp-host', email.smtp_host || 'smtpout.secureserver.net');
        setVal('email-settings-smtp-port', email.smtp_port || 465);
        setVal('email-settings-smtp-username', email.smtp_username || '');
        setVal('email-settings-smtp-encryption', email.smtp_encryption || 'ssl');
        setVal('email-settings-from-email', email.from_email || '');
        setVal('email-settings-from-name', email.from_name || '');
        setVal('email-settings-mode', email.mode || 'simulation');
        var pwd = document.getElementById('email-settings-smtp-password');
        if (pwd) pwd.value = '';
        var pwdNote = document.getElementById('email-settings-password-note');
        if (pwdNote) {
          pwdNote.textContent = email.has_smtp_password
            ? 'SMTP password is configured (encrypted). Leave blank to keep current password.'
            : 'SMTP password is encrypted at rest and never returned by the API.';
        }
        var emailBadge = document.getElementById('email-settings-mode-badge');
        if (emailBadge) emailBadge.textContent = (email.mode || 'simulation') === 'live' ? 'Live' : 'Simulation';
      })
      .catch(function () {});
    var emailSaveBtn = document.getElementById('email-settings-save-btn');
    if (emailSaveBtn && !emailSaveBtn._emailSettingsBound) {
      emailSaveBtn._emailSettingsBound = true;
      emailSaveBtn.addEventListener('click', function () {
        var payload = {
          provider_name: (document.getElementById('email-settings-provider-name') || {}).value || '',
          smtp_host: (document.getElementById('email-settings-smtp-host') || {}).value || '',
          smtp_port: parseInt((document.getElementById('email-settings-smtp-port') || {}).value, 10) || null,
          smtp_username: (document.getElementById('email-settings-smtp-username') || {}).value || '',
          smtp_encryption: (document.getElementById('email-settings-smtp-encryption') || {}).value || '',
          from_email: (document.getElementById('email-settings-from-email') || {}).value || '',
          from_name: (document.getElementById('email-settings-from-name') || {}).value || '',
          mode: (document.getElementById('email-settings-mode') || {}).value || 'simulation',
        };
        var pwdVal = (document.getElementById('email-settings-smtp-password') || {}).value || '';
        if (pwdVal) payload.smtp_password = pwdVal;
        apiFetch('/email-settings', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        }).then(function (body) {
          toast('Email settings saved', 'success');
          var email = (body && body.data) || {};
          var pwd = document.getElementById('email-settings-smtp-password');
          if (pwd) pwd.value = '';
          var emailBadge = document.getElementById('email-settings-mode-badge');
          if (emailBadge) emailBadge.textContent = payload.mode === 'live' ? 'Live' : 'Simulation';
        }).catch(function (err) {
          toast(err.message || 'Unable to save email settings', 'error');
        });
      });
    }
    if (saveBtn && !saveBtn._settingsBound) {
      saveBtn._settingsBound = true;
      saveBtn.addEventListener('click', function () {
        var payload = {
          general: {
            company_name: (document.getElementById('settings-company-name') || {}).value || '',
            timezone: (document.getElementById('settings-timezone') || {}).value || '',
            date_format: (document.getElementById('settings-date-format') || {}).value || '',
            default_city: (document.getElementById('settings-default-city') || {}).value || '',
          },
          assignment: {
            auto_assignment: !!(document.getElementById('settings-auto-assignment') || {}).checked,
            hot_lead_priority: !!(document.getElementById('settings-hot-lead-priority') || {}).checked,
            workload_balancing: !!(document.getElementById('settings-workload-balancing') || {}).checked,
            city_routing: !!(document.getElementById('settings-city-routing') || {}).checked,
          },
        };
        apiFetch('/settings/data', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        }).then(function () {
          toast('Settings saved successfully', 'success');
        }).catch(function (err) {
          toast(err.message || 'Unable to save settings', 'error');
        });
      });
    }
  }

  function initSecurityPage() {
    apiFetch('/admin/security-matrix')
      .then(function (body) {
        var data = body.data || {};
        window._securityMatrix = data.matrix || {};
        window._securityCanEdit = !!data.can_edit;
        var rbacBody = document.getElementById('security-rbac-matrix');
        var note = document.getElementById('security-matrix-note');
        if (note) {
          note.textContent = data.can_edit
            ? 'Toggle permissions for admin, manager, and employee roles. Changes save immediately.'
            : 'Read-only view. Only Super Admin and Admin can edit permissions.';
        }
        if (rbacBody) {
          var rows = [];
          (data.editable_roles || []).forEach(function (role) {
            (data.modules || []).forEach(function (module) {
              (data.permissions || []).forEach(function (permission) {
                var rolePerms = (data.matrix[role] && data.matrix[role][module]) || (data.matrix[role] && data.matrix[role]['*']) || [];
                var granted = rolePerms.indexOf('*') >= 0 || rolePerms.indexOf(permission) >= 0;
                rows.push('<tr class="ca-table-row">' +
                  '<td>' + escapeHtml(rbacRoleLabel(role)) + '</td>' +
                  '<td>' + escapeHtml(activityModuleLabel(module)) + '</td>' +
                  '<td>' + escapeHtml(rbacPermissionLabel(permission)) + '</td>' +
                  '<td><button type="button" class="perm-check' + (granted ? ' on' : ' off') + '" ' +
                    'data-security-toggle="1" data-role="' + escapeHtml(role) + '" data-module="' + escapeHtml(module) + '" data-permission="' + escapeHtml(permission) + '" ' +
                    (data.can_edit ? '' : 'disabled') + ' aria-label="Toggle ' + permission + ' for ' + role + ' on ' + module + '">' +
                    '<i data-lucide="' + (granted ? 'check' : 'x') + '" class="h-4 w-4"></i></button></td>' +
                '</tr>');
              });
            });
          });
          rbacBody.innerHTML = rows.join('') || '<tr><td colspan="4" class="text-center text-slate-500 p-4">No editable roles configured.</td></tr>';
          rbacBody.querySelectorAll('[data-security-toggle]').forEach(function (btn) {
            if (btn._secToggleBound) return;
            btn._secToggleBound = true;
            btn.addEventListener('click', function () {
              if (!window._securityCanEdit) return;
              var granted = !btn.classList.contains('on');
              apiFetch('/admin/security-matrix', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  role: btn.getAttribute('data-role'),
                  module: btn.getAttribute('data-module'),
                  permission: btn.getAttribute('data-permission'),
                  granted: granted,
                }),
              }).then(function (resp) {
                var matrix = (resp.data && resp.data.matrix) || {};
                window._securityMatrix = matrix;
                btn.classList.toggle('on', granted);
                btn.classList.toggle('off', !granted);
                var icon = btn.querySelector('[data-lucide]');
                if (icon) icon.setAttribute('data-lucide', granted ? 'check' : 'x');
                icons();
                toast('Permission updated', 'success');
              }).catch(function (err) {
                toast(err.message || 'Unable to update permission', 'error');
              });
            });
          });
        }
        var usersBody = document.getElementById('security-users-table');
        if (usersBody) {
          usersBody.innerHTML = (data.users || []).map(function (user) {
            var moduleCount = Object.keys(user.permissions || {}).length;
            return '<tr class="ca-table-row"><td>' + escapeHtml(user.name) + '</td><td>' + escapeHtml(user.email) + '</td><td>' + escapeHtml(user.role_label || user.role) + '</td><td>' + moduleCount + ' modules</td></tr>';
          }).join('') || '<tr><td colspan="4" class="text-center text-slate-500 p-4">No users</td></tr>';
        }
        icons();
      })
      .catch(function (err) {
        toast(err.message || 'Unable to load security matrix', 'error');
      });
  }

  function init() {
    initForms();
    initCampaignActions();
    initMasterDataActions();
    bindModalTriggers(document);
    initQuickActions();
    if (window.CA_STATE_CITY) {
      window.CA_STATE_CITY.loadStates().catch(function () { /* retry on open */ });
      window.CA_STATE_CITY.initAllPairs(document);
    }
    var initialPage = window.__CRM_INITIAL_PAGE__ || 'dashboard';
    onPage(initialPage);
    loadNotifications(true).then(function () {
      notificationPollStopped = false;
      notificationPollFailures = 0;
      if (typeof window.refreshNotificationsUI === 'function') window.refreshNotificationsUI();
      startNotificationPoller(window.refreshNotificationsUI);
    }).catch(function () {
      stopNotificationPoller();
    });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        stopNotificationPoller();
        return;
      }
      if (!notificationPollStopped && notificationsCache.length) {
        startNotificationPoller(window.refreshNotificationsUI);
      }
    });
    window.addEventListener('pagehide', stopNotificationPoller);
  }

  function reloadListing(key) {
    if (!window.CA_LISTING_SEARCH) return Promise.resolve();
    var cfg = CA_LISTING_SEARCH.REGISTRY[key];
    if (!cfg) return Promise.resolve();

    var extra = {};
    if (key === 'ca_masters') {
      var onLeadsHub = !!(document.getElementById('leads-data-table') || document.getElementById('leads-kpi-strip'));
      if (onLeadsHub && window._leadSegmentFilter && window._leadSegmentFilter !== 'all') {
        extra.segment = window._leadSegmentFilter;
      }
      if (onLeadsHub) {
        Object.assign(extra, CA_LISTING_SEARCH.readLeadDrawerFilters());
      }
    }
    if (key === 'activity_logs') Object.assign(extra, readActivityFiltersFromForm());
    if (key === 'activity_logs' && document.getElementById('audit-logs-table')) Object.assign(extra, readAuditFiltersFromForm());
    if (key === 'consent_trackings' && consentDndChannelFilter) extra.consent_type = consentDndChannelFilter;
    if (key === 'follow_ups') {
      if (window._followupDateFilter) {
        extra.followup_due = window._followupDateFilter;
      } else if (window.CA_LISTING_SEARCH) {
        var fuState = CA_LISTING_SEARCH.getState('follow_ups');
        if (fuState && fuState.filters && fuState.filters.followup_due) {
          extra.followup_due = fuState.filters.followup_due;
        }
      }
    }
    if (key === 'dnd_management') {
      var dndType = mapChannelToDndType(consentDndChannelFilter);
      if (dndType) extra.dnd_type = dndType;
    }

    return apiFetch(cfg.endpoint + listingPageQuery(key, extra))
      .then(function (body) {
        var parsed = CA_LISTING_SEARCH.unwrapListingBody(body, cfg.itemsKey || 'items');
        var items = parsed.items || [];

        if (key === 'ca_masters') {
          var leads = items.map(mapLeadRecord);
          window._listingLeadsPage = leads;
          window.realLeads = leads.slice();
          realLeadsLoaded = true;
          if (document.getElementById('leads-data-table')) renderLeadsTable(leads);
          if (document.getElementById('ca-master-data-table')) renderCaMasterTable(leads);
          var paginationTableId = document.getElementById('ca-master-data-table')
            ? 'ca-master-data-table'
            : 'leads-data-table';
          applyListingPagination(key, paginationTableId, body);
          if (document.getElementById('leads-data-table')) {
            CA_LISTING_SEARCH.bindSortableHeaders(key, 'leads-data-table', { 1: 'firm_name', 2: 'ca_name', 7: 'status' });
          }
        } else if (key === 'employees') {
          var employees = items.map(mapEmployeeRecord);
          window.realEmployees = employees;
          realEmployeesLoaded = true;
          renderEmployeesTable(employees);
          if (document.getElementById('leaderboard')) renderLeaderboard();
          applyListingPagination(key, cfg.tableId, body);
        } else if (key === 'lead_assignments') {
          window.realAssignments = items;
          renderAssignmentTable(items);
          renderAssignmentKpis();
          applyListingPagination(key, cfg.tableId, body);
        } else if (key === 'follow_ups') {
          window.realFollowUps = items;
          renderFollowupsTable(items);
          renderFollowupKpis();
          renderFollowupCalendarFromData();
          applyListingPagination(key, cfg.tableId, body);
        } else if (key === 'activity_logs') {
          activityLogsCache = items;
          if (parsed.filter_options) activityFilterOptions = parsed.filter_options;
          renderActivityLogsTable(items);
          if (document.getElementById('audit-logs-table')) renderAuditLogsTable(items);
          applyListingPagination(key, cfg.tableId, body);
        } else if (key === 'assignment_histories') {
          renderAssignmentHistoryTable(items);
          applyListingPagination(key, cfg.tableId, body);
        } else if (key === 'consent_trackings') {
          window.realConsentRecords = items;
          renderConsentRecordsTable(items);
          applyListingPagination(key, cfg.tableId, body);
        } else if (key === 'dnd_management') {
          window.realDndRecords = items;
          renderDndRecordsTable(items);
          applyListingPagination(key, cfg.tableId, body);
        } else if (key === 'bulk_operations') {
          window._bulkOperationsHistoryCache = items;
          renderBulkOperationsHistoryTable(items);
          applyListingPagination(key, cfg.tableId, body);
        } else if (key === 'states') {
          window.realStates = items;
          renderMasterTables();
          applyListingPagination(key, cfg.tableId, body);
        } else if (key === 'cities') {
          window.realCitiesCache = items;
          var citiesEl = document.getElementById('master-cities-table');
          if (citiesEl) {
            citiesEl.innerHTML = items.length ? items.map(function (c) {
              return '<tr class="ca-table-row">' +
                '<td class="font-medium">' + escapeHtml(c.city_name) + '</td>' +
                '<td>' + escapeHtml(c.state_name || (c.state && c.state.state_name) || '—') + '</td>' +
                '<td>—</td>' +
                '<td class="text-caption">' + formatRelativeDate(c.created_at) + '</td>' +
                '<td class="text-right whitespace-nowrap">' + masterActionButtons('city', c.city_id) + '</td>' +
              '</tr>';
            }).join('') : '<tr><td colspan="5" class="text-center text-slate-500 p-4">No cities yet.</td></tr>';
          }
          applyListingPagination(key, cfg.tableId, body);
        }

        return parsed;
      })
      .catch(function (error) {
        toast(error && error.message ? error.message : 'Unable to load data.', 'error');
        return null;
      });
  }

  return {
    init: init,
    apiFetch: apiFetch,
    onPage: onPage,
    refreshAll: refreshAll,
    openModal: openModal,
    populateSelects: populateSelects,
    renderLeadsHub: renderLeadsHub,
    openLeadFormForEdit: openLeadFormForEdit,
    openLeadFormForAdd: openLeadFormForAdd,
    selectLead: selectLead,
    setLeadFilter: function (f) { window._leadSegmentFilter = f || 'all'; },
    renderBulkImportSummary: renderBulkImportSummary,
    initBulkAssignmentPanel: initBulkAssignmentPanel,
    initBulkImportWizard: initBulkImportWizard,
    initBulkExportPanel: initBulkExportPanel,
    initBulkStatusUpdatePanel: initBulkStatusUpdatePanel,
    loadBulkImportHistory: loadBulkImportHistory,
    loadBulkOperationsHistory: loadBulkOperationsHistory,
    openBulkImportDetail: openBulkImportDetail,
    openBulkExportDetail: openBulkExportDetail,
    initDbHealthPage: initDbHealthPage,
    initQueuePage: initQueuePage,
    renderFollowupCalendarFromData: renderFollowupCalendarFromData,
    initAuditPage: initAuditPage,
    initSettingsPage: initSettingsPage,
    initSecurityPage: initSecurityPage,
    initQuickActions: initQuickActions,
    openEmployeeFormForEdit: openEmployeeFormForEdit,
    initActivityLogsPage: initActivityLogsPage,
    renderActivityTimeline: renderActivityTimeline,
    getActivityLogsForExport: getActivityLogsForExport,
    refreshWhatsAppPage: refreshWhatsAppPage,
    refreshEmailPage: refreshEmailPage,
    refreshSmsPage: refreshSmsPage,
    reloadListing: reloadListing,
    loadNotifications: loadNotifications,
    pollNotifications: pollNotifications,
    markNotificationReadApi: markNotificationReadApi,
    markAllNotificationsReadApi: markAllNotificationsReadApi,
    getNotificationsCache: getNotificationsCache,
    getUnreadNotificationCount: getUnreadNotificationCount,
    startNotificationPoller: startNotificationPoller,
    stopNotificationPoller: stopNotificationPoller,
    initReportsFilters: initReportsFilters,
    loadReportsAnalytics: loadReportsAnalytics,
    renderReportCharts: renderReportCharts,
    openReport: openReport,
    exportReport: exportReport,
    exportReportsSummary: exportReportsSummary,
    refreshReportsHub: refreshReportsHub,
    initPasswordToggleButtons: initPasswordToggleButtons,
    populateResetPasswordEmployeeSelect: populateResetPasswordEmployeeSelect,
    configureEmployeeCrmRoleSelect: configureEmployeeCrmRoleSelect,
  };
})();
