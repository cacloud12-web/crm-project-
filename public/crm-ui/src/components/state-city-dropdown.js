/* global window, document, sessionStorage */
(function (global) {
  'use strict';

  var STATES_CACHE_KEY = 'crm:states:v5';
  var STATES_URL = '/lookups/states';
  var CITIES_URL = '/lookups/cities';
  var statesCache = null;
  var cityCache = {};
  var statesLoading = null;

  function fetchJson(url) {
    if (global.CA_CRM && typeof global.CA_CRM.apiFetch === 'function') {
      return global.CA_CRM.apiFetch(url).then(function (body) {
        if (body && body.success === false) {
          throw new Error(body.message || 'Unable to load location data');
        }
        return body;
      });
    }

    return fetch(url, {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
      },
    }).then(function (response) {
      if (response.status === 401) {
        window.location.href = '/login';
        throw new Error('Session expired');
      }
      if (!response.ok) throw new Error('Unable to load location data');
      return response.json();
    });
  }

  function normalizeRow(row) {
    if (!row) return null;
    if (row.data && typeof row.data === 'object' && !Array.isArray(row.data)) {
      return row.data;
    }
    return row;
  }

  function unwrapItems(body) {
    if (!body) return [];

    if (body.success === false) {
      throw new Error(body.message || 'Unable to load location data');
    }

    var data = body.data !== undefined ? body.data : body;
    var list = [];

    if (Array.isArray(data)) {
      list = data;
    } else if (data && Array.isArray(data.items)) {
      list = data.items;
    } else if (data && Array.isArray(data.data)) {
      list = data.data;
    }

    return list.map(normalizeRow).filter(function (row) {
      return row && (row.state_id != null || row.city_id != null || row.state_name || row.city_name);
    });
  }

  function loadStates(force) {
    if (!force && statesCache && statesCache.length) {
      return Promise.resolve(statesCache);
    }

    if (!force) {
      try {
        var cached = sessionStorage.getItem(STATES_CACHE_KEY);
        if (cached) {
          var parsed = JSON.parse(cached);
          if (Array.isArray(parsed) && parsed.length) {
            statesCache = parsed;
            global.realStates = statesCache;
            return Promise.resolve(statesCache);
          }
        }
      } catch (e) { /* ignore */ }
    }

    if (statesLoading) return statesLoading;

    statesLoading = fetchJson(STATES_URL)
      .then(function (body) {
        statesCache = unwrapItems(body);
        if (!statesCache.length) {
          throw new Error('No states returned from server');
        }
        global.realStates = statesCache;
        try {
          if (statesCache.length) {
            sessionStorage.setItem(STATES_CACHE_KEY, JSON.stringify(statesCache));
          }
        } catch (e) { /* ignore */ }
        return statesCache;
      })
      .catch(function (err) {
        console.error('CA_STATE_CITY: failed to load states', err);
        throw err;
      })
      .finally(function () {
        statesLoading = null;
      });

    return statesLoading;
  }

  function loadCitiesForState(stateId, force) {
    if (!stateId) return Promise.resolve([]);
    var key = String(stateId);
    if (!force && cityCache[key] && cityCache[key].length) {
      return Promise.resolve(cityCache[key]);
    }

    return fetchJson(CITIES_URL + '?state_id=' + encodeURIComponent(key))
      .then(function (body) {
        var cities = unwrapItems(body);
        cityCache[key] = cities;
        return cities;
      });
  }

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function getCombobox(selectEl) {
    if (!selectEl || selectEl.dataset.scEnhanced !== '1') return null;
    return selectEl.parentElement?._scCombobox || null;
  }

  function enhanceCombobox(selectEl, options) {
    if (!selectEl) return null;
    options = options || {};

    var existing = getCombobox(selectEl);
    if (existing) return existing;

    var wrapper = document.createElement('div');
    wrapper.className = 'sc-combobox';
    selectEl.parentNode.insertBefore(wrapper, selectEl);
    wrapper.appendChild(selectEl);

    selectEl.classList.add('sc-combobox-native');
    selectEl.dataset.scEnhanced = '1';

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'input-field sc-combobox-input';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.placeholder = options.placeholder || 'Search...';
    input.disabled = !!options.disabled;
    wrapper.insertBefore(input, selectEl);

    var list = document.createElement('ul');
    list.className = 'sc-combobox-list hidden';
    list.setAttribute('role', 'listbox');
    wrapper.appendChild(list);

    var items = [];
    var activeIndex = -1;
    var open = false;
    var loading = false;
    var loadPromise = null;

    function syncInputFromSelect() {
      var opt = selectEl.options[selectEl.selectedIndex];
      input.value = opt && opt.value ? opt.textContent : '';
    }

    function closeList() {
      open = false;
      list.classList.add('hidden');
      activeIndex = -1;
    }

    function ensureItems() {
      if (items.length) {
        return Promise.resolve();
      }
      if (!options.onNeedItems) {
        return Promise.resolve();
      }
      if (loadPromise) {
        return loadPromise;
      }
      loading = true;
      loadPromise = options.onNeedItems()
        .catch(function (err) {
          console.error('CA_STATE_CITY: combobox load failed', err);
          throw err;
        })
        .finally(function () {
          loading = false;
          loadPromise = null;
        });
      return loadPromise;
    }

    function renderList(filter) {
      var q = (filter || '').trim().toLowerCase();
      var filtered = items.filter(function (item) {
        return !q || item.label.toLowerCase().indexOf(q) >= 0;
      });

      if (!filtered.length) {
        var emptyMsg = loading
          ? (options.loadingLabel || 'Loading...')
          : (items.length ? (options.emptyLabel || 'No matches') : (options.retryLabel || 'No data — click to retry'));
        list.innerHTML = '<li class="sc-combobox-empty">' + escapeHtml(emptyMsg) + '</li>';
        list.classList.remove('hidden');
        open = true;
        return;
      }

      list.innerHTML = filtered.map(function (item, idx) {
        return '<li class="sc-combobox-option' + (idx === activeIndex ? ' is-active' : '') + '" role="option" data-value="' + escapeHtml(item.value) + '" data-label="' + escapeHtml(item.label) + '">' + escapeHtml(item.label) + '</li>';
      }).join('');
      list.classList.remove('hidden');
      open = true;
    }

    function setItems(newItems, selectedValue) {
      items = (newItems || []).map(function (item) {
        return {
          value: String(item.value != null ? item.value : (item.state_id != null ? item.state_id : item.city_id)),
          label: String(item.label != null ? item.label : (item.state_name != null ? item.state_name : item.city_name)),
        };
      }).filter(function (item) {
        return item.label && item.label !== 'undefined';
      });

      var html = '';
      if (options.allowEmpty !== false) {
        html += '<option value="">' + escapeHtml(options.emptyOption || 'Select...') + '</option>';
      }
      html += items.map(function (item) {
        return '<option value="' + escapeHtml(item.value) + '">' + escapeHtml(item.label) + '</option>';
      }).join('');
      selectEl.innerHTML = html;

      if (selectedValue !== undefined && selectedValue !== null && selectedValue !== '') {
        selectEl.value = String(selectedValue);
      } else {
        selectEl.value = '';
      }

      syncInputFromSelect();
      if (open) {
        renderList(input.value);
      } else {
        closeList();
      }
    }

    function choose(value, label) {
      selectEl.value = value || '';
      input.value = label || '';
      closeList();
      selectEl.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function setDisabled(disabled) {
      input.disabled = !!disabled;
      selectEl.disabled = !!disabled;
      if (disabled) {
        input.value = '';
        selectEl.value = '';
        items = [];
        closeList();
      }
    }

    function setLoading(isLoading) {
      loading = !!isLoading;
      if (isLoading) {
        input.placeholder = options.loadingLabel || 'Loading...';
        input.value = '';
        items = [];
        selectEl.innerHTML = '<option value=""></option>';
        input.disabled = false;
        selectEl.disabled = true;
      } else {
        input.placeholder = options.placeholder || 'Search...';
        selectEl.disabled = false;
      }
    }

    function openWithLoad() {
      if (input.disabled) return;
      ensureItems().then(function () {
        renderList(input.value);
      }).catch(function () {
        renderList(input.value);
      });
    }

    input.addEventListener('focus', openWithLoad);
    input.addEventListener('click', openWithLoad);

    input.addEventListener('input', function () {
      if (input.disabled) return;
      activeIndex = -1;
      if (!items.length) {
        openWithLoad();
        return;
      }
      renderList(input.value);
    });

    input.addEventListener('keydown', function (event) {
      if (input.disabled) return;
      var optionsEls = list.querySelectorAll('.sc-combobox-option');
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (!open) openWithLoad();
        activeIndex = Math.min(activeIndex + 1, optionsEls.length - 1);
        renderList(input.value);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        renderList(input.value);
      } else if (event.key === 'Enter') {
        if (open && optionsEls[activeIndex]) {
          event.preventDefault();
          var el = optionsEls[activeIndex];
          choose(el.dataset.value, el.dataset.label);
        }
      } else if (event.key === 'Escape') {
        closeList();
        syncInputFromSelect();
      }
    });

    list.addEventListener('mousedown', function (event) {
      var option = event.target.closest('.sc-combobox-option');
      if (!option || option.classList.contains('sc-combobox-empty')) {
        if (option && option.classList.contains('sc-combobox-empty')) {
          event.preventDefault();
          openWithLoad();
        }
        return;
      }
      event.preventDefault();
      choose(option.dataset.value, option.dataset.label);
    });

    document.addEventListener('click', function (event) {
      if (!wrapper.contains(event.target)) closeList();
    });

    wrapper._scCombobox = {
      setItems: setItems,
      setDisabled: setDisabled,
      setLoading: setLoading,
      setValue: function (value) {
        selectEl.value = value ? String(value) : '';
        syncInputFromSelect();
      },
      clear: function () {
        choose('', '');
      },
      hasItems: function () {
        return items.length > 0;
      },
    };

    return wrapper._scCombobox;
  }

  function findStateSelect(container) {
    return container.querySelector('select[name="state_id"], select[data-sc-role="state"]');
  }

  function findCitySelect(container) {
    return container.querySelector('select[name="city_id"], select[data-sc-role="city"]');
  }

  function populateStateBox(stateBox, states, selectedValue) {
    if (!stateBox) return;
    stateBox.setItems((states || []).map(function (s) {
      return { value: s.state_id, label: s.state_name };
    }), selectedValue || '');
  }

  function bindDependentPair(stateSelect, citySelect, pairOptions) {
    pairOptions = pairOptions || {};
    if (!stateSelect || !citySelect) return null;

    var reloadStates = function () {
      return loadStates(true).then(function (states) {
        populateStateBox(stateBox, states, stateSelect.value);
        return states;
      });
    };

    var stateBox = enhanceCombobox(stateSelect, {
      placeholder: pairOptions.statePlaceholder || 'Search state...',
      emptyOption: pairOptions.stateEmptyOption || 'Select state',
      allowEmpty: pairOptions.stateRequired !== true,
      loadingLabel: 'Loading states...',
      retryLabel: 'Could not load states — click to retry',
      onNeedItems: reloadStates,
    });

    var cityBox = enhanceCombobox(citySelect, {
      placeholder: pairOptions.cityPlaceholder || 'Search city...',
      emptyOption: pairOptions.cityEmptyOption || 'Select city',
      allowEmpty: pairOptions.cityRequired !== true,
      loadingLabel: 'Loading cities...',
      retryLabel: 'Select a state first',
      disabled: true,
    });

    cityBox.setDisabled(true);

    function onStateChange() {
      var stateId = stateSelect.value;
      cityBox.clear();
      if (!stateId) {
        cityBox.setItems([], '');
        cityBox.setDisabled(true);
        return;
      }

      cityBox.setLoading(true);
      loadCitiesForState(stateId, true).then(function (cities) {
        cityBox.setLoading(false);
        cityBox.setItems(cities.map(function (c) {
          return { value: c.city_id, label: c.city_name };
        }), '');
        cityBox.setDisabled(false);
      }).catch(function () {
        cityBox.setLoading(false);
        cityBox.setItems([], '');
        cityBox.setDisabled(true);
      });
    }

    stateSelect.addEventListener('change', onStateChange);

    reloadStates().catch(function () { /* shown on open */ });

    return {
      setValues: function (stateId, cityId) {
        return loadStates().then(function (states) {
          populateStateBox(stateBox, states, stateId || '');

          if (!stateId) {
            cityBox.setItems([], '');
            cityBox.setDisabled(true);
            return;
          }

          return loadCitiesForState(stateId, true).then(function (cities) {
            cityBox.setItems(cities.map(function (c) {
              return { value: c.city_id, label: c.city_name };
            }), cityId || '');
            cityBox.setDisabled(false);
          });
        });
      },
      refreshStates: reloadStates,
      reset: function () {
        stateSelect.value = '';
        citySelect.value = '';
        reloadStates().then(function () {
          cityBox.setItems([], '');
          cityBox.setDisabled(true);
        });
      },
    };
  }

  function bindStandaloneState(stateSelect, options) {
    if (!stateSelect) return null;

    var reload = function () {
      return loadStates(true).then(function (states) {
        populateStateBox(stateBox, states, stateSelect.value);
        return states;
      });
    };

    var stateBox = enhanceCombobox(stateSelect, {
      placeholder: options?.placeholder || 'Search state...',
      emptyOption: options?.emptyOption || 'Select state',
      loadingLabel: 'Loading states...',
      retryLabel: 'Could not load states — click to retry',
      onNeedItems: reload,
    });

    reload().catch(function () { /* shown on open */ });

    return { refresh: reload };
  }

  function initPairContainer(container, options) {
    if (!container) return null;

    var stateSel = findStateSelect(container);
    var citySel = findCitySelect(container);
    if (!stateSel || !citySel) return null;

    if (container._scPair) {
      return container._scPair.refreshStates().then(function () {
        return container._scPair;
      });
    }

    container._scPair = bindDependentPair(stateSel, citySel, options || {});
    container.dataset.scPairReady = '1';
    return Promise.resolve(container._scPair);
  }

  function initStandaloneStates(root) {
    (root || document).querySelectorAll('select[data-sc-standalone-state]').forEach(function (stateSel) {
      if (stateSel.dataset.scStandaloneReady === '1') return;
      stateSel.dataset.scStandaloneReady = '1';
      bindStandaloneState(stateSel, {
        emptyOption: 'Select state',
        placeholder: 'Search state...',
      });
    });
  }

  function initAllPairs(root) {
    root = root || document;
    var jobs = [];

    root.querySelectorAll('.sc-location-pair').forEach(function (container) {
      var isLead = !!container.closest('#form-add-lead');
      var isFilter = !!container.closest('#filter-drawer');
      jobs.push(initPairContainer(container, {
        stateRequired: isLead,
        stateEmptyOption: isLead ? 'Select state *' : (isFilter ? 'All states' : 'Select state'),
        cityEmptyOption: isLead ? 'Select city' : (isFilter ? 'All cities' : 'Select city'),
      }));
    });

    initStandaloneStates(root);

    return Promise.all(jobs).then(function () {
      return loadStates();
    }).catch(function (err) {
      console.error('CA_STATE_CITY init failed', err);
    });
  }

  function prepareModal(modalEl) {
    if (!modalEl) return Promise.resolve();
    return initAllPairs(modalEl);
  }

  function prepareForm(formId) {
    var form = typeof formId === 'string' ? document.getElementById(formId) : formId;
    if (!form) return Promise.resolve();
    return initAllPairs(form);
  }

  function resetFormLocations(form) {
    if (!form) return;
    form.querySelectorAll('.sc-location-pair').forEach(function (container) {
      if (container._scPair && container._scPair.reset) {
        container._scPair.reset();
      }
    });
  }

  function setLeadLocationValues(stateId, cityId) {
    return prepareForm('form-add-lead').then(function () {
      var container = document.querySelector('#form-add-lead .sc-location-pair');
      if (!container || !container._scPair) return;
      return container._scPair.setValues(stateId, cityId);
    });
  }

  global.CA_STATE_CITY = {
    loadStates: loadStates,
    loadCitiesForState: loadCitiesForState,
    enhanceCombobox: enhanceCombobox,
    bindDependentPair: bindDependentPair,
    initAllPairs: initAllPairs,
    prepareModal: prepareModal,
    prepareForm: prepareForm,
    resetFormLocations: resetFormLocations,
    setLeadLocationValues: setLeadLocationValues,
    clearCache: function () {
      statesCache = null;
      cityCache = {};
      statesLoading = null;
      try {
        sessionStorage.removeItem(STATES_CACHE_KEY);
        sessionStorage.removeItem('crm:states:v4');
        sessionStorage.removeItem('crm:states:v3');
      } catch (e) { /* ignore */ }
    },
  };
})(window);
