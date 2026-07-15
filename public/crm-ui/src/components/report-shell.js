/**
 * Shared report page chrome for the Reports module.
 * Mounts inside the CRM `#page-container` (sidebar + top navbar stay visible).
 * Used by analytics report detail pages and Duplicate Attempts.
 */
window.CrmReportShell = (function () {
  'use strict';

  function escapeHtml(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function escapeAttr(text) {
    return escapeHtml(text);
  }

  /**
   * @param {object} cfg
   * @param {string} cfg.title
   * @param {string} [cfg.subtitle]
   * @param {string} [cfg.icon] lucide icon name
   * @param {string} [cfg.titleId]
   * @param {string} [cfg.backId]
   * @param {string} [cfg.backNav] data-nav-page target
   * @param {string} [cfg.actionsHtml]
   * @param {boolean} [cfg.showBack]
   */
  function buildHeader(cfg) {
    cfg = cfg || {};
    var backHtml = '';
    if (cfg.showBack !== false) {
      var backId = cfg.backId || 'crm-report-back';
      var navAttr = cfg.backNav ? ' data-nav-page="' + escapeAttr(cfg.backNav) + '"' : '';
      backHtml =
        '<button type="button" class="crm-toolbar-icon-btn crm-report-shell__back" id="' + escapeAttr(backId) + '"' +
          navAttr + ' title="Back" aria-label="Back">' +
          '<i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>' +
        '</button>';
    }
    var iconHtml = cfg.icon
      ? '<span class="ra-lc-header__icon" aria-hidden="true"><i data-lucide="' + escapeAttr(cfg.icon) + '" class="h-5 w-5"></i></span>'
      : '';
    var titleIdAttr = cfg.titleId ? ' id="' + escapeAttr(cfg.titleId) + '"' : '';
    return (
      '<header class="crm-report-shell ra-lc-header">' +
        backHtml +
        '<div class="ra-lc-header__title">' +
          '<div class="ra-lc-header__brand">' +
            iconHtml +
            '<div class="ra-lc-header__text">' +
              '<h2' + titleIdAttr + '>' + escapeHtml(cfg.title || 'Report') + '</h2>' +
              (cfg.subtitle ? '<p class="ra-lc-header__subtitle">' + escapeHtml(cfg.subtitle) + '</p>' : '') +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div class="ra-lc-header__actions">' + (cfg.actionsHtml || '') + '</div>' +
      '</header>'
    );
  }

  function buildPageActions() {
    return (
      '<div class="ra-export-menu">' +
        '<button type="button" class="crm-toolbar-icon-btn" id="ra-export-toggle" title="Export" aria-label="Export">' +
          '<i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>' +
        '</button>' +
        '<div class="ra-export-menu__list hidden" id="ra-export-list">' +
          '<button type="button" data-ra-export="pdf">PDF</button>' +
          '<button type="button" data-ra-export="csv">Excel / CSV</button>' +
          '<button type="button" data-ra-export="print">Print</button>' +
        '</div>' +
      '</div>' +
      '<button type="button" class="crm-toolbar-icon-btn" id="ra-refresh" title="Refresh" aria-label="Refresh">' +
        '<i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>' +
      '</button>' +
      '<button type="button" class="crm-toolbar-icon-btn" id="ra-share" title="Share" aria-label="Share">' +
        '<i data-lucide="share-2" class="h-4 w-4" aria-hidden="true"></i>' +
      '</button>' +
      '<button type="button" class="crm-toolbar-icon-btn" id="ra-print" title="Print" aria-label="Print">' +
        '<i data-lucide="printer" class="h-4 w-4" aria-hidden="true"></i>' +
      '</button>'
    );
  }

  /** @deprecated Use buildPageActions — kept for callers that still use the old name. */
  function buildDrawerActions() {
    return buildPageActions();
  }

  /**
   * @param {object} cfg
   * @param {string} cfg.bodyHtml
   * @param {string} [cfg.filtersHtml]
   */
  function buildStandalonePage(cfg) {
    cfg = cfg || {};
    return (
      '<div class="crm-report-page">' +
        buildHeader(cfg) +
        (cfg.filtersHtml || '') +
        '<div class="crm-report-page__body">' + (cfg.bodyHtml || '') + '</div>' +
      '</div>'
    );
  }

  function init(root) {
    if (!root) return;
    if (window.CrmReportFilterToolbar && typeof window.CrmReportFilterToolbar.initToolbar === 'function') {
      window.CrmReportFilterToolbar.initToolbar(root);
      return;
    }
    if (window.CrmDateTimePicker) {
      window.CrmDateTimePicker.initAll(root);
      window.CrmDateTimePicker.syncAll(root);
    }
    if (window.lucide) window.lucide.createIcons({ nodes: [root] });
  }

  return {
    buildHeader: buildHeader,
    buildDrawerActions: buildDrawerActions,
    buildPageActions: buildPageActions,
    buildStandalonePage: buildStandalonePage,
    init: init,
  };
})();
