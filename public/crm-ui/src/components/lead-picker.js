/**
 * Reusable lead picker (WhatsApp / Email / SMS campaigns + Schedule Follow-up).
 */
(function (global) {
  'use strict';

  var PAGE_SIZE = 50;
  var PREFIX = {
    campaign: 'campaign-lead-picker',
    followup: 'followup-lead-picker',
  };

  var deps = {};
  var hooks = {};

  function d() {
    return deps;
  }

  function pickerRoot(key) {
    return document.getElementById(PREFIX[key]);
  }

  function el(key, part) {
    if (!part) return pickerRoot(key);
    var node = document.getElementById(PREFIX[key] + '-' + part);
    if (node) return node;
    var root = pickerRoot(key);
    if (!root) return null;
    if (part === 'bulk') return root.querySelector('.campaign-lead-picker__bulk');
    if (part === 'chips') return root.querySelector('.campaign-lead-picker__chips');
    if (part === 'list') return root.querySelector('.campaign-lead-picker__list');
    if (part === 'list-wrap') return root.querySelector('.campaign-lead-picker__list-wrap');
    if (part === 'list-footer') return root.querySelector('.campaign-lead-picker__list-footer');
    if (part === 'search-wrap') return root.querySelector('.campaign-lead-picker__search-wrap');
    if (part === 'stats') return root.querySelector('.campaign-lead-picker__stats');
    return null;
  }

  function shouldShowBulkActions(key) {
    if (key === 'followup') return false;
    return true;
  }

  function emptyListMessage(key) {
    if (key === 'followup') return 'No matching leads found.';
    return 'No leads match your search.';
  }

  function syncBulkBarVisibility(key) {
    var bulk = el(key, 'bulk');
    if (!bulk) return;
    bulk.classList.toggle('hidden', !shouldShowBulkActions(key));
  }

  function isSingleSelect(key) {
    if (key === 'followup') return true;
    var hook = hooks[key];
    if (hook && typeof hook.singleSelect === 'function') return hook.singleSelect();
    if (hook && typeof hook.singleSelect === 'boolean') return hook.singleSelect;
    return false;
  }

  function fireSelectionChange(key) {
    var hook = hooks[key];
    if (hook && typeof hook.onSelectionChange === 'function') hook.onSelectionChange();
  }

  function ensurePickerStates() {
    if (!global._leadPickerStates || typeof global._leadPickerStates !== 'object') {
      global._leadPickerStates = {};
    }
    return global._leadPickerStates;
  }

  function state(key) {
    var states = ensurePickerStates();
    if (!states[key]) {
      states[key] = {
        selected: {},
        page: 1,
        perPage: PAGE_SIZE,
        search: '',
        searchTimer: null,
        totalLeads: 0,
        filteredTotal: 0,
        items: [],
        pageItems: [],
        loading: false,
        hasMore: false,
        lastPage: 1,
      };
    }
    return states[key];
  }

  function assignmentMeta(lead) {
    var executive = lead.executive || lead.employee_name || '';
    if (executive && executive !== 'Unassigned' && executive !== '—') {
      return { label: 'Assigned · ' + executive, assigned: true };
    }
    return { label: 'Unassigned', assigned: false };
  }

  function unwrapListing(body) {
    if (global.CA_LISTING_SEARCH) {
      return global.CA_LISTING_SEARCH.unwrapListingBody(body);
    }
    return { items: typeof d().unwrapList === 'function' ? d().unwrapList(body) : [], pagination: null };
  }

  function fetchPage(page, search) {
    var params = new URLSearchParams({
      per_page: String(PAGE_SIZE),
      page: String(page),
      sort_by: 'firm_name',
      sort_dir: 'asc',
    });
    if (search) params.set('search', search);
    return d().apiFetch('/ca-masters?' + params.toString()).then(function (body) {
      var listing = unwrapListing(body);
      var pagination = listing.pagination || {};
      return {
        items: (listing.items || []).map(d().mapLeadRecord),
        pagination: pagination,
        total: pagination.total != null ? pagination.total : (listing.items || []).length,
        lastPage: pagination.last_page != null ? pagination.last_page : 1,
        currentPage: pagination.current_page != null ? pagination.current_page : page,
      };
    });
  }

  function fetchTotalCount(key) {
    return d().apiFetch('/ca-masters?per_page=1&page=1&sort_by=firm_name&sort_dir=asc')
      .then(function (body) {
        var listing = unwrapListing(body);
        var total = listing.pagination && listing.pagination.total != null
          ? listing.pagination.total
          : (listing.items || []).length;
        state(key).totalLeads = total;
        return total;
      })
      .catch(function () { return 0; });
  }

  function syncSearchClear(key) {
    var input = el(key, 'search');
    var clearBtn = el(key, 'search-clear');
    if (!input || !clearBtn) return;
    var hasValue = !!(input.value || '').trim();
    clearBtn.classList.toggle('hidden', !hasValue);
    input.classList.toggle('has-value', hasValue);
  }

  function updateStats(key) {
    var st = state(key);
    var selectedCount = Object.keys(st.selected).length;
    var setText = function (part, val) {
      var node = el(key, part);
      if (node) node.textContent = String(val);
    };
    setText('total', st.totalLeads);
    setText('filtered', st.filteredTotal);
    setText('selected', selectedCount);
    var label = el(key, 'selected-label');
    if (label) {
      label.textContent = selectedCount === 1 ? '1 Lead Selected' : selectedCount + ' Leads Selected';
    }
    var pageInfo = el(key, 'page-info');
    if (pageInfo) {
      if (st.loading) pageInfo.textContent = 'Loading…';
      else if (!st.items.length) pageInfo.textContent = 'No leads found';
      else {
        var pageLabel = st.page > 1 ? ('Page ' + st.page + ' · ') : '';
        pageInfo.textContent = pageLabel + 'Showing ' + st.items.length + ' of ' + st.filteredTotal + (st.hasMore ? ' · scroll for more' : '');
      }
    }
    var loadMoreBtn = el(key, 'load-more');
    if (loadMoreBtn) loadMoreBtn.classList.toggle('hidden', !st.hasMore || st.loading);
    syncBulkBarVisibility(key);
  }

  function renderChips(key) {
    var chipsEl = el(key, 'chips');
    if (!chipsEl) return;
    var selected = Object.keys(state(key).selected).map(function (id) { return state(key).selected[id]; });
    if (!selected.length) {
      chipsEl.innerHTML = '<span class="text-caption text-slate-400">No leads selected yet</span>';
      return;
    }
    chipsEl.innerHTML = selected.map(function (lead) {
      return '<span class="campaign-lead-picker__chip" data-chip-lead-id="' + lead.ca_id + '">' +
        '<span class="campaign-lead-picker__chip-label" title="' + d().escapeHtml(lead.firm_name) + '">' + d().escapeHtml(lead.firm_name) + '</span>' +
        '<button type="button" class="campaign-lead-picker__chip-remove" data-remove-lead-id="' + lead.ca_id + '" aria-label="Remove ' + d().escapeHtml(lead.firm_name) + '">×</button>' +
      '</span>';
    }).join('');
  }

  function renderList(key, append) {
    var listEl = el(key, 'list');
    if (!listEl) return;
    var st = state(key);

    if (st.loading && !append) {
      listEl.innerHTML = '<div class="campaign-lead-picker__loading">Loading leads…</div>';
      updateStats(key);
      return;
    }

    if (!st.items.length) {
      listEl.innerHTML = '<div class="campaign-lead-picker__empty">' + d().escapeHtml(emptyListMessage(key)) + '</div>';
      updateStats(key);
      return;
    }

    var html = st.items.map(function (lead) {
      var assignment = assignmentMeta(lead);
      var selected = !!st.selected[String(lead.ca_id)];
      var mobile = lead.mobile_no && lead.mobile_no !== '—' ? lead.mobile_no : '—';
      var cityLine = lead.city && lead.city !== '—' ? lead.city : '';
      if (lead.state && lead.state !== '—') {
        cityLine = cityLine ? cityLine + ', ' + lead.state : lead.state;
      }
      return '<label class="campaign-lead-picker__item' + (selected ? ' is-selected' : '') + '" data-lead-id="' + lead.ca_id + '" role="option" aria-selected="' + (selected ? 'true' : 'false') + '" tabindex="0">' +
        '<input type="checkbox" class="campaign-lead-picker__checkbox" data-lead-id="' + lead.ca_id + '"' + (selected ? ' checked' : '') + ' />' +
        '<div class="campaign-lead-picker__item-body">' +
          '<p class="campaign-lead-picker__firm">' + d().escapeHtml(lead.firm_name || '—') + '</p>' +
          '<div class="campaign-lead-picker__meta">' +
            '<div class="campaign-lead-picker__meta-row"><span>CA: ' + d().escapeHtml(lead.ca_name || '—') + '</span></div>' +
            '<div class="campaign-lead-picker__meta-row"><span>Mobile: ' + d().escapeHtml(mobile) + '</span></div>' +
            (cityLine ? '<div class="campaign-lead-picker__meta-row"><span>City: ' + d().escapeHtml(cityLine) + '</span></div>' : '') +
          '</div>' +
          '<span class="campaign-lead-picker__assignment ' + (assignment.assigned ? 'is-assigned' : 'is-unassigned') + '">' + d().escapeHtml(assignment.label) + '</span>' +
        '</div>' +
      '</label>';
    }).join('');

    if (append) listEl.insertAdjacentHTML('beforeend', html);
    else listEl.innerHTML = html;

    updateStats(key);
    if (typeof d().icons === 'function') d().icons();
  }

  function render(key) {
    renderChips(key);
    renderList(key, false);
    updateStats(key);
    syncBulkBarVisibility(key);
  }

  function loadPage(key, page, append) {
    var st = state(key);
    if (st.loading) return Promise.resolve();
    st.loading = true;
    st.page = page;
    if (!append) renderList(key, false);

    return fetchPage(page, st.search).then(function (result) {
      st.loading = false;
      st.filteredTotal = result.total;
      st.lastPage = result.lastPage;
      st.hasMore = result.currentPage < result.lastPage;

      st.pageItems = result.items || [];

      if (append) {
        var existingIds = {};
        st.items.forEach(function (l) { existingIds[String(l.ca_id)] = true; });
        result.items.forEach(function (l) {
          if (!existingIds[String(l.ca_id)]) st.items.push(l);
        });
      } else {
        st.items = result.items;
      }

      renderList(key, append);
      return result;
    }).catch(function () {
      st.loading = false;
      if (!append) st.items = [];
      renderList(key, false);
      d().toast('Failed to load leads', 'error');
    });
  }

  function toggleSelection(key, lead, selected) {
    var st = state(key);
    if (selected && isSingleSelect(key)) st.selected = {};
    var id = String(lead.ca_id);
    if (selected) st.selected[id] = lead;
    else delete st.selected[id];
    renderChips(key);
    updateStats(key);
    document.querySelectorAll('#' + PREFIX[key] + '-list .campaign-lead-picker__item[data-lead-id]').forEach(function (row) {
      var rowId = row.getAttribute('data-lead-id');
      var isSel = !!st.selected[rowId];
      row.classList.toggle('is-selected', isSel);
      var cb = row.querySelector('.campaign-lead-picker__checkbox');
      if (cb) cb.checked = isSel;
    });
    fireSelectionChange(key);
  }

  function selectAllFiltered(key) {
    var st = state(key);
    var params = new URLSearchParams({ all: '1', sort_by: 'firm_name', sort_dir: 'asc' });
    if (st.search) params.set('search', st.search);
    st.loading = true;
    updateStats(key);
    return d().apiFetch('/ca-masters?' + params.toString())
      .then(function (body) {
        var listing = unwrapListing(body);
        var leads = (listing.items || []).map(d().mapLeadRecord);
        if (isSingleSelect(key)) {
          if (leads[0]) st.selected = { [String(leads[0].ca_id)]: leads[0] };
        } else {
          leads.forEach(function (lead) {
            st.selected[String(lead.ca_id)] = lead;
          });
        }
        st.loading = false;
        render(key);
        fireSelectionChange(key);
        d().toast(Object.keys(st.selected).length + ' leads selected', 'success');
      })
      .catch(function () {
        st.loading = false;
        updateStats(key);
        d().toast('Failed to select all leads', 'error');
      });
  }

  function selectPageLeads(key) {
    var st = state(key);
    var pageLeads = (st.pageItems && st.pageItems.length) ? st.pageItems : st.items;
    if (!pageLeads.length) {
      d().toast('No leads on this page to select', 'warning');
      return;
    }
    if (isSingleSelect(key)) {
      toggleSelection(key, pageLeads[0], true);
      return;
    }
    pageLeads.forEach(function (lead) {
      st.selected[String(lead.ca_id)] = lead;
    });
    render(key);
    fireSelectionChange(key);
    d().toast(pageLeads.length + ' lead' + (pageLeads.length === 1 ? '' : 's') + ' on this page selected', 'success');
  }

  function clearAllSelected(key) {
    state(key).selected = {};
    render(key);
    fireSelectionChange(key);
    d().toast('Selection cleared', 'info');
  }

  function reset(key) {
    ensurePickerStates()[key] = {
      selected: {},
      page: 1,
      perPage: PAGE_SIZE,
      search: '',
      searchTimer: null,
      totalLeads: 0,
      filteredTotal: 0,
      items: [],
      pageItems: [],
      loading: false,
      hasMore: false,
      lastPage: 1,
    };
    var searchInput = el(key, 'search');
    if (searchInput) searchInput.value = '';
    syncSearchClear(key);
    render(key);
  }

  function findLeadInCache(id) {
    if (d().getLeadRecord) {
      var fromDeps = d().getLeadRecord(id);
      if (fromDeps) return fromDeps;
    }
    var pools = [global._listingLeadsPage, global.kanbanLeads, global.realLeads, global._selectLeads];
    for (var i = 0; i < pools.length; i++) {
      if (!pools[i] || !pools[i].length) continue;
      var hit = pools[i].find(function (l) { return String(l.ca_id) === String(id); });
      if (hit) return hit;
    }
    return null;
  }

  function seedPresetFromCache(key, ids) {
    if (!ids || !ids.length) return false;
    var st = state(key);
    var seeded = false;
    ids.forEach(function (id) {
      var lead = findLeadInCache(id);
      if (!lead) return;
      if (isSingleSelect(key)) st.selected = { [String(lead.ca_id)]: lead };
      else st.selected[String(lead.ca_id)] = lead;
      seeded = true;
    });
    if (seeded) {
      renderChips(key);
      updateStats(key);
      fireSelectionChange(key);
    }
    return seeded;
  }

  function applyPresetIds(key, ids) {
    if (!ids || !ids.length) return Promise.resolve();
    var st = state(key);
    var missing = [];
    ids.forEach(function (id) {
      var lead = findLeadInCache(id);
      if (lead) {
        if (isSingleSelect(key)) st.selected = { [String(lead.ca_id)]: lead };
        else st.selected[String(lead.ca_id)] = lead;
      } else {
        missing.push(id);
      }
    });
    if (Object.keys(st.selected).length) {
      renderChips(key);
      updateStats(key);
      fireSelectionChange(key);
    }
    if (!missing.length) return Promise.resolve();

    return Promise.all(missing.map(function (id) {
      return d().apiFetch('/ca-masters/' + encodeURIComponent(id))
        .then(function (body) {
          var raw = body && body.data ? body.data : body;
          var lead = raw ? d().mapLeadRecord(raw) : null;
          if (!lead || !lead.ca_id) return;
          if (isSingleSelect(key)) st.selected = { [String(lead.ca_id)]: lead };
          else st.selected[String(lead.ca_id)] = lead;
        })
        .catch(function () { /* ignore */ });
    })).then(function () {
      render(key);
      fireSelectionChange(key);
    });
  }

  function init(key, presetIds, options) {
    options = options || {};
    if (options.reset !== false) reset(key);
    var cacheSeeded = seedPresetFromCache(key, presetIds);
    var deferTotalCount = cacheSeeded && presetIds && presetIds.length === 1;
    if (deferTotalCount) {
      setTimeout(function () {
        fetchTotalCount(key).then(function () { updateStats(key); });
      }, 0);
    } else {
      fetchTotalCount(key).then(function () { updateStats(key); });
    }
    return loadPage(key, 1, false).then(function () {
      if (presetIds && presetIds.length) return applyPresetIds(key, presetIds);
    });
  }

  function refresh(key) {
    var st = state(key);
    fetchTotalCount(key).then(function () { updateStats(key); });
    return loadPage(key, st.page || 1, false);
  }

  function focusListItem(key, leadId) {
    var listEl = el(key, 'list');
    if (!listEl) return;
    var row = listEl.querySelector('.campaign-lead-picker__item[data-lead-id="' + leadId + '"]');
    if (row && typeof row.focus === 'function') row.focus();
  }

  function focusAdjacentListItem(key, currentRow, direction) {
    var listEl = el(key, 'list');
    if (!listEl || !currentRow) return;
    var rows = Array.prototype.slice.call(listEl.querySelectorAll('.campaign-lead-picker__item[data-lead-id]'));
    var idx = rows.indexOf(currentRow);
    if (idx < 0) return;
    var next = rows[idx + direction];
    if (next && typeof next.focus === 'function') next.focus();
  }

  function bindListKeyboard(key) {
    var listEl = el(key, 'list');
    if (!listEl || listEl._crmKeyboardBound) return;
    listEl._crmKeyboardBound = true;
    listEl.addEventListener('keydown', function (e) {
      var row = e.target.closest('.campaign-lead-picker__item[data-lead-id]');
      if (!row) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        focusAdjacentListItem(key, row, 1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        focusAdjacentListItem(key, row, -1);
      } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        var cb = row.querySelector('.campaign-lead-picker__checkbox');
        if (!cb) return;
        var leadId = row.getAttribute('data-lead-id');
        var lead = state(key).items.find(function (l) { return String(l.ca_id) === String(leadId); })
          || state(key).pageItems.find(function (l) { return String(l.ca_id) === String(leadId); })
          || state(key).selected[String(leadId)];
        if (lead) toggleSelection(key, lead, isSingleSelect(key) ? true : !cb.checked);
      }
    });
  }

  function bindSearchKeyboard(key) {
    var input = el(key, 'search');
    if (!input || input._crmKeyboardBound) return;
    input._crmKeyboardBound = true;
    input.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowDown') return;
      var listEl = el(key, 'list');
      var first = listEl && listEl.querySelector('.campaign-lead-picker__item[data-lead-id]');
      if (!first) return;
      e.preventDefault();
      if (typeof first.focus === 'function') first.focus();
    });
  }

  function bind(key, keyHooks) {
    if (keyHooks) hooks[key] = keyHooks;
    var flag = '_leadPickerBound_' + key;
    if (global[flag]) return;
    global[flag] = true;

    bindListKeyboard(key);
    bindSearchKeyboard(key);

    el(key, 'search')?.addEventListener('input', function (e) {
      var st = state(key);
      syncSearchClear(key);
      clearTimeout(st.searchTimer);
      st.searchTimer = setTimeout(function () {
        st.search = (e.target.value || '').trim();
        loadPage(key, 1, false);
      }, 300);
    });

    el(key, 'search-clear')?.addEventListener('click', function () {
      var input = el(key, 'search');
      if (!input) return;
      input.value = '';
      syncSearchClear(key);
      state(key).search = '';
      loadPage(key, 1, false);
      input.focus();
    });

    el(key, 'select-page')?.addEventListener('click', function () {
      selectPageLeads(key);
    });

    el(key, 'select-all')?.addEventListener('click', function () {
      var st = state(key);
      var count = st.filteredTotal || st.items.length;
      if (!count) {
        d().toast('No leads available to select', 'warning');
        return;
      }
      if (count > 500 && !global.confirm('Select all ' + count + ' matching leads?')) return;
      selectAllFiltered(key);
    });

    el(key, 'clear')?.addEventListener('click', function () {
      clearAllSelected(key);
    });

    el(key, 'load-more')?.addEventListener('click', function () {
      var st = state(key);
      if (st.hasMore && !st.loading) loadPage(key, st.page + 1, true);
    });

    el(key, 'list')?.addEventListener('scroll', function (e) {
      var target = e.target;
      if (target.scrollTop + target.clientHeight >= target.scrollHeight - 48) {
        var st = state(key);
        if (st.hasMore && !st.loading) loadPage(key, st.page + 1, true);
      }
    });

    el(key, 'list')?.addEventListener('change', function (e) {
      var cb = e.target.closest('.campaign-lead-picker__checkbox');
      if (!cb) return;
      var leadId = cb.getAttribute('data-lead-id');
      var lead = state(key).items.find(function (l) { return String(l.ca_id) === String(leadId); })
        || state(key).pageItems.find(function (l) { return String(l.ca_id) === String(leadId); })
        || state(key).selected[String(leadId)];
      if (lead) toggleSelection(key, lead, cb.checked);
    });

    el(key, 'chips')?.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-remove-lead-id]');
      if (!btn) return;
      var id = btn.getAttribute('data-remove-lead-id');
      var lead = state(key).selected[id];
      if (lead) toggleSelection(key, lead, false);
    });
  }

  function selectedIds(key) {
    return Object.keys(state(key).selected).map(function (id) {
      return parseInt(id, 10);
    }).filter(Boolean);
  }

  function firstSelectedId(key) {
    var ids = selectedIds(key);
    return ids.length ? ids[0] : null;
  }

  function selectedLeads(key) {
    return Object.keys(state(key).selected).map(function (id) {
      return state(key).selected[id];
    });
  }

  global.CrmLeadPicker = {
    setDeps: function (next) { deps = next || {}; },
    bind: bind,
    init: init,
    refresh: refresh,
    reset: reset,
    render: render,
    state: state,
    selectedIds: selectedIds,
    firstSelectedId: firstSelectedId,
    selectedLeads: selectedLeads,
    applyPresetIds: applyPresetIds,
    seedPresetFromCache: seedPresetFromCache,
    syncSearchClear: syncSearchClear,
    selectPageLeads: selectPageLeads,
    clearAllSelected: clearAllSelected,
    syncBulkBarVisibility: syncBulkBarVisibility,
    showBulkActions: function (key) {
      var bulk = el(key, 'bulk');
      if (bulk) bulk.classList.remove('hidden');
    },
  };
})(window);
