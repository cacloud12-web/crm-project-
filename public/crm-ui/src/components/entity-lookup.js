/* global window, document */
(function (global) {
  'use strict';

  var PAGE_SIZE = 20;
  var instances = new WeakMap();

  function apiFetch(url) {
    if (global.CA_CRM && typeof global.CA_CRM.apiFetch === 'function') {
      return global.CA_CRM.apiFetch(url);
    }
    return fetch(url, {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
      },
    }).then(function (response) {
      if (!response.ok) throw new Error('Request failed');
      return response.json();
    });
  }

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function unwrapListing(body) {
    var data = body && body.data !== undefined ? body.data : body;
    if (Array.isArray(data)) {
      return { items: data, pagination: null };
    }
    if (data && Array.isArray(data.items)) {
      return { items: data.items, pagination: data.pagination || null };
    }
    return { items: [], pagination: null };
  }

  function mapLead(row) {
    if (!row) return null;
    if (global.CA_CRM && typeof global.CA_CRM.mapLeadRecord === 'function') {
      return global.CA_CRM.mapLeadRecord(row);
    }
    return {
      ca_id: String(row.ca_id),
      firm_name: row.firm_name || '—',
      ca_name: row.ca_name || '—',
      mobile_no: row.mobile_no || '—',
      alternate_mobile_no: row.alternate_mobile_no || '—',
      email_id: row.email_id || '—',
      city: row.city || row.city_name || '—',
      state: row.state || row.state_name || '—',
    };
  }

  function mapEmployee(row) {
    if (!row) return null;
    if (global.CA_CRM && typeof global.CA_CRM.mapEmployeeRecord === 'function') {
      return global.CA_CRM.mapEmployeeRecord(row);
    }
    return {
      employee_id: String(row.employee_id),
      name: row.name || '—',
      email_id: row.email_id || '—',
      mobile_no: row.mobile_no || '—',
      role: row.role || 'Employee',
      city: row.city || row.city_name || '—',
      login_status: row.login_status || 'none',
    };
  }

  function leadValue(lead) {
    return lead ? String(lead.ca_id) : '';
  }

  function employeeValue(emp) {
    return emp ? String(emp.employee_id) : '';
  }

  function leadInputLabel(lead) {
    if (!lead) return '';
    var parts = [lead.firm_name || '—'];
    if (lead.ca_name && lead.ca_name !== '—') parts.push(lead.ca_name);
    if (lead.city && lead.city !== '—') parts.push(lead.city);
    return parts.join(' · ');
  }

  function employeeInputLabel(emp) {
    if (!emp) return '';
    var parts = [emp.name || '—'];
    if (emp.email_id && emp.email_id !== '—') parts.push(emp.email_id);
    return parts.join(' · ');
  }

  function leadSearchPlaceholder() {
    return 'Search firm, CA name, mobile, city, state, email…';
  }

  function employeeSearchPlaceholder() {
    return 'Search employee name, email, mobile, role…';
  }

  function fetchLeadPage(page, search) {
    var params = new URLSearchParams({
      per_page: String(PAGE_SIZE),
      page: String(page),
      sort_by: 'firm_name',
      sort_dir: 'asc',
    });
    if (search) params.set('search', search);
    return apiFetch('/ca-masters?' + params.toString()).then(function (body) {
      var listing = unwrapListing(body);
      var pagination = listing.pagination || {};
      return {
        items: (listing.items || []).map(mapLead).filter(Boolean),
        total: pagination.total != null ? pagination.total : (listing.items || []).length,
        hasMore: pagination.current_page != null && pagination.last_page != null
          ? pagination.current_page < pagination.last_page
          : (listing.items || []).length >= PAGE_SIZE,
      };
    });
  }

  function fetchEmployeePage(page, search, extraParams) {
    extraParams = extraParams || {};
    var params = new URLSearchParams({
      per_page: String(PAGE_SIZE),
      page: String(page),
      sort_by: 'name',
      sort_dir: 'asc',
      status: extraParams.status || 'Active',
    });
    if (search) params.set('search', search);
    Object.keys(extraParams).forEach(function (key) {
      if (key === 'status') return;
      if (extraParams[key] != null && extraParams[key] !== '') params.set(key, extraParams[key]);
    });
    return apiFetch('/employees?' + params.toString()).then(function (body) {
      var listing = unwrapListing(body);
      var pagination = listing.pagination || {};
      return {
        items: (listing.items || []).map(mapEmployee).filter(Boolean),
        total: pagination.total != null ? pagination.total : (listing.items || []).length,
        hasMore: pagination.current_page != null && pagination.last_page != null
          ? pagination.current_page < pagination.last_page
          : (listing.items || []).length >= PAGE_SIZE,
      };
    }).catch(function () {
      return apiFetch('/lookups/executives').then(function (body) {
        var items = unwrapListing(body).items.map(mapEmployee).filter(Boolean);
        var term = (search || '').toLowerCase();
        if (term) {
          items = items.filter(function (emp) {
            return [emp.name, emp.email_id, emp.mobile_no, emp.role, emp.city].join(' ').toLowerCase().indexOf(term) >= 0;
          });
        }
        return { items: items, total: items.length, hasMore: false };
      });
    });
  }

  function fetchLeadById(id) {
    return apiFetch('/ca-masters/' + encodeURIComponent(id)).then(function (body) {
      return mapLead(body.data || body);
    });
  }

  function fetchEmployeeById(id) {
    return apiFetch('/employees/' + encodeURIComponent(id)).then(function (body) {
      return mapEmployee(body.data || body);
    }).catch(function () {
      return apiFetch('/lookups/executives').then(function (body) {
        var items = unwrapListing(body).items.map(mapEmployee).filter(Boolean);
        return items.find(function (emp) { return String(emp.employee_id) === String(id); }) || null;
      });
    });
  }

  function getTypeConfig(type) {
    if (type === 'employee') {
      return {
        type: 'employee',
        valueKey: 'employee_id',
        getValue: employeeValue,
        getLabel: employeeInputLabel,
        placeholder: employeeSearchPlaceholder(),
        fetchPage: fetchEmployeePage,
        fetchById: fetchEmployeeById,
        renderItem: renderEmployeeItem,
      };
    }
    return {
      type: 'lead',
      valueKey: 'ca_id',
      getValue: leadValue,
      getLabel: leadInputLabel,
      placeholder: leadSearchPlaceholder(),
      fetchPage: fetchLeadPage,
      fetchById: fetchLeadById,
      renderItem: renderLeadItem,
    };
  }

  function renderLeadItem(lead, active) {
    var mobile = lead.mobile_no && lead.mobile_no !== '—' ? lead.mobile_no : '—';
    var cityLine = [lead.city, lead.state].filter(function (part) { return part && part !== '—'; }).join(', ');
    return '<button type="button" class="crm-entity-lookup__option' + (active ? ' is-active' : '') + '" role="option" data-value="' + escapeHtml(lead.ca_id) + '" aria-selected="' + (active ? 'true' : 'false') + '">' +
      '<span class="crm-entity-lookup__option-title">' + escapeHtml(lead.firm_name || '—') + '</span>' +
      '<span class="crm-entity-lookup__option-meta">CA: ' + escapeHtml(lead.ca_name || '—') + ' · Mobile: ' + escapeHtml(mobile) + '</span>' +
      (cityLine ? '<span class="crm-entity-lookup__option-meta">City: ' + escapeHtml(cityLine) + '</span>' : '') +
    '</button>';
  }

  function renderEmployeeItem(emp, active) {
    return '<button type="button" class="crm-entity-lookup__option' + (active ? ' is-active' : '') + '" role="option" data-value="' + escapeHtml(emp.employee_id) + '" aria-selected="' + (active ? 'true' : 'false') + '">' +
      '<span class="crm-entity-lookup__option-title">' + escapeHtml(emp.name || '—') + '</span>' +
      '<span class="crm-entity-lookup__option-meta">' + escapeHtml(emp.role || 'Employee') + (emp.email_id && emp.email_id !== '—' ? ' · ' + escapeHtml(emp.email_id) : '') + '</span>' +
      (emp.city && emp.city !== '—' ? '<span class="crm-entity-lookup__option-meta">City: ' + escapeHtml(emp.city) + '</span>' : '') +
    '</button>';
  }

  function enhance(selectEl, options) {
    options = options || {};
    if (!selectEl || selectEl.tagName !== 'SELECT') return null;

    var existing = instances.get(selectEl);
    if (existing) return existing;

    var type = selectEl.dataset.crmEntityLookup || options.type || 'lead';
    var config = getTypeConfig(type);
    var compact = selectEl.dataset.crmLookupCompact === 'true' || options.compact;
    var extraParams = {};
    try {
      if (selectEl.dataset.crmLookupParams) extraParams = JSON.parse(selectEl.dataset.crmLookupParams);
    } catch (e) { /* ignore */ }

    var wrapper = document.createElement('div');
    wrapper.className = 'crm-entity-lookup' + (compact ? ' crm-entity-lookup--compact' : '');
    selectEl.parentNode.insertBefore(wrapper, selectEl);
    wrapper.appendChild(selectEl);

    selectEl.classList.add('crm-entity-lookup-native');
    selectEl.dataset.crmLookupEnhanced = '1';
    if (!selectEl.options.length || (selectEl.options.length === 1 && !selectEl.options[0].value)) {
      selectEl.innerHTML = '<option value="">' + escapeHtml(selectEl.dataset.crmLookupEmptyLabel || '') + '</option>';
    }

    var control = document.createElement('div');
    control.className = 'crm-entity-lookup__control';
    wrapper.insertBefore(control, selectEl);

    var searchWrap = document.createElement('div');
    searchWrap.className = 'crm-entity-lookup__search-wrap';
    searchWrap.innerHTML = '<i data-lucide="search" class="crm-entity-lookup__search-icon h-4 w-4"></i>';
    control.appendChild(searchWrap);

    var input = document.createElement('input');
    input.type = 'search';
    input.className = 'input-field crm-entity-lookup__input';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.placeholder = selectEl.dataset.crmLookupPlaceholder || config.placeholder;
    if (selectEl.disabled) input.disabled = true;
    searchWrap.appendChild(input);

    var clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'crm-entity-lookup__clear hidden';
    clearBtn.setAttribute('aria-label', 'Clear selection');
    clearBtn.innerHTML = '<i data-lucide="x" class="h-4 w-4"></i>';
    searchWrap.appendChild(clearBtn);

    var panel = document.createElement('div');
    panel.className = 'crm-entity-lookup__panel hidden';
    panel.setAttribute('role', 'listbox');
    control.appendChild(panel);

    var list = document.createElement('div');
    list.className = 'crm-entity-lookup__list';
    panel.appendChild(list);

    var footer = document.createElement('div');
    footer.className = 'crm-entity-lookup__footer text-caption text-slate-500';
    panel.appendChild(footer);

    var state = {
      page: 1,
      search: '',
      items: [],
      total: 0,
      hasMore: false,
      loading: false,
      open: false,
      activeIndex: -1,
      selectedRecord: null,
      searchTimer: null,
    };

    function syncIcons() {
      if (global.lucide && typeof global.lucide.createIcons === 'function') {
        global.lucide.createIcons({ nodes: [wrapper] });
      }
    }

    function closePanel() {
      state.open = false;
      panel.classList.add('hidden');
      wrapper.classList.remove('is-open');
      state.activeIndex = -1;
    }

    function openPanel() {
      if (input.disabled) return;
      state.open = true;
      panel.classList.remove('hidden');
      wrapper.classList.add('is-open');
      if (!state.items.length) loadPage(1, false);
    }

    function updateFooter() {
      if (state.loading) {
        footer.textContent = 'Loading…';
        return;
      }
      if (!state.items.length) {
        footer.textContent = state.search ? 'No matches found.' : 'Start typing to search.';
        return;
      }
      footer.textContent = 'Showing ' + state.items.length + ' of ' + state.total + (state.hasMore ? ' · scroll for more' : '');
    }

    function renderList(append) {
      if (state.loading && !append) {
        list.innerHTML = '<div class="crm-entity-lookup__empty">Loading…</div>';
        updateFooter();
        return;
      }
      if (!state.items.length) {
        list.innerHTML = '<div class="crm-entity-lookup__empty">' + (state.search ? 'No matches found.' : 'Start typing to search.') + '</div>';
        updateFooter();
        return;
      }
      var html = state.items.map(function (item, idx) {
        return config.renderItem(item, idx === state.activeIndex);
      }).join('');
      if (append) list.insertAdjacentHTML('beforeend', html);
      else list.innerHTML = html;
      updateFooter();
    }

    function loadPage(page, append) {
      state.loading = true;
      if (!append) renderList(false);
      return config.fetchPage(page, state.search, extraParams).then(function (result) {
        state.page = page;
        state.total = result.total || 0;
        state.hasMore = !!result.hasMore;
        var nextItems = result.items || [];
        state.items = append ? state.items.concat(nextItems) : nextItems;
        state.loading = false;
        renderList(append);
      }).catch(function () {
        state.loading = false;
        list.innerHTML = '<div class="crm-entity-lookup__empty text-rose-600">Unable to load results.</div>';
        footer.textContent = '';
      });
    }

    function ensureSelectOption(value, label) {
      if (!value) return;
      var strValue = String(value);
      var exists = Array.prototype.some.call(selectEl.options, function (opt) {
        return opt.value === strValue;
      });
      if (!exists) {
        var opt = document.createElement('option');
        opt.value = strValue;
        opt.textContent = label || strValue;
        selectEl.appendChild(opt);
      }
    }

    function applySelection(record, silent) {
      state.selectedRecord = record || null;
      var value = record ? config.getValue(record) : '';
      if (value) {
        ensureSelectOption(value, record ? config.getLabel(record) : value);
      }
      selectEl.value = value;
      input.value = record ? config.getLabel(record) : '';
      clearBtn.classList.toggle('hidden', !value);
      if (!silent) {
        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    function selectByValue(value) {
      if (!value) {
        applySelection(null);
        return Promise.resolve();
      }
      var found = state.items.find(function (item) {
        return String(config.getValue(item)) === String(value);
      });
      if (found) {
        applySelection(found);
        return Promise.resolve(found);
      }
      return config.fetchById(value).then(function (record) {
        if (record) applySelection(record);
        else {
          ensureSelectOption(value, String(value));
          selectEl.value = String(value);
          input.value = String(value);
        }
        return record;
      }).catch(function () {
        ensureSelectOption(value, String(value));
        selectEl.value = String(value);
        input.value = String(value);
        return null;
      });
    }

    function moveActive(delta) {
      if (!state.items.length) return;
      state.activeIndex = Math.max(0, Math.min(state.items.length - 1, state.activeIndex + delta));
      renderList(false);
      var activeEl = list.querySelector('.crm-entity-lookup__option.is-active');
      if (activeEl) activeEl.scrollIntoView({ block: 'nearest' });
    }

    input.addEventListener('focus', function () {
      openPanel();
    });

    input.addEventListener('input', function () {
      clearTimeout(state.searchTimer);
      var raw = input.value;
      if (state.selectedRecord && raw !== config.getLabel(state.selectedRecord)) {
        state.selectedRecord = null;
        selectEl.value = '';
        clearBtn.classList.add('hidden');
      }
      state.searchTimer = setTimeout(function () {
        state.search = raw.trim();
        loadPage(1, false);
      }, 250);
      if (!state.open) openPanel();
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!state.open) openPanel();
        moveActive(1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        moveActive(-1);
      } else if (e.key === 'Enter') {
        if (state.open && state.activeIndex >= 0 && state.items[state.activeIndex]) {
          e.preventDefault();
          applySelection(state.items[state.activeIndex]);
          closePanel();
        }
      } else if (e.key === 'Escape') {
        e.preventDefault();
        closePanel();
        if (state.selectedRecord) input.value = config.getLabel(state.selectedRecord);
      }
    });

    list.addEventListener('click', function (e) {
      var option = e.target.closest('.crm-entity-lookup__option');
      if (!option) return;
      var value = option.getAttribute('data-value');
      var record = state.items.find(function (item) {
        return String(config.getValue(item)) === String(value);
      });
      if (record) {
        applySelection(record);
        closePanel();
      }
    });

    list.addEventListener('scroll', function () {
      if (!state.hasMore || state.loading) return;
      if (list.scrollTop + list.clientHeight >= list.scrollHeight - 40) {
        loadPage(state.page + 1, true);
      }
    });

    clearBtn.addEventListener('click', function () {
      applySelection(null);
      input.focus();
      state.search = '';
      loadPage(1, false);
    });

    document.addEventListener('mousedown', function (e) {
      if (!wrapper.contains(e.target)) closePanel();
    });

    if (selectEl.value) {
      selectByValue(selectEl.value);
    }

    syncIcons();

    var api = {
      selectEl: selectEl,
      setValue: function (value, record) {
        if (record) {
          applySelection(record);
          return Promise.resolve(record);
        }
        return selectByValue(value);
      },
      getSelectedRecord: function () {
        return state.selectedRecord;
      },
      refresh: function () {
        return loadPage(1, false);
      },
      destroy: function () {
        instances.delete(selectEl);
      },
    };

    instances.set(selectEl, api);
    return api;
  }

  function get(selectEl) {
    return instances.get(selectEl) || null;
  }

  function setValue(selectEl, value, record) {
    var api = get(selectEl);
    if (!api) {
      enhance(selectEl);
      api = get(selectEl);
    }
    return api ? api.setValue(value, record) : Promise.resolve();
  }

  function enhanceAll(root) {
    var scope = root || document;
    scope.querySelectorAll('select[data-crm-entity-lookup]').forEach(function (selectEl) {
      if (!get(selectEl)) enhance(selectEl);
    });
  }

  global.CrmEntityLookup = {
    enhance: enhance,
    enhanceAll: enhanceAll,
    get: get,
    setValue: setValue,
  };
})(window);
