/**
 * Reusable CRM table footer pagination — matches enterprise three-column layout.
 * window.CATablePagination
 */
(function () {
  'use strict';

  var PER_PAGE_OPTIONS = [10, 25, 50, 100, 200, 500, 1000];
  var FOLLOWUP_PER_PAGE_OPTIONS = [10, 25, 50, 100, 200];
  var DEFAULT_PER_PAGE = 10;
  var _scopeHandlers = {};

  function esc(val) {
    if (val == null) return '';
    return String(val)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;');
  }

  function normalizePerPage(value, options) {
    var list = Array.isArray(options) && options.length ? options : PER_PAGE_OPTIONS;
    var n = parseInt(value, 10);
    if (list.indexOf(n) >= 0) return n;
    return list.indexOf(DEFAULT_PER_PAGE) >= 0 ? DEFAULT_PER_PAGE : list[0];
  }

  function resolvePerPageOptions(options) {
    if (options && Array.isArray(options.perPageOptions) && options.perPageOptions.length) {
      return options.perPageOptions.slice();
    }
    if (options && (options.listingKey === 'follow_ups' || options.scope === 'followup-activity')) {
      return FOLLOWUP_PER_PAGE_OPTIONS.slice();
    }
    return PER_PAGE_OPTIONS.slice();
  }

  function visibleCount(pagination) {
    if (!pagination) return 0;
    var from = pagination.from;
    var to = pagination.to;
    if (from != null && to != null && to >= from) {
      return to - from + 1;
    }
    var per = pagination.per_page || DEFAULT_PER_PAGE;
    var total = pagination.total || 0;
    var page = pagination.current_page || 1;
    if (!total) return 0;
    var remaining = total - (page - 1) * per;
    return Math.max(0, Math.min(per, remaining));
  }

  function register(scope, handlers) {
    if (!scope) return;
    _scopeHandlers[scope] = handlers || {};
  }

  function renderHtml(options) {
    options = options || {};
    var p = options.pagination || {};
    var current = p.current_page || 1;
    var last = Math.max(1, p.last_page || 1);
    var total = p.total || 0;
    var perPageOptions = resolvePerPageOptions(options);
    var perPage = normalizePerPage(options.perPage || p.per_page || DEFAULT_PER_PAGE, perPageOptions);
    var visible = visibleCount(p);
    var listingKey = options.listingKey || '';
    var scope = options.scope || '';
    var wrapId = options.wrapId || '';
    var showPerPage = options.showPerPage !== false;
    var prevPage = Math.max(1, current - 1);
    var nextPage = Math.min(last, current + 1);

    var perPageHtml = showPerPage
      ? '<div class="crm-table-pagination__left">' +
          '<label class="crm-table-pagination__rows">' +
            '<span class="crm-table-pagination__rows-label">Rows per page</span>' +
            '<select class="crm-table-pagination__per-page" aria-label="Rows per page">' +
              perPageOptions.map(function (n) {
                return '<option value="' + n + '"' + (n === perPage ? ' selected' : '') + '>' + n + '</option>';
              }).join('') +
            '</select>' +
          '</label>' +
        '</div>'
      : '<div class="crm-table-pagination__left" aria-hidden="true"></div>';

    return '<div class="crm-table-pagination"' +
      (wrapId ? ' id="' + esc(wrapId) + '"' : '') +
      (listingKey ? ' data-listing="' + esc(listingKey) + '"' : '') +
      (scope ? ' data-pagination-scope="' + esc(scope) + '"' : '') +
      '>' +
      perPageHtml +
      '<div class="crm-table-pagination__center" role="navigation" aria-label="Table pagination">' +
        '<button type="button" class="crm-table-pagination__nav" data-pagination-nav="prev" data-page="' + prevPage + '" aria-label="Previous page"' + (current <= 1 ? ' disabled' : '') + '>' +
          '<i data-lucide="chevron-left" class="h-4 w-4" aria-hidden="true"></i>' +
        '</button>' +
        '<span class="crm-table-pagination__page" aria-current="page">' + current + '</span>' +
        '<span class="crm-table-pagination__of">of ' + last + '</span>' +
        '<button type="button" class="crm-table-pagination__nav" data-pagination-nav="next" data-page="' + nextPage + '" aria-label="Next page"' + (current >= last ? ' disabled' : '') + '>' +
          '<i data-lucide="chevron-right" class="h-4 w-4" aria-hidden="true"></i>' +
        '</button>' +
      '</div>' +
      '<div class="crm-table-pagination__right">' +
        '<span class="crm-table-pagination__count" aria-live="polite">' + visible + ' / ' + total + '</span>' +
      '</div>' +
    '</div>';
  }

  function resolveTarget(target) {
    if (!target) return null;
    if (typeof target === 'string') return document.getElementById(target);
    return target;
  }

  function mountTarget(slot, tableId) {
    if (slot) return slot;
    if (!tableId) return null;
    var table = document.getElementById(tableId);
    if (!table) return null;
    var card = table.closest('.crm-table-card, .card, .assign-active__table-wrap');
    if (card) {
      var existing = card.querySelector('.crm-table-footer');
      if (existing) return existing;
      var footer = document.createElement('div');
      footer.className = 'crm-table-footer';
      card.appendChild(footer);
      return footer;
    }
    return table.parentElement;
  }

  function renderInto(target, options) {
    options = options || {};
    var slot = resolveTarget(target);
    slot = mountTarget(slot, options.tableId);
    if (!slot) return null;

    var p = options.pagination;
    if (!p || !p.total) {
      slot.innerHTML = '';
      slot.classList.add('crm-table-footer--empty');
      return null;
    }

    slot.classList.remove('crm-table-footer--empty');
    slot.innerHTML = renderHtml({
      pagination: p,
      listingKey: options.listingKey,
      scope: options.scope,
      perPage: options.perPage,
      perPageOptions: options.perPageOptions,
      showPerPage: options.showPerPage,
    });

    if (typeof icons === 'function') icons();
    return slot;
  }

  function initDelegated() {
    if (document._tablePaginationDelegated) return;
    document._tablePaginationDelegated = true;

    document.addEventListener('click', function (e) {
      var nav = e.target.closest('[data-pagination-nav]');
      if (!nav || nav.disabled) return;
      var wrap = nav.closest('.crm-table-pagination');
      if (!wrap) return;
      e.preventDefault();
      var page = parseInt(nav.getAttribute('data-page'), 10);
      if (!page) return;

      var listingKey = wrap.getAttribute('data-listing');
      if (listingKey && window.CA_LISTING_SEARCH) {
        CA_LISTING_SEARCH.setState(listingKey, { page: page });
        CA_LISTING_SEARCH.reload(listingKey);
        return;
      }

      var scope = wrap.getAttribute('data-pagination-scope');
      if (scope && _scopeHandlers[scope] && typeof _scopeHandlers[scope].onPageChange === 'function') {
        _scopeHandlers[scope].onPageChange(page, wrap);
      }
    });

    document.addEventListener('change', function (e) {
      var sel = e.target.closest('.crm-table-pagination__per-page');
      if (!sel) return;
      var wrap = sel.closest('.crm-table-pagination');
      if (!wrap) return;
      var perPage = parseInt(sel.value, 10);
      if (!perPage) return;

      var listingKey = wrap.getAttribute('data-listing');
      if (listingKey === 'follow_ups' || wrap.getAttribute('data-pagination-scope') === 'followup-activity') {
        perPage = normalizePerPage(perPage, FOLLOWUP_PER_PAGE_OPTIONS);
      }

      if (listingKey && window.CA_LISTING_SEARCH) {
        CA_LISTING_SEARCH.setState(listingKey, { page: 1, per_page: perPage });
        CA_LISTING_SEARCH.reload(listingKey);
        return;
      }

      var scope = wrap.getAttribute('data-pagination-scope');
      if (scope && _scopeHandlers[scope] && typeof _scopeHandlers[scope].onPerPageChange === 'function') {
        _scopeHandlers[scope].onPerPageChange(perPage, wrap);
      }
    });
  }

  window.CATablePagination = {
    DEFAULT_PER_PAGE: DEFAULT_PER_PAGE,
    PER_PAGE_OPTIONS: PER_PAGE_OPTIONS,
    FOLLOWUP_PER_PAGE_OPTIONS: FOLLOWUP_PER_PAGE_OPTIONS,
    normalizePerPage: normalizePerPage,
    visibleCount: visibleCount,
    renderHtml: renderHtml,
    renderInto: renderInto,
    register: register,
    init: initDelegated,
  };

  initDelegated();
})();
