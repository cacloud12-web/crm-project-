/* global window, document, sessionStorage */
(function (global) {
  'use strict';

  var STATES_CACHE_KEY = 'crm:states:v6';
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
    var key = stateId ? String(stateId) : 'all';
    if (!force && cityCache[key] && cityCache[key].length) {
      return Promise.resolve(cityCache[key]);
    }

    var url = stateId
      ? (CITIES_URL + '?state_id=' + encodeURIComponent(key))
      : CITIES_URL;

    return fetchJson(url)
      .then(function (body) {
        var cities = unwrapItems(body);
        cityCache[key] = cities;
        return cities;
      });
  }

  function isNumericId(value) {
    return value != null && value !== '' && /^\d+$/.test(String(value));
  }

  function normalizeLocationLabel(value) {
    if (value == null || value === '' || value === '—') return '';
    return String(value).trim();
  }

  function findStateByRef(states, stateRef, stateName) {
    if (isNumericId(stateRef)) {
      for (var i = 0; i < (states || []).length; i++) {
        if (String(states[i].state_id) === String(stateRef)) return states[i];
      }
    }
    var name = normalizeLocationLabel(stateName) || (!isNumericId(stateRef) ? normalizeLocationLabel(stateRef) : '');
    if (!name) return null;
    var lower = name.toLowerCase();
    for (var j = 0; j < (states || []).length; j++) {
      if (String(states[j].state_name || '').toLowerCase() === lower) return states[j];
    }
    return null;
  }

  function findCityByRef(cities, cityRef, cityName) {
    if (isNumericId(cityRef)) {
      for (var i = 0; i < (cities || []).length; i++) {
        if (String(cities[i].city_id) === String(cityRef)) return cities[i];
      }
    }
    var name = normalizeLocationLabel(cityName) || (!isNumericId(cityRef) ? normalizeLocationLabel(cityRef) : '');
    if (!name) return null;
    var lower = name.toLowerCase();
    for (var k = 0; k < (cities || []).length; k++) {
      if (String(cities[k].city_name || '').toLowerCase() === lower) return cities[k];
    }
    return null;
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
          state_id: item.state_id != null ? item.state_id : null,
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
      setDisplay: function (value, label) {
        var val = value != null && value !== '' ? String(value) : '';
        var text = normalizeLocationLabel(label);
        if (val) {
          var exists = false;
          for (var i = 0; i < selectEl.options.length; i++) {
            if (selectEl.options[i].value === val) {
              exists = true;
              break;
            }
          }
          if (!exists && text) {
            var opt = document.createElement('option');
            opt.value = val;
            opt.textContent = text;
            selectEl.appendChild(opt);
          }
          selectEl.value = val;
          input.value = text || (selectEl.selectedIndex >= 0 ? selectEl.options[selectEl.selectedIndex].textContent : '') || '';
        } else if (text) {
          selectEl.value = '';
          input.value = text;
        } else {
          selectEl.value = '';
          input.value = '';
        }
        closeList();
      },
      clear: function () {
        choose('', '');
      },
      hasItems: function () {
        return items.length > 0;
      },
      getItemByValue: function (value) {
        var key = value == null ? '' : String(value);
        if (!key) return null;
        for (var i = 0; i < items.length; i++) {
          if (String(items[i].value) === key) return items[i];
        }
        return null;
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

  function findCityStateIdInCache(cityId) {
    var key = cityId == null ? '' : String(cityId);
    if (!key) return null;
    var cacheKeys = Object.keys(cityCache);
    for (var i = 0; i < cacheKeys.length; i++) {
      var cities = cityCache[cacheKeys[i]] || [];
      for (var j = 0; j < cities.length; j++) {
        if (String(cities[j].city_id) === key && cities[j].state_id != null) {
          return cities[j].state_id;
        }
      }
    }
    return null;
  }

  function resolveStateIdForCity(cityId) {
    var key = cityId == null ? '' : String(cityId);
    if (!key) return Promise.resolve(null);
    var cached = findCityStateIdInCache(key);
    if (cached != null) return Promise.resolve(cached);
    return loadCitiesForState(null).then(function (cities) {
      for (var i = 0; i < (cities || []).length; i++) {
        if (String(cities[i].city_id) === key && cities[i].state_id != null) {
          return cities[i].state_id;
        }
      }
      return null;
    }).catch(function () {
      return null;
    });
  }

  function bindDependentPair(stateSelect, citySelect, pairOptions) {
    pairOptions = pairOptions || {};
    if (!stateSelect || !citySelect) return null;

    var allowAllCities = !!pairOptions.allowAllCities;
    var syncStateFromCity = pairOptions.syncStateFromCity !== false;
    var syncingFromCity = false;

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
      retryLabel: allowAllCities ? 'Could not load cities — click to retry' : 'Select a state first',
      disabled: !allowAllCities,
    });

    if (!allowAllCities) {
      cityBox.setDisabled(true);
    }

    function loadCitiesForCurrentState(selectedCityId) {
      var stateId = stateSelect.value || '';
      if (!stateId && !allowAllCities) {
        cityBox.setItems([], '');
        cityBox.setDisabled(true);
        return Promise.resolve([]);
      }

      cityBox.setLoading(true);
      return loadCitiesForState(stateId || null, true).then(function (cities) {
        cityBox.setLoading(false);
        cityBox.setItems((cities || []).map(function (c) {
          return { value: c.city_id, label: c.city_name, state_id: c.state_id };
        }), selectedCityId || '');
        cityBox.setDisabled(false);
        return cities;
      }).catch(function () {
        cityBox.setLoading(false);
        cityBox.setItems([], '');
        if (!allowAllCities) cityBox.setDisabled(true);
        return [];
      });
    }

    function applyStateFromCity(cityId) {
      if (!syncStateFromCity || !cityId) return Promise.resolve(null);
      var fromItem = cityBox.getItemByValue ? cityBox.getItemByValue(cityId) : null;
      var knownStateId = fromItem && fromItem.state_id != null ? fromItem.state_id : findCityStateIdInCache(cityId);
      var resolve = knownStateId != null
        ? Promise.resolve(knownStateId)
        : resolveStateIdForCity(cityId);

      return resolve.then(function (stateId) {
        if (stateId == null || stateId === '') return null;
        if (String(stateSelect.value) === String(stateId)) return stateId;
        syncingFromCity = true;
        return loadStates().then(function (states) {
          populateStateBox(stateBox, states, stateId);
          return loadCitiesForCurrentState(cityId).then(function () {
            return stateId;
          });
        }).finally(function () {
          syncingFromCity = false;
        });
      });
    }

    function onStateChange() {
      if (syncingFromCity) return;
      cityBox.clear();
      if (!stateSelect.value && !allowAllCities) {
        cityBox.setItems([], '');
        cityBox.setDisabled(true);
        return;
      }
      loadCitiesForCurrentState('');
    }

    function onCityChange() {
      if (syncingFromCity) return;
      var cityId = citySelect.value || '';
      if (!cityId) return;
      applyStateFromCity(cityId);
    }

    stateSelect.addEventListener('change', onStateChange);
    citySelect.addEventListener('change', onCityChange);

    reloadStates().then(function () {
      if (allowAllCities || stateSelect.value) {
        return loadCitiesForCurrentState(citySelect.value || '').then(function () {
          if (citySelect.value) return applyStateFromCity(citySelect.value);
        });
      }
    }).catch(function () { /* shown on open */ });

    return {
      setValues: function (stateRef, cityRef, hints) {
        hints = hints || {};
        var hintStateName = hints.stateName || '';
        var hintCityName = hints.cityName || '';

        return loadStates().then(function (states) {
          var stateMatch = findStateByRef(states, stateRef, hintStateName);
          var resolvedStateId = stateMatch ? String(stateMatch.state_id) : (isNumericId(stateRef) ? String(stateRef) : '');
          var displayStateName = stateMatch
            ? stateMatch.state_name
            : (normalizeLocationLabel(hintStateName) || (!isNumericId(stateRef) ? normalizeLocationLabel(stateRef) : ''));

          populateStateBox(stateBox, states, resolvedStateId || '');
          if (!resolvedStateId && displayStateName) stateBox.setDisplay('', displayStateName);

          var loadScope = resolvedStateId || (allowAllCities ? null : '');
          if (!loadScope && !allowAllCities) {
            cityBox.setItems([], '');
            cityBox.setDisabled(true);
            return null;
          }

          cityBox.setLoading(true);
          return loadCitiesForState(loadScope, true).then(function (cities) {
            cityBox.setLoading(false);
            var cityMatch = findCityByRef(cities, cityRef, hintCityName);
            var resolvedCityId = cityMatch ? String(cityMatch.city_id) : (isNumericId(cityRef) ? String(cityRef) : '');
            var displayCityName = cityMatch
              ? cityMatch.city_name
              : (normalizeLocationLabel(hintCityName) || (!isNumericId(cityRef) ? normalizeLocationLabel(cityRef) : ''));

            if (cityMatch && cityMatch.state_id != null && !resolvedStateId) {
              resolvedStateId = String(cityMatch.state_id);
              populateStateBox(stateBox, states, resolvedStateId);
            }

            cityBox.setItems((cities || []).map(function (c) {
              return { value: c.city_id, label: c.city_name, state_id: c.state_id };
            }), resolvedCityId || '');

            if (resolvedCityId && displayCityName && !cityMatch) {
              cityBox.setDisplay(resolvedCityId, displayCityName);
            } else if (!resolvedCityId && displayCityName) {
              cityBox.setDisplay('', displayCityName);
            }

            cityBox.setDisabled(false);

            if (resolvedCityId) return applyStateFromCity(resolvedCityId);
            return null;
          }).catch(function () {
            cityBox.setLoading(false);
            if (!allowAllCities) cityBox.setDisabled(true);
            if (normalizeLocationLabel(hintCityName) || (!isNumericId(cityRef) && normalizeLocationLabel(cityRef))) {
              cityBox.setDisplay('', normalizeLocationLabel(hintCityName) || normalizeLocationLabel(cityRef));
            }
            return null;
          });
        });
      },
      refreshStates: reloadStates,
      syncStateFromCity: applyStateFromCity,
      reset: function () {
        stateSelect.value = '';
        citySelect.value = '';
        if (stateBox && stateBox.setValue) stateBox.setValue('');
        if (cityBox && cityBox.clear) cityBox.clear();
        reloadStates().then(function () {
          if (allowAllCities) {
            return loadCitiesForCurrentState('');
          }
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
      var leadForm = container.closest('#form-add-lead');
      var isLead = !!leadForm;
      var isEmployeeLeadAdd = isLead && leadForm.dataset.employeeAddMode === '1';
      var isFilter = !!container.closest('#filter-drawer, #bulk-assignment-panel, #bulk-export-filters-wrap');
      jobs.push(initPairContainer(container, {
        stateRequired: isLead || isEmployeeLeadAdd,
        cityRequired: isEmployeeLeadAdd,
        allowAllCities: isLead || (isFilter && !!container.closest('#bulk-assignment-panel')),
        syncStateFromCity: true,
        stateEmptyOption: isLead || isEmployeeLeadAdd ? 'Select state *' : (isFilter ? 'Any State' : 'Select state'),
        cityEmptyOption: isEmployeeLeadAdd ? 'Select city *' : (isLead ? 'Select city' : (isFilter ? 'Any City' : 'Select city')),
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

  function setLeadLocationValues(stateRef, cityRef, hints) {
    return prepareForm('form-add-lead').then(function () {
      var container = document.querySelector('#form-add-lead .sc-location-pair');
      if (!container || !container._scPair) return null;
      return container._scPair.setValues(stateRef, cityRef, hints || {});
    });
  }

  global.CA_STATE_CITY = {
    loadStates: loadStates,
    loadCitiesForState: loadCitiesForState,
    resolveStateIdForCity: resolveStateIdForCity,
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
        sessionStorage.removeItem('crm:states:v5');
        sessionStorage.removeItem('crm:states:v4');
        sessionStorage.removeItem('crm:states:v3');
      } catch (e) { /* ignore */ }
    },
  };
})(window);
