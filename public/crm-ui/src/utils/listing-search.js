/* global window, document */
(function () {
  'use strict';

  var DEFAULT_PER_PAGE = 10;

  var REGISTRY = {
    ca_masters: { endpoint: '/ca-masters', tableId: 'leads-data-table', altTables: ['ca-master-data-table', 'ca-master-new-data-table', 'dashboard-leads-table'] },
    employees: { endpoint: '/employees', tableId: 'employees-data-table' },
    follow_ups: { endpoint: '/follow-ups', tableId: 'followups-data-table' },
    lead_assignments: { endpoint: '/lead-assignments', tableId: 'assignment-table' },
    assignment_histories: { endpoint: '/assignment-histories', tableId: 'assignment-history-table-el' },
    activity_logs: { endpoint: '/activity-logs', tableId: 'activity-logs-table', itemsKey: 'logs' },
    states: { endpoint: '/states', tableId: 'master-states-table' },
    cities: { endpoint: '/cities', tableId: 'master-cities-table' },
    source_leads: { endpoint: '/source-leads', tableId: 'master-sources-table' },
    team_sizes: { endpoint: '/team-sizes', tableId: 'master-team-sizes-table' },
    role_masters: { endpoint: '/role-masters', tableId: 'master-roles-table' },
    consent_trackings: { endpoint: '/consent-trackings', tableId: 'consent-records-table' },
    dnd_management: { endpoint: '/dnd-management', tableId: 'dnd-records-table' },
    whatsapp_campaigns: { endpoint: '/whatsapp-campaigns', tableId: 'campaigns-grid-whatsapp', grid: true },
    email_campaigns: { endpoint: '/email-campaigns', tableId: 'campaigns-grid-email', grid: true },
    sms_campaigns: { endpoint: '/sms-campaigns', tableId: 'campaigns-grid-sms', grid: true },
    wa_message_logs: { endpoint: '/wa-message-logs', tableId: 'wa-message-logs-table' },
    email_logs: { endpoint: '/email-logs', tableId: 'email-logs-table' },
    sms_logs: { endpoint: '/sms-logs', tableId: 'sms-logs-table' },
    sales_list: { endpoint: '/sales-list', tableId: 'sales-list-data-table' },
    bulk_operations: { endpoint: '/ca-masters/bulk-operations/history', tableId: 'bulk-actions-data-table' },
  };

  window._listingState = window._listingState || {};

  function getState(key) {
    if (!window._listingState[key]) {
      window._listingState[key] = { page: 1, per_page: DEFAULT_PER_PAGE, sort_by: null, sort_dir: 'desc', filters: {}, search: '' };
    }
    return window._listingState[key];
  }

  function setState(key, patch) {
    var state = getState(key);
    Object.assign(state, patch || {});
    window._listingState[key] = state;
    return state;
  }

  function clearState(key) {
    window._listingState[key] = { page: 1, per_page: DEFAULT_PER_PAGE, sort_by: null, sort_dir: 'desc', filters: {}, search: '' };
    return window._listingState[key];
  }

  function buildQueryString(key, extra) {
    var state = getState(key);
    var params = new URLSearchParams();
    var merged = Object.assign({}, state.filters || {}, extra || {});

    if (state.search) params.set('search', state.search);
    if (state.page) params.set('page', String(state.page));
    if (state.per_page) params.set('per_page', String(state.per_page));
    if (state.sort_by) params.set('sort_by', state.sort_by);
    if (state.sort_dir) params.set('sort_dir', state.sort_dir);

    Object.keys(merged).forEach(function (field) {
      var val = merged[field];
      if (val !== null && val !== undefined && val !== '') params.set(field, String(val));
    });

    var qs = params.toString();
    return qs ? '?' + qs : '';
  }

  function buildAllQueryString(key, extra) {
    var params = new URLSearchParams(Object.assign({ all: '1' }, extra || {}));
    return '?' + params.toString();
  }

  function unwrapListingBody(body, itemsKey) {
    itemsKey = itemsKey || 'items';
    var data = body && body.data !== undefined ? body.data : body;
    if (!data) return { items: [], pagination: null, meta: null, raw: body };

    if (Array.isArray(data)) return { items: data, pagination: null, meta: null, raw: body };

    if (data[itemsKey] !== undefined) {
      var items = data[itemsKey];
      if (items && items.data && Array.isArray(items.data)) items = items.data;
      else if (!Array.isArray(items)) items = [];
      return {
        items: items,
        pagination: data.pagination || null,
        meta: data.meta || null,
        filter_options: data.filter_options || null,
        raw: body,
      };
    }

    if (Array.isArray(data.items)) {
      var list = data.items;
      if (list.length && list[0] && list[0].data) list = list.map(function (i) { return i.data || i; });
      return { items: list, pagination: data.pagination || null, meta: data.meta || null, raw: body };
    }

    return { items: [], pagination: null, meta: null, raw: body };
  }

  function renderPaginationBar(key, tableId, pagination, slotId) {
    if (!window.CATablePagination) return;

    var slot = slotId ? document.getElementById(slotId) : null;
    var wrapId = 'listing-pagination-' + key + (slotId ? '-' + slotId : '');

    if (!pagination || !pagination.total) {
      if (slot) {
        slot.innerHTML = '';
        slot.classList.add('crm-table-footer--empty');
      }
      var existing = document.getElementById(wrapId);
      if (existing) existing.remove();
      var legacy = document.getElementById('listing-pagination-' + key);
      if (legacy && !slot) legacy.remove();
      return;
    }

    var state = getState(key);
    var perPageOptions = key === 'follow_ups' && window.CATablePagination && CATablePagination.FOLLOWUP_PER_PAGE_OPTIONS
      ? CATablePagination.FOLLOWUP_PER_PAGE_OPTIONS
      : null;
    var perPage = state.per_page || pagination.per_page || CATablePagination.DEFAULT_PER_PAGE;
    if (perPageOptions && CATablePagination.normalizePerPage) {
      perPage = CATablePagination.normalizePerPage(perPage, perPageOptions);
      if (state.per_page !== perPage) {
        setState(key, { per_page: perPage });
      }
    }
    CATablePagination.renderInto(slot || slotId, {
      tableId: tableId,
      wrapId: wrapId,
      listingKey: key,
      pagination: pagination,
      perPage: perPage,
      perPageOptions: perPageOptions,
      showPerPage: true,
    });

    if (slot) {
      slot.classList.remove('listing-pagination', 'cam-pagination', 'cam-pagination--enterprise', 'assign-active__pagination');
    }
  }

  function bindSortableHeaders(key, tableId, sortMap) {
    var table = document.getElementById(tableId);
    if (!table || table._listingSortBound) return;
    table._listingSortBound = true;

    var headers = table.closest('table')?.querySelectorAll('thead th') || table.parentElement?.querySelectorAll('thead th');
    if (!headers || !headers.length) return;

    headers.forEach(function (th, idx) {
      var sortField = (sortMap && sortMap[idx]) || null;
      if (!sortField) return;
      th.style.cursor = 'pointer';
      th.title = 'Sort by ' + th.textContent.trim();
      th.addEventListener('click', function () {
        var state = getState(key);
        var nextDir = state.sort_by === sortField && state.sort_dir === 'asc' ? 'desc' : 'asc';
        setState(key, { sort_by: sortField, sort_dir: nextDir, page: 1 });
        window.CA_LISTING_SEARCH.reload(key);
      });
    });
  }

  function readLeadDrawerFilters() {
    var drawer = document.getElementById('filter-drawer');
    if (!drawer) return {};
    var selects = drawer.querySelectorAll('.grid select');
    var inputs = drawer.querySelectorAll('.grid input');
    var status = selects[2] ? selects[2].value : '';
    var teamMin = inputs[0] ? inputs[0].value : '';
    var teamMax = inputs[1] ? inputs[1].value : '';
    var software = selects[3] ? selects[3].value : '';
    var rating = selects[4] ? selects[4].value : '';
    var newly = selects[5] ? selects[5].value : '';
    var from = document.getElementById('filter-date-from')?.value || '';
    var to = document.getElementById('filter-date-to')?.value || '';
    var prefs = window._leadFilterPrefs || {};
    if (!from && prefs.dateFrom) from = prefs.dateFrom;
    if (!to && prefs.dateTo) to = prefs.dateTo;

    var filters = {};
    var stateId = document.getElementById('filter-state')?.value || prefs.state_id || '';
    var cityId = document.getElementById('filter-city')?.value || prefs.city_id || '';
    if (stateId) filters.state_id = stateId;
    if (cityId) filters.city_id = cityId;
    if (status && status !== 'All') filters.status = status;
    if (teamMin) filters.team_size_min = teamMin;
    if (teamMax) filters.team_size_max = teamMax;
    if (software && software !== 'Any') filters.existing_software = software;
    if (rating && rating !== 'Any') {
      if (rating.indexOf('5') >= 0) filters.rating_min = 5;
      else if (rating.indexOf('4') >= 0) filters.rating_min = 4;
      else if (rating.indexOf('3') >= 0) filters.rating_min = 3;
    }
    if (newly === 'Yes') filters.is_newly_established = 'true';
    if (newly === 'No') filters.is_newly_established = 'false';
    if (from) filters.from = from;
    if (to) filters.to = to;
    return filters;
  }

  function exportListing(key) {
    var cfg = REGISTRY[key];
    if (!cfg) return;
    var qs = buildQueryString(key).replace(/^\?/, '');
    window.location.href = '/listings/' + encodeURIComponent(key) + '/export' + (qs ? '?' + qs : '');
  }

  function saveFilter(key, name, filters) {
    return fetch('/listing-filters/' + encodeURIComponent(key), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ name: name, filters: filters }),
    }).then(function (r) { return r.json(); });
  }

  function deleteSavedFilter(id) {
    return fetch('/listing-filters/' + encodeURIComponent(id), {
      method: 'DELETE',
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        'X-Requested-With': 'XMLHttpRequest',
      },
    }).then(function (r) { return r.json(); });
  }

  function loadSavedFilters(key) {
    return fetch('/listing-filters/' + encodeURIComponent(key), {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    }).then(function (r) { return r.json(); });
  }

  function applySavedFilterToDrawer(filters) {
    if (!filters) return;
    if (filters.state_id && document.getElementById('filter-state')) {
      document.getElementById('filter-state').value = filters.state_id;
      document.getElementById('filter-state').dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (filters.city_id && document.getElementById('filter-city')) {
      document.getElementById('filter-city').value = filters.city_id;
    }
    if (filters.city && document.getElementById('filter-city')) document.getElementById('filter-city').value = filters.city;
    if (filters.from && document.getElementById('filter-date-from')) {
      document.getElementById('filter-date-from').value = filters.from;
      document.getElementById('filter-date-from').disabled = false;
    }
    if (filters.to && document.getElementById('filter-date-to')) {
      document.getElementById('filter-date-to').value = filters.to;
      document.getElementById('filter-date-to').disabled = false;
    }
  }

  window.CA_LISTING_SEARCH = {
    REGISTRY: REGISTRY,
    getState: getState,
    setState: setState,
    clearState: clearState,
    buildQueryString: buildQueryString,
    buildAllQueryString: buildAllQueryString,
    unwrapListingBody: unwrapListingBody,
    renderPaginationBar: renderPaginationBar,
    bindSortableHeaders: bindSortableHeaders,
    readLeadDrawerFilters: readLeadDrawerFilters,
    exportListing: exportListing,
    saveFilter: saveFilter,
    loadSavedFilters: loadSavedFilters,
    deleteSavedFilter: deleteSavedFilter,
    applySavedFilterToDrawer: applySavedFilterToDrawer,
    reload: function (key) {
      if (window.CA_CRM && typeof window.CA_CRM.reloadListing === 'function') {
        window.CA_CRM.reloadListing(key);
      }
    },
    applyGlobalSearch: function (term, pageKey) {
      pageKey = pageKey || 'ca_masters';
      setState(pageKey, { search: term, page: 1 });
      if (window.navigateTo) {
        var nav = { ca_masters: 'ca-master', employees: 'assignment', follow_ups: 'followups' }[pageKey] || 'ca-master';
        window.navigateTo(nav);
      }
      setTimeout(function () { window.CA_LISTING_SEARCH.reload(pageKey); }, 150);
    },
    clearFilters: function (key) {
      clearState(key);
      if (key === 'ca_masters') window._leadFilterPrefs = null;
      window.CA_LISTING_SEARCH.reload(key);
    },
  };
})();
