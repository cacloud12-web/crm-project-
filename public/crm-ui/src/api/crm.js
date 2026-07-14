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

  function iconsIn(root) {
    if (window.CAActionDropdown && typeof window.CAActionDropdown.iconsIn === 'function') {
      window.CAActionDropdown.iconsIn(root || document);
      return;
    }
    icons();
  }

  function toast(msg, type) {
    if (typeof showToast === 'function') showToast(msg, type || 'info');
  }

  function iconBtn(icon, label, attrs, variant) {
    if (window.CA_ICON_BTN && typeof window.CA_ICON_BTN.icon === 'function') {
      return window.CA_ICON_BTN.icon(icon, label, attrs, variant);
    }
    var title = String(label || '').replace(/"/g, '&quot;');
    var cls = 'crm-toolbar-icon-btn';
    if (variant === 'primary') cls += ' crm-toolbar-icon-btn--primary';
    if (variant === 'danger') cls += ' crm-toolbar-icon-btn--danger';
    attrs = String(attrs || '').trim();
    return '<button type="button" class="' + cls + '"' + (attrs ? ' ' + attrs : '') +
      ' data-crm-tip="' + title + '" aria-label="' + title + '">' +
      '<i data-lucide="' + (icon || 'circle') + '" class="h-4 w-4"></i></button>';
  }

  function openModal(el) {
    if (typeof window.openModal === 'function' && window.openModal !== openModal) {
      window.openModal(el);
      return;
    }
    if (el) {
      el.classList.add('open');
      document.getElementById('overlay')?.classList.add('active');
      if (typeof window.setCrmScrollLock === 'function') window.setCrmScrollLock(true);
      else document.body.style.overflow = 'hidden';
    }
  }

  function closeModal(el) {
    if (window.CrmDateTimePicker && typeof window.CrmDateTimePicker.close === 'function') {
      window.CrmDateTimePicker.close({ focus: false });
    }
    if (el) el.classList.remove('open');
    document.getElementById('overlay')?.classList.remove('active');
    if (typeof window.setCrmScrollLock === 'function') window.setCrmScrollLock(false);
    else document.body.style.overflow = '';
  }

  function closeAllCrmModals() {
    document.querySelectorAll('.ca-modal.open').forEach(function (m) { m.classList.remove('open'); });
  }

  function resetModalScroll(el) {
    if (!el) return;
    var bodies = el.querySelectorAll('.ca-modal-body');
    bodies.forEach(function (body) {
      body.scrollTop = 0;
    });
    if (el.classList.contains('ca-modal-body')) {
      el.scrollTop = 0;
    }
  }

  function openExclusiveCrmModal(el) {
    if (!el) return;
    document.querySelectorAll('.ca-modal.open').forEach(function (m) {
      if (m !== el) m.classList.remove('open');
    });
    openModal(el);
    resetModalScroll(el);
    requestAnimationFrame(function () {
      resetModalScroll(el);
    });
    if (window.CrmDateTimePicker) {
      requestAnimationFrame(function () {
        window.CrmDateTimePicker.initAll(el, { force: true });
      });
    }
  }

  function isLeadModalOpen() {
    return document.getElementById('modal-add-lead')?.classList.contains('open') === true;
  }

  function isCampaignModalOpen() {
    return document.getElementById('modal-add-campaign')?.classList.contains('open') === true;
  }

  function campaignNameFromForm(data) {
    return String((data && (data.campaign_name || data.name)) || '').trim();
  }

  function stars(n) {
    n = Math.min(5, Math.max(1, n || 3));
    return '★'.repeat(n) + '☆'.repeat(5 - n);
  }

  function statusBadge(s) {
    var label = s || '—';
    var map = {
      Hot: 'bg-amber-50 text-amber-700',
      Interested: 'bg-amber-50 text-amber-700',
      Thinking: 'bg-amber-50 text-amber-700',
      Purchasing: 'bg-amber-50 text-amber-700',
      'Demo Scheduled': 'badge-brand',
      'Demo Completed': 'badge-brand',
      'Follow Up Scheduled': 'bg-blue-50 text-blue-700',
      'Follow Up Reminder': 'bg-blue-50 text-blue-700',
      Active: 'badge-success',
      Purchased: 'badge-success',
      Inactive: 'bg-slate-100 text-slate-600',
      Lost: 'badge-danger',
      'Not Interested': 'badge-danger',
      New: 'badge-brand',
      Pipeline: 'badge-brand',
      Negotiation: 'badge-brand',
      'Details Shared': 'badge-brand',
      Warm: 'bg-blue-50 text-blue-700',
      'Next Week': 'bg-violet-50 text-violet-700',
      'Next Month': 'bg-violet-50 text-violet-700',
      Hold: 'bg-violet-50 text-violet-700',
      Cold: 'bg-slate-100 text-slate-600',
    };
    return '<span class="badge ' + (map[label] || 'badge-brand') + '">' + escapeHtml(label) + '</span>';
  }
  var realLeadsLoaded = false;
  var kanbanLeadsLoaded = false;
  var _kanbanLoadGeneration = 0;
  window.kanbanLeads = [];
  window._listingLeadsPage = [];
  var realEmployeesLoaded = false;
  var realAssignmentsLoaded = false;
  var realFollowUpsLoaded = false;
  var masterDataLoaded = false;
  var dashboardMetricsLoaded = false;
  var dashboardMetricsPromise = null;
  var dashboardMetricsRequestSeq = 0;
  var _leadsLoadGeneration = 0;
  var _assignmentsLoadGeneration = 0;
  var _listingLoadGeneration = 0;
  var leadsHubLoading = false;
  var DASHBOARD_CACHE_TTL_MS = 120000;
  var LISTING_PAGE_CACHE_TTL_MS = 30000;
  var LISTING_PAGE_CACHE_KEYS = ['ca_masters', 'follow_ups', 'sales_list', 'lead_assignments', 'employees'];
  var employeeDashboardLoaded = false;
  var employeeDashboardPromise = null;
  var employeeDashboardData = null;
  var leadSegmentCounts = null;
  var leadSegmentCountsLoaded = false;
  var leadSegmentCountsPromise = null;
  var kanbanStageSearch = {};
  var kanbanStageSearchTimers = {};
  window.kanbanStageCounts = {};
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
    ca_master: 'Master Data',
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
    sales_list: 'Sales List',
    email_configuration: 'Email Configuration',
    google_api: 'Google API Settings',
    whatsapp_templates: 'WhatsApp Templates',
    email_templates: 'Email Templates',
    sources: 'Sources',
    team_size: 'Team Size',
    lead_status: 'Lead Status',
    roles_permissions: 'Roles & Permissions',
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
    'Call Logged': { icon: 'phone', color: 'bg-brand' },
    'Call Status': { icon: 'phone', color: 'bg-brand' },
    'Call Created': { icon: 'phone', color: 'bg-brand' },
    'Follow-up Created': { icon: 'calendar-plus', color: 'bg-blue-500' },
    'Follow-up Completed': { icon: 'check-circle', color: 'bg-emerald-500' },
    'Follow-up Rescheduled': { icon: 'calendar-clock', color: 'bg-amber-500' },
    'Demo Completed': { icon: 'video', color: 'bg-violet-500' },
    'Email Sent': { icon: 'mail', color: 'bg-sky-500' },
    'SMS Sent': { icon: 'smartphone', color: 'bg-indigo-500' },
    'WhatsApp Sent': { icon: 'message-circle', color: 'bg-emerald-500' },
    'Purchased': { icon: 'shopping-bag', color: 'bg-emerald-600' },
    'Not Interested': { icon: 'x-circle', color: 'bg-rose-500' },
    'Status Changed': { icon: 'git-branch', color: 'bg-slate-500' },
    'Call Completed': { icon: 'phone-call', color: 'bg-emerald-500' },
    'Follow-up Added': { icon: 'calendar-plus', color: 'bg-blue-500' },
    'Remarks Updated': { icon: 'file-edit', color: 'bg-slate-500' },
    'Demo Cancelled': { icon: 'calendar-x', color: 'bg-rose-500' },
    'Customer Requested Callback': { icon: 'phone-incoming', color: 'bg-amber-500' },
    'Lead Assigned': { icon: 'user-check', color: 'bg-indigo-500' },
  };

  var followupActivitySort = 'desc';
  var followupActivityPage = 1;
  var followupActivityPeriod = 'all';
  var followupActivityPageSize = 10;
  var followupActivityTotal = 0;
  var followupActivityIsDemo = false;
  var followupActivityHasApiData = false;
  var followupActivityLoading = false;
  var followupActivityPaginationRegistered = false;

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

  function listingPaginationSlot(key) {
    var slots = {
      employees: 'employees-pagination-slot',
      follow_ups: 'followups-pagination-slot',
      activity_logs: 'activity-pagination-slot',
      consent_trackings: 'consent-pagination-slot',
      dnd_management: 'dnd-pagination-slot',
      bulk_operations: 'bulk-operations-pagination-slot',
      states: 'master-states-pagination-slot',
      cities: 'master-cities-pagination-slot',
      source_leads: 'master-sources-pagination-slot',
      team_sizes: 'master-team-sizes-pagination-slot',
      role_masters: 'master-roles-pagination-slot',
      wa_message_logs: 'wa-logs-pagination-slot',
      email_logs: 'email-logs-pagination-slot',
      sms_logs: 'sms-logs-pagination-slot',
    };
    var id = slots[key];
    return id && document.getElementById(id) ? id : null;
  }

  function applyListingPagination(key, tableId, body, slotId) {
    if (!window.CA_LISTING_SEARCH || !body) return;
    var parsed = CA_LISTING_SEARCH.unwrapListingBody(body);
    if (parsed.pagination && parsed.pagination.per_page) {
      CA_LISTING_SEARCH.setState(key, { per_page: parsed.pagination.per_page });
    }
    if (parsed.pagination) {
      CA_LISTING_SEARCH.renderPaginationBar(key, tableId, parsed.pagination, slotId || listingPaginationSlot(key));
    }
  }

  function getCaMasterTableContext() {
    return {
      tbodyId: 'ca-master-data-table',
      tableId: 'ca-master-table',
      paginationSlot: 'ca-master-pagination-slot',
    };
  }

  function isMasterDataHub() {
    return !!document.getElementById('cam-hub');
  }

  function isCamPipelineTabActive() {
    var primary = document.getElementById('cam-primary-views');
    if (primary && primary.classList.contains('hidden')) return false;
    var panel = document.querySelector('.ca-tab-panel[data-tab-group="cam-view"][data-panel="pipeline"]');
    return !!(panel && panel.classList.contains('active'));
  }

  function isCamAllFirmsTabActive() {
    var primary = document.getElementById('cam-primary-views');
    if (primary && primary.classList.contains('hidden')) return false;
    var panel = document.querySelector('.ca-tab-panel[data-tab-group="cam-view"][data-panel="all"]');
    return !!(panel && panel.classList.contains('active'));
  }

  function formatApiErrorMessage(error, fallback) {
    if (!error) return fallback || 'Request failed';
    if (error.errors && typeof error.errors === 'object') {
      var messages = [];
      Object.keys(error.errors).forEach(function (key) {
        var items = error.errors[key];
        if (Array.isArray(items)) {
          items.forEach(function (item) {
            if (item) messages.push(String(item));
          });
        }
      });
      if (messages.length) return messages.join(' ');
    }
    return error.message || fallback || 'Request failed';
  }

  function apiFetch(url, options) {
    options = options || {};
    options.headers = Object.assign({
      'X-CSRF-TOKEN': csrfToken(),
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    }, options.headers || {});
    var timeoutMs = options.timeoutMs;
    var controller = null;
    var timeoutId = null;
    if (timeoutMs && typeof AbortController !== 'undefined') {
      controller = new AbortController();
      options.signal = controller.signal;
      timeoutId = setTimeout(function () {
        controller.abort();
      }, timeoutMs);
    }
    return fetch(url, options).then(function (response) {
      if (timeoutId) clearTimeout(timeoutId);
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
          } else if (response.status === 423) {
            message = body.message || 'This lead is currently being edited by another employee.';
          } else if (response.status === 409 && body.errors && body.errors.duplicate) {
            message = body.message || 'This phone number already exists.';
          } else if (response.status === 500) {
            message = body.message || 'A server error occurred. Please try again or contact support.';
          }
          var err = new Error(message);
          err.status = response.status;
          err.errors = body.errors || null;
          if (response.status === 423 && body.errors && body.errors.lock) {
            err.lock = body.errors.lock;
          }
          if (response.status === 409 && body.errors && body.errors.duplicate) {
            err.duplicate = body.errors.duplicate;
          }
          if (response.status === 409 && body.data) {
            err.data = body.data;
          }
          throw err;
        }
        return body;
      });
    }).catch(function (error) {
      if (timeoutId) clearTimeout(timeoutId);
      if (error && error.name === 'AbortError') {
        throw new Error('Request timed out. Please try again.');
      }
      if (error && error.message === 'Failed to fetch') {
        throw new Error('Unable to reach the server. Please refresh the page and try again.');
      }
      throw error;
    });
  }

  function mapStatusToStage(status) {
    var pipeline = window.__CRM_SALES_PIPELINE__;
    if (pipeline && pipeline.stage_statuses) {
      var stages = pipeline.stage_statuses;
      for (var stage in stages) {
        if (Object.prototype.hasOwnProperty.call(stages, stage) && stages[stage].indexOf(status) >= 0) {
          return stage;
        }
      }
      return 'New Lead';
    }
    var map = {
      New: 'New Lead',
      Cold: 'New Lead',
      Hot: 'Negotiation',
      Negotiation: 'Negotiation',
      Interested: 'Negotiation',
      Thinking: 'Negotiation',
      Purchasing: 'Negotiation',
      'Demo Scheduled': 'Demo Scheduled',
      'Demo Completed': 'Demo Completed',
      'Details Shared': 'Details Shared',
      Pipeline: 'Details Shared',
      Warm: 'Demo Completed',
      Lost: 'Lost',
      Inactive: 'Lost',
      'Not Interested': 'Lost',
      Active: 'Won',
      Purchased: 'Won',
      Purchasing: 'Won',
      'Next Week': 'Demo Completed',
      'Next Month': 'Demo Completed',
      Hold: 'Demo Completed',
      'Follow Up Scheduled': 'Details Shared',
      'Follow Up Reminder': 'Details Shared',
    };
    return map[status] || 'New Lead';
  }

  function mapStageToStatus(stage) {
    if (isMasterDataHub()) {
      return mapMasterPipelineStageToStatus(stage);
    }
    var salesPipeline = window.__CRM_SALES_PIPELINE__;
    if (salesPipeline && salesPipeline.stage_to_status && salesPipeline.stage_to_status[stage]) {
      return salesPipeline.stage_to_status[stage];
    }
    var map = {
      'New Lead': 'New',
      'Details Shared': 'Pipeline',
      'Demo Scheduled': 'Demo Scheduled',
      'Demo Completed': 'Demo Completed',
      'Negotiation': 'Hot',
      'Won': 'Active',
      'Lost': 'Lost',
    };
    return map[stage] || 'New';
  }

  function mapStatusToMasterPipelineStage(status) {
    var pipeline = window.__CRM_MASTER_PIPELINE__;
    if (pipeline && pipeline.stage_statuses) {
      var stages = pipeline.stage_statuses;
      for (var stage in stages) {
        if (Object.prototype.hasOwnProperty.call(stages, stage) && stages[stage].indexOf(status) >= 0) {
          return stage;
        }
      }
      return 'New Lead';
    }
    var map = {
      New: 'New Lead',
      Cold: 'New Lead',
      Lost: 'New Lead',
      Inactive: 'New Lead',
      'Not Interested': 'New Lead',
      Contacted: 'Contacted',
      'Details Shared': 'Contacted',
      Pipeline: 'Contacted',
      Warm: 'Contacted',
      'Follow Up Scheduled': 'Contacted',
      'Follow Up Reminder': 'Contacted',
      'Demo Scheduled': 'Contacted',
      Interested: 'Interested',
      Thinking: 'Interested',
      Hot: 'Interested',
      Negotiation: 'Interested',
      'Demo Completed': 'Interested',
      Hold: 'Interested',
      'Next Week': 'Interested',
      'Next Month': 'Interested',
      Purchasing: 'Interested',
      Converted: 'Converted',
      Active: 'Converted',
      Purchased: 'Converted',
    };
    return map[status] || 'New Lead';
  }

  function mapMasterPipelineStageToStatus(stage) {
    var pipeline = window.__CRM_MASTER_PIPELINE__;
    if (pipeline && pipeline.stage_to_status && pipeline.stage_to_status[stage]) {
      return pipeline.stage_to_status[stage];
    }
    var map = {
      'New Lead': 'New',
      Contacted: 'Contacted',
      Interested: 'Interested',
      Converted: 'Converted',
    };
    return map[stage] || 'New';
  }

  function leadPipelineStage(lead) {
    if (isMasterDataHub()) {
      return lead.master_pipeline_stage || mapStatusToMasterPipelineStage(lead.status);
    }
    return lead.stage || mapStatusToStage(lead.status);
  }

  function getMasterPipelineColumns() {
    var pipeline = window.__CRM_MASTER_PIPELINE__;
    if (pipeline && Array.isArray(pipeline.columns) && pipeline.columns.length) {
      return pipeline.columns.map(function (col) {
        return {
          name: col.label || col.key,
          key: col.key,
          icon: col.icon || 'circle',
          theme: col.theme || 'new-lead',
        };
      });
    }
    return [
      { name: 'New Lead', key: 'New Lead', icon: 'sparkles', theme: 'new-lead' },
      { name: 'Contacted', key: 'Contacted', icon: 'phone-call', theme: 'contacted' },
      { name: 'Interested', key: 'Interested', icon: 'handshake', theme: 'interested' },
      { name: 'Converted', key: 'Converted', icon: 'circle-check', theme: 'converted' },
    ];
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

  function previewTextCell(text, cellClass) {
    var raw = text == null || text === '' ? '' : String(text).trim();
    if (!raw) {
      return '<span class="cam-cell-text cam-cell-empty">—</span>';
    }
    var escaped = escapeHtml(raw);
    return '<span class="truncate-cell ' + (cellClass || '') + '" title="' + escaped + '">' + escaped + '</span>';
  }

  function firmNameCell(text) {
    return previewTextCell(text, 'crm-firm-cell');
  }

  function caNameCell(text) {
    return previewTextCell(text, 'crm-ca-cell');
  }

  function compactTextCell(text, opts) {
    opts = opts || {};
    var raw = text == null || text === '' ? '' : String(text);
    if (!raw) {
      return '<span class="cam-cell-text cam-cell-empty">' + escapeHtml(opts.fallback || '—') + '</span>';
    }
    var escaped = escapeHtml(raw);
    return '<span class="cam-cell-text" title="' + escaped + '">' + escaped + '</span>';
  }

  function camPhoneCell(raw) {
    var display = formatPhoneDisplay(raw);
    if (!display) {
      return '<span class="cam-cell-text cam-cell-empty">—</span>';
    }
    var escaped = escapeHtml(display);
    return '<a href="tel:' + escaped + '" class="cam-cell-text cam-cell-mono text-brand hover:underline" title="' + escaped + '" onclick="event.stopPropagation();">' + escaped + '</a>';
  }

  function camPhoneDisplayCell(raw) {
    var display = formatPhoneDisplay(raw);
    if (!display) {
      return '<span class="cam-cell-text cam-cell-empty">—</span>';
    }
    var escaped = escapeHtml(display);
    return '<span class="cam-cell-text cam-cell-mono cam-master-display-text" title="' + escaped + '">' + escaped + '</span>';
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

  var GOOGLE_LOOKUP_INSUFFICIENT_MSG = 'Insufficient lead information for Google Lookup. Add a Firm Name, CA Name, or Mobile Number first.';
  var _leadResearchOpening = {};

  function leadSearchPhone(value) {
    var text = leadFieldText(value);
    if (!text) return '';
    var digits = text.replace(/\D/g, '');
    return digits.length >= 10 ? digits : '';
  }

  function buildLeadGoogleSearchQuery(lead) {
    var firm = leadFieldText(lead.firm_name);
    var ca = leadFieldText(lead.ca_name);
    var city = leadFieldText(lead.city || lead.city_name);
    var state = leadFieldText(lead.state || lead.state_name);
    var mobile = leadSearchPhone(lead.mobile_no) || leadSearchPhone(lead.alternate_mobile_no);

    if (firm && ca) {
      return [firm, ca, city, state, 'Chartered Accountant'].filter(Boolean).join(' ').trim();
    }
    if (firm && (city || state)) {
      return [firm, city, state, 'Chartered Accountant'].filter(Boolean).join(' ').trim();
    }
    if (ca && (city || state)) {
      return [ca, city, state, 'Chartered Accountant'].filter(Boolean).join(' ').trim();
    }
    if (firm) return (firm + ' Chartered Accountant').trim();
    if (ca) return (ca + ' Chartered Accountant').trim();
    if (mobile) return mobile;
    if (city || state) return [city, state].filter(Boolean).join(' ').trim();
    return '';
  }

  function hasSearchableLeadForGoogleLookup(lead) {
    return buildLeadGoogleSearchQuery(lead) !== '';
  }

  function buildLeadGoogleMapsQuery(lead) {
    return buildLeadGoogleSearchQuery(lead);
  }

  function researchLeadIconSvg() {
    return '<svg class="lead-quick-icon" viewBox="0 0 48 48" aria-hidden="true" focusable="false">' +
      '<circle cx="20" cy="20" r="11" fill="none" stroke="#25B7A7" stroke-width="3.5"/>' +
      '<path d="M28.5 28.5L38 38" stroke="#25B7A7" stroke-width="3.5" stroke-linecap="round"/>' +
      '<path fill="#EA4335" d="M20 12c-4.4 0-8 3.6-8 8 0 6 8 14 8 14s8-8 8-14c0-4.4-3.6-8-8-8z"/>' +
      '<circle cx="20" cy="20" r="3" fill="#fff"/>' +
    '</svg>';
  }

  function canUseLeadQuickActions(lead) {
    if (!lead) return false;
    if (!isEmployeeUser()) return true;
    var u = window.__CRM_USER__ || {};
    var empId = u.employee_id ? String(u.employee_id) : '';
    if (empId && lead.executive_id && String(lead.executive_id) === empId) return true;
    var executive = lead.executive || '';
    return !!(executive && executive !== 'Unassigned' && executive !== '—' && executive !== 'Assigned');
  }

  function renderLeadResearchQuickCell(lead, opts) {
    opts = opts || {};
    var cellCls = 'lead-quick-cell lead-quick-cell--research';
    if (opts.master) cellCls += ' cam-col-google';
    if (!canUseLeadQuickActions(lead)) {
      return '<td class="' + cellCls + '"><span class="cam-cell-empty">—</span></td>';
    }
    var firmLabel = leadFieldText(lead.firm_name) || leadFieldText(lead.ca_name) || ('Lead ' + lead.ca_id);
    var tip = opts.tip || 'Google Lookup';
    var ariaLabel = opts.master
      ? ('Open Google Places Lookup for ' + firmLabel)
      : tip;
    return '<td class="' + cellCls + '">' +
      '<button type="button" class="lead-quick-btn lead-quick-btn--research" data-lead-quick="research" data-lead-id="' +
      escapeHtml(lead.ca_id) + '" title="' + escapeHtml(tip) + '" data-crm-tip="' + escapeHtml(tip) + '" aria-label="' + escapeHtml(ariaLabel) + '">' +
      researchLeadIconSvg() + '</button></td>';
  }

  function canUseLeadCallLog(lead) {
    var employeeOk = !isEmployeeUser() || (lead && canUseLeadQuickActions(lead));
    if (!employeeOk) return false;
    if (isMasterDataHub()) {
      return crmCanAction('ca_master', 'view') || crmCanAction('followups', 'schedule_followup');
    }
    return crmCanAction('leads', 'view')
      || crmCanAction('followups', 'schedule_followup')
      || crmCanAction('followups', 'create');
  }

  function renderLeadCallLogQuickCell(lead) {
    if (!canUseLeadCallLog(lead)) {
      return '<td class="lead-quick-cell lead-quick-cell--call-log"><span class="cam-cell-empty">—</span></td>';
    }
    return '<td class="lead-quick-cell lead-quick-cell--call-log">' +
      '<button type="button" class="lead-quick-btn lead-quick-btn--call-log" data-lead-quick="call-log" data-lead-id="' +
      escapeHtml(lead.ca_id) + '" title="Call Log" aria-label="Call Log" data-crm-tip="Call Log">' +
      '<i data-lucide="phone-call" class="lead-quick-icon h-4 w-4" aria-hidden="true"></i></button></td>';
  }

  /* ─── Inbox-style row selection (checkbox column) ─── */
  window._crmInboxSelected = window._crmInboxSelected || {};

  function inboxSelectedMap(tableKey) {
    if (!window._crmInboxSelected[tableKey]) window._crmInboxSelected[tableKey] = {};
    return window._crmInboxSelected[tableKey];
  }

  function normalizeInboxId(rawId) {
    if (rawId === null || rawId === undefined || rawId === '') return '';
    var id = String(rawId).trim();
    if (!/^\d+$/.test(id)) return '';
    return id;
  }

  function getInboxSelectedIds(tableKey) {
    var map = inboxSelectedMap(tableKey);
    return Object.keys(map)
      .map(normalizeInboxId)
      .filter(function (id) { return id && map[id]; })
      .filter(function (id, index, arr) { return arr.indexOf(id) === index; });
  }

  function getInboxSelectedLeadIds(tableKey) {
    return getInboxSelectedIds(tableKey).map(function (id) { return parseInt(id, 10); }).filter(function (id) { return id > 0; });
  }

  function resolveLeadLabelsForIds(ids) {
    var leads = (window.realLeads || []).concat(window.realCaMasters || []);
    var byId = {};
    leads.forEach(function (lead) {
      if (lead && lead.ca_id != null) byId[String(lead.ca_id)] = lead;
    });
    return (ids || []).map(function (id) {
      var lead = byId[String(id)];
      if (!lead) return 'Lead #' + id;
      return lead.firm_name || lead.ca_name || ('Lead #' + id);
    });
  }

  function renderInboxCheckCell(tableKey, rowId) {
    var id = normalizeInboxId(rowId);
    if (!id) return '<td class="crm-td-check sticky-left"></td>';
    var checked = !!inboxSelectedMap(tableKey)[id];
    return '<td class="crm-td-check sticky-left">' +
      '<input type="checkbox" class="crm-inbox-row-check" data-inbox-table="' + escapeHtml(tableKey) +
      '" data-inbox-id="' + escapeHtml(id) + '"' + (checked ? ' checked' : '') +
      ' aria-label="Select lead ' + escapeHtml(id) + '" />' +
    '</td>';
  }

  function updateInboxBulkBar(tableKey) {
    var ids = getInboxSelectedIds(tableKey);
    var bar = document.getElementById(tableKey + '-bulk-bar');
    var countEl = document.querySelector('[data-inbox-count="' + tableKey + '"]');
    if (countEl) countEl.textContent = ids.length + ' selected';
    if (bar) {
      var wasHidden = bar.classList.contains('hidden');
      bar.classList.toggle('hidden', ids.length === 0);
      if (ids.length > 0 && wasHidden) icons();
    }

    var tbody = document.getElementById(tableKey);
    var selectAll = document.querySelector('.crm-inbox-check-all[data-inbox-table="' + tableKey + '"]');
    if (selectAll && tbody) {
      var rowChecks = tbody.querySelectorAll('.crm-inbox-row-check');
      var checkedCount = 0;
      rowChecks.forEach(function (cb) { if (cb.checked) checkedCount++; });
      selectAll.checked = rowChecks.length > 0 && checkedCount === rowChecks.length;
      selectAll.indeterminate = checkedCount > 0 && checkedCount < rowChecks.length;
    }
  }

  function clearInboxSelection(tableKey) {
    window._crmInboxSelected[tableKey] = {};
    document.querySelectorAll('.crm-inbox-row-check[data-inbox-table="' + tableKey + '"]').forEach(function (cb) {
      cb.checked = false;
    });
    var selectAll = document.querySelector('.crm-inbox-check-all[data-inbox-table="' + tableKey + '"]');
    if (selectAll) {
      selectAll.checked = false;
      selectAll.indeterminate = false;
    }
    updateInboxBulkBar(tableKey);
  }

  function ensureInboxSelectionBound() {
    if (document._crmInboxSelectionBound) return;
    document._crmInboxSelectionBound = true;

    document.addEventListener('change', function (e) {
      var rowCheck = e.target.closest('.crm-inbox-row-check');
      if (rowCheck) {
        var tableKey = rowCheck.getAttribute('data-inbox-table');
        var id = normalizeInboxId(rowCheck.getAttribute('data-inbox-id'));
        if (!tableKey || !id) return;
        if (rowCheck.checked) inboxSelectedMap(tableKey)[id] = true;
        else delete inboxSelectedMap(tableKey)[id];
        updateInboxBulkBar(tableKey);
        return;
      }

      var allCheck = e.target.closest('.crm-inbox-check-all');
      if (allCheck) {
        var key = allCheck.getAttribute('data-inbox-table');
        var tbody = document.getElementById(key);
        if (!key || !tbody) return;
        var map = inboxSelectedMap(key);
        // Select-all only affects currently visible page rows (by lead ID, never by index).
        tbody.querySelectorAll('.crm-inbox-row-check').forEach(function (cb) {
          var rid = normalizeInboxId(cb.getAttribute('data-inbox-id'));
          if (!rid) return;
          cb.checked = allCheck.checked;
          if (allCheck.checked) map[rid] = true;
          else delete map[rid];
        });
        updateInboxBulkBar(key);
      }
    });

    document.addEventListener('click', function (e) {
      var actionBtn = e.target.closest('[data-inbox-action]');
      if (actionBtn) {
        e.preventDefault();
        e.stopPropagation();
        var bar = actionBtn.closest('[data-inbox-table], #assignment-bulk-bar');
        var tableKey = actionBtn.getAttribute('data-inbox-table')
          || (bar && bar.getAttribute('data-inbox-table'))
          || 'leads-data-table';
        var module = actionBtn.getAttribute('data-inbox-module')
          || (bar && bar.getAttribute('data-inbox-module'))
          || 'leads';
        handleInboxBulkAction(actionBtn.getAttribute('data-inbox-action'), tableKey, module);
        return;
      }
      var clearBtn = e.target.closest('[data-inbox-clear]');
      if (clearBtn) {
        e.preventDefault();
        clearInboxSelection(clearBtn.getAttribute('data-inbox-clear'));
        return;
      }
      if (e.target.closest('#assignment-bulk-clear')) {
        e.preventDefault();
        clearAssignmentBulkSelection();
      }
    });
  }

  function syncInboxChecks(tableKey) {
    ensureInboxSelectionBound();
    updateInboxBulkBar(tableKey);
    icons();
  }

  function ensureKpiCardsBound() {
    if (document._crmKpiCardsBound) return;
    document._crmKpiCardsBound = true;
    document.addEventListener('click', function (e) {
      var camCard = e.target.closest('[data-cam-summary]');
      if (camCard) {
        e.preventDefault();
        applyCaMasterSummaryFilter(camCard.getAttribute('data-cam-summary'));
        return;
      }
      var kpiCard = e.target.closest('[data-kpi-filter][data-kpi-listing="follow_ups"]');
      if (kpiCard) {
        e.preventDefault();
        applyFollowupKpiFilter(kpiCard.getAttribute('data-kpi-filter'));
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      var kpiCard = e.target.closest('[data-kpi-filter][data-kpi-listing="follow_ups"]');
      if (!kpiCard) return;
      e.preventDefault();
      applyFollowupKpiFilter(kpiCard.getAttribute('data-kpi-filter'));
    });
  }

  function setFollowupCalendarPopoverOpen(open) {
    var popover = document.getElementById('followup-cal-popover');
    var toggleBtn = document.getElementById('followup-cal-toggle');
    if (!popover) return;
    popover.classList.toggle('hidden', !open);
    if (toggleBtn) {
      toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggleBtn.classList.toggle('is-active', !!open);
    }
    if (open) {
      renderFollowupCalendarFromData();
      icons();
    }
  }

  function ensureFollowupCalendarPopoverBound() {
    if (document._followupCalPopoverBound) return;
    document._followupCalPopoverBound = true;

    document.addEventListener('click', function (e) {
      var popover = document.getElementById('followup-cal-popover');
      if (!popover) return;

      var toggle = e.target.closest('#followup-cal-toggle');
      if (toggle) {
        e.preventDefault();
        e.stopPropagation();
        setFollowupCalendarPopoverOpen(popover.classList.contains('hidden'));
        return;
      }

      if (e.target.closest('#followup-cal-close')) {
        e.preventDefault();
        setFollowupCalendarPopoverOpen(false);
        return;
      }

      if (!popover.classList.contains('hidden')
        && !e.target.closest('#followup-cal-popover')
        && !e.target.closest('#followup-cal-toggle')) {
        setFollowupCalendarPopoverOpen(false);
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') setFollowupCalendarPopoverOpen(false);
    });
  }

  function openInboxImport() {
    if (isMasterDataHub()) {
      showCamSecondaryView('bulk');
      if (typeof window.openBulkImportWizard === 'function') window.openBulkImportWizard();
      return;
    }
    if (typeof navigateTo === 'function') navigateTo('bulk');
    if (typeof window.openBulkImportWizard === 'function') {
      window.openBulkImportWizard();
    } else {
      setTimeout(function () {
        if (typeof window.openBulkImportWizard === 'function') window.openBulkImportWizard();
      }, 150);
    }
  }

  function openInboxAssign(ids) {
    var leadIds = (ids || []).map(function (id) { return parseInt(id, 10); }).filter(function (id) { return id > 0; });
    if (!leadIds.length) {
      toast('Select at least one lead', 'warning');
      return;
    }
    window._inboxBulkLeadIds = leadIds.slice();
    var leadId = leadIds[0];
    CAData.setSelectedLeadId(leadId);
    ensureFormSelectData(function () {
      populateSelects();
      var leadSelect = document.getElementById('form-assign-lead-select');
      var leadWrap = document.getElementById('assign-lead-select-wrap');
      var summary = document.getElementById('assign-bulk-summary');
      var titleText = document.querySelector('#assign-lead-title [data-assign-title-text]');
      setSelectValueIfValid(leadSelect, leadId);
      if (leadIds.length > 1) {
        if (leadWrap) leadWrap.classList.add('hidden');
        if (leadSelect) leadSelect.removeAttribute('required');
        if (summary) {
          summary.classList.remove('hidden');
          summary.textContent = 'Assigning ' + leadIds.length + ' selected leads to one employee.';
        }
        if (titleText) titleText.textContent = 'Assign ' + leadIds.length + ' Leads';
      } else {
        if (leadWrap) leadWrap.classList.remove('hidden');
        if (leadSelect) leadSelect.setAttribute('required', 'required');
        if (summary) {
          summary.classList.add('hidden');
          summary.textContent = '';
        }
        if (titleText) titleText.textContent = 'Assign Lead';
      }
      window._editingAssignmentId = null;
      openModal(document.getElementById('modal-assign-lead'));
      icons();
    });
  }

  function openInboxFollowup(ids) {
    var presetIds = (ids || []).map(function (id) { return parseInt(id, 10); }).filter(Boolean);
    if (presetIds.length !== 1) {
      toast('Select exactly one lead to schedule a follow-up.', 'warning');
      return;
    }
    window._inboxBulkLeadIds = null;
    window._editingFollowUpId = null;
    window._followupOriginalScheduled = '';
    openFollowupModalWithLeads(presetIds, { mode: 'row' });
  }

  function openInboxCampaign(channel, ids) {
    window._pendingCampaignLeadIds = (ids || []).map(function (id) { return parseInt(id, 10); }).filter(Boolean);
    var modal = document.getElementById('modal-add-campaign');
    if (typeof configureCampaignModal === 'function') configureCampaignModal(channel);
    if (modal) openExclusiveCrmModal(modal);
    setTimeout(function () { applyPendingCampaignLeadSelection(channel); }, 200);
    icons();
  }

  function exportInboxSelection(tableKey, module) {
    if (module === 'assignment' && window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.exportListing('lead_assignments');
      return;
    }
    if (module === 'employees' && window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.exportListing('employees');
      return;
    }
    if (module === 'followups' && window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.exportListing('follow_ups');
      return;
    }

    var selectedIds = getInboxSelectedLeadIds(tableKey);
    if (!selectedIds.length) {
      toast('Select at least one lead to export', 'warning');
      return;
    }

    toast('Exporting ' + selectedIds.length + ' selected lead(s)…', 'info');
    apiFetch('/ca-masters/bulk-export', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        scope: 'selected',
        format: 'csv',
        ca_ids: selectedIds,
      }),
    })
      .then(function (body) {
        var data = body.data || {};
        var exportedCount = data.total_rows || selectedIds.length;
        if (exportedCount !== selectedIds.length) {
          toast('Exported ' + exportedCount + ' of ' + selectedIds.length + ' selected leads', 'warning');
        }
        if (data.download_ready && data.bulk_action_id) {
          window.location.href = '/ca-masters/bulk-export/history/' + encodeURIComponent(data.bulk_action_id) + '/download';
          toast('Export ready for ' + exportedCount + ' selected lead(s)', 'success');
          return;
        }
        if (data.bulk_action_id) {
          toast('Export queued for ' + exportedCount + ' selected lead(s). Check Bulk Operations for download.', 'success');
          return;
        }
        toast('Export started for ' + exportedCount + ' selected lead(s)', 'success');
      })
      .catch(function (err) {
        toast(err.message || 'Unable to export selected leads', 'error');
      });
  }

  function bulkDeleteFollowups(followupIds, tableKey) {
    var ids = (followupIds || []).map(function (id) { return parseInt(id, 10); }).filter(function (id) { return id > 0; });
    if (!ids.length) {
      toast('Select at least one follow-up', 'warning');
      return;
    }

    Promise.all(ids.map(function (id) {
      return apiFetch('/follow-ups/' + encodeURIComponent(id), { method: 'DELETE' })
        .then(function () { return { id: id, ok: true }; })
        .catch(function (err) { return { id: id, ok: false, error: err }; });
    })).then(function (results) {
      var deleted = results.filter(function (r) { return r.ok; });
      var failed = results.filter(function (r) { return !r.ok; });
      deleted.forEach(function (r) {
        removeFollowupFromCache(r.id);
        removeFollowupTableRow(r.id);
      });
      clearInboxSelection(tableKey || 'followups-data-table');
      invalidateDataCaches(['metrics', 'followups']);
      refreshFollowupsPage({ reload: failed.length > 0, calendar: true });
      if (failed.length) {
        var firstError = failed[0] && failed[0].error;
        var detail = firstError && firstError.message ? ' ' + firstError.message : '';
        toast('Deleted ' + deleted.length + ' of ' + ids.length + ' selected follow-ups.' + detail, 'warning');
      } else {
        toast(deleted.length + ' follow-up(s) deleted', 'success');
      }
    });
  }

  function openBulkDeleteLeadsModal(tableKey, ids) {
    var leadIds = (ids || []).map(function (id) { return parseInt(id, 10); }).filter(function (id) { return id > 0; });
    if (!leadIds.length) {
      toast('Select at least one lead', 'warning');
      return;
    }
    window._pendingBulkDelete = { tableKey: tableKey, ids: leadIds };
    var modal = document.getElementById('modal-bulk-delete-leads');
    var message = document.getElementById('bulk-delete-leads-message');
    var namesEl = document.getElementById('bulk-delete-leads-names');
    var names = resolveLeadLabelsForIds(leadIds);
    if (message) {
      message.textContent = 'Are you sure you want to delete ' + leadIds.length + ' selected lead' + (leadIds.length === 1 ? '' : 's') + '?';
    }
    if (namesEl) {
      namesEl.innerHTML = names.slice(0, 20).map(function (name) {
        return '<li>' + escapeHtml(name) + '</li>';
      }).join('') + (names.length > 20 ? '<li>…and ' + (names.length - 20) + ' more</li>' : '');
    }
    openModal(modal);
    icons();
  }

  function confirmBulkDeleteLeads() {
    var pending = window._pendingBulkDelete;
    if (!pending || !pending.ids || !pending.ids.length) {
      toast('No leads selected', 'warning');
      return;
    }
    var selectedIds = pending.ids.slice();
    var tableKey = pending.tableKey || 'leads-data-table';
    var confirmBtn = document.getElementById('bulk-delete-leads-confirm');
    if (confirmBtn) confirmBtn.disabled = true;

    apiFetch('/ca-masters/bulk-delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ca_ids: selectedIds }),
    })
      .then(function (body) {
        var data = body.data || {};
        var deletedIds = (data.deleted_ids || []).map(function (id) { return parseInt(id, 10); });
        var deletedCount = data.deleted_count || deletedIds.length;
        if (deletedCount !== selectedIds.length) {
          toast('Deleted ' + deletedCount + ' of ' + selectedIds.length + ' selected leads', 'warning');
        } else {
          toast(deletedCount + ' selected lead(s) deleted', 'success');
        }
        closeModal(document.getElementById('modal-bulk-delete-leads'));
        window._pendingBulkDelete = null;
        clearInboxSelection(tableKey);
        invalidateDataCaches(['metrics', 'segment_counts', 'leads', 'ca_masters']);
        refreshCaMasterOrLeadsTable();
        if (typeof refreshAll === 'function') refreshAll();
      })
      .catch(function (err) {
        toast(err.message || 'Bulk delete failed', 'error');
      })
      .finally(function () {
        if (confirmBtn) confirmBtn.disabled = false;
      });
  }

  function handleInboxBulkAction(action, tableKey, module) {
    var rbacModule = module === 'ca-master' ? 'ca_master' : module === 'leads' ? 'leads' : module === 'followups' ? 'followups' : module;
    if (action === 'import' && !crmCanAction(rbacModule, 'import')) {
      toast('You do not have permission for this action.', 'warning');
      return;
    }
    if (action === 'export' && !crmCanAction(rbacModule, 'export')) {
      toast('You do not have permission for this action.', 'warning');
      return;
    }
    if (action === 'assign' && !crmCanAction('assignment', 'create')) {
      toast('You do not have permission for this action.', 'warning');
      return;
    }
    if (action === 'delete' && !crmCanAction(rbacModule, 'delete')) {
      toast('You do not have permission for this action.', 'warning');
      return;
    }

    // Import never depends on row selection.
    if (action === 'import') {
      openInboxImport();
      return;
    }

    var ids = module === 'assignment'
      ? getAssignmentBulkSelectedIds()
      : getInboxSelectedIds(tableKey);
    if (!ids.length) {
      toast('Select at least one record', 'warning');
      return;
    }

    if (action === 'assign') {
      if (module === 'assignment') {
        var assignmentCaIds = ids.map(function (assignmentId) {
          var row = (window.realAssignments || []).find(function (a) {
            return String(a.assignment_id) === String(assignmentId);
          });
          return row && row.ca_id ? parseInt(row.ca_id, 10) : 0;
        }).filter(function (id) { return id > 0; });
        openInboxAssign(assignmentCaIds);
        return;
      }
      openInboxAssign(ids);
      return;
    }
    if (action === 'export') {
      exportInboxSelection(tableKey, module);
      return;
    }
    if (action === 'delete') {
      if (module === 'assignment') {
        if (!window.confirm('Delete ' + ids.length + ' selected assignment(s)?')) return;
        Promise.all(ids.map(deleteAssignmentById))
          .then(function () {
            toast('Selected assignments removed', 'success');
            clearAssignmentBulkSelection();
            realAssignmentsLoaded = false;
            refreshAll();
          })
          .catch(function (err) {
            toast(err.message || 'Bulk delete failed', 'error');
          });
        return;
      }
      if (module === 'followups') {
        if (!window.confirm('Delete ' + ids.length + ' selected follow-up(s)?')) return;
        bulkDeleteFollowups(ids, tableKey);
        return;
      }
      openBulkDeleteLeadsModal(tableKey, ids);
      return;
    }
    if (action === 'email') {
      openInboxCampaign('email', ids);
      return;
    }
    if (action === 'sms') {
      openInboxCampaign('sms', ids);
      return;
    }
    if (action === 'whatsapp') {
      openInboxCampaign('whatsapp', ids);
      return;
    }
    if (action === 'followup') {
      openInboxFollowup(ids);
      return;
    }
    if (action === 'stage') {
      toast('Select a stage from the lead actions menu, or use Filters to review pipeline stages.', 'info');
      return;
    }
    if (action === 'tag') {
      toast('Open a lead and use Tags to add labels. Bulk tagging will use selected leads.', 'info');
      return;
    }
    if (action === 'note') {
      toast('Open a lead to add an internal note. Selected: ' + ids.length, 'info');
      return;
    }
    if (action === 'duplicate') {
      if (typeof navigateTo === 'function') navigateTo('duplicate-attempts');
      else toast('Open Duplicate Attempts to review matches for selected leads.', 'info');
      return;
    }
    if (action === 'more') {
      toast(ids.length + ' record(s) selected. Use row Actions for additional options.', 'info');
    }
  }

  function crmCanAction(module, permission) {
    if (window.CA_RBAC && typeof CA_RBAC.can === 'function') {
      return CA_RBAC.can(module, permission);
    }
    return true;
  }

  function getCrmRowActionItems(opts) {
    opts = opts || {};
    var items = [];
    if (crmCanAction('leads', 'edit') || crmCanAction('ca_master', 'edit')) {
      items.push({ action: 'edit', label: 'Edit Firm', icon: 'pencil' });
    }
    if (crmCanAction('followups', 'schedule_followup') || crmCanAction('followups', 'create')) {
      items.push({ action: 'followup', label: 'Add Follow-up', icon: 'calendar' });
    }
    if (!opts.master && opts.lead && canUseLeadQuickActions(opts.lead)) {
      items.push({ action: 'google-lookup', label: 'Google Lookup', icon: 'map-pin' });
    }
    var commItems = [];
    if (crmCanAction('campaigns', 'send_sms') || crmCanAction('campaigns', 'campaigns')) {
      commItems.push({ action: 'sms', label: 'Send SMS', icon: 'smartphone' });
    }
    if (crmCanAction('campaigns', 'send_email') || crmCanAction('campaigns', 'campaigns')) {
      commItems.push({ action: 'email', label: 'Send Email', icon: 'mail' });
      commItems.push({ action: 'whatsapp', label: 'Send WhatsApp', icon: 'message-circle' });
    }
    if (commItems.length) {
      if (items.length) items.push({ type: 'divider' });
      items.push({ type: 'communication', items: commItems });
    }
    return items;
  }

  function closeAllCrmActionMenus() {
    if (window.CAActionDropdown) CAActionDropdown.closeAll();
  }

  function findCrmActionsMenu(trigger) {
    return window.CAActionDropdown ? CAActionDropdown.findMenu(trigger) : null;
  }

  function positionCrmActionsDropdown(trigger, menu) {
    if (window.CAActionDropdown) CAActionDropdown.position(trigger, menu);
  }

  function refreshCaMasterOrLeadsTable() {
    invalidateDataCaches(['metrics', 'segment_counts', 'leads']);
    if (window.CA_LISTING_SEARCH) {
      reloadListing('ca_masters');
      return;
    }
    if (document.getElementById('ca-master-data-table')) renderCaMasterTable();
    else if (document.getElementById('leads-data-table')) renderLeadsTable();
  }

  function resetAssignLeadModalUi() {
    var leadWrap = document.getElementById('assign-lead-select-wrap');
    var leadSelect = document.getElementById('form-assign-lead-select');
    var summary = document.getElementById('assign-bulk-summary');
    var titleText = document.querySelector('#assign-lead-title [data-assign-title-text]');
    if (leadWrap) leadWrap.classList.remove('hidden');
    if (leadSelect) leadSelect.setAttribute('required', 'required');
    if (summary) {
      summary.classList.add('hidden');
      summary.textContent = '';
    }
    if (titleText) titleText.textContent = 'Assign Lead';
  }

  function openLeadAssignModal(leadId) {
    window._inboxBulkLeadIds = leadId ? [parseInt(leadId, 10)] : null;
    resetAssignLeadModalUi();
    CAData.setSelectedLeadId(leadId);
    ensureFormSelectData(function () {
      populateSelects();
      setSelectValueIfValid(document.getElementById('form-assign-lead-select'), leadId);
      window._editingAssignmentId = null;
      var modal = document.getElementById('modal-assign-lead');
      if (modal) openModal(modal);
      icons();
    });
  }

  function renderCrmRowActionsCell(lead, opts) {
    opts = opts || {};
    if (!window.CAActionDropdown) {
      return '<td class="crm-actions-cell sticky-right col-actions"><span class="cam-cell-empty">—</span></td>';
    }
    var items = getCrmRowActionItems(Object.assign({}, opts, { lead: lead }));
    return CAActionDropdown.renderCell(items, {
      scope: 'crm-lead',
      rowId: lead.ca_id,
      icon: 'more-vertical',
      ariaLabel: 'Lead actions',
    });
  }

  function openLeadFollowupModal(leadId) {
    var id = normalizeFollowupLeadId(leadId);
    if (!id) {
      toast('Lead not found', 'warning');
      return;
    }
    window._editingFollowUpId = null;
    window._followupOriginalScheduled = '';
    openFollowupModalWithLeads([id], { mode: 'row' });
  }

  var leadCallLogStatusOptions = null;

  function ensureLeadCallLogStatusOptions() {
    if (leadCallLogStatusOptions) return Promise.resolve(leadCallLogStatusOptions);
    return apiFetch('/workflow/options')
      .then(function (body) {
        var fromApi = (body.data && body.data.call_statuses) || [];
        var extras = ['No Answer', 'Call Later', 'Interested', 'Follow-up Required', 'Not Interested', 'Demo Scheduled'];
        var merged = fromApi.slice();
        extras.forEach(function (status) {
          if (merged.indexOf(status) === -1) merged.push(status);
        });
        leadCallLogStatusOptions = merged;
        return leadCallLogStatusOptions;
      })
      .catch(function () {
        leadCallLogStatusOptions = [
          'Connected', 'Not Connected', 'Busy', 'Wrong Number', 'Call Back Later',
          'No Answer', 'Call Later', 'Interested', 'Follow-up Required', 'Not Interested', 'Demo Scheduled',
        ];
        return leadCallLogStatusOptions;
      });
  }

  function populateLeadCallLogStatusSelect(selectEl, statuses) {
    if (!selectEl) return;
    selectEl.innerHTML = '<option value="">Select status…</option>' +
      (statuses || []).map(function (status) {
        return '<option value="' + escapeHtml(status) + '">' + escapeHtml(status) + '</option>';
      }).join('');
  }

  function renderLeadCallLogContext(lead) {
    var wrap = document.getElementById('lead-call-log-context');
    var hidden = document.getElementById('lead-call-log-ca-id');
    if (!wrap || !hidden) return;
    if (!lead) {
      hidden.value = '';
      wrap.classList.add('hidden');
      return;
    }
    hidden.value = String(lead.ca_id);
    var firmEl = document.getElementById('lead-call-log-ctx-firm');
    var caEl = document.getElementById('lead-call-log-ctx-ca');
    var mobileEl = document.getElementById('lead-call-log-ctx-mobile');
    var cityEl = document.getElementById('lead-call-log-ctx-city');
    var employeeEl = document.getElementById('lead-call-log-ctx-employee');
    if (firmEl) firmEl.textContent = lead.firm_name || '—';
    if (caEl) caEl.textContent = lead.ca_name || '—';
    if (mobileEl) mobileEl.textContent = lead.mobile_no || '—';
    if (cityEl) cityEl.textContent = lead.city || '—';
    if (employeeEl) employeeEl.textContent = lead.executive || 'Unassigned';
    wrap.classList.remove('hidden');
  }

  function clearLeadCallLogErrors(form) {
    if (!form) return;
    form.querySelectorAll('.ca-field-error').forEach(function (el) {
      el.textContent = '';
      el.classList.add('hidden');
    });
    form.querySelectorAll('.input-field.is-invalid').forEach(function (el) {
      el.classList.remove('is-invalid');
    });
  }

  function setLeadCallLogError(field, message) {
    var form = document.getElementById('form-lead-call-log');
    if (!form) return;
    var errorEl = form.querySelector('[data-error-for="' + field + '"]');
    var input = form.querySelector('[name="' + field + '"]') || document.getElementById('lead-call-log-' + field);
    if (errorEl) {
      errorEl.textContent = message || '';
      errorEl.classList.toggle('hidden', !message);
    }
    if (input) input.classList.toggle('is-invalid', !!message);
  }

  function syncLeadCallLogFields() {
    var status = (document.getElementById('lead-call-log-status') || {}).value || '';
    var nextWrap = document.getElementById('lead-call-log-next-wrap');
    var demoWrap = document.getElementById('lead-call-log-demo-wrap');
    if (nextWrap) {
      var showNext = status === 'Follow-up Required' || status === 'Call Back Later' || status === 'Call Later' || status === 'Interested';
      nextWrap.classList.toggle('hidden', !showNext);
      if (!showNext) {
        var nextDate = document.getElementById('lead-call-log-next-date');
        var nextTime = document.getElementById('lead-call-log-next-time');
        if (nextDate) nextDate.value = '';
        if (nextTime) nextTime.value = '';
        setLeadCallLogError('next_followup_date', '');
      }
    }
    if (demoWrap) {
      demoWrap.classList.toggle('hidden', status !== 'Demo Scheduled');
      if (status !== 'Demo Scheduled') {
        ['lead-call-log-demo-date', 'lead-call-log-demo-time', 'lead-call-log-meeting-link'].forEach(function (id) {
          var el = document.getElementById(id);
          if (!el) return;
          if (id === 'lead-call-log-demo-time') el.value = '10:00';
          else el.value = '';
        });
        setLeadCallLogError('demo_date', '');
        setLeadCallLogError('demo_time', '');
      }
    }
  }

  function validateLeadCallLogForm(form) {
    clearLeadCallLogErrors(form);
    var payload = Object.fromEntries(new FormData(form).entries());
    var errors = [];
    var status = (payload.call_status || '').trim();
    var note = (payload.call_note || '').trim();
    var caId = (payload.ca_id || '').trim();

    if (!caId) errors.push({ field: 'call_status', message: 'Lead is required.' });
    if (!status) errors.push({ field: 'call_status', message: 'Please select a call status.' });
    if (!note) errors.push({ field: 'call_note', message: 'Call note is required.' });
    if (!(payload.called_at || '').trim()) errors.push({ field: 'called_at', message: 'Call date and time are required.' });

    if (status === 'Demo Scheduled') {
      if (!(payload.demo_date || '').trim()) errors.push({ field: 'demo_date', message: 'Demo date is required.' });
      if (!(payload.demo_time || '').trim()) errors.push({ field: 'demo_time', message: 'Demo time is required.' });
    }

    if (status === 'Follow-up Required' && !(payload.next_followup_date || '').trim()) {
      errors.push({ field: 'next_followup_date', message: 'Next action date is required.' });
    }

    errors.forEach(function (err) { setLeadCallLogError(err.field, err.message); });
    if (errors.length) {
      var first = form.querySelector('.input-field.is-invalid');
      if (first && typeof first.focus === 'function') first.focus();
      return null;
    }

    payload.call_status = status;
    payload.call_note = note;
    if (payload.demo_date && payload.demo_time) {
      payload.demo_at = payload.demo_date + ' ' + payload.demo_time;
    }
    return payload;
  }

  function submitLeadCallLog(payload) {
    if (payload.call_status === 'Demo Scheduled') {
      return apiFetch('/follow-ups/call-outcome', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          ca_id: parseInt(payload.ca_id, 10),
          outcome: 'Demo Scheduled',
          remarks: payload.call_note,
          demo_date: payload.demo_date,
          demo_time: payload.demo_time,
          demo_at: payload.demo_at,
          meeting_link: payload.meeting_link || '',
        }),
      });
    }

    return apiFetch('/workflow/calls', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        ca_id: parseInt(payload.ca_id, 10),
        call_status: payload.call_status,
        call_note: payload.call_note,
        called_at: payload.called_at,
        next_followup_date: payload.next_followup_date || null,
        next_followup_time: payload.next_followup_time || null,
      }),
    });
  }

  function openLeadCallLogModal(leadId) {
    if (!leadId) {
      toast('Lead not found', 'warning');
      return;
    }
    var lead = getLeadRecord(leadId);
    if (isEmployeeUser() && lead && !canUseLeadQuickActions(lead)) {
      toast('You can only log calls for leads assigned to you.', 'error');
      return;
    }
    var modal = document.getElementById('modal-lead-call-log');
    var form = document.getElementById('form-lead-call-log');
    if (!modal || !form) {
      toast('Call log form is unavailable.', 'warning');
      return;
    }
    form.reset();
    clearLeadCallLogErrors(form);
    renderLeadCallLogContext(null);
    syncLeadCallLogFields();

    var calledAtEl = document.getElementById('lead-call-log-called-at');
    if (calledAtEl) {
      var now = new Date();
      var y = now.getFullYear();
      var m = String(now.getMonth() + 1).padStart(2, '0');
      var d = String(now.getDate()).padStart(2, '0');
      var h = String(now.getHours()).padStart(2, '0');
      var min = String(now.getMinutes()).padStart(2, '0');
      calledAtEl.value = y + '-' + m + '-' + d + 'T' + h + ':' + min;
    }

    openModal(modal);
    setLeadCallLogFormBusy(true);

    Promise.all([
      ensureLeadCallLogStatusOptions(),
      fetchLeadForFollowup(leadId),
    ]).then(function (results) {
      if (isEmployeeUser() && results[1] && !canUseLeadQuickActions(results[1])) {
        throw new Error('You can only log calls for leads assigned to you.');
      }
      populateLeadCallLogStatusSelect(document.getElementById('lead-call-log-status'), results[0]);
      renderLeadCallLogContext(results[1]);
      setLeadCallLogFormBusy(false);
      if (window.CrmDateTimePicker) {
        window.CrmDateTimePicker.syncAll(form);
      }
      var statusEl = document.getElementById('lead-call-log-status');
      if (statusEl) statusEl.focus();
      iconsIn(modal);
    }).catch(function (err) {
      setLeadCallLogFormBusy(false);
      closeModal(modal);
      toast(err.message || 'Unable to load lead for call log', 'error');
    });
  }

  function setLeadCallLogFormBusy(busy) {
    var form = document.getElementById('form-lead-call-log');
    if (!form) return;
    form.querySelectorAll('input, select, textarea, button').forEach(function (el) {
      el.disabled = !!busy;
    });
    var submitBtn = document.querySelector('button[form="form-lead-call-log"]');
    if (submitBtn) submitBtn.disabled = !!busy;
  }

  function openLeadCommunicationCampaign(channel, leadId) {
    var lead = getLeadRecord(leadId);
    if (!lead) {
      toast('Lead not found', 'warning');
      return;
    }
    if (channel === 'sms' && !leadHasValidMobile(lead)) {
      toast(SMS_MOBILE_REQUIRED_MESSAGE, 'error');
      return;
    }
    if (channel === 'email' && !leadHasEmail(lead)) {
      toast('Email address is required. Please update the lead first.', 'error');
      return;
    }
    window._pendingCampaignLeadIds = [parseInt(leadId, 10)];
    window._pendingCampaignChannel = channel;
    var pageMap = { sms: 'sms', email: 'email', whatsapp: 'whatsapp' };
    if (typeof navigateTo === 'function') navigateTo(pageMap[channel] || channel);
  }

  function applyPendingCampaignLeadSelection(channel) {
    var ids = window._pendingCampaignLeadIds;
    var pendingChannel = window._pendingCampaignChannel;
    if (!ids || !ids.length || !pendingChannel || pendingChannel !== channel) return;
    var presetIds = ids.slice();
    window._pendingCampaignLeadIds = null;
    window._pendingCampaignChannel = null;
    var modeEl = document.getElementById('form-campaign-audience-mode');
    if (modeEl) modeEl.value = 'selected_leads';
    toggleCampaignAudienceFields();
    configureCampaignModal(channel);
    populateCampaignAudienceSelects(channel, { preserveSelection: true }).then(function () {
      if (window.CrmLeadPicker) {
        window.CrmLeadPicker.applyPresetIds('campaign', presetIds);
      } else {
        var pickerState = getCampaignLeadPickerState();
        presetIds.forEach(function (id) {
          var lead = findLeadById(id);
          if (lead) pickerState.selected[String(id)] = lead;
        });
        renderCampaignLeadPicker();
      }
      var modal = document.getElementById('modal-add-campaign');
      if (modal) openExclusiveCrmModal(modal);
      icons();
    });
  }

  function handleCrmRowAction(action, leadId) {
    if (!leadId) {
      console.error('[CRM] Row action missing lead id:', action);
      toast('Lead not found', 'warning');
      return;
    }
    if (action === 'view') {
      selectLead(leadId, true);
      return;
    }
    if (action === 'edit') {
      openLeadFormForEdit(leadId).then(function (ok) {
        if (ok) openExclusiveCrmModal(document.getElementById('modal-add-lead'));
      }).catch(function (err) {
        console.error('[CRM] Edit firm failed:', leadId, err);
        toast(err.message || 'Unable to open edit form', 'error');
      });
      return;
    }
    if (action === 'assign') {
      openLeadAssignModal(leadId);
      return;
    }
    if (action === 'followup') {
      openLeadFollowupModal(leadId);
      return;
    }
    if (action === 'call-log') {
      openLeadCallLogModal(leadId);
      return;
    }
    if (action === 'google-lookup' || action === 'google_lookup') {
      openLeadResearch(leadId);
      return;
    }
    if (action === 'sms' || action === 'email' || action === 'whatsapp') {
      openLeadCommunicationCampaign(action, leadId);
      return;
    }
    if (action === 'delete') {
      if (!window.confirm('Delete this firm record? This cannot be undone.')) return;
      apiFetch('/ca-masters/' + encodeURIComponent(leadId), { method: 'DELETE' })
        .then(function () {
          toast('Firm deleted', 'success');
          refreshCaMasterOrLeadsTable();
        })
        .catch(function (err) {
          console.error('[CRM] Delete firm failed:', leadId, err);
          toast(err.message || 'Unable to delete firm', 'error');
        });
    }
  }

  function bindCrmRowActions(root) {
    ensureLeadQuickActionsDelegated();
    if (window.CAActionDropdown) CAActionDropdown.bindScrollDismiss(root || document);
  }

  function bindCrmActionsScrollDismiss(root) {
    if (window.CAActionDropdown) CAActionDropdown.bindScrollDismiss(root || document);
  }

  function ensureLeadQuickActionsDelegated() {
    if (document._leadQuickActionsDelegated) return;
    document._leadQuickActionsDelegated = true;

    document.addEventListener('click', function (e) {
      var quickBtn = e.target.closest('[data-lead-quick]');
      if (quickBtn) {
        e.preventDefault();
        e.stopPropagation();
        closeAllCrmActionMenus();
        handleLeadQuickAction(quickBtn.getAttribute('data-lead-quick'), quickBtn.getAttribute('data-lead-id'));
        return;
      }
      var drawerGoogleBtn = e.target.closest('[data-lead-drawer-google]');
      if (drawerGoogleBtn) {
        e.preventDefault();
        openLeadResearch(drawerGoogleBtn.getAttribute('data-lead-drawer-google'));
      }
    }, true);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeAllCrmActionMenus();
        closeTopLeadQuickPanel();
      }
    });
  }

  function bindCrmActionsDismiss() {
    ensureLeadQuickActionsDelegated();
    ensureLeadQuickPanelsDelegated();
  }

  function registerActionDropdownHandlers() {
    if (!window.CAActionDropdown || window._actionDropdownHandlersRegistered) return;
    window._actionDropdownHandlersRegistered = true;

    CAActionDropdown.register('crm-lead', function (action, dataset) {
      handleCrmRowAction(action, dataset.menuId);
    });

    CAActionDropdown.register('assignment', function (action, dataset) {
      handleAssignmentMenuAction(action, dataset.menuId);
    });

    CAActionDropdown.register('followup', function (action, dataset) {
      var id = dataset.menuId;
      if (!id) {
        toast('Follow-up record is missing. Refresh the page and try again.', 'warning');
        return;
      }
      if (action === 'view') {
        openFollowupDetails(id);
        return;
      }
      if (action === 'edit') {
        openFollowupFormForEdit(id);
        return;
      }
      if (action === 'outcome') {
        openCallOutcomeModal(id, dataset.caId);
        return;
      }
      if (action === 'complete') {
        markFollowupCompleted(id);
        return;
      }
      if (action === 'demo-result') {
        openDemoResultForFollowup(id, dataset.caId);
        return;
      }
      if (action === 'delete') {
        if (!crmCanAction('followups', 'delete')) return;
        if (!window.confirm('Delete this follow-up?')) return;
        var deleteRow = document.querySelector('tr[data-followup-id="' + id + '"]');
        if (deleteRow) deleteRow.classList.add('crm-row-busy');
        apiFetch('/follow-ups/' + encodeURIComponent(id), { method: 'DELETE' })
          .then(function () {
            removeFollowupFromCache(id);
            removeFollowupTableRow(id);
            toast('Follow-up deleted', 'success');
            refreshFollowupsPage({ reload: false, calendar: true });
          })
          .catch(function (err) {
            if (deleteRow) deleteRow.classList.remove('crm-row-busy');
            if (err.status === 403) return;
            toast(err.message || 'Unable to delete follow-up', 'error');
          });
      }
    });

    CAActionDropdown.register('employee', function (action, dataset) {
      var id = dataset.menuId;
      if (action === 'edit') {
        openEmployeeFormForEdit(id);
        return;
      }
      if (action === 'delete') {
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
      }
    });

    CAActionDropdown.register('master', function (action, dataset) {
      var entity = dataset.masterEntity;
      var id = dataset.masterId;
      if (action === 'edit') {
        var record = findMasterRecord(entity, id);
        if (!record) {
          toast('Record not found — refresh and try again', 'warning');
          return;
        }
        openMasterRecordModal(entity, record);
        return;
      }
      if (action === 'delete') {
        beginMasterDeleteFlow(entity, id);
      }
    });

    CAActionDropdown.register('sms-template', function (action, dataset) {
      var id = dataset.menuId;
      if (action === 'edit') {
        openSmsTemplateForm(parseInt(id, 10));
        return;
      }
      if (action === 'delete') {
        if (!window.confirm('Delete this DLT template?')) return;
        apiFetch('/sms-templates/' + id, { method: 'DELETE' })
          .then(function () {
            toast('SMS template deleted', 'success');
            refreshSmsTemplatesPanel();
          })
          .catch(function (err) {
            toast(err.message || 'Unable to delete template', 'error');
          });
      }
    });

    CAActionDropdown.register('email-account', function (action, dataset) {
      var id = dataset.menuId;
      if (action === 'edit') {
        loadEmailAccountIntoForm(id);
        return;
      }
      if (action === 'default') {
        apiFetch('/email-accounts/' + encodeURIComponent(id) + '/set-default', { method: 'POST' })
          .then(function () {
            toast('Default email account updated', 'success');
            reloadEmailAccountsList();
          })
          .catch(function (err) { toast(err.message || 'Unable to set default account', 'error'); });
        return;
      }
      if (action === 'delete') {
        if (!window.confirm('Delete this email account?')) return;
        apiFetch('/email-accounts/' + encodeURIComponent(id), { method: 'DELETE' })
          .then(function () {
            toast('Email account deleted', 'success');
            if ((document.getElementById('email-account-id') || {}).value === String(id)) {
              clearEmailAccountForm();
            }
            reloadEmailAccountsList();
          })
          .catch(function (err) { toast(err.message || 'Unable to delete account', 'error'); });
      }
    });

    CAActionDropdown.register('dnd', function (action, dataset) {
      var id = dataset.menuId;
      if (action !== 'remove') return;
      if (!id || !confirm('Remove this DND entry?')) return;
      apiFetch('/dnd-management/' + id, { method: 'DELETE' })
        .then(function () {
          toast('DND entry removed', 'success');
          refreshConsentDndPage();
          if (document.getElementById('recent-activity-list')) renderRecentActivity();
        })
        .catch(function (err) {
          toast(err.message || 'Unable to remove DND entry', 'error');
        });
    });
  }

  function researchWorkspaceDeps() {
    return {
      apiFetch: apiFetch,
      toast: toast,
      escapeHtml: escapeHtml,
      icons: icons,
      onSaved: function (lead) {
        mergeLeadIntoPools(lead);
        if (document.getElementById('leads-data-table')) renderLeadsTable();
        if (document.querySelector('[id^="ca-master"][id$="-data-table"]')) renderCaMasterTable();
        var editingId = window._editingLeadId ? String(window._editingLeadId) : '';
        if (editingId && lead && String(lead.ca_id) === editingId) {
          renderLeadGoogleFieldsSection(lead);
        }
      },
    };
  }

  function closeTopLeadQuickPanel() {
    if (window.CA_RESEARCH_WORKSPACE && CA_RESEARCH_WORKSPACE.isOpen()) {
      CA_RESEARCH_WORKSPACE.close();
      return true;
    }
    return false;
  }

  function closeAllLeadResearchPanels() {
    if (window.CA_RESEARCH_WORKSPACE) CA_RESEARCH_WORKSPACE.close();
  }

  function formatGoogleBusinessStatus(status) {
    var value = String(status || '').toUpperCase();
    if (value === 'OPERATIONAL') return 'Open';
    if (value === 'CLOSED_TEMPORARILY') return 'Temporarily closed';
    if (value === 'CLOSED_PERMANENTLY') return 'Permanently closed';
    return status || '—';
  }

  function leadHasSavedGoogleData(lead) {
    if (!lead) return false;
    return !!(lead.verified_from_google || lead.google_place_id);
  }

  function leadToResearchPlace(lead) {
    if (!leadHasSavedGoogleData(lead)) return null;
    return {
      place_id: lead.google_place_id,
      google_place_id: lead.google_place_id,
      business_name: lead.firm_name && lead.firm_name !== '—' ? lead.firm_name : null,
      verified_address: lead.verified_address || (lead.address && lead.address !== '—' ? lead.address : null),
      address: lead.address && lead.address !== '—' ? lead.address : lead.verified_address,
      mobile_no: lead.mobile_no && lead.mobile_no !== '—' ? lead.mobile_no : null,
      website: lead.website && lead.website !== '—' ? lead.website : null,
      google_rating: lead.google_rating,
      google_review_count: lead.google_review_count,
      google_business_status: lead.google_business_status,
      google_maps_url: lead.google_maps_url,
      latitude: lead.latitude,
      longitude: lead.longitude,
      open_status: formatGoogleBusinessStatus(lead.google_business_status),
    };
  }

  function mapResearchPayload(data, lead) {
    data = data || {};
    return {
      place: data.place || null,
      results: data.results || [],
      api_status: data.api_status || data.status || null,
      api_error: data.api_error || null,
      api_recommendation: data.api_recommendation || null,
      api_google_reason: data.api_google_reason || null,
      multiple_results: !!data.multiple_results,
      cached: !!data.cached,
      can_refresh: !!data.can_refresh,
      can_save: data.can_save !== false,
      current: data.current || lead,
      google_maps_embed_url: data.google_maps_embed_url || null,
      google_maps_url: data.google_maps_url || null,
      query: data.query || null,
    };
  }

  function mapGoogleSearchPayload(data, lead) {
    data = data || {};
    return {
      place: data.place || null,
      results: data.results || [],
      api_status: data.status || null,
      api_error: data.api_error || null,
      api_recommendation: data.api_recommendation || null,
      api_google_reason: data.api_google_reason || null,
      multiple_results: !!data.multiple_results,
      cached: false,
      can_refresh: false,
      can_save: true,
      current: lead,
      google_maps_embed_url: data.google_maps_embed_url || null,
      google_maps_url: data.google_maps_url || null,
      query: data.query || null,
    };
  }

  function isGooglePlacesServerRestrictionError(data) {
    data = data || {};
    var status = String(data.api_status || data.status || '').toUpperCase();
    var reason = String(data.api_google_reason || '').toUpperCase();
    if (status === 'PERMISSION_DENIED' || status === 'API_KEY_INVALID' || status === 'REFERER_NOT_ALLOWED') {
      return true;
    }
    return reason.indexOf('ANDROID') >= 0
      || reason.indexOf('IOS') >= 0
      || reason.indexOf('REFERRER') >= 0
      || reason.indexOf('IP_ADDRESS') >= 0;
  }

  function mapClientPlacesPayload(results, query, lead) {
    results = results || [];
    var place = results[0] || null;
    var encoded = encodeURIComponent(query || '');
    return {
      place: place,
      results: results,
      api_status: results.length ? 'OK' : 'ZERO_RESULTS',
      api_error: results.length ? null : 'No Google Places results matched this CA firm.',
      api_recommendation: null,
      api_google_reason: null,
      multiple_results: results.length > 1,
      cached: false,
      can_refresh: false,
      can_save: true,
      current: lead,
      google_maps_embed_url: place && place.latitude != null && place.longitude != null
        ? 'https://www.google.com/maps?q=' + place.latitude + ',' + place.longitude + '&output=embed'
        : 'https://www.google.com/maps?q=' + encoded + '&output=embed',
      google_maps_url: place && place.google_maps_url
        ? place.google_maps_url
        : 'https://www.google.com/maps/search/?api=1&query=' + encoded,
      query: query,
      sourceNote: 'Loaded via browser Places API (server key blocked)',
    };
  }

  function mergeLeadIntoPools(lead) {
    if (!lead || lead.ca_id == null) return;
    var mapped = mapLeadRecord(lead);
    var id = String(lead.ca_id);
    [window.realLeads, window._listingLeadsPage, window.kanbanLeads, window._selectLeads].forEach(function (pool) {
      if (!pool || !pool.length) return;
      var idx = pool.findIndex(function (item) { return String(item.ca_id) === id; });
      if (idx >= 0) pool[idx] = Object.assign({}, pool[idx], mapped);
    });
  }

  function ensureLeadWithGoogleFields(leadId, lead) {
    return apiFetch('/ca-masters/' + encodeURIComponent(leadId))
      .then(function (body) {
        var fresh = body.data;
        if (!fresh) return lead;
        mergeLeadIntoPools(fresh);
        return Object.assign({}, lead || {}, mapLeadRecord(fresh));
      })
      .catch(function () {
        return lead;
      });
  }

  function friendlyResearchError(err) {
    var msg = (err && err.message) ? String(err.message) : '';
    if (!msg || /SQLSTATE|sqlite|performed_by|database|QueryException|HY000/i.test(msg)) {
      return 'Google Places Lookup is temporarily unavailable. Please try again.';
    }
    return msg;
  }

  function runLeadResearchLookup(leadId, lead, googleQuery) {
    return apiFetch('/ca-masters/' + encodeURIComponent(leadId) + '/research', { method: 'POST' })
      .then(function (body) {
        var data = body.data || {};
        var payload = mapResearchPayload(data, lead);

        if (!payload.cached && payload.api_error && isGooglePlacesServerRestrictionError(data) && window.CrmGoogleMaps && window.CrmGoogleMaps.hasKey()) {
          return window.CrmGoogleMaps.searchPlacesText(googleQuery)
            .then(function (clientResults) {
              return mapClientPlacesPayload(clientResults, googleQuery, lead);
            })
            .catch(function () {
              return payload;
            });
        }

        return payload;
      });
  }

  function openLeadResearch(leadId) {
    if (!window.CA_RESEARCH_WORKSPACE) {
      toast('Research workspace is unavailable', 'error');
      return;
    }
    if (_leadResearchOpening[leadId]) return;
    var lead = getLeadRecord(leadId);
    if (!lead) {
      toast('Lead not found', 'warning');
      return;
    }
    if (!canUseLeadQuickActions(lead)) {
      toast('You do not have access to this lead', 'warning');
      return;
    }
    if (!hasSearchableLeadForGoogleLookup(lead)) {
      toast(GOOGLE_LOOKUP_INSUFFICIENT_MSG, 'warning');
      return;
    }
    var googleQuery = buildLeadGoogleSearchQuery(lead);
    var mapsQuery = buildLeadGoogleMapsQuery(lead);
    _leadResearchOpening[leadId] = true;

    ensureLeadWithGoogleFields(leadId, lead).then(function (resolvedLead) {
      lead = resolvedLead || lead;
      var savedPlace = leadToResearchPlace(lead);
      var savedMapsUrl = lead.google_maps_url
        || (lead.latitude != null && lead.longitude != null
          ? 'https://www.google.com/maps?q=' + lead.latitude + ',' + lead.longitude
          : null);

      CA_RESEARCH_WORKSPACE.open({
        leadId: leadId,
        lead: lead,
        googleQuery: googleQuery,
        mapsQuery: mapsQuery,
        place: savedPlace,
        results: savedPlace ? [savedPlace] : [],
        current: lead,
        cached: !!savedPlace,
        mapsExternal: savedMapsUrl,
        mapsEmbed: savedMapsUrl
          ? (String(savedMapsUrl).indexOf('output=embed') >= 0
            ? savedMapsUrl
            : savedMapsUrl + (String(savedMapsUrl).indexOf('?') >= 0 ? '&' : '?') + 'output=embed')
          : null,
        sourceNote: savedPlace
          ? 'Loading saved Google data…'
          : 'Loading Google Places results…',
        deps: researchWorkspaceDeps(),
      });

      runLeadResearchLookup(leadId, lead, googleQuery)
        .then(function (payload) {
          CA_RESEARCH_WORKSPACE.setResearchData(payload);
        })
        .catch(function (err) {
          CA_RESEARCH_WORKSPACE.setSourceError(
            friendlyResearchError(err),
          );
        })
        .finally(function () {
          delete _leadResearchOpening[leadId];
        });
    }).catch(function () {
      delete _leadResearchOpening[leadId];
    });
  }

  function ensureLeadQuickPanelsDelegated() {
    // Research workspace handles its own events; kept for bindCrmActionsDismiss compatibility.
  }

  function handleLeadQuickAction(action, leadId) {
    if (action === 'call-log' || action === 'call_log') {
      openLeadCallLogModal(leadId);
      return;
    }
    if (action === 'research' || action === 'google' || action === 'maps') {
      openLeadResearch(leadId);
    }
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
      team_size: l.team_size != null && l.team_size !== '' ? l.team_size : null,
      existing_software: l.existing_software || '—',
      website: l.website || '—',
      rating: l.rating || 1,
      is_newly_established: !!l.is_newly_established,
      status: l.status || l.demo_status || 'New',
      source: l.source || l.source_name || '—',
      source_id: l.source_id ? String(l.source_id) : null,
      stage: l.stage || mapStatusToStage(l.status),
      master_pipeline_stage: l.master_pipeline_stage || mapStatusToMasterPipelineStage(l.status),
      executive_id: l.executive_id ? String(l.executive_id) : null,
      executive: l.executive || l.executive_name || l.employee_name || (l.executive_id ? 'Assigned' : 'Unassigned'),
      team_members: l.team_members || {
        count: l.team_members_count != null ? l.team_members_count : (l.team_member_names && l.team_member_names.length ? l.team_member_names.length : 0),
        names: l.team_member_names || [],
        lead_owner_id: l.lead_owner_id != null ? l.lead_owner_id : (l.executive_id || null),
      },
      team_members_count: l.team_members_count != null
        ? l.team_members_count
        : ((l.team_members && l.team_members.count) || (l.team_member_names && l.team_member_names.length) || 0),
      team_member_names: l.team_member_names || (l.team_members && l.team_members.names) || [],
      lead_owner_id: l.lead_owner_id != null ? l.lead_owner_id : (l.team_members && l.team_members.lead_owner_id),
      last_activity: l.last_activity || null,
      last_activity_at: l.last_activity_at || (l.last_activity && l.last_activity.occurred_at) || null,
      priority: l.priority || null,
      lead_priority: l.priority || null,
      rating_stars: l.rating || 1,
      created_by: l.created_by_name || l.created_by || '—',
      is_verified: !!l.is_verified,
      is_wrong_number: !!l.is_wrong_number,
      last_action: l.last_action || '—',
      google_place_id: l.google_place_id || null,
      verified_address: l.verified_address || null,
      address: l.address || null,
      google_rating: l.google_rating != null ? Number(l.google_rating) : null,
      google_review_count: l.google_review_count != null ? Number(l.google_review_count) : null,
      google_business_status: l.google_business_status || null,
      google_maps_url: l.google_maps_url || null,
      latitude: l.latitude != null ? Number(l.latitude) : null,
      longitude: l.longitude != null ? Number(l.longitude) : null,
      verified_from_google: !!l.verified_from_google,
      researched_at: l.researched_at || null,
      research_status: l.research_status || null,
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
      role: e.role || 'Employee',
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
    var options = [{ value: 'employee', label: 'Employee' }];
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
      if (title) title.innerHTML = title.innerHTML.replace('Add Employee', 'Edit Employee');
    } else {
      if (loginFields) loginFields.classList.remove('hidden');
      passwordInputs.forEach(function (input) { input.required = true; });
      if (statusNote) statusNote.classList.add('hidden');
      configureEmployeeCrmRoleSelect();
      if (title && title.textContent.indexOf('Edit') >= 0) {
        title.innerHTML = title.innerHTML.replace('Edit Employee', 'Add Employee');
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

  function fillResetPasswordEmployeeSelect() {
    initResetPasswordEmployeeLookup();
  }

  function initResetPasswordEmployeeLookup() {
    var select = document.getElementById('reset-password-employee-select');
    var emailField = document.getElementById('reset-password-employee-email');
    if (!select) return;
    enhanceEntityLookups(select.parentElement || document);
    if (select._resetPwdBound) return;
    select._resetPwdBound = true;
    select.addEventListener('change', function () {
      var api = window.CrmEntityLookup ? window.CrmEntityLookup.get(select) : null;
      var record = api ? api.getSelectedRecord() : null;
      if (emailField) emailField.value = record && record.email_id && record.email_id !== '—' ? record.email_id : '';
    });
    if (emailField) emailField.value = '';
  }

  function populateResetPasswordEmployeeSelect() {
    initResetPasswordEmployeeLookup();
  }

  function getLeadRecord(leadId) {
    var id = String(leadId);
    var pools = [window._listingLeadsPage, window.kanbanLeads, window.realLeads, window._selectLeads];
    for (var i = 0; i < pools.length; i++) {
      if (!pools[i] || !pools[i].length) continue;
      var found = pools[i].find(function (l) { return String(l.ca_id) === id; });
      if (found) return found;
    }
    return USE_DEMO_FALLBACKS ? CAData.getLeadById(leadId) : null;
  }

  function getLeadsForSelects() {
    if (window._selectLeadsLoaded && window._selectLeads && window._selectLeads.length) {
      return window._selectLeads;
    }
    if (realLeadsLoaded && window.realLeads && window.realLeads.length) {
      return window.realLeads;
    }
    if (window._selectLeadsLoaded) return window._selectLeads || [];
    if (realLeadsLoaded) return window.realLeads || [];
    return [];
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
        if (!realLeadsLoaded || !window.realLeads || !window.realLeads.length) {
          window.realLeads = window._selectLeads.slice();
          realLeadsLoaded = true;
        }
        if (callback) callback();
      })
      .catch(function () {
        window._selectLeads = [];
        window._selectLeadsLoaded = true;
        if (callback) callback();
      });
  }

  function loadEmployeesForSelects(callback) {
    if (window._selectEmployeesLoaded && window._selectEmployees && window._selectEmployees.length) {
      if (callback) callback();
      return;
    }
    apiFetch('/lookups/executives')
      .then(function (body) {
        var items = unwrapList(body);
        if (items.length) {
          window._selectEmployees = items.map(mapEmployeeRecord);
          window._selectEmployeesLoaded = true;
          return null;
        }
        return apiFetch('/employees' + listingAllQuery('employees', { status: 'Active', sort_by: 'name', sort_dir: 'asc' }));
      })
      .then(function (body) {
        if (body === null) {
          if (callback) callback();
          return;
        }
        if (body) {
          window._selectEmployees = unwrapList(body).map(mapEmployeeRecord);
          window._selectEmployeesLoaded = true;
        } else {
          window._selectEmployees = getExecutivesForSelects();
          if (window._selectEmployees.length) {
            window._selectEmployeesLoaded = true;
          }
        }
        if (callback) callback();
      })
      .catch(function () {
        window._selectEmployees = getExecutivesForSelects();
        if (window._selectEmployees.length) {
          window._selectEmployeesLoaded = true;
        }
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
      window._camSegmentCountsLoaded = false;
      clearDashboardMetricsCache();
    }
    if (keys.indexOf('metrics') >= 0 || keys.indexOf('segment_counts') >= 0) {
      leadSegmentCountsLoaded = false;
      leadSegmentCounts = null;
      leadSegmentCountsPromise = null;
    }
    if (keys.indexOf('employee_dashboard') >= 0 || keys.indexOf('metrics') >= 0) {
      employeeDashboardLoaded = false;
      employeeDashboardPromise = null;
      employeeDashboardData = null;
    }
    if (keys.indexOf('leads') >= 0) {
      realLeadsLoaded = false;
      kanbanLeadsLoaded = false;
      window.kanbanLeads = [];
      window._listingLeadsPage = [];
      window._selectLeadsLoaded = false;
    }
    if (keys.indexOf('employees') >= 0) {
      realEmployeesLoaded = false;
      window._selectEmployeesLoaded = false;
    }
    if (keys.indexOf('assignments') >= 0) realAssignmentsLoaded = false;
    if (keys.indexOf('followups') >= 0) realFollowUpsLoaded = false;
    if (keys.indexOf('masters') >= 0) masterDataLoaded = false;
    if (keys.indexOf('ca_masters') >= 0 || keys.indexOf('leads') >= 0) {
      clearListingPageCaches(['ca_masters']);
    }
    if (keys.indexOf('followups') >= 0) clearListingPageCaches(['follow_ups']);
    if (keys.indexOf('assignments') >= 0) clearListingPageCaches(['lead_assignments', 'employees']);
    if (keys.indexOf('sales_list') >= 0) clearListingPageCaches(['sales_list']);
  }

  function listingCacheFingerprint(key, extra) {
    var state = window.CA_LISTING_SEARCH ? CA_LISTING_SEARCH.getState(key) : {};
    var raw = JSON.stringify({ key: key, state: state, extra: extra || {} });
    var hash = 0;
    for (var i = 0; i < raw.length; i++) {
      hash = ((hash << 5) - hash) + raw.charCodeAt(i);
      hash |= 0;
    }
    return 'crm_listing_v1_' + key + '_' + hash;
  }

  function readListingPageCache(key, extra) {
    if (LISTING_PAGE_CACHE_KEYS.indexOf(key) < 0) return null;
    try {
      var raw = sessionStorage.getItem(listingCacheFingerprint(key, extra));
      if (!raw) return null;
      var parsed = JSON.parse(raw);
      if (!parsed || !parsed.body || !parsed.savedAt) return null;
      if (Date.now() - parsed.savedAt > LISTING_PAGE_CACHE_TTL_MS) return null;
      return parsed;
    } catch (e) {
      return null;
    }
  }

  function writeListingPageCache(key, extra, body) {
    if (LISTING_PAGE_CACHE_KEYS.indexOf(key) < 0 || !body) return;
    try {
      sessionStorage.setItem(listingCacheFingerprint(key, extra), JSON.stringify({
        savedAt: Date.now(),
        body: body,
      }));
    } catch (e) { /* quota */ }
  }

  function clearListingPageCaches(keys) {
    try {
      var prefixes = (keys || LISTING_PAGE_CACHE_KEYS).map(function (key) {
        return 'crm_listing_v1_' + key + '_';
      });
      var toRemove = [];
      for (var i = 0; i < sessionStorage.length; i++) {
        var storageKey = sessionStorage.key(i);
        if (!storageKey) continue;
        prefixes.forEach(function (prefix) {
          if (storageKey.indexOf(prefix) === 0) toRemove.push(storageKey);
        });
      }
      toRemove.forEach(function (storageKey) {
        sessionStorage.removeItem(storageKey);
      });
    } catch (e) { /* ignore */ }
  }

  function loadLeadsFromDatabase(callback) {
    var gen = ++_leadsLoadGeneration;
    return apiFetch('/ca-masters' + listingAllQuery('ca_masters'))
      .then(function (body) {
        if (gen !== _leadsLoadGeneration) return;
        window.realLeads = unwrapList(body).map(mapLeadRecord);
        window.kanbanLeads = window.realLeads.slice();
        kanbanLeadsLoaded = true;
        realLeadsLoaded = true;
        window._selectLeads = window.realLeads.slice();
        window._selectLeadsLoaded = true;
        if (realAssignmentsLoaded) enrichLeadsWithAssignments();
        if (callback) callback();
      })
      .catch(function () {
        if (gen !== _leadsLoadGeneration) return;
        window.realLeads = [];
        window.kanbanLeads = [];
        kanbanLeadsLoaded = true;
        realLeadsLoaded = true;
        if (callback) callback();
      });
  }

  function loadKanbanLeads(callback) {
    if (!document.getElementById('kanban-board')) {
      if (callback) callback();
      return Promise.resolve();
    }
    if (!isAnyPipelineTabActive()) {
      if (callback) callback();
      return Promise.resolve();
    }
    var gen = ++_kanbanLoadGeneration;
    setLeadsHubLoadingState(true);
    var extra = { per_stage: 80 };
    if (document.getElementById('cam-hub')) {
      extra.pipeline = 'master';
    }
    var segment = getLeadFilter();
    if (segment && segment !== 'all') extra.segment = segment;
    if (window.CA_LISTING_SEARCH && (document.getElementById('leads-data-table') || document.getElementById('ca-master-data-table') || document.getElementById('cam-hub'))) {
      if (document.getElementById('leads-data-table')) {
        Object.assign(extra, CA_LISTING_SEARCH.readLeadDrawerFilters());
      } else {
        Object.assign(extra, readCaMasterColumnFilters());
      }
    }
    var qs = new URLSearchParams(extra).toString();
    return apiFetch('/ca-masters/kanban?' + qs)
      .then(function (body) {
        if (gen !== _kanbanLoadGeneration) return;
        var data = body.data || body;
        window.kanbanLeads = (data.items || []).map(mapLeadRecord);
        window.kanbanStageCounts = data.stage_counts || {};
        kanbanLeadsLoaded = true;
        setLeadsHubLoadingState(false);
        if (document.getElementById('kanban-board')) renderKanbanFromData();
        if (callback) callback();
      })
      .catch(function (err) {
        if (gen !== _kanbanLoadGeneration) return;
        window.kanbanLeads = window._listingLeadsPage ? window._listingLeadsPage.slice() : [];
        kanbanLeadsLoaded = true;
        setLeadsHubLoadingState(false);
        if (callback) callback(err);
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
    var gen = ++_assignmentsLoadGeneration;
    return apiFetch('/lead-assignments' + listingAllQuery('lead_assignments'))
      .then(function (body) {
        if (gen !== _assignmentsLoadGeneration) return;
        window.realAssignments = unwrapList(body);
        realAssignmentsLoaded = true;
        if (realLeadsLoaded) enrichLeadsWithAssignments();
        if (callback) callback();
      })
      .catch(function () {
        if (gen !== _assignmentsLoadGeneration) return;
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
    function settledList(promise) {
      return promise.then(function (value) {
        return { ok: true, value: value };
      }).catch(function (error) {
        return { ok: false, error: error };
      });
    }

    var sourcePromise = apiFetch('/lookups/sources')
      .then(function (body) {
        return unwrapList(body);
      })
      .catch(function () {
        return apiFetch('/source-leads' + listingAllQuery('source_leads')).then(function (body) {
          return unwrapList(body);
        });
      });

    var teamPromise = apiFetch('/team-sizes' + listingAllQuery('team_sizes'));
    var rolePromise = apiFetch('/role-masters' + listingAllQuery('role_masters'));
    var statePromise = window.CA_STATE_CITY
      ? window.CA_STATE_CITY.loadStates().then(function (states) {
        window.realStates = states;
        return states;
      })
      : apiFetch('/lookups/states').then(function (body) {
        window.realStates = unwrapList(body);
        return window.realStates;
      }).catch(function () {
        return apiFetch('/states' + listingAllQuery('states')).then(function (body) {
          window.realStates = unwrapList(body);
          return window.realStates;
        });
      });

    Promise.all([
      settledList(sourcePromise),
      settledList(teamPromise),
      settledList(rolePromise),
      settledList(statePromise),
    ]).then(function (results) {
      window.realSourceLeads = results[0].ok ? (results[0].value || []) : [];
      window.realTeamSizes = results[1].ok ? unwrapList(results[1].value) : [];
      window.realRoleMasters = results[2].ok ? unwrapList(results[2].value) : [];
      window.realRoles = window.realRoleMasters;
      if (results[3].ok && results[3].value) {
        window.realStates = results[3].value;
      } else {
        window.realStates = window.realStates || [];
      }
      window.realCities = [];
      masterDataLoaded = true;
      if (callback) callback();
    });
  }

  function buildMasterSelectOptions(items, valueKey, labelKey) {
    return (items || []).map(function (item) {
      return '<option value="' + item[valueKey] + '">' + item[labelKey] + '</option>';
    }).join('');
  }

  function activeMasterRecords(items) {
    return (items || []).filter(function (row) { return row.is_active !== false; });
  }

  function populateMasterDropdowns() {
    if (!masterDataLoaded) return;

    var sources = activeMasterRecords(window.realSourceLeads || []);

    document.querySelectorAll('select[name="source_id"]').forEach(function (sel) {
      var current = sel.value;
      var placeholder = '<option value="">Select source</option>';
      sel.innerHTML = sources.length
        ? placeholder + buildMasterSelectOptions(sources, 'source_id', 'source_name')
        : '<option value="">No sources — add in Masters</option>';
      setSelectValueIfValid(sel, current);
    });

    if (window.CA_STATE_CITY) {
      window.CA_STATE_CITY.initAllPairs();
    }
  }

  function masterStatusBadge(record) {
    if (record && record.is_system) {
      return '<span class="master-status-badge master-status-badge--system" title="System-protected record">System</span>';
    }
    if (record && record.is_active === false) {
      return '<span class="master-status-badge master-status-badge--inactive">Inactive</span>';
    }
    return '';
  }

  function masterRecordDisplayName(entity, record) {
    if (!record) return 'Record';
    if (entity === 'state') return record.state_name;
    if (entity === 'city') return record.city_name;
    if (entity === 'source') return record.source_name;
    if (entity === 'team') return record.team_size_label;
    if (entity === 'role') return record.role_name;
    return 'Record';
  }

  function setMasterDeleteGuardLoading(on) {
    var loading = document.getElementById('master-delete-guard-loading');
    var body = document.getElementById('master-delete-guard-body');
    if (loading) loading.classList.toggle('hidden', !on);
    if (body) body.classList.toggle('hidden', !!on);
    ['master-delete-guard-view-btn', 'master-delete-guard-reactivate-btn', 'master-delete-guard-deactivate-btn', 'master-delete-guard-confirm-delete-btn'].forEach(function (id) {
      var btn = document.getElementById(id);
      if (btn) btn.disabled = !!on;
    });
  }

  function resetMasterDeleteGuardModal() {
    window._masterDeleteGuardState = null;
    var viewUsage = document.getElementById('master-delete-guard-view-usage');
    if (viewUsage) viewUsage.classList.add('hidden');
    ['master-delete-guard-view-btn', 'master-delete-guard-reactivate-btn', 'master-delete-guard-deactivate-btn', 'master-delete-guard-confirm-delete-btn'].forEach(function (id) {
      var btn = document.getElementById(id);
      if (btn) btn.classList.add('hidden');
    });
  }

  function renderMasterDeleteGuardUsageList(dependencies, interactive) {
    return (dependencies || []).map(function (dep) {
      if (interactive && dep.filter_key && dep.filter_value != null) {
        return '<button type="button" class="master-usage-nav-btn" data-master-usage-filter="' + escapeHtml(dep.filter_key) + '" data-master-usage-value="' + escapeHtml(String(dep.filter_value)) + '">' +
          '<span>' + escapeHtml(dep.module) + '</span><strong>' + dep.count + '</strong></button>';
      }
      return '<li><span>' + escapeHtml(dep.module) + '</span><strong>' + dep.count + '</strong></li>';
    }).join('');
  }

  function openMasterDeleteGuardModal(entity, id, analysis) {
    var cfg = masterEntityConfig(entity);
    var modal = document.getElementById('modal-master-delete-guard');
    if (!cfg || !modal) return;

    var record = findMasterRecord(entity, id) || {};
    var recordName = analysis.record_name || masterRecordDisplayName(entity, record);
    var titleEl = document.getElementById('master-delete-guard-title-text');
    var messageEl = document.getElementById('master-delete-guard-message');
    var usageWrap = document.getElementById('master-delete-guard-usage-wrap');
    var usageList = document.getElementById('master-delete-guard-usage-list');
    var recommendation = document.getElementById('master-delete-guard-recommendation');
    var viewBtn = document.getElementById('master-delete-guard-view-btn');
    var deactivateBtn = document.getElementById('master-delete-guard-deactivate-btn');
    var reactivateBtn = document.getElementById('master-delete-guard-reactivate-btn');
    var confirmDeleteBtn = document.getElementById('master-delete-guard-confirm-delete-btn');
    var viewUsagePanel = document.getElementById('master-delete-guard-view-usage');
    var viewUsageList = document.getElementById('master-delete-guard-view-usage-list');

    window._masterDeleteGuardState = { entity: entity, id: id, cfg: cfg, analysis: analysis };

    if (viewUsagePanel) viewUsagePanel.classList.add('hidden');
    if (viewBtn) viewBtn.classList.add('hidden');
    if (deactivateBtn) deactivateBtn.classList.add('hidden');
    if (reactivateBtn) reactivateBtn.classList.add('hidden');
    if (confirmDeleteBtn) confirmDeleteBtn.classList.add('hidden');

    if (analysis.is_system) {
      if (titleEl) titleEl.textContent = 'System-Protected Record';
      if (messageEl) messageEl.textContent = 'This is a system-protected record and cannot be deleted.';
      if (usageWrap) usageWrap.classList.add('hidden');
      if (recommendation) recommendation.classList.add('hidden');
    } else if (analysis.is_active === false) {
      if (titleEl) titleEl.textContent = 'Record Already Inactive';
      if (messageEl) messageEl.textContent = '"' + recordName + '" is already inactive.';
      if (usageWrap) usageWrap.classList.add('hidden');
      if (recommendation) recommendation.classList.add('hidden');
      if (reactivateBtn && crmCanAction('ca_master', 'edit')) reactivateBtn.classList.remove('hidden');
    } else if (analysis.can_delete) {
      if (titleEl) titleEl.textContent = 'Delete ' + cfg.title + ' Permanently?';
      if (messageEl) messageEl.textContent = 'Delete "' + recordName + '" permanently? This action cannot be undone.';
      if (usageWrap) usageWrap.classList.add('hidden');
      if (recommendation) recommendation.classList.add('hidden');
      if (confirmDeleteBtn) confirmDeleteBtn.classList.remove('hidden');
    } else {
      if (titleEl) titleEl.textContent = 'Cannot Delete ' + cfg.title;
      if (messageEl) messageEl.textContent = 'Cannot delete "' + recordName + '".';
      if (usageWrap) usageWrap.classList.remove('hidden');
      if (usageList) {
        usageList.innerHTML = renderMasterDeleteGuardUsageList(analysis.dependencies || [], false);
      }
      if (recommendation) recommendation.classList.remove('hidden');
      if (viewBtn) viewBtn.classList.remove('hidden');
      if (deactivateBtn && crmCanAction('ca_master', 'edit')) deactivateBtn.classList.remove('hidden');
      if (viewUsageList) {
        viewUsageList.innerHTML = renderMasterDeleteGuardUsageList(analysis.dependencies || [], true);
      }
    }

    setMasterDeleteGuardLoading(false);
    openModal(modal);
    icons();
  }

  function fetchMasterDependencies(entity, id) {
    var cfg = masterEntityConfig(entity);
    if (!cfg) return Promise.reject(new Error('Unsupported master entity'));
    return apiFetch(cfg.endpoint + '/' + encodeURIComponent(id) + '/dependencies')
      .then(function (body) { return body.data || {}; });
  }

  function beginMasterDeleteFlow(entity, id) {
    var modal = document.getElementById('modal-master-delete-guard');
    if (!modal) return;
    resetMasterDeleteGuardModal();
    setMasterDeleteGuardLoading(true);
    openModal(modal);
    fetchMasterDependencies(entity, id)
      .then(function (analysis) {
        closeModal(modal);
        openMasterDeleteGuardModal(entity, id, analysis);
      })
      .catch(function (err) {
        setMasterDeleteGuardLoading(false);
        toast(err.message || 'Unable to check dependencies', 'error');
        closeModal(modal);
      });
  }

  function executeMasterDeactivate() {
    var state = window._masterDeleteGuardState;
    if (!state) return;
    var btn = document.getElementById('master-delete-guard-deactivate-btn');
    if (btn) btn.disabled = true;
    apiFetch(state.cfg.endpoint + '/' + encodeURIComponent(state.id) + '/deactivate', { method: 'PATCH' })
      .then(function () {
        toast(state.cfg.title + ' deactivated', 'success');
        closeModal(document.getElementById('modal-master-delete-guard'));
        refreshMasterDataCaches();
        if (window.CA_LISTING_SEARCH) {
          reloadListing('states');
          reloadListing('cities');
        }
      })
      .catch(function (err) {
        toast(err.message || 'Unable to deactivate record', 'error');
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function executeMasterReactivate() {
    var state = window._masterDeleteGuardState;
    if (!state) return;
    var btn = document.getElementById('master-delete-guard-reactivate-btn');
    if (btn) btn.disabled = true;
    apiFetch(state.cfg.endpoint + '/' + encodeURIComponent(state.id) + '/reactivate', { method: 'PATCH' })
      .then(function () {
        toast(state.cfg.title + ' reactivated', 'success');
        closeModal(document.getElementById('modal-master-delete-guard'));
        refreshMasterDataCaches();
        if (window.CA_LISTING_SEARCH) {
          reloadListing('states');
          reloadListing('cities');
        }
      })
      .catch(function (err) {
        toast(err.message || 'Unable to reactivate record', 'error');
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function executeMasterPermanentDelete() {
    var state = window._masterDeleteGuardState;
    if (!state) return;
    var btn = document.getElementById('master-delete-guard-confirm-delete-btn');
    if (btn) btn.disabled = true;
    apiFetch(state.cfg.endpoint + '/' + encodeURIComponent(state.id), { method: 'DELETE' })
      .then(function () {
        toast(state.cfg.title + ' deleted', 'success');
        closeModal(document.getElementById('modal-master-delete-guard'));
        refreshMasterDataCaches();
        if (window.CA_LISTING_SEARCH) {
          reloadListing('states');
          reloadListing('cities');
        }
      })
      .catch(function (err) {
        if (err.status === 409 && err.data) {
          openMasterDeleteGuardModal(state.entity, state.id, err.data);
        } else {
          toast(err.message || 'Unable to delete record', 'error');
        }
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function bindMasterDeleteGuardModal() {
    if (window._masterDeleteGuardBound) return;
    window._masterDeleteGuardBound = true;
    document.getElementById('master-delete-guard-deactivate-btn')?.addEventListener('click', executeMasterDeactivate);
    document.getElementById('master-delete-guard-reactivate-btn')?.addEventListener('click', executeMasterReactivate);
    document.getElementById('master-delete-guard-confirm-delete-btn')?.addEventListener('click', executeMasterPermanentDelete);
    document.getElementById('master-delete-guard-view-btn')?.addEventListener('click', function () {
      var panel = document.getElementById('master-delete-guard-view-usage');
      if (panel) panel.classList.toggle('hidden');
    });
    document.getElementById('master-delete-guard-view-usage-list')?.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-master-usage-filter]');
      if (!btn) return;
      var filterKey = btn.getAttribute('data-master-usage-filter');
      var filterValue = btn.getAttribute('data-master-usage-value');
      if (window.CA_LISTING_SEARCH) {
        var filters = {};
        filters[filterKey] = filterValue;
        CA_LISTING_SEARCH.setState('ca_masters', { page: 1, search: '', filters: filters });
      }
      closeModal(document.getElementById('modal-master-delete-guard'));
      if (typeof navigateTo === 'function') navigateTo('ca-master');
    });
  }

  function masterActionCell(entity, id) {
    if (!window.CAActionDropdown) return '<td class="crm-actions-cell text-right"><span class="cam-cell-empty">—</span></td>';
    var items = [];
    if (crmCanAction('ca_master', 'edit')) {
      items.push({
        action: 'edit',
        label: 'Edit',
        icon: 'pencil',
        dataAttrs: { 'master-entity': entity, 'master-id': id },
      });
    }
    if (crmCanAction('ca_master', 'delete')) {
      items.push({
        action: 'delete',
        label: 'Delete',
        icon: 'trash-2',
        danger: true,
        dataAttrs: { 'master-entity': entity, 'master-id': id },
      });
    }
    return CAActionDropdown.renderCell(items, {
      scope: 'master',
      rowId: entity + ':' + id,
      cellClass: 'crm-actions-cell text-right',
      ariaLabel: 'Master data actions',
    });
  }

  function applyMasterDataRbac() {
    var canCreate = crmCanAction('ca_master', 'create');
    document.querySelectorAll('[data-master-add]').forEach(function (btn) {
      btn.classList.toggle('hidden', !canCreate);
    });
    document.querySelectorAll('#cam-add-firm-btn').forEach(function (btn) {
      btn.classList.toggle('hidden', !canCreate);
    });
    document.querySelectorAll('#cam-import-btn').forEach(function (btn) {
      btn.classList.toggle('hidden', !crmCanAction('ca_master', 'import'));
    });
    document.querySelectorAll('#cam-export-btn').forEach(function (btn) {
      btn.classList.toggle('hidden', !crmCanAction('ca_master', 'export'));
    });
    document.querySelectorAll('[data-inbox-module="ca-master"] [data-inbox-action]').forEach(function (btn) {
      var action = btn.getAttribute('data-inbox-action');
      var allowed = true;
      if (action === 'assign') allowed = crmCanAction('assignment', 'create');
      else if (action === 'import') allowed = crmCanAction('ca_master', 'import');
      else if (action === 'export') allowed = crmCanAction('ca_master', 'export');
      else if (action === 'delete') allowed = crmCanAction('ca_master', 'delete');
      btn.classList.toggle('hidden', !allowed);
    });
    var bulkToolbar = document.querySelector('[data-inbox-module="ca-master"] .crm-inbox-bulk-toolbar');
    if (bulkToolbar) {
      var visible = bulkToolbar.querySelectorAll('[data-inbox-action]:not(.hidden)');
      bulkToolbar.classList.toggle('hidden', !visible.length);
    }
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
    bindMasterDeleteGuardModal();

    document.addEventListener('click', function (e) {
      var addBtn = e.target.closest('[data-master-add]');
      if (addBtn) {
        e.preventDefault();
        openMasterRecordModal(addBtn.getAttribute('data-master-add'), null);
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
        return '<tr class="ca-table-row' + (s.is_active === false ? ' opacity-70' : '') + '">' +
          '<td class="font-medium">' + escapeHtml(s.state_name) + masterStatusBadge(s) + '</td>' +
          '<td>' + (s.cities_count != null ? s.cities_count : '—') + '</td>' +
          '<td>' + formatRelativeDate(s.created_at) + '</td>' +
          masterActionCell('state', s.state_id) +
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
        return '<tr class="ca-table-row' + (s.is_active === false ? ' opacity-70' : '') + '">' +
          '<td class="font-medium">' + escapeHtml(s.source_name) + masterStatusBadge(s) + '</td>' +
          '<td>—</td>' +
          '<td>—</td>' +
          masterActionCell('source', s.source_id) +
        '</tr>';
      }).join('') : '<tr><td colspan="4" class="text-center text-slate-500 p-4">No lead sources yet.</td></tr>';
    }

    var teamSizesEl = document.getElementById('master-team-sizes-table');
    if (teamSizesEl) {
      var teamSizes = window.realTeamSizes || [];
      teamSizesEl.innerHTML = teamSizes.length ? teamSizes.map(function (t) {
        var id = t.team_size_id || t.id;
        return '<tr class="ca-table-row' + (t.is_active === false ? ' opacity-70' : '') + '">' +
          '<td>' + (t.team_size_min != null ? t.team_size_min : '—') + '</td>' +
          '<td>' + (t.team_size_max != null ? t.team_size_max : '—') + '</td>' +
          '<td>' + escapeHtml(t.team_size_label || '—') + masterStatusBadge(t) + '</td>' +
          '<td>—</td>' +
          masterActionCell('team', id) +
        '</tr>';
      }).join('') : '<tr><td colspan="5" class="text-center text-slate-500 p-4">No team size ranges yet.</td></tr>';
    }

    var rolesEl = document.getElementById('master-roles-table');
    if (rolesEl) {
      var roles = window.realRoleMasters || [];
      rolesEl.innerHTML = roles.length ? roles.map(function (r) {
        return '<tr class="ca-table-row' + (r.is_active === false ? ' opacity-70' : '') + '">' +
          '<td class="font-medium">' + escapeHtml(r.role_name) + masterStatusBadge(r) + '</td>' +
          '<td>' + escapeHtml(r.description || '—') + '</td>' +
          masterActionCell('role', r.id) +
        '</tr>';
      }).join('') : '<tr><td colspan="3" class="text-center text-slate-500 p-4">No roles yet.</td></tr>';
    }
  }

  function enrichLeadsWithAssignments() {
    if (!window.realLeads || !window.realAssignments) return;
    var latestByCa = {};
    window.realAssignments.forEach(function (a) {
      if (a.status && String(a.status).toLowerCase() !== 'active') return;
      var caId = String(a.ca_id);
      if (!latestByCa[caId] || Number(a.assignment_id) > Number(latestByCa[caId].assignment_id)) {
        latestByCa[caId] = a;
      }
    });
    window.realLeads = window.realLeads.map(function (lead) {
      var asgn = latestByCa[String(lead.ca_id)];
      var hasNamedExecutive = lead.executive && lead.executive !== 'Unassigned' && lead.executive !== '—' && lead.executive !== 'Assigned';
      if (hasNamedExecutive && lead.executive_id) return lead;
      if (!asgn) {
        if (lead.executive_id && !hasNamedExecutive) {
          return Object.assign({}, lead, { executive: lead.executive || 'Assigned' });
        }
        return lead;
      }
      return Object.assign({}, lead, {
        executive_id: String(asgn.employee_id),
        executive: asgn.executive || asgn.employee_name || lead.executive || 'Assigned',
        assignment_type: asgn.assignment_type || lead.assignment_type,
      });
    });
  }

  function setLeadsHubLoadingState(isLoading) {
    leadsHubLoading = !!isLoading;
    var table = document.getElementById('leads-data-table') || document.getElementById('ca-master-data-table');
    var kanban = document.getElementById('kanban-board');
    var loadingRow = '<tr><td colspan="15" class="text-center text-slate-500 p-6"><span class="inline-flex items-center gap-2"><i data-lucide="loader-2" class="h-4 w-4 animate-spin"></i> Loading leads…</span></td></tr>';
    var loadingKanban = '<div class="w-full text-center text-slate-500 p-8"><span class="inline-flex items-center gap-2"><i data-lucide="loader-2" class="h-4 w-4 animate-spin"></i> Loading pipeline…</span></div>';
    if (isLoading) {
      if (kanban && isAnyPipelineTabActive() && !kanban.querySelector('.kanban-column')) {
        kanban.innerHTML = loadingKanban;
      }
      if (table && !isAnyPipelineTabActive() && !table.querySelector('tr.ca-table-row') && !table.querySelector('.cam-loading-row')) {
        table.innerHTML = loadingRow;
      }
      icons();
    }
  }

  function upsertLeadInCache(mappedLead) {
    if (!mappedLead || !mappedLead.ca_id) return;
    var leads = window.realLeads || [];
    var idx = leads.findIndex(function (l) { return String(l.ca_id) === String(mappedLead.ca_id); });
    if (idx >= 0) {
      leads[idx] = Object.assign({}, leads[idx], mappedLead);
    } else {
      leads.unshift(mappedLead);
    }
    window.realLeads = leads;
    window._selectLeads = leads.slice();
    window._selectLeadsLoaded = true;
    realLeadsLoaded = true;
    if (window._listingLeadsPage) {
      var pageIdx = window._listingLeadsPage.findIndex(function (l) { return String(l.ca_id) === String(mappedLead.ca_id); });
      if (pageIdx >= 0) {
        window._listingLeadsPage[pageIdx] = Object.assign({}, window._listingLeadsPage[pageIdx], mappedLead);
      }
    }
    if (window.kanbanLeads) {
      var kanbanIdx = window.kanbanLeads.findIndex(function (l) { return String(l.ca_id) === String(mappedLead.ca_id); });
      if (kanbanIdx >= 0) {
        window.kanbanLeads[kanbanIdx] = Object.assign({}, window.kanbanLeads[kanbanIdx], mappedLead);
      }
    }
  }

  function mergeLeadFromApiResponse(body) {
    var raw = (body && body.data) ? body.data : body;
    if (!raw || raw.ca_id === undefined || raw.ca_id === null) return null;
    return mapLeadRecord(raw);
  }

  function refreshLeadsUi(options) {
    options = options || {};
    enrichLeadsWithAssignments();
    if (options.invalidateMetrics) {
      dashboardMetricsLoaded = false;
      dashboardMetricsPromise = null;
      leadSegmentCountsLoaded = false;
      leadSegmentCounts = null;
    }
    if (document.getElementById('leads-kpi-strip')) {
      if (options.invalidateMetrics) {
        loadLeadSegmentCounts(function () {
          renderLeadKpis();
        }, { force: true });
      } else {
        renderLeadKpis();
      }
    }
    if (document.getElementById('leads-data-table')) {
      if (window.CA_LISTING_SEARCH && options.reloadListing !== false) {
        reloadListing('ca_masters');
      } else {
        renderLeadsTable();
      }
    }
    if (document.getElementById('kanban-board')) {
      renderKanbanFromData();
    }
    var selId = CAData.getSelectedLeadId();
    if (selId) {
      highlightLeadSelection(selId);
      updateSelectedLeadBar(getLeadRecord(selId));
    }
    icons();
  }

  function reloadLeadDataAfterMutation(options) {
    options = options || {};
    invalidateDataCaches(options.cacheKeys || ['metrics', 'leads', 'assignments']);
    return Promise.all([
      new Promise(function (resolve) { loadAssignmentsFromDatabase(resolve); }),
      new Promise(function (resolve) { loadLeadsFromDatabase(resolve); }),
    ]).then(function () {
      enrichLeadsWithAssignments();
      refreshLeadsUi({ invalidateMetrics: true, reloadListing: options.reloadListing !== false });
      if (document.getElementById('assignment-page-root')) {
        refreshAssignmentDashboardWidgets();
      }
    });
  }

  function applyLeadMutationSuccess(body, options) {
    options = options || {};
    var mapped = mergeLeadFromApiResponse(body);
    invalidateDataCaches(options.cacheKeys || ['metrics', 'assignments']);
    if (mapped) {
      upsertLeadInCache(mapped);
      return new Promise(function (resolve) {
        loadAssignmentsFromDatabase(function () {
          enrichLeadsWithAssignments();
          refreshLeadsUi({ invalidateMetrics: true, reloadListing: options.reloadListing });
          if (options.toast) toast(options.toast, 'success');
          if (options.callback) options.callback(mapped);
          resolve(mapped);
        });
      });
    }
    return reloadLeadDataAfterMutation(options).then(function () {
      if (options.toast) toast(options.toast, 'success');
      if (options.callback) options.callback(null);
      return null;
    });
  }

  function metricsDataReady() {
    return isEmployeeUser() ? employeeDashboardLoaded : dashboardMetricsLoaded;
  }

  function getLeadHubMetrics() {
    if (isEmployeeUser()) {
      return mapEmployeeDashboardToLeadMetrics(employeeDashboardData || {});
    }
    return window.dashboardMetrics || {};
  }

  function isLeadsPipelineTabActive() {
    var panel = document.querySelector('.ca-tab-panel[data-tab-group="leads-view"][data-panel="pipeline"]');
    return !!(panel && panel.classList.contains('active'));
  }

  function isLeadsAllTabActive() {
    var panel = document.querySelector('.ca-tab-panel[data-tab-group="leads-view"][data-panel="all"]');
    return !!(panel && panel.classList.contains('active'));
  }

  function isAnyPipelineTabActive() {
    return isLeadsPipelineTabActive() || isCamPipelineTabActive();
  }

  function mapSegmentCountsToLeadMetrics(counts) {
    if (!counts) return {};
    return {
      total_leads: counts.all || 0,
      new_leads: counts.new || 0,
      hot_leads: counts.hot || 0,
      pipeline_leads: counts.pipeline || 0,
      lost_leads: counts.lost || 0,
    };
  }

  function loadLeadSegmentCounts(callback, options) {
    options = options || {};
    if (!options.force && leadSegmentCountsLoaded && leadSegmentCounts) {
      if (callback) callback(leadSegmentCounts);
      return Promise.resolve(leadSegmentCounts);
    }
    if (leadSegmentCountsPromise) {
      return leadSegmentCountsPromise.then(function (data) {
        if (callback) callback(data);
        return data;
      });
    }
    leadSegmentCountsPromise = apiFetch('/ca-masters/segment-counts')
      .then(function (body) {
        leadSegmentCounts = body.data || body;
        leadSegmentCountsLoaded = true;
        leadSegmentCountsPromise = null;
        if (callback) callback(leadSegmentCounts);
        return leadSegmentCounts;
      })
      .catch(function (err) {
        leadSegmentCountsPromise = null;
        if (callback) callback(null, err);
        throw err;
      });
    return leadSegmentCountsPromise;
  }

  function ensureLeadsHubData(callback) {
    if (leadSegmentCountsLoaded && leadSegmentCounts) {
      if (callback) callback();
      return Promise.resolve();
    }
    setLeadsHubLoadingState(true);
    return loadLeadSegmentCounts(function () {
      setLeadsHubLoadingState(false);
      if (callback) callback();
    }).catch(function (error) {
      setLeadsHubLoadingState(false);
      toast((error && error.message) ? error.message : 'Unable to load lead data. Please refresh.', 'error');
      if (callback) callback();
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
        window._formSelectDataReady = true;
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
      populateMasterDropdowns();
      window._formSelectDataReady = true;
      if (callback) callback();
    }
  }

  function enhanceEntityLookups(root) {
    if (window.CrmEntityLookup && typeof window.CrmEntityLookup.enhanceAll === 'function') {
      window.CrmEntityLookup.enhanceAll(root || document);
    }
  }

  function setSelectValueIfValid(select, value, record) {
    if (!select || value === undefined || value === null || value === '') return;
    if (select.dataset && select.dataset.crmEntityLookup && window.CrmEntityLookup) {
      enhanceEntityLookups(select.parentElement || document);
      return window.CrmEntityLookup.setValue(select, value, record);
    }
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

  function buildExecOptionsHtml(executives, placeholder) {
    placeholder = placeholder || 'Select employee';
    var blank = '<option value="">' + escapeHtml(placeholder) + '</option>';
    if (!executives || !executives.length) {
      return blank;
    }
    return blank + executives.map(function (e) {
      return '<option value="' + e.employee_id + '">' + e.name + ' · ' + (e.city || '—') + '</option>';
    }).join('');
  }

  function getExecutivesForSelects() {
    if (window._selectEmployees && window._selectEmployees.length) {
      return window._selectEmployees;
    }
    if (realEmployeesLoaded && window.realEmployees && window.realEmployees.length) {
      return window.realEmployees;
    }
    return getDashboardExecutives();
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

  function dashboardCacheStorageKey() {
    var crmUser = window.__CRM_USER__ || {};
    var role = crmUser.role || 'user';
    var scope = role;
    if (role === 'employee' && crmUser.id) {
      scope = role + '_' + crmUser.id;
    }
    return 'crm_dashboard_metrics_v2_' + scope;
  }

  function readDashboardMetricsCache() {
    var isEmployee = (window.__CRM_USER__ || {}).role === 'employee';
    var storages = isEmployee ? [sessionStorage] : [sessionStorage, localStorage];
    for (var s = 0; s < storages.length; s++) {
      try {
        var raw = storages[s].getItem(dashboardCacheStorageKey());
        if (!raw) continue;
        var parsed = JSON.parse(raw);
        if (!parsed || !parsed.data || !parsed.savedAt) continue;
        var maxAge = storages[s] === localStorage
          ? DASHBOARD_CACHE_TTL_MS * 30
          : DASHBOARD_CACHE_TTL_MS * 5;
        if (Date.now() - parsed.savedAt > maxAge) continue;
        if (!parsed.data.productivity || typeof parsed.data.productivity !== 'object') continue;
        return parsed;
      } catch (e) { /* try next storage */ }
    }
    return null;
  }

  function writeDashboardMetricsCache(data) {
    if (!data) return;
    var payload = JSON.stringify({
      savedAt: Date.now(),
      data: data,
    });
    var isEmployee = (window.__CRM_USER__ || {}).role === 'employee';
    try { sessionStorage.setItem(dashboardCacheStorageKey(), payload); } catch (e) { /* quota */ }
    // Employee dashboards change on assignment; avoid long-lived localStorage staleness.
    if (!isEmployee) {
      try { localStorage.setItem(dashboardCacheStorageKey(), payload); } catch (e) { /* quota */ }
    }
  }

  function clearDashboardMetricsCache() {
    try { sessionStorage.removeItem(dashboardCacheStorageKey()); } catch (e) { /* ignore */ }
    try { localStorage.removeItem(dashboardCacheStorageKey()); } catch (e) { /* ignore */ }
  }

  function mapDashboardLeadSummary(lead) {
    return {
      ca_id: String(lead.ca_id),
      firm_name: lead.firm_name || '—',
      ca_name: lead.ca_name || '—',
      city: lead.city || '—',
      status: lead.status || 'New',
      stage: lead.stage || mapStatusToStage(lead.status),
      executive: lead.executive || 'Unassigned',
      updated: formatRelativeDate(lead.updated_at),
    };
  }

  function setDashboardChartsLoading(isLoading) {
    document.querySelectorAll('[data-dashboard-chart]').forEach(function (el) {
      if (isLoading) {
        delete el.dataset.chartReady;
        el.innerHTML = '<div class="dash-chart-loading flex items-center justify-center gap-2 p-4 text-slate-400"><i data-lucide="loader-2" class="h-4 w-4 animate-spin"></i><span class="text-caption">Loading chart…</span></div>';
      }
    });
    if (isLoading) icons();
  }

  function showDashboardLoadError(message) {
    var root = document.querySelector('.mgr-dashboard');
    if (!root) return;
    var banner = document.getElementById('dashboard-load-error');
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'dashboard-load-error';
      banner.className = 'dashboard-load-error';
      root.insertBefore(banner, root.firstChild);
    }
    banner.innerHTML = '<i data-lucide="alert-circle" class="h-4 w-4"></i><span>' + escapeHtml(message || 'Unable to load dashboard metrics. Please refresh.') + '</span>';
    banner.classList.remove('hidden');
    icons();
  }

  function hideDashboardLoadError() {
    var banner = document.getElementById('dashboard-load-error');
    if (banner) banner.classList.add('hidden');
  }

  function paintDashboardLoadingShell() {
    paintDashboardInstantShell(true);
  }

  function paintDashboardInstantShell(showSpinner) {
    var crmUser = window.__CRM_USER__ || {};
    var displayName = crmUser.name || 'User';
    var roleLabel = crmUser.role_label || crmUser.role || 'User';
    var now = new Date();
    var greeting = now.getHours() < 12 ? 'Good morning' : now.getHours() < 17 ? 'Good afternoon' : 'Good evening';
    var dateStr = now.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    var greetingLine = dashboardGreetingText(greeting, displayName, roleLabel);
    var badgeIcon = showSpinner
      ? '<i data-lucide="loader-2" class="h-3.5 w-3.5 animate-spin"></i> Refreshing'
      : '<i data-lucide="layout-dashboard" class="h-3.5 w-3.5"></i> ' + escapeHtml(roleLabel);

    var top = document.getElementById('mgr-top-header');
    if (top) {
      top.innerHTML =
        '<div class="mgr-top-left">' +
          '<span class="manager-role-badge">' + badgeIcon + '</span>' +
          '<h1 class="text-page-title mgr-greeting">' + escapeHtml(greetingLine) + '</h1>' +
          '<p class="mgr-top-meta text-slate-400">' + escapeHtml(dateStr) + ' · updating metrics</p>' +
        '</div>' +
        managerDashboardFiltersHtml();
    }
    ensureManagerEmployeeFilter();
    iconsIn(document.querySelector('.mgr-dashboard') || document);
  }

  function paintEmployeeDashboardInstantShell() {
    var crmUser = window.__CRM_USER__ || {};
    var now = new Date();
    var greeting = now.getHours() < 12 ? 'Good morning' : now.getHours() < 17 ? 'Good afternoon' : 'Good evening';
    var timeStr = now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
    var dateStr = now.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    var roleBadge = crmUser.role_label || 'Employee';
    var top = document.getElementById('emp-top-header');
    if (top) {
      top.innerHTML =
        '<div class="mgr-top-left">' +
          '<span class="manager-role-badge"><i data-lucide="briefcase" class="h-3.5 w-3.5"></i> ' + escapeHtml(roleBadge) + '</span>' +
          '<h1 class="text-page-title mgr-greeting">' + escapeHtml(dashboardGreetingText(greeting, crmUser.name, roleBadge)) + '</h1>' +
          '<p class="mgr-top-meta text-slate-400">' + escapeHtml(dateStr) + ' · ' + escapeHtml(timeStr) + ' · updating metrics</p>' +
        '</div>';
    }
    iconsIn(document.querySelector('.emp-dashboard') || document);
  }

  var DASHBOARD_DATE_PRESETS = {
    today: 'Today',
    yesterday: 'Yesterday',
    last_7_days: 'Last 7 Days',
    last_15_days: 'Last 15 Days',
    last_30_days: 'Last 30 Days',
    this_week: 'This Week',
    last_week: 'Last Week',
    this_month: 'This Month',
    last_month: 'Last Month',
    last_quarter: 'Last Quarter',
    last_half_year: 'Last Half Year',
    this_year: 'This Year',
    last_year: 'Last Year',
    custom: 'Custom Range',
  };

  function getDashboardEmployeeFilterId() {
    if (isEmployeeUser()) return null;
    try {
      var stored = localStorage.getItem('crm_dashboard_employee_id');
      if (!stored || stored === 'all') return null;
      var id = parseInt(stored, 10);
      return id > 0 ? id : null;
    } catch (e) {
      return null;
    }
  }

  function setDashboardEmployeeFilterId(employeeId) {
    try {
      if (!employeeId) localStorage.setItem('crm_dashboard_employee_id', 'all');
      else localStorage.setItem('crm_dashboard_employee_id', String(employeeId));
    } catch (e) { /* ignore */ }
  }

  function clearStaleDashboardEmployeeFilter() {
    var selectedId = getDashboardEmployeeFilterId();
    if (!selectedId) return false;
    var employees = managerEmployeeFilterState.employees || [];
    if (!managerEmployeeFilterState.loaded) return false;
    var valid = employees.some(function (row) {
      return String(row.employee_id) === String(selectedId);
    });
    if (valid) return false;
    try {
      localStorage.removeItem('crm_dashboard_employee_id');
      localStorage.setItem('crm_dashboard_employee_id', 'all');
    } catch (e) { /* ignore */ }
    setDashboardEmployeeFilterId(null);
    dashboardMetricsLoaded = false;
    return true;
  }

  function recoverDashboardEmployeeFilterError(err, callback, options) {
    var message = (err && err.message) ? String(err.message) : '';
    if (!/do not have access to this employee/i.test(message)) return false;
    try {
      localStorage.removeItem('crm_dashboard_employee_id');
      localStorage.setItem('crm_dashboard_employee_id', 'all');
    } catch (e) { /* ignore */ }
    setDashboardEmployeeFilterId(null);
    dashboardMetricsLoaded = false;
    if (options && options._employeeFilterRecovered) return false;
    loadDashboardMetricsFromDatabase(callback, Object.assign({}, options || {}, { force: true, _employeeFilterRecovered: true }));
    toast('Previous employee filter was cleared. Showing organization dashboard.', 'info');
    return true;
  }

  function getDashboardDateFilter() {
    try {
      var preset = localStorage.getItem('crm_dashboard_date_preset') || 'today';
      var from = localStorage.getItem('crm_dashboard_date_from') || '';
      var to = localStorage.getItem('crm_dashboard_date_to') || '';
      if (!DASHBOARD_DATE_PRESETS[preset]) preset = 'today';
      return { preset: preset, from: from, to: to };
    } catch (e) {
      return { preset: 'today', from: '', to: '' };
    }
  }

  function setDashboardDateFilter(preset, from, to) {
    try {
      localStorage.setItem('crm_dashboard_date_preset', preset || 'today');
      localStorage.setItem('crm_dashboard_date_from', from || '');
      localStorage.setItem('crm_dashboard_date_to', to || '');
    } catch (e) { /* ignore */ }
  }

  function buildDashboardMetricsQuery(employeeId, dateFilter) {
    var params = [];
    if (employeeId) params.push('employee_id=' + encodeURIComponent(employeeId));
    dateFilter = dateFilter || getDashboardDateFilter();
    if (dateFilter.preset) params.push('date_preset=' + encodeURIComponent(dateFilter.preset));
    if (dateFilter.preset === 'custom') {
      if (dateFilter.from) params.push('from=' + encodeURIComponent(dateFilter.from));
      if (dateFilter.to) params.push('to=' + encodeURIComponent(dateFilter.to));
    }
    return params.length ? ('?' + params.join('&')) : '';
  }

  function loadDashboardMetricsFromDatabase(callback, options) {
    options = options || {};
    var isEmployee = isEmployeeUser();
    var filterEmployeeId = options.employeeId !== undefined ? options.employeeId : getDashboardEmployeeFilterId();
    var dateFilter = options.dateFilter || getDashboardDateFilter();
    var isDefaultFilters = !filterEmployeeId && (!dateFilter.preset || dateFilter.preset === 'today');
    var endpoint = isEmployee
      ? '/dashboard/employee'
      : ('/dashboard/metrics' + buildDashboardMetricsQuery(filterEmployeeId, dateFilter));

    // Default org-wide "today" views may use cache; filtered views always fetch live.
    if (!options.force && !options.background && isDefaultFilters) {
      var cached = readDashboardMetricsCache();
      if (cached && cached.data) {
        window.dashboardMetrics = isEmployee ? cached.data : cached.data;
        window.__dashboardUpdatedAt = cached.savedAt || Date.now();
        if (isEmployee) {
          employeeDashboardData = cached.data;
          employeeDashboardLoaded = true;
        } else {
          dashboardMetricsLoaded = true;
        }
        var metrics = isEmployee ? mapEmployeeDashboardToLeadMetrics(cached.data) : cached.data;
        if (callback) callback(metrics, null, { fromCache: true });
        loadDashboardMetricsFromDatabase(function (freshMetrics, err, meta) {
          if (!meta || !meta.background || err) return;
          if (isEmployeeUser()) {
            if (!employeeDashboardData) return;
            if (window._currentPageId === 'dashboard') {
              paintEmployeeDashboard(employeeDashboardData);
            }
          } else {
            if (!window.dashboardMetrics) return;
            if (window._currentPageId === 'dashboard') {
              paintManagerDashboard(buildDashboardDisplayMetrics(freshMetrics || window.dashboardMetrics));
              renderDashboardCharts((freshMetrics || window.dashboardMetrics).reports);
            }
          }
          if (document.getElementById('leads-kpi-strip')) renderLeadKpis();
        }, { force: true, background: true });
        return;
      }
    }

    if (!options.background && dashboardMetricsLoaded && window.dashboardMetrics && !options.force && isDefaultFilters) {
      var current = isEmployee ? mapEmployeeDashboardToLeadMetrics(employeeDashboardData || window.dashboardMetrics) : window.dashboardMetrics;
      if (callback) callback(current, null, { fromCache: true });
      return;
    }

    var activePromise = isEmployee ? employeeDashboardPromise : dashboardMetricsPromise;
    // Reuse in-flight requests only for non-forced loads so filter changes (e.g. Clear)
    // always fetch the correct employee / organization payload.
    if (activePromise && !options.force) {
      activePromise.then(function (data) {
        if (!data) {
          if (callback) callback(null, new Error('Unable to load dashboard'));
          return;
        }
        var result = isEmployee ? mapEmployeeDashboardToLeadMetrics(data) : data;
        if (callback) callback(result, null, { fromCache: false });
      }).catch(function (err) {
        if (callback) callback(null, err);
      });
      return;
    }

    var requestSeq = ++dashboardMetricsRequestSeq;
    var fetchPromise = apiFetch(endpoint)
      .then(function (body) {
        if (requestSeq !== dashboardMetricsRequestSeq) return null;
        var data = body.data || {};
        if (isDefaultFilters) writeDashboardMetricsCache(data);
        hideDashboardLoadError();
        if (isEmployee) {
          employeeDashboardData = data;
          employeeDashboardLoaded = true;
          window.__dashboardUpdatedAt = Date.now();
          return data;
        }
        window.dashboardMetrics = data;
        window.__dashboardUpdatedAt = Date.now();
        dashboardMetricsLoaded = true;
        return data;
      })
      .catch(function (err) {
        if (requestSeq !== dashboardMetricsRequestSeq) return null;
        if (!options.background) {
          window.dashboardMetrics = null;
          dashboardMetricsLoaded = true;
        }
        if (isEmployee) {
          employeeDashboardData = null;
          employeeDashboardLoaded = true;
        }
        throw err;
      })
      .finally(function () {
        if (isEmployee) {
          if (employeeDashboardPromise === fetchPromise) employeeDashboardPromise = null;
        } else if (dashboardMetricsPromise === fetchPromise) {
          dashboardMetricsPromise = null;
        }
      });

    if (isEmployee) employeeDashboardPromise = fetchPromise;
    else dashboardMetricsPromise = fetchPromise;

    fetchPromise.then(function (data) {
      if (requestSeq !== dashboardMetricsRequestSeq) return;
      if (!data) {
        if (callback) callback(null, new Error('Unable to load dashboard'));
        return;
      }
      var result = isEmployee ? mapEmployeeDashboardToLeadMetrics(data) : data;
      if (callback) callback(result, null, { fromCache: false, background: !!options.background });
    }).catch(function (err) {
      if (requestSeq !== dashboardMetricsRequestSeq) return;
      if (recoverDashboardEmployeeFilterError(err, callback, options)) return;
      if (callback) callback(null, err, { background: !!options.background });
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
    var metrics = window.dashboardMetrics || {};
    if (metrics.team_summary && metrics.team_summary.length) {
      return metrics.team_summary.map(function (employee) {
        return {
          employee_id: String(employee.employee_id),
          name: employee.name || '—',
          city: employee.city || '—',
          achieved_leads: employee.achieved_leads || 0,
          target_leads: employee.target_leads || 20,
          daily_calls: employee.daily_calls || 0,
          demos: employee.demos || 0,
        };
      });
    }
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
      new_status_leads: metrics ? metrics.new_status_leads : 0,
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
      productivity: metrics ? metrics.productivity : null,
      duplicate_monitoring: metrics ? metrics.duplicate_monitoring : null,
    };
  }

  function getDashboardPipelineBreakdown() {
    var metrics = window.dashboardMetrics || {};
    if (metrics.pipeline_breakdown && metrics.pipeline_breakdown.length) {
      return metrics.pipeline_breakdown;
    }
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
    var metrics = window.dashboardMetrics || {};
    if (metrics.priority_leads && metrics.priority_leads.length) {
      return metrics.priority_leads.map(mapDashboardLeadSummary);
    }
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
      window._selectedLeadId = recordId;
      if (typeof navigateTo === 'function') navigateTo(isEmployeeUser() ? 'leads' : 'ca-master');
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
      { label: 'Import Leads', icon: 'upload', page: 'bulk' },
      { label: 'Assign Lead', icon: 'user-check', modal: 'assign-lead' },
      { label: 'Schedule Follow-up', icon: 'calendar-clock', modal: 'followup' },
      { label: 'Communication', icon: 'messages-square', page: 'whatsapp' },
      { label: 'Reports', icon: 'bar-chart-3', page: 'reports' },
    ];
    el.innerHTML = actions.map(function (action) {
      if (action.modal) {
        return '<button type="button" class="dash-quick-action-btn" data-open-modal="' + action.modal + '">' +
          '<span class="dash-quick-action-btn__icon"><i data-lucide="' + action.icon + '" class="h-4 w-4"></i></span>' +
          '<span class="dash-quick-action-btn__label">' + action.label + '</span></button>';
      }
      if (action.campaign) {
        return '<button type="button" class="dash-quick-action-btn" data-nav-page="' + action.page + '" data-open-campaign="' + action.campaign + '">' +
          '<span class="dash-quick-action-btn__icon"><i data-lucide="' + action.icon + '" class="h-4 w-4"></i></span>' +
          '<span class="dash-quick-action-btn__label">' + action.label + '</span></button>';
      }
      return '<button type="button" class="dash-quick-action-btn" data-nav-page="' + action.page + '">' +
        '<span class="dash-quick-action-btn__icon"><i data-lucide="' + action.icon + '" class="h-4 w-4"></i></span>' +
        '<span class="dash-quick-action-btn__label">' + action.label + '</span></button>';
    }).join('');
    el.querySelectorAll('[data-open-campaign]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (typeof navigateTo === 'function') navigateTo(btn.getAttribute('data-nav-page'));
        setTimeout(function () {
          var modal = document.getElementById('modal-add-campaign');
          if (modal && typeof openModal === 'function') {
            configureCampaignModal(btn.getAttribute('data-open-campaign'));
            openModal(modal);
            initCampaignScheduledDateField();
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
      { label: 'Hot Leads', icon: 'flame', page: 'ca-master', leadFilter: 'hot' },
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
      '<span class="dash-activity-avatar" aria-hidden="true">' + escapeHtml(userInitials(log.performed_by || opts.userFallback || 'System')) + '</span>' +
      '<span class="dash-activity-icon" aria-hidden="true"><i data-lucide="' + activityModuleIcon(log.module_name) + '" class="h-4 w-4"></i></span>' +
      '<span class="dash-activity-body">' +
        '<span class="dash-activity-action">' + actionLabel + '</span>' +
        '<span class="dash-activity-detail">' + userLabel + (detail ? ' · ' + detail : '') + '</span>' +
      '</span>' +
      '<span class="dash-activity-time">' + timeLabel + '</span>' +
    '</button>';
  }

  function paintDashboardBarChart(el, rows, labelKey, valueKey) {
    if (!el) return;
    el.dataset.chartReady = '1';
    if (!rows || !rows.length) {
      el.innerHTML = '<p class="dash-chart-empty">No data available yet.</p>';
      return;
    }
    var max = Math.max.apply(null, rows.map(function (row) { return row[valueKey] || 0; }).concat([1]));
    el.innerHTML = rows.slice(0, 8).map(function (row, idx) {
      var val = row[valueKey] || 0;
      var pct = Math.round((val / max) * 100);
      var label = row[labelKey] || '—';
      return '<div class="mgr-bar-row dash-bar-row" title="' + escapeHtml(label) + ': ' + val + '">' +
        '<span class="mgr-bar-label" title="' + escapeHtml(label) + '">' + escapeHtml(label) + '</span>' +
        '<div class="mgr-bar-track"><div class="mgr-bar-fill' + (idx % 2 ? ' mgr-bar-fill-alt' : '') + '" style="width:' + pct + '%" data-value="' + val + '"></div></div>' +
        '<span class="mgr-bar-val">' + val + '</span></div>';
    }).join('');
  }

  function renderDashboardCharts(reports) {
    deferNonCriticalWork(function () {
      reports = reports || (window.dashboardMetrics && window.dashboardMetrics.reports) || {};
      setDashboardChartsLoading(false);
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
      monthlyEl.dataset.chartReady = '1';
      paintReportChart(monthlyEl, (reports.monthly_trends || []).map(function (row) {
        return { label: row.month, value: row.new_leads || 0 };
      }));
    }
    var employeeEl = document.querySelector('[data-chart="employee"]');
    if (employeeEl) {
      employeeEl.dataset.chartReady = '1';
      paintReportChart(employeeEl, (reports.employee_performance || []).slice(0, 8).map(function (row) {
        return { label: (row.employee_name || 'Exec').split(' ')[0], value: row.achievement_pct || 0 };
      }));
    }
    });
  }

  function renderRecentActivity() {
    var el = document.getElementById('recent-activity-list');
    if (!el) return;
    var preview = window.dashboardMetrics && window.dashboardMetrics.activity_preview;
    if (preview && preview.length) {
      el.innerHTML = preview.map(function (a) {
        return renderActivityFeedItem(a);
      }).join('');
      el.querySelectorAll('.dash-activity-item').forEach(function (btn, idx) {
        btn.addEventListener('click', function () {
          navigateActivityRecord(preview[idx]);
        });
      });
      icons();
      return;
    }
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

  function canViewSalesList() {
    return crmCanAction('sales_list', 'view');
  }

  function canEditSalesList() {
    return crmCanAction('sales_list', 'edit');
  }

  function canViewSalesListHistory() {
    return crmCanAction('sales_list', 'view') && (window.__CRM_USER__ || {}).role === 'super_admin';
  }

  function formatSalesCurrency(value) {
    var amount = Number(value || 0);
    return '₹' + amount.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  function salesPaymentBadge(status) {
    var tone = 'slate';
    if (status === 'Paid') tone = 'emerald';
    else if (status === 'Partial') tone = 'amber';
    else if (status === 'Overdue') tone = 'rose';
    else if (status === 'Pending') tone = 'blue';
    return '<span class="cam-cell-badge cam-cell-badge--' + tone + '">' + escapeHtml(status || '—') + '</span>';
  }

  function isAdminUser() {
    var u = window.__CRM_USER__ || {};
    return u.role === 'admin' || u.role === 'super_admin' || u.role === 'manager';
  }

  function releaseLeadLock() {
    var id = window._editingLeadId;
    if (!id || isAdminUser()) return Promise.resolve();
    return apiFetch('/ca-masters/' + encodeURIComponent(id) + '/lock', { method: 'DELETE' }).catch(function () {});
  }

  function releaseLeadLockBeacon() {
    var id = window._editingLeadId;
    if (!id || isAdminUser()) return;
    try {
      fetch('/ca-masters/' + encodeURIComponent(id) + '/lock', {
        method: 'DELETE',
        headers: {
          'X-CSRF-TOKEN': csrfToken(),
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        keepalive: true,
      });
    } catch (e) { /* ignore */ }
  }

  function setLeadLockBadge(lead) {
    var badge = document.getElementById('add-lead-lock-badge');
    if (!badge) return;
    var lock = (lead && lead.lock) || {};
    badge.classList.add('hidden');
    badge.textContent = '';
    if (lock.is_locked_by_me) {
      badge.textContent = '🟢 Editing';
      badge.className = 'ml-2 text-xs font-medium text-emerald-700';
      badge.classList.remove('hidden');
    } else if (lock.is_locked_by_other) {
      badge.textContent = '🔒 Locked by ' + (lock.locked_by_name || 'another employee');
      badge.className = 'ml-2 text-xs font-medium text-amber-700';
      badge.classList.remove('hidden');
    }
  }

  var leadDuplicateCheckTimer = null;
  window._leadDuplicateBlocked = false;

  function formatDuplicateDate(value) {
    if (!value) return '—';
    try {
      return new Date(value).toLocaleString();
    } catch (e) {
      return value;
    }
  }

  function renderLeadDuplicateWarning(duplicate, potential) {
    var box = document.getElementById('form-lead-duplicate-warning');
    var submitBtn = document.getElementById('add-lead-submit-btn');
    if (!box) return;

    if (potential && potential.existing_lead) {
      var pLead = potential.existing_lead;
      box.innerHTML =
        '<p class="font-semibold text-amber-700 mb-1">Potential Duplicate — similar number exists</p>' +
        '<p class="text-sm text-amber-800">Prefix matches existing lead <strong>' + escapeHtml(pLead.firm_name || pLead.ca_name || 'Lead') + '</strong> (' + escapeHtml(potential.existing_number || pLead.mobile_no || '—') + ').</p>' +
        '<p class="text-caption text-amber-700 mt-1">This attempt is logged for manager review. You may still save if the number is unique.</p>';
      box.classList.remove('hidden');
      window._leadDuplicateBlocked = false;
      if (submitBtn) submitBtn.disabled = false;
      return;
    }

    if (!duplicate || !duplicate.existing_lead) {
      box.classList.add('hidden');
      box.innerHTML = '';
      window._leadDuplicateBlocked = false;
      if (submitBtn) submitBtn.disabled = false;
      return;
    }

    var lead = duplicate.existing_lead;
    box.innerHTML =
      '<p class="font-semibold text-red-700 mb-1">' + escapeHtml(duplicate.title || 'Duplicate Number Found') + '</p>' +
      '<p class="text-sm text-red-800">This attempt has been logged for manager review.</p>' +
      '<p><strong>CA Name:</strong> ' + escapeHtml(lead.ca_name || '—') + '</p>' +
      '<p><strong>Firm Name:</strong> ' + escapeHtml(lead.firm_name || '—') + '</p>' +
      '<p><strong>Added by:</strong> ' + escapeHtml(lead.added_by || '—') + '</p>' +
      '<p><strong>Added on:</strong> ' + escapeHtml(formatDuplicateDate(lead.added_at)) + '</p>' +
      '<p><strong>Status:</strong> ' + escapeHtml(lead.status || '—') + '</p>' +
      '<p><strong>Assigned employee:</strong> ' + escapeHtml(lead.assigned_executive || '—') + '</p>';
    box.classList.remove('hidden');
    window._leadDuplicateBlocked = true;
    window._lastDuplicateAttemptId = duplicate.attempt_id || null;
    if (submitBtn) submitBtn.disabled = true;
  }

  function checkLeadMobileDuplicate(mobile, excludeCaId, fieldName) {
    var digits = normalizeLeadMobileDigits(mobile);
    if (!digits || digits.length < 8) {
      var box = document.getElementById('form-lead-duplicate-warning');
      if (box) {
        box.classList.add('hidden');
        box.innerHTML = '';
      }
      window._leadDuplicateBlocked = false;
      if (document.getElementById('add-lead-submit-btn')) {
        document.getElementById('add-lead-submit-btn').disabled = false;
      }
      return Promise.resolve(null);
    }

    var url = '/ca-masters/check-duplicate?mobile=' + encodeURIComponent(mobile) +
      '&field_name=' + encodeURIComponent(fieldName || 'mobile_no');
    if (excludeCaId) {
      url += '&exclude_ca_id=' + encodeURIComponent(excludeCaId);
    }

    return apiFetch(url).then(function (body) {
      var data = body.data || {};
      if (data.potential_duplicate) {
        renderLeadDuplicateWarning(null, data.potential_duplicate);
      } else {
        renderLeadDuplicateWarning(null);
        if (window._lastDuplicateAttemptId) {
          apiFetch('/duplicate-attempts/' + encodeURIComponent(window._lastDuplicateAttemptId) + '/mark-changed', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ final_number: mobile }),
          }).catch(function () {});
          window._lastDuplicateAttemptId = null;
        }
      }
      return null;
    }).catch(function (error) {
      if (error.status === 409 && error.duplicate) {
        renderLeadDuplicateWarning(error.duplicate);
        return error.duplicate;
      }
      return null;
    });
  }

  function scheduleLeadMobileDuplicateCheck(ev) {
    var form = document.getElementById('form-add-lead');
    if (!form) return;
    var target = ev && ev.target ? ev.target : form.elements.mobile_no;
    var fieldName = (target && target.name) ? target.name : 'mobile_no';
    var mobileInput = form.elements[fieldName] || form.elements.mobile_no;
    if (!mobileInput || mobileInput.readOnly || mobileInput.disabled) {
      renderLeadDuplicateWarning(null);
      return;
    }

    if (leadDuplicateCheckTimer) clearTimeout(leadDuplicateCheckTimer);
    leadDuplicateCheckTimer = setTimeout(function () {
      var excludeId = (document.getElementById('form-lead-ca-id') || {}).value || window._editingLeadId || '';
      checkLeadMobileDuplicate(mobileInput.value, excludeId || null, fieldName);
    }, 350);
  }

  function initLeadDuplicateChecks() {
    var form = document.getElementById('form-add-lead');
    if (!form || form.dataset.duplicateCheckBound === '1') return;
    form.dataset.duplicateCheckBound = '1';

    ['mobile_no', 'alternate_mobile_no'].forEach(function (fieldName) {
      var input = form.elements[fieldName];
      if (!input) return;
      input.addEventListener('input', scheduleLeadMobileDuplicateCheck);
      input.addEventListener('blur', scheduleLeadMobileDuplicateCheck);
    });
  }

  var LEAD_FORM_LOCKABLE_FIELDS = [
    'firm_name', 'ca_name', 'mobile_no', 'alternate_mobile_no', 'email_id', 'gst_no',
    'state_id', 'city_id', 'team_size', 'existing_software', 'website', 'rating',
    'is_newly_established', 'source_id', 'status', 'executive_id',
  ];

  var EMPLOYEE_ALWAYS_EDITABLE_FIELDS = [
    'alternate_mobile_no', 'rating', 'is_newly_established', 'status', 'source_id',
  ];

  function leadFieldLockTooltip(fieldName) {
    if (fieldName === 'mobile_no') {
      return 'Primary mobile cannot be changed once saved.';
    }
    return 'Locked by Admin';
  }

  function setLeadFieldLockState(el, locked, tooltip) {
    if (!el) return;
    var wrapper = el.closest('div');
    var label = wrapper ? wrapper.querySelector('label') : null;
    var existingIcon = wrapper ? wrapper.querySelector('[data-lead-lock-icon]') : null;
    var hint = null;
    if (el.name === 'mobile_no') {
      var formId = el.form && el.form.id;
      hint = document.getElementById(
        formId === 'form-lead-contact' ? 'form-lead-contact-mobile-hint' : 'form-lead-mobile-hint',
      );
    }

    if (locked) {
      var message = tooltip || leadFieldLockTooltip(el.name);
      if (el.tagName === 'SELECT') {
        el.disabled = true;
        el.readOnly = false;
      } else {
        el.readOnly = true;
        el.disabled = false;
      }
      el.classList.add('bg-slate-50', 'lead-field-locked');
      el.setAttribute('title', message);
      if (el.name === 'mobile_no' && hint) {
        hint.textContent = message;
        hint.classList.remove('hidden');
      }
      if (label && !existingIcon) {
        var icon = document.createElement('span');
        icon.setAttribute('data-lead-lock-icon', '1');
        icon.className = 'lead-field-lock-icon';
        icon.title = message;
        icon.innerHTML = '<i data-lucide="lock" class="h-3.5 w-3.5"></i>';
        label.appendChild(icon);
      } else if (existingIcon) {
        existingIcon.title = message;
      }
      return;
    }

    el.readOnly = false;
    el.disabled = false;
    el.classList.remove('bg-slate-50', 'lead-field-locked');
    el.removeAttribute('title');
    if (el.name === 'mobile_no' && hint) {
      hint.classList.add('hidden');
    }
    if (existingIcon) existingIcon.remove();
  }

  function clearLeadFormLockDecorations(form) {
    if (!form) return;
    LEAD_FORM_LOCKABLE_FIELDS.forEach(function (name) {
      setLeadFieldLockState(form.elements[name], false);
    });
    form.querySelectorAll('[data-lead-lock-icon]').forEach(function (icon) {
      icon.remove();
    });
    var locationPair = form.querySelector('.sc-location-pair');
    if (locationPair) {
      ['state_id', 'city_id'].forEach(function (name) {
        var select = form.elements[name];
        if (select) {
          select.disabled = false;
        }
      });
    }
    window._leadLockedFields = [];
    renderLeadDuplicateWarning(null);
  }

  function applyLeadFormAccessRules(lead) {
    var form = document.getElementById('form-add-lead');
    if (!form) return;
    var isEdit = !!(lead && lead.ca_id);
    var lock = (lead && lead.lock) || {};
    var lockedByOther = !!lock.is_locked_by_other;
    var submitBtn = document.getElementById('add-lead-submit-btn');
    var lockedFields = [];

    clearLeadFormLockDecorations(form);

    if (isEmployeeUser() && isEdit && lead.employee_locked_fields) {
      lockedFields = lead.employee_locked_fields.slice();
    } else if (isEmployeeUser() && isEdit) {
      LEAD_FORM_LOCKABLE_FIELDS.forEach(function (name) {
        if (EMPLOYEE_ALWAYS_EDITABLE_FIELDS.indexOf(name) >= 0) return;
        var value = lead[name];
        if (name === 'executive_id') {
          lockedFields.push(name);
          return;
        }
        if (value !== null && value !== undefined && String(value).trim() !== '' && value !== '—') {
          lockedFields.push(name);
        }
      });
    }

    if (isEmployeeUser() && isEdit && !lockedByOther) {
      lockedFields = lockedFields.filter(function (name) {
        return EMPLOYEE_ALWAYS_EDITABLE_FIELDS.indexOf(name) < 0;
      });
      if (lockedFields.indexOf('executive_id') < 0) {
        lockedFields.push('executive_id');
      }
    }

    window._leadLockedFields = lockedByOther
      ? LEAD_FORM_LOCKABLE_FIELDS.slice()
      : lockedFields.slice();

    LEAD_FORM_LOCKABLE_FIELDS.forEach(function (name) {
      var el = form.elements[name];
      if (!el) return;
      var isLocked = lockedByOther || lockedFields.indexOf(name) >= 0;
      if (EMPLOYEE_ALWAYS_EDITABLE_FIELDS.indexOf(name) >= 0 && isEmployeeUser() && isEdit) {
        isLocked = lockedByOther;
      } else if (name === 'executive_id' && isEmployeeUser() && isEdit) {
        isLocked = true;
      }
      setLeadFieldLockState(el, isLocked, leadFieldLockTooltip(name));
    });

    if (lockedByOther) {
      var locationPair = form.querySelector('.sc-location-pair');
      if (locationPair) {
        ['state_id', 'city_id'].forEach(function (name) {
          var select = form.elements[name];
          if (select) select.disabled = true;
        });
      }
    }

    if (submitBtn) {
      submitBtn.disabled = lockedByOther;
      submitBtn.classList.toggle('opacity-50', lockedByOther);
      submitBtn.classList.toggle('pointer-events-none', lockedByOther);
    }

    setLeadLockBadge(lead);
    scheduleLeadMobileDuplicateCheck();
    icons();
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

  function formatDashboardUpdatedAt(ts) {
    var when = ts ? new Date(ts) : new Date();
    return when.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
  }

  function parseDashboardMetricNumber(value) {
    if (value === undefined || value === null || value === '') return null;
    if (typeof value === 'number') return value;
    var cleaned = String(value).replace(/[^0-9.\-]/g, '');
    if (!cleaned) return null;
    var num = parseFloat(cleaned);
    return isNaN(num) ? null : num;
  }

  function inferKpiTrend(card, value) {
    var num = parseDashboardMetricNumber(value);
    if (num === null) return null;
    var negativeKeys = ['overdue_followups', 'demo_confirmation_rejected', 'demo_confirmation_rejected_after_reschedule', 'followups_overdue'];
    var isNegative = negativeKeys.indexOf(card.key) >= 0;
    if (isNegative) {
      if (num === 0) {
        return { dir: 'up', label: 'Clear', tone: 'success' };
      }
      return { dir: 'down', label: 'Needs attention', tone: 'danger' };
    }
    if (card.key === 'conversion' || card.key === 'conversion_pct' || card.key === 'demo_ratio') {
      var threshold = card.key === 'demo_ratio' ? 30 : 50;
      return {
        dir: num >= threshold ? 'up' : 'down',
        label: num >= threshold ? 'On track' : 'Below target',
        tone: num >= threshold ? 'success' : 'warning',
      };
    }
    if (num > 0) {
      return { dir: 'up', label: 'Active', tone: 'neutral' };
    }
    return { dir: 'down', label: 'None', tone: 'muted' };
  }

  function renderDashboardKpiCard(card, value, mode) {
    var display = value !== undefined && value !== null && value !== '' ? value : '—';
    if (card.suffix && display !== '—') display = display + card.suffix;
    var attrs = mode === 'employee'
      ? ' data-emp-nav="' + card.nav + '"' +
        (card.leadFilter ? ' data-emp-lead-filter="' + card.leadFilter + '"' : '') +
        (card.followupFilter ? ' data-emp-followup-filter="' + card.followupFilter + '"' : '')
      : ' data-nav-page="' + card.nav + '"' +
        (card.leadFilter ? ' data-lead-filter="' + card.leadFilter + '"' : '') +
        (card.followupFilter ? ' data-followup-filter="' + card.followupFilter + '"' : '');
    var trend = inferKpiTrend(card, value);
    var trendHtml = trend
      ? '<span class="mgr-kpi-trend ' + trend.dir + ' mgr-kpi-trend--' + trend.tone + '">' +
          '<i data-lucide="' + (trend.dir === 'up' ? 'trending-up' : 'trending-down') + '" class="h-3 w-3"></i>' +
          '<span>' + escapeHtml(trend.label) + '</span></span>'
      : '';
    var desc = card.desc ? '<p class="mgr-kpi-desc">' + escapeHtml(card.desc) + '</p>' : '';
    var updatedAt = window.__dashboardUpdatedAt || Date.now();
    var accent = card.accent || (card.key || 'default').replace(/_/g, '-');
    return '<button type="button" class="mgr-kpi-card dash-kpi-card dash-kpi-card--premium" data-accent="' + escapeHtml(accent) + '"' + attrs + ' data-kpi="' + escapeHtml(card.label) + '">' +
      '<div class="mgr-kpi-top">' +
        '<span class="mgr-kpi-icon"><i data-lucide="' + card.icon + '" class="h-4 w-4"></i></span>' +
        trendHtml +
      '</div>' +
      '<p class="mgr-kpi-value" data-metric="' + (card.key || '') + '">' + escapeHtml(String(display)) + '</p>' +
      '<p class="mgr-kpi-label">' + escapeHtml(card.label) + '</p>' +
      desc +
      '<div class="mgr-kpi-footer"><span class="mgr-kpi-updated">Updated ' + escapeHtml(formatDashboardUpdatedAt(updatedAt)) + '</span></div>' +
    '</button>';
  }

  function renderDashboardKpiSections(containerId, sections, resolveValue, mode) {
    var container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = sections.map(function (section) {
      return '<section class="dash-kpi-section" aria-label="' + escapeHtml(section.title) + '">' +
        '<h2 class="dash-kpi-section-title">' + escapeHtml(section.title) + '</h2>' +
        '<div class="mgr-kpi-grid dash-kpi-grid dash-kpi-section-grid">' +
        section.cards.map(function (card) {
          return renderDashboardKpiCard(card, resolveValue(card), mode);
        }).join('') +
        '</div></section>';
    }).join('');
  }

  var ADMIN_DASHBOARD_KPI_SECTIONS = [
    {
      title: 'Leads',
      cards: [
        { icon: 'users', label: 'Total Leads', key: 'total_leads', nav: 'ca-master', leadFilter: 'all', desc: 'All firms in master data' },
        { icon: 'sparkles', label: 'New Leads', key: 'new_status_leads', nav: 'ca-master', leadFilter: 'new', desc: 'Leads with New status' },
        { icon: 'flame', label: 'Hot Leads', key: 'hot_leads', nav: 'ca-master', leadFilter: 'hot', desc: 'High-intent prospects' },
        { icon: 'thermometer', label: 'Warm Leads', key: 'warm_leads', nav: 'ca-master', leadFilter: 'pipeline', desc: 'In active pipeline' },
        { icon: 'snowflake', label: 'Cold Leads', key: 'cold_leads', nav: 'ca-master', leadFilter: 'cold', desc: 'Low engagement leads' },
        { icon: 'trending-up', label: 'Conversion Rate', key: 'conversion', nav: 'analytics', desc: 'Lead-to-sale conversion' },
      ],
    },
    {
      title: 'Daily Work',
      cards: [
        { icon: 'phone', label: "Today's Calls", key: 'calls_total', nav: 'followups', desc: 'Scheduled calls today' },
        { icon: 'calendar-clock', label: "Today's Follow-ups", key: 'followups_due_today', nav: 'followups', followupFilter: 'today', desc: 'Due for follow-up today' },
        { icon: 'video', label: "Today's Meetings", key: 'meetings_today', nav: 'followups', desc: 'Demos and meetings' },
        { icon: 'alert-circle', label: 'Overdue Follow-ups', key: 'overdue_followups', nav: 'followups', followupFilter: 'overdue', desc: 'Past due actions' },
      ],
    },
    {
      title: 'Demo',
      cards: [
        { icon: 'percent', label: 'Demo Ratio', key: 'demo_ratio', nav: 'analytics', desc: 'Demo completion rate' },
        { icon: 'clock', label: 'Pending Confirmation', key: 'demo_confirmation_pending', nav: 'followups', desc: 'Awaiting client response' },
        { icon: 'badge-check', label: 'Confirmed', key: 'demo_confirmation_confirmed', nav: 'followups', desc: 'Client confirmed demos' },
        { icon: 'badge-x', label: 'Rejected', key: 'demo_confirmation_rejected', nav: 'followups', desc: 'Declined demo requests' },
        { icon: 'calendar-clock', label: 'Rescheduled', key: 'demo_confirmation_rescheduled', nav: 'followups', desc: 'Moved to new slot' },
        { icon: 'shield-alert', label: 'Rejected After Reschedule', key: 'demo_confirmation_rejected_after_reschedule', nav: 'followups', desc: 'Cancelled after reschedule' },
      ],
    },
    {
      title: 'Performance',
      cards: [
        { icon: 'user-check', label: 'Employees', key: 'active_employees', nav: 'employees', desc: 'Active team members' },
        { icon: 'git-branch', label: 'Assignments', key: 'assignments', nav: 'assignment', desc: 'Leads assigned to team' },
      ],
    },
  ];

  var EMPLOYEE_DASHBOARD_KPI_SECTIONS = [
    {
      title: 'Leads',
      cards: [
        { icon: 'users', label: 'My Leads', key: 'my_leads', nav: 'leads', source: 'summary', desc: 'Total assigned to you' },
        { icon: 'user-check', label: 'Assigned Leads', key: 'assigned_leads_today', nav: 'leads', source: 'today', desc: 'New assignments today' },
        { icon: 'flame', label: 'Hot Leads', key: 'hot_leads', nav: 'leads', leadFilter: 'hot', source: 'summary', desc: 'High priority prospects' },
        { icon: 'thermometer', label: 'Warm Leads', key: 'warm_leads', nav: 'leads', leadFilter: 'pipeline', source: 'summary', desc: 'In your pipeline' },
        { icon: 'snowflake', label: 'Cold Leads', key: 'cold_leads', nav: 'leads', leadFilter: 'cold', source: 'summary', desc: 'Needs re-engagement' },
        { icon: 'trending-up', label: 'Conversion Rate', key: 'conversion_pct', nav: 'leads', suffix: '%', source: 'summary', desc: 'Your conversion rate' },
      ],
    },
    {
      title: 'Daily Work',
      cards: [
        { icon: 'phone', label: "Today's Calls", key: 'todays_calls', nav: 'followups', source: 'summary', desc: 'Calls scheduled today' },
        { icon: 'calendar-clock', label: "Today's Follow-ups", key: 'followups_due', nav: 'followups', followupFilter: 'today', source: 'today', desc: 'Due today' },
        { icon: 'video', label: "Today's Meetings", key: 'meetings_today', nav: 'followups', source: 'today', desc: 'Demos and meetings' },
        { icon: 'list-checks', label: "Today's Tasks", key: 'todays_tasks', nav: 'followups', source: 'summary', desc: 'Tasks for today' },
        { icon: 'alert-circle', label: 'Overdue Follow-ups', key: 'followups_overdue', nav: 'followups', followupFilter: 'overdue', source: 'today', desc: 'Past due items' },
        { icon: 'calendar-clock', label: 'My Follow-ups', key: 'my_followups', nav: 'followups', source: 'summary', desc: 'All your follow-ups' },
        { icon: 'clock', label: 'Upcoming Tasks', key: 'upcoming_tasks', nav: 'followups', source: 'today', desc: 'Scheduled ahead' },
      ],
    },
    {
      title: 'Demo',
      cards: [
        { icon: 'presentation', label: "Today's Demos", key: 'my_demos', nav: 'followups', source: 'summary', desc: 'Demos scheduled today' },
        { icon: 'video', label: 'My Meetings', key: 'my_meetings', nav: 'followups', source: 'summary', desc: 'All your meetings' },
      ],
    },
    {
      title: 'Performance',
      cards: [
        { icon: 'award', label: 'Achievement', key: 'todays_achievement', nav: 'leads', source: 'summary', desc: 'Today\'s progress' },
        { icon: 'target', label: 'Target', key: 'todays_target', nav: 'leads', source: 'summary', desc: 'Daily target' },
      ],
    },
  ];

  function renderEmployeeDashboard() {
    var cached = readDashboardMetricsCache();
    var painted = false;
    if (cached && cached.data) {
      employeeDashboardData = cached.data;
      employeeDashboardLoaded = true;
      paintEmployeeDashboard(cached.data);
      painted = true;
    } else if (employeeDashboardData) {
      paintEmployeeDashboard(employeeDashboardData);
      painted = true;
    } else {
      paintEmployeeDashboardInstantShell();
    }

    loadDashboardMetricsFromDatabase(function (data, error, meta) {
      if (meta && meta.fromCache && painted && !meta.background) return;
      if (error && !employeeDashboardData && !cached) {
        toast('Unable to load your dashboard', 'error');
        return;
      }
      if (!employeeDashboardData) return;
      paintEmployeeDashboard(employeeDashboardData);
      if (window.CA_RBAC && typeof CA_RBAC.enforce === 'function') CA_RBAC.enforce();
      icons();
    });
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
      var roleBadge = crmUser.role_label || 'Employee';
      top.innerHTML =
        '<div class="mgr-top-left">' +
          '<span class="manager-role-badge"><i data-lucide="briefcase" class="h-3.5 w-3.5"></i> ' + escapeHtml(roleBadge) + '</span>' +
          '<h1 class="text-page-title mgr-greeting">' + escapeHtml(dashboardGreetingText(greeting, welcome.name || crmUser.name, roleBadge)) + '</h1>' +
          '<p class="mgr-top-meta">' + escapeHtml(dateStr) + ' · ' + escapeHtml(timeStr) + ' · ' + escapeHtml(welcome.working_status || 'On track') + '</p>' +
        '</div>';
    }

    renderDashboardKpiSections('emp-kpi-sections', EMPLOYEE_DASHBOARD_KPI_SECTIONS, function (card) {
      return card.source === 'today' ? todayWork[card.key] : summary[card.key];
    }, 'employee');

    renderEmployeeAssignedLeads(data.assigned_leads || []);
    renderEmployeeFollowups(data.followups || {});
    renderEmployeeActivity(data.recent_activity || []);
    renderEmployeeQuickActions();
    renderEmployeeProductivityPanel(data.productivity || {});
    renderEmployeeDailyTargetCard(data.daily_target || {}, data.daily_target_history || []);
    initEmployeeDashboardInteractions();
    loadEmployeeWorkflowLists();
    icons();
  }

  function renderEmployeeProductivityPanel(productivity) {
    var el = document.getElementById('emp-productivity-panel');
    if (!el) return;
    el.innerHTML =
      '<div class="flex items-center justify-between gap-3 mb-3">' +
        '<h2 class="text-card-heading">Today\'s Productivity</h2>' +
      '</div>' +
      '<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">' +
        productivityStatCard('Assigned Today', productivity.leads_assigned) +
        productivityStatCard('Unique Leads', productivity.unique_leads) +
        productivityStatCard('Duplicate Attempts', productivity.duplicate_attempts) +
        productivityStatCard('Wrong Numbers', productivity.wrong_numbers) +
        productivityStatCard('Verified Leads', productivity.verified_leads) +
        productivityStatCard('Follow-ups Done', productivity.followups_completed) +
        productivityStatCard('Quality Score', productivity.quality_score) +
        productivityStatCard('Rank', productivity.rank ? '#' + productivity.rank : '—') +
      '</div>';
  }

  function productivityStatCard(label, value) {
    return '<div class="rounded-xl border border-slate-100 bg-slate-50 p-3">' +
      '<p class="text-caption text-slate-500">' + escapeHtml(label) + '</p>' +
      '<p class="text-lg font-semibold text-slate-900 mt-1">' + escapeHtml(String(value ?? '—')) + '</p>' +
    '</div>';
  }

  function productivityInitials(name) {
    return String(name || 'E').trim().split(/\s+/).map(function (part) { return part.charAt(0); }).join('').slice(0, 2).toUpperCase();
  }

  function productivityRankTone(rank, invertTone) {
    if (invertTone) {
      if (rank === 1) return 'needs';
      if (rank === 2) return 'average';
      return 'good';
    }
    if (rank === 1) return 'excellent';
    if (rank === 2) return 'good';
    if (rank === 3) return 'average';
    return 'average';
  }

  function productivityValueTone(value, valueKey) {
    var num = Number(value) || 0;
    if (valueKey === 'followup_completion_pct') {
      if (num >= 90) return 'excellent';
      if (num >= 75) return 'good';
      if (num >= 55) return 'average';
      return 'needs';
    }
    if (valueKey === 'quality_score') {
      if (num >= 80) return 'excellent';
      if (num >= 65) return 'good';
      if (num >= 45) return 'average';
      return 'needs';
    }
    if (valueKey === 'duplicate_attempts') {
      if (num <= 1) return 'excellent';
      if (num <= 3) return 'good';
      if (num <= 6) return 'average';
      return 'needs';
    }
    if (num >= 8) return 'excellent';
    if (num >= 5) return 'good';
    if (num >= 2) return 'average';
    return 'needs';
  }

  function productivityFormatValue(value, valueKey) {
    var num = Number(value) || 0;
    if (valueKey === 'followup_completion_pct') return num.toFixed(1) + '%';
    if (valueKey === 'quality_score') return String(Math.round(num));
    return String(num);
  }

  function productivityProgressWidth(value, valueKey, maxValue) {
    var num = Number(value) || 0;
    if (valueKey === 'followup_completion_pct') return Math.max(6, Math.min(100, num));
    if (valueKey === 'quality_score') return Math.max(6, Math.min(100, num));
    var max = Math.max(1, Number(maxValue) || 1);
    return Math.max(6, Math.min(100, (num / max) * 100));
  }

  function productivityUpdatedLabel(dateValue) {
    if (dateValue) {
      try {
        return 'Updated ' + formatDate(dateValue);
      } catch (e) { /* fall through */ }
    }
    return 'Updated ' + formatDateTime(new Date().toISOString());
  }

  function productivityKpiCardHtml(config, rows, updatedLabel) {
    var valueKey = config.valueKey;
    var list = Array.isArray(rows) ? rows.slice(0, 5) : [];
    var maxValue = list.reduce(function (max, row) {
      return Math.max(max, Number(row[valueKey]) || 0);
    }, 0);

    var bodyHtml = '';
    if (!list.length) {
      bodyHtml =
        '<div class="mgr-prod-kpi__empty">' +
          '<span class="mgr-prod-kpi__empty-icon"><i data-lucide="bar-chart-2" class="h-5 w-5"></i></span>' +
          '<p>No productivity data available.</p>' +
        '</div>';
    } else {
      bodyHtml = '<ul class="mgr-prod-kpi__list">' + list.map(function (row, index) {
        var rank = index + 1;
        var rawValue = row[valueKey] ?? 0;
        var rankTone = productivityRankTone(rank, config.invertTone);
        var valueTone = config.invertTone && rank === 1
          ? 'needs'
          : productivityValueTone(rawValue, valueKey);
        var progress = productivityProgressWidth(rawValue, valueKey, maxValue);
        var animateValue = valueKey === 'followup_completion_pct'
          ? Number(rawValue).toFixed(1)
          : String(Math.round(Number(rawValue) || 0));
        var suffix = valueKey === 'followup_completion_pct' ? '%' : '';
        return '<li class="mgr-prod-kpi__row" data-prod-employee-id="' + escapeHtml(String(row.employee_id || '')) + '" title="View employee dashboard">' +
          '<span class="mgr-prod-kpi__rank mgr-prod-kpi__rank--' + rankTone + '">#' + rank + '</span>' +
          '<span class="mgr-prod-kpi__avatar" aria-hidden="true">' + escapeHtml(productivityInitials(row.employee_name)) + '</span>' +
          '<div class="mgr-prod-kpi__meta">' +
            '<span class="mgr-prod-kpi__name">' + escapeHtml(row.employee_name || 'Employee') + '</span>' +
            '<div class="mgr-prod-kpi__metric">' +
              '<div class="mgr-prod-kpi__bar" aria-hidden="true">' +
                '<span class="mgr-prod-kpi__bar-fill mgr-prod-kpi__bar-fill--' + valueTone + '" style="width:' + progress + '%"></span>' +
              '</div>' +
              '<span class="mgr-prod-kpi__value mgr-prod-kpi__value--' + valueTone + '" data-prod-animate="' + escapeHtml(animateValue) + '" data-prod-suffix="' + suffix + '">0' + suffix + '</span>' +
            '</div>' +
          '</div>' +
        '</li>';
      }).join('') + '</ul>';
    }

    return '<article class="mgr-prod-kpi mgr-prod-kpi--' + config.tone + ' mgr-prod-kpi--enter" style="--prod-i:' + config.index + '">' +
      '<header class="mgr-prod-kpi__head">' +
        '<div class="mgr-prod-kpi__title-row">' +
          '<span class="mgr-prod-kpi__icon mgr-prod-kpi__icon--' + config.tone + '"><i data-lucide="' + config.icon + '" class="h-4 w-4"></i></span>' +
          '<h3 class="mgr-prod-kpi__title">' + escapeHtml(config.title) + '</h3>' +
        '</div>' +
        '<time class="mgr-prod-kpi__updated" datetime="' + escapeHtml(config.dateValue || '') + '">' + escapeHtml(updatedLabel) + '</time>' +
      '</header>' +
      '<div class="mgr-prod-kpi__body">' + bodyHtml + '</div>' +
      '<footer class="mgr-prod-kpi__foot">' +
        '<button type="button" class="mgr-prod-kpi__details" data-prod-details="' + escapeHtml(config.detailsSlug) + '">' +
          'View Details <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>' +
        '</button>' +
      '</footer>' +
    '</article>';
  }

  function animateProductivityValues(root) {
    if (!root) return;
    root.querySelectorAll('[data-prod-animate]').forEach(function (el) {
      var target = parseFloat(el.getAttribute('data-prod-animate')) || 0;
      var suffix = el.getAttribute('data-prod-suffix') || '';
      var decimals = suffix === '%' ? 1 : 0;
      var startTime = null;
      var duration = 650;
      function step(ts) {
        if (!startTime) startTime = ts;
        var progress = Math.min((ts - startTime) / duration, 1);
        var eased = 1 - Math.pow(1 - progress, 3);
        var current = target * eased;
        el.textContent = (decimals ? current.toFixed(decimals) : String(Math.round(current))) + suffix;
        if (progress < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    });
  }

  function bindProductivityPanelInteractions(root) {
    if (!root || root._productivityBound) return;
    root._productivityBound = true;

    root.addEventListener('click', function (e) {
      var detailsBtn = e.target.closest('[data-prod-details]');
      if (detailsBtn) {
        var slug = detailsBtn.getAttribute('data-prod-details');
        if (slug === 'duplicate-attempts') {
          if (typeof navigateTo === 'function') navigateTo('duplicate-attempts');
          else toast('Open Duplicate Attempts to review.', 'info');
          return;
        }
        if (typeof openReport === 'function') openReport(slug);
        else if (typeof navigateTo === 'function') navigateTo('reports');
        return;
      }

      var row = e.target.closest('[data-prod-employee-id]');
      if (!row) return;
      var employeeId = parseInt(row.getAttribute('data-prod-employee-id'), 10);
      if (!employeeId) return;
      applyManagerEmployeeFilter(employeeId);
      toast('Dashboard filtered to selected employee', 'info');
    });
  }

  function resolveManagerProductivity(metrics) {
    metrics = metrics || window.dashboardMetrics || {};
    var productivity = metrics.productivity;
    if (productivity && typeof productivity === 'object') {
      return productivity;
    }
    return null;
  }

  function renderManagerProductivityPanel(productivity) {
    var el = document.getElementById('mgr-productivity-panel');
    if (!el) return;

    el.classList.remove('hidden');

    productivity = productivity || resolveManagerProductivity(window.dashboardMetrics);

    if (!productivity) {
      el.innerHTML =
        '<section class="mgr-productivity-hub mgr-productivity-hub--empty">' +
          '<div class="mgr-prod-kpi__empty mgr-prod-kpi__empty--hub">' +
            '<span class="mgr-prod-kpi__empty-icon"><i data-lucide="bar-chart-2" class="h-6 w-6"></i></span>' +
            '<h3 class="mgr-productivity-hub__title">Lead Collection Productivity</h3>' +
            '<p>No productivity data available.</p>' +
          '</div>' +
        '</section>';
      icons();
      return;
    }

    var updatedLabel = productivityUpdatedLabel(productivity.date);
    var kpiDefs = [
      { index: 0, key: 'top_performers', title: 'Top Performers', icon: 'trophy', tone: 'excellent', valueKey: 'quality_score', detailsSlug: 'duplicate_productivity', invertTone: false },
      { index: 1, key: 'most_verified', title: 'Most Verified Leads', icon: 'badge-check', tone: 'good', valueKey: 'verified_leads', detailsSlug: 'duplicate_productivity', invertTone: false, dataKey: 'most_verified_leads' },
      { index: 2, key: 'best_followup', title: 'Best Follow-up Rate', icon: 'phone-call', tone: 'good', valueKey: 'followup_completion_pct', detailsSlug: 'followup_performance', invertTone: false, dataKey: 'best_followup_rate' },
      { index: 3, key: 'most_duplicates', title: 'Most Duplicate Attempts', icon: 'alert-triangle', tone: 'average', valueKey: 'duplicate_attempts', detailsSlug: 'duplicate-attempts', invertTone: true, dataKey: 'most_duplicate_attempts' },
      { index: 4, key: 'lowest_quality', title: 'Lowest Quality Score', icon: 'trending-down', tone: 'needs', valueKey: 'quality_score', detailsSlug: 'duplicate_productivity', invertTone: true, dataKey: 'lowest_quality_score' },
    ];

    var cardsHtml = kpiDefs.map(function (def) {
      var rows = productivity[def.dataKey || def.key];
      if (def.key === 'lowest_quality' && (!rows || !rows.length)) {
        rows = productivity.lowest_productivity;
      }
      return productivityKpiCardHtml(Object.assign({}, def, { dateValue: productivity.date || '' }), rows, updatedLabel);
    }).join('');

    el.innerHTML =
      '<section class="mgr-productivity-hub">' +
        '<header class="mgr-productivity-hub__head">' +
          '<div class="mgr-productivity-hub__intro">' +
            '<h2 class="mgr-productivity-hub__title">Lead Collection Productivity</h2>' +
            '<p class="mgr-productivity-hub__sub">Employee rankings for today · Org duplicate rate <strong>' + escapeHtml(String(productivity.duplicate_percentage || 0)) + '%</strong></p>' +
          '</div>' +
          '<span class="mgr-productivity-hub__stamp"><i data-lucide="refresh-cw" class="h-3.5 w-3.5"></i> ' + escapeHtml(updatedLabel) + '</span>' +
        '</header>' +
        '<div class="mgr-productivity-hub__grid">' + cardsHtml + '</div>' +
      '</section>';

    bindProductivityPanelInteractions(el);
    animateProductivityValues(el);
    icons();
  }

  function renderEmployeeAssignedLeads(leads) {
    var el = document.getElementById('emp-assigned-leads');
    if (!el) return;
    if (!leads.length) {
      el.innerHTML = '<p class="text-caption text-slate-400 p-3">No assigned leads yet.</p>';
      return;
    }
    el.innerHTML = leads.map(function (lead) {
      return '<div class="emp-list-item">' +
        '<button type="button" class="emp-list-main text-left" data-emp-open-lead="' + escapeHtml(lead.ca_id) + '">' +
          '<strong>' + escapeHtml(lead.firm_name) + '</strong>' +
          '<span class="text-caption text-slate-500">' + escapeHtml(lead.status) + '</span></button>' +
        '<span class="emp-list-meta flex flex-col items-end gap-1">' +
          '<span>P' + (lead.priority_score || 1) + ' · ' + escapeHtml(formatDate(lead.assigned_date)) + '</span>' +
          '<span class="flex gap-1">' +
            iconBtn('phone', 'Call', 'data-emp-call-lead="' + escapeHtml(lead.ca_id) + '"', 'secondary') +
            iconBtn('video', 'Demo', 'data-emp-schedule-demo="' + escapeHtml(lead.ca_id) + '"', 'secondary') +
          '</span></span></div>';
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
      { label: 'Call / Mark Called', icon: 'phone', action: 'call' },
      { label: 'Schedule Demo', icon: 'presentation', action: 'schedule-demo' },
      { label: 'Update Demo Result', icon: 'clipboard-check', action: 'demo-result' },
      { label: 'Open My Leads', icon: 'users', nav: 'leads' },
      { label: 'Today Follow-ups', icon: 'calendar-clock', nav: 'followups' },
    ];
    el.innerHTML = actions.map(function (action) {
      if (action.action) {
        return '<button type="button" class="emp-quick-btn dash-quick-action-btn" data-workflow-action="' + action.action + '">' +
          '<i data-lucide="' + action.icon + '" class="h-4 w-4"></i><span>' + action.label + '</span></button>';
      }
      if (action.modal) {
        return '<button type="button" class="emp-quick-btn dash-quick-action-btn" data-open-modal="' + action.modal + '">' +
          '<i data-lucide="' + action.icon + '" class="h-4 w-4"></i><span>' + action.label + '</span></button>';
      }
      return '<button type="button" class="emp-quick-btn dash-quick-action-btn" data-emp-nav="' + action.nav + '">' +
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
      var callLeadBtn = e.target.closest('[data-emp-call-lead]');
      if (callLeadBtn) {
        openCallOutcomeModal(null, callLeadBtn.dataset.empCallLead);
        return;
      }
      var scheduleDemoBtn = e.target.closest('[data-emp-schedule-demo]');
      if (scheduleDemoBtn) {
        openScheduleDemoModal(scheduleDemoBtn.dataset.empScheduleDemo);
        return;
      }
      if (e.target.closest('[data-emp-open-lead]')) {
        var leadBtn = e.target.closest('[data-emp-open-lead]');
        var leadId = leadBtn && leadBtn.getAttribute('data-emp-open-lead');
        if (leadId) {
          window._pendingOpenLeadId = leadId;
          if (typeof navigateTo === 'function') navigateTo(isEmployeeUser() ? 'leads' : 'ca-master');
        }
        return;
      }
      if (e.target.closest('[data-emp-open-followup]')) {
        if (typeof navigateTo === 'function') navigateTo('followups');
        return;
      }
      var wfBtn = e.target.closest('[data-workflow-action]');
      if (wfBtn) {
        handleWorkflowQuickAction(wfBtn.dataset.workflowAction);
        return;
      }
      var demoResultBtn = e.target.closest('[data-emp-demo-result]');
      if (demoResultBtn) {
        openDemoResultModal(demoResultBtn.dataset.empDemoResult);
      }
    });
    bindModalTriggers(root);
  }

  function renderManagerDashboard() {
    var filterEmployeeId = getDashboardEmployeeFilterId();
    var dateFilter = getDashboardDateFilter();
    var isDefaultFilters = !filterEmployeeId && (!dateFilter.preset || dateFilter.preset === 'today');
    var cached = isDefaultFilters ? readDashboardMetricsCache() : null;
    var painted = false;

    if (cached && cached.data) {
      window.dashboardMetrics = cached.data;
      dashboardMetricsLoaded = true;
      paintManagerDashboard(buildDashboardDisplayMetrics(cached.data));
      renderDashboardCharts(cached.data.reports);
      painted = true;
    } else if (window.dashboardMetrics && isDefaultFilters) {
      paintManagerDashboard(buildDashboardDisplayMetrics(window.dashboardMetrics));
      renderDashboardCharts(window.dashboardMetrics.reports);
      painted = true;
    } else if (!isDefaultFilters) {
      paintDashboardInstantShell(true);
    } else {
      paintDashboardInstantShell(false);
    }

    loadDashboardMetricsFromDatabase(function onManagerDashboardMetrics(metrics, error, meta) {
      if (meta && meta.background) return;
      if (meta && meta.fromCache && painted) return;
      if (error && !window.dashboardMetrics) {
        if (recoverDashboardEmployeeFilterError(error, onManagerDashboardMetrics, { force: true, employeeId: null, dateFilter: dateFilter })) {
          return;
        }
        if (!painted) {
          showDashboardLoadError(error.message || 'Unable to load dashboard metrics.');
        }
        return;
      }
      if (!metrics && !window.dashboardMetrics) return;
      if (metrics) window.dashboardMetrics = metrics;
      paintManagerDashboard(buildDashboardDisplayMetrics(window.dashboardMetrics));
      renderDashboardCharts(window.dashboardMetrics.reports);
      if (window.CA_RBAC && typeof CA_RBAC.enforce === 'function') CA_RBAC.enforce();
    }, { force: !isDefaultFilters, employeeId: filterEmployeeId || null, dateFilter: dateFilter });
  }

  function preloadDashboardMetrics() {
    if (!window.__CRM_USER__ || !window.__CRM_USER__.authenticated) return;
    loadDashboardMetricsFromDatabase(null);
  }

  function deferNonCriticalWork(fn) {
    if (typeof fn !== 'function') return;
    if (typeof window.requestIdleCallback === 'function') {
      window.requestIdleCallback(fn, { timeout: 2000 });
      return;
    }
    setTimeout(fn, 0);
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

  function managerDashboardFiltersHtml() {
    if (isEmployeeUser()) return '';
    var dateOptions = [
      ['today', 'Today'], ['yesterday', 'Yesterday'], ['last_7_days', 'Last 7 Days'],
      ['last_15_days', 'Last 15 Days'], ['last_30_days', 'Last 30 Days'],
      ['this_week', 'This Week'], ['last_week', 'Last Week'],
      ['this_month', 'This Month'], ['last_month', 'Last Month'],
      ['last_quarter', 'Last Quarter'], ['last_half_year', 'Last Half Year'],
      ['this_year', 'This Year'], ['last_year', 'Last Year'], ['custom', 'Custom Range'],
    ];
    return '<div class="mgr-top-filters" id="mgr-employee-filter">' +
      '<div class="mgr-dash-filters__controls">' +
        '<div class="mgr-employee-combobox mgr-filter-sm" id="mgr-employee-combobox">' +
          '<div class="mgr-employee-combobox__control">' +
            '<button type="button" class="mgr-employee-combobox__trigger" id="mgr-employee-trigger" aria-haspopup="listbox" aria-expanded="false">' +
              '<span class="mgr-employee-avatar" id="mgr-employee-avatar">ALL</span>' +
              '<span class="mgr-employee-combobox__text" id="mgr-employee-selected-label">All Employees</span>' +
              '<i data-lucide="chevron-down" class="mgr-employee-combobox__chevron h-4 w-4 text-slate-400"></i>' +
            '</button>' +
            '<button type="button" class="mgr-employee-clear-btn hidden" id="mgr-employee-clear" title="Clear Employee Filter" aria-label="Clear Employee Filter">' +
              '<i data-lucide="x" class="h-4 w-4"></i><span class="mgr-employee-clear-btn__fallback" aria-hidden="true">×</span>' +
            '</button>' +
          '</div>' +
          '<div class="mgr-employee-combobox__panel hidden" id="mgr-employee-panel" role="listbox">' +
            '<div class="mgr-employee-combobox__search">' +
              '<i data-lucide="search" class="h-4 w-4 text-slate-400"></i>' +
              '<input type="search" id="mgr-employee-search" class="mgr-employee-combobox__input" placeholder="Search employee…" autocomplete="off" />' +
            '</div>' +
            '<div class="mgr-employee-combobox__list" id="mgr-employee-options"></div>' +
          '</div>' +
        '</div>' +
        '<div class="mgr-date-combobox mgr-filter-sm" id="mgr-date-combobox">' +
          '<button type="button" class="mgr-employee-combobox__trigger" id="mgr-date-trigger" aria-haspopup="listbox" aria-expanded="false">' +
            '<span class="mgr-employee-avatar mgr-employee-avatar--date"><i data-lucide="calendar" class="h-3 w-3"></i></span>' +
            '<span class="mgr-employee-combobox__text" id="mgr-date-selected-label">Today</span>' +
            '<i data-lucide="chevron-down" class="h-3.5 w-3.5 text-slate-400"></i>' +
          '</button>' +
          '<div class="mgr-employee-combobox__panel hidden" id="mgr-date-panel" role="listbox">' +
            '<div class="mgr-date-options" id="mgr-date-options">' +
              dateOptions.map(function (opt) {
                return '<button type="button" class="mgr-employee-option" data-date-preset="' + opt[0] + '">' +
                  '<span class="mgr-employee-option__name">' + opt[1] + '</span></button>';
              }).join('') +
            '</div>' +
            '<div class="mgr-date-custom hidden" id="mgr-date-custom">' +
              '<div class="mgr-date-custom__fields">' +
                '<label class="mgr-date-custom__field"><span>From</span><input type="date" id="mgr-date-from" class="input-field" data-crm-date-input data-allow-past data-hide-preview /></label>' +
                '<label class="mgr-date-custom__field"><span>To</span><input type="date" id="mgr-date-to" class="input-field" data-crm-date-input data-allow-past data-hide-preview /></label>' +
              '</div>' +
              '<div class="mgr-date-custom__actions">' +
                iconBtn('rotate-ccw', 'Reset', 'id="mgr-date-reset"', 'secondary') +
                iconBtn('filter', 'Apply', 'id="mgr-date-apply"', 'primary') +
              '</div>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div class="mgr-dash-filters__loading hidden" id="mgr-dash-filter-loading">' +
          '<span class="mgr-dash-spinner"></span><span>Updating…</span>' +
        '</div>' +
      '</div>' +
      '<p class="mgr-top-filters__hint" id="mgr-employee-filter-hint">All Employees · Today</p>' +
    '</div>';
  }

  function paintManagerDashboard(m) {
    var crmUser = window.__CRM_USER__ || {};
    var displayName = crmUser.name || 'User';
    var roleLabel = crmUser.role_label || crmUser.role || 'User';
    var now = new Date();
    var greeting = now.getHours() < 12 ? 'Good morning' : now.getHours() < 17 ? 'Good afternoon' : 'Good evening';
    var dateStr = now.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    var greetingLine = dashboardGreetingText(greeting, displayName, roleLabel);
    var metrics = window.dashboardMetrics || {};
    var employeeFilter = metrics.employee_productivity || null;

    var top = document.getElementById('mgr-top-header');
    if (top) {
      top.innerHTML =
        '<div class="mgr-top-left">' +
          '<span class="manager-role-badge"><i data-lucide="layout-dashboard" class="h-3.5 w-3.5"></i> ' + escapeHtml(roleLabel) + '</span>' +
          '<h1 class="text-page-title mgr-greeting">' + escapeHtml(greetingLine) + '</h1>' +
          '<p class="mgr-top-meta">' + escapeHtml(dateStr) + ' · ' + m.followups_due_today + ' follow-ups today</p>' +
        '</div>' +
        managerDashboardFiltersHtml();
    }

    ensureManagerEmployeeFilter();
    renderManagerEmployeeProductivityPanel(employeeFilter);

    renderDashboardKpiSections('mgr-kpi-sections', ADMIN_DASHBOARD_KPI_SECTIONS, function (card) {
      return m[card.key];
    }, 'admin');

    if (getDashboardEmployeeFilterId()) {
      renderManagerProductivityPanel(resolveManagerProductivity(metrics));
    } else {
      var prodSlot = document.getElementById('mgr-productivity-panel');
      if (prodSlot) {
        prodSlot.innerHTML = '';
        prodSlot.classList.add('hidden');
      }
    }
    renderDashboardFilterChips(m);
    renderDashboardQuickActions();
    renderSmsDashboardWidgets(m);
    renderDuplicateMonitoringPanel(m.duplicate_monitoring || metrics.duplicate_monitoring);
    renderDashboardCharts(metrics.reports);
    renderPipelineFunnel();
    renderPriorityList();
    renderTeamCards(getDashboardExecutives());
    renderTeamOverview(getDashboardExecutives());
    renderDashboardLeads();
    if (metrics.activity_preview && metrics.activity_preview.length) {
      renderRecentActivity();
    }
    initDashboardInteractions(top);
    deferNonCriticalWork(function () {
      loadManagerFollowUpMetrics();
      loadManagerWorkflowLists();
      if (!metrics.activity_preview || !metrics.activity_preview.length) {
        renderRecentActivity();
      }
    });
    icons();
  }

  var managerEmployeeFilterState = {
    employees: [],
    loaded: false,
    open: false,
    query: '',
  };

  function ensureManagerEmployeeFilter() {
    var root = document.getElementById('mgr-employee-filter');
    if (!root || isEmployeeUser()) {
      if (root) root.classList.add('hidden');
      return;
    }
    root.classList.remove('hidden');
    bindManagerEmployeeFilter();
    bindManagerDateFilter();
    // Always sync label/clear immediately from localStorage (don't wait for employee list).
    syncManagerEmployeeFilterLabel();
    if (!managerEmployeeFilterState.loaded) {
      loadManagerEmployeeFilterOptions();
    } else {
      renderManagerEmployeeFilterOptions();
    }
    syncManagerDateFilterLabel();
  }

  function bindManagerEmployeeFilter() {
    var combobox = document.getElementById('mgr-employee-combobox');
    var trigger = document.getElementById('mgr-employee-trigger');
    var panel = document.getElementById('mgr-employee-panel');
    var search = document.getElementById('mgr-employee-search');
    if (!combobox || !trigger || !panel || !search) return;

    trigger.addEventListener('click', function (e) {
      if (e.target.closest('#mgr-employee-clear')) return;
      e.preventDefault();
      e.stopPropagation();
      closeManagerDateFilter();
      managerEmployeeFilterState.open = !managerEmployeeFilterState.open;
      panel.classList.toggle('hidden', !managerEmployeeFilterState.open);
      combobox.classList.toggle('is-open', managerEmployeeFilterState.open);
      trigger.setAttribute('aria-expanded', managerEmployeeFilterState.open ? 'true' : 'false');
      if (managerEmployeeFilterState.open) {
        search.focus();
        search.select();
      }
      icons();
    });

    search.addEventListener('input', function () {
      managerEmployeeFilterState.query = search.value || '';
      renderManagerEmployeeFilterOptions();
    });

    // Document-level handlers survive dashboard repaints.
    if (!document._mgrDashFilterOutsideBound) {
      document._mgrDashFilterOutsideBound = true;
      document.addEventListener('click', function (e) {
        var clearBtn = e.target.closest('#mgr-employee-clear, #mgr-employee-clear-option');
        if (clearBtn) {
          e.preventDefault();
          e.stopPropagation();
          clearManagerEmployeeFilter();
          return;
        }
        if (e.target.closest('#mgr-employee-combobox') || e.target.closest('#mgr-date-combobox')) return;
        closeManagerEmployeeFilter();
        closeManagerDateFilter();
      });
    }
  }

  function closeManagerEmployeeFilter() {
    var combobox = document.getElementById('mgr-employee-combobox');
    var panel = document.getElementById('mgr-employee-panel');
    var trigger = document.getElementById('mgr-employee-trigger');
    managerEmployeeFilterState.open = false;
    if (panel) panel.classList.add('hidden');
    if (combobox) combobox.classList.remove('is-open');
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
  }

  function closeManagerDateFilter() {
    var combobox = document.getElementById('mgr-date-combobox');
    var panel = document.getElementById('mgr-date-panel');
    var trigger = document.getElementById('mgr-date-trigger');
    if (panel) panel.classList.add('hidden');
    if (combobox) combobox.classList.remove('is-open');
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
  }

  function bindManagerDateFilter() {
    var combobox = document.getElementById('mgr-date-combobox');
    var trigger = document.getElementById('mgr-date-trigger');
    var panel = document.getElementById('mgr-date-panel');
    var customWrap = document.getElementById('mgr-date-custom');
    var options = document.getElementById('mgr-date-options');
    var applyBtn = document.getElementById('mgr-date-apply');
    var resetBtn = document.getElementById('mgr-date-reset');
    if (!combobox || !trigger || !panel) return;

    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      closeManagerEmployeeFilter();
      var open = panel.classList.contains('hidden');
      panel.classList.toggle('hidden', !open);
      combobox.classList.toggle('is-open', open);
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      icons();
    });

    if (options) {
      options.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-date-preset]');
        if (!btn) return;
        var preset = btn.getAttribute('data-date-preset');
        if (preset === 'custom') {
          if (customWrap) customWrap.classList.remove('hidden');
          document.querySelectorAll('#mgr-date-options [data-date-preset]').forEach(function (el) {
            el.classList.toggle('is-active', el.getAttribute('data-date-preset') === 'custom');
          });
          if (window.CrmDateTimePicker && customWrap) {
            window.CrmDateTimePicker.initAll(customWrap);
          }
          return;
        }
        if (customWrap) customWrap.classList.add('hidden');
        setDashboardDateFilter(preset, '', '');
        closeManagerDateFilter();
        syncManagerDateFilterLabel();
        refreshDashboardFilters();
      });
    }

    if (applyBtn) {
      applyBtn.addEventListener('click', function () {
        var fromEl = document.getElementById('mgr-date-from');
        var toEl = document.getElementById('mgr-date-to');
        var from = fromEl ? fromEl.value : '';
        var to = toEl ? toEl.value : '';
        if (!from || !to) {
          toast('Select both From and To dates.', 'warning');
          return;
        }
        if (from > to) {
          toast('From date must be before To date.', 'warning');
          return;
        }
        setDashboardDateFilter('custom', from, to);
        closeManagerDateFilter();
        syncManagerDateFilterLabel();
        refreshDashboardFilters();
      });
    }

    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        setDashboardDateFilter('today', '', '');
        var fromEl = document.getElementById('mgr-date-from');
        var toEl = document.getElementById('mgr-date-to');
        if (fromEl) fromEl.value = '';
        if (toEl) toEl.value = '';
        if (customWrap) customWrap.classList.add('hidden');
        closeManagerDateFilter();
        syncManagerDateFilterLabel();
        refreshDashboardFilters();
      });
    }
  }

  function syncManagerDateFilterLabel() {
    var dateFilter = getDashboardDateFilter();
    var labelEl = document.getElementById('mgr-date-selected-label');
    var label = DASHBOARD_DATE_PRESETS[dateFilter.preset] || 'Today';
    if (dateFilter.preset === 'custom' && dateFilter.from && dateFilter.to) {
      label = dateFilter.from + ' – ' + dateFilter.to;
    }
    if (labelEl) labelEl.textContent = label;
    document.querySelectorAll('#mgr-date-options [data-date-preset]').forEach(function (el) {
      el.classList.toggle('is-active', el.getAttribute('data-date-preset') === dateFilter.preset);
    });
    var customWrap = document.getElementById('mgr-date-custom');
    if (customWrap) customWrap.classList.toggle('hidden', dateFilter.preset !== 'custom');
    syncManagerFilterHint();
  }

  function loadManagerEmployeeFilterOptions() {
    apiFetch('/dashboard/productivity-employees')
      .then(function (body) {
        managerEmployeeFilterState.employees = body.data || [];
        managerEmployeeFilterState.loaded = true;
        if (clearStaleDashboardEmployeeFilter() && window._currentPageId === 'dashboard') {
          renderManagerDashboard();
          return;
        }
        renderManagerEmployeeFilterOptions();
        syncManagerEmployeeFilterLabel();
      })
      .catch(function () {
        managerEmployeeFilterState.employees = [];
        managerEmployeeFilterState.loaded = true;
        renderManagerEmployeeFilterOptions();
        syncManagerEmployeeFilterLabel();
      });
  }

  function renderManagerEmployeeFilterOptions() {
    var list = document.getElementById('mgr-employee-options');
    if (!list) return;
    var query = (managerEmployeeFilterState.query || '').trim().toLowerCase();
    var selectedId = getDashboardEmployeeFilterId();
    var rows = [{ employee_id: null, name: 'All Employees', initials: 'ALL', role: 'Organization', city: '' }]
      .concat(managerEmployeeFilterState.employees || []);

    if (query) {
      rows = rows.filter(function (row) {
        return String(row.name || '').toLowerCase().indexOf(query) >= 0
          || String(row.role || '').toLowerCase().indexOf(query) >= 0
          || String(row.city || '').toLowerCase().indexOf(query) >= 0;
      });
    }

    if (!rows.length) {
      list.innerHTML = '<p class="text-caption text-slate-400 p-3">No employees found.</p>';
      return;
    }

    var html = '';
    if (selectedId) {
      html += '<button type="button" class="mgr-employee-option mgr-employee-option--clear" id="mgr-employee-clear-option" role="option">' +
        '<span class="mgr-employee-avatar mgr-employee-avatar--clear">×</span>' +
        '<span class="mgr-employee-option__meta">' +
          '<span class="mgr-employee-option__name">Clear Employee Filter</span>' +
          '<span class="mgr-employee-option__sub">Back to organization dashboard</span>' +
        '</span>' +
      '</button>';
    }

    html += rows.map(function (row) {
      var id = row.employee_id;
      var active = (id === null && !selectedId) || (selectedId && String(id) === String(selectedId));
      var sub = [row.role, row.city].filter(Boolean).join(' · ');
      return '<button type="button" class="mgr-employee-option' + (active ? ' is-active' : '') + '" data-employee-id="' + (id == null ? '' : id) + '" role="option" aria-selected="' + (active ? 'true' : 'false') + '">' +
        '<span class="mgr-employee-avatar">' + escapeHtml(row.initials || 'E') + '</span>' +
        '<span class="mgr-employee-option__meta">' +
          '<span class="mgr-employee-option__name">' + escapeHtml(row.name) + '</span>' +
          (sub ? '<span class="mgr-employee-option__sub">' + escapeHtml(sub) + '</span>' : '') +
        '</span>' +
      '</button>';
    }).join('');

    list.innerHTML = html;

    var clearOption = document.getElementById('mgr-employee-clear-option');
    if (clearOption) {
      clearOption.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        clearManagerEmployeeFilter();
      });
    }

    list.querySelectorAll('[data-employee-id]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var value = btn.getAttribute('data-employee-id');
        applyManagerEmployeeFilter(value ? parseInt(value, 10) : null);
      });
    });
  }

  function syncManagerEmployeeFilterLabel() {
    var selectedId = getDashboardEmployeeFilterId();
    var labelEl = document.getElementById('mgr-employee-selected-label');
    var avatarEl = document.getElementById('mgr-employee-avatar');
    var clearBtn = document.getElementById('mgr-employee-clear');
    var combobox = document.getElementById('mgr-employee-combobox');
    var selected = null;
    if (selectedId) {
      selected = (managerEmployeeFilterState.employees || []).find(function (row) {
        return String(row.employee_id) === String(selectedId);
      });
    }
    if (labelEl) labelEl.textContent = selected ? selected.name : (selectedId ? ('Employee #' + selectedId) : 'All Employees');
    if (avatarEl) avatarEl.textContent = selected ? (selected.initials || 'E') : (selectedId ? 'E' : 'ALL');
    if (combobox) combobox.classList.toggle('has-employee-filter', !!selectedId);
    if (clearBtn) {
      clearBtn.classList.toggle('hidden', !selectedId);
      clearBtn.setAttribute('aria-hidden', selectedId ? 'false' : 'true');
      clearBtn.disabled = !selectedId;
      if (selectedId) icons();
    }
    syncManagerFilterHint();
  }

  function clearManagerEmployeeFilter() {
    var selectedId = getDashboardEmployeeFilterId();
    if (!selectedId) return;
    try {
      localStorage.removeItem('crm_dashboard_employee_id');
      localStorage.setItem('crm_dashboard_employee_id', 'all');
    } catch (e) { /* ignore */ }
    setDashboardEmployeeFilterId(null);
    managerEmployeeFilterState.query = '';
    var search = document.getElementById('mgr-employee-search');
    if (search) search.value = '';
    closeManagerEmployeeFilter();
    syncManagerEmployeeFilterLabel();
    renderManagerEmployeeFilterOptions();
    refreshDashboardFilters();
    toast('Showing organization dashboard', 'info');
  }

  function syncManagerFilterHint() {
    var hintEl = document.getElementById('mgr-employee-filter-hint');
    if (!hintEl) return;
    var selectedId = getDashboardEmployeeFilterId();
    var selected = null;
    if (selectedId) {
      selected = (managerEmployeeFilterState.employees || []).find(function (row) {
        return String(row.employee_id) === String(selectedId);
      });
    }
    var dateFilter = getDashboardDateFilter();
    var dateLabel = DASHBOARD_DATE_PRESETS[dateFilter.preset] || 'Today';
    if (dateFilter.preset === 'custom' && dateFilter.from && dateFilter.to) {
      dateLabel = dateFilter.from + ' – ' + dateFilter.to;
    }
    hintEl.textContent = (selected ? selected.name : 'All Employees') + ' · ' + dateLabel;
  }

  function applyManagerEmployeeFilter(employeeId) {
    setDashboardEmployeeFilterId(employeeId || null);
    managerEmployeeFilterState.query = '';
    var search = document.getElementById('mgr-employee-search');
    if (search) search.value = '';
    closeManagerEmployeeFilter();
    syncManagerEmployeeFilterLabel();
    renderManagerEmployeeFilterOptions();
    refreshDashboardFilters();
  }

  function refreshDashboardFilters() {
    var employeeId = getDashboardEmployeeFilterId();
    var dateFilter = getDashboardDateFilter();
    var dash = document.querySelector('.mgr-dashboard');
    var loading = document.getElementById('mgr-dash-filter-loading');
    if (dash) dash.classList.add('is-loading-employee');
    if (loading) loading.classList.remove('hidden');

    loadDashboardMetricsFromDatabase(function (metrics, error) {
      if (dash) dash.classList.remove('is-loading-employee');
      if (loading) loading.classList.add('hidden');
      if (error || !metrics) {
        toast(error && error.message ? error.message : 'Unable to load dashboard filters', 'error');
        return;
      }
      window.dashboardMetrics = metrics;
      paintManagerDashboard(buildDashboardDisplayMetrics(metrics));
      renderDashboardCharts(metrics.reports);
    }, { force: true, employeeId: employeeId || null, dateFilter: dateFilter });
  }

  function renderManagerEmployeeProductivityPanel(payload) {
    var panel = document.getElementById('mgr-employee-productivity-panel');
    if (!panel) return;

    if (!payload || !payload.metrics || payload.scope !== 'employee') {
      panel.classList.add('hidden');
      panel.innerHTML = '';
      return;
    }

    panel.classList.remove('hidden');
    if (payload.scope === 'employee' && payload.has_activity === false) {
      panel.innerHTML =
        '<div class="flex items-center justify-between gap-3 mb-2">' +
          '<h2 class="text-card-heading">Employee Productivity</h2>' +
          '<span class="text-caption text-slate-500">' + escapeHtml((payload.employee && payload.employee.name) || 'Employee') + '</span>' +
        '</div>' +
        '<p class="text-sm text-slate-500">No activity found for this employee.</p>';
      return;
    }

    var metrics = payload.metrics;
    var dateLabel = (payload.date_range && payload.date_range.label) || '';
    var title = payload.scope === 'employee' && payload.employee
      ? (payload.employee.name + ' · Productivity')
      : 'Organization Productivity';
    if (dateLabel) title += ' · ' + dateLabel;

    function section(titleText, cards) {
      return '<div class="mgr-emp-prod-section">' +
        '<h3 class="mgr-emp-prod-section__title">' + escapeHtml(titleText) + '</h3>' +
        '<div class="mgr-emp-prod-grid">' + cards.map(function (card) {
          return '<div class="mgr-emp-prod-card">' +
            '<p class="mgr-emp-prod-card__label">' + escapeHtml(card.label) + '</p>' +
            '<p class="mgr-emp-prod-card__value">' + escapeHtml(String(card.value ?? '—')) + '</p>' +
          '</div>';
        }).join('') + '</div></div>';
    }

    var nextFollowup = metrics.followups && metrics.followups.next_upcoming
      ? ((metrics.followups.next_upcoming.firm_name || 'Lead') + ' · ' + formatDateTime(metrics.followups.next_upcoming.scheduled_date))
      : '—';

    panel.innerHTML =
      '<div class="flex items-center justify-between gap-3 mb-3">' +
        '<h2 class="text-card-heading">' + escapeHtml(title) + '</h2>' +
      '</div>' +
      section('Lead Metrics', [
        { label: 'Total Assigned', value: metrics.leads.total_assigned },
        { label: 'New Leads', value: metrics.leads.new_leads },
        { label: 'Hot / Warm / Cold', value: metrics.leads.hot_leads + ' / ' + metrics.leads.warm_leads + ' / ' + metrics.leads.cold_leads },
        { label: 'In Pipeline', value: metrics.leads.in_pipeline },
        { label: 'Converted / Won', value: metrics.leads.converted },
        { label: 'Lost / Dead', value: metrics.leads.lost },
        { label: 'Conversion Rate', value: metrics.leads.conversion_rate + '%' },
      ]) +
      section('Daily Work', [
        { label: "Today's Calls", value: metrics.daily_work.todays_calls },
        { label: "Today's Follow-ups", value: metrics.daily_work.todays_followups },
        { label: "Today's Meetings", value: metrics.daily_work.todays_meetings },
        { label: 'Overdue Follow-ups', value: metrics.daily_work.overdue_followups },
      ]) +
      section('Demo Metrics', [
        { label: 'Demos Scheduled', value: metrics.demos.demos_scheduled },
        { label: 'Demos Completed', value: metrics.demos.demos_completed },
        { label: 'Demo Conversion', value: metrics.demos.demo_conversion_rate + '%' },
        { label: 'Missed Demos', value: metrics.demos.missed_demos },
      ]) +
      section('Communication', [
        { label: 'Emails Sent', value: metrics.communication.emails_sent },
        { label: 'SMS Sent', value: metrics.communication.sms_sent },
        { label: 'WhatsApp Sent', value: metrics.communication.whatsapp_sent },
        { label: 'Customer Replies', value: metrics.communication.customer_replies },
      ]) +
      section('Performance', [
        { label: 'Target Assigned', value: metrics.performance.target_assigned },
        { label: 'Target Achieved', value: metrics.performance.target_achieved },
        { label: 'Pending Target', value: metrics.performance.pending_target },
        { label: 'Productivity %', value: metrics.performance.productivity_pct + '%' },
      ]) +
      section('Follow-up', [
        { label: 'Pending', value: metrics.followups.pending },
        { label: 'Completed', value: metrics.followups.completed },
        { label: 'Missed', value: metrics.followups.missed },
        { label: 'Next Upcoming', value: nextFollowup },
      ]);
  }

  function renderDuplicateMonitoringPanel(dm) {
    var root = document.getElementById('mgr-duplicate-monitoring');
    if (!root) return;
    if (!dm) {
      root.classList.add('hidden');
      return;
    }
    root.classList.remove('hidden');

    var typeLabel = function (type) {
      if (type === 'potential_duplicate') return '<span class="badge badge-warning">Potential Duplicate</span>';
      if (type === 'duplicate') return '<span class="badge badge-danger">Duplicate</span>';
      return '<span class="badge">' + escapeHtml(type || '—') + '</span>';
    };

    var statusLabel = function (status) {
      if (status === 'resolved') return '<span class="badge badge-success">Resolved</span>';
      if (status === 'changed' || status === 'changed_number') return '<span class="badge badge-brand">Changed</span>';
      return '<span class="badge badge-warning">Open</span>';
    };

    var recentRows = (dm.recent || []).map(function (row) {
      return '<tr class="ca-table-row">' +
        '<td>' + escapeHtml(row.employee_name || '—') + '</td>' +
        '<td class="font-mono text-sm">' + escapeHtml(row.duplicate_number || '—') + '</td>' +
        '<td>' + escapeHtml(row.existing_lead_name || '—') + '</td>' +
        '<td class="font-mono text-sm">' + escapeHtml(row.saved_number || '—') + '</td>' +
        '<td>' + escapeHtml(formatDuplicateDate(row.attempted_at)) + '</td>' +
        '<td>' + typeLabel(row.attempt_type) + '</td>' +
        '<td>' + statusLabel(row.status) + '</td>' +
      '</tr>';
    }).join('') || '<tr><td colspan="7" class="text-center text-slate-500 p-4">No duplicate attempts logged yet.</td></tr>';

    var topRows = (dm.top_employees || []).map(function (row, i) {
      return '<div class="mgr-dup-top-row"><span class="mgr-dup-rank">' + (i + 1) + '</span>' +
        '<strong>' + escapeHtml(row.employee_name) + '</strong>' +
        '<span class="text-rose-600 font-semibold">' + row.attempt_count + '</span></div>';
    }).join('') || '<p class="text-caption text-slate-500">No data this month.</p>';

    var trendBars = (dm.trend || []).map(function (point) {
      var max = Math.max.apply(null, (dm.trend || []).map(function (p) { return p.count; }).concat([1]));
      var pct = Math.round((point.count / max) * 100);
      return '<div class="mgr-dup-trend-col" title="' + escapeHtml(point.month) + ': ' + point.count + '">' +
        '<div class="mgr-dup-trend-bar" style="height:' + Math.max(pct, 8) + '%"></div>' +
        '<span class="mgr-dup-trend-label">' + escapeHtml((point.month || '').slice(5)) + '</span></div>';
    }).join('');

    root.innerHTML =
      '<div class="mgr-panel-head">' +
        '<h3 class="mgr-panel-title"><i data-lucide="shield-alert" class="h-5 w-5 text-rose-500"></i> Duplicate Monitoring</h3>' +
        '<button type="button" class="mgr-link-btn" data-nav-page="duplicate-attempts">View all</button>' +
      '</div>' +
      '<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-4">' +
        '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">Today</span><strong>' + (dm.today || 0) + '</strong></div>' +
        '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">This Week</span><strong>' + (dm.this_week || 0) + '</strong></div>' +
        '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">This Month</span><strong>' + (dm.this_month || 0) + '</strong></div>' +
        '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">Total</span><strong>' + (dm.total || 0) + '</strong></div>' +
        '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">Exact Duplicates</span><strong class="text-rose-600">' + (dm.duplicate_count || 0) + '</strong></div>' +
        '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">Potential</span><strong class="text-amber-600">' + (dm.potential_duplicate_count || 0) + '</strong></div>' +
        '<div class="mgr-fu-stat">' + iconBtn('download', 'Export', 'id="mgr-dup-export-btn"', 'secondary') + '</div>' +
      '</div>' +
      '<div class="mgr-grid-2 mb-4">' +
        '<div><h4 class="text-sm font-semibold mb-2">Top Employees (This Month)</h4><div class="mgr-dup-top-list">' + topRows + '</div></div>' +
        '<div><h4 class="text-sm font-semibold mb-2">6-Month Trend</h4><div class="mgr-dup-trend">' + (trendBars || '<p class="text-caption text-slate-500">No trend data.</p>') + '</div></div>' +
      '</div>' +
      '<h4 class="text-sm font-semibold mb-2">Recent Duplicate Attempts</h4>' +
      '<div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full mgr-table"><thead><tr>' +
        '<th>Employee</th><th>Duplicate #</th><th>Existing Lead</th><th>Saved #</th><th>Time</th><th>Type</th><th>Status</th>' +
      '</tr></thead><tbody>' + recentRows + '</tbody></table></div>';

    var exportBtn = document.getElementById('mgr-dup-export-btn');
    if (exportBtn && !exportBtn._dupBound) {
      exportBtn._dupBound = true;
      exportBtn.addEventListener('click', function () {
        fetchExportResponse('/duplicate-attempts/export')
          .then(function (result) {
            if (result.kind === 'file') {
              triggerFileDownload(result.blob, result.filename);
            }
          })
          .catch(function (err) {
            toast(err.message || 'Export failed', 'error');
          });
      });
    }
    icons();
  }

  function paintManagerFollowUpMetrics(d) {
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
  }

  function loadManagerFollowUpMetrics() {
    var panel = document.getElementById('mgr-followup-automation-panel');
    if (!panel) return;
    var embedded = window.dashboardMetrics && window.dashboardMetrics.followup_manager;
    if (embedded) {
      paintManagerFollowUpMetrics(embedded);
      return;
    }
    apiFetch('/follow-ups/manager-metrics')
      .then(function (body) {
        paintManagerFollowUpMetrics(body.data || {});
      })
      .catch(function () {
        var list = document.getElementById('mgr-fu-employee-list');
        if (list) list.innerHTML = '<p class="text-caption text-rose-500">Unable to load follow-up metrics.</p>';
      });
  }

  var workflowListsData = null;
  var workflowListTab = 'demo_scheduled';

  function loadEmployeeWorkflowLists() {
    var el = document.getElementById('emp-demo-schedule');
    if (!el) return;
    apiFetch('/workflow/lists')
      .then(function (body) {
        workflowListsData = body.data || {};
        renderEmployeeDemoSchedule(workflowListsData.demo_scheduled || []);
      })
      .catch(function () {
        el.innerHTML = '<p class="text-caption text-slate-400 p-3">Unable to load demos.</p>';
      });
  }

  function renderEmployeeDemoSchedule(items) {
    var el = document.getElementById('emp-demo-schedule');
    if (!el) return;
    if (!items.length) {
      el.innerHTML = '<p class="text-caption text-slate-400 p-3">No demos scheduled.</p>';
      return;
    }
    el.innerHTML = items.map(function (demo) {
      var firm = (demo.lead && demo.lead.firm_name) || demo.firm_name || 'Lead #' + demo.ca_id;
      return '<div class="emp-list-item">' +
        '<span class="emp-list-main"><strong>' + escapeHtml(firm) + '</strong>' +
        '<span class="text-caption text-slate-500">' + escapeHtml(formatDateTime(demo.demo_at)) + '</span></span>' +
        iconBtn('clipboard-check', 'Result', 'data-emp-demo-result="' + demo.id + '"', 'secondary') + '</div>';
    }).join('');
    icons();
  }

  function loadManagerWorkflowLists() {
    var panel = document.getElementById('mgr-workflow-panel');
    if (!panel) return;
    apiFetch('/workflow/lists')
      .then(function (body) {
        workflowListsData = body.data || {};
        paintManagerWorkflowLists(workflowListsData);
      })
      .catch(function () {
        var list = document.getElementById('mgr-workflow-list');
        if (list) list.innerHTML = '<p class="text-caption text-rose-500">Unable to load workflow lists.</p>';
      });
  }

  function paintManagerWorkflowLists(data) {
    var countsEl = document.getElementById('mgr-workflow-counts');
    var tabsEl = document.getElementById('mgr-workflow-tabs');
    var listEl = document.getElementById('mgr-workflow-list');
    var perfEl = document.getElementById('mgr-workflow-performance');
    if (!countsEl || !tabsEl || !listEl) return;

    var counts = data.counts || {};
    var tabs = [
      { id: 'demo_scheduled', label: 'Demo Scheduled', count: counts.demo_scheduled || 0 },
      { id: 'todays_calls', label: "Today's Calls", count: counts.todays_calls || 0 },
      { id: 'interested', label: 'Interested', count: counts.interested || 0 },
      { id: 'not_interested', label: 'Not Interested', count: counts.not_interested || 0 },
      { id: 'hold', label: 'Hold', count: counts.hold || 0 },
      { id: 'purchased', label: 'Purchased', count: counts.purchased || 0 },
    ];

    countsEl.innerHTML = tabs.map(function (tab) {
      return '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">' + escapeHtml(tab.label) + '</span><strong>' + tab.count + '</strong></div>';
    }).join('');

    if (!tabs.some(function (t) { return t.id === workflowListTab; })) {
      workflowListTab = 'demo_scheduled';
    }

    tabsEl.innerHTML = tabs.map(function (tab) {
      return '<button type="button" class="emp-tab' + (workflowListTab === tab.id ? ' active' : '') + '" data-wf-tab="' + tab.id + '">' +
        escapeHtml(tab.label) + ' (' + tab.count + ')</button>';
    }).join('');

    tabsEl.querySelectorAll('[data-wf-tab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        workflowListTab = btn.dataset.wfTab;
        paintManagerWorkflowLists(workflowListsData || data);
      });
    });

    listEl.innerHTML = renderWorkflowListRows(workflowListTab, data);

    if (perfEl) {
      var rows = data.employee_performance || [];
      if (!rows.length) {
        perfEl.innerHTML = '';
      } else {
        perfEl.innerHTML =
          '<h4 class="text-sm font-semibold mb-2">Employee-wise Performance</h4>' +
          '<div class="overflow-x-auto"><table class="ca-table w-full mgr-table"><thead><tr><th>Employee</th><th>Calls</th><th>Demos</th><th>Purchases</th></tr></thead><tbody>' +
          rows.map(function (row) {
            return '<tr><td>' + escapeHtml(row.name) + '</td><td>' + row.calls + '</td><td>' + row.demos + '</td><td>' + row.purchases + '</td></tr>';
          }).join('') +
          '</tbody></table></div>';
      }
    }
    icons();
  }

  function renderWorkflowListRows(tab, data) {
    var items = (data && data[tab]) || [];
    if (!items.length) {
      return '<p class="text-caption text-slate-400 p-3">No records in this list.</p>';
    }

    if (tab === 'purchased') {
      return items.map(function (row) {
        return '<div class="emp-list-item">' +
          '<span class="emp-list-main"><strong>' + escapeHtml(row.customer_name || row.firm_name || '—') + '</strong>' +
          '<span class="text-caption text-slate-500">' + escapeHtml(row.firm_name || '') + ' · ' + escapeHtml(row.mobile_no || '') + ' · ' + escapeHtml(row.email_id || '') + '</span></span>' +
          '<span class="emp-list-meta">' + escapeHtml(formatDate(row.purchase_date)) +
          '<br>Ref: ' + escapeHtml(row.reference_employee_name || (row.employee && row.employee.name) || '—') +
          '<br>By: ' + escapeHtml((row.assigned_by && row.assigned_by.name) || '—') +
          '<br>' + escapeHtml(row.status || 'Purchased') + '</span></div>';
      }).join('');
    }

    if (tab === 'todays_calls') {
      return items.map(function (row) {
        var firm = (row.lead && row.lead.firm_name) || ('Lead #' + row.ca_id);
        return '<div class="emp-list-item">' +
          '<span class="emp-list-main"><strong>' + escapeHtml(firm) + '</strong>' +
          '<span class="text-caption text-slate-500">' + escapeHtml(row.call_status) + ' · ' + escapeHtml((row.employee && row.employee.name) || '') + '</span></span>' +
          '<span class="emp-list-meta">' + escapeHtml(formatDateTime(row.called_at)) + '</span></div>';
      }).join('');
    }

    if (tab === 'demo_scheduled') {
      return items.map(function (row) {
        var firm = (row.lead && row.lead.firm_name) || row.firm_name || ('Lead #' + row.ca_id);
        return '<div class="emp-list-item">' +
          '<span class="emp-list-main"><strong>' + escapeHtml(firm) + '</strong>' +
          '<span class="text-caption text-slate-500">' + escapeHtml((row.employee && row.employee.name) || '') +
          (row.meeting_link ? ' · <a href="' + escapeHtml(row.meeting_link) + '" target="_blank" rel="noopener">Link</a>' : '') +
          '</span></span>' +
          '<span class="emp-list-meta">' + escapeHtml(formatDateTime(row.demo_at)) + '</span></div>';
      }).join('');
    }

    return items.map(function (row) {
      var firm = (row.lead && row.lead.firm_name) || ('Lead #' + row.ca_id);
      return '<div class="emp-list-item">' +
        '<span class="emp-list-main"><strong>' + escapeHtml(firm) + '</strong>' +
        '<span class="text-caption text-slate-500">' + escapeHtml(row.result || '') + ' · ' + escapeHtml((row.employee && row.employee.name) || '') + '</span></span>' +
        '<span class="emp-list-meta">' + escapeHtml(formatDateTime(row.created_at)) + '</span></div>';
    }).join('');
  }

  function handleWorkflowQuickAction(action) {
    var leadId = window._selectedLeadId || (window.CAData && CAData.getSelectedLeadId && CAData.getSelectedLeadId());
    if (action === 'call') {
      openCallOutcomeModal(null, leadId || '');
      return;
    }
    if (action === 'schedule-demo') {
      openScheduleDemoModal(leadId);
      return;
    }
    if (action === 'demo-result') {
      var demos = (workflowListsData && workflowListsData.demo_scheduled) || [];
      if (!demos.length) {
        toast('No scheduled demos to update.', 'warning');
        return;
      }
      openDemoResultModal(demos[0].id);
    }
  }

  function openScheduleDemoModal(caId) {
    var modal = document.getElementById('modal-schedule-demo');
    var form = document.getElementById('form-schedule-demo');
    if (!modal || !form) return;
    form.reset();
    var caEl = document.getElementById('schedule-demo-ca-id');
    if (caEl) caEl.value = caId || '';
    if (!caEl || !caEl.value) {
      toast('Select a lead first, then schedule a demo.', 'warning');
      if (typeof navigateTo === 'function') navigateTo(isEmployeeUser() ? 'leads' : 'ca-master');
      return;
    }
    openModal(modal);
    icons();
  }

  var demoPurchaseOptionsCache = null;

  function ensureDemoPurchaseOptions() {
    if (demoPurchaseOptionsCache) {
      return Promise.resolve(demoPurchaseOptionsCache);
    }
    return apiFetch('/workflow/options')
      .then(function (body) {
        var data = body.data || {};
        demoPurchaseOptionsCache = {
          plans: data.purchase_plans || ['CRM Annual', 'CRM Half-Yearly', 'CRM Quarterly', 'CRM Monthly'],
          plan_configs: data.plan_configs || {},
        };
        return demoPurchaseOptionsCache;
      })
      .catch(function () {
        demoPurchaseOptionsCache = {
          plans: ['CRM Annual', 'CRM Half-Yearly', 'CRM Quarterly', 'CRM Monthly'],
          plan_configs: {},
        };
        return demoPurchaseOptionsCache;
      });
  }

  function ensureSalesListOptionsCache() {
    if (salesListOptionsCache) {
      return Promise.resolve(salesListOptionsCache);
    }
    return apiFetch('/sales-list/options')
      .then(function (body) {
        salesListOptionsCache = body.data || {};
        return salesListOptionsCache;
      })
      .catch(function () {
        return ensureDemoPurchaseOptions();
      });
  }

  function populateDemoResultPlanSelect(selectedPlan) {
    var planSelect = document.getElementById('demo-result-plan');
    if (!planSelect) return;
    var options = demoPurchaseOptionsCache || salesListOptionsCache || {};
    var plans = options.plans || options.purchase_plans || ['CRM Annual', 'CRM Half-Yearly', 'CRM Quarterly', 'CRM Monthly'];
    planSelect.innerHTML = plans.map(function (plan) {
      return '<option value="' + plan + '">' + plan + '</option>';
    }).join('');
    if (selectedPlan) planSelect.value = selectedPlan;
    if (!planSelect.value && plans.length) planSelect.value = plans[0];
  }

  function formatSaleMonthLabel(dateString) {
    if (!dateString) return '—';
    var parts = dateString.split('-');
    if (parts.length < 2) return dateString;
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var monthIndex = parseInt(parts[1], 10) - 1;
    return (months[monthIndex] || parts[1]) + ' ' + parts[0];
  }

  function syncDemoPurchasePreview(applyPlanDefaults) {
    var options = demoPurchaseOptionsCache || salesListOptionsCache || {};
    var plan = (document.getElementById('demo-result-plan') || {}).value || '';
    var purchaseDate = (document.getElementById('demo-result-purchase-date') || {}).value || '';
    var planConfig = (options.plan_configs || {})[plan] || {};
    var coolingInput = document.getElementById('demo-result-cooling');
    var pointsInput = document.getElementById('demo-result-points');
    var totalInput = document.getElementById('demo-result-total');
    var monthInput = document.getElementById('demo-result-sale-month');

    if (monthInput) monthInput.value = formatSaleMonthLabel(purchaseDate);

    if (applyPlanDefaults && planConfig) {
      if (coolingInput && planConfig.cooling_period_days != null) {
        coolingInput.value = String(planConfig.cooling_period_days);
      }
      if (pointsInput && planConfig.points != null) {
        pointsInput.value = String(planConfig.points);
      }
      if (totalInput && planConfig.default_amount != null && (!totalInput.value || totalInput.value === '0')) {
        totalInput.value = String(planConfig.default_amount);
      }
    }

    var expiryInput = document.getElementById('demo-result-expiry');
    if (expiryInput) {
      var expiry = purchaseDate && planConfig.duration_months
        ? addMonthsToDateString(purchaseDate, planConfig.duration_months)
        : '';
      expiryInput.value = expiry || '—';
    }

    var total = parseFloat((document.getElementById('demo-result-total') || {}).value || '0');
    var received = parseFloat((document.getElementById('demo-result-received') || {}).value || '0');
    var balance = Math.max(0, Math.round((total - received) * 100) / 100);
    var balanceInput = document.getElementById('demo-result-balance');
    if (balanceInput) balanceInput.value = formatSalesCurrency(balance);

    var statusEl = document.getElementById('demo-result-payment-status');
    if (statusEl) {
      var expiryDate = expiryInput && expiryInput.value !== '—' ? expiryInput.value : '';
      statusEl.innerHTML = salesPaymentBadge(previewSalesListPaymentStatus(total, received, expiryDate));
    }
  }

  function prefillDemoPurchaseFields(context) {
    context = context || {};
    var today = new Date().toISOString().slice(0, 10);
    var purchaseDateInput = document.getElementById('demo-result-purchase-date');
    if (purchaseDateInput && !purchaseDateInput.value) purchaseDateInput.value = today;
    var customerInput = document.getElementById('demo-result-customer');
    if (customerInput) customerInput.value = context.customer_name || context.ca_name || '';
    var firmInput = document.getElementById('demo-result-firm');
    if (firmInput) firmInput.value = context.firm_name || '';
    var mobileInput = document.getElementById('demo-result-mobile');
    if (mobileInput) mobileInput.value = context.mobile_no || '';
    var cityInput = document.getElementById('demo-result-city');
    if (cityInput) cityInput.value = context.city_name || '';
    var referenceInput = document.getElementById('demo-result-reference');
    if (referenceInput) referenceInput.value = context.employee_name || context.reference_name || '';
    var executiveSelect = document.getElementById('demo-result-executive');
    if (executiveSelect && context.employee_id) {
      setSelectValueIfValid(executiveSelect, context.employee_id);
    }
    populateDemoResultPlanSelect(context.plan_purchased || 'CRM Annual');
    syncDemoPurchasePreview(true);
  }

  function openDemoResultModal(scheduleId, contextLabel, leadContext) {
    var modal = document.getElementById('modal-demo-result');
    var form = document.getElementById('form-demo-result');
    if (!modal || !form) return;
    form.reset();
    var idEl = document.getElementById('demo-result-schedule-id');
    if (idEl) idEl.value = scheduleId || '';
    var purchaseWrap = document.getElementById('demo-result-purchase-wrap');
    if (purchaseWrap) purchaseWrap.classList.add('hidden');
    var context = document.getElementById('demo-result-context');
    if (context) {
      if (contextLabel) {
        context.textContent = contextLabel;
        context.classList.remove('hidden');
      } else {
        context.textContent = '';
        context.classList.add('hidden');
      }
    }
    ensureDemoPurchaseOptions().then(function () {
      prefillDemoPurchaseFields(leadContext || {});
      enhanceEntityLookups(modal);
    });
    openModal(modal);
    var resultSelect = document.getElementById('demo-result-select');
    if (resultSelect) resultSelect.focus();
    icons();
  }

  function openDemoResultForFollowup(followupId, caId) {
    if (!followupId && !caId) {
      toast('Follow-up record is missing. Refresh and try again.', 'warning');
      return;
    }
    var followupPromise = followupId
      ? resolveFollowupById(followupId)
      : Promise.resolve(findFollowupInCache(followupId));
    followupPromise
      .then(function (followup) {
        var resolvedCaId = caId || (followup && followup.ca_id);
        var qs = followupId
          ? ('followup_id=' + encodeURIComponent(followupId))
          : ('ca_id=' + encodeURIComponent(resolvedCaId || ''));
        return apiFetch('/workflow/demos/resolve?' + qs).then(function (body) {
          return { body: body, followup: followup, caId: resolvedCaId };
        });
      })
      .then(function (result) {
        if (!result) return;
        var schedule = (result.body.data && result.body.data.demo_schedule) || null;
        if (!schedule || !schedule.id) {
          toast('No demo schedule is linked to this follow-up yet.', 'warning');
          return;
        }
        var firm = (result.followup && result.followup.firm_name) || schedule.firm_name || ('Lead #' + (result.caId || schedule.ca_id));
        openDemoResultModal(schedule.id, 'After-demo remark for ' + firm, {
          ca_name: (result.followup && result.followup.customer_name) || schedule.customer_name,
          customer_name: (result.followup && result.followup.customer_name) || schedule.customer_name,
          firm_name: firm,
          mobile_no: (result.followup && result.followup.mobile_no) || schedule.mobile_no,
          city_name: (result.followup && result.followup.city_name) || '',
          employee_id: schedule.employee_id,
          employee_name: schedule.employee_name || (result.followup && result.followup.employee_name),
        });
      })
      .catch(function (err) {
        if (err.status === 403) return;
        toast(err.message || 'Unable to open demo result', 'warning');
      });
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
        if (typeof navigateTo === 'function') navigateTo(isEmployeeUser() ? 'leads' : 'ca-master');
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
      if (row._dashFunnelBound) return;
      row._dashFunnelBound = true;
      row.addEventListener('click', function () {
        window._leadSegmentFilter = 'pipeline';
        if (typeof navigateTo === 'function') navigateTo('ca-master');
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
    var metrics = window.dashboardMetrics || {};
    var leads = (metrics.recent_leads || []).map(mapDashboardLeadSummary);
    if (!leads.length) leads = getDashboardLeads();
    if (!leads.length) {
      el.innerHTML = emptyTableRow(4, 'No leads yet — add firms from Master Data.');
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

  function getCamListingSegment() {
    if (!window.CA_LISTING_SEARCH) return '';
    return (CA_LISTING_SEARCH.getState('ca_masters').filters || {}).segment || '';
  }

  /** Keep Master Data chip state and listing filters aligned (single source of truth). */
  function syncCamSegmentState() {
    if (!document.getElementById('cam-hub')) return getLeadFilter();
    var mem = getLeadFilter();
    var listing = getCamListingSegment();
    if (!listing || listing === 'mobile_missing') return mem;
    if (mem === 'all' || !mem) {
      if (listing === 'new' || listing === 'hot') {
        var state = CA_LISTING_SEARCH.getState('ca_masters');
        var filters = Object.assign({}, state.filters || {});
        delete filters.segment;
        CA_LISTING_SEARCH.setState('ca_masters', { filters: filters });
      }
      return 'all';
    }
    if (listing !== mem && (mem === 'new' || mem === 'hot')) {
      var st = CA_LISTING_SEARCH.getState('ca_masters');
      CA_LISTING_SEARCH.setState('ca_masters', {
        filters: Object.assign({}, st.filters || {}, { segment: mem }),
      });
    }
    return mem;
  }

  function getRealLeadsFiltered() {
    var source = document.getElementById('kanban-board') && kanbanLeadsLoaded
      ? (window.kanbanLeads || [])
      : (window._listingLeadsPage && window._listingLeadsPage.length
        ? window._listingLeadsPage
        : (window.realLeads || []));
    var leads = source.slice();
    var segment = getLeadFilter();
    if (!segment || segment === 'all') return leads;
    if (segment === 'new') {
      return leads.filter(function (l) { return l.is_newly_established || l.status === 'New' || l.stage === 'New Lead'; });
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
    if (emailInput) {
      emailInput.value = u.email || '';
      var emailHint = document.getElementById('profile-edit-email-hint');
      if (u.role === 'super_admin') {
        emailInput.readOnly = true;
        emailInput.classList.add('bg-slate-50');
        if (emailHint) emailHint.classList.remove('hidden');
      } else {
        emailInput.readOnly = false;
        emailInput.classList.remove('bg-slate-50');
        if (emailHint) emailHint.classList.add('hidden');
      }
    }
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

  function renderLoginEmailChangeStatus(data) {
    var currentDisplay = document.getElementById('login-email-current-display');
    var verifiedBadge = document.getElementById('login-email-status-badge');
    var pendingBadge = document.getElementById('login-email-pending-badge');
    var expiredBadge = document.getElementById('login-email-expired-badge');
    var failedBadge = document.getElementById('login-email-failed-badge');
    var pendingPanel = document.getElementById('login-email-pending-panel');
    var pendingText = document.getElementById('login-email-pending-text');
    var pendingTarget = document.getElementById('login-email-pending-target');
    var pendingExpires = document.getElementById('login-email-pending-expires');
    var expiredPanel = document.getElementById('login-email-expired-panel');
    var expiredText = document.getElementById('login-email-expired-text');
    var changeFields = document.getElementById('login-email-change-fields');
    var submitBtn = document.getElementById('change-login-email-submit-btn');
    var currentEmail = (data && data.current_email) || (window.__CRM_USER__ && window.__CRM_USER__.email) || '';
    var status = (data && data.verification_status) || 'verified';
    var pending = data && data.pending_verification;
    var lastRequest = data && data.last_request;

    if (currentDisplay) {
      if (currentDisplay.tagName === 'INPUT') currentDisplay.value = currentEmail;
      else currentDisplay.textContent = currentEmail || '—';
    }

    if (verifiedBadge) verifiedBadge.classList.toggle('hidden', status !== 'verified');
    if (pendingBadge) pendingBadge.classList.toggle('hidden', status !== 'pending_verification');
    if (expiredBadge) expiredBadge.classList.toggle('hidden', status !== 'expired');
    if (failedBadge) failedBadge.classList.toggle('hidden', status !== 'failed' && status !== 'cancelled');

    var hasPending = status === 'pending_verification' && !!pending;
    if (pendingPanel) pendingPanel.classList.toggle('hidden', !hasPending);
    if (pendingTarget && pending) pendingTarget.textContent = pending.new_email || '—';
    if (pendingExpires && pending && pending.expires_at) {
      var hoursLeft = Math.max(1, Math.ceil((new Date(pending.expires_at).getTime() - Date.now()) / 3600000));
      pendingExpires.textContent = hoursLeft + (hoursLeft === 1 ? ' Hour' : ' Hours');
    } else if (pendingExpires) {
      pendingExpires.textContent = '24 Hours';
    }
    if (pendingText && pending) {
      pendingText.textContent = 'Waiting for verification at ' + pending.new_email + '. The link expires ' + (pending.expires_at ? new Date(pending.expires_at).toLocaleString() : 'soon') + '.';
    }

    if (expiredPanel) expiredPanel.classList.toggle('hidden', status !== 'expired');
    if (expiredText && status === 'expired' && lastRequest) {
      expiredText.textContent = 'The verification request for ' + lastRequest.new_email + ' has expired. You can submit a new login email change below.';
    }

    if (changeFields) changeFields.classList.toggle('hidden', hasPending);
    if (submitBtn) submitBtn.classList.toggle('hidden', hasPending);
  }

  function openChangeLoginEmailModal() {
    var modal = document.getElementById('modal-change-login-email');
    var form = document.getElementById('form-change-login-email');
    if (!modal || !form) return;

    if (typeof closeDetailDrawer === 'function') closeDetailDrawer();
    form.reset();
    renderLoginEmailChangeStatus({ current_email: (window.__CRM_USER__ || {}).email || '', verification_status: 'verified' });
    openModal(modal);
    if (typeof initPasswordToggleButtons === 'function') initPasswordToggleButtons(modal);
    icons();

    apiFetch('/auth/login-email-change')
      .then(function (body) {
        renderLoginEmailChangeStatus(body.data || body);
        icons();
      })
      .catch(function (error) {
        toast(error.message || 'Unable to load login email settings', error.status === 403 ? 'warning' : 'error');
      });
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
        { label: 'Google Maps', value: lead.google_maps_url
          ? '<a class="text-brand hover:underline" href="' + escapeHtml(lead.google_maps_url) + '" target="_blank" rel="noopener noreferrer">Open in Maps</a>'
          : '—' },
        { label: 'Google Place ID', value: lead.google_place_id || '—' },
        { label: 'Google Coordinates', value: lead.latitude != null && lead.longitude != null
          ? String(lead.latitude) + ', ' + String(lead.longitude)
          : '—' },
        { label: 'Google Address', value: lead.verified_address || lead.address || '—' },
        { label: 'Google Rating', value: lead.google_rating != null
          ? String(lead.google_rating) + (lead.google_review_count != null ? ' (' + lead.google_review_count + ' reviews)' : '')
          : '—' },
        { label: 'Google Status', value: formatGoogleBusinessStatus(lead.google_business_status) },
        { label: 'Google Verified', value: lead.verified_from_google ? 'Yes' : 'No' },
        { label: 'Google Researched', value: lead.researched_at ? formatDateTime(lead.researched_at) : '—' },
        { label: 'CRM Rating', value: data.rating + ' / 5' },
        { label: 'New Firm', value: data.newFirm ? 'Yes' : 'No' },
        { label: 'Employee', value: data.executive },
        { label: 'Stage', value: data.stage },
        { label: 'Status', value: data.status },
        { label: 'Source', value: data.source },
        { label: 'Last Action', value: data.last_action },
      ],
      extraHtml: (canUseLeadQuickActions(lead)
        ? '<div class="mt-4"><button type="button" class="btn-secondary btn-sm" data-lead-drawer-google="' + escapeHtml(String(lead.ca_id)) + '">' +
          '<i data-lucide="map-pin" class="h-4 w-4"></i> Google Places Lookup</button></div>'
        : '') +
        '<div id="lead-ocr-panel-host" class="mt-4"></div>' +
        '<div id="lead-email-communications-section"><p class="text-caption text-slate-400 mt-4">Loading communication history…</p></div>' +
        '<div id="lead-demo-confirmation-section"><p class="text-caption text-slate-400 mt-4">Loading confirmation…</p></div>',
    });
    if (window.CrmOcrPanel && typeof window.CrmOcrPanel.mountIntoDrawer === 'function') {
      window.CrmOcrPanel.mountIntoDrawer(lead.ca_id);
    }
    apiFetch('/ca-masters/' + encodeURIComponent(lead.ca_id) + '/email-communications')
      .then(function (body) {
        var section = document.getElementById('lead-email-communications-section');
        if (!section) return;
        var payload = body.data || {};
        if (!(payload.items || []).length && !(payload.threads || []).length) {
          section.outerHTML = '';
          return;
        }
        section.outerHTML = renderLeadEmailTimelineSection(payload);
        icons();
      })
      .catch(function () {
        var section = document.getElementById('lead-email-communications-section');
        if (section) section.outerHTML = '';
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

  function setLeadsView(viewId) {
    document.querySelectorAll('.ca-tab-panel[data-tab-group="leads-view"]').forEach(function (panel) {
      panel.classList.toggle('active', panel.dataset.panel === viewId);
    });
    renderLeadKpis();
    if (viewId === 'pipeline') loadKanbanLeads();
    else if (window.CA_LISTING_SEARCH) reloadListing('ca_masters');
    else renderLeadsTable();
  }

  function applyLeadSegment(segmentId) {
    window._leadSegmentFilter = segmentId || 'all';
    if (window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.setState('ca_masters', {
        page: 1,
        filters: Object.assign(
          {},
          readCaMasterColumnFilters(),
          { segment: !segmentId || segmentId === 'all' ? '' : segmentId },
        ),
      });
    }
    if (isLeadsPipelineTabActive()) {
      renderLeadKpis();
      loadKanbanLeads();
    } else {
      setLeadsView('all');
    }
  }

  function renderLeadKpis() {
    var el = document.getElementById('leads-kpi-strip');
    if (!el) return;
    var activeSegment = getLeadFilter();
    if (activeSegment === 'hot' || activeSegment === 'new') {
      activeSegment = 'all';
      window._leadSegmentFilter = 'all';
      if (window.CA_LISTING_SEARCH) {
        var state = CA_LISTING_SEARCH.getState('ca_masters');
        var filters = Object.assign({}, state.filters || {});
        if (filters.segment === 'hot' || filters.segment === 'new') {
          delete filters.segment;
          CA_LISTING_SEARCH.setState('ca_masters', { filters: filters });
        }
      }
    }
    var activeView = isLeadsPipelineTabActive() ? 'pipeline' : 'all';
    var items = [
      { kind: 'view', id: 'pipeline', label: 'Pipeline', icon: 'git-branch' },
      { kind: 'view', id: 'all', label: 'All Leads', icon: 'list' },
      { kind: 'segment', id: 'pipeline', label: 'In Pipeline', icon: 'columns-3' },
    ];
    if (canViewSalesList()) {
      items.push({ kind: 'segment', id: 'negotiation', label: 'Negotiation', icon: 'handshake' });
    }
    items.push({ kind: 'segment', id: 'lost', label: 'Lost', icon: 'user-x' });
    var chipsHtml = items.map(function (item) {
      var isActive = item.kind === 'view'
        ? activeView === item.id
        : item.kind === 'nav'
          ? false
          : activeSegment === item.id;
      var attrs = item.kind === 'view'
        ? 'data-leads-view="' + item.id + '"'
        : item.kind === 'nav'
          ? 'data-leads-nav="' + item.id + '"'
          : 'data-lead-segment="' + item.id + '"';
      return '<button type="button" class="leads-kpi-chip cam-control-chip' + (isActive ? ' active' : '') + '" ' + attrs +
        ' title="' + item.label + '" aria-label="' + item.label + '">' +
        '<i data-lucide="' + item.icon + '" class="h-4 w-4"></i>' +
        '<span class="leads-kpi-label">' + item.label + '</span></button>';
    }).join('');
    el.innerHTML =
      '<div class="leads-kpi-chips cam-control-chips">' + chipsHtml + '</div>' +
      (crmCanAction('leads', 'create')
        ? '<div class="leads-kpi-actions" role="toolbar" aria-label="Lead actions">' +
            '<button type="button" class="crm-toolbar-icon-btn crm-toolbar-icon-btn--primary" data-open-modal="add-lead" id="leads-kpi-add-btn" title="Add Lead" aria-label="Add Lead">' +
              '<i data-lucide="plus" class="h-4 w-4"></i></button>' +
          '</div>'
        : '');
    if (!el._kpiStripBound) {
      el._kpiStripBound = true;
      el.addEventListener('click', function (e) {
        var navBtn = e.target.closest('[data-leads-nav]');
        if (navBtn) {
          if (typeof navigateTo === 'function') navigateTo(navBtn.getAttribute('data-leads-nav'));
          return;
        }
        var viewBtn = e.target.closest('[data-leads-view]');
        if (viewBtn) {
          setLeadsView(viewBtn.getAttribute('data-leads-view'));
          return;
        }
        var btn = e.target.closest('[data-lead-segment]');
        if (!btn) return;
        applyLeadSegment(btn.dataset.leadSegment);
        toast('Showing ' + btn.querySelector('.leads-kpi-label').textContent, 'info');
      });
    }
    bindModalTriggers(el);
    icons();
  }

  function showCamPrimaryViews() {
    var primary = document.getElementById('cam-primary-views');
    var secondary = document.getElementById('cam-secondary-views');
    if (primary) primary.classList.remove('hidden');
    if (secondary) secondary.classList.add('hidden');
    var hub = document.getElementById('cam-hub');
    if (hub) hub.dataset.camSecondary = '';
  }

  function showCamSecondaryView(view) {
    var primary = document.getElementById('cam-primary-views');
    var secondary = document.getElementById('cam-secondary-views');
    var masters = document.getElementById('cam-secondary-masters');
    var bulk = document.getElementById('cam-secondary-bulk');
    var title = document.getElementById('cam-secondary-title');
    if (primary) primary.classList.add('hidden');
    if (secondary) secondary.classList.remove('hidden');
    if (masters) masters.classList.toggle('hidden', view !== 'masters');
    if (bulk) bulk.classList.toggle('hidden', view !== 'bulk');
    if (title) title.textContent = view === 'masters' ? 'Master Tables' : 'Bulk Tools';
    var hub = document.getElementById('cam-hub');
    if (hub) hub.dataset.camSecondary = view || '';
    if (view === 'bulk') {
      ensureBulkImportDetailCloseHandlers();
      ensureBulkImportDetailModalRoot();
      bindBulkImportDetailFooterActions();
      if (typeof initBulkImportWizard === 'function') initBulkImportWizard();
      if (typeof initBulkAssignmentPanel === 'function') initBulkAssignmentPanel();
      if (typeof initBulkExportPanel === 'function') initBulkExportPanel();
      if (typeof initBulkStatusUpdatePanel === 'function') initBulkStatusUpdatePanel();
      if (typeof loadBulkOperationsHistory === 'function') loadBulkOperationsHistory();
    }
    if (view === 'masters' && typeof renderMasterTables === 'function') {
      renderMasterTables();
    }
    icons();
  }

  function clearKanbanStageSearches() {
    kanbanStageSearch = {};
    Object.keys(kanbanStageSearchTimers).forEach(function (key) {
      window.clearTimeout(kanbanStageSearchTimers[key]);
    });
    kanbanStageSearchTimers = {};
  }

  function setCamView(viewId) {
    showCamPrimaryViews();
    if (viewId === 'pipeline') {
      clearKanbanStageSearches();
    }
    document.querySelectorAll('.ca-tab-panel[data-tab-group="cam-view"]').forEach(function (panel) {
      panel.classList.toggle('active', panel.dataset.panel === viewId);
    });
    renderCamKpis();
    syncCamStageFilterBarVisibility();
    if (viewId === 'all') syncCamStageFilterFromState();
    if (viewId === 'pipeline') loadKanbanLeads();
    else renderCaMasterTable();
  }

  function readCaMasterStageFilter() {
    var el = document.getElementById('cam-filter-pipeline-stage');
    return el ? String(el.value || '').trim() : '';
  }

  function syncCamStageFilterBarVisibility() {
    var toolbar = document.getElementById('cam-stage-filter-toolbar');
    if (!toolbar) return;
    var show = isCamAllFirmsTabActive();
    var primary = document.getElementById('cam-primary-views');
    if (primary && primary.classList.contains('hidden')) show = false;
    toolbar.classList.toggle('hidden', !show);
    var stage = readCaMasterStageFilter();
    toolbar.classList.toggle('cam-stage-filter-toolbar--active', !!stage);
  }

  function syncCamStageFilterFromState() {
    if (!window.CA_LISTING_SEARCH) return;
    var el = document.getElementById('cam-filter-pipeline-stage');
    if (!el) return;
    var filters = CA_LISTING_SEARCH.getState('ca_masters').filters || {};
    el.value = filters.master_pipeline_stage || '';
    syncCamStageFilterBarVisibility();
  }

  function buildCaMasterListingFilters(extraFilters) {
    var filters = Object.assign({}, readCaMasterColumnFilters(), extraFilters || {});
    var stage = readCaMasterStageFilter();
    if (stage) {
      filters.master_pipeline_stage = stage;
    } else {
      delete filters.master_pipeline_stage;
    }
    var listing = window.CA_LISTING_SEARCH ? (CA_LISTING_SEARCH.getState('ca_masters').filters || {}) : {};
    if (listing.segment && !Object.prototype.hasOwnProperty.call(extraFilters || {}, 'segment')) {
      filters.segment = listing.segment;
    }
    return filters;
  }

  function resetCaMasterTableFilters() {
    var stageEl = document.getElementById('cam-filter-pipeline-stage');
    if (stageEl) stageEl.value = '';
    document.querySelectorAll('.crm-col-filter-input[data-col-filter-group="ca_masters"]').forEach(function (input) {
      input.value = '';
    });
    window._leadSegmentFilter = 'all';
    if (window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.setState('ca_masters', { page: 1, search: '', filters: {} });
    }
    syncCamStageFilterBarVisibility();
    if (isCamPipelineTabActive()) loadKanbanLeads();
    else renderCaMasterTable();
  }

  function applyCamSegment(segmentId) {
    window._leadSegmentFilter = segmentId || 'all';
    if (window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.setState('ca_masters', {
        page: 1,
        filters: Object.assign(
          {},
          readCaMasterColumnFilters(),
          { segment: !segmentId || segmentId === 'all' ? '' : segmentId },
        ),
      });
    }
    showCamPrimaryViews();
    if (isCamPipelineTabActive()) {
      renderCamKpis();
      loadKanbanLeads();
    } else {
      setCamView('all');
    }
  }

  function camStageFilterToolbarHtml() {
    return '<div class="cam-stage-filter-toolbar hidden" id="cam-stage-filter-toolbar" aria-label="Firm status filters">' +
      '<select id="cam-filter-pipeline-stage" class="input-field cam-filter-select cam-filter-pipeline-stage" aria-label="Status">' +
        '<option value="">All Leads</option>' +
        '<option value="New Lead">New Lead</option>' +
        '<option value="Contacted">Contacted</option>' +
        '<option value="Interested">Interested</option>' +
        '<option value="Converted">Converted</option>' +
      '</select>' +
      '<button type="button" class="crm-toolbar-icon-btn cam-filter-reset-btn" id="cam-filter-reset" title="Reset Filters" data-crm-tip="Reset Filters" aria-label="Reset Filters">' +
        '<i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>' +
      '</button>' +
    '</div>';
  }

  function renderCamKpis() {
    var el = document.getElementById('cam-kpi-strip');
    if (!el) return;
    var activeSegment = getLeadFilter();
    if (activeSegment === 'hot') {
      activeSegment = 'all';
      window._leadSegmentFilter = 'all';
      if (window.CA_LISTING_SEARCH) {
        var state = CA_LISTING_SEARCH.getState('ca_masters');
        var filters = Object.assign({}, state.filters || {});
        if (filters.segment === 'hot') {
          delete filters.segment;
          CA_LISTING_SEARCH.setState('ca_masters', { filters: filters });
        }
      }
    }
    var activeView = isCamPipelineTabActive() ? 'pipeline' : 'all';
    if (activeSegment === 'pipeline' || activeSegment === 'lost') {
      activeSegment = 'all';
      window._leadSegmentFilter = 'all';
      if (window.CA_LISTING_SEARCH) {
        var clearState = CA_LISTING_SEARCH.getState('ca_masters');
        var clearFilters = Object.assign({}, clearState.filters || {});
        if (clearFilters.segment === 'pipeline' || clearFilters.segment === 'lost') {
          delete clearFilters.segment;
          CA_LISTING_SEARCH.setState('ca_masters', { filters: clearFilters });
        }
      }
    }
    var items = [
      { kind: 'view', id: 'pipeline', label: 'Master Pipeline', icon: 'git-branch' },
      { kind: 'view', id: 'all', label: 'All Firms', icon: 'list' },
    ];
    var chipsHtml = items.map(function (item) {
      var isActive = activeView === item.id;
      return '<button type="button" role="tab" class="leads-kpi-chip cam-control-chip' + (isActive ? ' active' : '') + '" data-cam-view="' + item.id + '"' +
        ' aria-label="' + item.label + '" aria-selected="' + (isActive ? 'true' : 'false') + '">' +
        '<i data-lucide="' + item.icon + '" class="h-4 w-4" aria-hidden="true"></i>' +
        '<span class="leads-kpi-label">' + item.label + '</span></button>';
    }).join('');
    var addFirmBtn = crmCanAction('ca_master', 'create')
      ? '<button type="button" class="crm-toolbar-icon-btn crm-toolbar-icon-btn--primary" data-open-modal="add-lead" id="cam-add-firm-btn" title="Add Firm" aria-label="Add Firm">' +
        '<i data-lucide="plus" class="h-4 w-4"></i></button>'
      : '';
    var firmActions = '';
    if (crmCanAction('ca_master', 'import')) {
      firmActions += iconBtn('file-spreadsheet', 'Import Excel', 'data-inbox-action="import" data-inbox-table="ca-master-data-table" data-inbox-module="ca-master" id="cam-import-btn"');
    }
    if (crmCanAction('ca_master', 'export')) {
      firmActions += iconBtn('download', 'Export', 'data-inbox-action="export" data-inbox-table="ca-master-data-table" data-inbox-module="ca-master" id="cam-export-btn"');
    }
    firmActions += addFirmBtn;
    el.innerHTML =
      '<div class="cam-control-primary">' +
        '<div class="leads-kpi-chips cam-control-chips">' + chipsHtml + '</div>' +
        camStageFilterToolbarHtml() +
      '</div>' +
      (firmActions
        ? '<div class="leads-kpi-actions" role="toolbar" aria-label="Firm actions">' + firmActions + '</div>'
        : '');
    if (!el._camKpiBound) {
      el._camKpiBound = true;
      el.addEventListener('click', function (e) {
        var viewBtn = e.target.closest('[data-cam-view]');
        if (viewBtn) {
          setCamView(viewBtn.getAttribute('data-cam-view'));
        }
      });
    }
    bindModalTriggers(el);
    applyMasterDataRbac();
    syncCamStageFilterBarVisibility();
    if (isCamAllFirmsTabActive()) syncCamStageFilterFromState();
    icons();
  }

  function bindLeadsColumnFilters() {
    var hub = document.getElementById('leads-hub');
    if (!hub || hub._leadsColFilterBound) return;
    hub._leadsColFilterBound = true;
    var timer = null;
    hub.addEventListener('input', function (e) {
      var input = e.target.closest('.crm-col-filter-input[data-col-filter-group="ca_masters"]');
      if (!input) return;
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        var segment = getLeadFilter();
        if (window.CA_LISTING_SEARCH) {
          CA_LISTING_SEARCH.setState('ca_masters', {
            page: 1,
            filters: Object.assign(
              {},
              readCaMasterColumnFilters(),
              { segment: segment && segment !== 'all' ? segment : '' },
            ),
          });
          reloadListing('ca_masters');
        }
      }, 320);
    });
  }

  function renderLeadsHub() {
    return ensureLeadsHubData(function () {
      renderLeadKpis();
      bindLeadsColumnFilters();
      var pipelineActive = isLeadsPipelineTabActive();
      var allActive = isLeadsAllTabActive();
      var finishHub = function () {
        initLeadActions();
        bindModalTriggers(document.getElementById('leads-selected-bar') || document);
        var pendingLeadId = window._pendingOpenLeadId;
        if (pendingLeadId) {
          window._pendingOpenLeadId = null;
          selectLead(pendingLeadId, true);
        } else {
          var selId = CAData.getSelectedLeadId();
          if (selId) {
            highlightLeadSelection(selId);
            updateSelectedLeadBar(getLeadRecord(selId));
          } else {
            updateSelectedLeadBar(null);
          }
        }
        icons();
      };
      if (window.CA_LISTING_SEARCH && document.getElementById('leads-data-table') && allActive) {
        return reloadListing('ca_masters').then(function () {
          if (pipelineActive) return loadKanbanLeads();
          return null;
        }).then(finishHub);
      }
      if (pipelineActive) {
        return loadKanbanLeads().then(finishHub);
      }
      renderLeadsTable();
      finishHub();
    });
  }

  function renderLeadsTable(pageLeads) {
    var el = document.getElementById('leads-data-table');
    if (!el) return;

    if (pageLeads === undefined && window.CA_LISTING_SEARCH) {
      if (leadsHubLoading && !el.querySelector('tr.ca-table-row')) {
        setLeadsHubLoadingState(true);
      }
      reloadListing('ca_masters');
      return;
    }

    var leads = pageLeads || getRealLeadsFiltered();

    el.innerHTML = leads.length ? leads.map(function (l) {
      var data = JSON.stringify(CAData.leadToRowData(l)).replace(/'/g, '&#39;');
      var executive = l.executive && l.executive !== 'Unassigned'
        ? compactTextCell(l.executive)
        : '<span class="cam-cell-text cam-cell-empty">Unassigned</span>';
      return '<tr class="ca-table-row crm-table-row" data-lead-id="' + l.ca_id + '" data-row=\'' + data + '\'>' +
        renderInboxCheckCell('leads-data-table', l.ca_id) +
        '<td class="sticky-left-2 crm-td-firm font-medium text-slate-900">' + firmNameCell(l.firm_name) + '</td>' +
        '<td class="sticky-left-3 crm-td-ca font-medium text-slate-900">' + caNameCell(l.ca_name) + '</td>' +
        '<td class="crm-td-mobile">' + camPhoneCell(l.mobile_no) + '</td>' +
        renderLeadCallLogQuickCell(l) +
        '<td class="crm-td-mobile">' + camPhoneCell(l.alternate_mobile_no) + '</td>' +
        '<td class="crm-td-geo">' + compactTextCell(l.city) + '</td>' +
        '<td class="crm-td-geo">' + compactTextCell(l.stage) + '</td>' +
        '<td class="crm-td-status"><span class="cam-cell-badge">' + statusBadge(l.status) + '</span></td>' +
        '<td class="crm-td-person">' + executive + '</td>' +
        '<td class="crm-td-source">' + compactTextCell(l.source) + '</td>' +
        '<td class="crm-td-rating"><span class="cam-cell-rating">' + stars(l.priority) + '</span></td>' +
        '<td class="crm-td-date"><span class="cam-cell-text cam-cell-mono text-slate-500" title="' + escapeHtml(l.updated) + '">' + escapeHtml(l.updated) + '</span></td>' +
        renderLeadResearchQuickCell(l) +
        renderCrmRowActionsCell(l) +
      '</tr>';
    }).join('') : emptyTableRow(15, isEmployeeUser()
      ? 'No assigned leads yet. Leads assigned to you will appear here.'
      : 'No leads yet. Click Add Lead to create one.');
    bindLeadRows(el);
    bindCrmRowActions(el);
    bindCrmActionsDismiss();
    syncInboxChecks('leads-data-table');
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
      return '<tr class="ca-table-row crm-table-row" data-employee-id="' + e.employee_id + '">' +
        renderInboxCheckCell('employees-data-table', e.employee_id) +
        '<td class="sticky-left-2 font-medium">' + escapeHtml(e.name || '—') + '</td>' +
        '<td>' + escapeHtml(e.email_id || '—') + '</td>' +
        '<td>' + escapeHtml(e.mobile_no || '—') + '</td>' +
        '<td>' + escapeHtml(e.role || '—') + '</td>' +
        '<td>' + loginStatusBadge(e.login_status, e.login_status_label) + '</td>' +
        '<td>' + escapeHtml(e.city || '—') + '</td>' +
        '<td>' + formatDate(e.date_of_joining) + '</td>' +
        '<td><span class="badge-success">' + escapeHtml(e.status || '—') + '</span></td>' +
        renderEmployeeActionsCell(e) +
      '</tr>';
    }).join('') : '<tr><td colspan="10" class="text-center text-slate-500 p-4">No employees yet.</td></tr>';
    bindCrmRowActions(el);
    syncInboxChecks('employees-data-table');
  }

  function bindEmployeeRowActions(container) {
    bindCrmRowActions(container);
  }

  function getEmployeeActionItems() {
    var items = [];
    if (crmCanAction('employees', 'edit')) {
      items.push({ action: 'edit', label: 'Edit', icon: 'pencil' });
    }
    if (crmCanAction('employees', 'delete')) {
      items.push({ action: 'delete', label: 'Delete', icon: 'trash-2', danger: true });
    }
    return items;
  }

  function renderEmployeeActionsCell(employee) {
    if (!window.CAActionDropdown) return '<td class="crm-actions-cell text-right"><span class="cam-cell-empty">—</span></td>';
    return CAActionDropdown.renderCell(getEmployeeActionItems(), {
      scope: 'employee',
      rowId: employee.employee_id,
      cellClass: 'crm-actions-cell text-right',
      ariaLabel: 'Employee actions',
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
    openExclusiveCrmModal(modal);
  }

  function assignmentStatusBadge(status) {
    var normalized = String(status || 'Active').toLowerCase();
    if (normalized === 'active') return '<span class="badge badge-success">Active</span>';
    if (normalized === 'paused') return '<span class="badge badge-warning">Paused</span>';
    if (normalized === 'inactive') return '<span class="badge badge-neutral">Inactive</span>';
    return '<span class="badge badge-brand">' + escapeHtml(status || '—') + '</span>';
  }

  function getAssignmentActionItems(assignment) {
    var items = [];
    var status = String((assignment && assignment.status) || 'Active').toLowerCase();
    if (crmCanAction('assignment', 'view')) {
      items.push({ action: 'view', label: 'View', icon: 'eye' });
    }
    if (crmCanAction('assignment', 'create') || crmCanAction('assignment', 'edit')) {
      items.push({ action: 'reassign', label: 'Reassign', icon: 'user-check' });
    }
    if (crmCanAction('assignment', 'edit')) {
      if (status === 'active') {
        items.push({ action: 'pause', label: 'Pause Assignment', icon: 'pause-circle' });
      } else if (status === 'paused') {
        items.push({ action: 'resume', label: 'Resume Assignment', icon: 'play-circle' });
      }
    }
    if (crmCanAction('assignment', 'delete')) {
      items.push({ action: 'delete', label: 'Delete', icon: 'trash-2', danger: true });
    }
    return items;
  }

  function renderAssignmentActionsCell(assignment) {
    var id = assignment.assignment_id;
    var items = getAssignmentActionItems(assignment);
    if (!window.CAActionDropdown) {
      return '<td class="assign-col-more sticky-right"><span class="assign-cell-empty">—</span></td>';
    }
    return CAActionDropdown.renderCell(items, {
      scope: 'assignment',
      rowId: id,
      cellClass: 'assign-col-more sticky-right crm-actions-cell',
      ariaLabel: 'Assignment actions',
    });
  }

  function upsertAssignmentInCache(assignment) {
    if (!assignment || assignment.assignment_id == null) return;
    window.realAssignments = (window.realAssignments || []).map(function (a) {
      return String(a.assignment_id) === String(assignment.assignment_id)
        ? Object.assign({}, a, assignment)
        : a;
    });
  }

  function refreshAssignmentRowUi(assignment) {
    if (!assignment) return;
    var id = assignment.assignment_id;
    var row = document.querySelector('tr.assign-table-row[data-assignment-id="' + id + '"]');
    if (row) {
      var statusCell = row.querySelector('.assign-col-status');
      if (statusCell) statusCell.innerHTML = assignmentStatusBadge(assignment.status);
      var moreCell = row.querySelector('.assign-col-more');
      if (moreCell) {
        var temp = document.createElement('table');
        temp.innerHTML = '<tbody>' + renderAssignmentTableRow(assignment) + '</tbody>';
        var newMore = temp.querySelector('.assign-col-more');
        if (newMore) moreCell.replaceWith(newMore);
      }
    }
    var card = document.querySelector('.assign-mobile-card[data-assignment-id="' + id + '"]');
    if (card) {
      var tempCard = document.createElement('div');
      tempCard.innerHTML = renderAssignmentMobileCard(assignment);
      var newCard = tempCard.firstElementChild;
      if (newCard) card.replaceWith(newCard);
    }
    icons();
  }

  function setAssignmentStatus(assignmentId, status) {
    return apiFetch('/lead-assignments/' + encodeURIComponent(assignmentId) + '/status', {
      method: 'PATCH',
      body: JSON.stringify({ status: status }),
    }).then(function (body) {
      var updated = body.data || body;
      var merged = Object.assign({}, (window.realAssignments || []).find(function (a) {
        return String(a.assignment_id) === String(assignmentId);
      }) || {}, updated);
      upsertAssignmentInCache(merged);
      refreshAssignmentRowUi(merged);
      invalidateDataCaches(['metrics', 'segment_counts']);
      refreshAssignmentDashboardWidgets();
      toast(status === 'Paused' ? 'Assignment paused' : 'Assignment resumed', 'success');
      return merged;
    });
  }

  window._assignmentBulkSelected = window._assignmentBulkSelected || {};

  function getAssignmentBulkSelectedIds() {
    return Object.keys(window._assignmentBulkSelected || {}).filter(function (id) {
      return window._assignmentBulkSelected[id];
    });
  }

  function updateAssignmentBulkBar() {
    var ids = getAssignmentBulkSelectedIds();
    var bar = document.getElementById('assignment-bulk-bar');
    var countEl = document.getElementById('assignment-bulk-count');
    if (countEl) countEl.textContent = ids.length + ' selected';
    if (bar) {
      bar.classList.toggle('hidden', ids.length === 0);
      if (ids.length > 0) icons();
    }
  }

  function clearAssignmentBulkSelection() {
    window._assignmentBulkSelected = {};
    document.querySelectorAll('.assignment-row-check').forEach(function (cb) { cb.checked = false; });
    var selectAll = document.getElementById('assignment-select-all');
    if (selectAll) selectAll.checked = false;
    updateAssignmentBulkBar();
  }

  function updateAssignmentTotalLabel(pagination, assignments) {
    var label = document.getElementById('assignment-total-label');
    if (!label) return;
    var total = pagination && pagination.total != null
      ? pagination.total
      : (assignments ? assignments.length : 0);
    label.textContent = 'Showing: ' + total + ' Assignment' + (total === 1 ? '' : 's');
  }

  function customizeAssignmentPaginationSummary(pagination) {
    if (!pagination || !window.CATablePagination) return;
  }

  function assignLeadCell(name) {
    var raw = name == null || String(name).trim() === '' ? '' : String(name).trim();
    if (!raw) return '<span class="assign-cell-empty">—</span>';
    var escaped = escapeHtml(raw);
    return '<span class="assign-lead-name crm-firm-cell" title="' + escaped + '">' + escaped + '</span>';
  }

  function assignTextCell(text) {
    var raw = text == null || String(text).trim() === '' ? '' : String(text).trim();
    if (!raw) return '<span class="assign-cell-empty">—</span>';
    var escaped = escapeHtml(raw);
    return '<span class="assign-cell-text" title="' + escaped + '">' + escaped + '</span>';
  }

  function renderAssignmentTableRow(a) {
    var checked = window._assignmentBulkSelected && window._assignmentBulkSelected[String(a.assignment_id)];
    return '<tr class="assign-table-row" data-assignment-id="' + a.assignment_id + '">' +
      '<td class="assign-col-check sticky-left"><input type="checkbox" class="assignment-row-check" data-assignment-id="' + a.assignment_id + '"' + (checked ? ' checked' : '') + ' aria-label="Select assignment" /></td>' +
      '<td class="assign-col-lead sticky-left-2 crm-td-firm">' + assignLeadCell(a.firm_name) + '</td>' +
      '<td class="assign-col-exec">' + assignTextCell(a.executive || a.employee_name) + '</td>' +
      '<td class="assign-col-num">' + escapeHtml(String(a.target_leads != null ? a.target_leads : '—')) + '</td>' +
      '<td class="assign-col-num">' + escapeHtml(String(a.achieved_leads != null ? a.achieved_leads : '—')) + '</td>' +
      '<td class="assign-col-status">' + assignmentStatusBadge(a.status) + '</td>' +
      '<td class="assign-col-date">' + escapeHtml(formatDate(a.assigned_date)) + '</td>' +
      renderAssignmentActionsCell(a) +
    '</tr>';
  }

  function renderAssignmentMobileCard(a) {
    var items = getAssignmentActionItems(a);
    var menuHtml = (window.CAActionDropdown && items.length)
      ? CAActionDropdown.renderInline(items, {
        scope: 'assignment',
        rowId: a.assignment_id,
        ariaLabel: 'Assignment actions',
      })
      : '';
    return '<article class="assign-mobile-card" data-assignment-id="' + a.assignment_id + '">' +
      '<div class="assign-mobile-card__top">' +
        '<label class="assign-mobile-card__check"><input type="checkbox" class="assignment-row-check" data-assignment-id="' + a.assignment_id + '"' +
          ((window._assignmentBulkSelected && window._assignmentBulkSelected[String(a.assignment_id)]) ? ' checked' : '') + ' aria-label="Select assignment" /></label>' +
        '<div class="assign-mobile-card__title">' +
          '<p class="assign-lead-name">' + escapeHtml(a.firm_name || '—') + '</p>' +
          '<p class="assign-mobile-card__exec">' + escapeHtml(a.executive || a.employee_name || '—') + '</p>' +
        '</div>' +
        menuHtml +
      '</div>' +
      '<div class="assign-mobile-card__meta">' +
        '<span>Target <strong>' + escapeHtml(String(a.target_leads != null ? a.target_leads : '—')) + '</strong></span>' +
        '<span>Achieved <strong>' + escapeHtml(String(a.achieved_leads != null ? a.achieved_leads : '—')) + '</strong></span>' +
        assignmentStatusBadge(a.status) +
        '<span class="assign-mobile-card__date">' + escapeHtml(formatDate(a.assigned_date)) + '</span>' +
      '</div>' +
    '</article>';
  }

  function renderAssignmentTable(pageAssignments, pagination) {
    var el = document.getElementById('assignment-data-table');
    var cards = document.getElementById('assignment-mobile-cards');
    if (!el) return;
    if (pageAssignments === undefined && window.CA_LISTING_SEARCH) {
      reloadListing('lead_assignments');
      return;
    }
    var assignments = pageAssignments || window.realAssignments || [];
    el.innerHTML = assignments.length
      ? assignments.map(renderAssignmentTableRow).join('')
      : '<tr><td colspan="8" class="text-center text-slate-500 p-6">No assignments found.</td></tr>';
    if (cards) {
      cards.innerHTML = assignments.length
        ? assignments.map(renderAssignmentMobileCard).join('')
        : '<p class="assign-mobile-empty">No assignments found.</p>';
    }
    updateAssignmentTotalLabel(pagination, assignments);
    bindAssignmentRowActions(el);
    if (cards) bindAssignmentRowActions(cards);
    bindCrmRowActions(document.getElementById('assignment-table-wrap'));
    icons();
  }

  function deleteAssignmentById(id) {
    if (!id) return Promise.resolve();
    return apiFetch('/lead-assignments/' + encodeURIComponent(id), { method: 'DELETE' });
  }

  function handleAssignmentMenuAction(action, assignmentId) {
    var assignment = (window.realAssignments || []).find(function (a) {
      return String(a.assignment_id) === String(assignmentId);
    });
    if (!assignment && action !== 'delete') {
      toast('Assignment not found', 'error');
      return;
    }
    if (action === 'view') {
      if (assignment.ca_id && typeof selectLead === 'function') {
        selectLead(assignment.ca_id, true);
      } else {
        toast('Lead details unavailable', 'warning');
      }
      return;
    }
    if (action === 'reassign') {
      openAssignmentFormForEdit(assignmentId);
      return;
    }
    if (action === 'pause') {
      setAssignmentStatus(assignmentId, 'Paused').catch(function (err) {
        toast(err.message || 'Unable to pause assignment', 'error');
      });
      return;
    }
    if (action === 'resume') {
      setAssignmentStatus(assignmentId, 'Active').catch(function (err) {
        toast(err.message || 'Unable to resume assignment', 'error');
      });
      return;
    }
    if (action === 'delete') {
      if (!window.confirm('Remove this assignment?')) return;
      deleteAssignmentById(assignmentId)
        .then(function () {
          toast('Assignment removed', 'success');
          delete window._assignmentBulkSelected[String(assignmentId)];
          realAssignmentsLoaded = false;
          refreshAll();
        })
        .catch(function (err) {
          toast(err.message || 'Unable to delete assignment', 'error');
        });
    }
  }

  function ensureAssignmentActionsDelegated() {
    bindCrmRowActions(document);
  }

  function bindAssignmentRowActions(container) {
    if (!container) return;
    ensureAssignmentActionsDelegated();
    container.querySelectorAll('.assignment-row-check').forEach(function (cb) {
      if (cb._assignCheckBound) return;
      cb._assignCheckBound = true;
      cb.addEventListener('change', function () {
        var id = cb.getAttribute('data-assignment-id');
        if (!window._assignmentBulkSelected) window._assignmentBulkSelected = {};
        if (cb.checked) window._assignmentBulkSelected[id] = true;
        else delete window._assignmentBulkSelected[id];
        updateAssignmentBulkBar();
        var selectAll = document.getElementById('assignment-select-all');
        if (selectAll) {
          var pageChecks = Array.prototype.slice.call(document.querySelectorAll('#assignment-data-table .assignment-row-check, #assignment-mobile-cards .assignment-row-check'));
          var uniqueIds = {};
          pageChecks.forEach(function (item) { uniqueIds[item.dataset.assignmentId] = item.checked; });
          var values = Object.keys(uniqueIds).map(function (key) { return uniqueIds[key]; });
          selectAll.checked = values.length > 0 && values.every(Boolean);
          selectAll.indeterminate = values.some(Boolean) && !values.every(Boolean);
        }
      });
    });
  }

  function populateAssignmentExecutiveFilter() {
    var sel = document.getElementById('assignment-executive-filter');
    if (!sel) return;
    enhanceEntityLookups(sel.parentElement || document);
  }

  function applyAssignmentActiveFilters() {
    if (!window.CA_LISTING_SEARCH) return;
    var search = document.getElementById('assignment-search');
    var status = document.getElementById('assignment-status-filter');
    var executive = document.getElementById('assignment-executive-filter');
    var type = document.getElementById('assignment-type-filter');
    var filters = {};
    if (status && status.value) filters.status = status.value;
    if (executive && executive.value) filters.employee_id = executive.value;
    if (type && type.value) filters.assignment_type = type.value;
    CA_LISTING_SEARCH.setState('lead_assignments', {
      search: search ? search.value.trim() : '',
      page: 1,
      filters: filters,
    });
    reloadListing('lead_assignments');
  }

  function resetAssignmentActiveFilters() {
    if (!window.CA_LISTING_SEARCH) return;
    ['assignment-search', 'assignment-status-filter', 'assignment-executive-filter', 'assignment-type-filter'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      if (el.tagName === 'SELECT') {
        if (el.dataset.crmEntityLookup && window.CrmEntityLookup) {
          window.CrmEntityLookup.setValue(el, '', null);
        } else {
          el.selectedIndex = 0;
        }
      } else {
        el.value = '';
      }
    });
    CA_LISTING_SEARCH.clearState('lead_assignments');
    reloadListing('lead_assignments');
  }

  function bindAssignmentActiveSection() {
    var toolbar = document.querySelector('[data-listing-toolbar="lead_assignments"]');
    if (!toolbar || toolbar._assignmentActiveBound) return;
    toolbar._assignmentActiveBound = true;

    var debounceTimer = null;
    var search = document.getElementById('assignment-search');
    if (search) {
      search.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(applyAssignmentActiveFilters, 300);
      });
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          applyAssignmentActiveFilters();
        }
      });
    }
    ['assignment-status-filter', 'assignment-executive-filter', 'assignment-type-filter'].forEach(function (id) {
      document.getElementById(id)?.addEventListener('change', applyAssignmentActiveFilters);
    });
    document.getElementById('assignment-filters-reset')?.addEventListener('click', function () {
      resetAssignmentActiveFilters();
    });
    document.getElementById('assignment-export-btn')?.addEventListener('click', function () {
      if (window.CA_LISTING_SEARCH) CA_LISTING_SEARCH.exportListing('lead_assignments');
    });

    var selectAll = document.getElementById('assignment-select-all');
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        if (!window._assignmentBulkSelected) window._assignmentBulkSelected = {};
        document.querySelectorAll('#assignment-data-table .assignment-row-check').forEach(function (cb) {
          cb.checked = selectAll.checked;
          var id = cb.getAttribute('data-assignment-id');
          if (selectAll.checked) window._assignmentBulkSelected[id] = true;
          else delete window._assignmentBulkSelected[id];
        });
        document.querySelectorAll('#assignment-mobile-cards .assignment-row-check').forEach(function (cb) {
          cb.checked = selectAll.checked;
        });
        selectAll.indeterminate = false;
        updateAssignmentBulkBar();
      });
    }

    ensureInboxSelectionBound();
  }

  function canViewAssignmentDashboardWidgets() {
    var role = (window.__CRM_USER__ || {}).role || 'employee';
    return role === 'super_admin' || role === 'admin' || role === 'manager';
  }

  function assignmentCapacityBarClass(tier) {
    if (tier === 'red') return 'assign-capacity-bar__fill--red';
    if (tier === 'yellow') return 'assign-capacity-bar__fill--yellow';
    return 'assign-capacity-bar__fill--green';
  }

  function canEditAssignmentCapacity() {
    var role = (window.__CRM_USER__ || {}).role || 'employee';
    return role === 'super_admin' || role === 'manager';
  }

  function renderAssignmentCapacityWidget(data) {
    var listEl = document.getElementById('assign-capacity-list');
    var dateEl = document.getElementById('assign-capacity-date');
    var editWrap = document.getElementById('assign-capacity-edit');
    var maxInput = document.getElementById('assign-capacity-max-input');
    if (!listEl) return;

    data = data || {};
    var employees = data.employees || [];
    var canEdit = !!(data.can_edit_capacity || canEditAssignmentCapacity());

    if (editWrap) {
      editWrap.classList.toggle('hidden', !canEdit);
    }
    if (maxInput && data.max_daily_capacity != null) {
      maxInput.value = String(data.max_daily_capacity);
    }

    if (dateEl) {
      dateEl.textContent = data.date ? ('Today · ' + formatDate(data.date)) : 'Today';
    }

    if (!employees.length) {
      listEl.innerHTML = '<p class="assign-widget__empty">No active employees to display.</p>';
      return;
    }

    listEl.innerHTML = employees.map(function (row) {
      var pct = Math.min(100, row.percentage || 0);
      var barClass = assignmentCapacityBarClass(row.capacity_tier);
      var badge = row.at_full_capacity
        ? '<span class="assign-capacity-item__badge" title="' + escapeHtml(row.tooltip || 'Daily assignment limit reached.') + '">Full Capacity</span>'
        : '';
      return '<article class="assign-capacity-item">' +
        '<div class="assign-capacity-item__head">' +
          '<span class="assign-capacity-item__name">' + escapeHtml(row.name || 'Employee') + '</span>' +
          badge +
        '</div>' +
        '<p class="assign-capacity-item__label">Today\'s Capacity</p>' +
        '<div class="assign-capacity-item__stats">' +
          '<span class="assign-capacity-item__ratio">' + (row.assigned_today || 0) + ' / ' + (row.max_daily_capacity || 0) + '</span>' +
          '<span class="assign-capacity-item__remaining">' + (row.remaining_capacity || 0) + ' remaining</span>' +
          '<span class="assign-capacity-item__pct">' + pct + '%</span>' +
        '</div>' +
        '<div class="assign-capacity-bar" title="' + escapeHtml(row.tooltip || '') + '">' +
          '<div class="assign-capacity-bar__fill ' + barClass + '" style="width:' + pct + '%"></div>' +
        '</div>' +
      '</article>';
    }).join('');
  }

  function populateAssignmentHeatMapFilters(options) {
    options = options || {};
    var employeeSel = document.getElementById('assign-heatmap-employee');
    var stateSel = document.getElementById('assign-heatmap-state');
    var sourceSel = document.getElementById('assign-heatmap-source');
    if (!employeeSel && !stateSel && !sourceSel) return;

    var employees = options.employees || [];
    var states = options.states || [];
    var sources = options.sources || [];

    if (employeeSel) {
      var employeeVal = employeeSel.value;
      employeeSel.innerHTML = '<option value="">All Employees</option>' + employees.map(function (item) {
        return '<option value="' + item.employee_id + '">' + escapeHtml(item.name) + '</option>';
      }).join('');
      if (employeeVal) employeeSel.value = employeeVal;
    }

    if (stateSel) {
      var stateVal = stateSel.value;
      stateSel.innerHTML = '<option value="">All States</option>' + states.map(function (item) {
        return '<option value="' + item.state_id + '">' + escapeHtml(item.state_name) + '</option>';
      }).join('');
      if (stateVal) stateSel.value = stateVal;
    }

    if (sourceSel) {
      var sourceVal = sourceSel.value;
      sourceSel.innerHTML = '<option value="">All Sources</option>' + sources.map(function (item) {
        return '<option value="' + item.source_id + '">' + escapeHtml(item.source_name) + '</option>';
      }).join('');
      if (sourceVal) sourceSel.value = sourceVal;
    }
  }

  function assignmentHeatMapQueryParams() {
    var period = (document.getElementById('assign-heatmap-period') || {}).value || 'today';
    var params = {
      period: period,
      sort: (document.getElementById('assign-heatmap-sort') || {}).value || 'highest',
    };
    var employeeId = (document.getElementById('assign-heatmap-employee') || {}).value;
    var stateId = (document.getElementById('assign-heatmap-state') || {}).value;
    var sourceId = (document.getElementById('assign-heatmap-source') || {}).value;
    if (employeeId) params.employee_id = employeeId;
    if (stateId) params.state_id = stateId;
    if (sourceId) params.source_id = sourceId;
    if (period === 'custom') {
      params.from = (document.getElementById('assign-heatmap-from') || {}).value || '';
      params.to = (document.getElementById('assign-heatmap-to') || {}).value || '';
    }
    return params;
  }

  function renderAssignmentHeatMapWidget(data) {
    var listEl = document.getElementById('assign-heatmap-list');
    var summaryEl = document.getElementById('assign-heatmap-summary');
    if (!listEl) return;

    data = data || {};
    var cities = data.cities || [];
    var maxTotal = cities.reduce(function (max, row) {
      return Math.max(max, row.total_assigned || 0);
    }, 0);

    if (summaryEl) {
      var range = data.date_range || {};
      summaryEl.textContent = (range.label || 'Today') + ' · ' + (data.total_assignments || 0) + ' total assignments';
    }

    if (!cities.length) {
      listEl.innerHTML = '<p class="assign-widget__empty">No assignments found for the selected filters.</p>';
      return;
    }

    listEl.innerHTML = cities.map(function (row) {
      var total = row.total_assigned || 0;
      var pct = row.percentage != null ? row.percentage : 0;
      var barWidth = maxTotal > 0 ? Math.max(4, Math.round((total / maxTotal) * 100)) : 0;
      var barClass = total >= maxTotal * 0.9 ? 'assign-heatmap-bar__fill--red'
        : total >= maxTotal * 0.7 ? 'assign-heatmap-bar__fill--yellow'
        : 'assign-heatmap-bar__fill--green';
      var cityId = row.city_id || 0;
      return '<div class="assign-heatmap-row">' +
        '<button type="button" class="assign-heatmap-row__city" data-heatmap-city-id="' + cityId + '" data-heatmap-city-name="' + escapeHtml(row.city || 'Unknown') + '">' +
          escapeHtml(row.city || 'Unknown') +
        '</button>' +
        '<div class="assign-heatmap-bar" aria-hidden="true">' +
          '<div class="assign-heatmap-bar__fill ' + barClass + '" style="width:' + barWidth + '%"></div>' +
        '</div>' +
        '<span class="assign-heatmap-row__meta">' + total + ' Leads · ' + pct + '%</span>' +
      '</div>';
    }).join('');
  }

  function openMasterDataForHeatMapCity(cityId) {
    if (!cityId) return;
    if (window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.setState('ca_masters', {
        page: 1,
        search: '',
        filters: { city_id: String(cityId) },
      });
    }
    if (typeof navigateTo === 'function') navigateTo('ca-master');
  }

  function loadAssignmentCapacityWidget() {
    if (!canViewAssignmentDashboardWidgets()) return Promise.resolve();
    var root = document.getElementById('assign-capacity-widget');
    if (!root) return Promise.resolve();
    return apiFetch('/assignment-dashboard/capacity')
      .then(function (body) {
        renderAssignmentCapacityWidget(body.data || {});
      })
      .catch(function () {
        var listEl = document.getElementById('assign-capacity-list');
        if (listEl) listEl.innerHTML = '<p class="assign-widget__empty">Unable to load assignment capacity.</p>';
      });
  }

  function saveAssignmentCapacity() {
    var input = document.getElementById('assign-capacity-max-input');
    var btn = document.getElementById('assign-capacity-save-btn');
    if (!input) return;
    var value = parseInt(input.value, 10);
    if (!value || value < 1 || value > 500) {
      toast('Enter a daily capacity between 1 and 500.', 'error');
      return;
    }
    if (btn) btn.disabled = true;
    apiFetch('/assignment-dashboard/capacity', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ daily_max_capacity: value }),
    })
      .then(function (body) {
        renderAssignmentCapacityWidget(body.data || {});
        toast('Assignment capacity updated', 'success');
      })
      .catch(function (err) {
        toast(err.message || 'Unable to update assignment capacity', 'error');
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function loadAssignmentHeatMapWidget() {
    if (!canViewAssignmentDashboardWidgets()) return Promise.resolve();
    var root = document.getElementById('assign-heatmap-widget');
    if (!root) return Promise.resolve();
    var params = assignmentHeatMapQueryParams();
    var qs = Object.keys(params).filter(function (key) { return params[key]; }).map(function (key) {
      return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
    }).join('&');
    return apiFetch('/assignment-dashboard/heat-map' + (qs ? ('?' + qs) : ''))
      .then(function (body) {
        var data = body.data || {};
        populateAssignmentHeatMapFilters(data.filter_options || {});
        renderAssignmentHeatMapWidget(data);
      })
      .catch(function (error) {
        var listEl = document.getElementById('assign-heatmap-list');
        if (listEl) {
          listEl.innerHTML = '<p class="assign-widget__empty">' + escapeHtml(error.message || 'Unable to load heat map.') + '</p>';
        }
      });
  }

  function refreshAssignmentDashboardWidgets() {
    if (!canViewAssignmentDashboardWidgets()) return Promise.resolve();
    return Promise.all([
      loadAssignmentHeatMapWidget(),
      refreshYearlyEmployeeTargets(),
    ]);
  }

  var yearlyEmployeeTargetsState = { items: [], summary: null, targetWorkingDays: null, loading: false };

  function canEditYearlyEmployeeTargets() {
    var role = (window.__CRM_USER__ || {}).role || 'employee';
    return role === 'super_admin' || role === 'manager';
  }

  function canManageYearlyEmployeeTargets() {
    return canEditYearlyEmployeeTargets();
  }

  function yearlyTargetQueryParams() {
    var yearEl = document.getElementById('assign-yearly-target-year');
    var employee = document.getElementById('assign-yearly-target-employee-filter');
    var params = { year: yearEl ? yearEl.value : new Date().getFullYear() };
    if (employee && employee.value) params.employee_id = employee.value;
    return params;
  }

  function renderYearlyEmployeeTargetsSummary(cards) {
    var el = document.getElementById('assign-yearly-targets-summary');
    if (!el) return;
    cards = cards || {};
    var defs = [
      { key: 'employees_with_target', label: 'With Target' },
      { key: 'target_completed', label: 'Completed' },
      { key: 'target_in_progress', label: 'In Progress' },
      { key: 'target_missed', label: 'Missed' },
      { key: 'no_target_assigned', label: 'No Target' },
    ];
    el.innerHTML = defs.map(function (def) {
      return '<span class="assign-daily-targets-kpi">' + escapeHtml(def.label) + ' <span class="assign-daily-targets-kpi__value">' + escapeHtml(String(cards[def.key] ?? 0)) + '</span></span>';
    }).join('');
  }

  function renderYearlyEmployeeTargetsTable(items) {
    var tbody = document.getElementById('assign-yearly-targets-table');
    if (!tbody) return;
    items = items || yearlyEmployeeTargetsState.items || [];
    var statusFilter = (document.getElementById('assign-yearly-target-status-filter') || {}).value || '';
    var defaultWorkingDays = yearlyEmployeeTargetsState.targetWorkingDays;
    if (statusFilter) items = items.filter(function (row) { return (row.status || '') === statusFilter; });
    if (!items.length) {
      tbody.innerHTML = '<tr><td colspan="10" class="text-center text-slate-500 py-4 text-sm">No yearly targets found.</td></tr>';
      return;
    }
    tbody.innerHTML = items.map(function (row) {
      var hasTarget = !!row.has_target_record;
      var actions = '';
      if (canEditYearlyEmployeeTargets()) {
        if (hasTarget) {
          actions = '<button type="button" class="ca-icon-btn" data-yearly-target-edit="' + row.id + '" title="Edit"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></button>' +
            '<button type="button" class="ca-icon-btn" data-yearly-target-delete="' + row.id + '" title="Delete"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i></button>';
        } else {
          actions = '<button type="button" class="ca-icon-btn" data-yearly-target-assign="' + row.employee_id + '" title="Assign"><i data-lucide="target" class="h-3.5 w-3.5"></i></button>';
        }
      }
      var workingDays = hasTarget ? (row.target_working_days || row.standard_countable_days || defaultWorkingDays || '—') : (defaultWorkingDays || '—');
      return '<tr>' +
        '<td>' + escapeHtml(row.employee_name || '—') + '</td>' +
        '<td>' + escapeHtml(String(row.target_year || '—')) + '</td>' +
        '<td>' + escapeHtml(String(workingDays)) + '</td>' +
        '<td>' + (hasTarget ? escapeHtml(String(row.lead_target || 0)) : '—') + '</td>' +
        '<td>' + (hasTarget ? escapeHtml(String(row.call_target || 0)) : '—') + '</td>' +
        '<td>' + (hasTarget ? escapeHtml(String(row.demo_target || 0)) : '—') + '</td>' +
        '<td>' + (hasTarget ? escapeHtml(String(row.followup_target || 0)) : '—') + '</td>' +
        '<td>' + dailyTargetProgressCell(row) + '</td>' +
        '<td>' + (hasTarget ? '<span class="' + dailyTargetStatusClass(row.status) + '">' + escapeHtml(row.status_label || row.status || '') + '</span>' : '<span class="assign-daily-target-status assign-daily-target-status--no_target">No Target</span>') + '</td>' +
        '<td class="whitespace-nowrap">' + actions + '</td>' +
      '</tr>';
    }).join('');
    icons();
  }

  function updateYearlyTargetPreview() {
    var preview = document.getElementById('assign-yearly-target-preview');
    if (!preview) return;
    var year = parseInt((document.getElementById('assign-yearly-target-year-input') || {}).value || new Date().getFullYear(), 10);
    var workingDays = yearlyEmployeeTargetsState.targetWorkingDays;
    if (!workingDays || !year) {
      preview.classList.add('hidden');
      return;
    }
    var leads = parseInt((document.getElementById('assign-yearly-target-leads') || {}).value, 10) || 0;
    var calls = parseInt((document.getElementById('assign-yearly-target-calls') || {}).value, 10) || 0;
    var demos = parseInt((document.getElementById('assign-yearly-target-demos') || {}).value, 10) || 0;
    var followups = parseInt((document.getElementById('assign-yearly-target-followups') || {}).value, 10) || 0;
    function setText(id, value) {
      var el = document.getElementById(id);
      if (el) el.textContent = value;
    }
    setText('assign-yearly-preview-year', year);
    setText('assign-yearly-preview-days', workingDays);
    setText('assign-yearly-preview-leads', (leads * workingDays).toLocaleString('en-IN'));
    setText('assign-yearly-preview-calls', (calls * workingDays).toLocaleString('en-IN'));
    setText('assign-yearly-preview-demos', (demos * workingDays).toLocaleString('en-IN'));
    setText('assign-yearly-preview-followups', (followups * workingDays).toLocaleString('en-IN'));
    preview.classList.remove('hidden');
  }

  function fetchYearlyTargetWorkingDays(year) {
    var qs = 'year=' + encodeURIComponent(year);
    return apiFetch('/yearly-employee-targets/calendar-summary?' + qs).then(function (body) {
      var cal = (body.data || {}).calendar_summary || {};
      yearlyEmployeeTargetsState.targetWorkingDays = cal.target_working_days || cal.standard_countable_days || null;
      return yearlyEmployeeTargetsState.targetWorkingDays;
    }).catch(function () {
      yearlyEmployeeTargetsState.targetWorkingDays = null;
      return null;
    });
  }

  function openViewHolidaysModal() {
    var holidays = yearlyEmployeeTargetsState.holidays || [];
    var tbody = document.getElementById('view-company-holidays-table');
    if (tbody) {
      tbody.innerHTML = holidays.length ? holidays.map(function (h) {
        return '<tr><td>' + escapeHtml(h.name || '') + '</td><td>' + escapeHtml(h.display_date || h.holiday_date || '') + '</td><td>' +
          (h.falls_on_sunday ? 'Falls on Sunday (counted once)' : (h.is_movable ? 'Movable festival' : 'Fixed')) + '</td></tr>';
      }).join('') : '<tr><td colspan="3" class="text-center text-slate-500 py-3">No holidays configured.</td></tr>';
    }
    openModal(document.getElementById('modal-view-company-holidays'));
    icons();
  }

  function openEditHolidayDatesModal() {
    if (!canEditYearlyEmployeeTargets()) return;
    var year = (document.getElementById('assign-yearly-target-year') || {}).value || new Date().getFullYear();
    var list = document.getElementById('edit-holiday-dates-list');
    var holidays = yearlyEmployeeTargetsState.holidays || [];
    if (list) {
      list.innerHTML = holidays.map(function (h, idx) {
        var editable = h.is_movable || canEditYearlyEmployeeTargets();
        return '<div class="rounded-lg border border-slate-200 p-3 mb-2">' +
          '<p class="font-medium mb-1">' + escapeHtml(h.name || '') + '</p>' +
          (editable
            ? '<input type="date" class="input-field input-field-sm" data-edit-holiday-id="' + h.id + '" value="' + escapeHtml((h.holiday_date || '').slice(0, 10)) + '" />'
            : '<p class="text-caption text-slate-500">' + escapeHtml(h.display_date || '') + ' (fixed)</p>') +
        '</div>';
      }).join('');
    }
    openModal(document.getElementById('modal-edit-holiday-dates'));
    icons();
  }

  function saveHolidayDates(e) {
    if (e) e.preventDefault();
    if (!canEditYearlyEmployeeTargets()) return;
    var year = parseInt((document.getElementById('assign-yearly-target-year') || {}).value || new Date().getFullYear(), 10);
    var holidays = (yearlyEmployeeTargetsState.holidays || []).map(function (h) {
      var input = document.querySelector('[data-edit-holiday-id="' + h.id + '"]');
      return { id: h.id, holiday_date: input ? input.value : h.holiday_date };
    }).filter(function (row) { return row.holiday_date; });
    apiFetch('/yearly-employee-targets/holiday-dates', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ year: year, holidays: holidays }),
    }).then(function () {
      toast('Holiday dates saved', 'success');
      closeModal(document.getElementById('modal-edit-holiday-dates'));
      refreshYearlyEmployeeTargets(true);
    }).catch(function (err) { toast(err.message || 'Unable to save holiday dates', 'error'); });
  }

  function openEmployeeLeaveModal() {
    var employeeId = (document.getElementById('assign-yearly-target-employee-filter') || {}).value;
    if (!employeeId) {
      toast('Select an employee first.', 'warning');
      return;
    }
    var year = (document.getElementById('assign-yearly-target-year') || {}).value || new Date().getFullYear();
    yearlyEmployeeTargetsState.selectedLeaveEmployeeId = employeeId;
    apiFetch('/yearly-employee-targets/' + encodeURIComponent(employeeId) + '/leaves?year=' + encodeURIComponent(year)).then(function (body) {
      renderEmployeeLeaveTable((body.data || {}).items || [], !!(body.data || {}).can_manage);
      var formWrap = document.getElementById('form-employee-leave-request');
      if (formWrap) formWrap.classList.toggle('hidden', !canEditYearlyEmployeeTargets());
      openModal(document.getElementById('modal-employee-leave'));
      icons();
    }).catch(function (err) { toast(err.message || 'Unable to load leave records', 'error'); });
  }

  function renderEmployeeLeaveTable(items, canManage) {
    var tbody = document.getElementById('employee-leave-table');
    if (!tbody) return;
    tbody.innerHTML = items.length ? items.map(function (row) {
      var actions = '';
      if (canManage && row.status === 'pending') {
        actions = '<button type="button" class="btn-primary btn-xs" data-leave-approve="' + row.id + '">Approve</button> ' +
          '<button type="button" class="btn-secondary btn-xs" data-leave-reject="' + row.id + '">Reject</button>';
      }
      return '<tr><td>' + escapeHtml(row.leave_date || '') + '</td><td>' + escapeHtml(row.status || '') + '</td><td>' + escapeHtml(row.reason || '—') + '</td><td>' + actions + '</td></tr>';
    }).join('') : '<tr><td colspan="4" class="text-center text-slate-500 py-3">No leave records.</td></tr>';
  }

  function submitEmployeeLeaveRequest(e) {
    if (e) e.preventDefault();
    var employeeId = yearlyEmployeeTargetsState.selectedLeaveEmployeeId;
    if (!employeeId) return;
    var year = (document.getElementById('assign-yearly-target-year') || {}).value || new Date().getFullYear();
    apiFetch('/yearly-employee-targets/leaves', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        employee_id: parseInt(employeeId, 10),
        leave_date: document.getElementById('employee-leave-date').value,
        target_year: parseInt(year, 10),
        reason: document.getElementById('employee-leave-reason').value || null,
      }),
    }).then(function () {
      toast('Leave request submitted', 'success');
      openEmployeeLeaveModal();
      refreshYearlyEmployeeTargets(true);
    }).catch(function (err) { toast(err.message || 'Unable to submit leave', 'error'); });
  }

  function recalculateYearlyCalendars() {
    if (!canEditYearlyEmployeeTargets()) return;
    var params = yearlyTargetQueryParams();
    var qs = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
    apiFetch('/yearly-employee-targets/recalculate?' + qs, { method: 'POST' }).then(function () {
      toast('Yearly calendars recalculated', 'success');
      refreshYearlyEmployeeTargets(true);
    }).catch(function (err) { toast(err.message || 'Recalculation failed', 'error'); });
  }

  function openYearlyTargetModal(row, employeeId) {
    if (!canEditYearlyEmployeeTargets()) return;
    ensureFormSelectData(function () {
      populateSelects();
      var modal = document.getElementById('modal-assign-yearly-target');
      if (!modal) return;
      document.getElementById('assign-yearly-target-id').value = row && row.id ? row.id : '';
      var yearInput = document.getElementById('assign-yearly-target-year-input');
      var yearFilter = document.getElementById('assign-yearly-target-year');
      var selectedYear = (row && row.target_year) || (yearFilter ? yearFilter.value : new Date().getFullYear());
      if (yearInput) yearInput.value = selectedYear;
      resetYearlyTargetEmployeeField(row, employeeId);
      if (row && row.id) {
        document.getElementById('assign-yearly-target-leads').value = row.lead_target || 0;
        document.getElementById('assign-yearly-target-calls').value = row.call_target || 0;
        document.getElementById('assign-yearly-target-demos').value = row.demo_target || 0;
        document.getElementById('assign-yearly-target-followups').value = row.followup_target || 0;
        document.getElementById('assign-yearly-target-notes').value = row.notes || '';
        document.getElementById('assign-yearly-target-title').textContent = 'Edit Yearly Target';
      } else {
        ['leads', 'calls', 'demos', 'followups'].forEach(function (k) {
          var el = document.getElementById('assign-yearly-target-' + k);
          if (el) el.value = 0;
        });
        document.getElementById('assign-yearly-target-notes').value = '';
        document.getElementById('assign-yearly-target-title').textContent = 'Assign Yearly Target';
      }
      fetchYearlyTargetWorkingDays(selectedYear).then(function () {
        updateYearlyTargetPreview();
        openModal(modal);
        icons();
      });
    });
  }

  function submitYearlyTargetForm(e) {
    if (e) e.preventDefault();
    if (!canEditYearlyEmployeeTargets()) return;
    var id = document.getElementById('assign-yearly-target-id').value;
    var select = document.getElementById('assign-yearly-target-employee');
    var hidden = document.getElementById('assign-yearly-target-employee-id');
    if (select && hidden && window.CrmEntityLookup) {
      var record = window.CrmEntityLookup.get(select) ? window.CrmEntityLookup.get(select).getSelectedRecord() : null;
      if (record && record.employee_id) hidden.value = String(record.employee_id);
      else if (select.value) hidden.value = String(select.value);
    }
    var employeeId = (hidden && hidden.value) || (select && select.value);
    if (!employeeId) {
      toast('Employee is required.', 'warning');
      return;
    }
    var payload = {
      employee_id: parseInt(employeeId, 10),
      target_year: parseInt(document.getElementById('assign-yearly-target-year-input').value, 10),
      lead_target: parseInt(document.getElementById('assign-yearly-target-leads').value, 10) || 0,
      call_target: parseInt(document.getElementById('assign-yearly-target-calls').value, 10) || 0,
      demo_target: parseInt(document.getElementById('assign-yearly-target-demos').value, 10) || 0,
      followup_target: parseInt(document.getElementById('assign-yearly-target-followups').value, 10) || 0,
      email_target: 0,
      sms_target: 0,
      notes: document.getElementById('assign-yearly-target-notes').value || '',
    };
    var url = id ? '/yearly-employee-targets/' + encodeURIComponent(id) : '/yearly-employee-targets';
    apiFetch(url, {
      method: id ? 'PUT' : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then(function () {
      toast('Yearly target saved', 'success');
      closeModal(document.getElementById('modal-assign-yearly-target'));
      refreshYearlyEmployeeTargets(true);
    }).catch(function (err) {
      toast(err.message || 'Unable to save yearly target', 'error');
    });
  }

  function deleteYearlyTarget(id) {
    if (!canEditYearlyEmployeeTargets() || !id) return;
    if (!confirm('Delete this yearly target and its generated calendar?')) return;
    apiFetch('/yearly-employee-targets/' + encodeURIComponent(id), { method: 'DELETE' })
      .then(function () {
        toast('Yearly target deleted', 'success');
        refreshYearlyEmployeeTargets(true);
      })
      .catch(function (err) { toast(err.message || 'Delete failed', 'error'); });
  }

  function populateYearlyTargetEmployeeFilter() {
    var select = document.getElementById('assign-yearly-target-employee-filter');
    if (!select || select.dataset.populated === '1') return;
    var employees = (window.realEmployees || []).slice().sort(function (a, b) {
      return String(a.name || '').localeCompare(String(b.name || ''));
    });
    select.innerHTML = '<option value="">All Employees</option>' + employees.map(function (emp) {
      return '<option value="' + emp.employee_id + '">' + escapeHtml(emp.name || ('Employee #' + emp.employee_id)) + '</option>';
    }).join('');
    select.dataset.populated = '1';
  }

  function resetYearlyTargetEmployeeField(row, employeeId) {
    var select = document.getElementById('assign-yearly-target-employee');
    var hidden = document.getElementById('assign-yearly-target-employee-id');
    var empId = (row && row.employee_id) || employeeId || '';
    if (select && window.CrmEntityLookup) {
      if (empId) window.CrmEntityLookup.setValue(select, String(empId));
      else window.CrmEntityLookup.setValue(select, '', null);
    } else if (select) {
      select.value = empId ? String(empId) : '';
    }
    if (hidden) hidden.value = empId ? String(empId) : '';
  }

  function loadYearlyEmployeeTargets() {
    var params = yearlyTargetQueryParams();
    var qs = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
    return Promise.all([
      apiFetch('/yearly-employee-targets/summary?' + qs),
      apiFetch('/yearly-employee-targets?' + qs),
    ]).then(function (results) {
      var summaryData = results[0].data || {};
      var listData = results[1].data || {};
      yearlyEmployeeTargetsState.summary = summaryData;
      yearlyEmployeeTargetsState.items = listData.items || summaryData.items || [];
      yearlyEmployeeTargetsState.targetWorkingDays = summaryData.target_working_days || yearlyEmployeeTargetsState.targetWorkingDays;
      populateYearlyTargetEmployeeFilter();
      renderYearlyEmployeeTargetsSummary(summaryData.cards || {});
      renderYearlyEmployeeTargetsTable(yearlyEmployeeTargetsState.items);
    }).catch(function () {
      var tbody = document.getElementById('assign-yearly-targets-table');
      if (tbody) tbody.innerHTML = '<tr><td colspan="10" class="text-center text-slate-500 py-4 text-sm">Unable to load yearly targets.</td></tr>';
    });
  }

  function refreshYearlyEmployeeTargets(force) {
    var section = document.getElementById('assign-yearly-targets-section');
    if (!section) return Promise.resolve();
    if (!canViewAssignmentDashboardWidgets()) {
      section.classList.add('hidden');
      return Promise.resolve();
    }
    section.classList.remove('hidden');
    bindYearlyEmployeeTargetsUi();
    var openBtn = document.getElementById('assign-yearly-target-open-modal');
    if (openBtn) openBtn.classList.toggle('hidden', !canEditYearlyEmployeeTargets());
    var year = (document.getElementById('assign-yearly-target-year') || {}).value || new Date().getFullYear();
    return fetchYearlyTargetWorkingDays(year).then(function () {
      return loadYearlyEmployeeTargets();
    });
  }

  function bindYearlyEmployeeTargetsUi() {
    var section = document.getElementById('assign-yearly-targets-section');
    if (!section || section._yearlyBound) return;
    section._yearlyBound = true;
    var form = document.getElementById('form-assign-yearly-target');
    if (form) form.addEventListener('submit', submitYearlyTargetForm);
    ['assign-yearly-target-leads', 'assign-yearly-target-calls', 'assign-yearly-target-demos', 'assign-yearly-target-followups'].forEach(function (id) {
      document.getElementById(id)?.addEventListener('input', updateYearlyTargetPreview);
    });
    document.getElementById('assign-yearly-target-year-input')?.addEventListener('change', function (e) {
      fetchYearlyTargetWorkingDays(e.target.value).then(updateYearlyTargetPreview);
    });
    ['assign-yearly-target-year', 'assign-yearly-target-employee-filter', 'assign-yearly-target-status-filter'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('change', function () {
        if (id === 'assign-yearly-target-status-filter') renderYearlyEmployeeTargetsTable(yearlyEmployeeTargetsState.items);
        else refreshYearlyEmployeeTargets(true);
      });
    });
    section.addEventListener('click', function (e) {
      if (e.target.closest('#assign-yearly-target-open-modal')) {
        e.preventDefault();
        openYearlyTargetModal(null);
        return;
      }
      var editBtn = e.target.closest('[data-yearly-target-edit]');
      if (editBtn) {
        var editId = editBtn.getAttribute('data-yearly-target-edit');
        var row = yearlyEmployeeTargetsState.items.find(function (r) { return String(r.id) === String(editId); });
        openYearlyTargetModal(row);
        return;
      }
      var delBtn = e.target.closest('[data-yearly-target-delete]');
      if (delBtn) deleteYearlyTarget(delBtn.getAttribute('data-yearly-target-delete'));
      var assignBtn = e.target.closest('[data-yearly-target-assign]');
      if (assignBtn) openYearlyTargetModal(null, assignBtn.getAttribute('data-yearly-target-assign'));
    });
  }

  var dailyEmployeeTargetsState = {
    items: [],
    summary: null,
    history: [],
    loading: false,
  };

  function canManageDailyEmployeeTargets() {
    return crmCanAction('assignment', 'assign');
  }

  function dailyTargetStatusClass(status) {
    return 'assign-daily-target-status assign-daily-target-status--' + String(status || 'not_started').replace(/[^a-z_]/g, '_');
  }

  function dailyTargetMetricCompact(metric, hasTarget) {
    if (!hasTarget) return '<span class="assign-daily-target-metric-compact assign-daily-target-metric-compact--empty">—</span>';
    metric = metric || {};
    return '<span class="assign-daily-target-metric-compact">' + (metric.completed || 0) + '/' + (metric.target || 0) + '</span>';
  }

  function dailyTargetProgressCell(row) {
    if (!row.has_target_record) return '<span class="assign-daily-target-metric-compact assign-daily-target-metric-compact--empty">—</span>';
    var pct = Math.min(100, Number(row.overall_pct || 0));
    return '<div class="assign-daily-target-progress">' +
      '<div class="assign-daily-target-progress__pct">' + pct + '%</div>' +
      '<div class="assign-daily-target-bar"><div class="assign-daily-target-bar__fill" style="width:' + pct + '%"></div></div>' +
    '</div>';
  }

  function formatDailyTargetDate(value) {
    if (!value) return '—';
    var d = new Date(String(value).slice(0, 10) + 'T12:00:00');
    if (isNaN(d.getTime())) return String(value);
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  function dailyTargetMetricSummary(metrics, key) {
    var metric = (metrics || []).find(function (row) { return row.key === key; }) || {};
    return (metric.completed || 0) + ' / ' + (metric.target || 0);
  }

  function dailyTargetQueryParams() {
    var preset = document.getElementById('assign-target-preset');
    var customDate = document.getElementById('assign-target-custom-date');
    var employee = document.getElementById('assign-target-employee-filter');
    var params = {
      preset: preset ? preset.value : 'today',
    };
    if (params.preset === 'custom' && customDate && customDate.value) {
      params.from = customDate.value;
      params.to = customDate.value;
    }
    if (employee && employee.value) params.employee_id = employee.value;
    return params;
  }

  function renderDailyEmployeeTargetsSummary(cards) {
    var el = document.getElementById('assign-daily-targets-summary');
    if (!el) return;
    cards = cards || {};
    var defs = [
      { key: 'employees_with_target', label: 'With Target' },
      { key: 'target_completed', label: 'Completed' },
      { key: 'target_in_progress', label: 'In Progress' },
      { key: 'target_missed', label: 'Missed' },
      { key: 'no_target_assigned', label: 'No Target' },
    ];
    el.innerHTML = defs.map(function (def) {
      return '<span class="assign-daily-targets-kpi">' + escapeHtml(def.label) + ' <span class="assign-daily-targets-kpi__value">' + escapeHtml(String(cards[def.key] ?? 0)) + '</span></span>';
    }).join('');
  }

  function renderDailyEmployeeTargetsInsights() {
    /* Insights removed from compact card layout */
  }

  function renderDailyEmployeeTargetsTable(items) {
    var tbody = document.getElementById('assign-daily-targets-table');
    if (!tbody) return;
    var statusFilter = document.getElementById('assign-target-status-filter');
    var filterStatus = statusFilter ? statusFilter.value : '';
    var rows = (items || []).filter(function (row) {
      if (!filterStatus) return true;
      return String(row.status || '') === filterStatus;
    });
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="9" class="text-center text-slate-500 py-6">No targets found for the selected filters.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(function (row) {
      var metrics = row.metrics || [];
      var hasTarget = !!row.has_target_record;
      var actions = '';
      if (canManageDailyEmployeeTargets() && hasTarget) {
        actions = '<button type="button" class="ca-icon-btn" data-daily-target-edit="' + row.id + '" title="Edit"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></button>' +
          '<button type="button" class="ca-icon-btn" data-daily-target-delete="' + row.id + '" title="Delete"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i></button>';
      } else if (canManageDailyEmployeeTargets() && !hasTarget) {
        actions = '<button type="button" class="ca-icon-btn" data-daily-target-assign-employee="' + row.employee_id + '" title="Assign"><i data-lucide="target" class="h-3.5 w-3.5"></i></button>';
      }
      return '<tr data-daily-target-row="' + (row.id || '') + '">' +
        '<td><strong>' + escapeHtml(row.employee_name || '—') + '</strong></td>' +
        '<td>' + escapeHtml(formatDailyTargetDate(row.target_date_label || row.target_date)) + '</td>' +
        '<td>' + dailyTargetMetricCompact(metrics.find(function (m) { return m.key === 'lead'; }), hasTarget) + '</td>' +
        '<td>' + dailyTargetMetricCompact(metrics.find(function (m) { return m.key === 'call'; }), hasTarget) + '</td>' +
        '<td>' + dailyTargetMetricCompact(metrics.find(function (m) { return m.key === 'demo'; }), hasTarget) + '</td>' +
        '<td>' + dailyTargetMetricCompact(metrics.find(function (m) { return m.key === 'followup'; }), hasTarget) + '</td>' +
        '<td>' + dailyTargetProgressCell(row) + '</td>' +
        '<td><span class="' + dailyTargetStatusClass(row.status) + '">' + escapeHtml(row.status_label || '—') + '</span></td>' +
        '<td class="whitespace-nowrap">' + (actions || '') + '</td>' +
      '</tr>';
    }).join('');
    iconsIn(tbody);
  }

  function renderDailyEmployeeTargetsHistory(items) {
    var tbody = document.getElementById('assign-daily-targets-history');
    if (!tbody) return;
    if (!items || !items.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center text-slate-500 py-4 text-sm">No history found.</td></tr>';
      return;
    }
    tbody.innerHTML = items.map(function (row) {
      return '<tr>' +
        '<td>' + escapeHtml(formatDailyTargetDate(row.target_date_label || row.target_date)) + '</td>' +
        '<td>' + escapeHtml(row.employee_name || '—') + '</td>' +
        '<td>' + dailyTargetMetricSummary(row.metrics, 'lead') + '</td>' +
        '<td>' + dailyTargetMetricSummary(row.metrics, 'call') + '</td>' +
        '<td>' + dailyTargetMetricSummary(row.metrics, 'demo') + '</td>' +
        '<td>' + dailyTargetMetricSummary(row.metrics, 'followup') + '</td>' +
        '<td>' + (row.overall_pct || 0) + '%</td>' +
        '<td><span class="' + dailyTargetStatusClass(row.status) + '">' + escapeHtml(row.status_label || '—') + '</span></td>' +
      '</tr>';
    }).join('');
  }

  function populateDailyTargetEmployeeFilter(items) {
    var select = document.getElementById('assign-target-employee-filter');
    if (!select || select.dataset.populated === '1') return;
    var employees = (window.realEmployees || []).slice().sort(function (a, b) {
      return String(a.name || '').localeCompare(String(b.name || ''));
    });
    select.innerHTML = '<option value="">All Employees</option>' + employees.map(function (emp) {
      return '<option value="' + emp.employee_id + '">' + escapeHtml(emp.name || ('Employee #' + emp.employee_id)) + '</option>';
    }).join('');
    select.dataset.populated = '1';
  }

  function resolveEntityLookupValue(select) {
    if (!select) return '';
    var value = String(select.value || '').trim();
    if (value) return value;
    var api = window.CrmEntityLookup ? window.CrmEntityLookup.get(select) : null;
    if (!api) return '';
    var record = api.getSelectedRecord ? api.getSelectedRecord() : null;
    if (!record) return '';
    var resolved = record.employee_id || record.ca_id || record.id || '';
    if (resolved) {
      select.value = String(resolved);
      var hidden = document.getElementById('assign-daily-target-employee-id');
      if (hidden && select.id === 'assign-daily-target-employee') hidden.value = String(resolved);
      return String(resolved);
    }
    return '';
  }

  function syncDailyTargetEmployeeId() {
    var select = document.getElementById('assign-daily-target-employee');
    var hidden = document.getElementById('assign-daily-target-employee-id');
    if (!hidden) return '';
    var resolved = resolveEntityLookupValue(select);
    hidden.value = resolved;
    return resolved;
  }

  function resetDailyTargetEmployeeField(row) {
    var select = document.getElementById('assign-daily-target-employee');
    var hidden = document.getElementById('assign-daily-target-employee-id');
    if (!select) return;
    if (window.CrmEntityLookup) {
      if (row && row.employee_id) {
        window.CrmEntityLookup.setValue(select, String(row.employee_id));
      } else {
        window.CrmEntityLookup.setValue(select, '', null);
      }
    } else {
      select.value = row && row.employee_id ? String(row.employee_id) : '';
    }
    if (hidden) hidden.value = row && row.employee_id ? String(row.employee_id) : '';
  }

  function ensureDailyTargetFormBound() {
    var form = document.getElementById('form-assign-daily-target');
    if (!form || form.dataset.dailyTargetBound === '1') return;
    form.dataset.dailyTargetBound = '1';
    form.addEventListener('submit', submitDailyTargetForm);
    var select = document.getElementById('assign-daily-target-employee');
    if (select && !select.dataset.dailyTargetSyncBound) {
      select.dataset.dailyTargetSyncBound = '1';
      select.addEventListener('change', syncDailyTargetEmployeeId);
    }
  }

  function resetDailyTargetForm(row) {
    var form = document.getElementById('form-assign-daily-target');
    if (!form) return;
    form.reset();
    document.getElementById('assign-daily-target-id').value = row && row.id ? row.id : '';
    document.getElementById('assign-daily-target-date').value = (row && row.target_date) || new Date().toISOString().slice(0, 10);
    resetDailyTargetEmployeeField(row || null);
    if (row) {
      document.getElementById('assign-daily-target-leads').value = row.lead_target || 0;
      document.getElementById('assign-daily-target-calls').value = row.call_target || 0;
      document.getElementById('assign-daily-target-demos').value = row.demo_target || 0;
      document.getElementById('assign-daily-target-followups').value = row.followup_target || 0;
      document.getElementById('assign-daily-target-email').value = row.email_target || 0;
      document.getElementById('assign-daily-target-sms').value = row.sms_target || 0;
      document.getElementById('assign-daily-target-notes').value = row.notes || '';
      document.getElementById('assign-daily-target-title').textContent = 'Edit Daily Target';
    } else {
      document.getElementById('assign-daily-target-leads').value = 0;
      document.getElementById('assign-daily-target-calls').value = 0;
      document.getElementById('assign-daily-target-demos').value = 0;
      document.getElementById('assign-daily-target-followups').value = 0;
      document.getElementById('assign-daily-target-email').value = 0;
      document.getElementById('assign-daily-target-sms').value = 0;
      document.getElementById('assign-daily-target-notes').value = '';
      document.getElementById('assign-daily-target-title').textContent = 'Assign Daily Target';
    }
    var copyTeamBtn = document.getElementById('assign-daily-target-copy-team');
    var copyWeekdaysBtn = document.getElementById('assign-daily-target-copy-weekdays');
    if (copyTeamBtn) copyTeamBtn.classList.toggle('hidden', !(row && row.id));
    if (copyWeekdaysBtn) copyWeekdaysBtn.classList.toggle('hidden', !(row && row.id));
  }

  function openDailyTargetModal(row) {
    if (!canManageDailyEmployeeTargets()) {
      toast('You do not have permission to assign targets.', 'error');
      return;
    }
    ensureDailyTargetFormBound();
    resetDailyTargetForm(row || null);
    ensureFormSelectData(function () {
      populateSelects();
      var modal = document.getElementById('modal-assign-daily-target');
      enhanceEntityLookups(modal || document);
      if (row && row.employee_id) {
        resetDailyTargetEmployeeField(row);
      }
      syncDailyTargetEmployeeId();
      if (modal) openExclusiveCrmModal(modal);
      icons();
    });
  }

  function submitDailyTargetForm(event) {
    event.preventDefault();
    if (!canManageDailyEmployeeTargets()) return;
    var employeeId = parseInt(syncDailyTargetEmployeeId(), 10);
    var employeeSelect = document.getElementById('assign-daily-target-employee');
    if (!employeeId || employeeId <= 0) {
      toast('Please select an employee from the search results.', 'warning');
      if (employeeSelect) {
        var lookupInput = employeeSelect.closest('.crm-entity-lookup')?.querySelector('.crm-entity-lookup__input');
        if (lookupInput) lookupInput.focus();
      }
      return;
    }
    var id = document.getElementById('assign-daily-target-id').value;
    var targetDate = document.getElementById('assign-daily-target-date').value;
    if (!targetDate) {
      toast('Target date is required.', 'warning');
      return;
    }
    var payload = {
      employee_id: employeeId,
      target_date: targetDate,
      lead_target: parseInt(document.getElementById('assign-daily-target-leads').value || '0', 10) || 0,
      call_target: parseInt(document.getElementById('assign-daily-target-calls').value || '0', 10) || 0,
      demo_target: parseInt(document.getElementById('assign-daily-target-demos').value || '0', 10) || 0,
      followup_target: parseInt(document.getElementById('assign-daily-target-followups').value || '0', 10) || 0,
      email_target: parseInt(document.getElementById('assign-daily-target-email').value || '0', 10) || 0,
      sms_target: parseInt(document.getElementById('assign-daily-target-sms').value || '0', 10) || 0,
      notes: document.getElementById('assign-daily-target-notes').value || null,
    };
    var jsonHeaders = { 'Content-Type': 'application/json' };
    var request = id
      ? apiFetch('/daily-employee-targets/' + id, { method: 'PUT', headers: jsonHeaders, body: JSON.stringify(payload) })
      : apiFetch('/daily-employee-targets', { method: 'POST', headers: jsonHeaders, body: JSON.stringify(payload) });
    request.then(function () {
      var modal = document.getElementById('modal-assign-daily-target');
      if (modal) closeModal(modal);
      toast(id ? 'Daily target updated' : 'Daily target assigned', 'success');
      refreshDailyEmployeeTargets(true);
    }).catch(function (err) {
      var message = err.message || 'Unable to save daily target.';
      if (/already exists/i.test(message) && !id) {
        if (window.confirm(message + '\n\nOpen the existing target for editing?')) {
          var existing = (dailyEmployeeTargetsState.items || []).find(function (row) {
            return String(row.employee_id) === String(payload.employee_id) && String(row.target_date) === String(payload.target_date);
          });
          if (existing) openDailyTargetModal(existing);
        }
        return;
      }
      toast(message, 'error');
    });
  }

  function deleteDailyTarget(id) {
    if (!canManageDailyEmployeeTargets()) return;
    if (!window.confirm('Delete this daily target?')) return;
    apiFetch('/daily-employee-targets/' + id, { method: 'DELETE' })
      .then(function () {
        toast('Daily target deleted', 'success');
        refreshDailyEmployeeTargets(true);
      })
      .catch(function (err) {
        toast(err.message || 'Unable to delete daily target.', 'error');
      });
  }

  function copyYesterdayDailyTargets() {
    if (!canManageDailyEmployeeTargets()) return;
    if (!window.confirm('Copy yesterday\'s targets to today for your team? Existing targets for today will be skipped unless you confirm overwrite.')) return;
    apiFetch('/daily-employee-targets/copy-yesterday', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ overwrite: false }),
    }).then(function (body) {
      var data = body.data || {};
      toast('Copied ' + (data.created || 0) + ' target(s). Skipped ' + (data.skipped || 0) + '.', 'success');
      refreshDailyEmployeeTargets(true);
    }).catch(function (err) {
      toast(err.message || 'Unable to copy targets.', 'error');
    });
  }

  function bindDailyEmployeeTargetsUi() {
    var section = document.getElementById('assign-daily-targets-section');
    if (!section || section.dataset.bound === '1') return;
    section.dataset.bound = '1';
    if (!canViewAssignmentDashboardWidgets()) {
      section.classList.add('hidden');
      return;
    }
    var actions = section.querySelector('.assign-daily-targets-actions');
    if (actions && !canManageDailyEmployeeTargets()) actions.classList.add('hidden');

    var preset = document.getElementById('assign-target-preset');
    var customDate = document.getElementById('assign-target-custom-date');
    if (preset) {
      preset.addEventListener('change', function () {
        if (customDate) customDate.classList.toggle('hidden', preset.value !== 'custom');
        refreshDailyEmployeeTargets(true);
      });
    }
    if (customDate) customDate.addEventListener('change', function () { refreshDailyEmployeeTargets(true); });
    ['assign-target-employee-filter', 'assign-target-status-filter'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('change', function () {
        if (id === 'assign-target-status-filter') renderDailyEmployeeTargetsTable(dailyEmployeeTargetsState.items);
        else refreshDailyEmployeeTargets(true);
      });
    });

    var openBtn = document.getElementById('assign-target-open-modal');
    if (openBtn) openBtn.addEventListener('click', function () { openDailyTargetModal(null); });
    var copyBtn = document.getElementById('assign-target-copy-yesterday');
    if (copyBtn) copyBtn.addEventListener('click', copyYesterdayDailyTargets);
    var copyTeamBtn = document.getElementById('assign-daily-target-copy-team');
    if (copyTeamBtn) copyTeamBtn.addEventListener('click', function () {
      var id = document.getElementById('assign-daily-target-id').value;
      if (!id || !window.confirm('Apply this target to the entire team for the same date? Existing targets will be skipped.')) return;
      apiFetch('/daily-employee-targets/copy-to-team', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ source_target_id: parseInt(id, 10), overwrite: false }),
      }).then(function (body) {
        toast('Applied to ' + ((body.data || {}).created || 0) + ' employee(s).', 'success');
        refreshDailyEmployeeTargets(true);
      }).catch(function (err) { toast(err.message || 'Copy failed.', 'error'); });
    });
    var copyWeekdaysBtn = document.getElementById('assign-daily-target-copy-weekdays');
    if (copyWeekdaysBtn) copyWeekdaysBtn.addEventListener('click', function () {
      var id = document.getElementById('assign-daily-target-id').value;
      if (!id || !window.confirm('Repeat this target on upcoming weekdays? Existing targets will be skipped.')) return;
      apiFetch('/daily-employee-targets/copy-weekdays', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ source_target_id: parseInt(id, 10), days: 5, overwrite: false }),
      }).then(function (body) {
        toast('Created ' + ((body.data || {}).created || 0) + ' weekday target(s).', 'success');
        refreshDailyEmployeeTargets(true);
      }).catch(function (err) { toast(err.message || 'Copy failed.', 'error'); });
    });
    ensureDailyTargetFormBound();

    section.addEventListener('click', function (event) {
      var editBtn = event.target.closest('[data-daily-target-edit]');
      if (editBtn) {
        var editId = editBtn.getAttribute('data-daily-target-edit');
        var editRow = (dailyEmployeeTargetsState.items || []).find(function (row) { return String(row.id) === String(editId); });
        openDailyTargetModal(editRow || { id: editId });
        return;
      }
      var deleteBtn = event.target.closest('[data-daily-target-delete]');
      if (deleteBtn) {
        deleteDailyTarget(deleteBtn.getAttribute('data-daily-target-delete'));
        return;
      }
      var assignBtn = event.target.closest('[data-daily-target-assign-employee]');
      if (assignBtn) {
        openDailyTargetModal({ employee_id: assignBtn.getAttribute('data-daily-target-assign-employee') });
      }
    });
  }

  function loadDailyEmployeeTargets() {
    if (!canViewAssignmentDashboardWidgets()) return Promise.resolve();
    var section = document.getElementById('assign-daily-targets-section');
    if (!section) return Promise.resolve();
    var params = dailyTargetQueryParams();
    var qs = Object.keys(params).map(function (key) {
      return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
    }).join('&');
    return Promise.all([
      apiFetch('/daily-employee-targets/summary?' + qs),
      apiFetch('/daily-employee-targets/history?' + qs + '&per_page=25'),
    ]).then(function (results) {
      var summaryBody = results[0] || {};
      var historyBody = results[1] || {};
      var summaryData = summaryBody.data || {};
      dailyEmployeeTargetsState.items = summaryData.items || [];
      dailyEmployeeTargetsState.summary = summaryData.cards || {};
      dailyEmployeeTargetsState.history = (historyBody.data || {}).items || [];
      renderDailyEmployeeTargetsSummary(summaryData.cards || {});
      renderDailyEmployeeTargetsInsights(summaryData.insights || {});
      renderDailyEmployeeTargetsTable(dailyEmployeeTargetsState.items);
      renderDailyEmployeeTargetsHistory(dailyEmployeeTargetsState.history);
      populateDailyTargetEmployeeFilter(dailyEmployeeTargetsState.items);
      iconsIn(section);
    }).catch(function () {
      var tbody = document.getElementById('assign-daily-targets-table');
      if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center text-slate-500 py-6">Unable to load daily targets.</td></tr>';
    });
  }

  function refreshDailyEmployeeTargets(force) {
    if (!canViewAssignmentDashboardWidgets()) return Promise.resolve();
    bindDailyEmployeeTargetsUi();
    return loadDailyEmployeeTargets();
  }

  function renderEmployeeDailyTargetCard(dailyTarget) {
    var panel = document.getElementById('emp-daily-targets-panel');
    if (!panel) return;
    dailyTarget = dailyTarget || {};
    var year = dailyTarget.target_year || new Date().getFullYear();
    if (!dailyTarget.has_target) {
      panel.innerHTML = '<div class="emp-daily-target-card"><div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="target" class="h-5 w-5 text-brand"></i> Yearly Targets (' + year + ')</h3></div><p class="text-slate-500">' + escapeHtml(dailyTarget.message || 'No yearly target has been assigned.') + '</p></div>';
      iconsIn(panel);
      return;
    }
    var target = dailyTarget.target || {};
    var metrics = target.metrics || [];
    var remaining = metrics.map(function (metric) {
      if ((metric.remaining || 0) > 0) return (metric.remaining || 0) + ' ' + (metric.label || '');
      return null;
    }).filter(Boolean);
    panel.innerHTML = '<div class="emp-daily-target-card">' +
      '<div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="target" class="h-5 w-5 text-brand"></i> Yearly Targets (' + (target.target_year || year) + ')</h3><span class="' + dailyTargetStatusClass(target.status) + '">' + escapeHtml(target.status_label || '') + '</span></div>' +
      '<p class="text-caption text-slate-500 mb-3">Standard countable days: ' + escapeHtml(String(target.standard_countable_days || '—')) +
      ' · Actual effective days: ' + escapeHtml(String(target.actual_effective_working_days_elapsed || 0) + ' / ' + String(target.actual_effective_working_days_total || 0)) +
      ' · Leave used: ' + escapeHtml(String(target.approved_leave_used || 0) + ' / ' + String(target.annual_leave_allowance || 12)) + '</p>' +
      '<div class="emp-daily-target-grid">' + metrics.map(function (metric) {
        var pct = Math.min(100, Number(metric.pct || 0));
        return '<article class="emp-daily-target-item"><p class="emp-daily-target-item__label">' + escapeHtml(metric.label || '') + '</p><p class="emp-daily-target-item__value">' + (metric.completed || 0) + ' / ' + (metric.target || 0) + '</p>' +
          '<div class="assign-daily-target-bar"><div class="assign-daily-target-bar__fill" style="width:' + pct + '%"></div></div></article>';
      }).join('') + '</div>' +
      '<p class="mt-3 text-sm"><strong>Overall YTD:</strong> ' + (target.overall_pct || 0) + '%</p>' +
      (remaining.length ? '<p class="mt-2 text-sm text-slate-600"><strong>Remaining:</strong> ' + escapeHtml(remaining.join(' · ')) + '</p>' : '') +
      (target.notes ? '<div class="emp-daily-target-notes"><strong>Manager instructions:</strong><br>' + escapeHtml(target.notes) + '</div>' : '') +
    '</div>';
    iconsIn(panel);
  }

  function refreshEmployeeDailyTargetsFromDashboard(force) {
    if (!isEmployeeUser()) return Promise.resolve();
    return loadEmployeeDashboardFromDatabase(function (data) {
      renderEmployeeDailyTargetCard((data || {}).yearly_target || (data || {}).daily_target || {});
    }, !!force);
  }

  function bindAssignmentDashboardWidgets() {
    var root = document.getElementById('assign-dashboard-widgets');
    if (!root || root._assignmentWidgetsBound || !canViewAssignmentDashboardWidgets()) {
      if (root && !canViewAssignmentDashboardWidgets()) root.classList.add('hidden');
      return;
    }
    root._assignmentWidgetsBound = true;

    var periodEl = document.getElementById('assign-heatmap-period');
    var customWrap = document.getElementById('assign-heatmap-custom-range');
    var debounceTimer = null;

    function scheduleHeatMapReload() {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function () {
        loadAssignmentHeatMapWidget();
      }, 250);
    }

    if (periodEl) {
      periodEl.addEventListener('change', function () {
        if (customWrap) customWrap.classList.toggle('hidden', periodEl.value !== 'custom');
        scheduleHeatMapReload();
      });
    }

    ['assign-heatmap-sort', 'assign-heatmap-employee', 'assign-heatmap-state', 'assign-heatmap-source', 'assign-heatmap-from', 'assign-heatmap-to'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('change', scheduleHeatMapReload);
    });

    root.addEventListener('click', function (e) {
      var cityBtn = e.target.closest('[data-heatmap-city-id]');
      if (!cityBtn) return;
      openMasterDataForHeatMapCity(cityBtn.getAttribute('data-heatmap-city-id'));
    });
  }

  function initAssignmentPage() {
    if (!document.getElementById('assignment-page-root')) return;
    bindAssignmentActiveSection();
    bindAssignmentListingToolbar('assignment_histories', 'assignment-history-search');
    populateAssignmentExecutiveFilter();
    syncAssignmentRotationToggles();
    ensureAssignmentActionsDelegated();
    bindAssignmentDashboardWidgets();
    refreshAssignmentDashboardWidgets();
    bindYearlyEmployeeTargetsUi();
    var importBtn = document.getElementById('assignment-import-btn');
    if (importBtn) importBtn.classList.toggle('hidden', !crmCanAction('ca_master', 'import'));
    var exportBtn = document.getElementById('assignment-export-btn');
    if (exportBtn) exportBtn.classList.toggle('hidden', !crmCanAction('assignment', 'export'));
    icons();
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
      if (leadSelect) setSelectValueIfValid(leadSelect, assignment.ca_id || '');
      if (execSelect) setSelectValueIfValid(execSelect, assignment.employee_id || '');
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
      return '<tr class="ca-table-row crm-table-row assign-table-row">' +
        '<td class="crm-td-person">' + escapeHtml(h.previous_employee || 'Unassigned') + '</td>' +
        '<td class="crm-td-person">' + escapeHtml(h.new_employee || '—') + '</td>' +
        '<td class="crm-td-firm font-medium">' + escapeHtml(h.firm_name || '—') + '</td>' +
        '<td class="crm-td-person">' + escapeHtml(h.assigned_by_name || h.assigned_by || 'System') + '</td>' +
        '<td class="crm-td-source">' + escapeHtml(bulkAssignReasonLabel(h.reason) !== '—' ? bulkAssignReasonLabel(h.reason) : (h.assignment_type || '—')) + '</td>' +
        '<td class="crm-td-date"><span class="cam-cell-text cam-cell-mono">' + escapeHtml(formatDateTime(h.assigned_at)) + '</span></td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="6" class="text-center text-slate-500 p-4">No assignment history yet.</td></tr>';
  }

  function syncAssignmentRotationToggles() {
    if (!document.getElementById('assign-rotation-section')) return;
    apiFetch('/settings/data')
      .then(function (body) {
        var assignment = (body.data || {}).assignment || {};
        var map = {
          'assign-rotation-round-robin': assignment.auto_assignment !== false,
          'assign-rotation-workload': assignment.workload_balancing !== false,
          'assign-rotation-priority': assignment.auto_assignment !== false,
          'assign-rotation-city': assignment.city_routing !== false,
          'assign-rotation-hot': assignment.hot_lead_priority !== false,
        };
        Object.keys(map).forEach(function (id) {
          var el = document.getElementById(id);
          if (el) el.checked = !!map[id];
        });
      })
      .catch(function () {});
  }

  function bindAssignmentListingToolbar(listingKey, searchId, filterId) {
    var root = document.querySelector('[data-listing-toolbar="' + listingKey + '"]');
    if (!root || root._assignmentToolbarBound) return;
    root._assignmentToolbarBound = true;
    var search = document.getElementById(searchId);
    var filter = filterId ? document.getElementById(filterId) : null;
    var debounceTimer = null;

    function apply() {
      if (!window.CA_LISTING_SEARCH) return;
      var current = CA_LISTING_SEARCH.getState(listingKey);
      var filters = Object.assign({}, current.filters || {});
      if (filter && filter.value) filters.status = filter.value;
      else delete filters.status;
      CA_LISTING_SEARCH.setState(listingKey, {
        search: search ? search.value.trim() : '',
        page: 1,
        filters: filters,
      });
      reloadListing(listingKey);
    }

    if (search) {
      search.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(apply, 300);
      });
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          apply();
        }
      });
    }
    if (filter) filter.addEventListener('change', apply);
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
    var setKpi = function (id, val) {
      var el = document.getElementById(id);
      if (el) el.textContent = String(val);
    };
    setKpi('fu-kpi-due-today', metrics.followups_due_today != null ? metrics.followups_due_today : 0);
    setKpi('fu-kpi-pending', metrics.pending_followups != null ? metrics.pending_followups : 0);
    setKpi('fu-kpi-overdue', metrics.overdue_followups != null ? metrics.overdue_followups : 0);
    setKpi('fu-kpi-completed', metrics.completed_followups != null ? metrics.completed_followups : 0);
  }

  function buildFollowupCalendarEvents(followups, year, month) {
    var eventsByDay = {};
    (followups || []).forEach(function (f) {
      if (!f.scheduled_date) return;
      var d = new Date(f.scheduled_date);
      if (d.getFullYear() === year && d.getMonth() === month) {
        var day = d.getDate();
        eventsByDay[day] = (eventsByDay[day] || 0) + 1;
      }
    });
    return eventsByDay;
  }

  function paintFollowupCalendar(eventsByDay) {
    var container = document.getElementById('followup-calendar');
    if (!container) return;

    var now = new Date();
    var year = now.getFullYear();
    var month = now.getMonth();
    var firstDay = new Date(year, month, 1).getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var selectedDay = window._followupCalSelectedDay || null;
    eventsByDay = eventsByDay || {};

    var days = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    var monthLabel = now.toLocaleString('en-IN', { month: 'long', year: 'numeric' });
    var html = '<div class="ca-cal-grid mb-2">' + days.map(function (d) {
      return '<div class="text-caption font-medium text-slate-400 py-1">' + d + '</div>';
    }).join('') + '</div><div class="ca-cal-grid">';
    for (var blank = 0; blank < firstDay; blank++) html += '<div class="ca-cal-empty"></div>';
    for (var i = 1; i <= daysInMonth; i++) {
      var cls = 'ca-cal-day';
      if (i === now.getDate() && !selectedDay) cls += ' today';
      if (selectedDay && i === selectedDay) cls += ' is-selected';
      if (eventsByDay[i]) cls += ' has-event';
      var dayCount = eventsByDay[i] || 0;
      var title = dayCount ? (dayCount + ' follow-up' + (dayCount === 1 ? '' : 's')) : 'No follow-ups';
      html += '<button type="button" class="' + cls + '" data-cal-day="' + i + '" data-cal-year="' + year +
        '" data-cal-month="' + month + '" title="' + title + '" aria-label="' + title + ' on ' + i + '">' + i + '</button>';
    }
    html += '</div>' +
      '<div class="crm-cal-footer mt-3">' +
        '<p class="crm-cal-footer__title">Follow-up Schedule</p>' +
        '<p class="crm-cal-footer__month">' + monthLabel + '</p>' +
        (selectedDay
          ? '<button type="button" class="mgr-link-btn mt-2" id="followup-cal-clear-day">Show all dates</button>'
          : '') +
      '</div>';
    container.innerHTML = html;
    bindFollowupCalendarClicks(container);
  }

  function renderFollowupCalendarFromData() {
    var container = document.getElementById('followup-calendar');
    if (!container) return;

    var now = new Date();
    var year = now.getFullYear();
    var month = now.getMonth();
    var cached = window._followupCalEvents;
    var hasCache = cached && cached.year === year && cached.month === month;

    // Prefer full-month cache so day-filtered table reloads don't wipe markers.
    if (hasCache) {
      paintFollowupCalendar(cached.eventsByDay);
      return;
    }

    // Paint from the current page while the full-month request is in flight.
    paintFollowupCalendar(buildFollowupCalendarEvents(window.realFollowUps || [], year, month));

    if (window._followupCalLoading) return;
    window._followupCalLoading = true;
    apiFetch('/follow-ups' + listingAllQuery('follow_ups'))
      .then(function (body) {
        var items = unwrapList(body);
        var eventsByDay = buildFollowupCalendarEvents(items, year, month);
        window._followupCalEvents = { year: year, month: month, eventsByDay: eventsByDay };
        paintFollowupCalendar(eventsByDay);
      })
      .catch(function () {
        var eventsByDay = buildFollowupCalendarEvents(window.realFollowUps || [], year, month);
        window._followupCalEvents = { year: year, month: month, eventsByDay: eventsByDay };
        paintFollowupCalendar(eventsByDay);
      })
      .finally(function () {
        window._followupCalLoading = false;
      });
  }

  function bindFollowupCalendarClicks(container) {
    if (!container || container._followupCalClickBound) return;
    container._followupCalClickBound = true;
    container.addEventListener('click', function (e) {
      var dayBtn = e.target.closest('[data-cal-day]');
      if (dayBtn) {
        e.preventDefault();
        e.stopPropagation();
        var day = parseInt(dayBtn.getAttribute('data-cal-day'), 10);
        var year = parseInt(dayBtn.getAttribute('data-cal-year'), 10);
        var month = parseInt(dayBtn.getAttribute('data-cal-month'), 10);
        if (!day) return;
        filterFollowupsByCalendarDay(year, month, day);
        return;
      }
      var clearBtn = e.target.closest('#followup-cal-clear-day');
      if (clearBtn) {
        e.preventDefault();
        clearFollowupCalendarDayFilter();
      }
    });
  }

  function pad2(n) {
    return String(n).padStart(2, '0');
  }

  function filterFollowupsByCalendarDay(year, month, day) {
    var dateStr = year + '-' + pad2(month + 1) + '-' + pad2(day);
    window._followupCalSelectedDay = day;
    window._followupDateFilter = '';

    // Clear other follow-up filters so the day range is the only active filter.
    document.querySelectorAll('[data-fu-type]').forEach(function (chip) {
      chip.classList.remove('active');
    });
    setFollowupKpiActive('');
    setFollowupTypePanels('list');

    if (window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.setState('follow_ups', {
        page: 1,
        filters: { from: dateStr, to: dateStr },
      });
      reloadListing('follow_ups');
    } else {
      var filtered = (window.realFollowUps || []).filter(function (f) {
        if (!f.scheduled_date) return false;
        var d = new Date(f.scheduled_date);
        return d.getFullYear() === year && d.getMonth() === month && d.getDate() === day;
      });
      renderFollowupsTable(filtered);
      renderFollowupCalendarFromData();
    }

    var count = (window._followupCalEvents && window._followupCalEvents.eventsByDay)
      ? (window._followupCalEvents.eventsByDay[day] || 0)
      : 0;
    toast(
      count
        ? ('Showing ' + count + ' follow-up' + (count === 1 ? '' : 's') + ' on ' + pad2(day) + '/' + pad2(month + 1) + '/' + year)
        : ('No follow-ups on ' + pad2(day) + '/' + pad2(month + 1) + '/' + year),
      count ? 'info' : 'warning'
    );
  }

  function clearFollowupCalendarDayFilter() {
    window._followupCalSelectedDay = null;
    window._followupDateFilter = '';
    setFollowupKpiActive('');
    if (window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.setState('follow_ups', { page: 1, filters: {} });
      reloadListing('follow_ups');
    } else {
      renderFollowupsTable();
      renderFollowupCalendarFromData();
    }
    toast('Showing all follow-ups', 'info');
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

  var duplicateAttemptsState = { page: 1, filters: {} };

  function readDuplicateAttemptFilters() {
    return {
      search: (document.getElementById('dup-attempts-search') || {}).value || '',
      attempt_type: (document.getElementById('dup-attempts-type') || {}).value || '',
      status: (document.getElementById('dup-attempts-status') || {}).value || '',
      from: (document.getElementById('dup-attempts-from') || {}).value || '',
      to: (document.getElementById('dup-attempts-to') || {}).value || '',
    };
  }

  function duplicateAttemptTypeBadge(type) {
    if (type === 'potential_duplicate') return '<span class="badge badge-warning">Potential Duplicate</span>';
    if (type === 'duplicate') return '<span class="badge badge-danger">Duplicate</span>';
    return '<span class="badge">' + escapeHtml(type || '—') + '</span>';
  }

  function duplicateAttemptStatusBadge(status) {
    if (status === 'resolved') return '<span class="badge badge-success">Resolved</span>';
    if (status === 'changed' || status === 'changed_number') return '<span class="badge badge-brand">Changed</span>';
    return '<span class="badge badge-warning">Open</span>';
  }

  function renderDuplicateAttemptsMetrics(metrics) {
    var el = document.getElementById('dup-attempts-metrics');
    if (!el || !metrics) return;
    el.innerHTML = [
      { label: 'Today', value: metrics.today, cls: '' },
      { label: 'This Week', value: metrics.this_week, cls: '' },
      { label: 'This Month', value: metrics.this_month, cls: '' },
      { label: 'Total', value: metrics.total, cls: '' },
      { label: 'Exact Duplicates', value: metrics.duplicate_count, cls: 'text-rose-600' },
      { label: 'Potential', value: metrics.potential_duplicate_count, cls: 'text-amber-600' },
    ].map(function (item) {
      return '<div class="card p-4"><p class="text-caption text-slate-500">' + item.label + '</p>' +
        '<p class="text-2xl font-semibold ' + item.cls + '">' + (item.value || 0) + '</p></div>';
    }).join('');
  }

  function renderDuplicateAttemptsTable(result) {
    var tbody = document.getElementById('dup-attempts-table');
    var footer = document.getElementById('dup-attempts-pagination');
    if (!tbody) return;
    var items = (result && result.items) || [];
    tbody.innerHTML = items.length ? items.map(function (row) {
      var leadLink = row.matched_lead_id
        ? '<button type="button" class="mgr-link-btn" data-lead-view="' + row.matched_lead_id + '">' + escapeHtml(row.existing_lead_name || 'Lead #' + row.matched_lead_id) + '</button>'
        : escapeHtml(row.existing_lead_name || '—');
      return '<tr class="ca-table-row">' +
        '<td>' + escapeHtml(row.employee_name || '—') + '</td>' +
        '<td class="font-mono text-sm">' + escapeHtml(row.duplicate_number || '—') + '</td>' +
        '<td>' + leadLink + '</td>' +
        '<td class="font-mono text-sm">' + escapeHtml(row.saved_number || '—') + '</td>' +
        '<td>' + escapeHtml(formatDuplicateDate(row.attempted_at)) + '</td>' +
        '<td>' + duplicateAttemptTypeBadge(row.attempt_type) + '</td>' +
        '<td>' + duplicateAttemptStatusBadge(row.status) + '</td>' +
        '<td><button type="button" class="btn-secondary btn-sm" data-dup-view="' + row.id + '" title="View details"><i data-lucide="eye" class="h-4 w-4"></i></button></td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="8" class="text-center text-slate-500 p-4">No duplicate attempts found.</td></tr>';

    tbody.querySelectorAll('[data-lead-view]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        window._selectedLeadId = btn.getAttribute('data-lead-view');
        if (typeof navigateTo === 'function') navigateTo(isEmployeeUser() ? 'leads' : 'ca-master');
      });
    });

    tbody.querySelectorAll('[data-dup-view]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var row = items.find(function (r) { return String(r.id) === btn.getAttribute('data-dup-view'); });
        if (!row) return;
        toast(
          'Attempt #' + row.id + ' · ' + (row.employee_name || 'Employee') +
          ' · ' + (row.duplicate_number || '—') +
          (row.number_changed ? ' · number was changed before save' : ''),
          'info'
        );
      });
    });

    if (footer && result && result.pagination) {
      var p = result.pagination;
      footer.innerHTML = '<div class="flex items-center justify-between gap-3 p-3">' +
        '<span class="text-caption text-slate-500">Page ' + p.current_page + ' of ' + p.last_page + ' · ' + p.total + ' records</span>' +
        '<div class="flex gap-2">' +
          '<button type="button" class="btn-secondary btn-sm" id="dup-attempts-prev"' + (p.current_page <= 1 ? ' disabled' : '') + '>Previous</button>' +
          '<button type="button" class="btn-secondary btn-sm" id="dup-attempts-next"' + (p.current_page >= p.last_page ? ' disabled' : '') + '>Next</button>' +
        '</div></div>';
      var prev = document.getElementById('dup-attempts-prev');
      var next = document.getElementById('dup-attempts-next');
      if (prev) prev.addEventListener('click', function () { loadDuplicateAttemptsPage(p.current_page - 1); });
      if (next) next.addEventListener('click', function () { loadDuplicateAttemptsPage(p.current_page + 1); });
    }
    icons();
  }

  function buildDuplicateAttemptsQuery(page) {
    var f = duplicateAttemptsState.filters;
    var qs = 'per_page=25&page=' + (page || 1);
    if (f.search) qs += '&search=' + encodeURIComponent(f.search);
    if (f.attempt_type) qs += '&attempt_type=' + encodeURIComponent(f.attempt_type);
    if (f.status) qs += '&status=' + encodeURIComponent(f.status);
    if (f.from) qs += '&from=' + encodeURIComponent(f.from);
    if (f.to) qs += '&to=' + encodeURIComponent(f.to);
    return qs;
  }

  function loadDuplicateAttemptsPage(page) {
    duplicateAttemptsState.page = page || 1;
    apiFetch('/duplicate-attempts?' + buildDuplicateAttemptsQuery(duplicateAttemptsState.page))
      .then(function (body) {
        renderDuplicateAttemptsTable(body.data || {});
      })
      .catch(function (err) {
        toast(err.message || 'Unable to load duplicate attempts', 'error');
      });
  }

  function initDuplicateAttemptsPage() {
    var root = document.getElementById('dup-attempts-table');
    if (!root || root.dataset.dupBound === '1') return;
    root.dataset.dupBound = '1';

    duplicateAttemptsState.filters = readDuplicateAttemptFilters();

    apiFetch('/duplicate-attempts/metrics')
      .then(function (body) {
        renderDuplicateAttemptsMetrics(body.data || {});
      })
      .catch(function () {});

    loadDuplicateAttemptsPage(1);

    var applyBtn = document.getElementById('dup-attempts-apply');
    var clearBtn = document.getElementById('dup-attempts-clear');
    var exportBtn = document.getElementById('dup-attempts-export-btn');

    if (applyBtn) {
      applyBtn.addEventListener('click', function () {
        duplicateAttemptsState.filters = readDuplicateAttemptFilters();
        loadDuplicateAttemptsPage(1);
      });
    }
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        ['dup-attempts-search', 'dup-attempts-type', 'dup-attempts-status', 'dup-attempts-from', 'dup-attempts-to'].forEach(function (id) {
          var el = document.getElementById(id);
          if (el) el.value = '';
        });
        duplicateAttemptsState.filters = {};
        loadDuplicateAttemptsPage(1);
      });
    }
    if (exportBtn) {
      exportBtn.addEventListener('click', function () {
        fetchExportResponse('/duplicate-attempts/export?' + buildDuplicateAttemptsQuery(duplicateAttemptsState.page).replace(/page=\d+&?/, ''))
          .then(function (result) {
            if (result.kind === 'file') triggerFileDownload(result.blob, result.filename);
          })
          .catch(function (err) {
            toast(err.message || 'Export failed', 'error');
          });
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

  function isDemoFollowupType(followup) {
    var type = String((followup && followup.followup_type) || '').toLowerCase();
    return type.indexOf('demo') >= 0;
  }

  function isFollowupOpenStatus(status) {
    var normalized = String(status || '').toLowerCase();
    return ['completed', 'closed', 'done'].indexOf(normalized) < 0;
  }

  function rebuildFollowupIndex(followups) {
    var index = {};
    (followups || []).forEach(function (f) {
      if (f && f.followup_id != null && f.followup_id !== '') {
        index[String(f.followup_id)] = f;
      }
    });
    window._followupById = index;
  }

  function upsertFollowupInCache(followup) {
    if (!followup || followup.followup_id == null || followup.followup_id === '') return;
    var key = String(followup.followup_id);
    if (!window._followupById) window._followupById = {};
    window._followupById[key] = followup;
    if (!window.realFollowUps) window.realFollowUps = [];
    var idx = window.realFollowUps.findIndex(function (f) {
      return String(f.followup_id) === key;
    });
    if (idx >= 0) window.realFollowUps[idx] = followup;
  }

  function removeFollowupFromCache(followupId) {
    if (followupId == null || followupId === '') return;
    var key = String(followupId);
    if (window._followupById) delete window._followupById[key];
    if (window.realFollowUps) {
      window.realFollowUps = window.realFollowUps.filter(function (f) {
        return String(f.followup_id) !== key;
      });
    }
  }

  function preloadFollowupActionData() {
    if (!document.getElementById('followups-data-table')) return;
    if (!window._formSelectDataReady) ensureFormSelectData();
  }

  function followupActivityMeta(type) {
    return ACTIVITY_ACTION_META[type] || { icon: 'activity', color: 'bg-slate-500' };
  }

  function unwrapTimelineItems(body) {
    if (!body || body.data === undefined) return [];
    if (Array.isArray(body.data)) return body.data;
    var items = body.data.items;
    if (Array.isArray(items)) return items;
    if (items && Array.isArray(items.data)) return items.data;
    return unwrapList(body);
  }

  function getFollowupActivityDemoItems() {
    var now = new Date();
    function at(daysAgo, hour, minute) {
      var d = new Date(now);
      d.setDate(d.getDate() - daysAgo);
      d.setHours(hour, minute, 0, 0);
      return d.toISOString();
    }
    var demos = [
      { activity_type: 'Call Completed', activity_label: 'Call Completed', firm_name: 'Sharma & Associates', ca_name: 'R. Sharma', employee_name: 'Rahul Sharma', status: 'Completed', notes: 'Customer interested in GST module demo.', occurred_at: at(0, 10, 30) },
      { activity_type: 'Demo Scheduled', activity_label: 'Demo Scheduled', firm_name: 'ABC & Co', ca_name: 'Neha Gupta', employee_name: 'Neha Gupta', status: 'Scheduled', notes: 'Demo booked for GST module.', occurred_at: at(0, 9, 15) },
      { activity_type: 'WhatsApp Sent', activity_label: 'WhatsApp Sent', firm_name: 'Patel Tax Consultants', ca_name: 'V. Patel', employee_name: 'Amit Verma', status: 'Sent', notes: 'Shared product brochure and pricing sheet.', occurred_at: at(0, 11, 45) },
      { activity_type: 'Follow-up Added', activity_label: 'Follow-up Added', firm_name: 'Kumar & Sons CA', ca_name: 'S. Kumar', employee_name: 'Priya Mehta', status: 'Open', notes: 'Follow-up set for pricing discussion.', occurred_at: at(0, 14, 20) },
      { activity_type: 'Email Sent', activity_label: 'Email Sent', firm_name: 'Singh Financial Services', ca_name: 'A. Singh', employee_name: 'Rahul Sharma', status: 'Delivered', notes: 'Sent demo confirmation email.', occurred_at: at(0, 16, 5) },
      { activity_type: 'Customer Requested Callback', activity_label: 'Customer Requested Callback', firm_name: 'Reddy Associates', ca_name: 'K. Reddy', employee_name: 'Neha Gupta', status: 'Pending', notes: 'Requested callback after 4 PM today.', occurred_at: at(0, 15, 10) },
      { activity_type: 'Demo Rescheduled', activity_label: 'Demo Rescheduled', firm_name: 'Mehta & Co', ca_name: 'R. Mehta', employee_name: 'Amit Verma', status: 'Rescheduled', notes: 'Moved demo from morning to afternoon slot.', occurred_at: at(1, 11, 0) },
      { activity_type: 'Call Completed', activity_label: 'Call Completed', firm_name: 'Joshi Tax Advisors', ca_name: 'P. Joshi', employee_name: 'Priya Mehta', status: 'Completed', notes: 'Discussed annual compliance package.', occurred_at: at(1, 10, 0) },
      { activity_type: 'Lead Assigned', activity_label: 'Lead Assigned', firm_name: 'Bansal & Partners', ca_name: 'M. Bansal', employee_name: 'Rahul Sharma', status: 'Assigned', notes: 'Lead assigned from inbound enquiry.', occurred_at: at(1, 9, 30) },
      { activity_type: 'Status Changed', activity_label: 'Status Changed', firm_name: 'Iyer Consultants', ca_name: 'L. Iyer', employee_name: 'Neha Gupta', status: 'Negotiation', notes: 'Moved from Interested to Negotiation.', occurred_at: at(1, 17, 45) },
      { activity_type: 'Demo Completed', activity_label: 'Demo Completed', firm_name: 'Gupta & Associates', ca_name: 'S. Gupta', employee_name: 'Amit Verma', status: 'Completed', notes: 'Demo completed successfully. Positive feedback.', occurred_at: at(2, 15, 30) },
      { activity_type: 'Remarks Updated', activity_label: 'Remarks Updated', firm_name: 'Nair Tax Solutions', ca_name: 'D. Nair', employee_name: 'Priya Mehta', status: 'Updated', notes: 'Added notes on team size and current software.', occurred_at: at(2, 13, 15) },
      { activity_type: 'SMS Sent', activity_label: 'SMS Sent', firm_name: 'Desai & Co', ca_name: 'H. Desai', employee_name: 'Rahul Sharma', status: 'Sent', notes: 'Reminder SMS for tomorrow\'s demo.', occurred_at: at(2, 18, 0) },
      { activity_type: 'Follow-up Added', activity_label: 'Follow-up Added', firm_name: 'Chopra Associates', ca_name: 'N. Chopra', employee_name: 'Neha Gupta', status: 'Open', notes: 'Scheduled follow-up after proposal review.', occurred_at: at(3, 10, 45) },
      { activity_type: 'Call Completed', activity_label: 'Call Completed', firm_name: 'Malhotra CA Firm', ca_name: 'V. Malhotra', employee_name: 'Amit Verma', status: 'No Answer', notes: 'No answer — will retry tomorrow.', occurred_at: at(3, 11, 30) },
      { activity_type: 'Demo Cancelled', activity_label: 'Demo Cancelled', firm_name: 'Rao & Associates', ca_name: 'T. Rao', employee_name: 'Priya Mehta', status: 'Cancelled', notes: 'Client cancelled due to audit season workload.', occurred_at: at(4, 14, 0) },
      { activity_type: 'Email Sent', activity_label: 'Email Sent', firm_name: 'Kapoor Tax Services', ca_name: 'R. Kapoor', employee_name: 'Rahul Sharma', status: 'Delivered', notes: 'Sent revised quotation with 10% discount.', occurred_at: at(4, 16, 20) },
      { activity_type: 'WhatsApp Sent', activity_label: 'WhatsApp Sent', firm_name: 'Verma Consultants', ca_name: 'A. Verma', employee_name: 'Neha Gupta', status: 'Read', notes: 'Shared meeting link for product walkthrough.', occurred_at: at(5, 12, 10) },
      { activity_type: 'Demo Scheduled', activity_label: 'Demo Scheduled', firm_name: 'Pillai & Co', ca_name: 'S. Pillai', employee_name: 'Amit Verma', status: 'Scheduled', notes: 'Demo scheduled for billing module.', occurred_at: at(6, 10, 0) },
      { activity_type: 'Status Changed', activity_label: 'Status Changed', firm_name: 'Saxena Associates', ca_name: 'P. Saxena', employee_name: 'Priya Mehta', status: 'Demo Scheduled', notes: 'Pipeline stage updated after qualification call.', occurred_at: at(7, 9, 0) },
      { activity_type: 'Call Completed', activity_label: 'Call Completed', firm_name: 'Bhatt Tax Advisors', ca_name: 'G. Bhatt', employee_name: 'Rahul Sharma', status: 'Interested', notes: 'Interested in multi-user license.', occurred_at: at(8, 15, 45) },
      { activity_type: 'Follow-up Completed', activity_label: 'Follow-up Completed', firm_name: 'Trivedi & Sons', ca_name: 'M. Trivedi', employee_name: 'Neha Gupta', status: 'Completed', notes: 'Closed follow-up after successful onboarding call.', occurred_at: at(9, 11, 20) },
      { activity_type: 'Lead Assigned', activity_label: 'Lead Assigned', firm_name: 'Agarwal CA Practice', ca_name: 'R. Agarwal', employee_name: 'Amit Verma', status: 'Assigned', notes: 'Reassigned from unassigned queue.', occurred_at: at(10, 10, 30) },
      { activity_type: 'Demo Completed', activity_label: 'Demo Completed', firm_name: 'Mishra & Partners', ca_name: 'K. Mishra', employee_name: 'Priya Mehta', status: 'Completed', notes: 'Walkthrough completed. Awaiting management approval.', occurred_at: at(12, 14, 30) },
      { activity_type: 'Remarks Updated', activity_label: 'Remarks Updated', firm_name: 'Dubey Consultants', ca_name: 'S. Dubey', employee_name: 'Rahul Sharma', status: 'Updated', notes: 'Updated objection: price sensitivity noted.', occurred_at: at(14, 16, 0) },
      { activity_type: 'Email Sent', activity_label: 'Email Sent', firm_name: 'Sethi & Associates', ca_name: 'A. Sethi', employee_name: 'Neha Gupta', status: 'Delivered', notes: 'Case study email sent for reference.', occurred_at: at(18, 10, 15) },
      { activity_type: 'Customer Requested Callback', activity_label: 'Customer Requested Callback', firm_name: 'Khanna Tax Hub', ca_name: 'V. Khanna', employee_name: 'Amit Verma', status: 'Pending', notes: 'Asked to call back next Monday morning.', occurred_at: at(20, 9, 45) },
      { activity_type: 'Call Completed', activity_label: 'Call Completed', firm_name: 'Bhardwaj & Co', ca_name: 'D. Bhardwaj', employee_name: 'Priya Mehta', status: 'Not Interested', notes: 'Using competitor product. Not exploring switch now.', occurred_at: at(25, 11, 0) },
      { activity_type: 'Demo Scheduled', activity_label: 'Demo Scheduled', firm_name: 'Chawla Associates', ca_name: 'N. Chawla', employee_name: 'Rahul Sharma', status: 'Scheduled', notes: 'Initial discovery demo booked.', occurred_at: at(28, 14, 0) },
    ];
    return demos.map(function (item, idx) {
      var occurred = new Date(item.occurred_at);
      return Object.assign({ activity_id: 'demo-' + (idx + 1) }, item, {
        date_label: occurred.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }),
        time_label: occurred.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true }),
      });
    });
  }

  function followupActivityWhenLabel(item) {
    if (!item || !item.occurred_at) {
      return ((item && item.date_label) || '') + ((item && item.time_label) ? ' · ' + item.time_label : '');
    }
    var d = new Date(item.occurred_at);
    if (Number.isNaN(d.getTime())) return item.date_label || '—';
    var now = new Date();
    var startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var startItem = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    var diffDays = Math.round((startToday - startItem) / 86400000);
    var dayLabel = diffDays === 0 ? 'Today' : (diffDays === 1 ? 'Yesterday' : d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }));
    var timeLabel = d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });
    return dayLabel + ' • ' + timeLabel;
  }

  function followupActivityDayGroupLabel(item) {
    if (!item || !item.occurred_at) return 'Earlier';
    var d = new Date(item.occurred_at);
    if (Number.isNaN(d.getTime())) return 'Earlier';
    var now = new Date();
    var startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var startItem = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    var diffDays = Math.round((startToday - startItem) / 86400000);
    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7) return d.toLocaleDateString('en-IN', { weekday: 'long' });
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  function followupActivityStatusClass(status) {
    var value = String(status || '').toLowerCase();
    if (/complete|deliver|sent|read|assign|interest/.test(value)) return 'fu-activity-badge--success';
    if (/schedule|open|pending|callback/.test(value)) return 'fu-activity-badge--brand';
    if (/cancel|reject|not interested|no answer|fail/.test(value)) return 'fu-activity-badge--danger';
    if (/resched|update|negotiat/.test(value)) return 'fu-activity-badge--warning';
    return 'fu-activity-badge--neutral';
  }

  function followupActivityDateOnly(value) {
    var d = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(d.getTime())) return null;
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
  }

  function followupActivityStartOfWeekMonday(value) {
    var d = followupActivityDateOnly(value);
    if (!d) return null;
    var day = d.getDay();
    var diff = day === 0 ? -6 : 1 - day;
    d.setDate(d.getDate() + diff);
    return d;
  }

  function filterFollowupActivitiesByPeriod(items, period) {
    if (!items || !items.length || !period || period === 'all') return items || [];
    var now = new Date();
    var today = followupActivityDateOnly(now);
    var weekStart = followupActivityStartOfWeekMonday(now);
    var weekEnd = weekStart ? new Date(weekStart.getFullYear(), weekStart.getMonth(), weekStart.getDate() + 6) : null;
    var monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
    var monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0);

    return items.filter(function (item) {
      if (!item.occurred_at) return false;
      var itemDay = followupActivityDateOnly(item.occurred_at);
      if (!itemDay || !today) return false;
      if (period === 'today') return itemDay.getTime() === today.getTime();
      if (period === 'week') {
        return weekStart && weekEnd && itemDay >= weekStart && itemDay <= weekEnd;
      }
      if (period === 'month') {
        return itemDay >= monthStart && itemDay <= monthEnd;
      }
      return true;
    });
  }

  function sortFollowupActivityItems(items, sort) {
    var list = (items || []).slice();
    list.sort(function (a, b) {
      var aTime = a.occurred_at ? new Date(a.occurred_at).getTime() : 0;
      var bTime = b.occurred_at ? new Date(b.occurred_at).getTime() : 0;
      return sort === 'asc' ? aTime - bTime : bTime - aTime;
    });
    return list;
  }

  function followupActivityPeriodSuffix() {
    if (followupActivityPeriod === 'today') return ' for Today';
    if (followupActivityPeriod === 'week') return ' for This Week';
    if (followupActivityPeriod === 'month') return ' for This Month';
    return '';
  }

  function resetFollowupActivityScrollPosition() {
    var scroll = document.getElementById('followup-activity-scroll');
    if (scroll) scroll.scrollTop = 0;
  }

  function setFollowupActivityPanelLoading(loading) {
    followupActivityLoading = !!loading;
    var scroll = document.getElementById('followup-activity-scroll');
    var footer = document.getElementById('followup-activity-pagination-slot');
    var filters = document.querySelector('.followup-history-panel__filters-wrap');
    var panel = document.querySelector('.followup-history-panel');
    if (scroll) scroll.classList.toggle('is-loading', !!loading);
    if (footer) footer.classList.toggle('is-loading', !!loading);
    if (filters) filters.classList.toggle('is-loading', !!loading);
    if (panel) panel.setAttribute('aria-busy', loading ? 'true' : 'false');
    document.querySelectorAll('[data-followup-activity-period]').forEach(function (btn) {
      btn.disabled = !!loading;
    });
    var sortBtn = document.getElementById('followup-timeline-sort');
    var refreshBtn = document.getElementById('followup-timeline-refresh');
    if (sortBtn) sortBtn.disabled = !!loading;
    if (refreshBtn) refreshBtn.disabled = !!loading;
  }

  function getFollowupActivityFilteredSortedAll(items) {
    var source = filterFollowupActivitiesByPeriod(items || [], followupActivityPeriod);
    return sortFollowupActivityItems(source, followupActivitySort);
  }

  function mergeFollowupActivityItems(existing, incoming) {
    var merged = (existing || []).slice();
    var seen = {};
    merged.forEach(function (item) {
      var key = String(item.activity_id || '') + '|' + String(item.occurred_at || '');
      seen[key] = true;
    });
    (incoming || []).forEach(function (item) {
      var key = String(item.activity_id || '') + '|' + String(item.occurred_at || '');
      if (!seen[key]) {
        seen[key] = true;
        merged.push(item);
      }
    });
    return merged;
  }

  function buildFollowupActivityEmptyHtml(message, title) {
    var emptyTitle = title || 'No Activity Yet';
    var emptyText = message || 'Activities like calls, demos and follow-ups will appear here.';
    return '<div class="fu-activity-empty">' +
      '<div class="fu-activity-empty__icon" aria-hidden="true"><i data-lucide="clipboard-list" class="h-4 w-4"></i></div>' +
      '<p class="fu-activity-empty__title">' + escapeHtml(emptyTitle) + '</p>' +
      '<p class="fu-activity-empty__text">' + escapeHtml(emptyText) + '</p>' +
      '<button type="button" class="btn-secondary btn-sm fu-activity-empty__refresh" id="followup-activity-empty-refresh">' +
        '<i data-lucide="refresh-cw" class="h-3.5 w-3.5"></i> Refresh' +
      '</button>' +
    '</div>';
  }

  function buildFollowupActivityRowHtml(item) {
    var meta = followupActivityMeta(item.activity_type || item.activity_label || '');
    var typeLabel = item.activity_label || item.activity_type || 'Activity';
    var firmLine = item.firm_name || '—';
    if (item.ca_name && item.ca_name !== item.firm_name) firmLine += ' · ' + item.ca_name;
    var employee = item.employee_name || item.created_by || item.performed_by || 'System';
    var statusText = item.call_status || item.status || '';
    var notesText = item.call_notes || item.notes || '';
    var badgeHtml = statusText
      ? '<span class="fu-activity-badge ' + followupActivityStatusClass(statusText) + '">' + escapeHtml(statusText) + '</span>'
      : '';
    var noteHtml = notesText
      ? '<p class="fu-activity-row__note" data-crm-tip="' + escapeHtml(notesText) + '" title="' + escapeHtml(notesText) + '">' + escapeHtml(notesText) + '</p>'
      : '';
    return '<article class="fu-activity-row" data-activity-id="' + escapeHtml(String(item.activity_id || '')) + '">' +
      '<div class="fu-activity-row__icon ' + meta.color + '"><i data-lucide="' + meta.icon + '" class="h-3 w-3"></i></div>' +
      '<div class="fu-activity-row__body">' +
        '<div class="fu-activity-row__line1">' +
          '<span class="fu-activity-row__type">' + escapeHtml(typeLabel) + '</span>' +
          '<span class="fu-activity-row__meta">' + escapeHtml(firmLine) + '</span>' +
          (badgeHtml ? '<span class="fu-activity-row__badge-inline">' + badgeHtml + '</span>' : '') +
        '</div>' +
        '<div class="fu-activity-row__line2">' +
          '<span class="fu-activity-row__employee">' + escapeHtml(employee) + '</span>' +
          '<span class="fu-activity-row__when">' + escapeHtml(followupActivityWhenLabel(item)) + '</span>' +
        '</div>' +
        noteHtml +
      '</div>' +
    '</article>';
  }

  function buildFollowupActivityTimelineHtml(items) {
    if (!items || !items.length) return buildFollowupActivityEmptyHtml();
    var groups = [];
    var groupMap = {};
    items.forEach(function (item) {
      var label = followupActivityDayGroupLabel(item);
      if (!groupMap[label]) {
        groupMap[label] = { label: label, items: [] };
        groups.push(groupMap[label]);
      }
      groupMap[label].items.push(item);
    });
    return '<div class="fu-activity-feed">' + groups.map(function (group) {
      return '<section class="fu-activity-day-group">' +
        '<h4 class="fu-activity-day-label">' + escapeHtml(group.label) + '</h4>' +
        '<div class="fu-activity-day-list">' + group.items.map(buildFollowupActivityRowHtml).join('') + '</div>' +
      '</section>';
    }).join('') + '</div>';
  }

  function buildFollowupActivityTimelineItemHtml(item) {
    return buildFollowupActivityRowHtml(item);
  }

  function updateFollowupActivityCountUi(total) {
    var countEl = document.getElementById('followup-activity-count');
    if (!countEl) return;
    countEl.textContent = total > 0 ? '(' + total + ')' : '';
  }

  function followupActivityPaginationMeta() {
    var total = followupActivityTotal || 0;
    var perPage = followupActivityPageSize || 10;
    var current = followupActivityPage || 1;
    var last = Math.max(1, Math.ceil(total / perPage) || 1);
    var visible = (window._followupActivityTimeline || []).length;
    var from = total && visible ? ((current - 1) * perPage) + 1 : 0;
    var to = total && visible ? Math.min(from + visible - 1, total) : 0;
    return {
      current_page: current,
      last_page: last,
      per_page: perPage,
      total: total,
      from: from,
      to: to,
    };
  }

  function renderFollowupActivityPagination() {
    var slot = document.getElementById('followup-activity-pagination-slot');
    if (!slot || !window.CATablePagination) return;
    var meta = followupActivityPaginationMeta();
    if (!meta.total) {
      slot.innerHTML = '';
      slot.classList.add('crm-table-footer--empty');
      return;
    }
    CATablePagination.renderInto(slot, {
      scope: 'followup-activity',
      pagination: meta,
      perPage: meta.per_page,
      showPerPage: true,
    });
    if (typeof iconsIn === 'function') iconsIn(slot);
  }

  function registerFollowupActivityPagination() {
    if (followupActivityPaginationRegistered || !window.CATablePagination) return;
    followupActivityPaginationRegistered = true;
    CATablePagination.register('followup-activity', {
      onPageChange: function (page) {
        if (followupActivityLoading) return;
        followupActivityPage = Math.max(1, parseInt(page, 10) || 1);
        if (followupActivityIsDemo) {
          renderFollowupActivityDemoPage();
          return;
        }
        loadFollowupActivityTimeline({ reset: false, page: followupActivityPage });
      },
      onPerPageChange: function (perPage) {
        if (followupActivityLoading) return;
        followupActivityPageSize = perPage;
        followupActivityPage = 1;
        if (followupActivityIsDemo) {
          renderFollowupActivityDemoPage();
          return;
        }
        loadFollowupActivityTimeline({ reset: true });
      },
    });
  }

  function renderFollowupActivityDemoPage() {
    var allDemo = getFollowupActivityFilteredSortedAll(window._followupActivityDemoAll || getFollowupActivityDemoItems());
    followupActivityTotal = allDemo.length;
    var offset = (followupActivityPage - 1) * followupActivityPageSize;
    window._followupActivityTimeline = allDemo.slice(offset, offset + followupActivityPageSize);
    window._followupActivityDemoBanner = true;
    renderFollowupActivityMainFeed();
    return Promise.resolve(window._followupActivityTimeline);
  }

  function bindFollowupActivityEmptyRefresh() {
    var emptyRefresh = document.getElementById('followup-activity-empty-refresh');
    if (!emptyRefresh || emptyRefresh._followupEmptyBound) return;
    emptyRefresh._followupEmptyBound = true;
    emptyRefresh.addEventListener('click', function () {
      loadFollowupActivityTimeline({ reset: true });
    });
  }

  function renderFollowupActivityMainFeed() {
    var container = document.getElementById('followup-activity-timeline');
    if (!container) return;
    var items = window._followupActivityTimeline || [];
    var total = followupActivityTotal || 0;

    if (!items.length && !total) {
      var periodEmpty = followupActivityPeriod !== 'all';
      container.innerHTML = periodEmpty
        ? '<p class="fu-activity-period-empty">No activities found for the selected period.</p>'
        : buildFollowupActivityEmptyHtml(
          'Activities like calls, demos and follow-ups will appear here.',
          'No Activity Yet'
        );
      updateFollowupActivityCountUi(0);
      renderFollowupActivityPagination();
      bindFollowupActivityEmptyRefresh();
      iconsIn(container);
      resetFollowupActivityScrollPosition();
      return;
    }

    container.innerHTML = (window._followupActivityDemoBanner
      ? '<div class="fu-activity-demo-banner" role="status">Sample activity data — connect real follow-up activity or disable demo mode.</div>'
      : '') + buildFollowupActivityTimelineHtml(items);
    window._followupActivityDemoBanner = false;
    bindFollowupActivityEmptyRefresh();
    updateFollowupActivityCountUi(total);
    renderFollowupActivityPagination();
    iconsIn(container);
    resetFollowupActivityScrollPosition();
  }

  function renderFollowupActivityTimelineItems(items, containerId) {
    var container = document.getElementById(containerId || 'followup-activity-timeline');
    if (!container) return;
    var isMainFeed = !containerId || containerId === 'followup-activity-timeline';
    if (isMainFeed) {
      renderFollowupActivityMainFeed();
      return;
    }
    var source = sortFollowupActivityItems(items || [], followupActivitySort);
    container.innerHTML = buildFollowupActivityTimelineHtml(source);
    iconsIn(container);
  }

  function fetchFollowupActivityPage(page, options) {
    options = options || {};
    var qs = '?sort=' + encodeURIComponent(followupActivitySort) +
      '&page=' + encodeURIComponent(page) +
      '&per_page=' + encodeURIComponent(followupActivityPageSize) +
      '&period=' + encodeURIComponent(followupActivityPeriod || 'all');
    return apiFetch('/follow-ups/activity-timeline' + qs)
      .then(function (body) {
        var items = unwrapTimelineItems(body);
        var pagination = body && body.data ? body.data.pagination : null;
        var period = followupActivityPeriod || 'all';
        var useDemo = window.CRM_FOLLOWUP_ACTIVITY_DEMO === true
          && !items.length
          && page === 1
          && period === 'all';

        if (useDemo) {
          followupActivityIsDemo = true;
          followupActivityHasApiData = false;
          window._followupActivityDemoAll = getFollowupActivityDemoItems();
          followupActivityPage = 1;
          return renderFollowupActivityDemoPage();
        }

        followupActivityIsDemo = false;
        followupActivityHasApiData = true;
        window._followupActivityTimelineRaw = items;
        followupActivityTotal = pagination ? pagination.total : items.length;
        if (pagination && pagination.current_page) {
          followupActivityPage = pagination.current_page;
        }
        if (pagination && pagination.per_page) {
          followupActivityPageSize = pagination.per_page;
        }
        window._followupActivityTimeline = items;

        if (!options.silentRender) {
          renderFollowupActivityMainFeed();
        }
        return window._followupActivityTimeline;
      });
  }

  function loadFollowupActivityTimeline(options) {
    options = options || {};
    var container = document.getElementById('followup-activity-timeline');
    if (!container) return Promise.resolve([]);
    if (!crmCanAction('followups', 'view')) {
      container.innerHTML = '<p class="text-caption text-slate-400 py-4 text-center">You do not have permission to view activity history.</p>';
      return Promise.resolve([]);
    }
    if (followupActivityLoading) {
      return Promise.resolve(window._followupActivityTimeline || []);
    }

    registerFollowupActivityPagination();
    followupActivitySort = options.sort || followupActivitySort || 'desc';
    if (options.reset !== false) {
      followupActivityPage = options.page || 1;
      window._followupActivityTimeline = [];
    } else if (options.page) {
      followupActivityPage = options.page;
    }

    if (followupActivityIsDemo && (options.reset !== false || options.page)) {
      return renderFollowupActivityDemoPage();
    }

    if (!options.silent) {
      setFollowupActivityPanelLoading(true);
    } else {
      followupActivityLoading = true;
    }

    return fetchFollowupActivityPage(followupActivityPage, { silentRender: !!options.silent })
      .then(function (items) {
        setFollowupActivityPanelLoading(false);
        if (options.silent) {
          renderFollowupActivityMainFeed();
        }
        return items;
      })
      .catch(function (err) {
        setFollowupActivityPanelLoading(false);
        if (err.status === 403) {
          container.innerHTML = '<p class="text-caption text-slate-400 py-4 text-center">You do not have permission to view activity history.</p>';
          return [];
        }
        container.innerHTML = '<p class="text-caption text-rose-500 py-4 text-center">' + escapeHtml(err.message || 'Unable to load activity history.') + '</p>';
        return [];
      });
  }

  function fetchLeadActivityTimeline(caId, sort) {
    var qs = '?sort=' + encodeURIComponent(sort || 'desc');
    return apiFetch('/ca-masters/' + encodeURIComponent(caId) + '/follow-up-history' + qs)
      .then(function (body) {
        return unwrapTimelineItems(body);
      });
  }

  function initFollowupActivityTimeline() {
    if (!document.getElementById('followup-activity-timeline')) return;
    if (document.getElementById('followup-activity-timeline')._followupTimelineInit) {
      loadFollowupActivityTimeline({ reset: true });
      return;
    }
    document.getElementById('followup-activity-timeline')._followupTimelineInit = true;
    registerFollowupActivityPagination();
    var sortBtn = document.getElementById('followup-timeline-sort');
    var refreshBtn = document.getElementById('followup-timeline-refresh');

    document.querySelectorAll('[data-followup-activity-period]').forEach(function (btn) {
      if (btn._followupPeriodBound) return;
      btn._followupPeriodBound = true;
      btn.addEventListener('click', function () {
        if (followupActivityLoading) return;
        followupActivityPeriod = btn.getAttribute('data-followup-activity-period') || 'all';
        document.querySelectorAll('[data-followup-activity-period]').forEach(function (chip) {
          chip.classList.toggle('is-active', chip === btn);
          chip.setAttribute('aria-pressed', chip === btn ? 'true' : 'false');
        });
        followupActivityPage = 1;
        if (followupActivityIsDemo) {
          renderFollowupActivityDemoPage();
        } else {
          loadFollowupActivityTimeline({ reset: true });
        }
      });
    });

    if (sortBtn && !sortBtn._followupTimelineBound) {
      sortBtn._followupTimelineBound = true;
      sortBtn.addEventListener('click', function () {
        if (followupActivityLoading) return;
        followupActivitySort = followupActivitySort === 'desc' ? 'asc' : 'desc';
        sortBtn.setAttribute('data-sort', followupActivitySort);
        sortBtn.innerHTML = followupActivitySort === 'desc'
          ? '<i data-lucide="arrow-down-narrow-wide" class="h-4 w-4"></i> Newest first'
          : '<i data-lucide="arrow-up-narrow-wide" class="h-4 w-4"></i> Oldest first';
        sortBtn.setAttribute('aria-label', followupActivitySort === 'desc'
          ? 'Sort activity history newest first'
          : 'Sort activity history oldest first');
        iconsIn(sortBtn);
        followupActivityPage = 1;
        if (followupActivityIsDemo) {
          renderFollowupActivityDemoPage();
        } else {
          loadFollowupActivityTimeline({ reset: true });
        }
      });
    }

    if (refreshBtn && !refreshBtn._followupTimelineBound) {
      refreshBtn._followupTimelineBound = true;
      refreshBtn.addEventListener('click', function () {
        if (followupActivityLoading) return;
        followupActivityIsDemo = false;
        loadFollowupActivityTimeline({ reset: true });
      });
    }

    loadFollowupActivityTimeline({ reset: true });
  }

  function refreshFollowupsPage(options) {
    options = options || {};
    if (options.metrics !== false) {
      dashboardMetricsLoaded = false;
      loadDashboardMetricsFromDatabase(function () {
        renderFollowupKpis();
      });
      refreshDailyEmployeeTargets(true);
      refreshEmployeeDailyTargetsFromDashboard(true);
    } else {
      renderFollowupKpis();
    }
    if (options.calendar) {
      window._followupCalEvents = null;
      renderFollowupCalendarFromData();
    }
    if (document.getElementById('followup-activity-timeline')) {
      loadFollowupActivityTimeline({ silent: !!options.timelineSilent });
    }
    if (options.reload && window.CA_LISTING_SEARCH) {
      return reloadListing('follow_ups');
    }
    return Promise.resolve();
  }

  function setFollowupFormBusy(busy) {
    var form = document.getElementById('form-followup');
    if (!form) return;
    form.classList.toggle('crm-form-loading', !!busy);
    form.setAttribute('aria-busy', busy ? 'true' : 'false');
  }

  function findFollowupInCache(followupId) {
    if (followupId === null || followupId === undefined || followupId === '') return null;
    var key = String(followupId);
    if (window._followupById && window._followupById[key]) return window._followupById[key];
    return (window.realFollowUps || []).find(function (f) {
      return String(f.followup_id) === key;
    }) || null;
  }

  function fetchFollowupById(followupId) {
    return apiFetch('/follow-ups/' + encodeURIComponent(followupId))
      .then(function (body) {
        var row = body && body.data ? body.data : null;
        if (row) upsertFollowupInCache(row);
        return row;
      })
      .catch(function (err) {
        if (err.status === 403) {
          return Promise.reject(new Error('You do not have permission to view this follow-up.'));
        }
        if (err.status === 404) {
          return Promise.reject(new Error('This follow-up could not be found. It may have been removed.'));
        }
        throw err;
      });
  }

  function resolveFollowupById(followupId) {
    var cached = findFollowupInCache(followupId);
    if (cached) return Promise.resolve(cached);
    return fetchFollowupById(followupId);
  }

  function getFollowupActionItems(followup) {
    if (!followup || followup.followup_id == null || followup.followup_id === '') return [];
    var items = [];
    var caAttrs = followup.ca_id ? { 'ca-id': String(followup.ca_id) } : null;
    var open = isFollowupOpenStatus(followup.status);

    if (crmCanAction('followups', 'view')) {
      items.push({ action: 'view', label: 'View', icon: 'eye', dataAttrs: caAttrs || undefined });
    }
    if (crmCanAction('followups', 'edit')) {
      items.push({ action: 'edit', label: 'Edit / Reschedule', icon: 'pencil', dataAttrs: caAttrs || undefined });
      if (open) {
        items.push({
          action: 'complete',
          label: 'Mark Completed',
          icon: 'check-circle',
          dataAttrs: caAttrs || undefined,
        });
      }
      if (isDemoFollowupType(followup)) {
        items.push({
          action: 'demo-result',
          label: 'Update Demo Result',
          icon: 'clipboard-check',
          dataAttrs: caAttrs || undefined,
        });
      }
    }
    if (crmCanAction('followups', 'delete')) {
      items.push({ action: 'delete', label: 'Delete', icon: 'trash-2', dataAttrs: caAttrs || undefined });
    }
    return items;
  }

  function showFollowupDetailDrawer(followup, loading) {
    if (typeof openDetailDrawer !== 'function') {
      toast('Details view is unavailable.', 'warning');
      return;
    }
    if (loading) {
      openDetailDrawer({
        firm: followup.firm_name || ('Follow-up #' + followup.followup_id),
        fields: [],
        extraHtml: '<div class="crm-inline-loading"><i data-lucide="loader-2" class="h-5 w-5 animate-spin text-brand"></i><span>Loading details…</span></div>',
      });
      iconsIn(document.getElementById('detail-drawer'));
      return;
    }
    var historyHtml = followup.ca_id
      ? '<div class="mt-5 pt-4 border-t border-slate-100">' +
          '<p class="text-caption font-semibold text-slate-600 mb-3">Activity History</p>' +
          '<div id="followup-drawer-timeline" class="followup-activity-timeline followup-activity-timeline--compact">' +
            '<div class="crm-inline-loading py-4"><i data-lucide="loader-2" class="h-4 w-4 animate-spin text-brand"></i><span>Loading history…</span></div>' +
          '</div></div>'
      : '';
    openDetailDrawer({
      firm: followup.firm_name || ('Follow-up #' + followup.followup_id),
      fields: [
        { label: 'Follow-up ID', value: String(followup.followup_id) },
        { label: 'Type', value: followup.followup_type || '—' },
        { label: 'Status', value: followup.status || '—' },
        { label: 'Executive', value: followup.executive || followup.employee_name || '—' },
        { label: 'Scheduled', value: formatDateTime(followup.scheduled_date) },
        { label: 'Next Follow-up', value: formatDate(followup.next_followup_date) },
        { label: 'Outcome', value: followup.outcome || '—' },
        { label: 'Remarks', value: followup.remarks || '—' },
        { label: 'Priority', value: followup.priority || '—' },
      ],
      extraHtml: historyHtml,
    });
    iconsIn(document.getElementById('detail-drawer'));
    if (followup.ca_id) {
      fetchLeadActivityTimeline(followup.ca_id, 'desc')
        .then(function (items) {
          renderFollowupActivityTimelineItems(items, 'followup-drawer-timeline');
        })
        .catch(function () {
          var drawerTimeline = document.getElementById('followup-drawer-timeline');
          if (drawerTimeline) {
            drawerTimeline.innerHTML = '<p class="text-caption text-slate-400">Unable to load activity history.</p>';
          }
        });
    }
  }

  function openFollowupDetails(followupId) {
    var cached = findFollowupInCache(followupId);
    if (cached) {
      showFollowupDetailDrawer(cached);
      return;
    }
    showFollowupDetailDrawer({ followup_id: followupId }, true);
    fetchFollowupById(followupId)
      .then(function (followup) {
        if (!followup) {
          toast('Follow-up not found. Refresh and try again.', 'warning');
          return;
        }
        showFollowupDetailDrawer(followup);
      })
      .catch(function (err) {
        if (err.status === 403) return;
        toast(err.message || 'Unable to load follow-up details', 'warning');
      });
  }

  function markFollowupCompleted(followupId) {
    if (!crmCanAction('followups', 'edit')) return;
    var cached = findFollowupInCache(followupId);
    if (cached && !isFollowupOpenStatus(cached.status)) {
      toast('This follow-up is already completed.', 'info');
      return;
    }
    var row = document.querySelector('tr[data-followup-id="' + followupId + '"]');
    if (row) row.classList.add('crm-row-busy');
    var previous = cached ? Object.assign({}, cached) : null;
    if (cached) {
      var optimistic = Object.assign({}, cached, { status: 'Completed' });
      upsertFollowupInCache(optimistic);
      updateFollowupTableRow(followupId, optimistic);
    }
    apiFetch('/follow-ups/' + encodeURIComponent(followupId), {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        status: 'Completed',
        meeting_link: cached && cached.meeting_link ? String(cached.meeting_link).trim() : undefined,
      }),
    })
      .then(function (body) {
        if (row) row.classList.remove('crm-row-busy');
        var updated = body && body.data ? body.data : null;
        if (updated) {
          upsertFollowupInCache(updated);
          updateFollowupTableRow(followupId, updated);
        }
        toast('Follow-up marked as completed', 'success');
        refreshFollowupsPage({ reload: false, calendar: true });
      })
      .catch(function (err) {
        if (row) row.classList.remove('crm-row-busy');
        if (previous) {
          upsertFollowupInCache(previous);
          updateFollowupTableRow(followupId, previous);
        }
        if (err.status === 403) return;
        toast(err.message || 'Unable to update follow-up', 'error');
      });
  }

  function loadRecycleBin() {
    var tbody = document.getElementById('recycle-bin-table');
    var countEl = document.getElementById('recycle-bin-count');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-slate-500 p-4">Loading recycle bin…</td></tr>';

    apiFetch('/ca-masters/trashed')
      .then(function (body) {
        var rows = body.data || [];
        window._recycleBinRows = rows;
        if (countEl) countEl.textContent = rows.length + ' item' + (rows.length === 1 ? '' : 's');
        renderRecycleBinTable(rows);
      })
      .catch(function (err) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-rose-500 p-4">' +
          escapeHtml(err.message || 'Unable to load recycle bin') + '</td></tr>';
        if (countEl) countEl.textContent = '0 items';
      });
  }

  function renderRecycleBinTable(rows) {
    var tbody = document.getElementById('recycle-bin-table');
    if (!tbody) return;
    if (!rows || !rows.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center text-slate-500 p-4">Recycle bin is empty.</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map(function (row) {
      return '<tr class="ca-table-row" data-recycle-id="' + row.ca_id + '">' +
        '<td><input type="checkbox" class="recycle-bin-check" data-ca-id="' + row.ca_id + '" aria-label="Select lead" /></td>' +
        '<td class="font-medium">' + escapeHtml(row.firm_name || '—') + '</td>' +
        '<td>' + escapeHtml(row.ca_name || '—') + '</td>' +
        '<td>' + escapeHtml(row.mobile_no || '—') + '</td>' +
        '<td>' + escapeHtml(row.city || '—') + '</td>' +
        '<td>' + escapeHtml(row.status || '—') + '</td>' +
        '<td><span class="cam-cell-text cam-cell-mono">' + escapeHtml(formatDateTime(row.deleted_at)) + '</span></td>' +
        '<td class="flex flex-wrap gap-1">' +
          iconBtn('rotate-ccw', 'Restore', 'data-recycle-restore="' + row.ca_id + '"', 'secondary') +
          iconBtn('trash-2', 'Delete Forever', 'data-recycle-force="' + row.ca_id + '"', 'danger') +
        '</td>' +
      '</tr>';
    }).join('');

    tbody.querySelectorAll('[data-recycle-restore]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        restoreRecycleLead(btn.getAttribute('data-recycle-restore'));
      });
    });
    tbody.querySelectorAll('[data-recycle-force]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        forceDeleteRecycleLead(btn.getAttribute('data-recycle-force'));
      });
    });
    icons();
  }

  function getRecycleSelectedIds() {
    return Array.from(document.querySelectorAll('.recycle-bin-check:checked'))
      .map(function (cb) { return parseInt(cb.getAttribute('data-ca-id'), 10); })
      .filter(function (id) { return id > 0; });
  }

  function restoreRecycleLead(caId) {
    apiFetch('/ca-masters/' + encodeURIComponent(caId) + '/restore', { method: 'POST' })
      .then(function () {
        toast('Lead restored', 'success');
        loadRecycleBin();
        invalidateDataCaches(['metrics', 'segment_counts', 'leads', 'ca_masters']);
      })
      .catch(function (err) {
        toast(err.message || 'Unable to restore lead', 'error');
      });
  }

  function forceDeleteRecycleLead(caId) {
    if (!window.confirm('Permanently delete this lead? This cannot be undone.')) return;
    apiFetch('/ca-masters/' + encodeURIComponent(caId) + '/force', { method: 'DELETE' })
      .then(function () {
        toast('Lead permanently deleted', 'success');
        loadRecycleBin();
      })
      .catch(function (err) {
        toast(err.message || 'Unable to permanently delete lead', 'error');
      });
  }

  function bindRecycleBinActions() {
    var refreshBtn = document.getElementById('recycle-bin-refresh');
    var restoreBtn = document.getElementById('recycle-bin-restore-selected');
    var deleteBtn = document.getElementById('recycle-bin-delete-selected');
    var selectAll = document.getElementById('recycle-bin-select-all');
    if (refreshBtn && !refreshBtn._bound) {
      refreshBtn._bound = true;
      refreshBtn.addEventListener('click', loadRecycleBin);
    }
    if (selectAll && !selectAll._bound) {
      selectAll._bound = true;
      selectAll.addEventListener('change', function () {
        document.querySelectorAll('.recycle-bin-check').forEach(function (cb) {
          cb.checked = selectAll.checked;
        });
      });
    }
    if (restoreBtn && !restoreBtn._bound) {
      restoreBtn._bound = true;
      restoreBtn.addEventListener('click', function () {
        var ids = getRecycleSelectedIds();
        if (!ids.length) {
          toast('Select at least one lead', 'warning');
          return;
        }
        apiFetch('/ca-masters/trashed/restore', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ca_ids: ids }),
        })
          .then(function (body) {
            var count = (body.data && body.data.restored_count) || ids.length;
            toast(count + ' lead(s) restored', 'success');
            loadRecycleBin();
            invalidateDataCaches(['metrics', 'segment_counts', 'leads', 'ca_masters']);
          })
          .catch(function (err) {
            toast(err.message || 'Unable to restore selected leads', 'error');
          });
      });
    }
    if (deleteBtn && !deleteBtn._bound) {
      deleteBtn._bound = true;
      deleteBtn.addEventListener('click', function () {
        var ids = getRecycleSelectedIds();
        if (!ids.length) {
          toast('Select at least one lead', 'warning');
          return;
        }
        if (!window.confirm('Permanently delete ' + ids.length + ' selected lead(s)? This cannot be undone.')) return;
        apiFetch('/ca-masters/trashed/force-delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ca_ids: ids }),
        })
          .then(function (body) {
            var count = (body.data && body.data.deleted_count) || ids.length;
            toast(count + ' lead(s) permanently deleted', 'success');
            loadRecycleBin();
          })
          .catch(function (err) {
            toast(err.message || 'Unable to permanently delete selected leads', 'error');
          });
      });
    }
  }

  function buildFollowupRowHtml(f) {
    var rowClass = 'ca-table-row crm-table-row' + (f.status === 'Overdue' ? ' followup-row-overdue' : '');
    var rescheduled = f.is_rescheduled ? ' <span class="badge badge-brand">Rescheduled</span>' : '';
    var outcomeBadge = f.outcome
      ? ' <span class="badge badge-brand">' + escapeHtml(f.outcome) + '</span>'
      : '';
    var remarksText = f.remarks || f.outcome || '—';
    var followupId = f.followup_id;
    var actionsCell = (followupId == null || followupId === '')
      ? '<td class="sticky-right crm-actions-cell crm-actions-cell--inline"><span class="cam-cell-empty">—</span></td>'
      : (window.CAActionDropdown
        ? CAActionDropdown.renderCell(getFollowupActionItems(f), {
          scope: 'followup',
          rowId: followupId,
          cellClass: 'sticky-right crm-actions-cell crm-actions-cell--inline',
          ariaLabel: 'Follow-up actions',
        })
        : '<td class="sticky-right crm-actions-cell"><span class="cam-cell-empty">—</span></td>');
    return '<tr class="' + rowClass + '" data-followup-id="' + followupId + '">' +
      renderInboxCheckCell('followups-data-table', f.followup_id) +
      '<td class="crm-td-status">' + compactTextCell(f.followup_type) + outcomeBadge + rescheduled + '</td>' +
      '<td class="sticky-left-2 crm-td-firm font-medium">' + firmNameCell(f.firm_name) + '</td>' +
      '<td class="crm-td-person">' + compactTextCell(f.executive || f.employee_name) + '</td>' +
      '<td class="crm-td-remarks">' + compactTextCell(remarksText) + '</td>' +
      '<td class="crm-td-date"><span class="cam-cell-text cam-cell-mono">' + escapeHtml(formatDateTime(f.scheduled_date)) + '</span></td>' +
      '<td class="crm-td-date"><span class="cam-cell-text cam-cell-mono">' + escapeHtml(formatDate(f.next_followup_date)) + '</span></td>' +
      '<td class="crm-td-status"><span class="cam-cell-badge">' + followupStatusBadge(f.status) + '</span></td>' +
      actionsCell +
    '</tr>';
  }

  function updateFollowupTableRow(followupId, followup) {
    var el = document.getElementById('followups-data-table');
    if (!el || !followup) return;
    var row = el.querySelector('tr[data-followup-id="' + followupId + '"]');
    if (!row) return;
    var temp = document.createElement('tbody');
    temp.innerHTML = buildFollowupRowHtml(followup);
    var newRow = temp.firstElementChild;
    if (!newRow) return;
    row.replaceWith(newRow);
    iconsIn(newRow);
    bindCrmRowActions(el);
    syncInboxChecks('followups-data-table');
  }

  function removeFollowupTableRow(followupId) {
    var el = document.getElementById('followups-data-table');
    if (!el) return;
    var row = el.querySelector('tr[data-followup-id="' + followupId + '"]');
    if (row) row.remove();
    if (!el.querySelector('tr[data-followup-id]')) {
      el.innerHTML = '<tr><td colspan="9" class="text-center text-slate-500 p-4">No follow-ups yet.</td></tr>';
    }
    renderFollowupKpis();
  }

  function renderFollowupsTable(pageFollowups) {
    var el = document.getElementById('followups-data-table');
    if (!el) return;
    if (pageFollowups === undefined && window.CA_LISTING_SEARCH) {
      reloadListing('follow_ups');
      return;
    }
    var followups = pageFollowups || window.realFollowUps || [];
    rebuildFollowupIndex(followups);
    el.innerHTML = followups.length ? followups.map(buildFollowupRowHtml).join('') : '<tr><td colspan="9" class="text-center text-slate-500 p-4">No follow-ups yet.</td></tr>';
    bindCrmRowActions(el);
    syncInboxChecks('followups-data-table');
    iconsIn(el);
    preloadFollowupActionData();
  }

  function setFollowupTypePanels(mode) {
    var mainWrap = document.getElementById('followups-main-table-wrap');
    var historyPanel = document.getElementById('demo-history-panel');
    var showHistory = mode === 'history';
    if (mainWrap) mainWrap.classList.toggle('hidden', showHistory);
    if (historyPanel) historyPanel.classList.toggle('hidden', !showHistory);
  }

  function applyFollowupTypeFilter(type) {
    var label = type || 'All';
    if (type === 'Demo History') {
      setFollowupTypePanels('history');
      loadDemoHistory({ refreshListing: true });
      toast('Showing Demo History', 'info');
      return;
    }

    setFollowupTypePanels('list');
    window._followupCalSelectedDay = null;
    window._followupDateFilter = '';
    setFollowupKpiActive('');
    var filters = {};
    if (type === 'Demo Completed') {
      // Show completed demo follow-ups and keep history panel visible below.
      filters.followup_type = 'Demo Completed';
      setFollowupTypePanels('both');
      var historyPanel = document.getElementById('demo-history-panel');
      var mainWrap = document.getElementById('followups-main-table-wrap');
      if (mainWrap) mainWrap.classList.remove('hidden');
      if (historyPanel) historyPanel.classList.remove('hidden');
      loadDemoHistory({ refreshListing: true });
    } else if (type) {
      filters.followup_type = type;
    }

    if (window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.setState('follow_ups', { page: 1, filters: filters });
      reloadListing('follow_ups');
    } else {
      renderFollowupsTable();
      renderFollowupCalendarFromData();
    }
    toast('Showing ' + label + ' follow-ups', 'info');
  }

  function loadDemoHistory(options) {
    options = options || {};
    var tbody = document.getElementById('demo-history-table');
    var countEl = document.getElementById('demo-history-count');
    if (!tbody) return;
    bindDemoHistoryTableActions();
    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-slate-500 p-4">Loading demo history…</td></tr>';

    apiFetch('/workflow/demo-history')
      .then(function (body) {
        var rows = body.data || [];
        window._demoHistoryRows = rows;
        if (countEl) countEl.textContent = rows.length + ' record' + (rows.length === 1 ? '' : 's');
        renderDemoHistoryTable(rows);
        if (options.refreshListing && window.CA_LISTING_SEARCH) {
          reloadListing('follow_ups');
        }
      })
      .catch(function (err) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-rose-500 p-4">' +
          escapeHtml(err.message || 'Unable to load demo history') + '</td></tr>';
        if (countEl) countEl.textContent = '0 records';
      });
  }

  function renderDemoHistoryTable(rows) {
    var tbody = document.getElementById('demo-history-table');
    if (!tbody) return;
    if (!rows || !rows.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center text-slate-500 p-4">No demo history yet. Update a demo result to see it here.</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map(function (row) {
      return '<tr class="ca-table-row" data-demo-history-id="' + row.id + '">' +
        '<td class="font-medium">' + escapeHtml(row.firm_name || ('Lead #' + row.ca_id)) + '</td>' +
        '<td><span class="cam-cell-text cam-cell-mono">' + escapeHtml(row.mobile_no || '—') + '</span></td>' +
        '<td><span class="badge badge-brand">' + escapeHtml(row.result || '—') + '</span></td>' +
        '<td>' + escapeHtml(row.remarks || '—') + '</td>' +
        '<td>' + escapeHtml(row.employee_name || '—') + '</td>' +
        '<td><span class="cam-cell-text cam-cell-mono">' + escapeHtml(formatDateTime(row.demo_at)) + '</span></td>' +
        '<td><span class="cam-cell-text cam-cell-mono">' + escapeHtml(formatDateTime(row.completed_at)) + '</span></td>' +
        '<td>' +
          (row.followup_id
            ? iconBtn('external-link', 'Open', 'data-demo-history-followup="' + row.followup_id + '"', 'secondary')
            : '<span class="cam-cell-empty">—</span>') +
        '</td>' +
      '</tr>';
    }).join('');

    icons();
  }

  function bindDemoHistoryTableActions() {
    var tbody = document.getElementById('demo-history-table');
    if (!tbody || tbody._demoHistoryBound) return;
    tbody._demoHistoryBound = true;
    tbody.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-demo-history-followup]');
      if (!btn) return;
      openFollowupFormForEdit(btn.getAttribute('data-demo-history-followup'));
    });
  }

  function bindFollowupRowActions(container) {
    bindCrmRowActions(container);
  }

  function openCallOutcomeModal(followupId, caId) {
    var modal = document.getElementById('modal-call-outcome');
    var form = document.getElementById('form-call-outcome');
    if (!modal || !form) return;
    form.reset();
    clearCallOutcomeErrors(form);
    var idEl = document.getElementById('call-outcome-followup-id');
    var caEl = document.getElementById('call-outcome-ca-id');
    if (idEl) idEl.value = followupId || '';
    if (caEl) caEl.value = caId || '';
    syncCallOutcomeFields();
    openModal(modal);
    var statusEl = document.getElementById('call-outcome-select');
    if (statusEl) statusEl.focus();
    iconsIn(modal);
  }

  function clearCallOutcomeErrors(form) {
    if (!form) return;
    form.querySelectorAll('.ca-field-error').forEach(function (el) {
      el.textContent = '';
      el.classList.add('hidden');
    });
    form.querySelectorAll('.input-field.is-invalid').forEach(function (el) {
      el.classList.remove('is-invalid');
    });
  }

  function setCallOutcomeError(field, message) {
    var form = document.getElementById('form-call-outcome');
    if (!form) return;
    var errorEl = form.querySelector('[data-error-for="' + field + '"]');
    var input = form.querySelector('[name="' + field + '"]') || form.querySelector('#' + (
      field === 'outcome' ? 'call-outcome-select'
        : field === 'remarks' ? 'call-outcome-remarks'
        : field === 'next_followup_date' ? 'call-outcome-followup-date'
        : field === 'demo_date' ? 'call-outcome-demo-date'
        : field === 'demo_time' ? 'call-outcome-demo-time'
        : field === 'meeting_link' ? 'call-outcome-meeting-link'
        : ''
    ));
    if (errorEl) {
      errorEl.textContent = message || '';
      errorEl.classList.toggle('hidden', !message);
    }
    if (input) input.classList.toggle('is-invalid', !!message);
  }

  function syncCallOutcomeFields() {
    var outcome = (document.getElementById('call-outcome-select') || {}).value || '';
    var scheduleWrap = document.getElementById('call-outcome-schedule-wrap');
    var demoWrap = document.getElementById('call-outcome-demo-wrap');
    if (scheduleWrap) {
      scheduleWrap.classList.toggle('hidden', outcome !== 'Follow-up Required');
      if (outcome !== 'Follow-up Required') {
        var followupDate = document.getElementById('call-outcome-followup-date');
        if (followupDate) followupDate.value = '';
        setCallOutcomeError('next_followup_date', '');
      }
    }
    if (demoWrap) {
      demoWrap.classList.toggle('hidden', outcome !== 'Demo Scheduled');
      if (outcome !== 'Demo Scheduled') {
        ['call-outcome-demo-date', 'call-outcome-demo-time', 'call-outcome-meeting-link'].forEach(function (id) {
          var el = document.getElementById(id);
          if (!el) return;
          if (id === 'call-outcome-demo-time') el.value = '10:00';
          else el.value = '';
        });
        setCallOutcomeError('demo_date', '');
        setCallOutcomeError('demo_time', '');
        setCallOutcomeError('meeting_link', '');
      }
    }
  }

  function validateCallOutcomeForm(form) {
    clearCallOutcomeErrors(form);
    var payload = Object.fromEntries(new FormData(form).entries());
    var errors = [];
    var outcome = (payload.outcome || '').trim();
    var remarks = (payload.remarks || '').trim();

    if (!outcome) errors.push({ field: 'outcome', message: 'Please select a call status.' });
    if (!remarks) errors.push({ field: 'remarks', message: 'Call note is required.' });

    if (outcome === 'Demo Scheduled') {
      if (!(payload.demo_date || '').trim()) errors.push({ field: 'demo_date', message: 'Demo date is required.' });
      if (!(payload.demo_time || '').trim()) errors.push({ field: 'demo_time', message: 'Demo time is required.' });
    }

    if (outcome === 'Follow-up Required') {
      if (!(payload.next_followup_date || '').trim()) {
        errors.push({ field: 'next_followup_date', message: 'Follow-up date is required.' });
      }
    }

    if (!payload.ca_id && !payload.followup_id) {
      errors.push({ field: 'outcome', message: 'Open this form from a lead or follow-up row.' });
    }

    errors.forEach(function (err) { setCallOutcomeError(err.field, err.message); });
    if (errors.length) {
      var first = form.querySelector('[name="' + errors[0].field + '"]')
        || form.querySelector('.input-field.is-invalid');
      if (first && typeof first.focus === 'function') first.focus();
      return null;
    }

    if (outcome === 'Demo Scheduled') {
      payload.demo_at = payload.demo_date + ' ' + payload.demo_time;
    }

    return payload;
  }

  function populateFollowupEditForm(followup) {
    var form = document.getElementById('form-followup');
    if (!form || !followup) return;
    if (form.elements.followup_type) form.elements.followup_type.value = followup.followup_type || 'Call Status';
    if (form.elements.remarks) form.elements.remarks.value = followup.remarks || '';
    if (form.elements.scheduled_date && followup.scheduled_date) {
      form.elements.scheduled_date.value = String(followup.scheduled_date).slice(0, 16);
    } else if (form.elements.scheduled_date) {
      form.elements.scheduled_date.value = '';
    }
    if (form.elements.priority) form.elements.priority.value = followup.priority || 'Normal';
    if (form.elements.team_size) form.elements.team_size.value = followup.team_size != null ? followup.team_size : '';
    if (form.elements.demo_provider_name) form.elements.demo_provider_name.value = followup.demo_provider_name || '';
    if (form.elements.meeting_link) form.elements.meeting_link.value = followup.meeting_link || '';
    resetFollowupDemoFieldState();
    window._followupOriginalScheduled = followup.scheduled_date ? String(followup.scheduled_date).slice(0, 16) : '';
  }

  function openFollowupFormForEdit(followupId) {
    if (!followupId) {
      toast('Follow-up record is missing. Refresh and try again.', 'warning');
      return;
    }
    var modal = document.getElementById('modal-followup');
    var form = document.getElementById('form-followup');
    if (!modal || !form) return;

    window._editingFollowUpId = followupId;
    var cached = findFollowupInCache(followupId);
    window._followupModalMode = 'row';
    setFollowupModalTitle(true);
    setFollowupLeadPickerVisible(false);
    clearFollowupLeadError();
    openModal(modal);
    setFollowupFormBusy(true);
    if (cached) populateFollowupEditForm(cached);

    Promise.all([
      cached ? Promise.resolve(cached) : fetchFollowupById(followupId),
      new Promise(function (resolve) { ensureFormSelectData(resolve); }),
    ])
      .then(function (results) {
        var followup = results[0];
        if (!followup) {
          toast('Follow-up not found. Refresh and try again.', 'warning');
          closeModal(modal);
          window._editingFollowUpId = null;
          setFollowupFormBusy(false);
          return null;
        }
        if (!cached) populateFollowupEditForm(followup);
        upsertFollowupInCache(followup);
        return initFollowupLeadContext(followup.ca_id ? parseInt(followup.ca_id, 10) : null);
      })
      .then(function () {
        if (!window._editingFollowUpId) return;
        initFollowUpDateTimeField();
        initFollowUpDemoFields();
        setFollowupFormBusy(false);
        iconsIn(modal);
      })
      .catch(function (err) {
        setFollowupFormBusy(false);
        if (err.status === 403) return;
        toast(err.message || 'Unable to load follow-up', 'warning');
      });
  }

  function teamSizeValue(lead) {
    var raw = lead.team_size;
    if (raw === null || raw === undefined || raw === '' || raw === 0 || raw === '0') {
      return null;
    }
    var parsed = parseInt(raw, 10);
    return isNaN(parsed) || parsed <= 0 ? null : parsed;
  }

  function renderTeamSizeCell(lead) {
    var size = teamSizeValue(lead);
    var tooltip;
    var labelFull;
    var labelCompact;

    if (size == null) {
      tooltip = 'Team Size\nNot Specified';
      labelFull = '⚪ Not Specified';
      labelCompact = '⚪ Not Specified';
    } else {
      tooltip = 'Team Size\n' + size + ' Employees';
      labelFull = '👥 ' + size + ' Employees';
      labelCompact = '👥 ' + size;
    }

    return '<span class="cam-team-size-cell" data-crm-tip="' + escapeHtml(tooltip) + '" aria-label="' + escapeHtml(tooltip.replace('\n', ' ')) + '">' +
      '<span class="cam-team-size-cell__full">' + escapeHtml(labelFull) + '</span>' +
      '<span class="cam-team-size-cell__compact">' + escapeHtml(labelCompact) + '</span>' +
    '</span>';
  }

  function teamMembersSummary(lead) {
    var summary = lead.team_members || {};
    var names = summary.names || lead.team_member_names || [];
    var count = summary.count != null ? summary.count : (lead.team_members_count != null ? lead.team_members_count : names.length);
    if (!count && lead.executive && lead.executive !== 'Unassigned' && lead.executive !== '—' && lead.executive !== 'Assigned') {
      count = 1;
      names = [lead.executive];
    }
    return { count: count, names: names };
  }

  function renderTeamMembersCell(lead) {
    var summary = teamMembersSummary(lead);
    var count = summary.count;
    var names = summary.names || [];
    var tooltip = names.length ? names.join('\n') : 'Unassigned';
    var label;
    var tone = 'empty';

    if (!count) {
      label = '⚪ Unassigned';
    } else if (count === 1) {
      label = names[0] ? ('👤 ' + names[0]) : '1 Member';
      tone = 'single';
    } else {
      label = '👥 ' + count + ' Members';
      tone = 'multi';
    }

    return '<button type="button" class="cam-team-members-btn cam-team-members-btn--' + tone + '" data-crm-tip="' + escapeHtml(tooltip) + '" data-team-members-open="' + escapeHtml(String(lead.ca_id)) + '" aria-label="View assigned team members">' +
      '<span class="cam-team-members-btn__label">' + escapeHtml(label) + '</span>' +
    '</button>';
  }

  function teamMemberAvailabilityBadge(status) {
    var normalized = String(status || 'Offline');
    var icon = '⚪';
    var cls = 'offline';
    if (normalized === 'Available') {
      icon = '🟢';
      cls = 'available';
    } else if (normalized === 'Busy') {
      icon = '🟡';
      cls = 'busy';
    } else if (normalized === 'Leave') {
      icon = '🔴';
      cls = 'leave';
    }
    return '<span class="cam-team-avail cam-team-avail--' + cls + '">' + icon + ' ' + escapeHtml(normalized) + '</span>';
  }

  function renderLeadTeamMembersDrawerBody(payload) {
    var members = (payload && payload.members) || [];
    if (!members.length) {
      return '<div class="cam-team-drawer-empty">⚪ No employees are assigned to this firm yet.</div>';
    }

    return members.map(function (member) {
      var role = member.role ? '<span class="cam-team-member-role">' + escapeHtml(member.role) + '</span>' : '';
      var assignedDate = member.assigned_date
        ? '<span class="cam-team-member-date">Assigned ' + escapeHtml(formatDate(member.assigned_date)) + '</span>'
        : '';
      var ownerBadge = member.is_lead_owner
        ? '<span class="cam-team-owner-badge">Lead Owner</span>'
        : '';
      var inactiveNote = member.is_active === false
        ? '<span class="cam-team-member-note">Inactive / deleted employee</span>'
        : '';

      return '<article class="cam-team-member-card">' +
        '<div class="cam-team-member-head">' +
          '<div class="cam-team-member-name">👤 ' + escapeHtml(member.name || 'Unknown Employee') + '</div>' +
          ownerBadge +
        '</div>' +
        role +
        '<div class="cam-team-member-meta">' +
          teamMemberAvailabilityBadge(member.availability_status) +
          assignedDate +
        '</div>' +
        inactiveNote +
      '</article>';
    }).join('');
  }

  function openLeadTeamMembersDrawer(caId) {
    var modal = document.getElementById('modal-lead-team-members');
    var body = document.getElementById('lead-team-members-body');
    var titleFirm = document.getElementById('lead-team-members-firm');
    if (!modal || !body) return;

    window._leadTeamMembersCaId = String(caId);
    body.innerHTML = '<div class="cam-team-drawer-loading"><i data-lucide="loader-2" class="h-5 w-5 animate-spin"></i> Loading team members…</div>';
    if (titleFirm) titleFirm.textContent = '…';
    openExclusiveCrmModal(modal);
    icons();

    apiFetch('/ca-masters/' + encodeURIComponent(caId) + '/team-members')
      .then(function (response) {
        var payload = response.data || {};
        window._leadTeamMembersPayload = payload;
        if (titleFirm) titleFirm.textContent = payload.firm_name || 'Lead #' + caId;
        body.innerHTML = renderLeadTeamMembersDrawerBody(payload);
        icons();
        if (window.CrmInstantTooltip && typeof window.CrmInstantTooltip.refresh === 'function') {
          window.CrmInstantTooltip.refresh(modal);
        }
      })
      .catch(function (error) {
        body.innerHTML = '<div class="cam-team-drawer-empty text-rose-600">' + escapeHtml(error.message || 'Unable to load team members.') + '</div>';
      });
  }

  function initLeadTeamMembersDrawer() {
    if (document.body._leadTeamMembersInit) return;
    document.body._leadTeamMembersInit = true;

    document.addEventListener('click', function (event) {
      var openBtn = event.target.closest('[data-team-members-open]');
      if (openBtn) {
        event.preventDefault();
        event.stopPropagation();
        openLeadTeamMembersDrawer(openBtn.getAttribute('data-team-members-open'));
        return;
      }

      var viewBtn = event.target.closest('[data-team-members-view-assignment]');
      if (viewBtn) {
        event.preventDefault();
        var payload = window._leadTeamMembersPayload || {};
        var members = payload.members || [];
        var owner = members.find(function (member) { return member.is_lead_owner; }) || members[0];
        closeModal(document.getElementById('modal-lead-team-members'));
        if (owner && owner.assignment_id && typeof openAssignmentFormForEdit === 'function') {
          openAssignmentFormForEdit(owner.assignment_id);
          return;
        }
        if (typeof navigateTo === 'function') navigateTo('assignment');
        return;
      }

      var reassignBtn = event.target.closest('[data-team-members-reassign]');
      if (reassignBtn) {
        event.preventDefault();
        var caId = window._leadTeamMembersCaId;
        closeModal(document.getElementById('modal-lead-team-members'));
        if (caId) openInboxAssign([caId]);
      }
    });
  }

  function lastActivityMeta(lead) {
    return lead.last_activity || null;
  }

  function lastActivityIcon(type) {
    var map = {
      call: '📞',
      whatsapp: '💬',
      email: '📧',
      follow_up: '📅',
      lead_created: '✨',
      lead_updated: '✏️',
      assignment: '👤',
      status_changed: '🏷️',
      lead_action: '🔀',
      sms: '💬',
    };
    return map[type] || '📝';
  }

  function renderLastActivityCell(lead) {
    var activity = lastActivityMeta(lead);
    if (!activity || !activity.occurred_at) {
      return '<span class="cam-last-activity cam-last-activity--none">⚪ No Activity Yet</span>';
    }

    var tooltip = (lastActivityIcon(activity.type) + ' ' + (activity.label || 'Activity') + '\n' +
      (activity.employee_name || 'System') + '\n' +
      (activity.date_label || '') + '\n' +
      (activity.time_label || '') +
      (activity.note ? ('\n' + activity.note) : ''));

    return '<button type="button" class="cam-last-activity cam-last-activity--' + escapeHtml(activity.age_bucket || 'none') + '" data-crm-tip="' + escapeHtml(tooltip) + '" data-activity-timeline-open="' + escapeHtml(String(lead.ca_id)) + '" aria-label="View activity timeline">' +
      '<span class="cam-last-activity__badge">' + escapeHtml(activity.emoji || '⚪') + ' ' + escapeHtml(activity.relative_label || 'Activity') + '</span>' +
      '<span class="cam-last-activity__time">' + escapeHtml(activity.time_label || '') + '</span>' +
    '</button>';
  }

  function renderLastActivityDisplayCell(lead) {
    var activity = lastActivityMeta(lead);
    if (!activity || !activity.occurred_at) {
      return '<span class="cam-last-activity cam-last-activity--none cam-last-activity--static">⚪ No Activity Yet</span>';
    }

    var tooltip = (lastActivityIcon(activity.type) + ' ' + (activity.label || 'Activity') + '\n' +
      (activity.employee_name || 'System') + '\n' +
      (activity.date_label || '') + '\n' +
      (activity.time_label || '') +
      (activity.note ? ('\n' + activity.note) : ''));

    return '<span class="cam-last-activity cam-last-activity--static cam-last-activity--' + escapeHtml(activity.age_bucket || 'none') + '" data-crm-tip="' + escapeHtml(tooltip) + '" title="' + escapeHtml(tooltip.replace(/\n/g, ' · ')) + '">' +
      '<span class="cam-last-activity__badge">' + escapeHtml(activity.emoji || '⚪') + ' ' + escapeHtml(activity.relative_label || 'Activity') + '</span>' +
      '<span class="cam-last-activity__time">' + escapeHtml(activity.time_label || '') + '</span>' +
    '</span>';
  }

  function renderLeadActivityTimelineBody(payload) {
    var items = (payload && payload.items) || [];
    if (!items.length) {
      return '<div class="cam-activity-timeline-empty">⚪ No Activity Yet</div>';
    }

    var lastGroup = '';
    return items.map(function (item) {
      var group = item.group_label || item.relative_label || '';
      var groupHtml = group !== lastGroup
        ? '<div class="cam-activity-timeline-group">' + escapeHtml(group) + '</div>'
        : '';
      lastGroup = group;
      var note = item.description || item.note;
      return groupHtml +
        '<article class="cam-activity-timeline-item">' +
          '<div class="cam-activity-timeline-icon">' + lastActivityIcon(item.type) + '</div>' +
          '<div class="cam-activity-timeline-body">' +
            '<div class="cam-activity-timeline-title">' + escapeHtml(item.label || 'Activity') + '</div>' +
            '<div class="cam-activity-timeline-meta">' + escapeHtml(item.employee_name || 'System') + ' · ' + escapeHtml(item.time_label || '') + '</div>' +
            (note ? '<div class="cam-activity-timeline-note truncate">' + escapeHtml(note) + '</div>' : '') +
          '</div>' +
        '</article>';
    }).join('');
  }

  function openLeadActivityTimelineDrawer(caId) {
    var modal = document.getElementById('modal-lead-activity-timeline');
    var body = document.getElementById('lead-activity-timeline-body');
    var titleFirm = document.getElementById('lead-activity-timeline-firm');
    if (!modal || !body) return;

    body.innerHTML = '<div class="cam-activity-timeline-loading"><i data-lucide="loader-2" class="h-5 w-5 animate-spin"></i> Loading activity…</div>';
    if (titleFirm) titleFirm.textContent = '…';
    openExclusiveCrmModal(modal);
    icons();

    apiFetch('/ca-masters/' + encodeURIComponent(caId) + '/activity-timeline?limit=10')
      .then(function (response) {
        var payload = response.data || {};
        if (titleFirm) titleFirm.textContent = payload.firm_name || 'Lead #' + caId;
        body.innerHTML = renderLeadActivityTimelineBody(payload);
        icons();
        if (window.CrmInstantTooltip && typeof window.CrmInstantTooltip.refresh === 'function') {
          window.CrmInstantTooltip.refresh(modal);
        }
      })
      .catch(function (error) {
        body.innerHTML = '<div class="cam-activity-timeline-empty text-rose-600">' + escapeHtml(error.message || 'Unable to load activity timeline.') + '</div>';
      });
  }

  function initLeadActivityTimelineDrawer() {
    if (document.body._leadActivityTimelineInit) return;
    document.body._leadActivityTimelineInit = true;

    document.addEventListener('click', function (event) {
      var openBtn = event.target.closest('[data-activity-timeline-open]');
      if (!openBtn) return;
      event.preventDefault();
      event.stopPropagation();
      openLeadActivityTimelineDrawer(openBtn.getAttribute('data-activity-timeline-open'));
    });
  }

  function renderCaMasterTableRow(l, tableKey) {
    var data = JSON.stringify(CAData.leadToRowData(l)).replace(/'/g, '&#39;');
    var executive = l.executive && l.executive !== 'Unassigned'
      ? compactTextCell(l.executive)
      : '<span class="cam-cell-text cam-cell-empty">Unassigned</span>';
    tableKey = tableKey || getCaMasterTableContext().tbodyId || 'ca-master-data-table';
    return '<tr class="ca-table-row cam-table-row crm-table-row cam-master-data-row" data-lead-id="' + l.ca_id + '" data-row=\'' + data + '\'>' +
      renderInboxCheckCell(tableKey, l.ca_id) +
      '<td class="sticky-left-2 crm-td-firm cam-master-data-cell">' + firmNameCell(l.firm_name) + '</td>' +
      '<td class="crm-td-ca cam-master-data-cell">' + caNameCell(l.ca_name) + '</td>' +
      '<td class="cam-td-team-size cam-master-data-cell">' + renderTeamSizeCell(l) + '</td>' +
      '<td class="cam-td-last-activity cam-master-data-cell">' + renderLastActivityDisplayCell(l) + '</td>' +
      '<td class="cam-td-mobile cam-master-data-cell">' + camPhoneDisplayCell(l.mobile_no) + '</td>' +
      renderLeadCallLogQuickCell(l) +
      '<td class="cam-td-mobile cam-master-data-cell">' + camPhoneDisplayCell(l.alternate_mobile_no) + '</td>' +
      '<td class="cam-td-geo cam-master-data-cell">' + compactTextCell(l.city) + '</td>' +
      '<td class="cam-td-geo cam-master-data-cell">' + compactTextCell(l.state) + '</td>' +
      '<td class="cam-td-source cam-master-data-cell">' + compactTextCell(l.source) + '</td>' +
      '<td class="cam-td-rating cam-master-data-cell"><span class="cam-cell-rating cam-master-display-text" title="' + (l.rating || 0) + ' stars">' + stars(l.rating) + '</span></td>' +
      '<td class="cam-td-status cam-master-data-cell"><span class="cam-cell-badge">' + statusBadge(l.status) + '</span></td>' +
      '<td class="cam-td-person cam-master-data-cell">' + executive + '</td>' +
      '<td class="cam-td-person cam-master-data-cell">' + compactTextCell(l.created_by) + '</td>' +
      '<td class="crm-td-date cam-master-data-cell"><span class="cam-cell-text cam-cell-mono text-slate-500 cam-master-display-text" title="' + escapeHtml(l.updated || formatRelativeDate(l.updated_at)) + '">' + escapeHtml(l.updated || formatRelativeDate(l.updated_at)) + '</span></td>' +
      renderLeadResearchQuickCell(l, { master: true, tip: 'Google Places Lookup' }) +
      renderCaMasterActionCell(l) +
    '</tr>';
  }

  function renderCaMasterActionCell(lead) {
    return renderCrmRowActionsCell(lead, { master: true });
  }

  function renderCaMasterEmptyState(colspan, message) {
    return '<tr class="cam-empty-row"><td colspan="' + colspan + '">' +
      '<div class="cam-empty-state">' +
        '<i data-lucide="building-2" class="h-10 w-10 text-slate-300"></i>' +
        '<p class="cam-empty-title">' + escapeHtml(message || 'No firms found') + '</p>' +
        '<p class="cam-empty-sub">Try adjusting filters or add a new firm.</p>' +
      '</div></td></tr>';
  }

  function renderCaMasterLoadingState(colspan) {
    return '<tr class="cam-loading-row"><td colspan="' + colspan + '">' +
      '<div class="cam-loading-state"><i data-lucide="loader-2" class="h-5 w-5 animate-spin text-brand"></i><span>Loading firms…</span></div>' +
    '</td></tr>';
  }

  function bindCaMasterActionMenus(root) {
    bindCrmRowActions(root);
    bindCrmActionsDismiss();
  }

  function setCaMasterSummaryActive(key) {
    document.querySelectorAll('[data-cam-summary]').forEach(function (card) {
      card.classList.toggle('is-active', card.getAttribute('data-cam-summary') === key);
    });
  }

  function applyCaMasterSummaryFilter(key) {
    if (key === 'duplicates') {
      if (typeof navigateTo === 'function') navigateTo('duplicate-attempts');
      else toast('Open Duplicate Attempts to review matches.', 'info');
      return;
    }

    var allTab = document.querySelector('.ca-tab[data-tab="all"][data-tab-group="main"]');

    if (key === 'new') {
      setCamView('all');
      window._leadSegmentFilter = 'all';
      var stageEl = document.getElementById('cam-filter-pipeline-stage');
      if (stageEl) stageEl.value = '';
      if (window.CA_LISTING_SEARCH) {
        CA_LISTING_SEARCH.setState('ca_masters', {
          page: 1,
          filters: Object.assign({}, readCaMasterColumnFilters(), { segment: 'new' }),
        });
      }
      syncCamStageFilterBarVisibility();
      renderCaMasterTable();
      setCaMasterSummaryActive(key);
      toast('Showing New Firms', 'info');
      return;
    }

    if (allTab && !allTab.classList.contains('active')) {
      allTab.click();
    }

    document.querySelectorAll('.crm-col-filter-input[data-col-filter-group="ca_masters"]').forEach(function (input) {
      input.value = '';
    });
    var stageEl = document.getElementById('cam-filter-pipeline-stage');
    if (stageEl) stageEl.value = '';
    window._leadSegmentFilter = 'all';

    var filters = {};
    var label = 'All Firms';
    if (key === 'active') {
      filters.status = 'Active';
      label = 'Active Firms';
    } else if (key === 'missing-mobile') {
      filters.segment = 'mobile_missing';
      label = 'Missing Mobile';
    } else if (key === 'verified') {
      filters.is_verified = 'true';
      label = 'Verified Firms';
    } else {
      key = 'total';
      label = 'Total Firms';
    }

    if (window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.setState('ca_masters', { page: 1, search: '', filters: filters });
    }
    renderCaMasterTable();
    setCaMasterSummaryActive(key);
    toast('Showing ' + label, 'info');
  }

  function setFollowupKpiActive(filterKey) {
    document.querySelectorAll('[data-kpi-listing="follow_ups"]').forEach(function (card) {
      card.classList.toggle('is-active', card.getAttribute('data-kpi-filter') === filterKey);
    });
  }

  function applyFollowupKpiFilter(filterKey) {
    var filters = {};
    var label = 'All follow-ups';
    if (filterKey === 'today') {
      filters.followup_due = 'today';
      label = 'Due Today';
    } else if (filterKey === 'overdue') {
      filters.followup_due = 'overdue';
      label = 'Overdue';
    } else if (filterKey === 'pending') {
      filters.followup_due = 'pending';
      label = 'Pending';
    } else if (filterKey === 'completed') {
      filters.followup_due = 'completed';
      label = 'Completed';
    } else {
      filterKey = '';
    }

    window._followupCalSelectedDay = null;
    window._followupDateFilter = '';
    document.querySelectorAll('[data-fu-type]').forEach(function (chip) {
      chip.classList.remove('active');
    });

    if (window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.setState('follow_ups', { page: 1, filters: filters });
      reloadListing('follow_ups');
    } else {
      renderFollowupsTable();
      renderFollowupCalendarFromData();
    }
    setFollowupKpiActive(filterKey);
    toast('Showing ' + label, 'info');
  }

  function loadCaMasterSummaryCards() {
    var paintFromMetrics = function (m) {
      setText('cam-stat-total', m.total_leads != null ? m.total_leads : '—');
      setText('cam-stat-new', m.new_leads != null ? m.new_leads : '—');
      var active = Math.max(0, (m.total_leads || 0) - (m.lost_leads || 0));
      setText('cam-stat-active', m.total_leads != null ? active : '—');
      var dup = 0;
      var prod = m.productivity || {};
      (prod.most_duplicate_attempts || []).forEach(function (row) {
        dup += row.duplicate_attempts || 0;
      });
      setText('cam-stat-duplicates', dup || '0');
    };
    if (dashboardMetricsLoaded && window.dashboardMetrics) {
      paintFromMetrics(window.dashboardMetrics);
    } else {
      loadDashboardMetricsFromDatabase(function () {
        paintFromMetrics(window.dashboardMetrics || {});
      });
    }
    if (window._camSegmentCountsLoaded) {
      return;
    }
    window._camSegmentCountsLoaded = true;
    apiFetch('/ca-masters?per_page=1&segment=mobile_missing')
      .then(function (body) {
        var parsed = window.CA_LISTING_SEARCH ? CA_LISTING_SEARCH.unwrapListingBody(body) : { pagination: null };
        var total = parsed.pagination ? parsed.pagination.total : null;
        setText('cam-stat-missing-mobile', total != null ? total : '—');
      })
      .catch(function () { setText('cam-stat-missing-mobile', '—'); });
    apiFetch('/ca-masters?per_page=1&is_verified=true')
      .then(function (body) {
        var parsed = window.CA_LISTING_SEARCH ? CA_LISTING_SEARCH.unwrapListingBody(body) : { pagination: null };
        var total = parsed.pagination ? parsed.pagination.total : null;
        setText('cam-stat-verified', total != null ? total : '—');
      })
      .catch(function () { setText('cam-stat-verified', '—'); });
  }

  function readCaMasterColumnFilters() {
    var filters = {};
    var activePanel = document.querySelector('.ca-tab-panel[data-tab-group="cam-view"][data-panel="all"].active')
      || document.querySelector('.ca-tab-panel[data-tab-group="leads-view"][data-panel="all"].active')
      || document.querySelector('.ca-tab-panel[data-tab-group="main"].active');
    var root = activePanel || document;
    root.querySelectorAll('.crm-col-filter-input[data-col-filter-group="ca_masters"]').forEach(function (input) {
      var key = input.getAttribute('data-col-filter');
      var value = (input.value || '').trim();
      if (key && value) filters[key] = value;
    });
    return filters;
  }

  function applyCaMasterListingFilters(extraFilters) {
    var filters = buildCaMasterListingFilters(extraFilters);
    if (window.CA_LISTING_SEARCH) {
      CA_LISTING_SEARCH.setState('ca_masters', {
        page: 1,
        search: '',
        filters: filters,
      });
    }
    syncCamStageFilterBarVisibility();
    if (isCamPipelineTabActive()) loadKanbanLeads();
    else renderCaMasterTable();
  }

  function initCaMasterPage() {
    var page = document.getElementById('cam-hub');
    if (!page) return;
    if (page._camInit) return;
    page._camInit = true;

    page.addEventListener('change', function (e) {
      if (e.target && e.target.id === 'cam-filter-pipeline-stage') {
        var filters = buildCaMasterListingFilters();
        if (e.target.value) {
          delete filters.segment;
          window._leadSegmentFilter = 'all';
        }
        if (window.CA_LISTING_SEARCH) {
          CA_LISTING_SEARCH.setState('ca_masters', { page: 1, filters: filters });
        }
        syncCamStageFilterBarVisibility();
        if (isCamPipelineTabActive()) loadKanbanLeads();
        else renderCaMasterTable();
        return;
      }
      var select = e.target.closest('select.crm-col-filter-input[data-col-filter-group="ca_masters"]');
      if (!select) return;
      applyCaMasterListingFilters();
    });

    page.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && e.target && e.target.id === 'cam-filter-pipeline-stage') {
        e.target.blur();
      }
    });

    var colFilterTimer = null;
    page.addEventListener('input', function (e) {
      var input = e.target.closest('.crm-col-filter-input[data-col-filter-group="ca_masters"]');
      if (!input || input.tagName === 'SELECT') return;
      window.clearTimeout(colFilterTimer);
      colFilterTimer = window.setTimeout(function () {
        applyCaMasterListingFilters();
      }, 320);
    });

    page.addEventListener('click', function (e) {
      if (e.target.closest('#cam-filter-reset')) {
        resetCaMasterTableFilters();
        return;
      }
      var backBtn = e.target.closest('[data-cam-action="back-to-firms"]');
      if (backBtn) {
        setCamView('all');
        return;
      }
    });

    if (page.dataset.camSecondary === 'masters' || page.dataset.camSecondary === 'bulk') {
      showCamSecondaryView(page.dataset.camSecondary);
    }

    bindCrmActionsDismiss();
    initLeadTeamMembersDrawer();
    initLeadActivityTimelineDrawer();
    syncCamStageFilterFromState();
  }

  function renderCaMasterTable(pageLeads, targetTbodyId) {
    var ctx = getCaMasterTableContext();
    var tbodyId = targetTbodyId || ctx.tbodyId;
    if (typeof tbodyId === 'object' && tbodyId.id) tbodyId = tbodyId.id;
    var el = document.getElementById(tbodyId);
    if (!el) return;
    var colCount = 18;

    if (pageLeads === undefined && window.CA_LISTING_SEARCH) {
      if (!el.querySelector('tr')) {
        el._camRowHtml = null;
        el.innerHTML = renderCaMasterLoadingState(colCount);
        icons();
      }
      reloadListing('ca_masters').catch(function () {
        if (!el.querySelector('.cam-table-row') && !el.querySelector('.cam-empty-row')) {
          el.innerHTML = renderCaMasterEmptyState(colCount, 'Unable to load firms. Please try again.');
          icons();
        }
      });
      return;
    }

    var leads = pageLeads || window._listingLeadsPage || [];
    var rowHtml = leads.length
      ? leads.map(function (lead) { return renderCaMasterTableRow(lead, tbodyId); }).join('')
      : renderCaMasterEmptyState(colCount, tbodyId === 'ca-master-new-data-table' ? 'No new firms yet.' : 'No firms yet. Click Add Firm to create one.');

    if (el._camRowHtml === rowHtml && el.querySelector('.cam-table-row, .cam-empty-row')) {
      bindCaMasterTableRows(el);
      syncInboxChecks(tbodyId);
      icons();
      return;
    }
    el._camRowHtml = rowHtml;

    el.innerHTML = rowHtml;

    bindCaMasterTableRows(el);
    syncInboxChecks(tbodyId);
    icons();
  }

  function bindCaMasterTableRows(container) {
    if (!container) return;
    bindCaMasterActionMenus(container.closest('#cam-hub') || container.closest('.cam-page') || document);
  }

  function renderLeaderboard() {
    var el = document.getElementById('leaderboard');
    if (!el) return;
    var execs = getDashboardExecutives().slice().sort(function (a, b) {
      return (b.achieved_leads || 0) - (a.achieved_leads || 0);
    });
    if (!execs.length) {
      el.innerHTML = '<h3 class="text-card-heading mb-4 flex items-center gap-2"><i data-lucide="trophy" class="h-5 w-5 text-amber-500"></i> Team Leaderboard</h3>' +
        '<p class="text-caption text-slate-400">No active employees yet.</p>';
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

  function renderEmployeesPerformanceTable() {
    var el = document.getElementById('employees-performance-table');
    if (!el) return;
    var execs = getDashboardExecutives();
    if (!execs.length) {
      el.innerHTML = '<tr><td colspan="6" class="text-center text-slate-500 p-4">No performance data yet.</td></tr>';
      return;
    }
    el.innerHTML = execs.map(function (e) {
      var targetPct = e.target_leads ? Math.round(((e.achieved_leads || 0) / e.target_leads) * 100) : 0;
      return '<tr class="ca-table-row">' +
        '<td class="font-medium">' + escapeHtml(e.name) + '</td>' +
        '<td>' + escapeHtml(String(e.daily_calls || 0)) + '</td>' +
        '<td>' + escapeHtml(String(e.demos || e.demo_count || 0)) + '</td>' +
        '<td>' + escapeHtml(e.conversion || '—') + '</td>' +
        '<td>' + escapeHtml(e.revenue || '—') + '</td>' +
        '<td>' + escapeHtml(String(targetPct) + '%') + '</td>' +
      '</tr>';
    }).join('');
  }

  function leadMatchesKanbanSearch(lead, rawQuery) {
    var query = String(rawQuery || '').trim().toLowerCase();
    if (!query) return true;
    var haystack = [
      lead.firm_name,
      lead.ca_name,
      lead.mobile_no,
      lead.alternate_mobile_no,
      lead.email_id,
      lead.city,
      lead.city_name,
      lead.state,
      lead.state_name,
      lead.source,
      lead.source_name,
      lead.executive,
    ].map(function (value) {
      return String(value == null || value === '—' ? '' : value).toLowerCase();
    }).join(' ');
    return haystack.indexOf(query) >= 0;
  }

  function formatKanbanLastActivity(lead) {
    var activity = lead.last_activity;
    if (activity && activity.relative_label && activity.time_label) {
      return activity.relative_label + ' • ' + activity.time_label;
    }
    if (lead.last_activity_at) return formatActivityTimestamp(lead.last_activity_at);
    if (lead.updated && lead.updated !== '—') return lead.updated;
    return '—';
  }

  function formatKanbanLastActivityCompact(lead) {
    var activity = lead.last_activity;
    if (activity && activity.relative_label && activity.time_label) {
      return activity.relative_label + ', ' + activity.time_label;
    }
    if (lead.last_activity_at) return formatActivityTimestamp(lead.last_activity_at);
    if (lead.updated && lead.updated !== '—') return lead.updated;
    return '—';
  }

  function kanbanPriorityBadge(lead, compact) {
    var priority = String(lead.priority || lead.lead_priority || '').trim();
    var rating = parseInt(lead.rating_stars != null ? lead.rating_stars : lead.rating, 10);
    var label = '';
    if (priority === 'High' || priority === 'Urgent') label = compact ? 'High' : 'High Priority';
    else if (priority === 'Low') label = compact ? 'Low' : 'Low Priority';
    else if (rating >= 5 || rating === 4) label = compact ? 'High' : 'High Priority';
    if (!label) return '';
    return '<span class="kanban-card-priority">' + escapeHtml(label) + '</span>';
  }

  function buildKanbanCardHtml(l) {
    if (isMasterDataHub()) {
      var employee = l.executive && l.executive !== 'Unassigned' ? l.executive : 'Unassigned';
      var city = l.city && l.city !== '—' ? l.city : '—';
      var priorityHtml = kanbanPriorityBadge(l, true);
      return '<div class="kanban-card kanban-card--master" draggable="true" data-lead-id="' + l.ca_id + '">' +
        '<div class="kanban-card-top">' +
          '<p class="kanban-card-firm">' + escapeHtml(l.firm_name || '—') + '</p>' +
          (priorityHtml || '') +
        '</div>' +
        '<p class="kanban-card-meta">' + escapeHtml(city) + ' · ' + escapeHtml(employee) + '</p>' +
        '<p class="kanban-card-activity">' + escapeHtml(formatKanbanLastActivityCompact(l)) + '</p>' +
      '</div>';
    }
    var hot = l.status === 'Hot' ? ' kanban-card-hot' : '';
    return '<div class="kanban-card mb-2' + hot + '" draggable="true" data-lead-id="' + l.ca_id + '">' +
      '<div class="flex justify-between gap-2"><p class="text-body font-medium">' + escapeHtml(l.firm_name || '—') + '</p>' +
      (l.status === 'Hot' ? '<span class="badge bg-amber-50 text-amber-700 text-xs">Hot</span>' : '') + '</div>' +
      '<p class="text-caption text-slate-400">' + escapeHtml(l.city || '—') + ' · ' + escapeHtml(l.executive || '—') + '</p></div>';
  }

  function renderKanbanStageCardsHtml(items, query, options) {
    options = options || {};
    var filtered = !query
      ? items
      : items.filter(function (l) { return leadMatchesKanbanSearch(l, query); });
    if (!items.length) {
      var emptyCopy = isMasterDataHub()
        ? (options.emptyLabel || 'No leads in this stage')
        : (options.emptyLabel || 'No Leads in this Stage');
      var emptyCls = isMasterDataHub() ? 'kanban-empty kanban-empty--compact' : 'kanban-empty';
      return '<div class="' + emptyCls + '">' +
        '<i data-lucide="inbox" class="kanban-empty__icon" aria-hidden="true"></i>' +
        '<span>' + escapeHtml(emptyCopy) + '</span>' +
      '</div>';
    }
    if (!filtered.length) {
      var searchEmptyCls = isMasterDataHub() ? 'kanban-empty kanban-empty--compact kanban-empty--search' : 'kanban-empty kanban-empty--search';
      return '<div class="' + searchEmptyCls + '">' +
        '<i data-lucide="search-x" class="kanban-empty__icon" aria-hidden="true"></i>' +
        '<span>No matching leads</span>' +
      '</div>';
    }
    return filtered.map(buildKanbanCardHtml).join('');
  }

  function updateKanbanStageColumn(columnEl) {
    if (!columnEl) return;
    var stage = columnEl.getAttribute('data-stage');
    var query = String(kanbanStageSearch[stage] || '').trim();
    var filtered = getRealLeadsFiltered().filter(function (l) { return leadPipelineStage(l) === stage; });
    var stageCounts = window.kanbanStageCounts || {};
    var totalInStage = stageCounts[stage] != null ? stageCounts[stage] : filtered.length;
    var visible = query
      ? filtered.filter(function (l) { return leadMatchesKanbanSearch(l, query); })
      : filtered;
    var countEl = columnEl.querySelector('[data-kanban-count]');
    if (countEl) {
      countEl.textContent = query
        ? (visible.length + ' / ' + totalInStage)
        : String(totalInStage);
      countEl.title = query
        ? (visible.length + ' matching of ' + totalInStage + ' in stage')
        : (totalInStage + ' firms in stage');
    }
    var metaEl = columnEl.querySelector('[data-kanban-filter-meta]');
    if (metaEl) {
      if (query) {
        metaEl.textContent = visible.length + ' of ' + filtered.length + ' shown';
        metaEl.classList.remove('hidden');
      } else {
        metaEl.textContent = '';
        metaEl.classList.add('hidden');
      }
    }
    var clearBtn = columnEl.querySelector('[data-kanban-search-clear]');
    if (clearBtn) clearBtn.classList.toggle('hidden', !query);
    var cardsEl = columnEl.querySelector('.kanban-column-cards');
    if (cardsEl) {
      cardsEl.innerHTML = renderKanbanStageCardsHtml(filtered, query);
      bindKanbanCardInteractions(cardsEl);
    }
  }

  function bindKanbanCardInteractions(scope) {
    var root = scope || document.getElementById('kanban-board');
    if (!root) return;
    root.querySelectorAll('.kanban-card').forEach(function (card) {
      if (card._kanbanBound) return;
      card._kanbanBound = true;
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
  }

  function bindKanbanBoardInteractions(container) {
    if (!container || container._kanbanBoardBound) return;
    container._kanbanBoardBound = true;

    container.addEventListener('input', function (e) {
      var input = e.target.closest('[data-kanban-stage-search]');
      if (!input) return;
      var stage = input.getAttribute('data-kanban-stage-search');
      var column = input.closest('.kanban-column');
      var value = input.value || '';
      window.clearTimeout(kanbanStageSearchTimers[stage]);
      kanbanStageSearchTimers[stage] = window.setTimeout(function () {
        kanbanStageSearch[stage] = value;
        updateKanbanStageColumn(column);
      }, 350);
      var clearBtn = column && column.querySelector('[data-kanban-search-clear]');
      if (clearBtn) clearBtn.classList.toggle('hidden', !String(value).trim());
    });

    container.addEventListener('click', function (e) {
      var clearBtn = e.target.closest('[data-kanban-search-clear]');
      if (!clearBtn) return;
      e.preventDefault();
      var column = clearBtn.closest('.kanban-column');
      var stage = clearBtn.getAttribute('data-kanban-search-clear');
      var input = column && column.querySelector('[data-kanban-stage-search]');
      kanbanStageSearch[stage] = '';
      if (input) {
        input.value = '';
        input.focus();
      }
      updateKanbanStageColumn(column);
    });

    container.addEventListener('dragover', function (e) {
      var col = e.target.closest('.kanban-column');
      if (!col || !container.contains(col)) return;
      e.preventDefault();
      col.classList.add('ring-2', 'ring-brand/30');
    });

    container.addEventListener('dragleave', function (e) {
      var col = e.target.closest('.kanban-column');
      if (!col || !container.contains(col)) return;
      if (col.contains(e.relatedTarget)) return;
      col.classList.remove('ring-2', 'ring-brand/30');
    });

    container.addEventListener('drop', function (e) {
      var col = e.target.closest('.kanban-column');
      if (!col || !container.contains(col)) return;
      e.preventDefault();
      col.classList.remove('ring-2', 'ring-brand/30');
      var leadId = e.dataTransfer.getData('text/plain');
      var stage = col.dataset.stage;
      if (!leadId || !stage) return;
      var lead = (window.kanbanLeads || []).find(function (l) { return String(l.ca_id) === String(leadId); })
        || (window._listingLeadsPage || []).find(function (l) { return String(l.ca_id) === String(leadId); });
      var previousStage = lead ? leadPipelineStage(lead) : null;
      var status = mapStageToStatus(stage);
      updateLeadStatus(leadId, status)
        .then(function (body) {
          var mapped = mergeLeadFromApiResponse(body);
          if (mapped) {
            mapped.master_pipeline_stage = mapStatusToMasterPipelineStage(mapped.status);
            mapped.stage = isMasterDataHub() ? mapped.master_pipeline_stage : mapStatusToStage(mapped.status);
            upsertLeadInCache(mapped);
          }
          if (previousStage && window.kanbanStageCounts) {
            if (window.kanbanStageCounts[previousStage] != null && previousStage !== stage) {
              window.kanbanStageCounts[previousStage] = Math.max(0, window.kanbanStageCounts[previousStage] - 1);
            }
            if (previousStage !== stage) {
              window.kanbanStageCounts[stage] = (window.kanbanStageCounts[stage] || 0) + 1;
            }
          }
          if (isMasterDataHub()) {
            var fromCol = previousStage ? container.querySelector('.kanban-column[data-stage="' + previousStage + '"]') : null;
            updateKanbanStageColumn(fromCol);
            updateKanbanStageColumn(col);
            toast('Firm moved to ' + stage, 'success');
            return;
          }
          invalidateDataCaches(['metrics', 'segment_counts', 'assignments']);
          loadAssignmentsFromDatabase(function () {
            enrichLeadsWithAssignments();
            refreshLeadsUi({ invalidateMetrics: true });
            toast('Lead moved to ' + stage, 'success');
          });
        })
        .catch(function (err) {
          toast(err.message || 'Unable to update lead stage', 'error');
        });
    });
  }

  function renderKanbanFromData() {
    var container = document.getElementById('kanban-board');
    if (!container) return;
    if (leadsHubLoading && !container.querySelector('.kanban-column')) {
      setLeadsHubLoadingState(true);
      return;
    }
    var masterHub = isMasterDataHub();
    var stages = masterHub
      ? getMasterPipelineColumns()
      : [
        { name: 'New Lead', key: 'New Lead', color: 'bg-slate-400' },
        { name: 'Details Shared', key: 'Details Shared', color: 'bg-blue-400' },
        { name: 'Demo Scheduled', key: 'Demo Scheduled', color: 'bg-brand' },
        { name: 'Demo Completed', key: 'Demo Completed', color: 'bg-indigo-400' },
        { name: 'Negotiation', key: 'Negotiation', color: 'bg-amber-400' },
        { name: 'Won', key: 'Won', color: 'bg-emerald-500' },
        { name: 'Lost', key: 'Lost', color: 'bg-red-400' },
      ];
    var filtered = getRealLeadsFiltered();
    var stageCounts = window.kanbanStageCounts || {};
    container.innerHTML = stages.map(function (col) {
      var items = filtered.filter(function (l) { return leadPipelineStage(l) === col.key; });
      var totalInStage = stageCounts[col.key] != null ? stageCounts[col.key] : items.length;
      var query = String(kanbanStageSearch[col.key] || '');
      var visibleCount = query
        ? items.filter(function (l) { return leadMatchesKanbanSearch(l, query); }).length
        : items.length;
      var countLabel = query ? (visibleCount + ' / ' + totalInStage) : String(totalInStage);
      var themeClass = col.theme ? (' kanban-column--' + col.theme) : '';
      var searchPlaceholder = masterHub ? 'Search leads...' : 'Search in this stage…';
      var searchMetaHtml = masterHub ? '' : (
        '<p class="kanban-stage-search__meta' + (query.trim() ? '' : ' hidden') + '" data-kanban-filter-meta">' +
          (query.trim() ? (visibleCount + ' of ' + items.length + ' shown') : '') +
        '</p>'
      );
      var titleIconHtml = masterHub && col.icon
        ? '<i data-lucide="' + col.icon + '" class="kanban-column-icon kanban-column-icon--' + col.theme + '" aria-hidden="true"></i>'
        : (masterHub ? '' : '<span class="kanban-column-dot ' + (col.color || 'bg-slate-400') + '"></span>');
      return '<div class="kanban-column' + themeClass + '" data-stage="' + escapeHtml(col.key) + '">' +
        '<div class="kanban-column-head">' +
          '<div class="kanban-column-title-row">' +
            '<div class="kanban-column-title">' +
              titleIconHtml +
              '<h4 class="kanban-column-name">' + escapeHtml(col.name) + '</h4>' +
            '</div>' +
            '<span class="kanban-column-count" data-kanban-count title="' + escapeHtml(String(totalInStage) + ' in stage') + '">' + escapeHtml(countLabel) + '</span>' +
          '</div>' +
          '<label class="kanban-stage-search">' +
            '<span class="sr-only">Search leads in ' + escapeHtml(col.name) + '</span>' +
            '<i data-lucide="search" class="kanban-stage-search__icon" aria-hidden="true"></i>' +
            '<input type="search" class="input-field kanban-stage-search__input" data-kanban-stage-search="' + escapeHtml(col.key) + '"' +
              ' placeholder="' + escapeHtml(searchPlaceholder) + '" value="' + escapeHtml(query) + '" autocomplete="off" />' +
            '<button type="button" class="kanban-stage-search__clear' + (query.trim() ? '' : ' hidden') + '" data-kanban-search-clear="' + escapeHtml(col.key) + '" title="Clear search" aria-label="Clear stage search">' +
              '<i data-lucide="x" aria-hidden="true"></i></button>' +
          '</label>' +
          searchMetaHtml +
        '</div>' +
        '<div class="kanban-column-cards">' +
          renderKanbanStageCardsHtml(items, query) +
        '</div></div>';
    }).join('');
    container.classList.toggle('kanban-board--master', masterHub);
    bindKanbanBoardInteractions(container);
    bindKanbanCardInteractions(container);
    var selId = CAData.getSelectedLeadId();
    if (selId) highlightLeadSelection(selId);
    icons();
  }

  function populateSelects() {
    enhanceEntityLookups();
  }

  function bindLeadRows(container) {
    if (!container) return;
    container.querySelectorAll('.ca-table-row[data-lead-id]').forEach(function (row) {
      if (row._leadRowClickBound) return;
      if (row.classList.contains('cam-master-data-row') || row.closest('#cam-hub')) return;
      row._leadRowClickBound = true;
      row.addEventListener('click', function (e) {
        if (e.target.closest('.crm-actions-cell, .crm-actions-menu, .lead-quick-cell, .lead-quick-actions-cell, [data-lead-quick], .crm-td-check, .crm-inbox-row-check')) return;
        selectLead(row.dataset.leadId, true);
      });
    });
  }

  function renderLeadGoogleFieldValue(label, value, isLink) {
    var display = value == null || value === '' ? '—' : String(value);
    var valueHtml = isLink && display !== '—'
      ? '<a class="text-brand hover:underline break-all" href="' + escapeHtml(display) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(display) + '</a>'
      : escapeHtml(display);
    return '<div class="detail-field"><span class="detail-field-label">' + escapeHtml(label) + '</span><span class="detail-field-value">' + valueHtml + '</span></div>';
  }

  function renderLeadGoogleFieldsSection(lead) {
    var section = document.getElementById('form-lead-google-section');
    var fields = document.getElementById('form-lead-google-fields');
    if (!section || !fields) return;

    if (!leadHasSavedGoogleData(lead)) {
      section.classList.add('hidden');
      fields.innerHTML = '';
      return;
    }

    var rating = lead.google_rating != null
      ? String(lead.google_rating) + (lead.google_review_count != null ? ' (' + lead.google_review_count + ' reviews)' : '')
      : null;
    var coordinates = lead.latitude != null && lead.longitude != null
      ? String(lead.latitude) + ', ' + String(lead.longitude)
      : null;

    fields.innerHTML =
      renderLeadGoogleFieldValue('Business Name', lead.firm_name && lead.firm_name !== '—' ? lead.firm_name : null) +
      renderLeadGoogleFieldValue('Address', lead.verified_address || lead.address) +
      renderLeadGoogleFieldValue('Google Maps URL', lead.google_maps_url, true) +
      renderLeadGoogleFieldValue('Place ID', lead.google_place_id) +
      renderLeadGoogleFieldValue('Latitude', lead.latitude) +
      renderLeadGoogleFieldValue('Longitude', lead.longitude) +
      renderLeadGoogleFieldValue('Rating', rating) +
      renderLeadGoogleFieldValue('Open/Closed', formatGoogleBusinessStatus(lead.google_business_status)) +
      renderLeadGoogleFieldValue('Last Saved', lead.researched_at ? formatDateTime(lead.researched_at) : null);

    section.classList.remove('hidden');
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
    var googleSection = document.getElementById('form-lead-google-section');
    if (!isEdit && googleSection) {
      googleSection.classList.add('hidden');
      var googleFields = document.getElementById('form-lead-google-fields');
      if (googleFields) googleFields.innerHTML = '';
    }
  }

  function applyLeadFormUiDefaults(form) {
    if (!form) return;
    if (form.elements.team_size) form.elements.team_size.value = '0';
    if (form.elements.existing_software) setSelectValueIfValid(form.elements.existing_software, 'None');
    if (form.elements.rating) form.elements.rating.value = '1';
    if (form.elements.is_newly_established) form.elements.is_newly_established.value = '';
    if (form.elements.status) setSelectValueIfValid(form.elements.status, 'New');
    if (form.elements.source_id) form.elements.source_id.value = '';
    if (form.elements.executive_id) form.elements.executive_id.value = '';
  }

  function resetLeadForm() {
    var form = document.getElementById('form-add-lead');
    if (!form) return;
    releaseLeadLock();
    form.reset();
    var caIdField = document.getElementById('form-lead-ca-id');
    if (caIdField) caIdField.value = '';
    applyLeadFormUiDefaults(form);
    setLeadFormMode('add');
    applyLeadFormAccessRules(null);
    var googleSection = document.getElementById('form-lead-google-section');
    if (googleSection) googleSection.classList.add('hidden');
    var googleFields = document.getElementById('form-lead-google-fields');
    if (googleFields) googleFields.innerHTML = '';
    if (window.CA_STATE_CITY) {
      window.CA_STATE_CITY.resetFormLocations(form);
    }
    icons();
  }

  function fillLeadForm(lead) {
    var form = document.getElementById('form-add-lead');
    if (!form || !lead) return;

    function isEmptyLeadValue(value) {
      return value == null || value === '' || value === '—';
    }

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
    form.elements.team_size.value = isEmptyLeadValue(lead.team_size) ? 0 : lead.team_size;
    if (form.elements.existing_software) {
      var software = isEmptyLeadValue(lead.existing_software) ? 'None' : String(lead.existing_software);
      var softwareSelect = form.elements.existing_software;
      var hasSoftwareOption = Array.prototype.some.call(softwareSelect.options, function (opt) {
        return opt.value === software || opt.text === software;
      });
      if (!hasSoftwareOption) {
        var softwareOpt = document.createElement('option');
        softwareOpt.value = software;
        softwareOpt.textContent = software;
        softwareSelect.appendChild(softwareOpt);
      }
      setSelectValueIfValid(softwareSelect, software);
    }
    form.elements.website.value = lead.website && lead.website !== '—' ? lead.website : '';
    form.elements.rating.value = isEmptyLeadValue(lead.rating) ? '1' : String(lead.rating);
    var newFirm = lead.is_newly_established;
    if (newFirm === true || newFirm === 1 || newFirm === 'yes' || newFirm === 'Yes') {
      form.elements.is_newly_established.value = 'yes';
    } else if (newFirm === false || newFirm === 0 || newFirm === 'no' || newFirm === 'No') {
      form.elements.is_newly_established.value = 'no';
    } else {
      form.elements.is_newly_established.value = 'no';
    }
    if (form.elements.source_id) {
      setSelectValueIfValid(
        form.elements.source_id,
        lead.source_id || lead.source_name || (lead.source && lead.source !== '—' ? lead.source : ''),
      );
    }
    var statusValue = lead.status || lead.demo_status;
    if (isEmptyLeadValue(statusValue)) statusValue = 'New';
    setSelectValueIfValid(form.elements.status, statusValue);
    if (form.elements.executive_id) form.elements.executive_id.value = lead.executive_id || '';

    var caIdField = document.getElementById('form-lead-ca-id');
    if (caIdField) caIdField.value = lead.ca_id;
    var lock = lead.lock || {};
    window._editingLeadId = lock.is_locked_by_other ? '' : lead.ca_id;
    setLeadFormMode('edit');
    applyLeadFormAccessRules(lead);
    renderLeadGoogleFieldsSection(lead);
  }

  function openLeadFormForAdd() {
    resetLeadForm();
    var modal = document.getElementById('modal-add-lead');
    resetModalScroll(modal);
    ensureFormSelectData(function () {
      if (window.CA_STATE_CITY) {
        window.CA_STATE_CITY.prepareForm('form-add-lead');
      }
      applyLeadFormUiDefaults(document.getElementById('form-add-lead'));
      applyLeadFormAccessRules(null);
      resetModalScroll(modal);
      var firmInput = document.querySelector('#form-add-lead [name="firm_name"]');
      if (firmInput && typeof firmInput.focus === 'function') {
        try { firmInput.focus({ preventScroll: true }); } catch (e) { firmInput.focus(); }
      }
    });
  }

  function openLeadFormForEdit(leadId) {
    var localLead = getLeadRecord(leadId);
    if (!localLead) {
      toast('Select a lead to edit', 'warning');
      return Promise.resolve(false);
    }

    return new Promise(function (resolve) {
      ensureFormSelectData(function () {
        apiFetch('/ca-masters/' + encodeURIComponent(leadId))
          .then(function (body) {
            var lead = body.data || localLead;
            return apiFetch('/ca-masters/' + encodeURIComponent(leadId) + '/lock', { method: 'POST' })
              .then(function (lockBody) {
                populateSelects();
                populateMasterDropdowns();
                fillLeadForm(lockBody.data || lead);
                resolve(true);
              })
              .catch(function (error) {
                if (error.status === 423) {
                  populateSelects();
                  populateMasterDropdowns();
                  var lockedLead = Object.assign({}, lead, {
                    lock: error.lock || { is_locked_by_other: true, locked_by_name: 'another employee' },
                  });
                  fillLeadForm(lockedLead);
                  toast(error.message || 'This lead is currently being edited by another employee.', 'warning');
                  resolve(true);
                  return;
                }
                throw error;
              });
          })
          .catch(function (error) {
            toast(error.message || 'Unable to open lead for editing', error.status === 423 ? 'warning' : 'error');
            resolve(false);
          });
      });
    });
  }

  function applyContactFormAccessRules(lead) {
    var form = document.getElementById('form-lead-contact');
    if (!form || !lead) return;
    var submitBtn = document.getElementById('lead-contact-submit-btn');
    var lockedByOther = !!(lead.lock && lead.lock.is_locked_by_other);
    var lockedFields = (isEmployeeUser() && lead.employee_locked_fields) ? lead.employee_locked_fields.slice() : [];
    var contactFields = ['mobile_no', 'alternate_mobile_no', 'email_id', 'website'];

    contactFields.forEach(function (name) {
      var el = form.elements[name];
      if (!el) return;
      var isLocked = lockedByOther || (name !== 'alternate_mobile_no' && lockedFields.indexOf(name) >= 0);
      if (name === 'mobile_no' && isEmployeeUser() && !lockedByOther) {
        isLocked = isLocked || !!lead.employee_cannot_edit_mobile || !!(lead.mobile_no && String(lead.mobile_no).trim() && lead.mobile_no !== '—');
      }
      setLeadFieldLockState(el, isLocked, leadFieldLockTooltip(name));
    });

    if (submitBtn) {
      submitBtn.disabled = lockedByOther;
      submitBtn.classList.toggle('opacity-50', lockedByOther);
      submitBtn.classList.toggle('pointer-events-none', lockedByOther);
    }
    icons();
  }

  function setCampaignSectionVisible(el, visible) {
    if (!el) return;
    el.classList.toggle('hidden', !visible);
  }

  function applyCampaignAudienceEmployeeScope() {
    var modeSelect = document.getElementById('form-campaign-audience-mode');
    if (!modeSelect) return;
    var allLeadsOption = modeSelect.querySelector('option[value="all_leads"]');
    if (!allLeadsOption) return;
    if (isEmployeeUser()) {
      allLeadsOption.disabled = true;
      allLeadsOption.hidden = true;
      if (modeSelect.value === 'all_leads') modeSelect.value = 'selected_leads';
    } else {
      allLeadsOption.disabled = false;
      allLeadsOption.hidden = false;
    }
  }

  function populateCampaignEmailSenders() {
    var select = document.getElementById('form-campaign-email-config-id');
    if (!select) return;
    apiFetch('/email-accounts').then(function (body) {
      var items = (body.data && body.data.items) ? body.data.items : [];
      var active = items.filter(function (a) { return a.is_active !== false; });
      var options = '<option value="">Default email account</option>' + active.map(function (a) {
        var label = (a.account_name || a.from_email || 'Account') + ' · ' + (a.from_email || '');
        if (a.is_default) label += ' (default)';
        return '<option value="' + a.id + '"' + (a.is_default ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
      }).join('');
      select.innerHTML = options;
    }).catch(function () {
      select.innerHTML = '<option value="">Default email account</option>';
    });
  }

  function populateCampaignSmsSender() {
    var select = document.getElementById('form-campaign-sms-sender-id');
    if (!select) return;
    apiFetch('/sms-settings').then(function (body) {
      var sms = body.data || {};
      var sender = sms.sender_id || 'Not configured';
      var disabled = !sms.is_active || !sms.sender_id;
      select.innerHTML = '<option value="' + (sms.id || '') + '"' + (disabled ? ' disabled' : ' selected') + '>' +
        escapeHtml(sender + (sms.is_active === false ? ' (inactive)' : '')) + '</option>';
      select.disabled = true;
    }).catch(function () {
      select.innerHTML = '<option value="">SMS sender unavailable</option>';
    });
  }

  function populateCampaignWhatsAppSender() {
    var select = document.getElementById('form-campaign-whatsapp-sender-id');
    if (!select) return;
    apiFetch('/whatsapp-settings').then(function (body) {
      var wa = body.data || {};
      var label = wa.display_phone_number || wa.phone_number_id || 'WhatsApp number';
      var pending = wa.integration_status && wa.integration_status !== 'integrated' && wa.integration_status !== 'connected';
      var disabled = pending || !wa.phone_number_id;
      select.innerHTML = '<option value="' + (wa.id || '') + '"' + (disabled ? ' disabled' : ' selected') + '>' +
        escapeHtml(label + (pending ? ' (pending approval)' : '')) + '</option>';
      select.disabled = disabled;
    }).catch(function () {
      select.innerHTML = '<option value="">WhatsApp sender unavailable</option>';
    });
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
    var emailSenderField = document.getElementById('form-campaign-email-sender-field');
    var smsSenderField = document.getElementById('form-campaign-sms-sender-field');
    var whatsappSenderField = document.getElementById('form-campaign-whatsapp-sender-field');
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
    if (emailSenderField) emailSenderField.classList.toggle('hidden', channel !== 'email');
    if (smsSenderField) smsSenderField.classList.toggle('hidden', channel !== 'sms');
    if (whatsappSenderField) whatsappSenderField.classList.toggle('hidden', channel !== 'whatsapp');
    if (messageTemplate) messageTemplate.required = channel === 'whatsapp';
    var smsTemplateSelect = document.getElementById('form-campaign-sms-template-id');
    if (smsTemplateSelect) smsTemplateSelect.required = channel === 'sms';
    if (smsMessageTemplate) smsMessageTemplate.required = false;
    if (bodyTemplate) bodyTemplate.required = channel === 'email';
    if (emailSubject) emailSubject.required = channel === 'email';
    initCampaignScheduledDateField();
    var createBtn = document.getElementById('btn-create-campaign');
    var smsPreviewMsg = document.getElementById('btn-sms-preview-message');
    var smsSaveDraft = document.getElementById('btn-sms-save-draft');
    var smsPreviewPayload = document.getElementById('btn-sms-preview-payload');
    var isSms = channel === 'sms';
    if (createBtn) createBtn.classList.toggle('hidden', isSms);
    if (smsPreviewMsg) smsPreviewMsg.classList.toggle('hidden', !isSms);
    if (smsSaveDraft) smsSaveDraft.classList.toggle('hidden', !isSms);
    if (smsPreviewPayload) {
      smsPreviewPayload.classList.toggle('hidden', !isSms);
      smsPreviewPayload.disabled = false;
    }
    var smsSendBtn = document.getElementById('btn-sms-send');
    if (smsSendBtn) smsSendBtn.classList.toggle('hidden', !isSms);
    if (createBtn && !isSms) {
      if (channel === 'whatsapp') {
        createBtn.innerHTML = '<i data-lucide="send" class="h-4 w-4"></i> Send Campaign';
      } else {
        createBtn.innerHTML = '<i data-lucide="save" class="h-4 w-4"></i> Create Campaign';
      }
      icons();
    }
    if (channel === 'whatsapp' || channel === 'email' || channel === 'sms') {
      applyCampaignAudienceEmployeeScope();
      toggleCampaignAudienceFields();
      populateCampaignAudienceSelects(channel);
      if (channel === 'email') populateCampaignEmailSenders();
      if (channel === 'sms') populateCampaignSmsSender();
      if (channel === 'whatsapp') populateCampaignWhatsAppSender();
      if (channel === 'sms') {
        loadSmsTemplatesFromDatabase(function () {
          populateSmsTemplateSelect();
          syncSmsCampaignTemplateBody();
        }, true);
        apiFetch('/sms-settings').then(function (body) {
          window.smsSettingsState = body.data || {};
          updateSmsSendButtonState();
        }).catch(function () {});
        requestAnimationFrame(function () {
          var footer = document.getElementById('add-campaign-footer');
          if (footer) footer.scrollLeft = 0;
        });
      }
      if (channel === 'whatsapp') {
        loadWhatsAppTemplatesFromDatabase(function () {
          populateWhatsAppTemplateSelect();
          syncWhatsAppCampaignTemplateBody();
          scheduleWhatsAppCampaignPreview(true);
        }, true, true);
        apiFetch('/whatsapp-settings').then(function (body) {
          window.whatsappSettingsState = body.data || {};
        }).catch(function () {});
      }
      if (channel === 'email') {
        loadEmailTemplatesFromDatabase(function () {
          populateEmailTemplateSelect();
          syncEmailCampaignTemplateFields();
          scheduleEmailCampaignPreview(true);
        }, true);
      }
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

  function initLeadPickerDeps() {
    if (!window.CrmLeadPicker) return;
    window.CrmLeadPicker.setDeps({
      apiFetch: apiFetch,
      mapLeadRecord: mapLeadRecord,
      unwrapList: unwrapList,
      escapeHtml: escapeHtml,
      toast: toast,
      icons: icons,
      getLeadRecord: getLeadRecord,
    });
  }

  var CAMPAIGN_LEAD_PICKER_PAGE_SIZE = 50;

  function getCampaignLeadPickerState() {
    return window.CrmLeadPicker ? window.CrmLeadPicker.state('campaign') : { selected: {}, items: [] };
  }

  function resetCampaignLeadPicker() {
    if (window.CrmLeadPicker) window.CrmLeadPicker.reset('campaign');
  }

  function getCampaignSelectedLeadIds() {
    return window.CrmLeadPicker ? window.CrmLeadPicker.selectedIds('campaign') : [];
  }

  function getFirstCampaignSelectedLeadId() {
    return window.CrmLeadPicker ? window.CrmLeadPicker.firstSelectedId('campaign') : null;
  }

  function getCampaignSelectedLeads() {
    return window.CrmLeadPicker ? window.CrmLeadPicker.selectedLeads('campaign') : [];
  }

  function campaignLeadAssignmentMeta(lead) {
    var executive = lead.executive || lead.employee_name || '';
    if (executive && executive !== 'Unassigned' && executive !== '—') {
      return { label: 'Assigned · ' + executive, assigned: true };
    }
    return { label: 'Unassigned', assigned: false };
  }

  function renderCampaignLeadPicker() {
    if (window.CrmLeadPicker) window.CrmLeadPicker.render('campaign');
  }

  function initCampaignLeadPicker(options) {
    options = options || {};
    if (!window.CrmLeadPicker) return Promise.resolve();
    initLeadPickerDeps();
    bindCampaignLeadPickerEvents();
    if (options.preserveSelection) {
      return window.CrmLeadPicker.refresh('campaign');
    }
    return window.CrmLeadPicker.init('campaign');
  }

  function bindCampaignLeadPickerEvents() {
    if (!window.CrmLeadPicker) return;
    window.CrmLeadPicker.bind('campaign', {
      onSelectionChange: function () {
        updateSmsCampaignEstimates();
        scheduleWhatsAppCampaignPreview(true);
        scheduleEmailCampaignPreview(true);
      },
    });
  }

  function normalizeFollowupLeadId(leadId) {
    var id = parseInt(leadId, 10);
    return id > 0 ? id : null;
  }

  function resolveFollowupContextLeadId(presetIds) {
    if (presetIds && presetIds.length) {
      return normalizeFollowupLeadId(presetIds[0]);
    }
    var selected = normalizeFollowupLeadId(CAData.getSelectedLeadId());
    if (selected) return selected;
    var bulk = (window._inboxBulkLeadIds || []).map(function (id) { return parseInt(id, 10); }).filter(Boolean);
    if (bulk.length === 1) return bulk[0];
    return null;
  }

  function setFollowupModalTitle(editing, opts) {
    opts = opts || {};
    var titleEl = document.querySelector('#followup-title [data-followup-title-text]')
      || document.getElementById('followup-title');
    if (!titleEl) return;
    var text = editing
      ? 'Edit Follow-up'
      : (opts.rowMode ? 'Add Follow-up' : 'Schedule Follow-up');
    if (titleEl.id === 'followup-title') {
      var icon = titleEl.querySelector('.ca-modal-icon');
      titleEl.textContent = '';
      if (icon) titleEl.appendChild(icon);
      var span = document.createElement('span');
      span.setAttribute('data-followup-title-text', '1');
      span.textContent = text;
      titleEl.appendChild(span);
    } else {
      titleEl.textContent = text;
    }
  }

  function clearFollowupLeadContext() {
    window._followupContextLead = null;
    var hidden = document.getElementById('form-followup-ca-id');
    var wrap = document.getElementById('followup-lead-context');
    if (hidden) hidden.value = '';
    if (wrap) wrap.classList.add('hidden');
  }

  function clearFollowupLeadError() {
    var errorEl = document.getElementById('followup-lead-error');
    var picker = document.getElementById('followup-lead-picker');
    if (errorEl) {
      errorEl.textContent = '';
      errorEl.classList.add('hidden');
    }
    if (picker) picker.classList.remove('is-invalid');
    var search = document.getElementById('followup-lead-picker-search');
    if (search) search.classList.remove('is-invalid');
  }

  function setFollowupLeadError(message) {
    var errorEl = document.getElementById('followup-lead-error');
    var picker = document.getElementById('followup-lead-picker');
    if (errorEl) {
      errorEl.textContent = message || '';
      errorEl.classList.toggle('hidden', !message);
    }
    if (picker) picker.classList.toggle('is-invalid', !!message);
    var search = document.getElementById('followup-lead-picker-search');
    if (search) {
      search.classList.toggle('is-invalid', !!message);
      if (message && typeof search.focus === 'function') search.focus();
    }
  }

  function setFollowupLeadPickerVisible(visible) {
    var wrap = document.getElementById('followup-lead-picker-wrap');
    if (wrap) wrap.classList.toggle('hidden', !visible);
  }

  function renderFollowupLeadContext(lead) {
    window._followupContextLead = lead || null;
    var wrap = document.getElementById('followup-lead-context');
    var hidden = document.getElementById('form-followup-ca-id');
    if (!wrap || !hidden) return;
    if (!lead) {
      clearFollowupLeadContext();
      return;
    }
    hidden.value = String(lead.ca_id);
    var firmEl = document.getElementById('followup-ctx-firm');
    var caEl = document.getElementById('followup-ctx-ca');
    var mobileEl = document.getElementById('followup-ctx-mobile');
    var statusEl = document.getElementById('followup-ctx-status');
    var cityEl = document.getElementById('followup-ctx-city');
    var employeeEl = document.getElementById('followup-ctx-employee');
    if (firmEl) firmEl.textContent = lead.firm_name || '—';
    if (caEl) caEl.textContent = lead.ca_name || '—';
    if (mobileEl) mobileEl.textContent = lead.mobile_no || '—';
    if (statusEl) statusEl.textContent = lead.status || '—';
    if (cityEl) cityEl.textContent = lead.city || '—';
    if (employeeEl) employeeEl.textContent = lead.executive || 'Unassigned';
    var titleEl = wrap.querySelector('.followup-lead-context__title');
    if (titleEl) {
      titleEl.textContent = window._followupModalMode === 'row' ? 'Selected Lead' : 'Lead';
    }
    wrap.classList.remove('hidden');
    clearFollowupLeadError();
  }

  function fetchLeadForFollowup(leadId) {
    var cached = getLeadRecord(leadId);
    if (cached) return Promise.resolve(cached);
    if (window._listingLeadsPage && window._listingLeadsPage.length) {
      var listed = window._listingLeadsPage.find(function (l) { return String(l.ca_id) === String(leadId); });
      if (listed) return Promise.resolve(listed);
    }
    return apiFetch('/ca-masters/' + encodeURIComponent(leadId))
      .then(function (body) {
        return mapLeadRecord(body.data || {});
      });
  }

  function initFollowupLeadContext(leadId) {
    if (!leadId) {
      clearFollowupLeadContext();
      return Promise.resolve(null);
    }
    return fetchLeadForFollowup(leadId)
      .then(function (lead) {
        renderFollowupLeadContext(lead);
        return lead;
      })
      .catch(function (err) {
        clearFollowupLeadContext();
        return Promise.reject(err);
      });
  }

  function getFollowupContextLead() {
    if (window._followupContextLead) return window._followupContextLead;
    var leadId = normalizeFollowupLeadId(document.getElementById('form-followup-ca-id')?.value);
    return leadId ? getLeadRecord(leadId) : null;
  }

  function getFollowupSelectedLeadIds() {
    var leadId = normalizeFollowupLeadId(document.getElementById('form-followup-ca-id')?.value);
    return leadId ? [leadId] : [];
  }

  function getFirstFollowupSelectedLeadId() {
    return normalizeFollowupLeadId(document.getElementById('form-followup-ca-id')?.value);
  }

  function getFollowupSelectedLeads() {
    var lead = getFollowupContextLead();
    return lead ? [lead] : [];
  }

  function resetFollowupLeadPicker() {
    if (window.CrmLeadPicker) {
      window.CrmLeadPicker.reset('followup');
      window.CrmLeadPicker.syncBulkBarVisibility('followup');
    }
    clearFollowupLeadContext();
    clearFollowupLeadError();
  }

  function bindFollowupLeadPickerEvents() {
    if (!window.CrmLeadPicker) return;
    window.CrmLeadPicker.bind('followup', {
      singleSelect: true,
      onSelectionChange: function () {
        var leads = window.CrmLeadPicker.selectedLeads('followup');
        if (leads.length) {
          renderFollowupLeadContext(leads[0]);
        } else {
          clearFollowupLeadContext();
        }
      },
    });
  }

  function initFollowupLeadPicker(options) {
    options = options || {};
    if (!window.CrmLeadPicker) return Promise.resolve();
    initLeadPickerDeps();
    bindFollowupLeadPickerEvents();
    var presetIds = (options.presetIds || []).map(function (id) { return parseInt(id, 10); }).filter(Boolean);
    return window.CrmLeadPicker.init('followup', presetIds);
  }

  var _followupModalOpening = false;

  function openFollowupModalWithLeads(presetIds, options) {
    if (_followupModalOpening) return;
    options = options || {};
    presetIds = (presetIds || []).map(function (id) { return parseInt(id, 10); }).filter(Boolean);
    var isRowMode = options.mode === 'row';
    var leadId = isRowMode && presetIds.length
      ? normalizeFollowupLeadId(presetIds[0])
      : resolveFollowupContextLeadId(presetIds);

    if (isRowMode && !leadId) {
      toast('Lead not found', 'warning');
      return;
    }

    var modal = document.getElementById('modal-followup');
    var form = document.getElementById('form-followup');
    if (!modal || !form) return;
    _followupModalOpening = true;
    window._followupModalMode = isRowMode ? 'row' : 'global';
    window._editingFollowUpId = null;
    window._followupOriginalScheduled = '';
    window._inboxBulkLeadIds = null;
    form.reset();
    clearFollowupLeadContext();
    clearFollowupLeadError();
    resetFormDateTimePickers(form);
    resetFollowupLeadPicker();
    resetFollowupDemoFieldState();
    var demoWrap = document.getElementById('followup-demo-fields-wrap');
    if (demoWrap) demoWrap.classList.add('hidden');
    var rescheduleWrap = document.getElementById('followup-reschedule-reason-wrap');
    if (rescheduleWrap) rescheduleWrap.classList.add('hidden');
    setFollowupModalTitle(false, { rowMode: isRowMode });
    setFollowupLeadPickerVisible(!isRowMode);

    openExclusiveCrmModal(modal);
    setFollowupFormBusy(true);
    iconsIn(modal);

    var initTasks = [
      new Promise(function (resolve) { ensureFormSelectData(resolve); }),
    ];

    if (isRowMode && leadId) {
      initTasks.push(initFollowupLeadContext(leadId));
    } else {
      initTasks.push(initFollowupLeadPicker({ presetIds: presetIds }));
    }

    Promise.all(initTasks)
      .then(function () {
        if (!isRowMode && leadId && window.CrmLeadPicker) {
          var selected = window.CrmLeadPicker.selectedLeads('followup');
          if (!selected.length) {
            return window.CrmLeadPicker.applyPresetIds('followup', [leadId]).then(function () {
              var leads = window.CrmLeadPicker.selectedLeads('followup');
              if (leads[0]) renderFollowupLeadContext(leads[0]);
            });
          }
          if (selected[0]) renderFollowupLeadContext(selected[0]);
        }
        initFollowUpDateTimeField();
        initFollowUpDemoFields();
        setFollowupFormBusy(false);
        iconsIn(modal);
        if (!isRowMode && !leadId) {
          var search = document.getElementById('followup-lead-picker-search');
          if (search && typeof search.focus === 'function') search.focus();
        }
      })
      .catch(function (err) {
        setFollowupFormBusy(false);
        closeModal(modal);
        toast(err.message || 'Unable to load follow-up form', 'error');
      })
      .finally(function () {
        _followupModalOpening = false;
      });
  }

  function populateCampaignAudienceSelects(channel, options) {
    options = options || {};
    channel = channel || document.getElementById('form-campaign-channel')?.value || 'whatsapp';
    initLeadPickerDeps();
    bindCampaignLeadPickerEvents();
    return new Promise(function (resolve) {
      ensureFormSelectData(function () {
        var sources = window.realSourceLeads || [];
        var pickerPromise = initCampaignLeadPicker(options);
        var sourceSel = document.getElementById('form-campaign-source-id');
        if (sourceSel) sourceSel.innerHTML = buildMasterSelectOptions(sources, 'source_id', 'source_name');
        if (window.CA_STATE_CITY) {
          window.CA_STATE_CITY.prepareForm('form-add-campaign');
        }
        if (pickerPromise && typeof pickerPromise.then === 'function') {
          pickerPromise.then(resolve).catch(resolve);
        } else {
          resolve();
        }
      });
    });
  }

  function localDatetimeForServer(value) {
    if (!value || !String(value).trim()) return null;
    var raw = String(value).trim();
    if (/[zZ]$/.test(raw) || /[+-]\d{2}:\d{2}$/.test(raw)) return raw;
    var d = new Date(raw);
    if (Number.isNaN(d.getTime())) return raw;
    var pad = function (n) { return String(n).padStart(2, '0'); };
    var offsetMin = -d.getTimezoneOffset();
    var sign = offsetMin >= 0 ? '+' : '-';
    var abs = Math.abs(offsetMin);
    var base = raw.length === 16 ? raw + ':00' : raw;
    return base + sign + pad(Math.floor(abs / 60)) + ':' + pad(abs % 60);
  }

  function initCampaignScheduledDateField() {
    if (!window.CrmDateTimePicker) return;
    var form = document.getElementById('form-add-campaign');
    if (!form) return;
    window.CrmDateTimePicker.initAll(form, { force: true });
    window.CrmDateTimePicker.syncAll(form);
  }

  function canEditFollowupTeamSizeField() {
    var role = (window.__CRM_USER__ || {}).role;
    return role === 'super_admin' || role === 'manager';
  }

  function canEditFollowupDemoProviderField() {
    return canEditFollowupTeamSizeField();
  }

  function isFollowupDemoScheduledType(type) {
    return String(type || '').trim() === 'Demo Scheduled';
  }

  function resolveDemoProviderFromTeamSize(teamSize) {
    var n = parseInt(teamSize, 10);
    if (!n || n < 1) return null;
    if (n === 1) {
      return { provider: 'Ankit Bhardwaj', link: 'https://meet.google.com/mcq-jrnh-uea' };
    }
    if (n <= 10) {
      return { provider: 'Dev Aggarwal', link: 'https://meet.google.com/awm-gsft-xov' };
    }
    return { provider: 'Kamal Sharma', link: 'https://meet.google.com/ouq-sxne-jwn' };
  }

  function getFollowupSelectedLeadTeamSize() {
    var lead = getFollowupContextLead();
    if (!lead || lead.team_size == null || lead.team_size === '') return null;
    var n = parseInt(lead.team_size, 10);
    return n > 0 ? n : null;
  }

  function syncFollowupDemoProviderFromTeamSize(force) {
    var teamInput = document.getElementById('form-followup-team-size');
    var providerInput = document.getElementById('form-followup-demo-provider');
    var linkInput = document.getElementById('form-followup-meeting-link');
    if (!teamInput || !providerInput || !linkInput) return;
    var n = parseInt(teamInput.value, 10);
    if (!n || n < 1) return;
    var resolved = resolveDemoProviderFromTeamSize(n);
    if (!resolved) return;
    if (force || providerInput.dataset.autoFilled === '1') {
      providerInput.value = resolved.provider;
      providerInput.dataset.autoFilled = '1';
    }
    if (force || linkInput.dataset.autoFilled === '1') {
      linkInput.value = resolved.link;
      linkInput.dataset.autoFilled = '1';
    }
  }

  function applyFollowupDemoFieldsAccess(teamSizeUnavailable) {
    var teamInput = document.getElementById('form-followup-team-size');
    var providerInput = document.getElementById('form-followup-demo-provider');
    var linkInput = document.getElementById('form-followup-meeting-link');
    if (!teamInput || !providerInput || !linkInput) return;

    var allowTeam = teamSizeUnavailable || canEditFollowupTeamSizeField();
    var allowProvider = teamSizeUnavailable || canEditFollowupDemoProviderField();

    teamInput.readOnly = !allowTeam;
    teamInput.classList.toggle('bg-slate-50', !allowTeam);
    providerInput.readOnly = !allowProvider;
    providerInput.classList.toggle('bg-slate-50', !allowProvider);
    linkInput.readOnly = false;
    linkInput.classList.remove('bg-slate-50');
  }

  function resetFollowupDemoFieldState() {
    var providerInput = document.getElementById('form-followup-demo-provider');
    var linkInput = document.getElementById('form-followup-meeting-link');
    if (providerInput) {
      providerInput.dataset.autoFilled = '1';
      providerInput.dataset.userEdited = '';
    }
    if (linkInput) {
      linkInput.dataset.autoFilled = '1';
      linkInput.dataset.userEdited = '';
    }
  }

  function populateFollowupDemoFieldsFromLead() {
    var teamInput = document.getElementById('form-followup-team-size');
    var providerInput = document.getElementById('form-followup-demo-provider');
    var linkInput = document.getElementById('form-followup-meeting-link');
    if (!teamInput) return;

    if (window._editingFollowUpId && teamInput.value) {
      applyFollowupDemoFieldsAccess(false);
      return;
    }

    var teamSize = getFollowupSelectedLeadTeamSize();
    if (teamSize) {
      teamInput.value = String(teamSize);
      resetFollowupDemoFieldState();
      syncFollowupDemoProviderFromTeamSize(true);
      applyFollowupDemoFieldsAccess(false);
      return;
    }

    if (!window._editingFollowUpId) {
      teamInput.value = '';
      if (providerInput) providerInput.value = '';
      if (linkInput) linkInput.value = '';
      resetFollowupDemoFieldState();
    }
    applyFollowupDemoFieldsAccess(true);
  }

  function toggleFollowupDemoFields() {
    var form = document.getElementById('form-followup');
    var typeSel = form && form.elements.followup_type;
    var wrap = document.getElementById('followup-demo-fields-wrap');
    if (!typeSel || !wrap) return;
    var isDemo = isFollowupDemoScheduledType(typeSel.value);
    wrap.classList.toggle('hidden', !isDemo);
    if (isDemo) {
      populateFollowupDemoFieldsFromLead();
    }
  }

  function bindFollowupDemoFieldEvents() {
    var form = document.getElementById('form-followup');
    if (!form || form._demoFieldsBound) return;
    form._demoFieldsBound = true;

    var typeSel = form.elements.followup_type;
    var teamInput = document.getElementById('form-followup-team-size');
    var providerInput = document.getElementById('form-followup-demo-provider');
    var linkInput = document.getElementById('form-followup-meeting-link');

    if (typeSel) typeSel.addEventListener('change', toggleFollowupDemoFields);
    if (teamInput) {
      teamInput.addEventListener('input', function () {
        resetFollowupDemoFieldState();
        syncFollowupDemoProviderFromTeamSize(true);
      });
    }
    if (providerInput) {
      providerInput.addEventListener('input', function () {
        providerInput.dataset.userEdited = '1';
        providerInput.dataset.autoFilled = '0';
      });
    }
    if (linkInput) {
      linkInput.addEventListener('input', function () {
        linkInput.dataset.userEdited = '1';
        linkInput.dataset.autoFilled = '0';
      });
    }
  }

  function initFollowUpDemoFields() {
    bindFollowupDemoFieldEvents();
    toggleFollowupDemoFields();
  }

  function initFollowUpDateTimeField() {
    if (!window.CrmDateTimePicker) return;
    var form = document.getElementById('form-followup');
    if (!form) return;
    window.CrmDateTimePicker.initAll(form, { force: true });
    window.CrmDateTimePicker.syncAll(form);
  }

  function resetFormDateTimePickers(form) {
    if (!form || !window.CrmDateTimePicker) return;
    window.CrmDateTimePicker.syncAll(form);
  }

  function formatSchedulePreview(value) {
    if (!value) return '';
    if (window.CrmDateTimePicker && window.CrmDateTimePicker.formatPreview) {
      var parsed = window.CrmDateTimePicker.parseValue(value);
      if (parsed) return window.CrmDateTimePicker.formatPreview(parsed);
    }
    return formatDateTime(value);
  }

  function validateCampaignScheduledAt(value) {
    if (!value || !String(value).trim()) {
      return { valid: true, value: null, isFuture: false };
    }
    var parsed = window.CrmDateTimePicker
      ? window.CrmDateTimePicker.parseValue(value)
      : new Date(value);
    if (!parsed || Number.isNaN(parsed.getTime())) {
      return { valid: false, message: 'Please select a future date and time.' };
    }
    if (parsed.getTime() <= Date.now()) {
      return { valid: false, message: 'Please select a future date and time.' };
    }
    return {
      valid: true,
      value: localDatetimeForServer(value),
      isFuture: true,
    };
  }

  function validateFollowUpScheduledAt(value) {
    if (!value || !String(value).trim()) {
      return { valid: false, message: 'Please select a future date and time.' };
    }
    return validateCampaignScheduledAt(value);
  }

  function buildCampaignAudiencePayload(data) {
    var selectedIds = getCampaignSelectedLeadIds();
    var schedule = validateCampaignScheduledAt(data.scheduled_at);
    var payload = {
      campaign_name: campaignNameFromForm(data),
      campaign_type: data.campaign_type,
      audience_mode: data.audience_mode || 'all_leads',
      scheduled_at: schedule.valid ? schedule.value : null,
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
      Sent: 'badge-success',
      'Reply Received': 'badge-info',
      Failed: 'badge-danger',
      'API Error': 'badge-danger',
      Queued: 'badge-warning',
      Pending: 'badge-warning',
      Mapped: 'badge-brand',
      Processing: 'badge-brand',
      Scheduled: 'bg-blue-50 text-blue-700',
      Completed: 'badge-success',
      Partial: 'badge-warning',
      Draft: 'bg-slate-100 text-slate-600',
    };
    return '<span class="badge ' + (map[status] || 'badge-brand') + '">' + escapeHtml(status || '—') + '</span>';
  }

  function campaignDeleteButton(channel, id, status) {
    if (status === 'Processing') return '';
    return iconBtn('trash-2', 'Delete', 'data-delete-' + channel + '-campaign="' + id + '"', 'danger');
  }

  function whatsappCampaignCardHtml(c) {
    var sent = c.total_messages || 0;
    var sentCount = c.sent_count || 0;
    var delivered = c.delivered_count || 0;
    var failed = c.failed_count || 0;
    var skipped = c.skipped_count || 0;
    var pct = sentCount ? Math.round((delivered / sentCount) * 100) : (sentCount > 0 ? 100 : 0);
    var launchBtn = c.status === 'Scheduled'
      ? iconBtn('rocket', 'Process', 'data-launch-whatsapp-campaign="' + c.id + '"', 'primary')
      : '';
    var actions = '<div class="flex flex-wrap gap-2 mt-3">' + launchBtn + campaignDeleteButton('whatsapp', c.id, c.status) + '</div>';
    return '<div class="card-interactive p-4 campaign-card" data-whatsapp-campaign-id="' + c.id + '">' +
      '<div class="flex justify-between mb-2"><p class="text-card-heading">' + escapeHtml(c.campaign_name) + '</p>' + waStatusBadge(c.status) + '</div>' +
      '<p class="text-caption text-slate-500">Type: ' + escapeHtml(c.campaign_type) + '</p>' +
      '<p class="text-caption text-slate-500 mt-1">Audience: ' + escapeHtml(c.audience_label || c.audience_mode) + ' · By: ' + escapeHtml(c.performed_by || 'System') + '</p>' +
      '<p class="text-caption text-slate-500 mt-1">Messages: ' + sent + ' · Sent ' + sentCount + ' · Delivered ' + delivered + ' · Failed ' + failed + (skipped ? ' · Skipped ' + skipped : '') + '</p>' +
      (sentCount ? '<div class="mt-3 h-2 rounded-full bg-slate-100"><div class="h-full rounded-full bg-green-500" style="width:' + pct + '%"></div></div>' +
        '<p class="text-caption text-slate-400 mt-1">' + (delivered > 0 ? pct + '% delivered to phone' : 'Accepted by Meta — phone delivery pending') + '</p>' + actions : actions) +
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
    setText('wa-kpi-delivered', formatCampaignNumber(metrics.whatsapp_sent || metrics.whatsapp_delivered || 0));
    setText('wa-kpi-failed', formatCampaignNumber(metrics.whatsapp_failed || 0));
    setText('wa-kpi-queued', formatCampaignNumber(metrics.whatsapp_queued || 0));
  }

  function renderWaMessageLogsTable(logs) {
    var tbody = document.getElementById('wa-message-logs-table');
    if (!tbody) return;
    logs = logs || window.realWaMessageLogs || [];
    if (!logs.length) {
      tbody.innerHTML = '<tr><td colspan="10" class="text-center text-slate-400 py-8">No message logs yet.</td></tr>';
      return;
    }
    tbody.innerHTML = logs.map(function (log) {
      var errorText = log.error_message || log.failed_reason || '';
      var ts = log.sent_at || log.queued_at || log.created_at;
      var retryBtn = log.can_retry
        ? '<button type="button" class="btn-secondary btn-xs" data-retry-wa-log="' + log.id + '" title="Retry">Retry</button>'
        : '';
      var payloadBtn = log.api_payload
        ? ' <button type="button" class="btn-secondary btn-xs" data-view-wa-payload="' + log.id + '" title="View Payload">Payload</button>'
        : '';
      var responseBtn = log.provider_response
        ? ' <button type="button" class="btn-secondary btn-xs" data-view-wa-response="' + log.id + '" title="View Response">Response</button>'
        : '';
      return '<tr>' +
        '<td class="whitespace-nowrap">' + escapeHtml(formatActivityTimestamp(ts)) + '</td>' +
        '<td>' + escapeHtml(log.recipient || log.mobile_no || '—') + '</td>' +
        '<td>' + escapeHtml(log.lead_name || log.firm_name || '—') + '</td>' +
        '<td class="max-w-[8rem] truncate" title="' + escapeHtml(log.template_name || '') + '">' + escapeHtml(log.template_name || '—') + '</td>' +
        '<td>' + waStatusBadge(log.message_status || log.status) + '</td>' +
        '<td class="max-w-[10rem] truncate font-mono text-xs" title="' + escapeHtml(log.meta_message_id || '') + '">' + escapeHtml(log.meta_message_id || '—') + '</td>' +
        '<td>' + (log.is_delivered ? '<span class="badge-success">Yes</span>' : '<span class="text-slate-400">—</span>') + '</td>' +
        '<td>' + (log.is_read ? '<span class="badge-success">Yes</span>' : '<span class="text-slate-400">—</span>') + '</td>' +
        '<td class="max-w-xs truncate text-red-700" title="' + escapeHtml(errorText) + '">' + (log.is_failed ? escapeHtml(errorText || 'Failed') : '<span class="text-slate-400">—</span>') + '</td>' +
        '<td class="whitespace-nowrap">' + retryBtn + payloadBtn + responseBtn + '</td>' +
      '</tr>';
    }).join('');
  }

  function showWaLogJsonModal(title, data) {
    var json = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
    var overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 z-[200] flex items-center justify-center bg-black/40 p-4';
    overlay.innerHTML = '<div class="card max-w-3xl w-full max-h-[80vh] overflow-hidden flex flex-col">' +
      '<div class="flex items-center justify-between p-4 border-b"><h3 class="text-card-heading">' + escapeHtml(title) + '</h3>' +
      '<button type="button" class="btn-secondary btn-sm" data-close-wa-json>Close</button></div>' +
      '<pre class="p-4 overflow-auto text-xs font-mono bg-slate-50 flex-1">' + escapeHtml(json) + '</pre></div>';
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay || e.target.closest('[data-close-wa-json]')) overlay.remove();
    });
    document.body.appendChild(overlay);
  }

  function retryWaMessageLog(logId) {
    apiFetch('/wa-message-logs/' + encodeURIComponent(logId) + '/retry', { method: 'POST' })
      .then(function () {
        toast('WhatsApp message retried', 'success');
        loadWaMessageLogsFromDatabase(function (logs) { renderWaMessageLogsTable(logs); });
      })
      .catch(function (err) {
        toast(err.message || 'Retry failed', 'error');
        loadWaMessageLogsFromDatabase(function (logs) { renderWaMessageLogsTable(logs); });
      });
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
    var stats = c.statistics || {};
    var total = stats.total_leads || c.total_emails || 0;
    var sent = stats.emails_sent || c.sent_count || c.delivered_count || 0;
    var valid = stats.valid_emails || c.valid_emails_count || 0;
    var invalid = stats.invalid_emails || c.invalid_emails_count || 0;
    var duplicate = stats.duplicate_emails || c.duplicate_emails_count || 0;
    var skipped = stats.skipped || c.skipped_count || 0;
    var failed = stats.failed || c.failed_count || 0;
    var pct = total ? Math.round((sent / total) * 100) : 0;
    var launchBtn = c.status === 'Scheduled'
      ? iconBtn('rocket', 'Process', 'data-launch-email-campaign="' + c.id + '"', 'primary')
      : '';
    var retryBtn = failed > 0 && c.status !== 'Scheduled' && c.status !== 'Draft'
      ? iconBtn('rotate-ccw', 'Retry Failed', 'data-retry-email-campaign="' + c.id + '"', 'secondary')
      : '';
    var actions = '<div class="flex flex-wrap gap-2 mt-3">' + launchBtn + retryBtn + campaignDeleteButton('email', c.id, c.status) + '</div>';
    return '<div class="card-interactive p-5 campaign-card" data-email-campaign-id="' + c.id + '">' +
      '<i data-lucide="send" class="h-8 w-8 text-brand mb-3"></i>' +
      '<div class="flex justify-between gap-2 mb-1"><p class="text-card-heading">' + escapeHtml(c.campaign_name) + '</p>' + waStatusBadge(c.status) + '</div>' +
      '<p class="text-caption text-slate-500 mt-1">Subject: ' + escapeHtml(c.subject || '—') + '</p>' +
      '<p class="text-caption text-slate-500 mt-1">Type: ' + escapeHtml(c.campaign_type) + ' · Audience: ' + escapeHtml(c.audience_label || c.audience_mode) + '</p>' +
      '<p class="text-caption text-slate-500 mt-1">Leads: ' + total + ' · Valid ' + valid + ' · Sent ' + sent + ' · Failed ' + failed + '</p>' +
      '<p class="text-caption text-slate-500 mt-1">Invalid ' + invalid + ' · Duplicate ' + duplicate + ' · Skipped ' + skipped + '</p>' +
      (total ? '<div class="mt-3 h-2 rounded-full bg-slate-100"><div class="h-full rounded-full bg-green-500" style="width:' + pct + '%"></div></div>' +
        '<p class="text-caption text-slate-400 mt-1">' + pct + '% sent rate</p>' + actions : actions) +
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
    setText('email-kpi-replies', formatCampaignNumber(metrics.email_replies_received || metrics.reply_received_logs || metrics.email_today_replies || 0));
    setText('email-kpi-unread-replies', formatCampaignNumber(metrics.email_unread_replies || metrics.unread_replies || 0));
    var unreadBadge = document.getElementById('email-inbox-unread-badge');
    if (unreadBadge) {
      var unread = metrics.email_unread_replies || metrics.unread_replies || 0;
      unreadBadge.textContent = unread + ' unread';
      unreadBadge.classList.toggle('hidden', unread <= 0);
    }
    var lastSyncEl = document.getElementById('email-inbox-last-sync');
    if (lastSyncEl) {
      if (metrics.sync_in_progress) {
        lastSyncEl.textContent = 'Updating…';
      } else {
        var lastSync = metrics.last_sync_at || (metrics.last_sync_log && metrics.last_sync_log.finished_at) || null;
        var syncLabel = metrics.sync_status === 'Failed' ? 'Update failed' : 'Last updated';
        lastSyncEl.textContent = syncLabel + ': ' + (lastSync ? formatActivityTimestamp(lastSync) : '—');
      }
    }
  }

  function loadEmailInboxMetrics(callback) {
    return apiFetch('/email-inbox/metrics')
      .then(function (body) {
        var metrics = body.data || {};
        applyEmailInboxMetrics(metrics);
        if (callback) callback(metrics);
        return metrics;
      })
      .catch(function () {
        if (callback) callback({});
        return {};
      });
  }

  var EMAIL_INBOX_SYNC_TIMEOUT_MS = 10000;
  var EMAIL_INBOX_AUTO_SYNC_MS = 5 * 60 * 1000;
  var EMAIL_INBOX_SYNC_POLL_MS = 2000;
  var EMAIL_INBOX_SYNC_POLL_MAX = 90;
  var CAMPAIGN_POLL_MS = 3000;
  var CAMPAIGN_POLL_MAX = 120;
  var CAMPAIGN_TERMINAL_STATUSES = {
    Completed: true,
    Partial: true,
    Failed: true,
    Cancelled: true,
    Paused: true,
  };

  function isCampaignTerminal(status) {
    return !!CAMPAIGN_TERMINAL_STATUSES[status];
  }

  function pollCampaignUntilDone(channel, campaignId, onTick) {
    var attempts = 0;
    function tick() {
      attempts += 1;
      return apiFetch('/' + channel + '-campaigns/' + encodeURIComponent(campaignId))
        .then(function (body) {
          var campaign = body.data || {};
          if (onTick) onTick(campaign);
          if (isCampaignTerminal(campaign.status) || attempts >= CAMPAIGN_POLL_MAX) {
            return campaign;
          }
          return new Promise(function (resolve) {
            setTimeout(function () { resolve(tick()); }, CAMPAIGN_POLL_MS);
          });
        });
    }
    return tick();
  }

  function afterCampaignQueued(channel, campaignId, refreshFn, initialMessage) {
    toast(initialMessage || 'Campaign queued successfully.', 'success');
    if (refreshFn) refreshFn();
    return pollCampaignUntilDone(channel, campaignId, function () {
      if (refreshFn) refreshFn();
    }).then(function (campaign) {
      if (refreshFn) refreshFn();
      if (!isCampaignTerminal(campaign.status)) {
        return campaign;
      }
      var delivered = campaign.delivered_count || 0;
      var failed = campaign.failed_count || 0;
      toast(
        'Campaign ' + String(campaign.status || 'done').toLowerCase() + ' · ' +
        delivered + ' delivered · ' + failed + ' failed',
        failed > 0 ? 'warning' : 'success'
      );
      return campaign;
    }).catch(function () {});
  }

  function applyEmailInboxMetrics(metrics) {
    metrics = metrics || {};
    window.emailInboxMetrics = metrics;
    renderEmailKpis(Object.assign({}, window.dashboardMetrics || {}, {
      email_replies_received: metrics.reply_received_logs,
      email_unread_replies: metrics.unread_replies,
      email_today_replies: metrics.today_replies,
      last_sync_at: metrics.last_sync_at,
      last_sync_log: metrics.last_sync_log,
    }));
  }

  function refreshEmailInboxAfterSync(data) {
    if (data && data.metrics) {
      applyEmailInboxMetrics(data.metrics);
    } else {
      loadEmailInboxMetrics();
    }
    return loadEmailInboxFromDatabase(function () {
      renderEmailInboxTable();
    });
  }

  function pollInboxSyncUntilDone() {
    var attempts = 0;
    function tick() {
      attempts += 1;
      return loadEmailInboxMetrics().then(function (metrics) {
        if (!metrics.sync_in_progress) {
          var log = metrics.last_sync_log || {};
          if (metrics.sync_status === 'Failed' && log.error_message) {
            toast(log.error_message, 'error');
          }
          return refreshEmailInboxAfterSync({ metrics: metrics });
        }
        if (attempts >= EMAIL_INBOX_SYNC_POLL_MAX) {
          toast('Sync is taking longer than expected. Results will appear when complete.', 'info');
          return metrics;
        }
        return new Promise(function (resolve) {
          setTimeout(function () { resolve(tick()); }, EMAIL_INBOX_SYNC_POLL_MS);
        });
      });
    }
    return tick();
  }

  function syncLatestEmailsFromInbox(options) {
    options = options || {};
    var silent = !!options.silent;
    var btn = document.getElementById('email-inbox-sync-btn');
    if (btn && btn.disabled) {
      return Promise.resolve();
    }
    var originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i data-lucide="loader-2" class="h-4 w-4 animate-spin"></i> Updating…';
      icons();
    }
    return apiFetch('/email-inbox/sync', { method: 'POST', timeoutMs: EMAIL_INBOX_SYNC_TIMEOUT_MS })
      .then(function (body) {
        if (!silent) {
          var queuedMsg = body.message || 'Inbox update started.';
          if (/sync/i.test(queuedMsg)) queuedMsg = 'Inbox update started.';
          toast(queuedMsg, body.data && body.data.already_running ? 'info' : 'success');
        }
        return pollInboxSyncUntilDone();
      })
      .catch(function (err) {
        var msg = err.message || 'Unable to refresh inbox';
        if (err.name === 'AbortError' || /timed out/i.test(msg)) {
          msg = 'Could not reach the server. Please try again.';
        }
        if (!silent) {
          toast(msg, 'error');
        }
        throw err;
      })
      .finally(function () {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = originalHtml;
          icons();
        }
      });
  }

  function maybeTriggerInboxAutoSync() {
    if (!document.getElementById('email-inbox-table')) return;
    var metrics = window.emailInboxMetrics || {};
    var last = metrics.last_sync_at;
    var stale = !last || (Date.now() - new Date(last).getTime() > 2 * 60 * 1000);
    if (stale && !window._emailInboxAutoSyncRunning) {
      window._emailInboxAutoSyncRunning = true;
      syncLatestEmailsFromInbox({ silent: true })
        .catch(function () {})
        .finally(function () {
          window._emailInboxAutoSyncRunning = false;
        });
    }
  }

  function startEmailInboxAutoSync() {
    if (window._emailInboxAutoSyncTimer) return;
    window._emailInboxAutoSyncTimer = setInterval(function () {
      if (!document.getElementById('email-inbox-table')) return;
      maybeTriggerInboxAutoSync();
    }, EMAIL_INBOX_AUTO_SYNC_MS);
  }

  function renderEmailInboxTable(items) {
    var tbody = document.getElementById('email-inbox-table');
    if (!tbody) return;
    items = items || window.realEmailInbox || [];
    if (!items.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-slate-400 py-8">No inbox messages yet. Enable IMAP and run sync.</td></tr>';
      return;
    }
    tbody.innerHTML = items.map(function (item) {
      var unreadClass = item.is_read ? '' : ' font-semibold';
      var statusBadge = item.match_status === 'matched'
        ? '<span class="badge-success">Matched</span>'
        : '<span class="badge-warning">Unmatched</span>';
      return '<tr class="cursor-pointer hover:bg-slate-50" data-email-inbox-id="' + item.id + '">' +
        '<td class="' + unreadClass + '">' + escapeHtml(item.from_email || '—') + '</td>' +
        '<td>' + escapeHtml(item.lead_name || '—') + '</td>' +
        '<td>' + escapeHtml(item.from_email || '—') + '</td>' +
        '<td class="max-w-xs truncate' + unreadClass + '" title="' + escapeHtml(item.subject) + '">' + escapeHtml(item.subject || '—') + '</td>' +
        '<td class="whitespace-nowrap">' + escapeHtml(formatActivityTimestamp(item.received_at)) + '</td>' +
        '<td>' + statusBadge + (item.attachment_count ? ' <i data-lucide="paperclip" class="inline h-3 w-3"></i>' : '') + '</td>' +
        '<td>' + iconBtn('eye', 'View', 'data-view-inbox-message="' + item.id + '"', 'secondary') + '</td>' +
      '</tr>';
    }).join('');
    icons();
  }

  function loadEmailInboxFromDatabase(callback) {
    apiFetch('/email-inbox?per_page=50&sort_by=received_at&sort_dir=desc')
      .then(function (body) {
        window.realEmailInbox = unwrapList(body);
        if (callback) callback(window.realEmailInbox);
      })
      .catch(function () {
        window.realEmailInbox = [];
        if (callback) callback([]);
      });
  }

  function renderLeadEmailTimelineSection(payload) {
    var items = (payload && payload.items) || [];
    var threads = (payload && payload.threads) || [];
    if (!items.length && !threads.length) return '';
    var html = '<div class="mt-4 border-t border-slate-100 pt-4">' +
      '<h3 class="text-sm font-semibold text-slate-700 mb-3">Communication Timeline</h3>';
    if (threads.length) {
      html += threads.map(function (thread) {
        return '<div class="mb-4 rounded-lg border border-slate-100 p-3">' +
          '<p class="font-medium text-slate-800 mb-2">' + escapeHtml(thread.subject || 'Conversation') + '</p>' +
          '<div class="space-y-3 border-l-2 border-slate-200 pl-3 ml-1">' +
          (thread.timeline || []).map(function (item) {
            var isCustomer = item.actor_role === 'customer' || item.direction === 'inbound';
            return '<div class="text-sm">' +
              '<div class="flex items-center justify-between gap-2">' +
                '<span class="' + (isCustomer ? 'badge-info' : 'badge-neutral') + '">' + escapeHtml(isCustomer ? 'Customer' : 'Employee') + '</span>' +
                '<span class="text-caption text-slate-400">' + escapeHtml(item.occurred_at ? formatDateTime(item.occurred_at) : '') + '</span>' +
              '</div>' +
              '<p class="font-medium mt-1">' + escapeHtml(item.subject || '(No subject)') + '</p>' +
              (item.body_preview ? '<p class="text-caption text-slate-600 mt-1 whitespace-pre-wrap">' + escapeHtml(item.body_preview) + '</p>' : '') +
              (item.reply_preview ? '<p class="text-caption text-emerald-700 mt-1">Reply: ' + escapeHtml(item.reply_preview) + '</p>' : '') +
            '</div>';
          }).join('') +
          '</div></div>';
      }).join('');
    } else {
      html += '<div class="space-y-2 max-h-72 overflow-y-auto">' +
        items.slice().reverse().map(function (item) {
          var isCustomer = item.actor_role === 'customer' || item.direction === 'inbound';
          return '<div class="rounded-lg border border-slate-100 p-3 text-sm">' +
            '<div class="flex items-center justify-between gap-2">' +
              '<span class="' + (isCustomer ? 'badge-info' : 'badge-neutral') + '">' + escapeHtml(item.actor || (isCustomer ? 'Customer' : 'Employee')) + '</span>' +
              '<span class="text-caption text-slate-400">' + escapeHtml(item.occurred_at ? formatDateTime(item.occurred_at) : '') + '</span>' +
            '</div>' +
            '<p class="font-medium mt-1">' + escapeHtml(item.subject || '(No subject)') + '</p>' +
            (item.body_preview ? '<p class="text-caption text-slate-600 mt-1 whitespace-pre-wrap">' + escapeHtml(item.body_preview) + '</p>' : '') +
          '</div>';
        }).join('') + '</div>';
    }
    html += '</div>';
    return html;
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
        '<td class="max-w-xs truncate" title="' + escapeHtml(log.failed_reason || log.reply_preview || '') + '">' +
          escapeHtml(log.failed_reason || log.reply_preview || (log.reply_from ? 'Reply from ' + log.reply_from : '—')) + '</td>' +
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
      loadEmailInboxMetrics(function () {
        maybeTriggerInboxAutoSync();
      });
    });
    loadEmailInboxFromDatabase(function () {
      renderEmailInboxTable();
    });
    startEmailInboxAutoSync();
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

  function loadSmsTemplatesFromDatabase(callback, approvedOnly) {
    var url = '/sms-templates' + (approvedOnly ? '?approved_only=1' : '');
    apiFetch(url)
      .then(function (body) {
        window.realSmsTemplates = Array.isArray(body.data) ? body.data : [];
        if (callback) callback(window.realSmsTemplates);
      })
      .catch(function () {
        window.realSmsTemplates = [];
        if (callback) callback([]);
      });
  }

  function populateSmsTemplateSelect() {
    var select = document.getElementById('form-campaign-sms-template-id');
    if (!select) return;
    var templates = (window.realSmsTemplates || []).filter(function (t) {
      return t.status === 'approved' && t.is_active !== false;
    });
    select.innerHTML = '<option value="">Select approved DLT template</option>' +
      templates.map(function (t) {
        return '<option value="' + t.id + '">' + escapeHtml(t.template_name) + ' · ' + escapeHtml(t.sender_id) + '</option>';
      }).join('');
  }

  function syncSmsCampaignTemplateBody() {
    var select = document.getElementById('form-campaign-sms-template-id');
    var body = document.getElementById('form-campaign-sms-message-template');
    if (!select || !body) return;
    var templateId = parseInt(select.value, 10);
    var template = (window.realSmsTemplates || []).find(function (t) {
      return parseInt(t.id, 10) === templateId;
    });
    body.value = template ? (template.body_template || '') : '';
    updateSmsCampaignEstimates();
  }

  function getSmsSendBlockers(sms) {
    sms = sms || window.smsSettingsState || {};
    if (Array.isArray(sms.send_blockers) && sms.send_blockers.length) {
      return sms.send_blockers;
    }
    var blockers = [];
    if (sms.is_active === false) blockers.push('SMS provider is inactive. Enable it in SMS Settings.');
    if (sms.mode !== 'live') blockers.push('Set SMS mode to Live in SMS Settings to send messages.');
    if (!sms.has_api_key) blockers.push('Add your SMS Alert API Key in Settings.');
    if (!sms.sender_id) blockers.push('Add your Sender ID in Settings.');
    if (!sms.dlt_template_id) blockers.push('Add DLT Template ID in Settings (required for Live mode).');
    return blockers;
  }

  function updateSmsSendButtonState() {
    var sendBtn = document.getElementById('btn-sms-send');
    if (!sendBtn) return;
    var blockers = getSmsSendBlockers();
    var canSend = blockers.length === 0;
    sendBtn.disabled = false;
    sendBtn.removeAttribute('aria-disabled');
    sendBtn.classList.toggle('btn-primary--blocked', !canSend);
    if (!canSend) {
      sendBtn.title = blockers[0];
    } else if (window.smsSettingsState && window.smsSettingsState.integration_status === 'failed') {
      sendBtn.title = 'Last connection test failed — campaign send will still be attempted using your DLT template.';
    } else if (window.smsSettingsState && window.smsSettingsState.integration_status !== 'integrated') {
      sendBtn.title = 'Send SMS using your configured SMS Alert credentials.';
    } else {
      sendBtn.removeAttribute('title');
    }
    updateSmsCampaignSendNotice(blockers);
  }

  function updateSmsCampaignSendNotice(blockers) {
    var notice = document.getElementById('form-campaign-sms-send-notice');
    if (!notice) return;
    blockers = blockers || getSmsSendBlockers();
    if (!blockers.length) {
      notice.classList.add('hidden');
      notice.textContent = '';
      return;
    }
    notice.classList.remove('hidden');
    notice.innerHTML = '<strong>Send SMS is blocked:</strong> ' + escapeHtml(blockers[0]) +
      ' Open <strong>Settings → SMS Configuration</strong> and set Mode to <strong>Live</strong>.';
  }

  function ensureSmsSendAllowed() {
    var blockers = getSmsSendBlockers();
    if (blockers.length) {
      toast(blockers[0], 'error');
      return false;
    }
    return true;
  }

  function renderSmsTemplatesTable(templates) {
    var tbody = document.getElementById('sms-templates-table');
    if (!tbody) return;
    templates = templates || window.realSmsTemplates || [];
    if (!templates.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-slate-400 py-6">No DLT templates yet. Add an approved template to start sending SMS.</td></tr>';
      return;
    }
    tbody.innerHTML = templates.map(function (t) {
      var body = t.body_template || '';
      if (body.length > 80) body = body.slice(0, 80) + '…';
      var statusBadge = t.status === 'approved' ? 'badge-success' : (t.status === 'pending' ? 'badge-warning' : 'badge-danger');
      var actionsCell = !window.smsTemplateCanManage
        ? '<td class="crm-actions-cell text-right"><span class="cam-cell-empty">—</span></td>'
        : (window.CAActionDropdown
          ? CAActionDropdown.renderCell([
            { action: 'edit', label: 'Edit', icon: 'pencil' },
            { action: 'delete', label: 'Delete', icon: 'trash-2', danger: true },
          ], {
            scope: 'sms-template',
            rowId: t.id,
            cellClass: 'crm-actions-cell text-right',
            ariaLabel: 'Template actions',
          })
          : '<td class="crm-actions-cell text-right"><span class="cam-cell-empty">—</span></td>');
      return '<tr>' +
        '<td>' + escapeHtml(t.template_name) + '</td>' +
        '<td>' + escapeHtml(t.sender_id || '—') + '</td>' +
        '<td class="font-mono text-xs">' + escapeHtml(t.dlt_template_id || '—') + '</td>' +
        '<td class="max-w-md truncate" title="' + escapeHtml(t.body_template || '') + '">' + escapeHtml(body) + '</td>' +
        '<td><span class="badge ' + statusBadge + '">' + escapeHtml(t.status || '—') + '</span></td>' +
        actionsCell +
      '</tr>';
    }).join('');
    icons();
  }

  function bindSmsTemplateHandlers() {
    if (window._smsTemplateHandlersBound) return;
    window._smsTemplateHandlersBound = true;

    document.addEventListener('click', function (e) {
      if (e.target.closest('#sms-template-add-btn')) {
        e.preventDefault();
        var wrap = document.getElementById('sms-template-form-wrap');
        if (wrap) wrap.classList.remove('hidden');
        var idEl = document.getElementById('sms-template-form-id');
        if (idEl) idEl.value = '';
        ['sms-template-form-name', 'sms-template-form-sender', 'sms-template-form-dlt-id', 'sms-template-form-body'].forEach(function (id) {
          var el = document.getElementById(id);
          if (el) el.value = '';
        });
        var statusEl = document.getElementById('sms-template-form-status');
        if (statusEl) statusEl.value = 'approved';
        return;
      }
      if (e.target.closest('#sms-template-form-cancel')) {
        e.preventDefault();
        document.getElementById('sms-template-form-wrap')?.classList.add('hidden');
        return;
      }
      if (e.target.closest('#sms-template-form-save')) {
        e.preventDefault();
        saveSmsTemplateForm();
      }
    });
  }

  function openSmsTemplateForm(templateId) {
    var template = (window.realSmsTemplates || []).find(function (t) {
      return parseInt(t.id, 10) === templateId;
    });
    if (!template) return;
    var wrap = document.getElementById('sms-template-form-wrap');
    if (wrap) wrap.classList.remove('hidden');
    var idEl = document.getElementById('sms-template-form-id');
    if (idEl) idEl.value = String(template.id);
    var setVal = function (id, val) {
      var el = document.getElementById(id);
      if (el) el.value = val || '';
    };
    setVal('sms-template-form-name', template.template_name);
    setVal('sms-template-form-sender', template.sender_id);
    setVal('sms-template-form-dlt-id', template.dlt_template_id);
    setVal('sms-template-form-body', template.body_template);
    setVal('sms-template-form-status', template.status || 'pending');
  }

  function saveSmsTemplateForm() {
    var id = document.getElementById('sms-template-form-id')?.value || '';
    var payload = {
      template_name: document.getElementById('sms-template-form-name')?.value?.trim() || '',
      sender_id: document.getElementById('sms-template-form-sender')?.value?.trim() || '',
      dlt_template_id: document.getElementById('sms-template-form-dlt-id')?.value?.trim() || '',
      body_template: document.getElementById('sms-template-form-body')?.value?.trim() || '',
      status: document.getElementById('sms-template-form-status')?.value || 'pending',
      is_active: true,
    };
    if (!payload.template_name || !payload.sender_id || !payload.body_template) {
      toast('Template name, sender ID, and body are required', 'error');
      return;
    }
    var req = id
      ? apiFetch('/sms-templates/' + id, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      : apiFetch('/sms-templates', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    req.then(function () {
      toast('SMS template saved', 'success');
      document.getElementById('sms-template-form-wrap')?.classList.add('hidden');
      refreshSmsTemplatesPanel();
    }).catch(function (err) {
      toast(err.message || 'Unable to save template', 'error');
    });
  }

  function refreshSmsTemplatesPanel() {
    loadSmsTemplatesFromDatabase(function (templates) {
      renderSmsTemplatesTable(templates);
      populateSmsTemplateSelect();
    }, false);
  }

  function smsCampaignCardHtml(c) {
    var sent = c.delivered_count || 0;
    var failed = c.failed_count || 0;
    var total = c.total_sms || 0;
    var previewBtn = iconBtn('code-2', 'Preview Payload', 'data-preview-sms-payload="' + c.id + '"', 'secondary');
    var sendBtn = (c.status === 'Draft' || c.status === 'Scheduled')
      ? iconBtn('send', 'Send', 'data-send-sms-campaign="' + c.id + '"', 'primary')
      : '';
    var actions = '<div class="flex flex-wrap gap-2 mt-3">' + sendBtn + previewBtn + campaignDeleteButton('sms', c.id, c.status) + '</div>';
    var subtitle = c.status === 'Completed' || c.status === 'Partial'
      ? 'Sent: ' + sent + ' · Failed: ' + failed
      : 'Recipients: ' + total + ' · Draft' + (window.smsSettingsState && window.smsSettingsState.mode !== 'live' ? ' · SMS in Simulation mode' : '');
    return '<div class="card-interactive p-4 campaign-card" data-sms-campaign-id="' + c.id + '">' +
      '<div class="flex justify-between mb-2"><p class="text-card-heading">' + escapeHtml(c.campaign_name) + '</p>' + waStatusBadge(c.status) + '</div>' +
      '<p class="text-caption text-slate-500">Type: ' + escapeHtml(c.campaign_type) + ' · Audience: ' + escapeHtml(c.audience_label || c.audience_mode) + '</p>' +
      '<p class="text-caption text-slate-500 mt-1">' + escapeHtml(subtitle) + '</p>' + actions +
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
    setText('sms-kpi-sent', formatCampaignNumber(metrics.sms_sent || metrics.sms_delivered || 0));
    setText('sms-kpi-pending-logs', formatCampaignNumber(metrics.sms_pending || metrics.sms_queued || 0));
    setText('sms-kpi-failed-logs', formatCampaignNumber((metrics.sms_failed || 0) + (metrics.sms_api_error || 0)));
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
      tbody.innerHTML = '<tr><td colspan="9" class="text-center text-slate-400 py-8">No SMS logs yet. Send a campaign to create delivery logs.</td></tr>';
      return;
    }
    tbody.innerHTML = logs.map(function (log) {
      var provider = log.provider_response || '';
      if (provider.length > 80) provider = provider.slice(0, 80) + '…';
      return '<tr>' +
        '<td>' + escapeHtml(log.campaign_name || log.campaign_id || '—') + '</td>' +
        '<td>' + escapeHtml(log.template_name || '—') + '</td>' +
        '<td class="font-mono text-xs">' + escapeHtml(log.dlt_template_id || '—') + '</td>' +
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
    var u = window.__CRM_USER__ || {};
    window.smsTemplateCanManage = u.role === 'admin' || u.role === 'super_admin';
    loadLeadsForSelects();
    loadDashboardMetricsFromDatabase(function (metrics) {
      renderSmsKpis(metrics);
    });
    apiFetch('/sms-settings').then(function (body) {
      window.smsSettingsState = body.data || {};
      updateSmsSendButtonState();
    }).catch(function () {});
    refreshSmsTemplatesPanel();
    bindSmsTemplateHandlers();
    var addBtn = document.getElementById('sms-template-add-btn');
    if (addBtn) addBtn.classList.toggle('hidden', !window.smsTemplateCanManage);
    loadSmsCampaignsFromDatabase(function () {
      renderSmsCampaignGrid();
    });
    loadSmsLogsFromDatabase(function (logs) {
      renderSmsLogsTable(logs);
    });
  }

  function updateSmsCampaignEstimates() {
    var mode = document.getElementById('form-campaign-audience-mode')?.value || 'all_leads';
    var count = mode === 'selected_leads' ? getCampaignSelectedLeadIds().length : getLeadsForSelects().length;
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
    var fromPicker = getCampaignLeadPickerState().selected[String(leadId)];
    if (fromPicker) return fromPicker;
    return getLeadsForSelects().find(function (l) {
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
    if (!form || !isCampaignModalOpen()) return;
    var fd = new FormData(form);
    var data = Object.fromEntries(fd.entries());
    if (!campaignNameFromForm(data)) {
      toast('Campaign name is required', 'error');
      return;
    }
    if (!(data.campaign_type && data.campaign_type.trim())) {
      toast('Campaign type is required', 'error');
      return;
    }
    if (!(document.getElementById('form-campaign-sms-template-id')?.value)) {
      toast('Select a DLT SMS template', 'error');
      return;
    }
    var smsPayload = buildCampaignAudiencePayload(data);
    if (smsPayload.audience_mode === 'selected_leads' && (!smsPayload.ca_ids || !smsPayload.ca_ids.length)) {
      toast('Select at least one lead', 'error');
      return;
    }
    var schedule = validateCampaignScheduledAt(data.scheduled_at);
    if (!schedule.valid) {
      toast(schedule.message || 'Scheduled date is invalid', 'error');
      return;
    }
    smsPayload.scheduled_at = schedule.value;
    smsPayload.sms_template_id = parseInt(document.getElementById('form-campaign-sms-template-id').value, 10);
    smsPayload.message_template = document.getElementById('form-campaign-sms-message-template')?.value?.trim() || '';
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
          resetFormDateTimePickers(form);
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
        (window.CAActionDropdown
          ? CAActionDropdown.renderCell([
            { action: 'remove', label: 'Delete', icon: 'trash-2', danger: true },
          ], {
            scope: 'dnd',
            rowId: row.id,
            cellClass: 'crm-actions-cell',
            ariaLabel: 'DND actions',
          })
          : '<td class="crm-actions-cell"><span class="cam-cell-empty">—</span></td>') +
      '</tr>';
    }).join('');
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
      var inboxSyncBtn = e.target.closest('#email-inbox-sync-btn');
      if (inboxSyncBtn) {
        e.preventDefault();
        e.stopPropagation();
        syncLatestEmailsFromInbox();
        return;
      }

      var launchEmailBtn = e.target.closest('[data-launch-email-campaign]');
      if (launchEmailBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (launchEmailBtn.disabled) return;
        launchEmailBtn.disabled = true;
        var emailCampaignId = launchEmailBtn.dataset.launchEmailCampaign;
        apiFetch('/email-campaigns/' + emailCampaignId + '/process', { method: 'POST' })
          .then(function (body) {
            var campaign = body.data || {};
            afterCampaignQueued('email', campaign.id || emailCampaignId, function () {
              refreshEmailPage();
              if (document.getElementById('recent-activity-list')) renderRecentActivity();
            }, body.message || 'Email campaign queued successfully.');
          })
          .catch(function (error) {
            toast(error.message || 'Failed to process email campaign', 'error');
          })
          .finally(function () {
            launchEmailBtn.disabled = false;
          });
        return;
      }

      var viewInboxBtn = e.target.closest('[data-view-inbox-message]');
      if (viewInboxBtn) {
        e.preventDefault();
        e.stopPropagation();
        var inboxId = viewInboxBtn.getAttribute('data-view-inbox-message');
        apiFetch('/email-inbox/' + encodeURIComponent(inboxId))
          .then(function (body) {
            var data = body.data || {};
            var msg = data.message || {};
            var preview = (msg.body || msg.body_preview || '').substring(0, 200);
            toast((msg.subject || 'Inbox message') + (preview ? ' — ' + preview : ''), 'info');
            if (data.suggest_followup) {
              toast('Customer replied — consider creating a follow-up', 'info');
            }
            refreshEmailPage();
          })
          .catch(function (error) { toast(error.message || 'Unable to load inbox message', 'error'); });
        return;
      }

      var retryEmailBtn = e.target.closest('[data-retry-email-campaign]');
      if (retryEmailBtn) {
        e.preventDefault();
        e.stopPropagation();
        var retryCampaignId = retryEmailBtn.dataset.retryEmailCampaign;
        apiFetch('/email-campaigns/' + retryCampaignId + '/retry-failed', { method: 'POST' })
          .then(function () {
            toast('Failed messages queued for retry', 'success');
            refreshEmailPage();
          })
          .catch(function (error) {
            toast(error.message || 'Failed to retry email campaign messages', 'error');
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
            toast('Payload preview saved — this does not send SMS. Click Send on the campaign card to deliver.', 'success');
            refreshSmsPage();
            if (document.getElementById('recent-activity-list')) renderRecentActivity();
          })
          .catch(function (error) {
            toast(error.message || 'Unable to create SMS payload preview', 'error');
          });
        return;
      }

      var sendSmsBtn = e.target.closest('[data-send-sms-campaign]');
      if (sendSmsBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (!ensureSmsSendAllowed()) return;
        if (!window.confirm('Send this SMS campaign now using the DLT template?')) return;
        if (sendSmsBtn.disabled) return;
        sendSmsBtn.disabled = true;
        var smsCampaignId = sendSmsBtn.getAttribute('data-send-sms-campaign');
        apiFetch('/sms-campaigns/' + smsCampaignId + '/process', { method: 'POST' })
          .then(function (body) {
            afterCampaignQueued('sms', smsCampaignId, refreshSmsPage, body.message || 'SMS campaign queued successfully.');
          })
          .catch(function (error) {
            toast(error.message || 'Failed to send SMS campaign', 'error');
          })
          .finally(function () {
            sendSmsBtn.disabled = false;
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
        if (launchWaBtn.disabled) return;
        launchWaBtn.disabled = true;
        var campaignId = launchWaBtn.dataset.launchWhatsappCampaign;
        apiFetch('/whatsapp-campaigns/' + campaignId + '/process', { method: 'POST' })
          .then(function (body) {
            afterCampaignQueued('whatsapp', campaignId, function () {
              refreshWhatsAppPage();
              if (document.getElementById('recent-activity-list')) renderRecentActivity();
            }, body.message || 'WhatsApp campaign queued successfully.');
          })
          .catch(function (error) {
            toast(error.message || 'Failed to process campaign', 'error');
          })
          .finally(function () {
            launchWaBtn.disabled = false;
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
    } else if (channel === 'email') {
      refreshEmailPage();
    } else if (channel === 'sms') {
      refreshSmsPage();
    } else {
      renderCampaignGrids();
      return;
    }
    setTimeout(function () { applyPendingCampaignLeadSelection(channel); }, 200);
  }

  function bindModalTriggers(root) {
    (root || document).querySelectorAll('[data-open-modal]').forEach(function (btn) {
      if (btn._crmBound) return;
      btn._crmBound = true;
      btn.addEventListener('click', function () {
        var modalKey = btn.dataset.openModal;
        var id = 'modal-' + modalKey;
        var modal = document.getElementById(id);
        if (!modal) return;

        function prepareModalContents() {
          populateSelects();
          if (modalKey === 'assign-lead') {
            window._editingAssignmentId = null;
            if (!window._inboxBulkLeadIds || window._inboxBulkLeadIds.length <= 1) {
              var singleLeadId = CAData.getSelectedLeadId();
              window._inboxBulkLeadIds = singleLeadId ? [parseInt(singleLeadId, 10)] : null;
              resetAssignLeadModalUi();
            }
            var assignSelId = CAData.getSelectedLeadId();
            var assignSel = document.getElementById('form-assign-lead-select');
            setSelectValueIfValid(assignSel, assignSelId);
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
          icons();
        }

        function loadFollowupModalContents(triggerBtn) {
          window._editingFollowUpId = null;
          window._followupOriginalScheduled = '';
          var isGlobalSchedule = triggerBtn && triggerBtn.hasAttribute('data-manager-schedule-followup');
          if (isGlobalSchedule) {
            openFollowupModalWithLeads([], { mode: 'global' });
            return;
          }
          var bulkIds = (window._inboxBulkLeadIds || []).map(function (id) { return parseInt(id, 10); }).filter(Boolean);
          var followSelId = CAData.getSelectedLeadId();
          var presetIds = bulkIds.length === 1 ? bulkIds : (followSelId ? [parseInt(followSelId, 10)] : []);
          openFollowupModalWithLeads(presetIds, {
            mode: presetIds.length === 1 ? 'row' : 'global',
          });
        }

        if (modalKey === 'followup') {
          loadFollowupModalContents(btn);
          return;
        }

        if (modalKey === 'assign-daily-target') {
          openDailyTargetModal(null);
          return;
        }

        openExclusiveCrmModal(modal);
        icons();

        if (modalKey === 'add-campaign' && ['whatsapp', 'email', 'sms'].indexOf(btn.dataset.campaignChannel || 'whatsapp') >= 0) {
          var campaignChannel = btn.dataset.campaignChannel || 'whatsapp';
          var campaignForm = document.getElementById('form-add-campaign');
          if (campaignForm) campaignForm.reset();
          configureCampaignModal(campaignChannel);
          ensureFormSelectData(function () {
            populateSelects();
            populateCampaignAudienceSelects(campaignChannel);
            if (window.CA_STATE_CITY) {
              window.CA_STATE_CITY.prepareForm('form-add-campaign');
            }
            icons();
          });
        } else if (modalKey === 'assign-lead' || modalKey === 'add-lead') {
          if (modalKey === 'assign-lead') {
            window._editingAssignmentId = null;
            if (!window._inboxBulkLeadIds || window._inboxBulkLeadIds.length <= 1) {
              var leadId = CAData.getSelectedLeadId();
              window._inboxBulkLeadIds = leadId ? [parseInt(leadId, 10)] : null;
              resetAssignLeadModalUi();
            }
          }
          if (modalKey === 'add-lead') {
            openLeadFormForAdd();
          }
          ensureFormSelectData(prepareModalContents);
        } else {
          prepareModalContents();
        }
      });
    });
  }

  function submitLeadForm() {
    var form = document.getElementById('form-add-lead');
    if (!form || form.dataset.formPurpose !== 'lead' || !isLeadModalOpen()) return;
    if (window._leadDuplicateBlocked) {
      toast('This phone number already exists. Resolve the duplicate before saving.', 'warning');
      return;
    }

    var fd = new FormData(form);
    var editingId = fd.get('ca_id') || window._editingLeadId;
    var url = editingId ? '/ca-masters/' + editingId : '/ca-masters';
    var submitBtn = document.getElementById('add-lead-submit-btn');

    if (editingId) {
      fd.append('_method', 'PUT');
    }

    if (editingId && isEmployeeUser()) {
      (window._leadLockedFields || []).forEach(function (fieldName) {
        fd.delete(fieldName);
      });
    }

    if (!isEmployeeUser() && form.elements.executive_id && !form.elements.executive_id.disabled) {
      var executiveValue = form.elements.executive_id.value;
      if (executiveValue) {
        fd.set('executive_id', executiveValue);
      } else {
        fd.delete('executive_id');
      }
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.dataset.prevText = submitBtn.textContent;
      submitBtn.textContent = 'Saving…';
    }

    apiFetch(url, { method: 'POST', body: fd })
      .then(function (body) {
        window._editingLeadId = '';
        closeModal(document.getElementById('modal-add-lead'));
        resetLeadForm();
        window._leadSegmentFilter = 'all';
        if (window.CA_LISTING_SEARCH) {
          CA_LISTING_SEARCH.setState('ca_masters', { page: 1, filters: {}, search: '' });
        }
        return applyLeadMutationSuccess(body, {
          toast: editingId ? 'Lead updated successfully' : 'Lead saved successfully',
          cacheKeys: ['metrics', 'leads', 'assignments'],
          callback: function () {
            if (document.getElementById('mgr-kpi-sections')) {
              renderManagerDashboard();
            }
            renderMasterTables();
          },
        });
      })
      .catch(function (error) {
        if (error.duplicate) {
          renderLeadDuplicateWarning(error.duplicate);
        }
        toast(error.message || 'Error while saving lead', error.duplicate ? 'warning' : 'error');
      })
      .finally(function () {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = submitBtn.dataset.prevText || 'Save Lead';
        }
      });
  }

  function handleCampaignFormSubmit() {
    if (!isCampaignModalOpen()) return;

    var form = document.getElementById('form-add-campaign');
    if (!form || form.dataset.formPurpose !== 'campaign') return;

    var fd = new FormData(form);
    var data = Object.fromEntries(fd.entries());
    var channel = data.channel || document.getElementById('form-campaign-channel')?.value || 'whatsapp';

    if (!campaignNameFromForm(data)) {
      toast('Campaign name is required', 'error');
      return;
    }
    if (!(data.campaign_type && data.campaign_type.trim())) {
      toast('Campaign type is required', 'error');
      return;
    }

    var scheduleCheck = validateCampaignScheduledAt(data.scheduled_at);
    if (!scheduleCheck.valid) {
      toast(scheduleCheck.message || 'Scheduled date is invalid', 'error');
      return;
    }

    if (channel === 'whatsapp') {
      submitWhatsAppCampaign(form);
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
      if (emailPayload.audience_mode === 'selected_leads' && (!emailPayload.ca_ids || !emailPayload.ca_ids.length)) {
        toast('Select at least one lead', 'error');
        return;
      }
      emailPayload.subject = data.subject.trim();
      emailPayload.body_template = data.body_template.trim();
      var emailTemplateId = parseInt(document.getElementById('form-campaign-email-template-id')?.value || '', 10);
      if (emailTemplateId) emailPayload.email_template_id = emailTemplateId;
      var emailConfigId = parseInt(document.getElementById('form-campaign-email-config-id')?.value || '', 10);
      if (emailConfigId) emailPayload.email_config_id = emailConfigId;

      apiFetch('/email-campaigns', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(emailPayload),
      })
        .then(function (body) {
          var campaign = body.data || {};
          closeModal(document.getElementById('modal-add-campaign'));
          form.reset();
          resetFormDateTimePickers(form);
          configureCampaignModal('email');
          refreshEmailPage();
          if (document.getElementById('recent-activity-list')) renderRecentActivity();
          if (campaign.status === 'Scheduled' && campaign.scheduled_at) {
            toast('Scheduled for ' + formatSchedulePreview(campaign.scheduled_at), 'success');
          } else if (campaign.status === 'Processing') {
            closeModal(document.getElementById('modal-add-campaign'));
            form.reset();
            resetFormDateTimePickers(form);
            configureCampaignModal('email');
            afterCampaignQueued('email', campaign.id, refreshEmailPage, body.message || 'Email campaign queued successfully.');
          } else {
            toast(
              'Campaign "' + (campaign.campaign_name || campaignNameFromForm(data)) + '" created · ' +
              (campaign.total_emails || 0) + ' emails · ' + (campaign.status || 'Completed'),
              'success',
            );
          }
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
  }

  function initForms() {
    initLeadDuplicateChecks();

    document.getElementById('form-add-lead')?.addEventListener('submit', function (e) {
      e.preventDefault();
      e.stopPropagation();
      submitLeadForm();
    });

    document.getElementById('add-lead-submit-btn')?.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      submitLeadForm();
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
        mobile_no: (fd.get('mobile_no') || '').trim() || null,
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
          invalidateDataCaches(['metrics', 'segment_counts', 'leads']);
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
        if (!fd.get('role')) fd.set('role', 'Employee');
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

    document.getElementById('form-change-login-email')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      if ((fd.get('new_email') || '').trim() !== (fd.get('new_email_confirmation') || '').trim()) {
        toast('New email and confirmation must match', 'error');
        return;
      }
      var submitBtn = document.getElementById('change-login-email-submit-btn');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending…';
      }
      apiFetch('/auth/login-email-change', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          new_email: (fd.get('new_email') || '').trim(),
          new_email_confirmation: (fd.get('new_email_confirmation') || '').trim(),
          current_password: fd.get('current_password') || '',
        }),
      })
        .then(function (body) {
          renderLoginEmailChangeStatus(body.data || body);
          var newEmailInput = e.target.querySelector('[name="new_email"]');
          var confirmInput = e.target.querySelector('[name="new_email_confirmation"]');
          var passwordInput = e.target.querySelector('[name="current_password"]');
          if (newEmailInput) newEmailInput.value = '';
          if (confirmInput) confirmInput.value = '';
          if (passwordInput) passwordInput.value = '';
          toast(body.message || 'Verification email sent', 'success');
          icons();
        })
        .catch(function (error) {
          toast(error.message || 'Unable to request login email change', 'error');
        })
        .finally(function () {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Send Verification Email';
          }
        });
    });

    document.getElementById('login-email-resend-btn')?.addEventListener('click', function () {
      var btn = this;
      btn.disabled = true;
      apiFetch('/auth/login-email-change/resend', { method: 'POST' })
        .then(function (body) {
          renderLoginEmailChangeStatus(body.data || body);
          toast(body.message || 'Verification email resent', 'success');
          icons();
        })
        .catch(function (error) {
          toast(error.message || 'Unable to resend verification email', 'error');
        })
        .finally(function () {
          btn.disabled = false;
        });
    });

    document.getElementById('login-email-cancel-btn')?.addEventListener('click', function () {
      var btn = this;
      btn.disabled = true;
      apiFetch('/auth/login-email-change/cancel', { method: 'POST' })
        .then(function (body) {
          renderLoginEmailChangeStatus(body.data || body);
          toast(body.message || 'Pending login email change cancelled', 'success');
          icons();
        })
        .catch(function (error) {
          toast(error.message || 'Unable to cancel pending request', 'error');
        })
        .finally(function () {
          btn.disabled = false;
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
      var bulkLeadIds = (window._inboxBulkLeadIds || []).map(function (id) {
        return parseInt(id, 10);
      }).filter(function (id) { return id > 0; });

      if (!executiveId) {
        toast('Please select an employee', 'warning');
        return;
      }

      // Bulk assign uses only the selected lead IDs captured when the modal opened.
      if (!window._editingAssignmentId && bulkLeadIds.length > 1) {
        apiFetch('/lead-assignments/bulk', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            ca_ids: bulkLeadIds,
            employee_ids: [parseInt(executiveId, 10)],
            assignment_mode: 'manual',
            reason: fd.get('reason') || 'MANUAL_ASSIGN',
          }),
        })
          .then(function (body) {
            var data = body.data || {};
            var affected = (data.assigned_rows || 0) + (data.reassigned_rows || 0);
            closeModal(document.getElementById('modal-assign-lead'));
            window._editingAssignmentId = null;
            window._inboxBulkLeadIds = null;
            e.target.reset();
            resetAssignLeadModalUi();
            clearInboxSelection('leads-data-table');
            clearInboxSelection('ca-master-data-table');
            invalidateDataCaches(['metrics', 'segment_counts', 'leads', 'assignments', 'employee_dashboard']);
            reloadLeadDataAfterMutation({
              cacheKeys: ['metrics', 'leads', 'assignments', 'employee_dashboard'],
            }).then(function () {
              if (affected !== bulkLeadIds.length) {
                toast('Assigned ' + affected + ' of ' + bulkLeadIds.length + ' selected leads', 'warning');
              } else {
                toast(affected + ' selected lead(s) assigned successfully', 'success');
              }
            });
          })
          .catch(function (error) {
            toast(error.message || 'Error while assigning selected leads', 'error');
          });
        return;
      }

      if (!caId) {
        toast('Please select a lead and employee', 'warning');
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
          window._inboxBulkLeadIds = null;
          e.target.reset();
          resetAssignLeadModalUi();
          clearInboxSelection('leads-data-table');
          clearInboxSelection('ca-master-data-table');
          invalidateDataCaches(['metrics', 'segment_counts', 'leads', 'assignments', 'employee_dashboard']);
          reloadLeadDataAfterMutation({
            cacheKeys: ['metrics', 'leads', 'assignments', 'employee_dashboard'],
          }).then(function () {
            toast(editingAssignmentId ? 'Assignment updated successfully' : 'Lead assigned successfully', 'success');
          });
        })
        .catch(function (error) {
          toast(error.message || 'Error while assigning lead', 'error');
        });
    });

    document.getElementById('bulk-delete-leads-confirm')?.addEventListener('click', function () {
      confirmBulkDeleteLeads();
    });

    document.getElementById('form-followup')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var editingId = window._editingFollowUpId;
      var url = editingId ? '/follow-ups/' + encodeURIComponent(editingId) : '/follow-ups';
      var method = editingId ? 'PUT' : 'POST';
      var payload = Object.fromEntries(fd.entries());
      var scheduleCheck = validateFollowUpScheduledAt(payload.scheduled_date);
      if (!scheduleCheck.valid && !editingId) {
        toast(scheduleCheck.message || 'Please select a future date and time.', 'error');
        return;
      }
      if (!scheduleCheck.valid && editingId && payload.scheduled_date) {
        var original = window._followupOriginalScheduled || '';
        var newSlice = String(payload.scheduled_date).slice(0, 16);
        if (newSlice !== original) {
          toast(scheduleCheck.message || 'Please select a future date and time.', 'error');
          return;
        }
      }
      if (editingId && window._followupOriginalScheduled && payload.scheduled_date) {
        var newSlice = String(payload.scheduled_date).slice(0, 16);
        if (newSlice !== window._followupOriginalScheduled && !payload.reschedule_reason) {
          toast('Please provide a reschedule reason when changing the date.', 'warning');
          var wrap = document.getElementById('followup-reschedule-reason-wrap');
          if (wrap) wrap.classList.remove('hidden');
          return;
        }
      }

      if (isFollowupDemoScheduledType(payload.followup_type)) {
        if (!payload.meeting_link || !String(payload.meeting_link).trim()) {
          toast('Meeting link is required for Demo Scheduled follow-ups.', 'error');
          var linkInput = document.getElementById('form-followup-meeting-link');
          if (linkInput) linkInput.focus();
          return;
        }
        if (payload.team_size) payload.team_size = parseInt(payload.team_size, 10);
      } else {
        delete payload.team_size;
        delete payload.demo_provider_name;
        delete payload.meeting_link;
      }

      delete payload.ca_id;
      var leadId = normalizeFollowupLeadId(document.getElementById('form-followup-ca-id')?.value);
      if (!leadId && window._followupModalMode !== 'row' && window.CrmLeadPicker) {
        leadId = window.CrmLeadPicker.firstSelectedId('followup');
        if (leadId) {
          var pickerLeads = window.CrmLeadPicker.selectedLeads('followup');
          if (pickerLeads[0]) renderFollowupLeadContext(pickerLeads[0]);
        }
      }
      if (!leadId) {
        setFollowupLeadError('Please select a lead.');
        return;
      }
      clearFollowupLeadError();

      function afterFollowupSaved(updatedFollowup) {
        closeModal(document.getElementById('modal-followup'));
        e.target.reset();
        resetFormDateTimePickers(e.target);
        var savedId = window._editingFollowUpId;
        window._editingFollowUpId = null;
        window._followupOriginalScheduled = '';
        window._inboxBulkLeadIds = null;
        window._followupModalMode = 'global';
        resetFollowupLeadPicker();
        setFollowupLeadPickerVisible(true);
        var demoWrap = document.getElementById('followup-demo-fields-wrap');
        if (demoWrap) demoWrap.classList.add('hidden');
        resetFollowupDemoFieldState();
        invalidateDataCaches(['metrics', 'followups']);
        if (updatedFollowup && savedId) {
          upsertFollowupInCache(updatedFollowup);
          updateFollowupTableRow(savedId, updatedFollowup);
          refreshFollowupsPage({ reload: false, calendar: true });
        } else {
          if (window.CA_LISTING_SEARCH) {
            CA_LISTING_SEARCH.setState('follow_ups', { page: 1 });
          }
          refreshFollowupsPage({ reload: true, calendar: true });
        }
        toast(savedId ? 'Follow-up updated successfully' : 'Follow-up saved successfully', 'success');
      }

      payload.ca_id = leadId;
      apiFetch(url, { method: method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(function (body) { afterFollowupSaved(body && body.data ? body.data : null); })
        .catch(function (error) {
          toast(formatApiErrorMessage(error, 'Error while saving follow-up'), 'error');
        });
    });

    document.getElementById('form-call-outcome')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var form = e.target;
      var payload = validateCallOutcomeForm(form);
      if (!payload) return;

      var submitBtn = document.querySelector('button[form="form-call-outcome"]');
      if (submitBtn) submitBtn.disabled = true;

      apiFetch('/follow-ups/call-outcome', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function () {
          closeModal(document.getElementById('modal-call-outcome'));
          form.reset();
          clearCallOutcomeErrors(form);
          syncCallOutcomeFields();
          invalidateDataCaches(['metrics', 'followups']);
          refreshFollowupsPage({ reload: true, calendar: true });
          if (typeof loadEmployeeWorkflowLists === 'function') loadEmployeeWorkflowLists();
          if (typeof loadManagerWorkflowLists === 'function') loadManagerWorkflowLists();
          toast('Call outcome saved.', 'success');
        })
        .catch(function (error) {
          var message = error.message || 'Failed to save call outcome';
          if (/employee is required/i.test(message)) {
            message = 'Unable to save call outcome. Please try again.';
          }

          var fieldErrors = error.errors || null;
          var fieldMap = {
            outcome: 'outcome',
            remarks: 'remarks',
            next_followup_date: 'next_followup_date',
            demo_date: 'demo_date',
            demo_time: 'demo_time',
            demo_at: 'demo_date',
            meeting_link: 'meeting_link',
          };
          var focused = false;
          if (fieldErrors) {
            Object.keys(fieldMap).forEach(function (key) {
              if (fieldErrors[key] && fieldErrors[key][0]) {
                setCallOutcomeError(fieldMap[key], fieldErrors[key][0]);
                if (!focused) {
                  var input = form.querySelector('[name="' + fieldMap[key] + '"]');
                  if (input) {
                    input.focus();
                    focused = true;
                  }
                }
              }
            });
          }

          if (!focused) {
            var textMap = {
              'call note': 'remarks',
              'call status': 'outcome',
              'follow-up date': 'next_followup_date',
              'demo date': 'demo_date',
              'demo time': 'demo_time',
              'demo date/time': 'demo_date',
              'demo date and time': 'demo_date',
            };
            var mapped = null;
            Object.keys(textMap).forEach(function (key) {
              if (!mapped && message.toLowerCase().indexOf(key) >= 0) mapped = textMap[key];
            });
            if (mapped) {
              setCallOutcomeError(mapped, message);
              var fieldInput = form.querySelector('[name="' + mapped + '"]');
              if (fieldInput) fieldInput.focus();
            } else {
              toast(message, 'error');
            }
          }
        })
        .finally(function () {
          if (submitBtn) submitBtn.disabled = false;
        });
    });

    document.getElementById('form-lead-call-log')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var form = e.target;
      var payload = validateLeadCallLogForm(form);
      if (!payload) return;

      var submitBtn = document.querySelector('button[form="form-lead-call-log"]');
      if (submitBtn) submitBtn.disabled = true;

      submitLeadCallLog(payload)
        .then(function () {
          closeModal(document.getElementById('modal-lead-call-log'));
          form.reset();
          clearLeadCallLogErrors(form);
          syncLeadCallLogFields();
          invalidateDataCaches(['metrics', 'followups', 'segment_counts', 'leads']);
          refreshCaMasterOrLeadsTable();
          refreshFollowupsPage({ reload: true, calendar: true });
          if (typeof loadEmployeeWorkflowLists === 'function') loadEmployeeWorkflowLists();
          if (typeof loadManagerWorkflowLists === 'function') loadManagerWorkflowLists();
          toast('Call log saved.', 'success');
        })
        .catch(function (error) {
          var message = error.message || 'Failed to save call log';
          if (/employee is required/i.test(message)) {
            message = 'Unable to save call log. Please try again.';
          }
          if (error.status === 403) {
            toast('You do not have permission to log calls for this lead.', 'error');
            return;
          }
          toast(message, 'error');
        })
        .finally(function () {
          if (submitBtn) submitBtn.disabled = false;
        });
    });

    var leadCallLogStatus = document.getElementById('lead-call-log-status');
    if (leadCallLogStatus) {
      leadCallLogStatus.addEventListener('change', function () {
        clearLeadCallLogErrors(document.getElementById('form-lead-call-log'));
        syncLeadCallLogFields();
      });
    }

    var outcomeSelect = document.getElementById('call-outcome-select');
    if (outcomeSelect) {
      outcomeSelect.addEventListener('change', function () {
        clearCallOutcomeErrors(document.getElementById('form-call-outcome'));
        syncCallOutcomeFields();
      });
    }

    document.getElementById('form-schedule-demo')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var payload = Object.fromEntries(fd.entries());
      if (!payload.ca_id || !payload.demo_at || !payload.meeting_link) {
        toast('Lead, demo date/time, and meeting link are required.', 'warning');
        return;
      }
      apiFetch('/workflow/demos', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function () {
          closeModal(document.getElementById('modal-schedule-demo'));
          e.target.reset();
          realFollowUpsLoaded = false;
          refreshAll();
          loadEmployeeWorkflowLists();
          loadManagerWorkflowLists();
          toast('Demo scheduled. Reminders queued.', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Failed to schedule demo', 'error');
        });
    });

    document.getElementById('form-demo-result')?.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(e.target);
      var payload = Object.fromEntries(fd.entries());
      var scheduleId = payload.demo_schedule_id;
      if (!scheduleId || !payload.result) {
        toast('Demo and result are required.', 'warning');
        return;
      }
      delete payload.demo_schedule_id;
      delete payload.sale_month_preview;
      if (payload.plan_purchased) payload.software_name = payload.plan_purchased;
      if (payload.points) payload.points = parseInt(payload.points, 10);
      if (payload.cooling_period_days) payload.cooling_period_days = parseInt(payload.cooling_period_days, 10);
      if (payload.total_amount !== undefined && payload.total_amount !== '') payload.total_amount = parseFloat(payload.total_amount);
      if (payload.amount_received !== undefined && payload.amount_received !== '') payload.amount_received = parseFloat(payload.amount_received);
      if (!payload.employee_id) delete payload.employee_id;
      if (!payload.manager_id) delete payload.manager_id;
      if (!payload.invoice_number) delete payload.invoice_number;
      apiFetch('/workflow/demos/' + scheduleId + '/result', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function () {
          closeModal(document.getElementById('modal-demo-result'));
          e.target.reset();
          realFollowUpsLoaded = false;
          refreshAll();
          loadEmployeeWorkflowLists();
          loadManagerWorkflowLists();
          if (document.getElementById('demo-history-table')) loadDemoHistory();
          toast('Demo result saved.', 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Failed to save demo result', 'error');
        });
    });

    var demoResultSelect = document.getElementById('demo-result-select');
    if (demoResultSelect) {
      demoResultSelect.addEventListener('change', function () {
        var wrap = document.getElementById('demo-result-purchase-wrap');
        var purchaseResults = ['Purchased', 'Purchasing'];
        var showPurchase = purchaseResults.indexOf(demoResultSelect.value) >= 0;
        if (wrap) wrap.classList.toggle('hidden', !showPurchase);
        if (showPurchase) {
          ensureDemoPurchaseOptions().then(function () {
            populateDemoResultPlanSelect();
            syncDemoPurchasePreview(true);
            enhanceEntityLookups(document.getElementById('modal-demo-result') || document);
          });
        }
      });
    }

    var demoResultForm = document.getElementById('form-demo-result');
    if (demoResultForm && !demoResultForm._demoPurchasePreviewBound) {
      demoResultForm._demoPurchasePreviewBound = true;
      demoResultForm.addEventListener('input', function (e) {
        if (!e.target || !e.target.id) return;
        if (e.target.id === 'demo-result-plan') {
          syncDemoPurchasePreview(true);
          return;
        }
        if (['demo-result-purchase-date', 'demo-result-total', 'demo-result-received', 'demo-result-cooling', 'demo-result-points'].indexOf(e.target.id) !== -1) {
          syncDemoPurchasePreview(false);
        }
      });
      demoResultForm.addEventListener('change', function (e) {
        if (!e.target || !e.target.id) return;
        if (e.target.id === 'demo-result-plan' || e.target.id === 'demo-result-purchase-date') {
          syncDemoPurchasePreview(e.target.id === 'demo-result-plan');
        }
      });
    }

    var fuScheduled = document.querySelector('#form-followup [name="scheduled_date"]');
    if (fuScheduled) {
      initFollowUpDateTimeField();
      initFollowUpDemoFields();
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
      e.stopPropagation();
      handleCampaignFormSubmit();
    });

    document.getElementById('btn-create-campaign')?.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      handleCampaignFormSubmit();
    });

    document.getElementById('form-campaign-audience-mode')?.addEventListener('change', function () {
      toggleCampaignAudienceFields();
      if (this.value === 'selected_leads') {
        populateCampaignAudienceSelects(null, { preserveSelection: true });
      }
      updateSmsCampaignEstimates();
    });
    document.getElementById('form-campaign-sms-template-id')?.addEventListener('change', syncSmsCampaignTemplateBody);
    document.getElementById('form-campaign-whatsapp-template-id')?.addEventListener('change', syncWhatsAppCampaignTemplateBody);
    document.getElementById('form-campaign-email-template-id')?.addEventListener('change', syncEmailCampaignTemplateFields);
    document.getElementById('btn-wa-preview-message')?.addEventListener('click', previewWhatsAppCampaignMessage);
    document.getElementById('btn-email-preview-message')?.addEventListener('click', previewEmailCampaignMessage);
    document.getElementById('form-campaign-sms-message-template')?.addEventListener('input', updateSmsCampaignEstimates);

    document.getElementById('btn-sms-save-draft')?.addEventListener('click', function () {
      submitSmsCampaignDraft();
    });

    function sendSmsCampaignById(campaignId) {
      apiFetch('/sms-campaigns/' + campaignId + '/process', { method: 'POST' })
        .then(function (body) {
          closeModal(document.getElementById('modal-add-campaign'));
          afterCampaignQueued('sms', campaignId, refreshSmsPage, body.message || 'SMS campaign queued successfully.');
        })
        .catch(function (error) {
          toast(error.message || 'Failed to send SMS campaign', 'error');
        });
    }

    function finalizeSmsCampaignAfterSave(campaign, form) {
      if (!campaign) return;
      if (campaign.status === 'Scheduled' && campaign.scheduled_at) {
        closeModal(document.getElementById('modal-add-campaign'));
        if (form) {
          form.reset();
          resetFormDateTimePickers(form);
        }
        configureCampaignModal('sms');
        refreshSmsPage();
        toast('Scheduled for ' + formatSchedulePreview(campaign.scheduled_at), 'success');
        return;
      }
      sendSmsCampaignById(campaign.id);
    }

    document.getElementById('btn-sms-send')?.addEventListener('click', function () {
      if (!ensureSmsSendAllowed()) return;
      submitSmsCampaignDraft(function (campaign) {
        finalizeSmsCampaignAfterSave(campaign, document.getElementById('form-add-campaign'));
      });
    });

    document.getElementById('btn-sms-preview-message')?.addEventListener('click', function () {
      var templateId = parseInt(document.getElementById('form-campaign-sms-template-id')?.value || '', 10);
      var leadId = getFirstCampaignSelectedLeadId() || (getLeadsForSelects()[0] || {}).ca_id;
      if (!templateId) {
        toast('Select a DLT template first', 'error');
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
      apiFetch('/sms-templates/preview', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sms_template_id: templateId, lead_id: leadId }),
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
            toast(error.message || 'Unable to create payload preview', 'error');
          });
      });
    });

    document.querySelectorAll('[data-close-crm-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var modal = btn.closest('.ca-modal');
        if (modal && modal.id === 'modal-add-lead') {
          releaseLeadLock().finally(function () {
            closeModal(modal);
            resetLeadForm();
          });
          return;
        }
        closeModal(modal);
      });
    });

    if (!window._leadLockUnloadBound) {
      window._leadLockUnloadBound = true;
      window.addEventListener('beforeunload', releaseLeadLockBeacon);
    }

    document.getElementById('detail-followup-btn')?.addEventListener('click', function () {
      if (typeof closeDetailDrawer === 'function') closeDetailDrawer();
      window._editingFollowUpId = null;
      window._followupOriginalScheduled = '';
      var leadId = CAData.getSelectedLeadId();
      openFollowupModalWithLeads(
        leadId ? [parseInt(leadId, 10)] : [],
        { mode: leadId ? 'row' : 'global' },
      );
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
      openLeadFormForEdit(leadId).then(function (ok) {
        if (ok) {
          openExclusiveCrmModal(document.getElementById('modal-add-lead'));
          icons();
        }
      });
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

    initCampaignScheduledDateField();
    initFollowUpDateTimeField();
    initFollowUpDemoFields();
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
    var pageId = window._currentPageId || 'dashboard';
    if (pageId === 'dashboard') {
      if (isEmployeeUser()) renderEmployeeDashboard();
      else renderManagerDashboard();
      return;
    }
    onPage(pageId);
  }

  function refreshAll() {
    var pageId = window._currentPageId || 'dashboard';
    if (pageId === 'dashboard') {
      dashboardMetricsLoaded = false;
      dashboardMetricsPromise = null;
      employeeDashboardLoaded = false;
      employeeDashboardPromise = null;
      clearDashboardMetricsCache();
      if (isEmployeeUser()) renderEmployeeDashboard();
      else renderManagerDashboard();
      return;
    }
    invalidateDataCaches(['metrics']);
    refreshCurrentPage();
  }

  function onPage(pageId) {
    window._currentPageId = pageId;
    bindModalTriggers(document);
    enhanceEntityLookups(document.getElementById('page-container') || document);

    if (pageId === 'dashboard') {
      if (isEmployeeUser()) renderEmployeeDashboard();
      else renderManagerDashboard();
      if (window.CA_CRM && typeof CA_CRM.startNotificationPoller === 'function') {
        CA_CRM.startNotificationPoller();
      }
      icons();
      return;
    }
    if (pageId === 'demo-calendar') {
      if (window.CrmDemoCalendarPage && typeof window.CrmDemoCalendarPage.init === 'function') {
        window.CrmDemoCalendarPage.init();
      }
      icons();
      return;
    }
    if (pageId === 'recycle-bin') {
      loadRecycleBin();
      bindRecycleBinActions();
      icons();
      return;
    }
    if (pageId === 'leads' || pageId === 'leads-segments') {
      renderLeadsHub();
      icons();
      return;
    }
    if (pageId === 'employees' || pageId === 'assignment') {
      function paintEmployeesAssignmentPage() {
        if (window.CA_LISTING_SEARCH) {
          reloadListing('employees');
        } else {
          renderEmployeesTable();
        }
        renderLeaderboard();
        renderEmployeesPerformanceTable();
        renderAssignmentTable();
        renderAssignmentHistoryTable();
        renderAssignmentKpis();
        populateAssignmentExecutiveFilter();
        if (pageId === 'assignment') initAssignmentPage();
        icons();
      }
      paintEmployeesAssignmentPage();
      loadDashboardMetricsFromDatabase(function () {
        renderLeaderboard();
        renderEmployeesPerformanceTable();
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
      setFollowupTypePanels('list');
      if (window.CA_LISTING_SEARCH) {
        reloadListing('follow_ups');
      } else {
        renderFollowupsTable();
        renderFollowupKpis();
        renderFollowupCalendarFromData();
      }
      loadDashboardMetricsFromDatabase(function () {
        renderFollowupKpis();
        icons();
      });
      loadDemoHistory();
      initFollowupActivityTimeline();
      icons();
      return;
    }
    if (pageId === 'ca-master' || pageId === 'bulk') {
      if (pageId === 'bulk') window._leadSegmentFilter = 'all';
      var camHubEarly = document.getElementById('cam-hub');
      var camSecondaryEarly = camHubEarly ? camHubEarly.getAttribute('data-cam-secondary') : '';
      ensureMasterData(function () {
        function finishCaMasterPage() {
          initCaMasterPage();
          renderCamKpis();
          renderMasterTables();
          applyMasterDataRbac();
          var camHub = document.getElementById('cam-hub');
          var camSecondary = camHub ? camHub.getAttribute('data-cam-secondary') : '';
          if (pageId === 'bulk' || camSecondary === 'bulk') {
            showCamSecondaryView('bulk');
            var bulkWizard = document.getElementById('bulk-import-wizard');
            if (bulkWizard && bulkWizard.classList.contains('hidden') && typeof window.resetBulkImportWizard === 'function') {
              window.resetBulkImportWizard();
            }
          } else if (camSecondary === 'masters') {
            showCamSecondaryView('masters');
          } else if (isCamPipelineTabActive()) {
            loadKanbanLeads();
          } else {
            syncCamStageFilterFromState();
            renderCaMasterTable();
          }
          icons();
        }
        if (window.CA_LISTING_SEARCH && camSecondaryEarly === 'masters' && !(window.realCitiesCache && window.realCitiesCache.length)) {
          reloadListing('cities').then(function () {
            finishCaMasterPage();
          });
          return;
        }
        finishCaMasterPage();
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
    if (pageId === 'campaigns') {
      if (window.CA_CAMPAIGNS_HUB && typeof CA_CAMPAIGNS_HUB.refresh === 'function') {
        CA_CAMPAIGNS_HUB.refresh();
      }
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
    if (pageId === 'email-configuration') {
      initEmailConfigurationPage();
      icons();
      return;
    }
    if (pageId === 'roles-permissions') {
      initRolesPermissionsPage();
      icons();
      return;
    }
    if (pageId === 'settings-email-templates') {
      initSettingsEmailTemplatesPage();
      icons();
      return;
    }
    if (pageId === 'settings-whatsapp-templates') {
      initSettingsWhatsAppTemplatesPage();
      icons();
      return;
    }
    if (pageId === 'settings-google-api') {
      initSettingsGoogleApiPage();
      icons();
      return;
    }
    if (pageId === 'settings-demo-providers') {
      initDemoProvidersSettingsPage();
      icons();
      return;
    }
    if (pageId === 'security') {
      initSecurityPage();
      icons();
      return;
    }
    if (pageId === 'duplicate-attempts' || document.getElementById('dup-attempts-table')) {
      initDuplicateAttemptsPage();
      icons();
      return;
    }
    if (pageId === 'sales-list' || document.getElementById('sales-list-data-table')) {
      initSalesListPage();
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
    if (!window.CATablePagination || !pagination) return;
    var current = pagination.page || pagination.current_page || 1;
    var perPage = pagination.per_page || 10;
    var total = pagination.total || 0;
    var last = pagination.last_page || 1;
    var from = pagination.from != null ? pagination.from : (total ? ((current - 1) * perPage) + 1 : 0);
    var to = pagination.to != null ? pagination.to : (total ? Math.min(current * perPage, total) : 0);
    CATablePagination.renderInto(containerId, {
      scope: 'bulk-assign-' + type,
      pagination: {
        current_page: current,
        last_page: last,
        total: total,
        from: from,
        to: to,
        per_page: perPage,
      },
      perPage: perPage,
      showPerPage: false,
    });
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
          invalidateDataCaches(['metrics', 'segment_counts', 'leads', 'assignments', 'employee_dashboard']);
          loadBulkAssignBatches(1);
          loadBulkAssignEmployees(1);
          reloadLeadDataAfterMutation({ cacheKeys: ['metrics', 'leads', 'assignments', 'employee_dashboard'] });
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
    rowActions: {},
    canForceActions: false,
    lastBulkActionId: null,
    pollTimer: null,
    pollInFlight: false,
    importInProgress: false,
    validationTimer: null,
  };

  var bulkImportDetailState = {
    isOpen: false,
    bulkActionId: null,
    loading: false,
    details: null,
    error: null,
    requestSeq: 0,
    abortController: null,
  };

  function ensureBulkImportDetailModalRoot() {
    var modals = document.querySelectorAll('#modal-bulk-import-detail');
    if (!modals.length) return null;

    var rootModal = document.querySelector('#modal-bulk-import-detail[data-crm-modal-root="true"]')
      || document.body.querySelector(':scope > #modal-bulk-import-detail')
      || modals[0];

    modals.forEach(function (modal) {
      if (modal === rootModal) return;
      if (modal.classList.contains('open')) {
        rootModal.classList.add('open');
      }
      modal.remove();
    });

    if (rootModal.parentElement !== document.body) {
      document.body.appendChild(rootModal);
    }

    return rootModal;
  }

  function resetBulkImportDetailState() {
    if (bulkImportDetailState.abortController) {
      bulkImportDetailState.abortController.abort();
      bulkImportDetailState.abortController = null;
    }
    bulkImportDetailState.isOpen = false;
    bulkImportDetailState.bulkActionId = null;
    bulkImportDetailState.loading = false;
    bulkImportDetailState.details = null;
    bulkImportDetailState.error = null;
  }

  function closeBulkImportDetail() {
    var modal = ensureBulkImportDetailModalRoot();
    if (!modal) return;
    resetBulkImportDetailState();
    modal.classList.remove('bulk-import-detail--loading');
    modal.setAttribute('aria-busy', 'false');
    closeModal(modal);
  }

  function ensureBulkImportDetailCloseHandlers() {
    if (document._bulkImportDetailCloseBound) return;
    document._bulkImportDetailCloseBound = true;

    document.addEventListener('click', function (event) {
      var closeButton = event.target.closest('[data-close-bulk-import-detail]');
      if (closeButton) {
        event.preventDefault();
        event.stopPropagation();
        closeBulkImportDetail();
        return;
      }

      var modal = event.target.closest('#modal-bulk-import-detail');
      if (modal && event.target === modal && modal.getAttribute('data-close-on-backdrop') === 'true') {
        closeBulkImportDetail();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      var modal = document.getElementById('modal-bulk-import-detail');
      if (!modal || !modal.classList.contains('open')) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      closeBulkImportDetail();
    }, true);
  }

  function bindBulkImportDetailFooterActions() {
    var errorBtn = document.getElementById('bulk-detail-error-report-btn');
    var reimportBtn = document.getElementById('bulk-detail-reimport-btn');
    var reuploadBtn = document.getElementById('bulk-detail-reupload-btn');
    if (errorBtn && !errorBtn._detailBound) {
      errorBtn._detailBound = true;
      errorBtn.addEventListener('click', function () {
        if (!bulkImportDetailState.bulkActionId) return;
        window.location.href = '/ca-masters/bulk-import/history/' + encodeURIComponent(bulkImportDetailState.bulkActionId) + '/error-report.csv';
      });
    }
    if (reimportBtn && !reimportBtn._detailBound) {
      reimportBtn._detailBound = true;
      reimportBtn.addEventListener('click', function () {
        if (!bulkImportDetailState.bulkActionId) return;
        window.location.href = '/ca-masters/bulk-import/history/' + encodeURIComponent(bulkImportDetailState.bulkActionId) + '/reimport-template.csv';
      });
    }
    if (reuploadBtn && !reuploadBtn._detailBound) {
      reuploadBtn._detailBound = true;
      reuploadBtn.addEventListener('click', function () {
        var modal = document.getElementById('modal-bulk-import-detail');
        closeBulkImportDetail();
        if (typeof window.openBulkImportWizard === 'function') window.openBulkImportWizard();
        if (window.CA_CRM && typeof window.CA_CRM.initBulkImportWizard === 'function') {
          var fileInput = document.getElementById('bulk-import-file');
          if (fileInput) fileInput.click();
        }
        toast('Upload your corrected file to run the import wizard again', 'info');
      });
    }
  }

  function initBulkImportWizard() {
    ensureBulkImportDetailCloseHandlers();
    ensureBulkImportDetailModalRoot();
    bindBulkImportDetailFooterActions();
    var wizard = document.getElementById('bulk-import-wizard');
    var zone = document.getElementById('bulk-upload-zone');
    var fileInput = document.getElementById('bulk-import-file');
    if (!wizard || !zone || !fileInput) return;
    if (window._bulkImportWizardElement === wizard) return;
    window._bulkImportWizardElement = wizard;

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
      for (var i = 1; i <= 5; i++) {
        var panel = document.getElementById('bulk-wizard-panel-' + i);
        if (panel) panel.classList.toggle('hidden', i !== step);
      }
      var backBtn = document.getElementById('bulk-wizard-back-btn');
      var nextBtn = document.getElementById('bulk-wizard-next-btn');
      var importBtn = document.getElementById('bulk-wizard-import-btn');
      if (backBtn) backBtn.disabled = step === 1 || step === 5;
      if (nextBtn) {
        nextBtn.classList.toggle('hidden', step === 4 || step === 5);
        nextBtn.disabled = (step === 1 && !bulkImportWizardState.sessionId) || step === 5;
        nextBtn.textContent = step === 2 ? 'Validate Rows' : (step === 3 ? 'Review Duplicates' : 'Next');
      }
      if (importBtn) importBtn.classList.toggle('hidden', step !== 4);
      if (step === 4 && !bulkImportWizardState.importInProgress) {
        document.getElementById('bulk-import-progress-wrap')?.classList.add('hidden');
      }
      icons();
    }

    function resetWizard() {
      if (bulkImportWizardState.pollTimer) {
        clearInterval(bulkImportWizardState.pollTimer);
      }
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
        rowActions: {},
        canForceActions: false,
        lastBulkActionId: null,
        pollTimer: null,
        pollInFlight: false,
        importInProgress: false,
        validationTimer: null,
      };
      fileInput.value = '';
      document.getElementById('bulk-upload-meta')?.classList.add('hidden');
      document.getElementById('bulk-mapping-table').innerHTML = '';
      document.getElementById('bulk-validation-table').innerHTML = '';
      document.getElementById('bulk-duplicate-actions-table').innerHTML = '';
      document.getElementById('bulk-import-summary').innerHTML = '';
      document.getElementById('bulk-validation-downloads')?.classList.add('hidden');
      document.getElementById('bulk-import-summary-downloads')?.classList.add('hidden');
      document.getElementById('bulk-import-progress-wrap')?.classList.add('hidden');
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
      var validationTable = document.getElementById('bulk-validation-table');
      var duplicateTable = document.getElementById('bulk-duplicate-actions-table');
      if (validationTable) {
        validationTable.innerHTML = '<tr><td colspan="10" class="text-center text-slate-500 py-8">Validation is running…</td></tr>';
      }
      if (duplicateTable) duplicateTable.innerHTML = '';
      setWizardStep(3);
      setValidationBusy(true);
      return new Promise(function (resolve) {
        requestAnimationFrame(function () { resolve(); });
      }).then(function () {
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
        });
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
          applyValidationData(data, { deferTables: true });
          toast('Validation complete — ' + (data.ready_to_import_rows || data.valid_rows || 0) + ' ready to import', data.invalid_rows > 0 || data.duplicate_rows > 0 ? 'warning' : 'success');
        })
        .catch(function (error) {
          setWizardStep(2);
          throw error;
        })
        .finally(function () {
          setValidationBusy(false);
        });
    }

    function setValidationBusy(busy) {
      var progress = document.getElementById('bulk-validation-progress');
      var bar = document.getElementById('bulk-validation-progress-bar');
      var label = document.getElementById('bulk-validation-progress-label');
      var steps = document.getElementById('bulk-validation-progress-steps');
      var nextBtn = document.getElementById('bulk-wizard-next-btn');
      var backBtn = document.getElementById('bulk-wizard-back-btn');

      if (bulkImportWizardState.validationTimer) {
        clearInterval(bulkImportWizardState.validationTimer);
        bulkImportWizardState.validationTimer = null;
      }

      if (!busy) {
        if (progress) progress.classList.add('hidden');
        if (nextBtn) {
          nextBtn.disabled = false;
          nextBtn.removeAttribute('aria-busy');
        }
        if (backBtn) backBtn.disabled = bulkImportWizardState.step === 1 || bulkImportWizardState.step === 5;
        document.querySelectorAll('.bulk-mapping-select').forEach(function (select) { select.disabled = false; });
        return;
      }

      if (progress) progress.classList.remove('hidden');
      if (bar) bar.style.width = '12%';
      if (label) label.textContent = 'Reading mapped rows…';
      if (steps) steps.textContent = '✓ Upload received · Reading file';
      if (nextBtn) {
        nextBtn.disabled = true;
        nextBtn.setAttribute('aria-busy', 'true');
      }
      if (backBtn) backBtn.disabled = true;
      document.querySelectorAll('.bulk-mapping-select').forEach(function (select) { select.disabled = true; });

      var phases = [
        { percent: 35, label: 'Validating rows…', steps: '✓ Upload received · ✓ Reading file · Validating rows' },
        { percent: 65, label: 'Detecting duplicates…', steps: '✓ Upload received · ✓ Reading file · ✓ Validating rows · Detecting duplicates' },
        { percent: 85, label: 'Preparing review…', steps: '✓ Upload received · ✓ Reading file · ✓ Validating rows · ✓ Detecting duplicates · Preparing review' },
      ];
      var phaseIndex = 0;
      bulkImportWizardState.validationTimer = setInterval(function () {
        var phase = phases[Math.min(phaseIndex, phases.length - 1)];
        if (bar) bar.style.width = phase.percent + '%';
        if (label) label.textContent = phase.label;
        if (steps) steps.textContent = phase.steps;
        phaseIndex++;
      }, 700);
    }

    function applyValidationData(data, options) {
      options = options || {};
      bulkImportWizardState.validation = data;
      bulkImportWizardState.hasMobileColumn = !!data.has_mobile_column;
      bulkImportWizardState.canForceActions = !!data.can_force_actions;
      if (data.crm_fields) bulkImportWizardState.crmFields = data.crm_fields;
      setText('bulk-total-count', String(data.total_rows || 0));
      setText('bulk-valid-count', String(data.valid_rows || 0));
      setText('bulk-invalid-count', String(data.invalid_rows || 0));
      setText('bulk-duplicate-count', String(data.duplicate_rows || 0));
      setText('bulk-missing-mobile-count', String(data.missing_mobile_rows || 0));
      setText('bulk-missing-email-count', String(data.missing_email_rows || 0));
      setText('bulk-landline-count', String(data.landline_rows || 0));
      setText('bulk-ready-count', String(data.ready_to_import_rows || 0));
      setText('bulk-confirm-ready-count', String(data.ready_to_import_rows || 0));
      setText('bulk-confirm-duplicate-count', String(data.duplicate_rows || 0));
      setText('bulk-confirm-missing-mobile-count', String(data.missing_mobile_rows || 0));
      setText('bulk-confirm-missing-email-count', String(data.missing_email_rows || 0));
      var dupNote = document.getElementById('bulk-duplicate-report-note');
      if (dupNote) {
        var dupTotal = data.duplicate_report_total || (data.duplicate_report || []).length || 0;
        var dupShown = (data.duplicate_report || []).length;
        if (dupTotal > dupShown) {
          dupNote.textContent = 'Showing first ' + dupShown + ' of ' + dupTotal + ' duplicate rows. Remaining duplicates will be skipped automatically during import.';
          dupNote.classList.remove('hidden');
        } else if (dupTotal > 0) {
          dupNote.textContent = dupTotal + ' duplicate row(s) found. These are skipped by default and are not caused by blank mobile or email.';
          dupNote.classList.remove('hidden');
        } else {
          dupNote.classList.add('hidden');
        }
      }
      var renderTables = function () {
        renderValidationPreview(data.preview_rows || []);
        renderDuplicateActionsTable(data.duplicate_report || []);
      };
      if (options.deferTables) requestAnimationFrame(renderTables);
      else renderTables();
      var errorCount = (data.error_row_count || 0);
      document.getElementById('bulk-validation-downloads')?.classList.toggle('hidden', errorCount === 0);
    }

    function statusBadgeHtml(status) {
      if (status === 'valid') return '<span class="badge-success">Valid</span>';
      if (status === 'duplicate') return '<span class="badge-brand">Duplicate</span>';
      if (status === 'landline') return '<span class="badge-brand">Landline</span>';
      if (status === 'missing_mobile') return '<span class="badge-brand">Missing Mobile</span>';
      return '<span class="badge-danger">Invalid</span>';
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
        var issues = (row.errors || []).join('; ') || '—';
        var fieldErrors = row.field_errors || {};
        var cells = ['ca_name', 'firm_name', 'mobile_no', 'email_id', 'gst_no', 'state_id', 'city_id'].map(function (key) {
          var cls = fieldErrors[key] ? 'text-rose-600 font-medium' : '';
          return '<td class="' + cls + '">' + escapeHtml(data[key] || '—') + '</td>';
        }).join('');
        var rowCls = (status === 'valid' || status === 'landline' || status === 'missing_mobile') ? '' : 'bg-rose-50/60';
        return '<tr class="' + rowCls + '">' +
          '<td>' + row.row_number + '</td><td>' + statusBadgeHtml(status) + '</td>' + cells +
          '<td class="max-w-xs truncate" title="' + escapeHtml(issues) + '">' + escapeHtml(issues) + '</td></tr>';
      }).join('');
    }

    function renderDuplicateActionsTable(rows) {
      var tbody = document.getElementById('bulk-duplicate-actions-table');
      var empty = document.getElementById('bulk-duplicate-actions-empty');
      if (!tbody) return;
      if (!rows.length) {
        tbody.innerHTML = '';
        if (empty) empty.classList.remove('hidden');
        return;
      }
      if (empty) empty.classList.add('hidden');
      var canForce = bulkImportWizardState.canForceActions;
      tbody.innerHTML = rows.map(function (row) {
        var rowNumber = row.row_number;
        var current = bulkImportWizardState.rowActions[rowNumber] || row.action || 'skip';
        var options = [
          { value: 'skip', label: 'Skip' },
          { value: 'import_anyway', label: 'Import Anyway', force: true },
          { value: 'merge', label: 'Merge With Existing', force: true },
          { value: 'replace', label: 'Replace Existing', force: true },
        ].filter(function (opt) { return !opt.force || canForce; });
        var select = '<select class="input-field bulk-dup-action" data-row="' + rowNumber + '">' +
          options.map(function (opt) {
            return '<option value="' + opt.value + '"' + (current === opt.value ? ' selected' : '') + '>' + opt.label + '</option>';
          }).join('') + '</select>';
        return '<tr>' +
          '<td>' + rowNumber + '</td>' +
          '<td>' + escapeHtml(row.ca_name || '—') + '</td>' +
          '<td>' + escapeHtml(row.firm_name || '—') + '</td>' +
          '<td>' + escapeHtml(row.mobile || '—') + '</td>' +
          '<td>' + escapeHtml(row.email || '—') + '</td>' +
          '<td><span class="badge-brand">' + escapeHtml(row.duplicate_type_label || row.duplicate_type || '—') + '</span></td>' +
          '<td class="max-w-xs truncate" title="' + escapeHtml(row.matched_lead_label || '') + '">' + escapeHtml(row.matched_lead_label || '—') + '</td>' +
          '<td>' + select + '</td></tr>';
      }).join('');
      tbody.querySelectorAll('.bulk-dup-action').forEach(function (sel) {
        sel.addEventListener('change', function () {
          bulkImportWizardState.rowActions[sel.dataset.row] = sel.value;
        });
      });
    }

    function collectRowActions() {
      var actions = Object.assign({}, bulkImportWizardState.rowActions || {});
      document.querySelectorAll('.bulk-dup-action').forEach(function (sel) {
        actions[sel.dataset.row] = sel.value;
      });
      bulkImportWizardState.rowActions = actions;
      return actions;
    }

    function persistRowActions() {
      var actions = collectRowActions();
      if (!Object.keys(actions).length) {
        return Promise.resolve(bulkImportWizardState.validation);
      }
      return fetch('/ca-masters/bulk-import/row-actions', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken(),
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          session_id: bulkImportWizardState.sessionId,
          row_actions: actions,
        }),
      }).then(function (response) {
        return response.json().then(function (body) {
          if (!response.ok) throw new Error(body.message || 'Unable to save actions');
          applyValidationData(body.data || {});
          return body.data || {};
        });
      });
    }

    function setImportProgress(percent, label) {
      var wrap = document.getElementById('bulk-import-progress-wrap');
      var bar = document.getElementById('bulk-import-progress-bar');
      var text = document.getElementById('bulk-import-progress-label');
      if (wrap) wrap.classList.remove('hidden');
      if (bar) bar.style.width = Math.max(0, Math.min(100, percent || 0)) + '%';
      if (text) text.textContent = label || ((percent || 0) + '%');
    }

    function stopImportPolling() {
      if (bulkImportWizardState.pollTimer) {
        clearInterval(bulkImportWizardState.pollTimer);
        bulkImportWizardState.pollTimer = null;
      }
      bulkImportWizardState.pollInFlight = false;
    }

    function formatImportProgressLabel(data) {
      if (data.progress_message) return data.progress_message;
      var processed = data.processed_rows || data.processed_records || 0;
      var total = data.total_rows || 0;
      if (processed > 0 && total > 0) {
        return 'Processing ' + processed + ' of ' + total + ' rows...';
      }
      return data.status === 'Processing' ? 'Processing rows...' : (data.status || 'Processing rows...');
    }

    function fetchImportStatusOnce(bulkActionId) {
      if (bulkImportWizardState.pollInFlight) {
        return Promise.resolve(null);
      }
      bulkImportWizardState.pollInFlight = true;
      return fetch('/ca-masters/bulk-import/history/' + encodeURIComponent(bulkActionId) + '/status', {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then(function (response) { return response.json(); })
        .then(function (body) {
          var data = body.data || {};
          setImportProgress(data.progress_percent || 0, formatImportProgressLabel(data));
          return data;
        })
        .finally(function () {
          bulkImportWizardState.pollInFlight = false;
        });
    }

    function pollImportStatus(bulkActionId) {
      stopImportPolling();
      setImportProgress(0, 'Processing rows...');
      return new Promise(function (resolve, reject) {
        var poll = function () {
          fetchImportStatusOnce(bulkActionId)
            .then(function (data) {
              if (!data) return;
              if (data.completed) {
                stopImportPolling();
                resolve(data);
              }
            })
            .catch(function (error) {
              stopImportPolling();
              reject(error);
            });
        };
        poll();
        bulkImportWizardState.pollTimer = setInterval(poll, 1500);
      });
    }

    function runImport() {
      var mapping = collectMappingFromForm();
      var templateName = document.getElementById('bulk-mapping-template-name')?.value.trim() || '';
      var saveTemplate = templateName.length > 0;
      var rowActions = collectRowActions();
      var importBtn = document.getElementById('bulk-wizard-import-btn');
      var backBtn = document.getElementById('bulk-wizard-back-btn');
      var cancelBtn = document.getElementById('bulk-wizard-cancel-btn');
      var setImportBusy = function (busy) {
        [importBtn, backBtn, cancelBtn].forEach(function (btn) {
          if (!btn) return;
          btn.disabled = !!busy;
          btn.setAttribute('aria-busy', busy ? 'true' : 'false');
        });
        if (importBtn) importBtn.classList.toggle('is-loading', !!busy);
      };
      bulkImportWizardState.importInProgress = true;
      setImportBusy(true);
      setImportProgress(0, 'Preparing import...');
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
          row_actions: rowActions,
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
          if (summary.bulk_action_id && (summary.uses_background || summary.status === 'Processing')) {
            return pollImportStatus(summary.bulk_action_id).then(function (statusData) {
              summary = Object.assign({}, summary, statusData, {
                inserted_rows: statusData.inserted_rows,
                duplicate_rows: statusData.duplicate_rows,
                failed_rows: statusData.failed_rows,
                skipped_rows: statusData.skipped_rows,
                progress_percent: statusData.progress_percent,
                status: statusData.status,
              });
              return { body: result.body, summary: summary };
            });
          }
          if (summary.queue_notice) {
            toast(summary.queue_notice, 'warning');
          }
          setImportProgress(100, formatImportProgressLabel(summary) || 'Import completed');
          return result;
        })
        .then(function (result) {
          var summary = result.summary;
          renderImportSummaryPanel(summary);
          renderBulkImportSummary(summary, false);
          loadBulkImportHistory();
          realLeadsLoaded = false;
          invalidateDataCaches(['metrics', 'segment_counts', 'leads', 'ca_masters']);
          refreshAll();
          var hasErrors = (summary.error_row_count || 0) > 0 || (summary.failed_rows || 0) > 0 || (summary.duplicate_rows || 0) > 0;
          document.getElementById('bulk-import-summary-downloads')?.classList.toggle('hidden', !hasErrors);
          setWizardStep(5);
          toast(result.body.message || 'Import completed', summary.failed_rows > 0 ? 'warning' : 'success');
        })
        .catch(function (error) {
          toast(error.message || 'Bulk import failed', 'error');
        })
        .finally(function () {
          bulkImportWizardState.importInProgress = false;
          stopImportPolling();
          setImportBusy(false);
        });
    }

    function renderImportSummaryPanel(summary) {
      var el = document.getElementById('bulk-import-summary');
      if (!el) return;
      var cards = [
        { label: 'Imported Successfully', value: summary.inserted_rows || 0, cls: 'text-emerald-600' },
        { label: 'Skipped Duplicates', value: summary.duplicate_rows || 0, cls: 'text-amber-600' },
        { label: 'Invalid Rows', value: summary.invalid_rows || summary.failed_rows || 0, cls: 'text-rose-600' },
        { label: 'Landline Rows', value: summary.landline_rows || 0, cls: 'text-sky-700' },
        { label: 'Failed Rows', value: summary.failed_rows || 0, cls: 'text-rose-600' },
        { label: 'Total Rows', value: summary.total_rows || 0, cls: 'text-slate-900' },
      ];
      el.innerHTML = cards.map(function (card) {
        return '<div class="card p-5"><p class="text-caption text-slate-500">' + card.label + '</p>' +
          '<p class="text-2xl font-semibold ' + card.cls + '">' + card.value + '</p></div>';
      }).join('') +
        '<div class="card p-5 sm:col-span-2 lg:col-span-3"><p class="text-caption text-slate-500">Import Reference</p>' +
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
      if (bulkImportWizardState.step > 1 && bulkImportWizardState.step < 5) {
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
        return;
      }
      if (bulkImportWizardState.step === 3) {
        persistRowActions()
          .then(function () { setWizardStep(4); })
          .catch(function (error) {
            toast(error.message || 'Unable to prepare confirm step', 'error');
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

    setWizardStep(1);
  }

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

  function canDeleteBulkImportHistory() {
    if (window.CA_RBAC && typeof CA_RBAC.can === 'function') {
      return CA_RBAC.can('bulk', 'delete') || CA_RBAC.can('ca_master', 'delete');
    }
    return false;
  }

  function bulkHistoryViewButton(item) {
    return iconBtn('eye', 'View Details', 'data-bulk-history-view="' + item.bulk_action_id + '" data-action-type="' + (item.action_type || '') + '"', 'secondary');
  }

  function bulkHistoryDeleteButton(item) {
    if (item.action_type !== 'ca_master_import' || !canDeleteBulkImportHistory()) return '';
    return iconBtn('trash-2', 'Delete', 'data-bulk-history-delete="' + item.bulk_action_id + '"', 'danger');
  }

  function openBulkOperationDetailByType(bulkActionId, actionType) {
    if (actionType === 'ca_master_export') openBulkExportDetail(bulkActionId);
    else if (actionType === 'ca_master_status_update') openBulkStatusUpdateDetail(bulkActionId);
    else openBulkImportDetail(bulkActionId);
  }

  function renderBulkImportDetailSkeleton() {
    return '<div class="bulk-import-detail-skeleton" aria-busy="true" aria-live="polite">' +
      '<div class="bulk-import-detail-skeleton__grid">' +
      Array(6).fill('<div class="bulk-import-detail-skeleton__card"></div>').join('') +
      '</div>' +
      '<p class="bulk-import-detail-skeleton__text"><span class="bulk-import-detail-skeleton__spinner" aria-hidden="true"></span> Loading import details…</p>' +
    '</div>';
  }

  function setBulkImportDetailLoading(loading) {
    bulkImportDetailState.loading = !!loading;
    var modal = document.getElementById('modal-bulk-import-detail');
    if (modal) {
      modal.classList.toggle('bulk-import-detail--loading', !!loading);
      modal.setAttribute('aria-busy', loading ? 'true' : 'false');
    }
    document.querySelectorAll('[data-bulk-history-view]').forEach(function (btn) {
      var btnId = btn.getAttribute('data-bulk-history-view');
      var isActive = bulkImportDetailState.bulkActionId && String(btnId) === String(bulkImportDetailState.bulkActionId);
      btn.disabled = !!loading && isActive;
    });
  }

  function setBulkImportDetailFooterState(detail) {
    var errorBtn = document.getElementById('bulk-detail-error-report-btn');
    var reimportBtn = document.getElementById('bulk-detail-reimport-btn');
    var reuploadBtn = document.getElementById('bulk-detail-reupload-btn');
    if (errorBtn) { errorBtn.classList.remove('hidden'); errorBtn.disabled = !(detail && detail.error_row_count > 0); }
    if (reimportBtn) { reimportBtn.classList.remove('hidden'); reimportBtn.disabled = !(detail && detail.failed_rows > 0); }
    if (reuploadBtn) {
      reuploadBtn.classList.remove('hidden');
      reuploadBtn.onclick = null;
      reuploadBtn.innerHTML = '<i data-lucide="upload" class="h-4 w-4"></i> Re-upload Corrected File';
    }
  }

  function hideBulkImportDetailFooterActions() {
    ['bulk-detail-error-report-btn', 'bulk-detail-reimport-btn', 'bulk-detail-reupload-btn'].forEach(function (id) {
      var btn = document.getElementById(id);
      if (btn) {
        btn.classList.add('hidden');
        btn.disabled = true;
      }
    });
  }

  function confirmDeleteBulkImportHistory(bulkActionId) {
    if (!bulkActionId || !canDeleteBulkImportHistory()) {
      toast('You do not have permission to delete import history.', 'warning');
      return;
    }
    if (!window.confirm('Delete this bulk import history record? This cannot be undone.')) return;
    window._bulkOperationsHistoryCache = (window._bulkOperationsHistoryCache || []).filter(function (item) {
      return String(item.bulk_action_id) !== String(bulkActionId);
    });
    renderBulkOperationsHistoryTable(window._bulkOperationsHistoryCache);
    toast('Import history record deleted.', 'success');
  }

  function bindBulkHistoryTableActions(el) {
    if (!el || el._bulkHistoryActionsBound) return;
    el._bulkHistoryActionsBound = true;
    el.addEventListener('click', function (e) {
      var viewBtn = e.target.closest('[data-bulk-history-view]');
      if (viewBtn) {
        e.preventDefault();
        e.stopPropagation();
        var viewId = viewBtn.getAttribute('data-bulk-history-view');
        if (bulkImportDetailState.loading && String(bulkImportDetailState.bulkActionId) === String(viewId)) return;
        openBulkOperationDetailByType(
          viewId,
          viewBtn.getAttribute('data-action-type')
        );
        return;
      }
      var deleteBtn = e.target.closest('[data-bulk-history-delete]');
      if (deleteBtn) {
        e.preventDefault();
        e.stopPropagation();
        confirmDeleteBulkImportHistory(deleteBtn.getAttribute('data-bulk-history-delete'));
      }
    });
  }

  function renderBulkOperationsHistoryTable(items) {
    var el = document.getElementById('bulk-actions-data-table');
    if (!el) return;
    if (!items.length) {
      el.innerHTML = '<tr><td colspan="11" class="text-center text-slate-400 py-8">No bulk operations yet.</td></tr>';
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
      return '<tr class="ca-table-row ' + rowClass + ' hover:bg-slate-50" data-bulk-action-id="' + item.bulk_action_id + '" data-action-type="' + item.action_type + '">' +
        '<td class="bulk-history-cell bulk-history-cell--view">' + bulkHistoryViewButton(item) + '</td>' +
        '<td>' + item.bulk_action_id + '</td>' +
        '<td>' + bulkOperationLabel(item) + '</td>' +
        '<td>' + escapeHtml(item.file_name || '—') + '</td>' +
        '<td>' + (item.total_rows || 0) + '</td>' +
        '<td>' + bulkOperationSuccess(item) + '</td>' +
        '<td>' + failed + '</td>' +
        '<td><span class="badge ' + statusClass + '">' + escapeHtml(item.status || 'Completed') + '</span></td>' +
        '<td>' + escapeHtml(performer) + '</td>' +
        '<td class="whitespace-nowrap">' + formatBulkImportDate(item.created_at) + '</td>' +
        '<td class="bulk-history-cell bulk-history-cell--delete">' + bulkHistoryDeleteButton(item) + '</td>' +
      '</tr>';
    }).join('');
    bindBulkHistoryTableActions(el);
    if (!el._bulkHistoryClickBound) {
      el._bulkHistoryClickBound = true;
      el.addEventListener('click', function (e) {
        if (e.target.closest('[data-bulk-history-view], [data-bulk-history-delete], button, a')) return;
        var row = e.target.closest('[data-bulk-action-id]');
        if (!row) return;
        if (bulkImportDetailState.loading && String(bulkImportDetailState.bulkActionId) === String(row.dataset.bulkActionId)) return;
        openBulkOperationDetailByType(row.dataset.bulkActionId, row.dataset.actionType);
      });
    }
    iconsIn(el.closest('#bulk-actions-table') || el);
  }

  function renderBulkImportHistoryTable(items) {
    renderBulkOperationsHistoryTable(items);
  }

  function openBulkStatusUpdateDetail(bulkActionId) {
    if (!bulkActionId) return;
    ensureBulkImportDetailCloseHandlers();
    var modal = ensureBulkImportDetailModalRoot();
    var content = document.getElementById('bulk-import-detail-body');
    var title = document.getElementById('bulk-import-detail-title');
    if (!modal || !content) return;
    bulkImportDetailState.isOpen = true;
    bulkImportDetailState.bulkActionId = bulkActionId;
    bulkImportDetailState.loading = false;
    bulkImportDetailState.details = null;
    bulkImportDetailState.error = null;
    if (title) title.innerHTML = '<span class="ca-modal-icon"><i data-lucide="refresh-cw" class="h-5 w-5"></i></span> Status Update Details ';
    var items = window._bulkOperationsHistoryCache || [];
    var detail = items.find(function (item) { return String(item.bulk_action_id) === String(bulkActionId); });
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
    openExclusiveCrmModal(modal);
    iconsIn(modal);
  }

  function openBulkExportDetail(bulkActionId) {
    if (!bulkActionId) return;
    ensureBulkImportDetailCloseHandlers();
    var modal = ensureBulkImportDetailModalRoot();
    var content = document.getElementById('bulk-import-detail-body');
    var title = document.getElementById('bulk-import-detail-title');
    if (!modal || !content) return;

    bulkImportDetailState.isOpen = true;
    bulkImportDetailState.bulkActionId = bulkActionId;
    bulkImportDetailState.details = null;
    bulkImportDetailState.error = null;
    setBulkImportDetailLoading(true);
    if (title) title.innerHTML = '<span class="ca-modal-icon"><i data-lucide="download" class="h-5 w-5"></i></span> Export Details ';
    content.innerHTML = renderBulkImportDetailSkeleton();
    hideBulkImportDetailFooterActions();
    openExclusiveCrmModal(modal);
    iconsIn(modal);

    apiFetch('/ca-masters/bulk-export/history/' + encodeURIComponent(bulkActionId))
      .then(function (body) {
        var detail = body.data || {};
        if (!modal || !content || String(bulkImportDetailState.bulkActionId) !== String(bulkActionId)) return;
        bulkImportDetailState.details = detail;
        bulkImportDetailState.error = null;
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
          '<p class="text-caption text-slate-500">Download the export file when status is Completed.</p>';
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
        iconsIn(modal);
      })
      .catch(function (error) {
        if (String(bulkImportDetailState.bulkActionId) !== String(bulkActionId)) return;
        bulkImportDetailState.error = error.message || 'Failed to load export details';
        if (content) {
          content.innerHTML = '<p class="text-body text-rose-600">' + escapeHtml(bulkImportDetailState.error) + '</p>';
        }
        toast(bulkImportDetailState.error, 'error');
      })
      .finally(function () {
        if (String(bulkImportDetailState.bulkActionId) === String(bulkActionId)) {
          setBulkImportDetailLoading(false);
        }
      });
  }

  function openBulkImportDetail(bulkActionId) {
    if (!bulkActionId) return;
    if (bulkImportDetailState.loading && String(bulkImportDetailState.bulkActionId) === String(bulkActionId)) return;

    ensureBulkImportDetailCloseHandlers();
    bindBulkImportDetailFooterActions();
    var modal = ensureBulkImportDetailModalRoot();
    var content = document.getElementById('bulk-import-detail-body');
    var title = document.getElementById('bulk-import-detail-title');
    if (!modal || !content) return;

    if (bulkImportDetailState.abortController) {
      bulkImportDetailState.abortController.abort();
    }
    var requestSeq = ++bulkImportDetailState.requestSeq;
    var abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
    bulkImportDetailState.abortController = abortController;

    bulkImportDetailState.isOpen = true;
    bulkImportDetailState.bulkActionId = bulkActionId;
    bulkImportDetailState.details = null;
    bulkImportDetailState.error = null;
    setBulkImportDetailLoading(true);
    if (title) title.innerHTML = '<span class="ca-modal-icon"><i data-lucide="file-text" class="h-5 w-5"></i></span> Import Details ';
    content.innerHTML = renderBulkImportDetailSkeleton();
    hideBulkImportDetailFooterActions();
    openExclusiveCrmModal(modal);
    iconsIn(modal);

    apiFetch('/ca-masters/bulk-import/history/' + encodeURIComponent(bulkActionId), abortController ? { signal: abortController.signal } : {})
      .then(function (body) {
        if (requestSeq !== bulkImportDetailState.requestSeq) return;
        var detail = body.data || {};
        if (!modal || !content || String(bulkImportDetailState.bulkActionId) !== String(bulkActionId)) return;
        bulkImportDetailState.details = detail;
        bulkImportDetailState.error = null;
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
        setBulkImportDetailFooterState(detail);
        iconsIn(modal);
      })
      .catch(function (error) {
        if (requestSeq !== bulkImportDetailState.requestSeq) return;
        if (error && error.name === 'AbortError') return;
        if (String(bulkImportDetailState.bulkActionId) !== String(bulkActionId)) return;
        bulkImportDetailState.error = error.message || 'Failed to load import details';
        if (content) {
          content.innerHTML = '<p class="text-body text-rose-600">' + escapeHtml(bulkImportDetailState.error) + '</p>';
        }
        toast(bulkImportDetailState.error, 'error');
      })
      .finally(function () {
        if (requestSeq !== bulkImportDetailState.requestSeq) return;
        if (bulkImportDetailState.abortController === abortController) {
          bulkImportDetailState.abortController = null;
        }
        if (String(bulkImportDetailState.bulkActionId) === String(bulkActionId)) {
          setBulkImportDetailLoading(false);
        }
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

  function defaultReportsDateRange() {
    var end = new Date();
    var start = new Date();
    start.setDate(start.getDate() - 30);
    return {
      from: start.toISOString().slice(0, 10),
      to: end.toISOString().slice(0, 10),
    };
  }

  function getReportsFilterQuery() {
    var from = document.getElementById('reports-filter-from');
    var to = document.getElementById('reports-filter-to');
    var range = defaultReportsDateRange();
    var fromVal = (from && from.value) || range.from;
    var toVal = (to && to.value) || range.to;
    return '?from=' + encodeURIComponent(fromVal) + '&to=' + encodeURIComponent(toVal);
  }

  function initReportsFilters() {
    /* Hub date bar removed — default range applied in getReportsFilterQuery(). */
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
    el.dataset.chartReady = '1';
    if (!series || !series.length) {
      el.innerHTML = '<p class="dash-chart-empty">No data for selected range</p>';
      return;
    }
    var max = Math.max.apply(null, series.map(function (p) { return p.value; }).concat([1]));
    el.innerHTML = '<div class="dash-column-chart__inner">' +
      series.map(function (point, i) {
        var h = Math.max(8, Math.round((point.value / max) * 100));
        var shortLabel = String(point.label || '').length > 6
          ? String(point.label).slice(0, 5) + '…'
          : (point.label || '—');
        return '<div class="dash-column-chart__col" title="' + escapeHtml(String(point.label)) + ': ' + point.value + '">' +
          '<span class="dash-column-chart__value">' + point.value + '</span>' +
          '<div class="ca-chart-bar dash-column-chart__bar" style="height:' + h + '%;transition-delay:' + (i * 40) + 'ms"></div>' +
          '<span class="dash-column-chart__label">' + escapeHtml(shortLabel) + '</span>' +
        '</div>';
      }).join('') + '</div>';
  }

  function renderReportCharts() {
    if (!document.querySelector('[data-chart-key]')) return;
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
    if (window.CrmReportAnalytics && typeof window.CrmReportAnalytics.open === 'function') {
      window.CrmReportAnalytics.open(slug);
      return;
    }
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
        toast((report.label || 'Report') + ' · ' + (report.rows || []).length + ' rows', 'success');
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
          toast((label || 'Report') + ' ready', 'success');
          return;
        }
        var payload = (result.body && result.body.data) || {};
        if (!payload.export_id) {
          throw new Error('Unexpected export response.');
        }
        toast('Large export queued — running in background…', 'info');
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
    if (status === 'integrated') return { label: 'Integrated', badge: 'badge-success' };
    if (status === 'connected') return { label: 'Connected', badge: 'badge-brand' };
    if (status === 'failed') return { label: 'Failed', badge: 'badge-danger' };
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
    ['sms-settings-save-btn', 'sms-settings-test-btn', 'sms-settings-test-connection-btn', 'sms-settings-reset-btn'].forEach(function (id) {
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

  function testSmsConnection() {
    var mobileEl = document.getElementById('sms-settings-test-mobile');
    var messageEl = document.getElementById('sms-settings-test-message');
    var mobileno = mobileEl ? mobileEl.value.trim() : '';
    var text = messageEl ? messageEl.value.trim() : '';
    if (!mobileno) {
      showSmsSettingsError({ message: 'Test mobile number is required.' });
      return;
    }
    if (!text) {
      showSmsSettingsError({ message: 'Test message is required.' });
      return;
    }
    showSmsSettingsError(null);
    setSmsSettingsBusy(true);
    apiFetch('/sms-settings/test-connection', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ mobileno: mobileno, text: text }),
    }).then(function (body) {
      var result = body.data || {};
      if (result.settings) populateSmsSettingsForm(result.settings);
      if (result.success) {
        showSmsSettingsError(null);
        toast(result.message || 'SMS Alert connection test succeeded', 'success');
      } else {
        showSmsSettingsError({ message: result.message || 'SMS Alert connection test failed' });
        toast(result.message || 'SMS Alert connection test failed', 'error');
      }
    }).catch(function (err) {
      showSmsSettingsError(err);
      toast(err.message || 'SMS connection test failed', 'error');
    }).finally(function () {
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
      if (e.target.closest('#sms-settings-test-connection-btn')) {
        e.preventDefault();
        testSmsConnection();
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
    setVal('sms-settings-dlt-template-id', sms.dlt_template_id || '');
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
    var lastTestNote = document.getElementById('sms-settings-last-test-note');
    if (lastTestNote) {
      if (sms.last_tested_at) {
        var statusLabel = sms.last_test_status === 'success' ? 'Last test succeeded' : 'Last test failed';
        var detail = sms.last_test_message ? ' — ' + sms.last_test_message : '';
        lastTestNote.textContent = statusLabel + ' at ' + sms.last_tested_at + detail;
        lastTestNote.classList.remove('hidden');
        lastTestNote.classList.toggle('text-red-600', sms.last_test_status === 'failed');
        lastTestNote.classList.toggle('text-slate-400', sms.last_test_status !== 'failed');
      } else {
        lastTestNote.textContent = '';
        lastTestNote.classList.add('hidden');
      }
    }
    updateSmsIntegrationStatusBadge(sms);
    applySmsSettingsReadOnly(!sms.can_edit);
  }

  function applySmsSettingsReadOnly(readOnly) {
    ['sms-settings-provider-name', 'sms-settings-api-url', 'sms-settings-api-key', 'sms-settings-sender-id', 'sms-settings-dlt-template-id', 'sms-settings-mode', 'sms-settings-is-active', 'sms-settings-test-mobile', 'sms-settings-test-message'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.disabled = readOnly;
    });
    ['sms-settings-save-btn', 'sms-settings-test-btn', 'sms-settings-test-connection-btn', 'sms-settings-reset-btn'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.classList.toggle('hidden', readOnly);
    });
  }

  function buildSmsSettingsPayload() {
    var payload = {
      provider_name: (document.getElementById('sms-settings-provider-name') || {}).value || '',
      api_url: (document.getElementById('sms-settings-api-url') || {}).value || '',
      sender_id: (document.getElementById('sms-settings-sender-id') || {}).value || '',
      dlt_template_id: (document.getElementById('sms-settings-dlt-template-id') || {}).value || '',
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

  function whatsappIntegrationStatusMeta(wa) {
    wa = wa || {};
    var status = wa.integration_status || 'not_configured';
    if (!wa.integration_status) {
      if (wa.is_active === false) status = 'disabled';
      else if (wa.has_access_token && wa.phone_number_id && wa.business_account_id) status = 'connected';
      else status = 'not_configured';
    }
    if (status === 'integrated' || status === 'connected' || wa.connection_connected) {
      return { label: wa.connection_status || 'Connected', badge: 'badge-success' };
    }
    if (status === 'failed' || status === 'disabled' || status === 'not_configured') {
      return { label: wa.connection_status || 'Disconnected', badge: 'badge-danger' };
    }
    return { label: 'Disconnected', badge: 'badge-danger' };
  }

  function whatsappStatusBadgeHtml(label, ok) {
    return '<span class="' + (ok ? 'badge-success' : 'badge-danger') + '">' + escapeHtml(label) + '</span>';
  }

  function renderWhatsAppConnectionDashboard(wa) {
    wa = wa || {};
    var setDash = function (id, html) {
      var el = document.getElementById(id);
      if (el) el.innerHTML = html;
    };
    var connected = !!wa.connection_connected || wa.integration_status === 'integrated' || wa.integration_status === 'connected';
    setDash('whatsapp-dash-connection', whatsappStatusBadgeHtml(wa.connection_status || (connected ? 'Connected' : 'Disconnected'), connected));
    setDash('whatsapp-dash-webhook', whatsappStatusBadgeHtml(wa.webhook_status === 'configured' ? 'Configured' : 'Not Configured', wa.webhook_status === 'configured'));
    setDash('whatsapp-dash-api', whatsappStatusBadgeHtml(wa.api_status === 'configured' ? 'Configured' : 'Not Configured', wa.api_status === 'configured'));
    var tokenOk = wa.token_status === 'configured';
    var tokenLabel = wa.token_status === 'invalid' ? 'Invalid' : (wa.token_status === 'configured' ? 'Configured' : 'Missing');
    setDash('whatsapp-dash-token', whatsappStatusBadgeHtml(tokenLabel, tokenOk));
    setDash('whatsapp-dash-templates', escapeHtml(String(wa.approved_templates_count != null ? wa.approved_templates_count : '—')));
    setDash('whatsapp-dash-last-sync', escapeHtml(wa.last_sync_at ? formatActivityTimestamp(wa.last_sync_at) : '—'));
    setDash('whatsapp-dash-callback', escapeHtml(wa.callback_url || '—'));
  }

  function updateWhatsAppIntegrationStatusBadge(wa) {
    var badge = document.getElementById('whatsapp-integration-status-badge');
    if (!badge) return;
    var meta = whatsappIntegrationStatusMeta(wa);
    badge.textContent = meta.label;
    badge.className = meta.badge;
  }

  function showWhatsAppSettingsError(err) {
    var box = document.getElementById('whatsapp-settings-error-box');
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

  function setWhatsAppSettingsBusy(busy) {
    ['whatsapp-settings-save-btn', 'whatsapp-settings-validate-btn', 'whatsapp-settings-reset-btn', 'whatsapp-settings-test-connection-btn', 'whatsapp-settings-send-test-template-btn'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.disabled = !!busy;
    });
  }

  function openWhatsAppIntegrationPanel() {
    var panel = document.getElementById('whatsapp-settings-panel');
    if (panel) {
      panel.classList.remove('hidden');
      document.body.classList.add('whatsapp-settings-open');
      var fabWrap = document.getElementById('fab-wrap');
      if (fabWrap) fabWrap.classList.add('hidden');
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    apiFetch('/whatsapp-settings').then(function (body) {
      populateWhatsAppSettingsForm(body.data || {});
      loadWhatsAppTemplatesFromDatabase(function () {
        populateWhatsAppTemplateSelect();
      });
    }).catch(function () {});
    if (typeof icons === 'function') icons();
  }

  function closeWhatsAppIntegrationPanel() {
    var panel = document.getElementById('whatsapp-settings-panel');
    if (panel) panel.classList.add('hidden');
    document.body.classList.remove('whatsapp-settings-open');
    var fabWrap = document.getElementById('fab-wrap');
    if (fabWrap) fabWrap.classList.remove('hidden');
  }

  function saveWhatsAppSettings() {
    showWhatsAppSettingsError(null);
    setWhatsAppSettingsBusy(true);
    apiFetch('/whatsapp-settings', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(collectWhatsAppSettingsPayload()),
    }).then(function (body) {
      populateWhatsAppSettingsForm(body.data || {});
      var token = document.getElementById('whatsapp-settings-access-token');
      if (token) token.value = '';
      toast('WhatsApp settings saved', 'success');
    }).catch(function (err) {
      showWhatsAppSettingsError(err);
      toast(err.message || 'Unable to save WhatsApp settings', 'error');
    }).finally(function () {
      setWhatsAppSettingsBusy(false);
    });
  }

  function validateWhatsAppSettings() {
    showWhatsAppSettingsError(null);
    setWhatsAppSettingsBusy(true);
    apiFetch('/whatsapp-settings/validate', { method: 'POST' })
      .then(function (body) {
        var result = body.data || {};
        if (result.valid) {
          showWhatsAppSettingsError(null);
          toast('WhatsApp mapping configuration is valid (no API call made)', 'success');
        } else {
          showWhatsAppSettingsError({ message: (result.errors || []).join(' ') });
          toast((result.errors || []).join(' ') || 'WhatsApp validation failed', 'error');
        }
        if (result.settings) populateWhatsAppSettingsForm(result.settings);
      })
      .catch(function (err) {
        showWhatsAppSettingsError(err);
        toast(err.message || 'Validation failed', 'error');
      })
      .finally(function () {
        setWhatsAppSettingsBusy(false);
      });
  }

  function resetWhatsAppSettings() {
    if (!window.confirm('Reset WhatsApp settings to defaults? Credentials will be cleared.')) return;
    showWhatsAppSettingsError(null);
    setWhatsAppSettingsBusy(true);
    apiFetch('/whatsapp-settings/reset', { method: 'POST' })
      .then(function (body) {
        populateWhatsAppSettingsForm(body.data || {});
        toast('WhatsApp settings reset', 'success');
      })
      .catch(function (err) {
        showWhatsAppSettingsError(err);
        toast(err.message || 'Unable to reset WhatsApp settings', 'error');
      })
      .finally(function () {
        setWhatsAppSettingsBusy(false);
      });
  }

  function populateWhatsAppSettingsForm(wa) {
    wa = wa || {};
    var setVal = function (id, val) {
      var el = document.getElementById(id);
      if (el && val != null) el.value = val;
    };
    var setCheck = function (id, val) {
      var el = document.getElementById(id);
      if (el) el.checked = !!val;
    };
    setVal('whatsapp-settings-provider-name', wa.provider_name || 'Meta WhatsApp Cloud API');
    setVal('whatsapp-settings-phone-number-id', wa.phone_number_id || '');
    setVal('whatsapp-settings-business-account-id', wa.business_account_id || '');
    setVal('whatsapp-settings-api-version', wa.api_version || 'v23.0');
    setVal('whatsapp-settings-mode', wa.mode || 'simulation');
    setVal('whatsapp-settings-test-mobile', wa.test_mobile_number || '');
    setCheck('whatsapp-settings-is-active', wa.is_active !== false);
    var token = document.getElementById('whatsapp-settings-access-token');
    if (token) token.value = '';
    var webhookToken = document.getElementById('whatsapp-settings-webhook-verify-token');
    if (webhookToken) webhookToken.value = '';
    var tokenNote = document.getElementById('whatsapp-settings-token-note');
    if (tokenNote) {
      tokenNote.textContent = wa.has_access_token
        ? 'Access token is configured (encrypted). Leave blank to keep current token.'
        : 'Access token is encrypted at rest and never returned by the API.';
    }
    var webhookNote = document.getElementById('whatsapp-settings-webhook-note');
    if (webhookNote) {
      webhookNote.textContent = wa.has_webhook_verify_token
        ? 'Webhook verify token is configured. Leave blank to keep current value.'
        : 'Optional — used when Meta verifies your webhook subscription.';
    }
    var statusSummary = document.getElementById('whatsapp-settings-status-summary');
    if (statusSummary) {
      var parts = [];
      if (wa.phone_number_id) parts.push('Phone Number ID: ' + wa.phone_number_id);
      if (wa.business_account_id) parts.push('Business Account ID: ' + wa.business_account_id);
      parts.push('Token: ' + (wa.has_access_token ? 'Configured' : 'Missing'));
      if (wa.last_tested_at) parts.push('Last test: ' + wa.last_test_status + ' (' + wa.last_tested_at + ')');
      if (wa.last_successful_send_at) parts.push('Last send: ' + wa.last_successful_send_at);
      statusSummary.textContent = parts.join(' · ');
    }
    var lastTestNote = document.getElementById('whatsapp-settings-last-test-note');
    if (lastTestNote) {
      if (wa.last_tested_at) {
        var statusLabel = wa.last_test_status === 'success' ? 'Last test succeeded' : 'Last test failed';
        var detail = wa.last_test_message ? ' — ' + wa.last_test_message : '';
        lastTestNote.textContent = statusLabel + ' at ' + wa.last_tested_at + detail;
        lastTestNote.classList.remove('hidden');
        lastTestNote.classList.toggle('text-red-600', wa.last_test_status === 'failed');
        lastTestNote.classList.toggle('text-slate-400', wa.last_test_status !== 'failed');
      } else {
        lastTestNote.textContent = '';
        lastTestNote.classList.add('hidden');
      }
    }
    var badge = document.getElementById('whatsapp-settings-mode-badge');
    if (badge) badge.textContent = (wa.mode || 'simulation') === 'live' ? 'Live' : 'Simulation';
    var canEdit = wa.can_edit !== false;
    ['whatsapp-settings-save-btn', 'whatsapp-settings-validate-btn', 'whatsapp-settings-reset-btn', 'whatsapp-settings-test-connection-btn', 'whatsapp-settings-send-test-template-btn'].forEach(function (id) {
      var btn = document.getElementById(id);
      if (btn) btn.disabled = !canEdit;
    });
    updateWhatsAppIntegrationStatusBadge(wa);
    renderWhatsAppConnectionDashboard(wa);
  }

  function collectWhatsAppSettingsPayload() {
    var payload = {
      provider_name: (document.getElementById('whatsapp-settings-provider-name') || {}).value || '',
      phone_number_id: (document.getElementById('whatsapp-settings-phone-number-id') || {}).value || '',
      business_account_id: (document.getElementById('whatsapp-settings-business-account-id') || {}).value || '',
      api_version: (document.getElementById('whatsapp-settings-api-version') || {}).value || 'v23.0',
      mode: (document.getElementById('whatsapp-settings-mode') || {}).value || 'simulation',
      is_active: !!(document.getElementById('whatsapp-settings-is-active') || {}).checked,
      test_mobile_number: (document.getElementById('whatsapp-settings-test-mobile') || {}).value || '',
    };
    var tokenVal = (document.getElementById('whatsapp-settings-access-token') || {}).value || '';
    if (tokenVal) payload.access_token = tokenVal;
    var webhookVal = (document.getElementById('whatsapp-settings-webhook-verify-token') || {}).value || '';
    if (webhookVal) payload.webhook_verify_token = webhookVal;
    return payload;
  }

  function testWhatsAppConnection() {
    showWhatsAppSettingsError(null);
    setWhatsAppSettingsBusy(true);
    apiFetch('/whatsapp-settings/test-connection', { method: 'POST' })
      .then(function (body) {
        var result = body.data || {};
        populateWhatsAppSettingsForm(result.settings || {});
        updateWhatsAppIntegrationStatusBadge(result.settings || {});
        toast(result.message || body.message || 'WhatsApp connection test completed', result.success ? 'success' : 'error');
      })
      .catch(function (error) {
        showWhatsAppSettingsError(error);
        toast(error.message || 'WhatsApp connection test failed', 'error');
      })
      .finally(function () { setWhatsAppSettingsBusy(false); });
  }

  function sendWhatsAppTestTemplate() {
    showWhatsAppSettingsError(null);
    var templateId = (document.getElementById('whatsapp-settings-test-template-id') || {}).value || '';
    var mobile = (document.getElementById('whatsapp-settings-test-mobile') || {}).value || '';
    if (!templateId) {
      toast('Select an approved template for the test send', 'error');
      return;
    }
    setWhatsAppSettingsBusy(true);
    apiFetch('/whatsapp-settings/send-test-template', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message_template_id: parseInt(templateId, 10), mobile_no: mobile || undefined }),
    })
      .then(function (body) {
        var result = body.data || {};
        var msg = result.message || body.message || 'Test template sent';
        if (result.success && result.meta_message_id) {
          msg += ' (ID: ' + result.meta_message_id + ')';
        }
        toast(msg, result.success ? 'success' : 'error');
        if (!result.success && result.provider_response) {
          console.info('WhatsApp test send provider response', result.provider_response);
        }
      })
      .catch(function (error) {
        var detail = error.data && error.data.message ? error.data.message : error.message;
        showWhatsAppSettingsError(error);
        toast(detail || 'Failed to send test template', 'error');
      })
      .finally(function () { setWhatsAppSettingsBusy(false); });
  }

  function loadWhatsAppTemplatesFromDatabase(callback, force, dispatchableOnly) {
    var cacheKey = dispatchableOnly ? 'realWhatsAppCampaignTemplates' : 'realWhatsAppTemplates';
    if (window[cacheKey] && !force) {
      if (callback) callback(window[cacheKey]);
      return;
    }
    var url = '/message-templates/whatsapp' + (dispatchableOnly ? '?dispatchable=1' : '');
    apiFetch(url)
      .then(function (body) {
        window[cacheKey] = body.data || [];
        if (callback) callback(window[cacheKey]);
      })
      .catch(function () {
        window[cacheKey] = [];
        if (callback) callback([]);
      });
  }

  function formatWhatsAppLanguageLabel(code) {
    var labels = {
      en_US: 'English (US)',
      en_GB: 'English (UK)',
      en: 'English',
      hi: 'Hindi',
    };
    return labels[code] || code || 'en';
  }

  function populateWhatsAppTemplateSelect() {
    var select = document.getElementById('form-campaign-whatsapp-template-id');
    var testSelect = document.getElementById('whatsapp-settings-test-template-id');
    var campaignTemplates = window.realWhatsAppCampaignTemplates || window.realWhatsAppTemplates || [];
    var allTemplates = window.realWhatsAppTemplates || campaignTemplates;
    var labelFor = function (t) {
      return escapeHtml(t.template_name) + ' · ' + escapeHtml(formatWhatsAppLanguageLabel(t.language_code));
    };
    var options = '<option value="">Select approved WhatsApp template</option>' +
      campaignTemplates.map(function (t) {
        return '<option value="' + t.id + '">' + labelFor(t) + '</option>';
      }).join('');
    var allOptions = '<option value="">Select approved WhatsApp template</option>' +
      allTemplates.map(function (t) {
        return '<option value="' + t.id + '">' + labelFor(t) + '</option>';
      }).join('');
    if (select) select.innerHTML = options;
    if (testSelect) {
      testSelect.innerHTML = allOptions;
      var regTemplate = allTemplates.find(function (t) { return t.template_name === 'task_customermp2et391nk'; })
        || allTemplates.find(function (t) { return t.template_name === 'task_scheduled_reminder'; })
        || allTemplates.find(function (t) { return t.template_name === 'company_registration_docs'; });
      if (regTemplate) testSelect.value = String(regTemplate.id);
    }
    if (select && !select.value) {
      var defaultTemplate = campaignTemplates.find(function (t) { return t.template_name === 'task_customermp2et391nk'; })
        || campaignTemplates[0];
      if (defaultTemplate) select.value = String(defaultTemplate.id);
    }
    syncWhatsAppCampaignTemplateBody();
  }

  function syncWhatsAppCampaignTemplateBody() {
    var select = document.getElementById('form-campaign-whatsapp-template-id');
    var body = document.getElementById('form-campaign-whatsapp-message-template');
    var hidden = document.getElementById('form-campaign-message-template');
    var templateId = parseInt(select?.value || '', 10);
    var templates = window.realWhatsAppCampaignTemplates || window.realWhatsAppTemplates || [];
    var template = templates.find(function (t) { return t.id === templateId; });
    if (body) body.value = template ? (template.body_template || '') : '';
    if (hidden) hidden.value = template ? (template.body_template || '') : '';
    scheduleWhatsAppCampaignPreview(true);
  }

  function getWhatsAppPreviewLead() {
    var selected = getCampaignSelectedLeads()[0];
    if (selected) return selected;
    var pickerItems = getCampaignLeadPickerState().items || [];
    if (pickerItems.length) return pickerItems[0];
    return getLeadsForSelects()[0] || null;
  }

  function renderWhatsAppVariablesLocally(templateBody, lead, template) {
    if (!templateBody || !lead) return '';
    var cityName = lead.city_name || (lead.city && lead.city.city_name) || '';
    var stateName = lead.state_name || (lead.state && lead.state.state_name) || '';
    var variables = {
      '{{name}}': lead.ca_name || '',
      '{{firm_name}}': lead.firm_name || '',
      '{{city}}': cityName,
      '{{mobile}}': lead.mobile_no || '',
      '{{state}}': stateName,
      '{{employee_name}}': lead.executive || lead.employee_name || '',
      '{{demo_date}}': lead.demo_date || '',
      '{{demo_time}}': lead.demo_time || '',
      '{{task_name}}': lead.task_name || 'Follow-up',
      '{{task_status}}': lead.task_status || 'Scheduled',
      '{{scheduled_date}}': lead.scheduled_date || '',
      '{{scheduled_time}}': lead.scheduled_time || '',
      '{{1}}': lead.ca_name || '',
      '{{2}}': 'GST Return',
      '{{3}}': '24-June-2025',
      '{{4}}': '₹10,150',
      '{{5}}': '28-June-2025',
    };
    var invoiceFallbacks = {
      service_name: 'GST Return',
      invoice_date: '24-June-2025',
      invoice_amount: '₹10,150',
      due_date: '28-June-2025',
    };
    if (template && template.variable_map && typeof template.variable_map === 'object') {
      Object.keys(template.variable_map).forEach(function (placeholder) {
        var source = template.variable_map[placeholder];
        if (typeof source === 'string' && source.indexOf('static:') === 0) {
          variables[placeholder] = source.slice(7);
        } else if (source === 'ca_name' || source === 'client_name') {
          variables[placeholder] = lead.ca_name || '';
        } else if (invoiceFallbacks[source]) {
          variables[placeholder] = invoiceFallbacks[source];
        }
      });
    }
    return Object.keys(variables).reduce(function (text, key) {
      return text.split(key).join(variables[key]);
    }, templateBody);
  }

  var waPreviewTimer = null;

  function scheduleWhatsAppCampaignPreview(silent) {
    clearTimeout(waPreviewTimer);
    waPreviewTimer = setTimeout(function () {
      refreshWhatsAppCampaignPreview(!!silent);
    }, 250);
  }

  function showWhatsAppCampaignValidation(messages, isError) {
    var box = document.getElementById('form-campaign-whatsapp-validation');
    if (!box) return;
    if (!messages || !messages.length) {
      box.classList.add('hidden');
      box.textContent = '';
      return;
    }
    box.classList.remove('hidden');
    box.classList.toggle('border-amber-200', !isError);
    box.classList.toggle('bg-amber-50', !isError);
    box.classList.toggle('text-amber-800', !isError);
    box.classList.toggle('border-red-200', !!isError);
    box.classList.toggle('bg-red-50', !!isError);
    box.classList.toggle('text-red-700', !!isError);
    box.textContent = messages.join(' ');
  }

  function refreshWhatsAppCampaignPreview(silent) {
    var channel = document.getElementById('form-campaign-channel')?.value;
    if (channel !== 'whatsapp') return;

    var templateId = parseInt(document.getElementById('form-campaign-whatsapp-template-id')?.value || '', 10);
    var lead = getWhatsAppPreviewLead();
    var template = (window.realWhatsAppTemplates || []).find(function (t) { return t.id === templateId; });
    var templateBody = document.getElementById('form-campaign-whatsapp-message-template')?.value || '';
    var previewEl = document.getElementById('form-campaign-whatsapp-preview-message');

    if (!templateId || !templateBody) {
      if (previewEl) previewEl.value = '';
      return;
    }

    if (lead) {
      if (previewEl) previewEl.value = renderWhatsAppVariablesLocally(templateBody, lead, template);
    }

    if (!templateId || !lead || !lead.ca_id) return;

    apiFetch('/whatsapp-campaigns/preview-message', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message_template_id: templateId, lead_id: lead.ca_id }),
    })
      .then(function (body) {
        var data = body.data || {};
        if (previewEl && data.preview) previewEl.value = data.preview;
        if (!silent) toast('Preview ready', 'success');
      })
      .catch(function (error) {
        if (!silent) toast(error.message || 'Failed to preview WhatsApp message', 'error');
      });
  }

  function validateWhatsAppCampaignPrerequisites(payload, onSuccess) {
    apiFetch('/whatsapp-campaigns/validate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then(function (body) {
      var result = body.data || {};
      if (!result.valid) {
        showWhatsAppCampaignValidation(result.errors || ['WhatsApp campaign validation failed'], true);
        toast((result.errors && result.errors[0]) || 'WhatsApp campaign validation failed', 'error');
        return;
      }
      showWhatsAppCampaignValidation(result.warnings || [], false);
      if (result.warnings && result.warnings.length && !onSuccess) {
        result.warnings.forEach(function (warning) {
          toast(warning, 'warning');
        });
      }
      if (onSuccess) onSuccess(result);
    }).catch(function (error) {
      showWhatsAppCampaignValidation([error.message || 'WhatsApp campaign validation failed'], true);
      toast(error.message || 'WhatsApp campaign validation failed', 'error');
    });
  }

  function submitWhatsAppCampaign(form, callback) {
    if (!form || !isCampaignModalOpen()) return;
    var fd = new FormData(form);
    var data = Object.fromEntries(fd.entries());
    if (!campaignNameFromForm(data)) {
      toast('Campaign name is required', 'error');
      return;
    }
    if (!(data.campaign_type && data.campaign_type.trim())) {
      toast('Campaign type is required', 'error');
      return;
    }
    var waTemplateId = parseInt(document.getElementById('form-campaign-whatsapp-template-id')?.value || '', 10);
    if (!waTemplateId) {
      toast('Select an approved WhatsApp template', 'error');
      return;
    }
    var scheduleCheck = validateCampaignScheduledAt(data.scheduled_at);
    if (!scheduleCheck.valid) {
      toast(scheduleCheck.message || 'Scheduled date is invalid', 'error');
      return;
    }
    var waPayload = buildCampaignAudiencePayload(data);
    if (waPayload.audience_mode === 'selected_leads' && (!waPayload.ca_ids || !waPayload.ca_ids.length)) {
      toast('Select at least one lead', 'error');
      return;
    }
    waPayload.message_template_id = waTemplateId;
    waPayload.message_template = (document.getElementById('form-campaign-message-template') || {}).value || '';
    waPayload.scheduled_at = scheduleCheck.value;

    validateWhatsAppCampaignPrerequisites(waPayload, function () {
      var createBtn = document.getElementById('btn-create-campaign');
      if (createBtn) createBtn.disabled = true;
      apiFetch('/whatsapp-campaigns', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(waPayload),
      })
        .then(function (body) {
          var campaign = body.data || {};
          if (callback) {
            callback(campaign);
            return;
          }
          closeModal(document.getElementById('modal-add-campaign'));
          form.reset();
          resetFormDateTimePickers(form);
          showWhatsAppCampaignValidation([], false);
          configureCampaignModal('whatsapp');
          refreshWhatsAppPage();
          if (document.getElementById('recent-activity-list')) renderRecentActivity();
          var sent = campaign.sent_count || 0;
          var delivered = campaign.delivered_count || 0;
          var failed = campaign.failed_count || 0;
          var status = campaign.status || 'Completed';
          if (status === 'Scheduled' && campaign.scheduled_at) {
            toast('Scheduled for ' + formatSchedulePreview(campaign.scheduled_at), 'success');
          } else if (status === 'Processing') {
            afterCampaignQueued('whatsapp', campaign.id, function () {
              refreshWhatsAppPage();
              if (document.getElementById('recent-activity-list')) renderRecentActivity();
            }, body.message || 'WhatsApp campaign queued successfully.');
          } else {
            var toastMsg = 'Campaign "' + (campaign.campaign_name || campaignNameFromForm(data)) + '" · ' +
              (campaign.total_messages || 0) + ' recipients · Sent to Meta ' + sent;
            if (failed > 0) {
              toastMsg += ' · Failed ' + failed;
            } else if (delivered > 0) {
              toastMsg += ' · Delivered ' + delivered;
            } else if (sent > 0) {
              toastMsg += ' · Phone delivery pending';
            }
            toast(toastMsg, failed > 0 ? 'warning' : 'success');
          }
        })
        .catch(function (error) {
          showWhatsAppCampaignValidation([error.message || 'Failed to send WhatsApp campaign'], true);
          toast(error.message || 'Failed to send WhatsApp campaign', 'error');
        })
        .finally(function () {
          if (createBtn) createBtn.disabled = false;
        });
    });
  }

  function loadEmailTemplatesFromDatabase(callback, force) {
    if (window.realEmailTemplates && !force) {
      if (callback) callback(window.realEmailTemplates);
      return;
    }
    apiFetch('/email-templates')
      .then(function (body) {
        window.realEmailTemplates = body.data || [];
        if (callback) callback(window.realEmailTemplates);
      })
      .catch(function () {
        window.realEmailTemplates = [];
        if (callback) callback([]);
      });
  }

  function populateEmailTemplateSelect() {
    var select = document.getElementById('form-campaign-email-template-id');
    if (!select) return;
    var templates = window.realEmailTemplates || [];
    select.innerHTML = '<option value="">Select email template (optional)</option>' +
      templates.map(function (t) {
        return '<option value="' + t.id + '">' + escapeHtml(t.name || t.slug || ('Template ' + t.id)) + '</option>';
      }).join('');
    var auditTemplate = templates.find(function (t) { return t.slug === 'company-registration-docs'; })
      || templates.find(function (t) { return t.slug === 'audit-data-request'; });
    if (auditTemplate) select.value = String(auditTemplate.id);
  }

  function syncEmailCampaignTemplateFields() {
    var select = document.getElementById('form-campaign-email-template-id');
    var templateId = parseInt(select?.value || '', 10);
    var template = (window.realEmailTemplates || []).find(function (t) { return t.id === templateId; });
    var subject = document.getElementById('form-campaign-email-subject');
    var body = document.getElementById('form-campaign-body-template');
    if (template) {
      if (subject) subject.value = template.subject || '';
      if (body) body.value = template.body || '';
    }
    scheduleEmailCampaignPreview(true);
  }

  function renderEmailVariablesLocally(subject, body, lead) {
    if (!lead) return { subject: subject || '', body: body || '' };
    var cityName = lead.city_name || (lead.city && lead.city.city_name) || '';
    var senderName = (window.currentUser && window.currentUser.name) || 'CA Cloud Desk';
    var vars = {
      '{CLIENT_NAME}': lead.ca_name || '',
      '{{CLIENT_NAME}}': lead.ca_name || '',
      '{{SERVICE_NAME}}': 'GST Return',
      '{{INVOICE_DATE}}': '24-June-2025',
      '{{INVOICE_AMOUNT}}': '₹10,150',
      '{{DUE_DATE}}': '28-June-2025',
      '{SERVICE_NAME}': 'GST Return',
      '{INVOICE_DATE}': '24-June-2025',
      '{INVOICE_AMOUNT}': '₹10,150',
      '{DUE_DATE}': '28-June-2025',
      '{CA_ORGANIZATION_NAME}': lead.firm_name || '',
      '{SENDER_NAME}': senderName,
      '{EMAIL}': lead.email_id || '',
      '{PHONE}': lead.mobile_no || '',
      '{CITY}': cityName,
      '{{name}}': lead.ca_name || '',
      '{{firm_name}}': lead.firm_name || '',
      '{{email}}': lead.email_id || '',
      '{{mobile}}': lead.mobile_no || '',
      '{{city}}': cityName,
    };
    var applyVars = function (text) {
      return Object.keys(vars).reduce(function (result, key) {
        return result.split(key).join(vars[key]);
      }, text || '');
    };
    return { subject: applyVars(subject), body: applyVars(body) };
  }

  var emailPreviewTimer = null;

  function scheduleEmailCampaignPreview(silent) {
    clearTimeout(emailPreviewTimer);
    emailPreviewTimer = setTimeout(function () {
      refreshEmailCampaignPreview(!!silent);
    }, 250);
  }

  function refreshEmailCampaignPreview(silent) {
    var channel = document.getElementById('form-campaign-channel')?.value;
    if (channel !== 'email') return;
    var templateId = parseInt(document.getElementById('form-campaign-email-template-id')?.value || '', 10);
    var subject = document.getElementById('form-campaign-email-subject')?.value || '';
    var body = document.getElementById('form-campaign-body-template')?.value || '';
    var lead = getWhatsAppPreviewLead();
    var previewEl = document.getElementById('form-campaign-email-preview');
    if (lead && (subject || body)) {
      var local = renderEmailVariablesLocally(subject, body, lead);
      if (previewEl) previewEl.value = 'Subject: ' + local.subject + '\n\n' + local.body;
    }
    if (!templateId || !lead || !lead.ca_id) return;
    apiFetch('/email-templates/preview', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email_template_id: templateId, lead_id: lead.ca_id }),
    }).then(function (resp) {
      var data = resp.data || {};
      if (previewEl) previewEl.value = 'Subject: ' + (data.subject || subject) + '\n\n' + (data.body || body);
      if (!silent) toast('Email preview ready', 'success');
    }).catch(function (error) {
      if (!silent) toast(error.message || 'Failed to preview email', 'error');
    });
  }

  function previewEmailCampaignMessage() {
    refreshEmailCampaignPreview(false);
  }

  function populateEmailSettingsForm(email) {
    email = email || {};
    var setVal = function (id, val) {
      var el = document.getElementById(id);
      if (el && val != null && val !== '') el.value = val;
    };
    setVal('email-settings-provider-name', email.provider_name || 'cloud desk');
    setVal('email-settings-smtp-host', email.smtp_host || 'smtpout.secureserver.net');
    setVal('email-settings-smtp-port', email.smtp_port || 465);
    setVal('email-settings-smtp-username', email.smtp_username || 'CRM Email');
    setVal('email-settings-smtp-encryption', email.smtp_encryption || 'tls');
    setVal('email-settings-from-email', email.from_email || 'cacloud12@gmail.com');
    setVal('email-settings-from-name', email.from_name || 'CA Cloud Desk');
    setVal('email-settings-reply-to-email', email.reply_to_email || 'cacloud12@gmail.com');
    setVal('email-settings-mode', email.mode || 'simulation');
    var pwd = document.getElementById('email-settings-smtp-password');
    if (pwd) pwd.value = '';
    var pwdNote = document.getElementById('email-settings-password-note');
    if (pwdNote) {
      pwdNote.textContent = email.has_smtp_password
        ? 'SMTP password is configured (encrypted). Leave blank to keep current password.'
        : 'Set SMTP password here or via SMTP_PASSWORD in .env.';
    }
    var emailBadge = document.getElementById('email-settings-mode-badge');
    if (emailBadge) emailBadge.textContent = (email.mode || 'simulation') === 'live' ? 'Live' : 'Simulation';
    var summary = document.getElementById('email-settings-status-summary');
    if (summary) {
      var parts = [];
      if (email.last_tested_at) parts.push('Last test: ' + email.last_test_status + ' (' + email.last_tested_at + ')');
      if (email.is_configured) parts.push('Configured');
      summary.textContent = parts.length ? parts.join(' · ') : 'Configure GoDaddy / cloud desk SMTP for live campaign delivery.';
    }
  }

  function showEmailSettingsError(err) {
    var box = document.getElementById('email-settings-error-box');
    if (!box) return;
    if (!err) {
      box.classList.add('hidden');
      box.textContent = '';
      return;
    }
    box.classList.remove('hidden');
    box.textContent = err.message || String(err);
  }

  function collectEmailSettingsPayload() {
    var payload = {
      provider_name: (document.getElementById('email-settings-provider-name') || {}).value || '',
      smtp_host: (document.getElementById('email-settings-smtp-host') || {}).value || '',
      smtp_port: parseInt((document.getElementById('email-settings-smtp-port') || {}).value, 10) || null,
      smtp_username: (document.getElementById('email-settings-smtp-username') || {}).value || '',
      smtp_encryption: (document.getElementById('email-settings-smtp-encryption') || {}).value || '',
      from_email: (document.getElementById('email-settings-from-email') || {}).value || '',
      from_name: (document.getElementById('email-settings-from-name') || {}).value || '',
      reply_to_email: (document.getElementById('email-settings-reply-to-email') || {}).value || '',
      mode: (document.getElementById('email-settings-mode') || {}).value || 'simulation',
    };
    var pwdVal = (document.getElementById('email-settings-smtp-password') || {}).value || '';
    if (pwdVal) payload.smtp_password = pwdVal;
    return payload;
  }

  function bindEmailSettingsHandlers() {
    var emailSaveBtn = document.getElementById('email-settings-save-btn');
    if (emailSaveBtn && !emailSaveBtn._emailSettingsBound) {
      emailSaveBtn._emailSettingsBound = true;
      emailSaveBtn.addEventListener('click', function () {
        showEmailSettingsError(null);
        apiFetch('/email-settings', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(collectEmailSettingsPayload()),
        }).then(function (body) {
          populateEmailSettingsForm(body.data || {});
          toast('Email settings saved', 'success');
        }).catch(function (err) {
          showEmailSettingsError(err);
          toast(err.message || 'Unable to save email settings', 'error');
        });
      });
    }
    var validateBtn = document.getElementById('email-settings-validate-btn');
    if (validateBtn && !validateBtn._emailSettingsBound) {
      validateBtn._emailSettingsBound = true;
      validateBtn.addEventListener('click', function () {
        showEmailSettingsError(null);
        apiFetch('/email-settings/validate', { method: 'POST' })
          .then(function (body) {
            var result = body.data || {};
            if (result.valid) {
              toast('SMTP configuration is valid', 'success');
              if (result.settings) populateEmailSettingsForm(result.settings);
            } else {
              var message = (result.errors || []).join(' ') || 'SMTP validation failed';
              showEmailSettingsError({ message: message });
              toast(message, 'error');
            }
          })
          .catch(function (err) {
            showEmailSettingsError(err);
            toast(err.message || 'SMTP validation failed', 'error');
          });
      });
    }
    var testBtn = document.getElementById('email-settings-send-test-btn');
    if (testBtn && !testBtn._emailSettingsBound) {
      testBtn._emailSettingsBound = true;
      testBtn.addEventListener('click', function () {
        var recipient = (document.getElementById('email-settings-test-recipient') || {}).value || '';
        if (!recipient) {
          toast('Enter a test recipient email address', 'error');
          return;
        }
        showEmailSettingsError(null);
        testBtn.disabled = true;
        apiFetch('/email-settings/send-test-email', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ recipient_email: recipient }),
        }).then(function (body) {
          var result = body.data || {};
          if (result.settings) populateEmailSettingsForm(result.settings);
          toast(result.message || 'Test email sent successfully', 'success');
          refreshEmailPage();
        }).catch(function (err) {
          showEmailSettingsError(err);
          toast(err.message || 'Failed to send test email', 'error');
        }).finally(function () {
          testBtn.disabled = false;
        });
      });
    }
  }

  function previewWhatsAppCampaignMessage() {
    refreshWhatsAppCampaignPreview(false);
  }

  function bindWhatsAppSettingsHandlers() {
    if (window._whatsappSettingsDocDelegation) return;
    window._whatsappSettingsDocDelegation = true;

    document.addEventListener('click', function (e) {
      if (e.target.closest('#whatsapp-integration-card')) {
        e.preventDefault();
        openWhatsAppIntegrationPanel();
        return;
      }
      if (e.target.closest('#whatsapp-settings-open-btn')) {
        e.preventDefault();
        openWhatsAppIntegrationPanel();
        return;
      }
      if (e.target.closest('#whatsapp-settings-close-btn') || e.target.closest('#whatsapp-settings-cancel-btn')) {
        e.preventDefault();
        apiFetch('/whatsapp-settings').then(function (body) {
          populateWhatsAppSettingsForm(body.data || {});
        }).catch(function () {}).finally(closeWhatsAppIntegrationPanel);
        return;
      }
      if (e.target.closest('#whatsapp-settings-save-btn')) {
        e.preventDefault();
        saveWhatsAppSettings();
        return;
      }
      if (e.target.closest('#whatsapp-settings-validate-btn')) {
        e.preventDefault();
        validateWhatsAppSettings();
        return;
      }
      if (e.target.closest('#whatsapp-settings-test-connection-btn')) {
        e.preventDefault();
        testWhatsAppConnection();
        return;
      }
      if (e.target.closest('#whatsapp-settings-send-test-template-btn')) {
        e.preventDefault();
        sendWhatsAppTestTemplate();
        return;
      }
      if (e.target.closest('#whatsapp-settings-reset-btn')) {
        e.preventDefault();
        resetWhatsAppSettings();
        return;
      }
      var retryWaBtn = e.target.closest('[data-retry-wa-log]');
      if (retryWaBtn) {
        e.preventDefault();
        retryWaMessageLog(retryWaBtn.getAttribute('data-retry-wa-log'));
        return;
      }
      var payloadBtn = e.target.closest('[data-view-wa-payload]');
      if (payloadBtn) {
        e.preventDefault();
        var logId = payloadBtn.getAttribute('data-view-wa-payload');
        var log = (window.realWaMessageLogs || []).find(function (l) { return String(l.id) === String(logId); });
        showWaLogJsonModal('Request Payload', log && log.api_payload ? log.api_payload : {});
        return;
      }
      var responseBtn = e.target.closest('[data-view-wa-response]');
      if (responseBtn) {
        e.preventDefault();
        var respLogId = responseBtn.getAttribute('data-view-wa-response');
        var respLog = (window.realWaMessageLogs || []).find(function (l) { return String(l.id) === String(respLogId); });
        showWaLogJsonModal('Meta Response', respLog && respLog.provider_response ? respLog.provider_response : {});
      }
    });
  }

  function initWhatsAppSettingsModule() {
    bindWhatsAppSettingsHandlers();
  }

  initSmsSettingsModule();
  initWhatsAppSettingsModule();

  var _emailAccountState = {
    items: [],
    smtpToken: '',
    imapToken: '',
    smtpVerified: false,
    imapVerified: false,
  };

  function resetEmailAccountVerification() {
    _emailAccountState.smtpToken = '';
    _emailAccountState.imapToken = '';
    _emailAccountState.smtpVerified = false;
    _emailAccountState.imapVerified = false;
    var smtpBadge = document.getElementById('email-account-smtp-test-badge');
    var imapBadge = document.getElementById('email-account-imap-test-badge');
    var imapDetails = document.getElementById('email-account-imap-test-details');
    if (smtpBadge) { smtpBadge.textContent = 'Not tested'; smtpBadge.className = 'badge-neutral'; }
    if (imapBadge) { imapBadge.textContent = 'Not tested'; imapBadge.className = 'badge-neutral'; }
    if (imapDetails) { imapDetails.classList.add('hidden'); imapDetails.innerHTML = ''; }
    updateEmailAccountSaveState();
  }

  function renderImapTestDetails(data) {
    var el = document.getElementById('email-account-imap-test-details');
    if (!el) return;
    var folders = (data.folders || []).slice(0, 8).join(', ');
    el.innerHTML =
      '<div class="ecfg-imap-success-card">' +
        '<p class="font-medium text-emerald-700">Connected to: ' + escapeHtml(data.connected_mailbox || '') + '</p>' +
        '<ul class="text-caption text-slate-600 mt-2 space-y-1">' +
          '<li>Inbox found: ' + (data.inbox_found ? 'Yes' : 'No') + '</li>' +
          '<li>Messages in inbox: ' + (data.inbox_count != null ? data.inbox_count : '—') + '</li>' +
          '<li>Unread messages: ' + (data.unread_count != null ? data.unread_count : '—') + '</li>' +
          '<li>Folders found: ' + (data.folders_count != null ? data.folders_count : '—') + (folders ? ' (' + escapeHtml(folders) + ')' : '') + '</li>' +
        '</ul>' +
      '</div>';
    el.classList.remove('hidden');
  }

  function renderImapTestError(message) {
    var el = document.getElementById('email-account-imap-test-details');
    if (!el) return;
    el.innerHTML = '<div class="ecfg-imap-error-card"><p class="text-rose-700 text-sm">' + escapeHtml(message || 'IMAP connection failed') + '</p></div>';
    el.classList.remove('hidden');
  }

  function updateEmailAccountSaveState() {
    var saveBtn = document.getElementById('email-account-save-btn');
    if (!saveBtn) return;
    var imapEnabled = !!(document.getElementById('email-account-imap-enabled') || {}).checked;
    var ok = _emailAccountState.smtpVerified && (!imapEnabled || _emailAccountState.imapVerified);
    saveBtn.disabled = !ok;
  }

  function toggleImapFieldsUi() {
    var enabled = !!(document.getElementById('email-account-imap-enabled') || {}).checked;
    var fields = document.getElementById('email-account-imap-fields');
    var testBtn = document.getElementById('email-account-test-imap-btn');
    var testBadge = document.getElementById('email-account-imap-test-badge');
    if (fields) fields.classList.toggle('hidden', !enabled);
    if (testBtn) testBtn.classList.toggle('hidden', !enabled);
    if (testBadge) testBadge.classList.toggle('hidden', !enabled);
    updateEmailAccountSaveState();
  }

  function applyGmailAutofill(force) {
    var emailEl = document.getElementById('email-account-from-email');
    if (!emailEl) return;
    var email = (emailEl.value || '').trim().toLowerCase();
    if (!email.endsWith('@gmail.com')) return;
    var host = document.getElementById('email-account-smtp-host');
    var port = document.getElementById('email-account-smtp-port');
    var enc = document.getElementById('email-account-smtp-encryption');
    var imapHost = document.getElementById('email-account-imap-host');
    var imapPort = document.getElementById('email-account-imap-port');
    if (host && (force || !host.value.trim() || host.value.indexOf('secureserver') !== -1)) host.value = 'smtp.gmail.com';
    if (port && (force || !port.value.trim())) port.value = '587';
    if (enc) enc.value = 'tls';
    if (imapHost && (force || !imapHost.value.trim() || imapHost.value.indexOf('secureserver') !== -1)) imapHost.value = 'imap.gmail.com';
    if (imapPort && (force || !imapPort.value.trim())) imapPort.value = '993';
    var username = document.getElementById('email-account-smtp-username');
    if (username) username.value = email;
    var imapUsername = document.getElementById('email-account-imap-username');
    if (imapUsername) imapUsername.value = email;
    var display = document.getElementById('email-account-display-name');
    if (display && !display.value.trim()) {
      display.value = email.split('@')[0].replace(/[._]/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }
  }

  function switchEmailConfigTab(tabId) {
    var group = 'email-account';
    document.querySelectorAll('.ca-tab[data-tab-group="' + group + '"]').forEach(function (t) {
      t.classList.toggle('active', t.dataset.tab === tabId);
    });
    document.querySelectorAll('.ca-tab-panel[data-tab-group="' + group + '"]').forEach(function (p) {
      p.classList.toggle('active', p.dataset.panel === tabId);
    });
    icons();
  }

  function collectEmailAccountPayload() {
    var fromEmail = (document.getElementById('email-account-from-email') || {}).value || '';
    var smtpPassword = ((document.getElementById('email-account-smtp-password') || {}).value || '').replace(/\s+/g, '');
    var smtpUsernameEl = document.getElementById('email-account-smtp-username');
    var displayEl = document.getElementById('email-account-display-name');
    var imapEnabled = !!(document.getElementById('email-account-imap-enabled') || {}).checked;
    if (smtpUsernameEl) smtpUsernameEl.value = fromEmail.trim();
    if (displayEl && !displayEl.value.trim() && fromEmail) {
      displayEl.value = fromEmail.split('@')[0];
    }
    return {
      account_id: (document.getElementById('email-account-id') || {}).value || null,
      from_email: fromEmail,
      display_name: (displayEl || {}).value || '',
      from_name: (displayEl || {}).value || '',
      smtp_host: (document.getElementById('email-account-smtp-host') || {}).value || '',
      smtp_port: parseInt((document.getElementById('email-account-smtp-port') || {}).value, 10) || null,
      smtp_encryption: (document.getElementById('email-account-smtp-encryption') || {}).value || 'ssl',
      smtp_username: (smtpUsernameEl || {}).value || fromEmail.trim(),
      smtp_password: smtpPassword,
      imap_enabled: imapEnabled,
      imap_host: (document.getElementById('email-account-imap-host') || {}).value || '',
      imap_port: parseInt((document.getElementById('email-account-imap-port') || {}).value, 10) || 993,
      imap_encryption: (document.getElementById('email-account-imap-encryption') || {}).value || 'ssl',
      imap_username: fromEmail.trim(),
      imap_password: smtpPassword,
      is_default: !!(document.getElementById('email-account-is-default') || {}).checked,
      is_active: !!(document.getElementById('email-account-is-active') || {}).checked,
      mode: 'live',
      smtp_verification_token: _emailAccountState.smtpToken,
      imap_verification_token: _emailAccountState.imapToken,
    };
  }

  function emailAccountStatusBadge(account) {
    if (!account.is_active) return '<span class="badge-neutral">Inactive</span>';
    if (account.smtp_last_test_status === 'success') return '<span class="badge-success">Connected</span>';
    if (account.smtp_last_test_status === 'failed') return '<span class="badge-danger">Failed</span>';
    return '<span class="badge-neutral">Untested</span>';
  }

  function paintEmailAccountsViewTable(items) {
    var body = document.getElementById('email-accounts-view-body');
    if (!body) return;
    if (!items.length) {
      body.innerHTML = '<tr><td colspan="7" class="text-center text-slate-500 p-6">No email accounts yet. Configure SMTP on the left tab and save.</td></tr>';
      return;
    }
    body.innerHTML = items.map(function (a) {
      var actionItems = [
        { action: 'edit', label: 'Edit', icon: 'pencil' },
      ];
      if (!a.is_default) {
        actionItems.push({ action: 'default', label: 'Make Default', icon: 'star' });
      }
      actionItems.push({ action: 'delete', label: 'Delete', icon: 'trash-2', danger: true });
      var actionsCell = window.CAActionDropdown
        ? CAActionDropdown.renderCell(actionItems, {
          scope: 'email-account',
          rowId: a.id,
          cellClass: 'crm-actions-cell text-right',
          ariaLabel: 'Email account actions',
        })
        : '<td class="crm-actions-cell text-right"><span class="cam-cell-empty">—</span></td>';
      return '<tr class="ca-table-row" data-email-account-id="' + a.id + '">' +
        '<td>' + escapeHtml(a.from_email) + '</td>' +
        '<td>' + escapeHtml(a.smtp_host || '—') + '</td>' +
        '<td>' + escapeHtml(a.smtp_port != null ? String(a.smtp_port) : '—') + '</td>' +
        '<td>' + (a.imap_enabled ? '<span class="badge-success">Yes</span>' : '<span class="badge-neutral">No</span>') + '</td>' +
        '<td>' + (a.is_default ? '<span class="badge-brand">Default</span>' : '—') + '</td>' +
        '<td>' + emailAccountStatusBadge(a) + '</td>' +
        actionsCell +
      '</tr>';
    }).join('');
    icons();
  }

  function paintEmailAccountsList(items) {
    paintEmailAccountsViewTable(items);
  }

  function loadEmailAccountIntoForm(id) {
    var account = _emailAccountState.items.find(function (a) { return String(a.id) === String(id); });
    if (!account) return;
    document.getElementById('email-account-id').value = account.id;
    document.getElementById('email-account-from-email').value = account.from_email || '';
    document.getElementById('email-account-display-name').value = account.display_name || account.from_name || '';
    document.getElementById('email-account-smtp-host').value = account.smtp_host || '';
    document.getElementById('email-account-smtp-port').value = account.smtp_port || 465;
    document.getElementById('email-account-smtp-encryption').value = account.smtp_encryption || 'ssl';
    document.getElementById('email-account-smtp-username').value = account.smtp_username || '';
    document.getElementById('email-account-smtp-password').value = '';
    document.getElementById('email-account-imap-enabled').checked = !!account.imap_enabled;
    document.getElementById('email-account-imap-host').value = account.imap_host || '';
    document.getElementById('email-account-imap-port').value = account.imap_port || 993;
    var imapEnc = document.getElementById('email-account-imap-encryption');
    if (imapEnc) imapEnc.value = account.imap_encryption || 'ssl';
    document.getElementById('email-account-is-default').checked = !!account.is_default;
    document.getElementById('email-account-is-active').checked = !!account.is_active;
    document.getElementById('email-account-delete-btn')?.classList.remove('hidden');
    document.getElementById('email-account-sync-imap-btn')?.classList.toggle('hidden', !account.imap_enabled);
    toggleImapFieldsUi();
    switchEmailConfigTab('smtp');
    resetEmailAccountVerification();
  }

  function clearEmailAccountForm() {
    var form = document.getElementById('email-account-form');
    if (!form) return;
    form.reset();
    document.getElementById('email-account-id').value = '';
    document.getElementById('email-account-is-active').checked = true;
    document.getElementById('email-account-delete-btn')?.classList.add('hidden');
    document.getElementById('email-account-sync-imap-btn')?.classList.add('hidden');
    toggleImapFieldsUi();
    switchEmailConfigTab('smtp');
    resetEmailAccountVerification();
  }

  function reloadEmailAccountsList() {
    return apiFetch('/email-accounts').then(function (body) {
      _emailAccountState.items = (body.data && body.data.items) ? body.data.items : [];
      paintEmailAccountsViewTable(_emailAccountState.items);
    }).catch(function (err) {
      toast(err.message || 'Unable to load email accounts', 'error');
    });
  }

  function initEmailConfigurationPage() {
    var root = document.getElementById('email-config-page-root');
    if (!root) return;

    if (!root._emailConfigBound) {
      root._emailConfigBound = true;

      root.querySelector('#email-account-add-btn')?.addEventListener('click', clearEmailAccountForm);
      root.querySelector('#email-account-imap-enabled')?.addEventListener('change', function () {
        resetEmailAccountVerification();
        toggleImapFieldsUi();
      });

      root.querySelector('#email-account-from-email')?.addEventListener('blur', function () {
        applyGmailAutofill(true);
      });
      root.querySelector('#email-account-from-email')?.addEventListener('input', function () {
        resetEmailAccountVerification();
        applyGmailAutofill(true);
      });

      root.querySelector('#email-account-test-smtp-btn')?.addEventListener('click', function () {
        applyGmailAutofill(true);
        var payload = collectEmailAccountPayload();
        apiFetch('/email-accounts/test-smtp', { method: 'POST', body: JSON.stringify(payload), headers: { 'Content-Type': 'application/json' } })
          .then(function (body) {
            var data = body.data || {};
            _emailAccountState.smtpToken = data.verification_token || '';
            _emailAccountState.smtpVerified = true;
            var badge = document.getElementById('email-account-smtp-test-badge');
            if (badge) { badge.textContent = 'SMTP OK'; badge.className = 'badge-success'; }
            toast(data.message || 'SMTP connection successful', 'success');
            updateEmailAccountSaveState();
          })
          .catch(function (err) {
            _emailAccountState.smtpVerified = false;
            var badge = document.getElementById('email-account-smtp-test-badge');
            if (badge) { badge.textContent = 'SMTP Failed'; badge.className = 'badge-danger'; }
            toast(err.message || 'SMTP test failed', 'error');
            updateEmailAccountSaveState();
          });
      });

      root.querySelector('#email-account-test-imap-btn')?.addEventListener('click', function () {
        applyGmailAutofill(true);
        var payload = collectEmailAccountPayload();
        apiFetch('/email-accounts/test-imap', { method: 'POST', body: JSON.stringify(payload), headers: { 'Content-Type': 'application/json' } })
          .then(function (body) {
            var data = body.data || {};
            _emailAccountState.imapToken = data.verification_token || '';
            _emailAccountState.imapVerified = true;
            var imapToggle = document.getElementById('email-account-imap-enabled');
            if (imapToggle) {
              imapToggle.checked = true;
              toggleImapFieldsUi();
            }
            var badge = document.getElementById('email-account-imap-test-badge');
            if (badge) { badge.textContent = 'IMAP Connected'; badge.className = 'badge-success'; }
            renderImapTestDetails(data);
            toast(data.message || 'IMAP Connected Successfully', 'success');
            updateEmailAccountSaveState();
          })
          .catch(function (err) {
            _emailAccountState.imapVerified = false;
            var badge = document.getElementById('email-account-imap-test-badge');
            var errMsg = err.message || 'IMAP test failed';
            if (badge) { badge.textContent = errMsg.length > 40 ? 'IMAP Failed' : errMsg; badge.className = 'badge-danger'; badge.title = errMsg; }
            renderImapTestError(errMsg);
            toast(errMsg, 'error');
            updateEmailAccountSaveState();
          });
      });

      root.querySelector('#email-account-form')?.addEventListener('submit', function (e) {
        e.preventDefault();
        var payload = collectEmailAccountPayload();
        var id = payload.account_id;
        delete payload.account_id;
        var method = id ? 'PUT' : 'POST';
        var url = id ? '/email-accounts/' + encodeURIComponent(id) : '/email-accounts';
        if (!payload.smtp_password) delete payload.smtp_password;
        if (!payload.imap_password) delete payload.imap_password;
        apiFetch(url, { method: method, body: JSON.stringify(payload), headers: { 'Content-Type': 'application/json' } })
          .then(function () {
            toast('Email account saved', 'success');
            clearEmailAccountForm();
            reloadEmailAccountsList();
          })
          .catch(function (err) {
            toast(err.message || 'Unable to save email account', 'error');
          });
      });

      root.querySelector('#email-account-delete-btn')?.addEventListener('click', function () {
        var id = (document.getElementById('email-account-id') || {}).value;
        if (!id || !window.confirm('Delete this email account?')) return;
        apiFetch('/email-accounts/' + encodeURIComponent(id), { method: 'DELETE' })
          .then(function () {
            toast('Email account deleted', 'success');
            clearEmailAccountForm();
            reloadEmailAccountsList();
          })
          .catch(function (err) { toast(err.message || 'Unable to delete account', 'error'); });
      });

      root.querySelector('#email-account-sync-imap-btn')?.addEventListener('click', function () {
        var id = (document.getElementById('email-account-id') || {}).value;
        if (!id) return;
        apiFetch('/email-accounts/' + encodeURIComponent(id) + '/sync-imap', { method: 'POST' })
          .then(function (body) {
            toast((body.data && body.data.message) || 'IMAP sync completed', 'success');
            reloadEmailAccountsList();
          })
          .catch(function (err) { toast(err.message || 'IMAP sync failed', 'error'); });
      });

      ['email-account-smtp-host', 'email-account-smtp-port', 'email-account-smtp-password', 'email-account-imap-host', 'email-account-imap-port', 'email-account-smtp-encryption'].forEach(function (fieldId) {
        root.querySelector('#' + fieldId)?.addEventListener('input', resetEmailAccountVerification);
      });
    }

    toggleImapFieldsUi();
    if (typeof initPasswordToggleButtons === 'function') initPasswordToggleButtons(root);
    reloadEmailAccountsList();
    icons();
  }

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
        var capacityInput = document.getElementById('settings-daily-max-capacity');
        if (capacityInput) {
          capacityInput.value = assignment.daily_max_capacity != null ? assignment.daily_max_capacity : 50;
        }
        var capacityWrap = document.getElementById('settings-daily-capacity-wrap');
        if (capacityWrap) {
          capacityWrap.classList.toggle('hidden', (window.__CRM_USER__ || {}).role !== 'super_admin');
        }
      })
      .catch(function () {});
    var smsIntegrationCard = document.getElementById('sms-integration-card');
    var whatsappIntegrationCard = document.getElementById('whatsapp-integration-card');
    var crmUser = window.__CRM_USER__ || {};
    if (crmUser.role === 'employee') {
      if (smsIntegrationCard) smsIntegrationCard.classList.add('hidden');
      if (whatsappIntegrationCard) whatsappIntegrationCard.classList.add('hidden');
    }
    apiFetch('/whatsapp-settings')
      .then(function (body) {
        populateWhatsAppSettingsForm(body.data || {});
      })
      .catch(function () {
        if (whatsappIntegrationCard) whatsappIntegrationCard.classList.add('hidden');
      });
    apiFetch('/sms-settings')
      .then(function (body) {
        populateSmsSettingsForm(body.data || {});
      })
      .catch(function () {
        if (smsIntegrationCard) smsIntegrationCard.classList.add('hidden');
      });
    apiFetch('/email-settings')
      .then(function (body) {
        populateEmailSettingsForm(body.data || {});
        bindEmailSettingsHandlers();
      })
      .catch(function () {});
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
        if ((window.__CRM_USER__ || {}).role === 'super_admin') {
          var capacityVal = parseInt((document.getElementById('settings-daily-max-capacity') || {}).value, 10);
          if (capacityVal > 0) payload.assignment.daily_max_capacity = capacityVal;
        }
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

  function initSettingsEmailTemplatesPage() {
    if (window.CrmTemplateManagement) {
      window.CrmTemplateManagement.initEmail();
    }
  }

  function initSettingsWhatsAppTemplatesPage() {
    if (window.CrmTemplateManagement) {
      window.CrmTemplateManagement.initWhatsApp();
    }
  }

  function initDemoProvidersSettingsPage() {
    var root = document.getElementById('settings-demo-providers-page');
    var list = document.getElementById('demo-providers-settings-list');
    if (!root || !list || root._demoProvidersBound) return;
    root._demoProvidersBound = true;

    function renderProviders(items) {
      if (!items.length) {
        list.innerHTML = '<p class="text-slate-500">No demo providers configured.</p>';
        return;
      }
      list.innerHTML = items.map(function (p) {
        return '<article class="demo-provider-settings-card" data-provider-id="' + p.id + '">' +
          '<div class="demo-provider-settings-card__head"><h4 class="font-semibold">' + escapeHtml(p.name) + '</h4>' +
          '<span class="badge-' + (p.is_active ? 'success' : 'neutral') + '">' + (p.is_active ? 'Active' : 'Inactive') + '</span></div>' +
          '<div class="grid sm:grid-cols-2 gap-3 mt-3 text-sm">' +
            '<label class="block">Work Start<input class="input-field mt-1" data-field="work_start_time" value="' + escapeHtml(String(p.work_start_time || '10:00:00').slice(0, 5)) + '" /></label>' +
            '<label class="block">Work End<input class="input-field mt-1" data-field="work_end_time" value="' + escapeHtml(String(p.work_end_time || '18:00:00').slice(0, 5)) + '" /></label>' +
            '<label class="block">Slot (min)<input class="input-field mt-1" data-field="slot_duration_minutes" type="number" min="15" value="' + (p.slot_duration_minutes || 60) + '" /></label>' +
            '<label class="block">Buffer (min)<input class="input-field mt-1" data-field="buffer_minutes" type="number" min="0" value="' + (p.buffer_minutes || 15) + '" /></label>' +
            '<label class="block">Max Demos/Day<input class="input-field mt-1" data-field="max_demos_per_day" type="number" min="1" value="' + (p.max_demos_per_day || 6) + '" /></label>' +
            '<label class="block">Meeting Link<input class="input-field mt-1" data-field="default_meeting_link" value="' + escapeHtml(p.default_meeting_link || '') + '" /></label>' +
            '<label class="block sm:col-span-2">Break Start<input class="input-field mt-1" data-field="break_start_time" value="' + escapeHtml(String(p.break_start_time || '13:00:00').slice(0, 5)) + '" /></label>' +
            '<label class="block sm:col-span-2">Break End<input class="input-field mt-1" data-field="break_end_time" value="' + escapeHtml(String(p.break_end_time || '14:00:00').slice(0, 5)) + '" /></label>' +
          '</div>' +
          '<button type="button" class="btn-primary btn-sm mt-4" data-save-provider="' + p.id + '">Save Provider</button>' +
        '</article>';
      }).join('');
    }

    apiFetch('/demo-calendar/providers/settings')
      .then(function (body) {
        var items = body.data || [];
        if (items.data) items = items.data;
        renderProviders(Array.isArray(items) ? items : []);
      })
      .catch(function () {
        list.innerHTML = '<p class="text-rose-500">Unable to load demo providers.</p>';
      });

    list.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-save-provider]');
      if (!btn) return;
      var card = btn.closest('[data-provider-id]');
      var id = btn.getAttribute('data-save-provider');
      var payload = { is_active: true };
      card.querySelectorAll('[data-field]').forEach(function (input) {
        payload[input.getAttribute('data-field')] = input.value;
      });
      apiFetch('/demo-calendar/providers/' + id, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }).then(function () {
        toast('Demo provider updated.', 'success');
      }).catch(function (err) {
        toast(err.message || 'Unable to save provider.', 'error');
      });
    });
  }

  function initSettingsGoogleApiPage() {
    var root = document.getElementById('settings-google-api-page');
    if (!root || root._googleApiBound) return;
    root._googleApiBound = true;

    var form = document.getElementById('google-api-settings-form');
    var badge = document.getElementById('google-api-status-badge');
    var sourceLabel = document.getElementById('google-api-source-label');
    var maskedEl = document.getElementById('google-api-key-masked');
    var testBtn = document.getElementById('google-api-test-btn');
    var testResult = document.getElementById('google-api-test-result');
    var keyInput = document.getElementById('google-api-key-input');

    function populate(data) {
      data = data || {};
      if (badge) {
        badge.textContent = data.places_api_key_configured ? 'API key configured' : 'Not configured';
        badge.className = data.places_api_key_configured ? 'badge-success' : 'badge-neutral';
      }
      if (sourceLabel) {
        var sourceMap = { database: 'Stored in CRM settings', environment: 'From environment variable', none: 'No key found' };
        sourceLabel.textContent = sourceMap[data.source] || '';
      }
      if (maskedEl) {
        maskedEl.textContent = data.places_api_key_masked ? ('Current key: ' + data.places_api_key_masked) : '';
      }
    }

    function loadSettings() {
      apiFetch('/google-api-settings')
        .then(function (body) { populate(body.data || {}); })
        .catch(function (err) { toast(err.message || 'Unable to load Google API settings', 'error'); });
    }

    loadSettings();

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var saveBtn = document.getElementById('google-api-save-btn');
        if (saveBtn) saveBtn.disabled = true;
        apiFetch('/google-api-settings', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ places_api_key: keyInput ? keyInput.value : '' }),
        }).then(function (body) {
          populate(body.data || {});
          if (keyInput) keyInput.value = '';
          toast('Google API settings saved', 'success');
        }).catch(function (err) {
          toast(err.message || 'Unable to save settings', 'error');
        }).finally(function () {
          if (saveBtn) saveBtn.disabled = false;
        });
      });
    }

    if (testBtn) {
      testBtn.addEventListener('click', function () {
        testBtn.disabled = true;
        if (testResult) {
          testResult.classList.remove('hidden');
          testResult.textContent = 'Testing connection…';
        }
        apiFetch('/google-api-settings/test', { method: 'POST' })
          .then(function (body) {
            var result = body.data || {};
            if (testResult) {
              var lines = [result.message || body.message || 'Test complete'];
              if (result.recommendation) lines.push(result.recommendation);
              if (result.google_reason) lines.push('Reason: ' + result.google_reason);
              testResult.textContent = lines.join(' ');
              testResult.className = 'text-body mt-2 ' + (result.valid ? 'text-emerald-700' : 'text-red-600');
            }
            toast(result.message || body.message, result.valid ? 'success' : 'error');
          })
          .catch(function (err) {
            if (testResult) {
              testResult.textContent = err.message || 'Test failed';
              testResult.className = 'text-body mt-2 text-red-600';
            }
            toast(err.message || 'Test failed', 'error');
          })
          .finally(function () { testBtn.disabled = false; });
      });
    }
  }

  function initRolesPermissionsPage() {
    var root = document.getElementById('roles-permissions-page');
    if (!root || root._rolesPermBound) return;
    root._rolesPermBound = true;

    var roleSelect = document.getElementById('roles-perm-role-select');
    var matrixHead = document.getElementById('roles-perm-matrix-head');
    var matrixBody = document.getElementById('roles-perm-matrix-body');
    var statusEl = document.getElementById('roles-perm-status');
    var saveBtn = document.getElementById('roles-perm-save-btn');
    var resetBtn = document.getElementById('roles-perm-reset-btn');
    var state = {
      modules: [],
      permissions: [],
      moduleLabels: {},
      permissionLabels: {},
      matrix: {},
      dirty: {},
    };

    function showStatus(message, type) {
      if (!statusEl) return;
      statusEl.textContent = message || '';
      statusEl.classList.remove('hidden', 'is-success', 'is-error', 'is-info');
      if (!message) {
        statusEl.classList.add('hidden');
        return;
      }
      statusEl.classList.add(type === 'error' ? 'is-error' : type === 'success' ? 'is-success' : 'is-info');
    }

    function selectedRole() {
      return roleSelect ? roleSelect.value : 'manager';
    }

    function roleGrants(role) {
      var grants = {};
      var roleMatrix = state.matrix[role] || {};
      state.modules.forEach(function (module) {
        grants[module] = (roleMatrix[module] || []).slice();
      });
      return grants;
    }

    function isGranted(role, module, permission) {
      var grants = state.dirty[role] || roleGrants(role);
      var moduleGrants = grants[module] || [];
      return moduleGrants.indexOf(permission) >= 0;
    }

    function renderMatrix() {
      if (!matrixHead || !matrixBody) return;
      var role = selectedRole();
      matrixHead.innerHTML = '<tr><th class="sticky-left roles-perm-module-col">Module</th>' +
        state.permissions.map(function (permission) {
          var label = state.permissionLabels[permission] || rbacPermissionLabel(permission);
          return '<th class="roles-perm-action-col" title="' + escapeHtml(label) + '">' + escapeHtml(label) + '</th>';
        }).join('') + '</tr>';

      matrixBody.innerHTML = state.modules.map(function (module) {
        var moduleLabel = state.moduleLabels[module] || activityModuleLabel(module);
        var cells = state.permissions.map(function (permission) {
          var granted = isGranted(role, module, permission);
          return '<td class="roles-perm-action-col text-center">' +
            '<label class="roles-perm-toggle">' +
              '<input type="checkbox" class="roles-perm-checkbox" data-module="' + escapeHtml(module) + '" data-permission="' + escapeHtml(permission) + '"' + (granted ? ' checked' : '') + ' />' +
              '<span class="roles-perm-toggle-ui" aria-hidden="true"></span>' +
            '</label></td>';
        }).join('');
        return '<tr class="ca-table-row"><th scope="row" class="sticky-left roles-perm-module-col font-medium text-slate-800">' + escapeHtml(moduleLabel) + '</th>' + cells + '</tr>';
      }).join('') || '<tr><td colspan="99" class="text-center text-slate-500 p-6">No modules configured.</td></tr>';

      matrixBody.querySelectorAll('.roles-perm-checkbox').forEach(function (input) {
        input.addEventListener('change', function () {
          var r = selectedRole();
          if (!state.dirty[r]) state.dirty[r] = roleGrants(r);
          var module = input.getAttribute('data-module');
          var permission = input.getAttribute('data-permission');
          var list = state.dirty[r][module] || [];
          if (input.checked) {
            if (list.indexOf(permission) < 0) list.push(permission);
          } else {
            list = list.filter(function (item) { return item !== permission; });
          }
          state.dirty[r][module] = list;
          showStatus('Unsaved changes for ' + rbacRoleLabel(r) + '.', 'info');
        });
      });
    }

    function loadMatrix() {
      showStatus('Loading permissions…', 'info');
      apiFetch('/admin/role-permissions')
        .then(function (body) {
          var data = body.data || {};
          state.modules = data.modules || [];
          state.permissions = data.permissions || [];
          state.moduleLabels = data.module_labels || {};
          state.permissionLabels = data.permission_labels || {};
          state.matrix = data.matrix || {};
          state.dirty = {};
          renderMatrix();
          showStatus('', '');
        })
        .catch(function (err) {
          showStatus(err.message || 'Unable to load role permissions.', 'error');
        });
    }

    if (roleSelect) {
      roleSelect.addEventListener('change', function () {
        renderMatrix();
        showStatus('', '');
      });
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        var role = selectedRole();
        var grants = state.dirty[role] || roleGrants(role);
        saveBtn.disabled = true;
        showStatus('Saving permissions…', 'info');
        apiFetch('/admin/role-permissions', {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ role: role, grants: grants }),
        }).then(function (body) {
          state.matrix = (body.data && body.data.matrix) || state.matrix;
          delete state.dirty[role];
          renderMatrix();
          showStatus('Permissions saved successfully.', 'success');
          toast('Permissions saved successfully', 'success');
        }).catch(function (err) {
          showStatus(err.message || 'Unable to save permissions.', 'error');
          toast(err.message || 'Unable to save permissions', 'error');
        }).finally(function () {
          saveBtn.disabled = false;
        });
      });
    }

    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        var role = selectedRole();
        if (!window.confirm('Reset ' + rbacRoleLabel(role) + ' permissions to default values?')) return;
        resetBtn.disabled = true;
        showStatus('Resetting permissions…', 'info');
        apiFetch('/admin/role-permissions/reset', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ role: role }),
        }).then(function (body) {
          state.matrix = (body.data && body.data.matrix) || state.matrix;
          delete state.dirty[role];
          renderMatrix();
          showStatus('Permissions reset to default.', 'success');
          toast('Permissions reset to default', 'success');
        }).catch(function (err) {
          showStatus(err.message || 'Unable to reset permissions.', 'error');
          toast(err.message || 'Unable to reset permissions', 'error');
        }).finally(function () {
          resetBtn.disabled = false;
        });
      });
    }

    loadMatrix();
  }

  function initSecurityPage() {
    apiFetch('/admin/security-matrix')
      .then(function (body) {
        var data = body.data || {};
        var summary = data.summary || {};
        window._securityMatrix = data.matrix || {};
        window._securityCanEdit = !!data.can_edit;

        function setMetric(id, text) {
          var el = document.getElementById(id);
          if (el && text != null) el.textContent = text;
        }

        var roleCount = summary.role_count != null ? summary.role_count : Object.keys(data.roles || {}).length;
        var userCount = summary.user_count != null ? summary.user_count : (data.users || []).length;
        setMetric('security-metric-rbac', roleCount + ' roles · ' + userCount + ' users');
        setMetric('security-metric-consent', (summary.consent_count != null ? summary.consent_count : '—') + ' consent records');
        setMetric('security-metric-dnd', (summary.dnd_count != null ? summary.dnd_count : '—') + ' DND contacts');
        setMetric('security-metric-encrypt', summary.encryption_label || 'AES-256 via Laravel APP_KEY');
        setMetric('security-metric-locking', (summary.active_lock_count != null ? summary.active_lock_count : 0) + ' active locks');
        setMetric('security-metric-api', summary.api_rate_summary || 'Rate limits enforced');

        var rbacBody = document.getElementById('security-rbac-matrix');
        var note = document.getElementById('security-matrix-note');
        if (note) {
          note.textContent = data.can_edit
            ? 'Toggle permissions for manager, employee, and admin roles. Changes save immediately.'
            : 'Read-only view. Only Super Admin can edit permissions.';
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
    initLeadPickerDeps();
    bindCampaignLeadPickerEvents();
    ensureBulkImportDetailCloseHandlers();
    ensureBulkImportDetailModalRoot();
    bindBulkImportDetailFooterActions();
    if (window.CrmLeadPicker) {
      window.CrmLeadPicker.bind('followup', {
        onSelectionChange: function () {
          var typeSel = document.getElementById('form-followup')?.elements?.followup_type;
          if (typeSel && isFollowupDemoScheduledType(typeSel.value)) {
            populateFollowupDemoFieldsFromLead();
          }
        },
      });
    }
    initForms();
    initCampaignActions();
    initMasterDataActions();
    ensureInboxSelectionBound();
    ensureKpiCardsBound();
    ensureFollowupCalendarPopoverBound();
    bindModalTriggers(document);
    initQuickActions();
    if (window.CAActionDropdown) {
      CAActionDropdown.init();
      registerActionDropdownHandlers();
      CAActionDropdown.bindScrollDismiss(document);
    }
    window.icons = icons;
    if (window.CATablePagination) {
      CATablePagination.init();
      CATablePagination.register('bulk-assign-batches', {
        onPageChange: function (page) { loadBulkAssignBatches(page); },
      });
      CATablePagination.register('bulk-assign-employees', {
        onPageChange: function (page) { loadBulkAssignEmployees(page); },
      });
    }
    ensureLeadQuickActionsDelegated();
    if (window.CrmDateTimePicker) {
      window.CrmDateTimePicker.initAll(document);
    }
    if (window.CA_STATE_CITY) {
      window.CA_STATE_CITY.initAllPairs(document);
    }
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
    enhanceEntityLookups();
    initResetPasswordEmployeeLookup();
  }

  function applyListingFetchBody(key, body, extra, options) {
    options = options || {};
    var cfg = CA_LISTING_SEARCH.REGISTRY[key];
    var listingGen = 0;
    if (key === 'ca_masters') {
      listingGen = options.fromCache ? _listingLoadGeneration : ++_listingLoadGeneration;
    }
    var parsed = CA_LISTING_SEARCH.unwrapListingBody(body, cfg.itemsKey || 'items');
    var items = parsed.items || [];

    if (key === 'ca_masters') {
      if (listingGen !== _listingLoadGeneration) return parsed;
      var leads = items.map(mapLeadRecord);
      window._listingLeadsPage = leads;
      realLeadsLoaded = true;
      enrichLeadsWithAssignments();
      if (document.getElementById('leads-data-table')) renderLeadsTable(leads);
      if (document.getElementById('kanban-board') && isLeadsPipelineTabActive()) {
        loadKanbanLeads();
      }
      var camCtx = getCaMasterTableContext();
      if (document.getElementById('ca-master-data-table')) renderCaMasterTable(leads, 'ca-master-data-table');
      if (document.getElementById('ca-master-new-data-table') && document.querySelector('.ca-tab-panel[data-panel="new"].active')) {
        renderCaMasterTable(leads, 'ca-master-new-data-table');
      }
      var paginationSlot = null;
      if (document.getElementById('leads-data-table') && document.getElementById('leads-pagination-slot') && !document.querySelector('.cam-page')) {
        paginationSlot = 'leads-pagination-slot';
      } else if (document.getElementById(camCtx.paginationSlot)) {
        paginationSlot = camCtx.paginationSlot;
      }
      var paginationTableId = document.getElementById('leads-table')
        ? 'leads-table'
        : (document.getElementById(camCtx.tableId) ? camCtx.tableId : 'leads-data-table');
      applyListingPagination(key, paginationTableId, body, paginationSlot);
      if (document.getElementById('leads-table')) {
        CA_LISTING_SEARCH.bindSortableHeaders(key, 'leads-table', { 1: 'firm_name', 2: 'ca_name', 7: 'status' });
      }
      if (document.getElementById(camCtx.tableId)) {
        CA_LISTING_SEARCH.bindSortableHeaders(key, camCtx.tableId, { 1: 'firm_name', 2: 'ca_name', 3: 'team_size', 4: 'last_activity_at', 12: 'status', 15: 'updated_at' });
      }
    } else if (key === 'employees') {
      var employees = items.map(mapEmployeeRecord);
      window.realEmployees = employees;
      realEmployeesLoaded = true;
      renderEmployeesTable(employees);
      populateAssignmentExecutiveFilter();
      if (document.getElementById('leaderboard')) renderLeaderboard();
      applyListingPagination(key, cfg.tableId, body);
    } else if (key === 'lead_assignments') {
      window.realAssignments = items;
      renderAssignmentTable(items, parsed.pagination);
      renderAssignmentKpis();
      applyListingPagination(key, cfg.tableId, body, 'assignment-pagination-slot');
      customizeAssignmentPaginationSummary(parsed.pagination);
    } else if (key === 'follow_ups') {
      window.realFollowUps = items;
      realFollowUpsLoaded = true;
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
      applyListingPagination(key, cfg.tableId, body, 'assignment-history-pagination-slot');
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
    } else if (key === 'sales_list') {
      renderSalesListTable(items);
      applyListingPagination(key, cfg.tableId, body);
      CA_LISTING_SEARCH.bindSortableHeaders(key, 'sales-list-table', {
        0: 'serial_number', 1: 'sale_month', 2: 'points', 3: 'customer_name', 4: 'firm_name',
        6: 'mobile_no', 7: 'city_name', 8: 'plan_purchased', 9: 'purchase_date', 10: 'cooling_period_days',
        11: 'expiry_date', 12: 'total_amount', 13: 'amount_received', 14: 'balance_amount',
        15: 'invoice_number', 16: 'payment_status',
      });
    } else if (key === 'states') {
      window.realStates = items;
      renderMasterTables();
      applyListingPagination(key, cfg.tableId, body);
    } else if (key === 'cities') {
      window.realCitiesCache = items;
      var citiesEl = document.getElementById('master-cities-table');
      if (citiesEl) {
        citiesEl.innerHTML = items.length ? items.map(function (c) {
          return '<tr class="ca-table-row' + (c.is_active === false ? ' opacity-70' : '') + '">' +
            '<td class="font-medium">' + escapeHtml(c.city_name) + masterStatusBadge(c) + '</td>' +
            '<td>' + escapeHtml(c.state_name || (c.state && c.state.state_name) || '—') + '</td>' +
            '<td>—</td>' +
            '<td class="text-caption">' + formatRelativeDate(c.created_at) + '</td>' +
            masterActionCell('city', c.city_id) +
          '</tr>';
        }).join('') : '<tr><td colspan="5" class="text-center text-slate-500 p-4">No cities yet.</td></tr>';
      }
      applyListingPagination(key, cfg.tableId, body);
    }

    return parsed;
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
      if (isMasterDataHub()) {
        syncCamSegmentState();
        var camSeg = getLeadFilter();
        if (camSeg && camSeg !== 'all') extra.segment = camSeg;
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

    var cachedListing = readListingPageCache(key, extra);
    var tableHasRows = false;
    if (cfg.tableId) {
      var listingTable = document.getElementById(cfg.tableId);
      if (listingTable) {
        tableHasRows = !!listingTable.querySelector('tbody tr:not(.crm-table-loading-row):not(.crm-table-empty-row)');
      }
    }
    if (cachedListing && cachedListing.body && !tableHasRows) {
      applyListingFetchBody(key, cachedListing.body, extra, { fromCache: true });
    }

    return apiFetch(cfg.endpoint + listingPageQuery(key, extra))
      .then(function (body) {
        writeListingPageCache(key, extra, body);
        return applyListingFetchBody(key, body, extra);
      })
      .catch(function (error) {
        toast(error && error.message ? error.message : 'Unable to load data.', 'error');
        return null;
      });
  }

  var salesListOptionsCache = null;

  function readSalesListColumnFilters() {
    var root = document.getElementById('sales-list-module');
    if (!root) return {};
    var filters = {};
    root.querySelectorAll('[data-col-filter-group="sales_list"][data-col-filter]').forEach(function (input) {
      var key = input.getAttribute('data-col-filter');
      if (!key) return;
      var value = (input.value || '').trim();
      if (value) filters[key] = value;
    });
    root.querySelectorAll('[data-col-filter-group="sales_list"][data-col-filter-min]').forEach(function (input) {
      var key = input.getAttribute('data-col-filter-min');
      if (!key) return;
      var value = (input.value || '').trim();
      if (value) filters[key] = value;
    });
    root.querySelectorAll('[data-col-filter-group="sales_list"][data-col-filter-max]').forEach(function (input) {
      var key = input.getAttribute('data-col-filter-max');
      if (!key) return;
      var value = (input.value || '').trim();
      if (value) filters[key] = value;
    });
    return filters;
  }

  function applySalesListFilters() {
    if (!window.CA_LISTING_SEARCH) return;
    CA_LISTING_SEARCH.setState('sales_list', {
      page: 1,
      filters: readSalesListColumnFilters(),
    });
    reloadListing('sales_list');
  }

  function renderSalesListTable(items) {
    var el = document.getElementById('sales-list-data-table');
    if (!el) return;
    items = items || [];
    var canEdit = canEditSalesList();
    var canHistory = canViewSalesListHistory();
    if (!items.length) {
      el.innerHTML = '<tr><td colspan="20" class="text-center text-slate-500 p-6">No sales records yet. Converted leads will appear here automatically.</td></tr>';
      return;
    }
    el.innerHTML = items.map(function (row) {
      var actions = '';
      if (canEdit) {
        actions += '<button type="button" class="crm-row-action-btn" data-sales-edit="' + escapeHtml(String(row.id)) + '" title="Edit record" aria-label="Edit record"><i data-lucide="pencil" class="h-4 w-4"></i></button>';
      }
      if (canHistory) {
        actions += '<button type="button" class="crm-row-action-btn" data-sales-history="' + escapeHtml(String(row.id)) + '" title="View edit history" aria-label="View edit history"><i data-lucide="history" class="h-4 w-4"></i></button>';
      }
      if (!actions) actions = '<span class="text-slate-400 text-xs">—</span>';
      return '<tr class="ca-table-row crm-table-row" data-sales-id="' + escapeHtml(String(row.id)) + '">' +
        '<td class="sticky-left crm-td-num">' + escapeHtml(String(row.serial_number || '—')) + '</td>' +
        '<td class="crm-td-date">' + escapeHtml(row.sale_month || '—') + '</td>' +
        '<td class="crm-td-num">' + escapeHtml(String(row.points != null ? row.points : '—')) + '</td>' +
        '<td class="sticky-left-2 crm-td-person font-medium text-slate-900">' + previewTextCell(row.customer_name, 'crm-ca-cell') + '</td>' +
        '<td class="crm-td-firm">' + previewTextCell(row.firm_name, 'crm-firm-cell') + '</td>' +
        '<td class="crm-td-person">' + escapeHtml(row.reference_name || '—') + '</td>' +
        '<td class="crm-td-mobile">' + camPhoneCell(row.mobile_no) + '</td>' +
        '<td class="crm-td-geo">' + escapeHtml(row.city_name || '—') + '</td>' +
        '<td class="crm-td-source">' + escapeHtml(row.plan_purchased || '—') + '</td>' +
        '<td class="crm-td-date">' + escapeHtml(row.purchase_date || '—') + '</td>' +
        '<td class="crm-td-num">' + escapeHtml(String(row.cooling_period_days != null ? row.cooling_period_days + ' days' : '—')) + '</td>' +
        '<td class="crm-td-date">' + escapeHtml(row.expiry_date || '—') + '</td>' +
        '<td class="crm-td-num">' + escapeHtml(formatSalesCurrency(row.total_amount)) + '</td>' +
        '<td class="crm-td-num">' + escapeHtml(formatSalesCurrency(row.amount_received)) + '</td>' +
        '<td class="crm-td-num">' + escapeHtml(formatSalesCurrency(row.balance_amount)) + '</td>' +
        '<td class="crm-td-mono">' + escapeHtml(row.invoice_number || '—') + '</td>' +
        '<td class="crm-td-status">' + salesPaymentBadge(row.payment_status) + '</td>' +
        '<td class="crm-td-person">' + escapeHtml(row.employee_name || '—') + '</td>' +
        '<td class="crm-td-person">' + escapeHtml(row.manager_name || '—') + '</td>' +
        '<td class="sticky-right crm-td-actions crm-td-actions--cluster">' + actions + '</td>' +
      '</tr>';
    }).join('');
    icons();
  }

  function populateSalesListFilterOptions(options) {
    options = options || salesListOptionsCache || {};

    function fillSelect(id, emptyLabel, items, labelFn) {
      var select = document.getElementById(id);
      if (!select) return;
      var current = select.value;
      select.innerHTML = '<option value="">' + escapeHtml(emptyLabel) + '</option>' +
        (items || []).map(function (item) {
          var value = String(item);
          var label = labelFn ? labelFn(item) : value;
          return '<option value="' + escapeHtml(value) + '">' + escapeHtml(label) + '</option>';
        }).join('');
      if (current) select.value = current;
    }

    fillSelect('sales-filter-plan', 'All plans', options.plans);
    fillSelect('sales-filter-payment-status', 'All statuses', options.payment_statuses);
    fillSelect('sales-filter-month', 'All months', options.sale_months);
    fillSelect('sales-filter-executive', 'All executives', options.executives);
    fillSelect('sales-filter-manager', 'All managers', options.managers);
    fillSelect('sales-filter-cooling', 'All periods', options.cooling_periods, function (days) {
      return days + ' days';
    });
  }

  function fillSalesListChoiceSelect(selectId, choices, selectedId) {
    var select = document.getElementById(selectId);
    if (!select) return;
    enhanceEntityLookups(select.parentElement || document);
    if (!selectedId) {
      if (window.CrmEntityLookup) window.CrmEntityLookup.setValue(select, '', null);
      return;
    }
    var match = (choices || []).find(function (choice) {
      return String(choice.id) === String(selectedId);
    });
    var record = match ? { employee_id: String(match.id), name: match.name || String(match.id) } : null;
    if (window.CrmEntityLookup) {
      window.CrmEntityLookup.setValue(select, String(selectedId), record);
    } else {
      select.value = String(selectedId);
    }
  }

  function addMonthsToDateString(dateStr, months) {
    if (!dateStr) return '';
    var parts = String(dateStr).split('-');
    if (parts.length !== 3) return '';
    var year = parseInt(parts[0], 10);
    var month = parseInt(parts[1], 10) - 1;
    var day = parseInt(parts[2], 10);
    var date = new Date(year, month + Number(months || 0), day);
    var y = date.getFullYear();
    var m = String(date.getMonth() + 1).padStart(2, '0');
    var d = String(date.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
  }

  function previewSalesListPaymentStatus(total, received, expiryDate) {
    var balance = Math.max(0, Math.round((Number(total || 0) - Number(received || 0)) * 100) / 100);
    if (balance <= 0 && Number(total || 0) > 0) return 'Paid';
    if (Number(received || 0) > 0 && balance > 0) {
      if (expiryDate) {
        var expiry = new Date(expiryDate + 'T23:59:59');
        if (!isNaN(expiry.getTime()) && expiry < new Date()) return 'Overdue';
      }
      return 'Partial';
    }
    if (expiryDate && Number(total || 0) > 0) {
      var exp = new Date(expiryDate + 'T23:59:59');
      if (!isNaN(exp.getTime()) && exp < new Date()) return 'Overdue';
    }
    return 'Pending';
  }

  function syncSalesListEditPreview(applyPlanDefaults) {
    var options = salesListOptionsCache || {};
    var plan = (document.getElementById('sales-list-edit-plan') || {}).value || '';
    var purchaseDate = (document.getElementById('sales-list-edit-purchase-date') || {}).value || '';
    var planConfig = (options.plan_configs || {})[plan] || {};
    var coolingInput = document.getElementById('sales-list-edit-cooling');
    var pointsInput = document.getElementById('sales-list-edit-points');

    if (applyPlanDefaults && planConfig) {
      if (coolingInput && planConfig.cooling_period_days != null) {
        coolingInput.value = String(planConfig.cooling_period_days);
      }
      if (pointsInput && planConfig.points != null) {
        pointsInput.value = String(planConfig.points);
      }
    }

    var expiryInput = document.getElementById('sales-list-edit-expiry');
    if (expiryInput) {
      var expiry = purchaseDate && planConfig.duration_months
        ? addMonthsToDateString(purchaseDate, planConfig.duration_months)
        : '';
      expiryInput.value = expiry || '—';
    }

    var total = parseFloat((document.getElementById('sales-list-edit-total') || {}).value || '0');
    var received = parseFloat((document.getElementById('sales-list-edit-received') || {}).value || '0');
    var balance = Math.max(0, Math.round((total - received) * 100) / 100);
    var balanceInput = document.getElementById('sales-list-edit-balance');
    if (balanceInput) balanceInput.value = formatSalesCurrency(balance);

    var statusEl = document.getElementById('sales-list-edit-status');
    if (statusEl) {
      var expiryDate = expiryInput && expiryInput.value !== '—' ? expiryInput.value : '';
      statusEl.innerHTML = salesPaymentBadge(previewSalesListPaymentStatus(total, received, expiryDate));
    }
  }

  function bindSalesListEditPreview() {
    var form = document.getElementById('sales-list-edit-form');
    if (!form || form._salesPreviewBound) return;
    form._salesPreviewBound = true;
    form.addEventListener('input', function (e) {
      if (!e.target || !e.target.id) return;
      if (e.target.id === 'sales-list-edit-plan') {
        syncSalesListEditPreview(true);
        return;
      }
      if (['sales-list-edit-purchase-date', 'sales-list-edit-total', 'sales-list-edit-received', 'sales-list-edit-cooling', 'sales-list-edit-points'].indexOf(e.target.id) !== -1) {
        syncSalesListEditPreview(false);
      }
    });
    form.addEventListener('change', function (e) {
      if (!e.target || !e.target.id) return;
      if (e.target.id === 'sales-list-edit-plan' || e.target.id === 'sales-list-edit-purchase-date') {
        syncSalesListEditPreview(e.target.id === 'sales-list-edit-plan');
      }
    });
  }

  function openSalesListEditModal(id) {
    if (!canEditSalesList()) {
      toast('You do not have permission to edit sales records.', 'error');
      return;
    }
    apiFetch('/sales-list/' + encodeURIComponent(id))
      .then(function (body) {
        var row = body.data || {};
        var modal = document.getElementById('modal-sales-list-edit');
        if (!modal) return;
        document.getElementById('sales-list-edit-id').value = row.id || '';
        document.getElementById('sales-list-edit-serial-preview').textContent = row.serial_number != null ? String(row.serial_number) : '—';
        document.getElementById('sales-list-edit-invoice-preview').textContent = row.invoice_number || '—';
        document.getElementById('sales-list-edit-points').value = row.points != null ? row.points : '';
        document.getElementById('sales-list-edit-customer').value = row.customer_name || '';
        document.getElementById('sales-list-edit-firm').value = row.firm_name || '';
        document.getElementById('sales-list-edit-reference').value = row.reference_name || '';
        document.getElementById('sales-list-edit-mobile').value = row.mobile_no || '';
        document.getElementById('sales-list-edit-city').value = row.city_name || '';
        document.getElementById('sales-list-edit-purchase-date').value = row.purchase_date || '';
        document.getElementById('sales-list-edit-cooling').value = row.cooling_period_days != null ? row.cooling_period_days : '';
        document.getElementById('sales-list-edit-total').value = row.total_amount != null ? row.total_amount : '';
        document.getElementById('sales-list-edit-received').value = row.amount_received != null ? row.amount_received : '';
        document.getElementById('sales-list-edit-invoice').value = row.invoice_number || '';
        document.getElementById('sales-list-edit-notes').value = row.notes || '';
        var planSelect = document.getElementById('sales-list-edit-plan');
        if (planSelect) {
          planSelect.innerHTML = (salesListOptionsCache && salesListOptionsCache.plans || []).map(function (plan) {
            return '<option value="' + escapeHtml(plan) + '"' + (plan === row.plan_purchased ? ' selected' : '') + '>' + escapeHtml(plan) + '</option>';
          }).join('');
        }
        fillSalesListChoiceSelect('sales-list-edit-executive', (salesListOptionsCache || {}).executive_choices, row.employee_id);
        fillSalesListChoiceSelect('sales-list-edit-manager', (salesListOptionsCache || {}).manager_choices, row.manager_id);
        bindSalesListEditPreview();
        syncSalesListEditPreview(false);
        openExclusiveCrmModal(modal);
        icons();
      })
      .catch(function (err) {
        toast(err.message || 'Unable to load sales record', 'error');
      });
  }

  function openSalesListHistoryModal(id) {
    if (!canViewSalesListHistory()) {
      toast('Only Super Admin can view edit history.', 'error');
      return;
    }
    var modal = document.getElementById('modal-sales-list-history');
    var bodyEl = document.getElementById('sales-list-history-body');
    var subtitle = document.getElementById('sales-list-history-subtitle');
    if (!modal || !bodyEl) return;
    bodyEl.innerHTML = '<tr><td colspan="5" class="text-center text-slate-500 p-4">Loading history…</td></tr>';
    openExclusiveCrmModal(modal);
    apiFetch('/sales-list/' + encodeURIComponent(id) + '/history')
      .then(function (resp) {
        var items = resp.data || [];
        if (subtitle) subtitle.textContent = items.length ? 'Showing ' + items.length + ' change' + (items.length === 1 ? '' : 's') + ' for this record.' : 'No edits recorded yet for this sales record.';
        if (!items.length) {
          bodyEl.innerHTML = '<tr><td colspan="5" class="text-center text-slate-500 p-6">No edit history yet.</td></tr>';
          return;
        }
        bodyEl.innerHTML = items.map(function (item) {
          return '<tr class="ca-table-row">' +
            '<td class="crm-td-date whitespace-nowrap">' + escapeHtml(formatDateTime(item.edited_at)) + '</td>' +
            '<td class="crm-td-person">' + escapeHtml(item.edited_by || 'System') + '</td>' +
            '<td class="crm-td-source">' + escapeHtml(item.field_label || item.field_name || '—') + '</td>' +
            '<td class="text-slate-600">' + escapeHtml(item.old_value || '—') + '</td>' +
            '<td class="text-slate-900 font-medium">' + escapeHtml(item.new_value || '—') + '</td>' +
          '</tr>';
        }).join('');
      })
      .catch(function (err) {
        bodyEl.innerHTML = '<tr><td colspan="5" class="text-center text-red-600 p-4">' + escapeHtml(err.message || 'Unable to load history') + '</td></tr>';
      });
  }

  function initSalesListPage() {
    if (!document.getElementById('sales-list-data-table')) return;
    enhanceEntityLookups(document.getElementById('modal-sales-list-edit') || document);

    apiFetch('/sales-list/options')
      .then(function (body) {
        salesListOptionsCache = body.data || {};
        populateSalesListFilterOptions(salesListOptionsCache);
      })
      .catch(function () { /* optional */ });

    reloadListing('sales_list');

    var root = document.getElementById('sales-list-module');
    if (root && !root._salesFiltersBound) {
      root._salesFiltersBound = true;
      var debounceTimer;
      var searchTimer;
      root.addEventListener('input', function (e) {
        if (e.target.id === 'sales-list-search') {
          clearTimeout(searchTimer);
          searchTimer = setTimeout(function () {
            if (!window.CA_LISTING_SEARCH) return;
            CA_LISTING_SEARCH.setState('sales_list', {
              search: (e.target.value || '').trim(),
              page: 1,
            });
            reloadListing('sales_list');
          }, 300);
          return;
        }
        if (!e.target.matches('[data-col-filter-group="sales_list"]')) return;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(applySalesListFilters, 300);
      });
      root.addEventListener('change', function (e) {
        if (!e.target.matches('[data-col-filter-group="sales_list"]')) return;
        applySalesListFilters();
      });
      root.addEventListener('click', function (e) {
        if (e.target.closest('#sales-filter-reset')) {
          root.querySelectorAll('[data-col-filter-group="sales_list"]').forEach(function (input) {
            input.value = '';
          });
          var searchEl = document.getElementById('sales-list-search');
          if (searchEl) searchEl.value = '';
          if (window.CA_LISTING_SEARCH) {
            CA_LISTING_SEARCH.setState('sales_list', { search: '', page: 1, filters: {} });
          }
          reloadListing('sales_list');
          return;
        }
        var editBtn = e.target.closest('[data-sales-edit]');
        if (editBtn) openSalesListEditModal(editBtn.getAttribute('data-sales-edit'));
        var historyBtn = e.target.closest('[data-sales-history]');
        if (historyBtn) openSalesListHistoryModal(historyBtn.getAttribute('data-sales-history'));
      });
    }

    var searchInput = document.getElementById('sales-list-search');
    if (searchInput && window.CA_LISTING_SEARCH) {
      var salesSearchState = CA_LISTING_SEARCH.getState('sales_list');
      if (salesSearchState && salesSearchState.search) searchInput.value = salesSearchState.search;
    }

    var exportCsvBtn = document.getElementById('sales-list-export-csv');
    if (exportCsvBtn && !exportCsvBtn._bound) {
      exportCsvBtn._bound = true;
      exportCsvBtn.addEventListener('click', function () {
        if (window.CA_LISTING_SEARCH) CA_LISTING_SEARCH.exportListing('sales_list');
      });
    }
    var exportPdfBtn = document.getElementById('sales-list-export-pdf');
    if (exportPdfBtn && !exportPdfBtn._bound) {
      exportPdfBtn._bound = true;
      exportPdfBtn.addEventListener('click', function () {
        var qs = window.CA_LISTING_SEARCH ? CA_LISTING_SEARCH.buildQueryString('sales_list').replace(/^\?/, '') : '';
        window.location.href = '/sales-list/export?format=pdf' + (qs ? '&' + qs : '');
      });
    }

    var saveBtn = document.getElementById('sales-list-edit-save');
    if (saveBtn && !saveBtn._bound) {
      saveBtn._bound = true;
      saveBtn.addEventListener('click', function () {
        if (!canEditSalesList()) {
          toast('You do not have permission to edit sales records.', 'error');
          return;
        }
        var id = document.getElementById('sales-list-edit-id').value;
        if (!id) return;
        var employeeVal = document.getElementById('sales-list-edit-executive').value;
        var managerVal = document.getElementById('sales-list-edit-manager').value;
        apiFetch('/sales-list/' + encodeURIComponent(id), {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            points: parseInt(document.getElementById('sales-list-edit-points').value || '0', 10),
            customer_name: document.getElementById('sales-list-edit-customer').value,
            firm_name: document.getElementById('sales-list-edit-firm').value,
            reference_name: document.getElementById('sales-list-edit-reference').value,
            mobile_no: document.getElementById('sales-list-edit-mobile').value,
            city_name: document.getElementById('sales-list-edit-city').value,
            plan_purchased: document.getElementById('sales-list-edit-plan').value,
            purchase_date: document.getElementById('sales-list-edit-purchase-date').value,
            cooling_period_days: parseInt(document.getElementById('sales-list-edit-cooling').value || '0', 10),
            total_amount: parseFloat(document.getElementById('sales-list-edit-total').value || '0'),
            amount_received: parseFloat(document.getElementById('sales-list-edit-received').value || '0'),
            invoice_number: document.getElementById('sales-list-edit-invoice').value,
            employee_id: employeeVal ? parseInt(employeeVal, 10) : null,
            manager_id: managerVal ? parseInt(managerVal, 10) : null,
            notes: document.getElementById('sales-list-edit-notes').value,
          }),
        }).then(function () {
          closeModal(document.getElementById('modal-sales-list-edit'));
          toast('Sales record updated', 'success');
          reloadListing('sales_list');
        }).catch(function (err) {
          toast(err.message || 'Unable to update sales record', 'error');
        });
      });
    }

    document.querySelectorAll('[data-close-sales-list-history]').forEach(function (btn) {
      if (btn._bound) return;
      btn._bound = true;
      btn.addEventListener('click', function () {
        closeModal(document.getElementById('modal-sales-list-history'));
      });
    });

    document.querySelectorAll('[data-close-sales-list-edit]').forEach(function (btn) {
      if (btn._bound) return;
      btn._bound = true;
      btn.addEventListener('click', function () {
        closeModal(document.getElementById('modal-sales-list-edit'));
      });
    });
  }

  return {
    init: init,
    preloadDashboardMetrics: preloadDashboardMetrics,
    apiFetch: apiFetch,
    onPage: onPage,
    refreshAll: refreshAll,
    openModal: openModal,
    openExclusiveCrmModal: openExclusiveCrmModal,
    submitLeadForm: submitLeadForm,
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
    initEmailConfigurationPage: initEmailConfigurationPage,
    initRolesPermissionsPage: initRolesPermissionsPage,
    initSettingsEmailTemplatesPage: initSettingsEmailTemplatesPage,
    initSettingsWhatsAppTemplatesPage: initSettingsWhatsAppTemplatesPage,
    initSettingsGoogleApiPage: initSettingsGoogleApiPage,
    initDemoProvidersSettingsPage: initDemoProvidersSettingsPage,
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
    initSalesListPage: initSalesListPage,
    applyFollowupTypeFilter: applyFollowupTypeFilter,
    loadDemoHistory: loadDemoHistory,
    loadFollowupActivityTimeline: loadFollowupActivityTimeline,
    initFollowupActivityTimeline: initFollowupActivityTimeline,
    renderFollowupActivityTimelineItems: renderFollowupActivityTimelineItems,
    loadRecycleBin: loadRecycleBin,
    loadKanbanLeads: loadKanbanLeads,
    loadLeadSegmentCounts: loadLeadSegmentCounts,
    renderLeaderboard: renderLeaderboard,
    renderEmployeesPerformanceTable: renderEmployeesPerformanceTable,
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
    openChangeLoginEmailModal: openChangeLoginEmailModal,
    populateResetPasswordEmployeeSelect: populateResetPasswordEmployeeSelect,
    configureEmployeeCrmRoleSelect: configureEmployeeCrmRoleSelect,
    enhanceEntityLookups: enhanceEntityLookups,
    mapLeadRecord: mapLeadRecord,
    mapEmployeeRecord: mapEmployeeRecord,
  };
})();
