/* CA Cloud Desk — Tickets module page (Phase 5 frontend) */
(function () {
  'use strict';

  var LISTING_KEY = 'support_tickets';
  var LOOKUP_NOT_CONFIGURED = 'CA Cloud Desk organization lookup is not configured yet.';

  var state = {
    page: 1,
    perPage: 10,
    search: '',
    sortBy: 'created_at',
    sortDir: 'desc',
    filters: {},
    items: [],
    pagination: null,
    metadata: null,
    loading: false,
    kpi: {},
    employees: [],
    verification: {
      correlationId: null,
      verified: false,
      email: null,
      organizationNumber: null,
      organizationName: null,
      organizations: [],
      lookupMessage: '',
    },
    activeTicketId: null,
    createBusy: false,
  };

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function toast(message, type) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type || 'info');
      return;
    }
    if (window.CA_CRM && typeof window.CA_CRM.toast === 'function') {
      window.CA_CRM.toast(message, type || 'info');
    }
  }

  function apiFetch(url, options) {
    if (window.CA_CRM && typeof window.CA_CRM.apiFetch === 'function') {
      return window.CA_CRM.apiFetch(url, options);
    }
    return Promise.reject(new Error('CRM API is not ready yet. Please refresh the page.'));
  }

  function can(permission) {
    if (window.CA_RBAC && typeof window.CA_RBAC.can === 'function') {
      return window.CA_RBAC.can('tickets', permission);
    }
    return true;
  }

  function currentUser() {
    return window.__CRM_USER__ || {};
  }

  function icons() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
  }

  function formatDate(value) {
    if (!value) return '—';
    try {
      var d = new Date(value);
      if (isNaN(d.getTime())) return esc(String(value));
      return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) {
      return esc(String(value));
    }
  }

  function formatDateTime(value) {
    if (!value) return '—';
    try {
      var d = new Date(value);
      if (isNaN(d.getTime())) return esc(String(value));
      return d.toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
      });
    } catch (e) {
      return esc(String(value));
    }
  }

  function formatBytes(bytes) {
    var n = Number(bytes) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function handleApiError(err, fallback) {
    var status = err && err.status;
    var message = (err && err.message) || fallback || 'Something went wrong.';
    if (status === 401) message = 'Session expired. Please sign in again.';
    if (status === 403) message = 'You do not have permission for this action.';
    if (status === 404) message = 'Ticket or resource not found.';
    if (status === 409) message = message || 'Conflict — please refresh and try again.';
    if (status === 422) message = message || 'Please check the form values.';
    if (status === 500) message = 'Server error. Please try again.';
    if (status === 503) message = message || LOOKUP_NOT_CONFIGURED;
    toast(message, status === 403 || status === 401 ? 'warning' : 'error');
    return message;
  }

  function statusBadge(status) {
    var map = {
      open: 'ticket-badge ticket-badge--open',
      under_review: 'ticket-badge ticket-badge--review',
      closed: 'ticket-badge ticket-badge--closed',
    };
    var label = {
      open: 'Open',
      under_review: 'Under Review',
      closed: 'Closed',
    };
    var key = String(status || '').toLowerCase();
    return '<span class="' + (map[key] || 'ticket-badge') + '">' + esc(label[key] || status || '—') + '</span>';
  }

  function problemBadge(type) {
    var map = {
      issue: 'ticket-badge ticket-badge--issue',
      improvement: 'ticket-badge ticket-badge--improvement',
      new_feature: 'ticket-badge ticket-badge--feature',
    };
    var label = {
      issue: 'Issue',
      improvement: 'Improvement',
      new_feature: 'New Feature',
    };
    var key = String(type || '').toLowerCase();
    return '<span class="' + (map[key] || 'ticket-badge') + '">' + esc(label[key] || type || '—') + '</span>';
  }

  function priorityBadge(priority) {
    var map = {
      low: 'ticket-badge ticket-badge--prio-low',
      normal: 'ticket-badge ticket-badge--prio-normal',
      high: 'ticket-badge ticket-badge--prio-high',
      urgent: 'ticket-badge ticket-badge--prio-urgent',
    };
    var label = {
      low: 'Low',
      normal: 'Normal',
      high: 'High',
      urgent: 'Urgent',
    };
    var key = String(priority || '').toLowerCase();
    return '<span class="' + (map[key] || 'ticket-badge') + '">' + esc(label[key] || priority || '—') + '</span>';
  }

  function buildQuery(extra) {
    var params = new URLSearchParams();
    var filters = Object.assign({}, state.filters, extra || {});
    var search = state.search || '';

    // Text filters without backend exact config ride on global search.
    ['ticket_number', 'customer_name', 'mobile_number'].forEach(function (key) {
      if (filters[key]) {
        if (!search) search = String(filters[key]);
        else if (search.indexOf(String(filters[key])) === -1) search += ' ' + filters[key];
        delete filters[key];
      }
    });

    // Updated-date range is not supported by listing date_column (created_at only).
    delete filters.updated_from;
    delete filters.updated_to;

    if (search) params.set('search', search);
    params.set('page', String(state.page));
    params.set('per_page', String(state.perPage));
    if (state.sortBy) params.set('sort_by', state.sortBy);
    if (state.sortDir) params.set('sort_dir', state.sortDir);
    Object.keys(filters).forEach(function (key) {
      var val = filters[key];
      if (val !== null && val !== undefined && val !== '') params.set(key, String(val));
    });
    return '?' + params.toString();
  }

  function unwrapListing(body) {
    var data = body && body.data !== undefined ? body.data : body;
    var items = (data && data.items) || [];
    var pagination = (data && data.pagination) || null;
    return { items: items, pagination: pagination, raw: body };
  }

  function setBusy(btn, busy, label) {
    if (!btn) return;
    btn.disabled = !!busy;
    if (label) btn.setAttribute('data-busy-label', label);
  }

  function loadMetadata() {
    return apiFetch('/tickets/metadata')
      .then(function (body) {
        state.metadata = (body && body.data) || body || {};
        populateCreateSelects();
        populateFilterSelects();
      })
      .catch(function () {
        state.metadata = state.metadata || {};
      });
  }

  function loadEmployees() {
    return apiFetch('/employees?all=1&per_page=200')
      .then(function (body) {
        var data = body && body.data !== undefined ? body.data : body;
        state.employees = (data && data.items) || data || [];
        if (!Array.isArray(state.employees)) state.employees = [];
        populateAssigneeSelects();
      })
      .catch(function () {
        state.employees = [];
      });
  }

  function countQuery(filters) {
    var params = new URLSearchParams(Object.assign({ page: '1', per_page: '1' }, filters || {}));
    return apiFetch('/tickets?' + params.toString())
      .then(function (body) {
        var parsed = unwrapListing(body);
        return parsed.pagination && parsed.pagination.total != null
          ? Number(parsed.pagination.total)
          : (parsed.items || []).length;
      })
      .catch(function () { return 0; });
  }

  function refreshKpis() {
    var user = currentUser();
    var employeeId = user.employee_id || user.employeeId || null;
    var raisedBy = user.id || null;

    var jobs = {
      open: countQuery({ status: 'open' }),
      under_review: countQuery({ status: 'under_review' }),
      closed: countQuery({ status: 'closed' }),
      high: countQuery({ priority: 'high' }),
      urgent: countQuery({ priority: 'urgent' }),
      my: raisedBy ? countQuery({ raised_by_user_id: raisedBy }) : Promise.resolve(0),
      assigned: employeeId ? countQuery({ assigned_to_employee_id: employeeId }) : Promise.resolve(0),
    };

    return Promise.all([
      jobs.open, jobs.under_review, jobs.closed, jobs.high, jobs.urgent, jobs.my, jobs.assigned,
    ]).then(function (vals) {
      state.kpi = {
        open: vals[0],
        under_review: vals[1],
        closed: vals[2],
        high_priority: vals[3] + vals[4],
        my_tickets: vals[5],
        assigned_to_me: vals[6],
        unread_replies: '—',
      };
      renderKpis();
    });
  }

  function renderKpis() {
    var map = {
      'ticket-kpi-open': state.kpi.open,
      'ticket-kpi-review': state.kpi.under_review,
      'ticket-kpi-closed': state.kpi.closed,
      'ticket-kpi-mine': state.kpi.my_tickets,
      'ticket-kpi-assigned': state.kpi.assigned_to_me,
      'ticket-kpi-high': state.kpi.high_priority,
      'ticket-kpi-unread': state.kpi.unread_replies,
    };
    Object.keys(map).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.textContent = map[id] == null ? '—' : String(map[id]);
    });
  }

  function loadTickets() {
    var tbody = document.getElementById('tickets-data-table');
    if (!tbody) return Promise.resolve();
    state.loading = true;
    tbody.innerHTML = '<tr><td colspan="18" class="text-center text-slate-500 p-6">' +
      '<span class="crm-inline-loading"><i data-lucide="loader-2" class="h-4 w-4 animate-spin text-brand"></i> Loading tickets…</span></td></tr>';
    icons();

    return apiFetch('/tickets' + buildQuery())
      .then(function (body) {
        var parsed = unwrapListing(body);
        state.items = parsed.items;
        state.pagination = parsed.pagination;
        renderTable(state.items);
        renderPagination();
      })
      .catch(function (err) {
        handleApiError(err, 'Unable to load tickets.');
        tbody.innerHTML = '<tr><td colspan="18" class="text-center text-slate-500 p-6">Unable to load tickets.</td></tr>';
      })
      .finally(function () {
        state.loading = false;
      });
  }

  function truncate(text, max) {
    var s = String(text || '');
    if (s.length <= max) return esc(s);
    return esc(s.slice(0, max)) + '…';
  }

  function renderTable(items) {
    var tbody = document.getElementById('tickets-data-table');
    if (!tbody) return;
    items = items || [];
    if (!items.length) {
      tbody.innerHTML = '<tr><td colspan="18" class="text-center text-slate-500 p-8">' +
        '<div class="ticket-empty"><i data-lucide="ticket" class="h-8 w-8 text-slate-300 mb-2"></i>' +
        '<p class="font-medium text-slate-600">No tickets found</p>' +
        '<p class="text-caption text-slate-400 mt-1">Create a ticket or adjust your filters.</p></div></td></tr>';
      icons();
      return;
    }

    tbody.innerHTML = items.map(function (row) {
      var id = row.id;
      var email = row.email != null && row.email !== '' ? esc(row.email) : '—';
      var desc = truncate(row.description, 60);
      var actions = '';
      if (can('view')) {
        actions += '<button type="button" class="crm-row-action-btn" data-ticket-view="' + id + '" title="View" aria-label="View"><i data-lucide="eye" class="h-4 w-4"></i></button>';
      }
      if (can('edit')) {
        actions += '<button type="button" class="crm-row-action-btn" data-ticket-status="' + id + '" title="Change status" aria-label="Change status"><i data-lucide="git-branch" class="h-4 w-4"></i></button>';
        actions += '<button type="button" class="crm-row-action-btn" data-ticket-assign="' + id + '" title="Assign" aria-label="Assign"><i data-lucide="user-plus" class="h-4 w-4"></i></button>';
      }
      if (can('delete')) {
        actions += '<button type="button" class="crm-row-action-btn text-rose-600" data-ticket-delete="' + id + '" title="Delete" aria-label="Delete"><i data-lucide="trash-2" class="h-4 w-4"></i></button>';
      }
      if (!actions) actions = '<span class="text-slate-400 text-xs">—</span>';

      return '<tr class="ca-table-row crm-table-row" data-ticket-id="' + id + '">' +
        '<td class="crm-td-num">' + esc(row.serial_number || '—') + '</td>' +
        '<td class="font-medium text-slate-900">' + esc(row.ticket_number || '—') + '</td>' +
        '<td>' + esc(row.customer_name || '—') + '</td>' +
        '<td>' + esc(row.organization_number || '—') + '</td>' +
        '<td>' + esc(row.organization_name || '—') + '</td>' +
        '<td>' + esc(row.raised_by_name || '—') + '</td>' +
        '<td class="crm-td-mobile">' + esc(row.mobile_number || '—') + '</td>' +
        '<td>' + email + '</td>' +
        '<td>' + problemBadge(row.problem_type) + '</td>' +
        '<td class="ticket-desc-cell" title="' + esc(row.description || '') + '">' + desc + '</td>' +
        '<td>' + priorityBadge(row.priority) + '</td>' +
        '<td>' + statusBadge(row.status) + '</td>' +
        '<td>' + esc(row.assigned_to_name || '—') + '</td>' +
        '<td>' + esc(row.source_system || '—') + '</td>' +
        '<td>' + esc(row.sync_status || '—') + '</td>' +
        '<td class="crm-td-date">' + formatDate(row.created_at) + '</td>' +
        '<td class="crm-td-date">' + formatDate(row.updated_at) + '</td>' +
        '<td class="crm-td-actions sticky-right"><div class="crm-row-actions">' + actions + '</div></td>' +
      '</tr>';
    }).join('');
    icons();
  }

  function renderPagination() {
    var slot = document.getElementById('tickets-pagination-slot');
    if (!slot) return;
    var p = state.pagination;
    if (!p || !p.total) {
      slot.innerHTML = '';
      return;
    }
    if (window.CATablePagination && typeof window.CATablePagination.render === 'function') {
      window.CATablePagination.render(slot, {
        page: p.current_page || state.page,
        perPage: p.per_page || state.perPage,
        total: p.total,
        lastPage: p.last_page || 1,
        onPage: function (page) {
          state.page = page;
          loadTickets();
        },
        onPerPage: function (perPage) {
          state.perPage = perPage;
          state.page = 1;
          loadTickets();
        },
      });
      return;
    }
    slot.innerHTML = '<div class="flex items-center justify-between gap-3 text-caption text-slate-500">' +
      '<span>Page ' + esc(p.current_page) + ' of ' + esc(p.last_page) + ' · ' + esc(p.total) + ' tickets</span>' +
      '<div class="flex gap-2">' +
        '<button type="button" class="btn-secondary btn-sm" id="ticket-page-prev"' + (p.current_page <= 1 ? ' disabled' : '') + '>Prev</button>' +
        '<button type="button" class="btn-secondary btn-sm" id="ticket-page-next"' + (p.current_page >= p.last_page ? ' disabled' : '') + '>Next</button>' +
      '</div></div>';
    var prev = document.getElementById('ticket-page-prev');
    var next = document.getElementById('ticket-page-next');
    if (prev) prev.addEventListener('click', function () { state.page = Math.max(1, state.page - 1); loadTickets(); });
    if (next) next.addEventListener('click', function () { state.page += 1; loadTickets(); });
  }

  function populateFilterSelects() {
    var meta = state.metadata || {};
    fillSelect('ticket-filter-problem-type', meta.problem_types || ['issue', 'improvement', 'new_feature'], 'All problem types');
    fillSelect('ticket-filter-priority', meta.priorities || ['low', 'normal', 'high', 'urgent'], 'All priorities');
    fillSelect('ticket-filter-status', meta.statuses || ['open', 'under_review', 'closed'], 'All statuses');
    fillSelect('ticket-filter-source', meta.source_systems || ['crm', 'ca_cloud_desk'], 'All sources');
    fillSelect('ticket-filter-sync', meta.sync_statuses || ['pending', 'synced', 'failed'], 'All sync statuses');
  }

  function populateCreateSelects() {
    var meta = state.metadata || {};
    fillSelect('ticket-create-problem-type', meta.problem_types || ['issue', 'improvement', 'new_feature'], 'Select problem type', true);
    fillSelect('ticket-create-priority', meta.priorities || ['low', 'normal', 'high', 'urgent'], null, true, 'normal');
  }

  function populateAssigneeSelects() {
    var opts = state.employees.map(function (e) {
      return { value: e.employee_id, label: e.name || ('Employee #' + e.employee_id) };
    });
    fillSelectOptions('ticket-filter-assignee', opts, 'All assignees');
    fillSelectOptions('ticket-create-assignee', opts, 'Assign to…');
    fillSelectOptions('ticket-drawer-assign-select', opts, 'Select employee');
  }

  function fillSelect(id, values, placeholder, required, selected) {
    var el = document.getElementById(id);
    if (!el) return;
    var html = placeholder != null ? '<option value="">' + esc(placeholder) + '</option>' : '';
    (values || []).forEach(function (v) {
      var val = typeof v === 'string' ? v : String(v);
      html += '<option value="' + esc(val) + '"' + (selected === val ? ' selected' : '') + '>' + esc(val.replace(/_/g, ' ')) + '</option>';
    });
    el.innerHTML = html;
    if (required) el.required = true;
  }

  function fillSelectOptions(id, options, placeholder) {
    var el = document.getElementById(id);
    if (!el) return;
    var html = '<option value="">' + esc(placeholder || 'Select') + '</option>';
    (options || []).forEach(function (o) {
      html += '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>';
    });
    el.innerHTML = html;
  }

  function readFiltersFromDom() {
    state.filters = {};
    var map = {
      'ticket-filter-problem-type': 'problem_type',
      'ticket-filter-priority': 'priority',
      'ticket-filter-status': 'status',
      'ticket-filter-assignee': 'assigned_to_employee_id',
      'ticket-filter-source': 'source_system',
      'ticket-filter-sync': 'sync_status',
      'ticket-filter-ticket-number': 'ticket_number',
      'ticket-filter-org-number': 'organization_number',
      'ticket-filter-mobile': 'mobile_number',
      'ticket-filter-customer': 'customer_name',
      'ticket-filter-created-from': 'date_from',
      'ticket-filter-created-to': 'date_to',
      'ticket-filter-updated-from': 'updated_from',
      'ticket-filter-updated-to': 'updated_to',
    };
    Object.keys(map).forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      var val = (el.value || '').trim();
      if (val) state.filters[map[id]] = val;
    });
    var searchEl = document.getElementById('ticket-search');
    state.search = searchEl ? (searchEl.value || '').trim() : '';
    var sortEl = document.getElementById('ticket-sort');
    if (sortEl && sortEl.value) {
      var parts = sortEl.value.split(':');
      state.sortBy = parts[0] || 'created_at';
      state.sortDir = parts[1] || 'desc';
    }
  }

  function applyKpiFilter(key) {
    var user = currentUser();
    state.page = 1;
    state.filters = {};
    if (key === 'open') state.filters.status = 'open';
    if (key === 'under_review') state.filters.status = 'under_review';
    if (key === 'closed') state.filters.status = 'closed';
    if (key === 'high') state.filters.priority = 'high';
    if (key === 'mine' && user.id) state.filters.raised_by_user_id = user.id;
    if (key === 'assigned' && (user.employee_id || user.employeeId)) {
      state.filters.assigned_to_employee_id = user.employee_id || user.employeeId;
    }
    syncFilterDomFromState();
    loadTickets();
  }

  function syncFilterDomFromState() {
    var reverse = {
      problem_type: 'ticket-filter-problem-type',
      priority: 'ticket-filter-priority',
      status: 'ticket-filter-status',
      assigned_to_employee_id: 'ticket-filter-assignee',
      source_system: 'ticket-filter-source',
      sync_status: 'ticket-filter-sync',
    };
    Object.keys(reverse).forEach(function (k) {
      var el = document.getElementById(reverse[k]);
      if (el) el.value = state.filters[k] || '';
    });
  }

  function setVerifyEnabled(enabled) {
    var btn = document.getElementById('ticket-org-verify-btn');
    if (btn) btn.disabled = !enabled;
  }

  function resetCreateForm() {
    var form = document.getElementById('form-ticket-create');
    if (form) form.reset();
    state.verification = {
      correlationId: null,
      verified: false,
      email: null,
      organizationNumber: null,
      organizationName: null,
      organizations: [],
      lookupMessage: '',
    };
    var orgSelect = document.getElementById('ticket-create-org-select');
    if (orgSelect) orgSelect.innerHTML = '<option value="">Search organizations first</option>';
    setOrgFields('', '', '');
    setCreateEnabled(false);
    setVerifyEnabled(false);
    var msg = document.getElementById('ticket-org-lookup-message');
    if (msg) {
      msg.textContent = '';
      msg.className = 'text-caption text-slate-500 mt-1';
    }
    populateCreateSelects();
    populateAssigneeSelects();
  }

  function setOrgFields(number, name, email) {
    var n = document.getElementById('ticket-create-org-number');
    var nm = document.getElementById('ticket-create-org-name');
    var em = document.getElementById('ticket-create-email');
    if (n) n.value = number || '';
    if (nm) nm.value = name || '';
    if (em) em.value = email || '';
  }

  function setCreateEnabled(enabled) {
    var btn = document.getElementById('ticket-create-submit');
    if (btn) btn.disabled = !enabled || !can('create');
  }

  function openCreateModal() {
    if (!can('create')) {
      toast('You do not have permission to create tickets.', 'warning');
      return;
    }
    resetCreateForm();
    setCreateEnabled(false);
    var modal = document.getElementById('modal-ticket');
    if (modal && window.CA_CRM && typeof window.CA_CRM.openModal === 'function') {
      window.CA_CRM.openModal(modal);
    } else if (modal) {
      modal.classList.add('open');
    }
    icons();
  }

  function searchOrganizations() {
    var mobileEl = document.getElementById('ticket-create-mobile');
    var mobile = mobileEl ? (mobileEl.value || '').trim() : '';
    var msg = document.getElementById('ticket-org-lookup-message');
    var btn = document.getElementById('ticket-org-search-btn');
    if (!mobile) {
      toast('Enter a mobile number first.', 'warning');
      return;
    }
    setBusy(btn, true);
    if (msg) {
      msg.textContent = 'Looking up organizations…';
      msg.className = 'text-caption text-slate-500 mt-1';
    }
    state.verification.verified = false;
    state.verification.correlationId = null;
    setCreateEnabled(false);
    setVerifyEnabled(false);
    setOrgFields('', '', '');

    apiFetch('/ticket-organizations?mobile_number=' + encodeURIComponent(mobile))
      .then(function (body) {
        var data = (body && body.data) || body || {};
        var orgs = data.organizations || data.items || [];
        state.verification.organizations = orgs;
        state.verification.correlationId = data.correlation_id || null;
        var select = document.getElementById('ticket-create-org-select');
        if (!orgs.length) {
          if (select) select.innerHTML = '<option value="">No organizations found</option>';
          if (msg) {
            msg.textContent = 'No organizations linked to this mobile number.';
            msg.className = 'text-caption text-amber-600 mt-1';
          }
          return;
        }
        if (select) {
          select.innerHTML = '<option value="">Select organization</option>' + orgs.map(function (o, idx) {
            var num = o.organization_number || o.number || '';
            var name = o.organization_name || o.name || '';
            return '<option value="' + idx + '" data-number="' + esc(num) + '" data-name="' + esc(name) + '">' +
              esc(num + (name ? ' — ' + name : '')) + '</option>';
          }).join('');
        }
        if (msg) {
          msg.textContent = 'Select an organization, then click Verify Organization.';
          msg.className = 'text-caption text-emerald-600 mt-1';
        }
      })
      .catch(function (err) {
        var status = err && err.status;
        var text = LOOKUP_NOT_CONFIGURED;
        if (status && status !== 404 && status !== 503 && err.message) text = err.message;
        if (msg) {
          msg.textContent = text;
          msg.className = 'text-caption text-amber-700 mt-1';
        }
        toast(text, 'warning');
        var select = document.getElementById('ticket-create-org-select');
        if (select) select.innerHTML = '<option value="">Lookup unavailable</option>';
      })
      .finally(function () {
        setBusy(btn, false);
      });
  }

  function onOrgSelected() {
    var select = document.getElementById('ticket-create-org-select');
    if (!select || select.value === '') {
      setOrgFields('', '', '');
      state.verification.verified = false;
      setCreateEnabled(false);
      setVerifyEnabled(false);
      return;
    }
    var opt = select.options[select.selectedIndex];
    var number = opt.getAttribute('data-number') || '';
    var name = opt.getAttribute('data-name') || '';
    state.verification.organizationNumber = number;
    state.verification.organizationName = name;
    setOrgFields(number, name, '');
    state.verification.verified = false;
    setCreateEnabled(false);
    setVerifyEnabled(!!number && !!state.verification.correlationId);
    var msg = document.getElementById('ticket-org-lookup-message');
    if (msg) {
      msg.textContent = 'Click Verify Organization to unlock verified email.';
      msg.className = 'text-caption text-slate-500 mt-1';
    }
  }

  function verifyOrganization() {
    var mobileEl = document.getElementById('ticket-create-mobile');
    var mobile = mobileEl ? (mobileEl.value || '').trim() : '';
    var orgNumber = state.verification.organizationNumber;
    var msg = document.getElementById('ticket-org-lookup-message');
    var verifyBtn = document.getElementById('ticket-org-verify-btn');
    if (!mobile || !orgNumber) return;
    if (!state.verification.correlationId) {
      toast('Search organizations first.', 'warning');
      return;
    }

    if (msg) {
      msg.textContent = 'Verifying organization…';
      msg.className = 'text-caption text-slate-500 mt-1';
    }
    setBusy(verifyBtn, true);

    apiFetch('/ticket-organizations/verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        mobile_number: mobile,
        organization_number: orgNumber,
        correlation_id: state.verification.correlationId,
      }),
    })
      .then(function (body) {
        var data = (body && body.data) || body || {};
        if (!data.verified && data.verification_status !== 'verified') {
          throw Object.assign(new Error(data.message || 'Verification failed.'), { status: 422 });
        }
        state.verification.verified = true;
        state.verification.email = data.email || data.verified_email || null;
        state.verification.correlationId = data.correlation_id || state.verification.correlationId;
        state.verification.organizationNumber = data.organization_number || orgNumber;
        state.verification.organizationName = data.organization_name || state.verification.organizationName;
        setOrgFields(
          state.verification.organizationNumber,
          state.verification.organizationName,
          state.verification.email || ''
        );
        setCreateEnabled(true);
        setVerifyEnabled(false);
        if (msg) {
          msg.textContent = 'Organization verified. Email unlocked.';
          msg.className = 'text-caption text-emerald-700 mt-1';
        }
      })
      .catch(function (err) {
        state.verification.verified = false;
        state.verification.email = null;
        setOrgFields(state.verification.organizationNumber, state.verification.organizationName, '');
        setCreateEnabled(false);
        setVerifyEnabled(true);
        var text = (err && err.status === 503) || (err && err.status === 404)
          ? LOOKUP_NOT_CONFIGURED
          : ((err && err.message) || 'Verification failed. Email hidden.');
        if (msg) {
          msg.textContent = text;
          msg.className = 'text-caption text-rose-600 mt-1';
        }
        toast(text, 'warning');
      })
      .finally(function () {
        setBusy(verifyBtn, false);
      });
  }

  function submitCreateTicket(event) {
    event.preventDefault();
    if (!can('create')) return;
    if (!state.verification.verified || !state.verification.correlationId) {
      toast('Verify organization before creating a ticket.', 'warning');
      return;
    }
    var form = document.getElementById('form-ticket-create');
    if (!form) return;
    var submitBtn = document.getElementById('ticket-create-submit');
    setBusy(submitBtn, true);
    state.createBusy = true;

    var payload = {
      customer_name: (document.getElementById('ticket-create-customer') || {}).value,
      mobile_number: (document.getElementById('ticket-create-mobile') || {}).value,
      verification_correlation_id: state.verification.correlationId,
      problem_type: (document.getElementById('ticket-create-problem-type') || {}).value,
      description: (document.getElementById('ticket-create-description') || {}).value,
      priority: (document.getElementById('ticket-create-priority') || {}).value || 'normal',
    };
    var assignee = (document.getElementById('ticket-create-assignee') || {}).value;
    if (assignee) payload.assigned_to_employee_id = Number(assignee);

    // Never send email / org fields — backend reads verified lookup.
    apiFetch('/tickets', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(function (body) {
        var ticket = (body && body.data) || body;
        toast('Ticket created successfully.', 'success');
        var fileInput = document.getElementById('ticket-create-attachment');
        var file = fileInput && fileInput.files && fileInput.files[0];
        var closeAndRefresh = function () {
          var modal = document.getElementById('modal-ticket');
          if (modal) modal.classList.remove('open');
          var overlay = document.getElementById('overlay');
          if (overlay) overlay.classList.remove('active');
          if (typeof window.setCrmScrollLock === 'function') window.setCrmScrollLock(false);
          resetCreateForm();
          refreshKpis();
          loadTickets();
          if (ticket && ticket.id) openTicketDrawer(ticket.id);
        };
        if (file && ticket && ticket.id && can('edit')) {
          return uploadAttachment(ticket.id, file).then(closeAndRefresh).catch(function (err) {
            handleApiError(err, 'Ticket created but attachment failed.');
            closeAndRefresh();
          });
        }
        closeAndRefresh();
      })
      .catch(function (err) {
        handleApiError(err, 'Unable to create ticket.');
      })
      .finally(function () {
        state.createBusy = false;
        setBusy(submitBtn, false);
        setCreateEnabled(state.verification.verified);
      });
  }

  function uploadAttachment(ticketId, file) {
    var fd = new FormData();
    fd.append('attachment', file);
    return apiFetch('/tickets/' + ticketId + '/attachments', {
      method: 'POST',
      body: fd,
    });
  }

  function openTicketDrawer(ticketId) {
    state.activeTicketId = ticketId;
    if (typeof openDetailDrawer !== 'function') {
      toast('Details view is unavailable.', 'warning');
      return;
    }
    openDetailDrawer({
      firm: 'Ticket #' + ticketId,
      fields: [],
      extraHtml: '<div class="crm-inline-loading"><i data-lucide="loader-2" class="h-5 w-5 animate-spin text-brand"></i><span>Loading ticket…</span></div>',
    });
    icons();

    apiFetch('/tickets/' + ticketId)
      .then(function (body) {
        var ticket = (body && body.data) || body;
        return Promise.all([
          Promise.resolve(ticket),
          apiFetch('/tickets/' + ticketId + '/comments').catch(function () { return { data: [] }; }),
          apiFetch('/tickets/' + ticketId + '/attachments').catch(function () { return { data: [] }; }),
          apiFetch('/tickets/' + ticketId + '/history').catch(function () { return { data: [] }; }),
        ]);
      })
      .then(function (results) {
        renderTicketDrawer(results[0], unwrapList(results[1]), unwrapList(results[2]), unwrapList(results[3]));
      })
      .catch(function (err) {
        handleApiError(err, 'Unable to load ticket.');
        if (typeof closeDetailDrawer === 'function') closeDetailDrawer();
      });
  }

  function unwrapList(body) {
    var data = body && body.data !== undefined ? body.data : body;
    return Array.isArray(data) ? data : (data && data.items) || [];
  }

  function renderTicketDrawer(ticket, comments, attachments, history) {
    var publicComments = (comments || []).filter(function (c) {
      return !c.is_internal && c.visibility !== 'internal';
    });
    var internalNotes = (comments || []).filter(function (c) {
      return c.is_internal || c.visibility === 'internal';
    });

    var emailValue = ticket.email ? esc(ticket.email) : '—';
    var fields = [
      { label: 'Ticket Number', value: esc(ticket.ticket_number) },
      { label: 'Serial', value: esc(ticket.serial_number) },
      { label: 'Status', value: statusBadge(ticket.status) },
      { label: 'Priority', value: priorityBadge(ticket.priority) },
      { label: 'Problem Type', value: problemBadge(ticket.problem_type) },
      { label: 'Customer', value: esc(ticket.customer_name) },
      { label: 'Mobile', value: esc(ticket.mobile_number) },
      { label: 'Email', value: emailValue },
      { label: 'Organization No.', value: esc(ticket.organization_number) },
      { label: 'Organization', value: esc(ticket.organization_name) },
      { label: 'Raised By', value: esc(ticket.raised_by_name) },
      { label: 'Assigned To', value: esc(ticket.assigned_to_name || '—') },
      { label: 'Source', value: esc(ticket.source_system) },
      { label: 'Sync Status', value: esc(ticket.sync_status) },
      { label: 'Created', value: formatDateTime(ticket.created_at) },
      { label: 'Updated', value: formatDateTime(ticket.updated_at) },
    ];

    var actionsHtml = '';
    if (can('edit')) {
      actionsHtml +=
        '<div class="ticket-drawer-actions">' +
          '<label class="form-label">Status</label>' +
          '<div class="flex flex-wrap gap-2 mb-3">' +
            '<button type="button" class="btn-secondary btn-sm" data-drawer-status="open">Open</button>' +
            '<button type="button" class="btn-secondary btn-sm" data-drawer-status="under_review">Under Review</button>' +
            '<button type="button" class="btn-secondary btn-sm" data-drawer-status="closed">Closed</button>' +
          '</div>' +
          '<label class="form-label" for="ticket-drawer-assign-select">Assign</label>' +
          '<div class="flex gap-2 mb-4">' +
            '<select id="ticket-drawer-assign-select" class="input-field flex-1"></select>' +
            '<button type="button" class="btn-secondary btn-sm" id="ticket-drawer-assign-btn">Assign</button>' +
          '</div>' +
        '</div>';
    }

    var conversationHtml =
      '<div class="detail-section ticket-drawer-section">' +
        '<p class="detail-section__title">Conversation</p>' +
        '<div class="ticket-thread" id="ticket-drawer-thread">' +
          (publicComments.length
            ? publicComments.map(commentHtml).join('')
            : '<p class="text-caption text-slate-400">No public replies yet.</p>') +
        '</div>' +
        (can('edit')
          ? '<div class="ticket-composer mt-3">' +
              '<textarea id="ticket-drawer-reply" class="input-field" rows="3" placeholder="Write a public reply…"></textarea>' +
              '<button type="button" class="btn-primary btn-sm mt-2" id="ticket-drawer-reply-btn">Send Reply</button>' +
            '</div>'
          : '') +
      '</div>';

    var notesHtml =
      '<div class="detail-section ticket-drawer-section">' +
        '<p class="detail-section__title">Internal Notes</p>' +
        '<div class="ticket-thread ticket-thread--internal" id="ticket-drawer-notes">' +
          (internalNotes.length
            ? internalNotes.map(commentHtml).join('')
            : '<p class="text-caption text-slate-400">No internal notes.</p>') +
        '</div>' +
        (can('edit')
          ? '<div class="ticket-composer mt-3">' +
              '<textarea id="ticket-drawer-note" class="input-field" rows="2" placeholder="Add an internal note…"></textarea>' +
              '<button type="button" class="btn-secondary btn-sm mt-2" id="ticket-drawer-note-btn">Add Internal Note</button>' +
            '</div>'
          : '') +
      '</div>';

    var attachmentsHtml =
      '<div class="detail-section ticket-drawer-section">' +
        '<p class="detail-section__title">Attachments</p>' +
        '<div id="ticket-drawer-attachments">' + renderAttachments(attachments, ticket.id) + '</div>' +
        (can('edit')
          ? '<div class="mt-3 flex flex-wrap items-center gap-2">' +
              '<input type="file" id="ticket-drawer-file" class="input-field" />' +
              '<button type="button" class="btn-secondary btn-sm" id="ticket-drawer-upload-btn">Upload</button>' +
            '</div>'
          : '') +
      '</div>';

    var historyHtml =
      '<div class="detail-section ticket-drawer-section">' +
        '<p class="detail-section__title">Status & Assignment History</p>' +
        '<div class="ticket-history">' +
          ((history || []).length
            ? history.map(function (h) {
              return '<div class="ticket-history__item">' +
                '<div class="ticket-history__meta">' + formatDateTime(h.created_at) +
                  (h.changed_by_name ? ' · ' + esc(h.changed_by_name) : '') + '</div>' +
                '<div class="ticket-history__body">' +
                  (h.from_status || h.to_status
                    ? '<div>Status: ' + esc(h.from_status || '—') + ' → ' + esc(h.to_status || '—') + '</div>'
                    : '') +
                  (h.from_priority || h.to_priority
                    ? '<div>Priority: ' + esc(h.from_priority || '—') + ' → ' + esc(h.to_priority || '—') + '</div>'
                    : '') +
                  (h.from_assigned_to_employee_id || h.to_assigned_to_employee_id
                    ? '<div>Assignee: ' + esc(h.from_assignee_name || h.from_assigned_to_employee_id || '—') +
                      ' → ' + esc(h.to_assignee_name || h.to_assigned_to_employee_id || '—') + '</div>'
                    : '') +
                  (h.notes ? '<div class="text-caption text-slate-500">' + esc(h.notes) + '</div>' : '') +
                '</div></div>';
            }).join('')
            : '<p class="text-caption text-slate-400">No history yet.</p>') +
        '</div>' +
      '</div>';

    var notifyHtml =
      '<div class="detail-section ticket-drawer-section">' +
        '<p class="detail-section__title">Notification Status</p>' +
        '<div class="grid grid-cols-2 gap-2 text-caption">' +
          '<div>Email: <strong>' + esc(ticket.notification_email_status || '—') + '</strong></div>' +
          '<div>WhatsApp: <strong>' + esc(ticket.notification_whatsapp_status || '—') + '</strong></div>' +
        '</div>' +
      '</div>';

    var descHtml =
      '<div class="detail-section ticket-drawer-section">' +
        '<p class="detail-section__title">Description</p>' +
        '<p class="text-sm text-slate-700 whitespace-pre-wrap">' + esc(ticket.description || '—') + '</p>' +
      '</div>';

    openDetailDrawer({
      firm: ticket.ticket_number || ('Ticket #' + ticket.id),
      fields: fields,
      extraHtml: descHtml + actionsHtml + conversationHtml + notesHtml + attachmentsHtml + historyHtml + notifyHtml,
    });

    populateAssigneeSelects();
    if (ticket.assigned_to_employee_id) {
      var assignSelect = document.getElementById('ticket-drawer-assign-select');
      if (assignSelect) assignSelect.value = String(ticket.assigned_to_employee_id);
    }
    bindDrawerActions(ticket);
    icons();
  }

  function commentHtml(c) {
    var internal = c.is_internal || c.visibility === 'internal';
    return '<div class="ticket-comment' + (internal ? ' ticket-comment--internal' : '') + '">' +
      '<div class="ticket-comment__head">' +
        '<strong>' + esc(c.author_name || c.author_type || 'User') + '</strong>' +
        (internal ? '<span class="ticket-badge ticket-badge--internal">Internal</span>' : '') +
        '<span class="text-caption text-slate-400">' + formatDateTime(c.created_at) + '</span>' +
      '</div>' +
      '<div class="ticket-comment__body">' + esc(c.body) + '</div>' +
    '</div>';
  }

  function renderAttachments(attachments, ticketId) {
    if (!attachments || !attachments.length) {
      return '<p class="text-caption text-slate-400">No attachments.</p>';
    }
    return '<ul class="ticket-attachment-list">' + attachments.map(function (a) {
      var downloadUrl = '/tickets/' + ticketId + '/attachments/' + a.id + '/download';
      var mime = String(a.mime_type || a.content_type || '').toLowerCase();
      var canPreview = mime.indexOf('image/') === 0 || mime === 'application/pdf';
      return '<li class="ticket-attachment-item">' +
        '<div class="min-w-0">' +
          '<div class="font-medium text-slate-800 truncate">' + esc(a.original_filename) + '</div>' +
          '<div class="text-caption text-slate-500">' +
            esc((a.uploader && a.uploader.name) || a.uploaded_by_name || 'Uploader') + ' · ' +
            esc(formatBytes(a.file_size)) + ' · ' + formatDateTime(a.created_at) +
          '</div>' +
        '</div>' +
        '<div class="flex gap-1">' +
          (canPreview
            ? '<a class="crm-row-action-btn" href="' + downloadUrl + '" target="_blank" rel="noopener" title="Preview" aria-label="Preview">' +
              '<i data-lucide="eye" class="h-4 w-4"></i></a>'
            : '') +
          '<a class="crm-row-action-btn" href="' + downloadUrl + '" title="Download" aria-label="Download">' +
            '<i data-lucide="download" class="h-4 w-4"></i></a>' +
        '</div></li>';
    }).join('') + '</ul>';
  }

  function bindDrawerActions(ticket) {
    document.querySelectorAll('[data-drawer-status]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var status = btn.getAttribute('data-drawer-status');
        changeStatus(ticket.id, status);
      });
    });
    var assignBtn = document.getElementById('ticket-drawer-assign-btn');
    if (assignBtn) {
      assignBtn.addEventListener('click', function () {
        var select = document.getElementById('ticket-drawer-assign-select');
        var employeeId = select && select.value;
        if (!employeeId) {
          toast('Select an employee to assign.', 'warning');
          return;
        }
        assignTicket(ticket.id, Number(employeeId));
      });
    }
    var replyBtn = document.getElementById('ticket-drawer-reply-btn');
    if (replyBtn) {
      replyBtn.addEventListener('click', function () {
        var body = (document.getElementById('ticket-drawer-reply') || {}).value;
        postComment(ticket.id, body, false);
      });
    }
    var noteBtn = document.getElementById('ticket-drawer-note-btn');
    if (noteBtn) {
      noteBtn.addEventListener('click', function () {
        var body = (document.getElementById('ticket-drawer-note') || {}).value;
        postComment(ticket.id, body, true);
      });
    }
    var uploadBtn = document.getElementById('ticket-drawer-upload-btn');
    if (uploadBtn) {
      uploadBtn.addEventListener('click', function () {
        var input = document.getElementById('ticket-drawer-file');
        var file = input && input.files && input.files[0];
        if (!file) {
          toast('Choose a file first.', 'warning');
          return;
        }
        setBusy(uploadBtn, true);
        uploadAttachment(ticket.id, file)
          .then(function () {
            toast('Attachment uploaded.', 'success');
            openTicketDrawer(ticket.id);
          })
          .catch(function (err) { handleApiError(err, 'Upload failed.'); })
          .finally(function () { setBusy(uploadBtn, false); });
      });
    }
  }

  function changeStatus(ticketId, status) {
    if (status === 'closed' && !window.confirm('Close this ticket? This action should be confirmed.')) {
      return;
    }
    apiFetch('/tickets/' + ticketId + '/status', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ status: status }),
    })
      .then(function () {
        toast('Status updated.', 'success');
        refreshKpis();
        loadTickets();
        openTicketDrawer(ticketId);
      })
      .catch(function (err) { handleApiError(err, 'Unable to change status.'); });
  }

  function assignTicket(ticketId, employeeId) {
    apiFetch('/tickets/' + ticketId + '/assign', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ assigned_to_employee_id: employeeId }),
    })
      .then(function () {
        toast('Ticket assigned.', 'success');
        loadTickets();
        openTicketDrawer(ticketId);
      })
      .catch(function (err) { handleApiError(err, 'Unable to assign ticket.'); });
  }

  function postComment(ticketId, body, isInternal) {
    body = (body || '').trim();
    if (!body) {
      toast('Enter a message.', 'warning');
      return;
    }
    apiFetch('/tickets/' + ticketId + '/comments', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        body: body,
        is_internal: !!isInternal,
        visibility: isInternal ? 'internal' : 'public',
        comment_type: isInternal ? 'internal_note' : 'reply',
      }),
    })
      .then(function () {
        toast(isInternal ? 'Internal note added.' : 'Reply sent.', 'success');
        openTicketDrawer(ticketId);
      })
      .catch(function (err) { handleApiError(err, 'Unable to post comment.'); });
  }

  function deleteTicket(ticketId) {
    if (!can('delete')) return;
    if (!window.confirm('Delete this ticket?')) return;
    apiFetch('/tickets/' + ticketId, { method: 'DELETE' })
      .then(function () {
        toast('Ticket deleted.', 'success');
        if (typeof closeDetailDrawer === 'function') closeDetailDrawer();
        refreshKpis();
        loadTickets();
      })
      .catch(function (err) { handleApiError(err, 'Unable to delete ticket.'); });
  }

  function promptStatus(ticketId) {
    var status = window.prompt('Enter status: open, under_review, or closed', 'under_review');
    if (!status) return;
    status = String(status).trim().toLowerCase();
    if (['open', 'under_review', 'closed'].indexOf(status) === -1) {
      toast('Invalid status.', 'warning');
      return;
    }
    changeStatus(ticketId, status);
  }

  function promptAssign(ticketId) {
    if (!state.employees.length) {
      loadEmployees().then(function () { promptAssign(ticketId); });
      return;
    }
    var list = state.employees.map(function (e) {
      return e.employee_id + ' = ' + e.name;
    }).join('\n');
    var raw = window.prompt('Assign to employee_id:\n' + list);
    if (!raw) return;
    var id = Number(raw);
    if (!id) {
      toast('Invalid employee id.', 'warning');
      return;
    }
    assignTicket(ticketId, id);
  }

  function bindPageEvents() {
    var root = document.getElementById('tickets-module');
    if (!root || root.dataset.bound === '1') return;
    root.dataset.bound = '1';

    root.addEventListener('click', function (e) {
      var t = e.target.closest('[data-ticket-view],[data-ticket-status],[data-ticket-assign],[data-ticket-delete],[data-ticket-kpi],[data-open-modal]');
      if (!t) return;
      if (t.getAttribute('data-open-modal') === 'ticket') {
        openCreateModal();
        return;
      }
      var kpi = t.getAttribute('data-ticket-kpi');
      if (kpi) {
        applyKpiFilter(kpi);
        return;
      }
      var viewId = t.getAttribute('data-ticket-view');
      if (viewId) openTicketDrawer(viewId);
      var statusId = t.getAttribute('data-ticket-status');
      if (statusId) promptStatus(statusId);
      var assignId = t.getAttribute('data-ticket-assign');
      if (assignId) promptAssign(assignId);
      var deleteId = t.getAttribute('data-ticket-delete');
      if (deleteId) deleteTicket(deleteId);
    });

    var refreshBtn = document.getElementById('ticket-refresh-btn');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        refreshKpis();
        loadTickets();
      });
    }

    var applyBtn = document.getElementById('ticket-filter-apply');
    if (applyBtn) {
      applyBtn.addEventListener('click', function () {
        readFiltersFromDom();
        state.page = 1;
        loadTickets();
      });
    }

    var resetBtn = document.getElementById('ticket-filter-reset');
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        var form = document.getElementById('ticket-filters');
        if (form) form.reset();
        state.filters = {};
        state.search = '';
        state.page = 1;
        state.sortBy = 'created_at';
        state.sortDir = 'desc';
        loadTickets();
      });
    }

    var searchEl = document.getElementById('ticket-search');
    if (searchEl) {
      var timer = null;
      searchEl.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          readFiltersFromDom();
          state.page = 1;
          loadTickets();
        }, 350);
      });
    }

    var sortEl = document.getElementById('ticket-sort');
    if (sortEl) {
      sortEl.addEventListener('change', function () {
        readFiltersFromDom();
        state.page = 1;
        loadTickets();
      });
    }
  }

  function bindCreateModalEvents() {
    var form = document.getElementById('form-ticket-create');
    if (!form || form.dataset.bound === '1') return;
    form.dataset.bound = '1';
    form.addEventListener('submit', submitCreateTicket);

    var searchBtn = document.getElementById('ticket-org-search-btn');
    if (searchBtn) searchBtn.addEventListener('click', searchOrganizations);

    var verifyBtn = document.getElementById('ticket-org-verify-btn');
    if (verifyBtn) verifyBtn.addEventListener('click', verifyOrganization);

    var orgSelect = document.getElementById('ticket-create-org-select');
    if (orgSelect) orgSelect.addEventListener('change', onOrgSelected);

    var resetBtn = document.getElementById('ticket-create-reset');
    if (resetBtn) resetBtn.addEventListener('click', resetCreateForm);
  }

  function init() {
    if (!document.getElementById('tickets-module')) return;
    bindPageEvents();
    bindCreateModalEvents();
    var createBtn = document.getElementById('ticket-create-btn');
    if (createBtn) createBtn.classList.toggle('hidden', !can('create'));

    Promise.all([loadMetadata(), loadEmployees()])
      .then(function () {
        return Promise.all([refreshKpis(), loadTickets()]);
      })
      .finally(function () {
        icons();
      });
  }

  window.CA_TICKETS = {
    init: init,
    reload: function () {
      refreshKpis();
      return loadTickets();
    },
    openCreate: openCreateModal,
    openTicket: openTicketDrawer,
  };
})();
