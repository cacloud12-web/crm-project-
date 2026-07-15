/**
 * Shared compact single-row filter toolbar for Reports module pages.
 * Date fields use CrmDateTimePicker (one in-input calendar icon only).
 */
window.CrmReportFilterToolbar = (function () {
  'use strict';

  var DATE_ATTRS = 'data-crm-date-input data-allow-past data-hide-preview data-optional';

  /** Standard field order for all report toolbars. */
  var FIELD_ORDER = ['date', 'singleDate', 'employee', 'employeeSearch', 'type', 'status', 'search'];

  /** In-memory filter values keyed by report page (survives Apply re-renders). */
  var sharedFilterState = Object.create(null);

  function escapeAttr(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function dateField(id, label, placeholder) {
    return (
      '<div class="crm-report-filter crm-report-filter--date">' +
        '<span class="crm-report-filter__label">' + escapeAttr(label) + '</span>' +
        '<input type="text" id="' + escapeAttr(id) + '" class="input-field input-field-sm crm-report-filter__control" ' +
          DATE_ATTRS + ' data-placeholder="' + escapeAttr(placeholder) + '" autocomplete="off" ' +
          'aria-label="' + escapeAttr(placeholder) + '" />' +
      '</div>'
    );
  }

  function selectField(cfg) {
    var icon = cfg.icon
      ? '<i data-lucide="' + escapeAttr(cfg.icon) + '" class="h-3.5 w-3.5 crm-report-filter__leading-icon" aria-hidden="true"></i>'
      : '';
    return (
      '<div class="crm-report-filter crm-report-filter--select' + (cfg.grow ? ' crm-report-filter--grow' : '') + '">' +
        '<span class="crm-report-filter__label">' + escapeAttr(cfg.label) + '</span>' +
        '<div class="crm-report-filter__control-wrap">' +
          icon +
          '<select id="' + escapeAttr(cfg.id) + '" class="input-field input-field-sm crm-report-filter__control" aria-label="' + escapeAttr(cfg.label) + '">' +
            (cfg.options || '') +
          '</select>' +
        '</div>' +
      '</div>'
    );
  }

  function searchField(cfg) {
    return (
      '<div class="crm-report-filter crm-report-filter--search crm-report-filter--grow">' +
        '<span class="crm-report-filter__label">' + escapeAttr(cfg.label || 'SEARCH') + '</span>' +
        '<div class="crm-report-filter__control-wrap">' +
          '<i data-lucide="search" class="h-3.5 w-3.5 crm-report-filter__leading-icon" aria-hidden="true"></i>' +
          '<input type="search" id="' + escapeAttr(cfg.id) + '" class="input-field input-field-sm crm-report-filter__control" ' +
            'placeholder="' + escapeAttr(cfg.placeholder || 'Search…') + '" aria-label="' + escapeAttr(cfg.label || 'Search') + '" autocomplete="off" />' +
        '</div>' +
      '</div>'
    );
  }

  /**
   * Build fields in standard order: From/To Date, Employee, Type, Status, Search.
   * @param {string[]} enabled e.g. ['date','employee','status','search']
   * @param {object} defs field id/options map
   */
  function buildFieldsFromPreset(enabled, defs) {
    enabled = enabled || [];
    defs = defs || {};
    var fields = [];
    var push = function (field) {
      if (field) fields.push(field);
    };

    FIELD_ORDER.forEach(function (key) {
      if (enabled.indexOf(key) < 0) return;
      if (key === 'date') {
        push({ kind: 'dateFrom', id: defs.fromId });
        push({ kind: 'dateTo', id: defs.toId });
        return;
      }
      if (key === 'singleDate') {
        push({
          kind: 'date',
          id: defs.dateId,
          label: defs.dateLabel || 'DATE',
          placeholder: defs.datePlaceholder || 'Select Date',
        });
        return;
      }
      if (key === 'employee' && defs.employee) {
        push({
          kind: 'select',
          id: defs.employee.id,
          label: 'EMPLOYEE',
          icon: 'user',
          grow: defs.employee.grow !== false,
          options: defs.employee.options || '<option value="">All employees</option>',
        });
        return;
      }
      if (key === 'employeeSearch' && defs.employeeSearch) {
        push({
          kind: 'search',
          id: defs.employeeSearch.id,
          label: 'EMPLOYEE',
          placeholder: defs.employeeSearch.placeholder || 'Search employee…',
        });
        return;
      }
      if (key === 'type' && defs.type) {
        push({
          kind: 'select',
          id: defs.type.id,
          label: defs.type.label || 'TYPE',
          icon: 'filter',
          options: defs.type.options || '<option value="">All types</option>',
        });
        return;
      }
      if (key === 'status' && defs.status) {
        push({
          kind: 'select',
          id: defs.status.id,
          label: defs.status.label || 'STATUS',
          icon: 'list-filter',
          options: defs.status.options || '<option value="">All statuses</option>',
        });
        return;
      }
      if (key === 'search' && defs.search) {
        push({
          kind: 'search',
          id: defs.search.id,
          label: defs.search.label || 'SEARCH',
          placeholder: defs.search.placeholder || 'Search…',
        });
      }
    });

    return fields;
  }

  function initToolbar(root) {
    initDatePickers(root);
    if (window.lucide) window.lucide.createIcons({ nodes: [root] });
  }

  function buildField(field) {
    if (!field || !field.kind) return '';
    if (field.kind === 'dateFrom') {
      return dateField(field.id, field.label || 'FROM DATE', field.placeholder || 'From Date');
    }
    if (field.kind === 'dateTo') {
      return dateField(field.id, field.label || 'TO DATE', field.placeholder || 'To Date');
    }
    if (field.kind === 'date') {
      return dateField(field.id, field.label || 'DATE', field.placeholder || 'Select Date');
    }
    if (field.kind === 'select') return selectField(field);
    if (field.kind === 'search') return searchField(field);
    return '';
  }

  /**
   * @param {object} cfg
   * @param {string} [cfg.wrapperId]
   * @param {string} [cfg.wrapperClass]
   * @param {string} [cfg.errorId]
   * @param {string} cfg.applyId
   * @param {string} cfg.resetId
   * @param {string} [cfg.applyLabel]
   * @param {Array} cfg.fields
   */
  function build(cfg) {
    cfg = cfg || {};
    var parts = (cfg.fields || []).map(buildField).filter(Boolean);
    parts.push(
      '<button type="button" class="btn-primary btn-sm crm-report-filter__apply" id="' + escapeAttr(cfg.applyId) + '">' +
        escapeAttr(cfg.applyLabel || 'Apply') +
      '</button>',
      '<button type="button" class="crm-toolbar-icon-btn crm-report-filter__reset" id="' + escapeAttr(cfg.resetId) + '" ' +
        'data-crm-tip="Reset Filters" aria-label="Reset Filters">' +
        '<i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>' +
      '</button>'
    );
    var errorHtml = cfg.errorId
      ? '<p class="crm-report-filter__error hidden" id="' + escapeAttr(cfg.errorId) + '" role="alert"></p>'
      : '';
    var wrapperClass = 'crm-report-filter-toolbar' + (cfg.wrapperClass ? ' ' + cfg.wrapperClass : '');
    var idAttr = cfg.wrapperId ? ' id="' + escapeAttr(cfg.wrapperId) + '"' : '';
    return (
      '<div class="' + wrapperClass + '"' + idAttr + '>' +
        '<div class="crm-report-filter-toolbar__row">' + parts.join('') + '</div>' +
        errorHtml +
      '</div>'
    );
  }

  function initDatePickers(root) {
    if (!root || !window.CrmDateTimePicker) return;
    window.CrmDateTimePicker.initAll(root);
    window.CrmDateTimePicker.syncAll(root);
  }

  function readInputValue(id) {
    var el = document.getElementById(id);
    return el && el.value ? String(el.value).trim() : '';
  }

  function showDateRangeError(errorId, message) {
    var el = errorId ? document.getElementById(errorId) : null;
    if (!el) {
      if (window.toast) window.toast(message, 'warning');
      return;
    }
    el.textContent = message;
    el.classList.remove('hidden');
  }

  function hideDateRangeError(errorId) {
    var el = errorId ? document.getElementById(errorId) : null;
    if (el) {
      el.textContent = '';
      el.classList.add('hidden');
    }
  }

  function writeInputValue(id, value) {
    var el = document.getElementById(id);
    if (!el) return;
    el.value = value == null ? '' : String(value);
    if (window.CrmDateTimePicker) {
      window.CrmDateTimePicker.syncAll(el.closest('.crm-report-filter-toolbar') || el.closest('.crm-report-page') || document);
    }
  }

  function clearDateFields(fromId, toId, errorId) {
    writeInputValue(fromId, '');
    writeInputValue(toId, '');
    hideDateRangeError(errorId);
  }

  /**
   * @param {string} key shared state key (e.g. report slug or page id)
   * @param {Object.<string,string>} fieldMap logicalName -> element id
   */
  function captureFields(key, fieldMap) {
    var values = {};
    Object.keys(fieldMap || {}).forEach(function (name) {
      values[name] = readInputValue(fieldMap[name]);
    });
    sharedFilterState[key] = values;
    return values;
  }

  /**
   * Restore captured values into the DOM without clearing other controls.
   * @param {string} key
   * @param {Object.<string,string>} fieldMap
   * @param {object} [fallback] used when no shared state exists yet
   */
  function restoreFields(key, fieldMap, fallback) {
    var values = sharedFilterState[key] || fallback || {};
    Object.keys(fieldMap || {}).forEach(function (name) {
      if (!Object.prototype.hasOwnProperty.call(values, name)) return;
      writeInputValue(fieldMap[name], values[name]);
    });
    return values;
  }

  function getSharedState(key) {
    return sharedFilterState[key] ? Object.assign({}, sharedFilterState[key]) : null;
  }

  function setSharedState(key, values) {
    sharedFilterState[key] = Object.assign({}, values || {});
    return getSharedState(key);
  }

  function clearSharedState(key) {
    delete sharedFilterState[key];
  }

  /**
   * Validate From/To date range (YYYY-MM-DD from hidden source inputs).
   * @returns {boolean}
   */
  function validateDateRange(fromId, toId, errorId) {
    var from = readInputValue(fromId);
    var to = readInputValue(toId);
    hideDateRangeError(errorId);
    if (from && to && from > to) {
      showDateRangeError(errorId, 'From Date cannot be later than To Date.');
      return false;
    }
    if (from && !to) {
      showDateRangeError(errorId, 'Please select a To Date to complete the range.');
      return false;
    }
    if (!from && to) {
      showDateRangeError(errorId, 'Please select a From Date to complete the range.');
      return false;
    }
    return true;
  }

  return {
    build: build,
    buildFieldsFromPreset: buildFieldsFromPreset,
    initDatePickers: initDatePickers,
    initToolbar: initToolbar,
    validateDateRange: validateDateRange,
    hideDateRangeError: hideDateRangeError,
    writeInputValue: writeInputValue,
    clearDateFields: clearDateFields,
    readInputValue: readInputValue,
    captureFields: captureFields,
    restoreFields: restoreFields,
    getSharedState: getSharedState,
    setSharedState: setSharedState,
    clearSharedState: clearSharedState,
    DATE_ATTRS: DATE_ATTRS,
    FIELD_ORDER: FIELD_ORDER,
  };
})();
