/* CA Cloud Desk — Router + full interactive UI (design only) */
(function () {
  'use strict';

  var USE_DEMO_FALLBACKS = window.CRM_USE_DEMO_FALLBACKS === true;

  function icons() {
    if (typeof lucide !== 'undefined') lucide.createIcons();
  }

  const state = {
    sidebarCollapsed: false,
    sidebarMobileOpen: false,
    notificationOpen: false,
    filterDrawerOpen: false,
    quickActionsOpen: false,
    shortcutsOpen: false,
    detailDrawerOpen: false,
    leadSegmentFilter: 'all',
    currentPage: 'dashboard',
  };

  const REPORTS_PAGES = ['reports', 'analytics', 'activity', 'audit', 'duplicate-attempts'];
  const ASSIGNMENT_PAGES = ['assignment', 'employees'];
  const CA_MASTER_PAGES = ['ca-master', 'bulk'];
  const SETTINGS_PAGES = [
    'settings', 'sales-list', 'email-configuration', 'roles-permissions',
    'settings-email-templates', 'settings-whatsapp-templates', 'settings-google-api',
  ];

  const sidebar = document.getElementById('sidebar');
  const mainContent = document.getElementById('main-content');
  const pageContainer = document.getElementById('page-container');
  const overlay = document.getElementById('overlay');
  const notificationDrawer = document.getElementById('notification-drawer');
  const filterDrawer = document.getElementById('filter-drawer');
  const quickActionsMenu = document.getElementById('quick-actions-menu');
  const shortcutsModal = document.getElementById('shortcuts-modal');
  const detailDrawer = document.getElementById('detail-drawer');
  const fab = document.getElementById('fab');
  const fabWrap = document.getElementById('fab-wrap');

  /* ─── Toast ─── */
  function showToast(msg, type) {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const el = document.createElement('div');
    el.className = 'ca-toast ' + (type || 'info');
    el.innerHTML = '<i data-lucide="' + (type === 'success' ? 'check-circle' : type === 'warning' ? 'alert-triangle' : 'info') + '" class="h-4 w-4 text-brand"></i><span>' + msg + '</span>';
    container.appendChild(el);
    icons();
    setTimeout(function () { el.style.opacity = '0'; el.style.transform = 'translateX(24px)'; setTimeout(function () { el.remove(); }, 300); }, 3200);
  }

  /* ─── Detail Drawer ─── */
  function openDetailDrawer(data) {
    if (!detailDrawer) return;
    const title = document.getElementById('detail-drawer-title');
    const body = document.getElementById('detail-drawer-body');
    const caption = document.getElementById('detail-drawer-caption');
    const followBtn = document.getElementById('detail-followup-btn');
    const editBtn = document.getElementById('detail-edit-btn');
    const mode = data.mode || 'record';
    detailDrawer.dataset.mode = mode;
    if (caption) caption.textContent = mode === 'profile' ? 'My Profile' : 'Record Details';
    if (title) title.textContent = data.firm || data.title || 'Record Details';
    if (body) {
      const fields = data.fields || Object.keys(data).filter(function (k) { return k !== 'fields' && k !== 'title' && k !== 'mode' && k !== 'firm' && k !== 'extraHtml'; }).map(function (k) {
        return { label: k.replace(/([A-Z])/g, ' $1').replace(/^./, function (s) { return s.toUpperCase(); }), value: data[k] };
      });
      body.innerHTML = fields.map(function (f) {
        return '<div class="detail-field"><span class="detail-field-label">' + f.label + '</span><span class="detail-field-value">' + f.value + '</span></div>';
      }).join('') + (data.extraHtml || '');
    }
    if (mode === 'profile') {
      followBtn?.classList.add('hidden');
      if (editBtn) {
        editBtn.innerHTML = '<i data-lucide="pencil" class="h-4 w-4"></i>';
        editBtn.setAttribute('title', 'Edit Profile');
        editBtn.setAttribute('aria-label', 'Edit Profile');
        editBtn.setAttribute('data-crm-tip', 'Edit Profile');
      }
    } else {
      followBtn?.classList.remove('hidden');
      if (editBtn) {
        editBtn.innerHTML = '<i data-lucide="pencil" class="h-4 w-4"></i>';
        editBtn.setAttribute('title', 'Edit');
        editBtn.setAttribute('aria-label', 'Edit');
        editBtn.setAttribute('data-crm-tip', 'Edit');
      }
    }
    closeAllOverlays();
    state.detailDrawerOpen = true;
    openModal(detailDrawer);
  }

  function setCrmScrollLock(locked) {
    document.documentElement.classList.toggle('crm-scroll-locked', locked);
    document.body.classList.toggle('crm-scroll-locked', locked);
    var area = document.getElementById('crm-scroll-area');
    if (area) area.classList.toggle('crm-scroll-area--locked', locked);
  }

  function closeDetailDrawer() {
    detailDrawer?.classList.remove('open');
    if (detailDrawer) detailDrawer.dataset.mode = 'record';
    state.detailDrawerOpen = false;
    if (!state.notificationOpen && !state.filterDrawerOpen && !state.quickActionsOpen && !state.shortcutsOpen && !state.sidebarMobileOpen) {
      overlay?.classList.remove('active');
      setCrmScrollLock(false);
    }
  }

  /* ─── Sidebar & Modals ─── */
  function syncSidebarNavTips() {
    var collapsed = !!state.sidebarCollapsed;
    document.querySelectorAll('#sidebar .nav-item').forEach(function (item) {
      var label = item.querySelector('.sidebar-label');
      if (!label) return;
      if (collapsed) {
        item.setAttribute('data-crm-tip', label.textContent.trim());
      } else {
        item.removeAttribute('data-crm-tip');
      }
    });
  }

  function toggleSidebar() {
    state.sidebarCollapsed = !state.sidebarCollapsed;
    sidebar?.classList.toggle('sidebar-collapsed', state.sidebarCollapsed);
    mainContent?.classList.toggle('main-expanded', state.sidebarCollapsed);
    syncSidebarNavTips();
    const icon = document.getElementById('sidebar-toggle-icon');
    if (icon) { icon.setAttribute('data-lucide', state.sidebarCollapsed ? 'chevrons-right' : 'chevrons-left'); icons(); }
  }

  function initSidebarToolbar() {
    syncSidebarNavTips();
    document.querySelector('.ca-sidebar-logo-link')?.addEventListener('click', function (e) {
      e.preventDefault();
      navigateTo('dashboard');
    });
    var recycleBtn = document.getElementById('sidebar-recycle-btn');
    if (recycleBtn && !recycleBtn._footerBound) {
      recycleBtn._footerBound = true;
      recycleBtn.disabled = false;
      recycleBtn.setAttribute('data-crm-tip', 'Recycle Bin');
      recycleBtn.removeAttribute('title');
      recycleBtn.addEventListener('click', function (e) {
        e.preventDefault();
        var canDelete = window.CA_RBAC && typeof CA_RBAC.can === 'function'
          ? CA_RBAC.can('ca_master', 'delete')
          : true;
        if (!canDelete) {
          showToast('You do not have permission to open the recycle bin.', 'warning');
          return;
        }
        navigateTo('recycle-bin');
      });
    }
  }

  function toggleMobileSidebar() {
    state.sidebarMobileOpen = !state.sidebarMobileOpen;
    sidebar?.classList.toggle('sidebar-mobile-open', state.sidebarMobileOpen);
    overlay?.classList.toggle('active', state.sidebarMobileOpen);
    if (state.sidebarMobileOpen) {
      setCrmScrollLock(true);
    } else if (!state.notificationOpen && !state.filterDrawerOpen && !state.quickActionsOpen && !state.shortcutsOpen && !state.detailDrawerOpen) {
      setCrmScrollLock(false);
    }
  }

  function closeAllOverlays() {
    state.notificationOpen = state.filterDrawerOpen = state.quickActionsOpen = state.shortcutsOpen = false;
    state.sidebarMobileOpen = false;
    state.detailDrawerOpen = false;
    [notificationDrawer, filterDrawer, quickActionsMenu, shortcutsModal, detailDrawer].forEach(function (el) { el?.classList.remove('open'); });
    overlay?.classList.remove('active');
    fab?.classList.remove('fab-active');
    sidebar?.classList.remove('sidebar-mobile-open');
    setCrmScrollLock(false);
  }

    function openModal(el) {
    el?.classList.add('open');
    overlay?.classList.add('active');
    setCrmScrollLock(true);
    if (el) {
      el.querySelectorAll('.ca-modal-body').forEach(function (body) {
        body.scrollTop = 0;
      });
    }
    icons();
    if (window.CA_STATE_CITY && el) {
      window.CA_STATE_CITY.prepareModal(el);
    }
    if (window.CrmDateTimePicker && el) {
      requestAnimationFrame(function () {
        window.CrmDateTimePicker.initAll(el, { force: true });
      });
    }
  }

  function toggleNotificationDrawer() {
    const opening = !state.notificationOpen;
    closeAllOverlays();
    if (opening) { state.notificationOpen = true; openModal(notificationDrawer); }
  }

  function toggleFilterDrawer() {
    const opening = !state.filterDrawerOpen;
    closeAllOverlays();
    if (opening) {
      state.filterDrawerOpen = true;
      openModal(filterDrawer);
      if (window.CA_STATE_CITY) window.CA_STATE_CITY.prepareModal(filterDrawer);
    }
  }

  function toggleQuickActions() {
    const opening = !state.quickActionsOpen;
    closeAllOverlays();
    if (opening) { state.quickActionsOpen = true; openModal(quickActionsMenu); fab?.classList.add('fab-active'); }
  }

  function toggleShortcuts() {
    const opening = !state.shortcutsOpen;
    closeAllOverlays();
    if (opening) { state.shortcutsOpen = true; openModal(shortcutsModal); }
  }

  /* ─── Page Router ─── */
  function usesMasterDataForLeads() {
    var u = window.__CRM_USER__ || {};
    return u.role === 'super_admin' || u.role === 'manager' || u.role === 'admin';
  }

  function resolveLeadHubPage(pageId) {
    if ((pageId === 'leads' || pageId === 'leads-segments') && usesMasterDataForLeads()) {
      return 'ca-master';
    }
    return pageId;
  }

  window.CA_NAV = {
    usesMasterDataForLeads: usesMasterDataForLeads,
    resolveLeadHubPage: resolveLeadHubPage,
    leadHubPageId: function () {
      return usesMasterDataForLeads() ? 'ca-master' : 'leads';
    },
  };

  var PATH_PAGE_MAP = {
    '/': 'dashboard',
    '/dashboard': 'dashboard',
    '/assignment': 'assignment',
    '/lead-assignments': 'assignment',
    '/leads': 'leads',
    '/settings/sales-list': 'sales-list',
    '/ca-masters': 'ca-master',
    '/employees': 'employees',
    '/follow-ups': 'followups',
    '/followups': 'followups',
    '/bulk': 'bulk',
    '/settings': 'settings',
    '/settings/roles-permissions': 'roles-permissions',
    '/settings/email-templates': 'settings-email-templates',
    '/settings/whatsapp-templates': 'settings-whatsapp-templates',
    '/settings/google-api': 'settings-google-api',
    '/reports': 'reports',
    '/duplicate-attempts': 'duplicate-attempts',
    '/analytics': 'analytics',
    '/audit': 'audit',
    '/communication': 'communication',
    '/consent-dnd': 'consent-dnd',
    '/whatsapp': 'whatsapp',
    '/sms': 'sms',
    '/email': 'email',
    '/campaigns': 'campaigns',
    '/notifications': 'notifications',
    '/activity': 'activity',
    '/security': 'security',
    '/queue': 'queue',
    '/admin/database-health': 'db-health',
    '/demo-calendar': 'demo-calendar',
  };

  var PAGE_PATH_MAP = {
    dashboard: '/',
    assignment: '/assignment',
    leads: '/leads',
    'sales-list': '/settings/sales-list',
    'ca-master': '/ca-masters',
    employees: '/employees',
    followups: '/follow-ups',
    bulk: '/bulk',
    settings: '/settings',
    'roles-permissions': '/settings/roles-permissions',
    'settings-email-templates': '/settings/email-templates',
    'settings-whatsapp-templates': '/settings/whatsapp-templates',
    'settings-google-api': '/settings/google-api',
    reports: '/reports',
    'duplicate-attempts': '/duplicate-attempts',
    analytics: '/analytics',
    audit: '/audit',
    communication: '/communication',
    'consent-dnd': '/consent-dnd',
    whatsapp: '/whatsapp',
    sms: '/sms',
    email: '/email',
    campaigns: '/campaigns',
    notifications: '/notifications',
    activity: '/activity',
    security: '/security',
    queue: '/queue',
    'db-health': '/admin/database-health',
    'demo-calendar': '/demo-calendar',
  };

  function normalizePath(path) {
    path = (path || '/').replace(/\/+$/, '') || '/';
    return path;
  }

  function resolvePageFromLocation() {
    if (window.__CRM_INITIAL_PAGE__ && CAPages.get(window.__CRM_INITIAL_PAGE__)) {
      return window.__CRM_INITIAL_PAGE__;
    }
    var path = normalizePath(location.pathname);
    if (PATH_PAGE_MAP[path]) return resolveLeadHubPage(PATH_PAGE_MAP[path]);
    var hash = (location.hash || '').replace('#', '');
    if (hash && CAPages.get(hash)) return resolveLeadHubPage(hash);
    return 'dashboard';
  }

  function syncLocationForPage(pageId) {
    var targetPath = PAGE_PATH_MAP[pageId];
    if (targetPath) {
      if (normalizePath(location.pathname) !== targetPath) {
        history.replaceState({ pageId: pageId }, '', targetPath);
      }
      return;
    }
    var desired = '/#' + pageId;
    if (location.pathname + location.hash !== '/' + '#' + pageId) {
      history.replaceState({ pageId: pageId }, '', desired);
    }
  }

  function updateFabVisibility(pageId) {
    if (fabWrap) {
      var hideFab = pageId === 'dashboard' || pageId === 'settings' || pageId === 'demo-calendar'
        || pageId === 'sales-list' || pageId === 'email-configuration'
        || pageId === 'ca-master' || pageId === 'bulk' || pageId === 'leads'
        || pageId === 'reports' || pageId === 'analytics' || pageId === 'activity' || pageId === 'audit';
      fabWrap.classList.toggle('hidden', hideFab);
    }
  }

  function enhanceCrmTables(root) {
    root = root || document;
    root.querySelectorAll('.overflow-x-auto').forEach(function (el) {
      if (el.closest('.ca-modal-body') || el.classList.contains('crm-scroll-area')) return;
      el.classList.add('crm-table-container', 'scrollbar-thin');
      if (/\bmax-h-/.test(el.className)) {
        el.classList.add('crm-table-container--scroll-y');
      }
    });
    root.querySelectorAll('table.ca-table').forEach(function (table) {
      var parent = table.parentElement;
      if (!parent || parent.classList.contains('crm-scroll-area') || parent.closest('.ca-modal-body')) return;
      if (!parent.classList.contains('crm-table-container')) {
        parent.classList.add('crm-table-container', 'scrollbar-thin');
      }
    });
  }

  function scrollCrmContentToTop() {
    var area = document.getElementById('crm-scroll-area');
    if (area) {
      area.scrollTo({ top: 0, behavior: 'auto' });
      return;
    }
    window.scrollTo({ top: 0, behavior: 'auto' });
  }

  function applyPageContent(pageId) {
    const page = CAPages.get(pageId);
    pageContainer.innerHTML = page.html;
    pageContainer.classList.remove('page-exit');
    pageContainer.classList.add('page-enter');
    document.title = page.title + ' — CA Cloud Desk';
    initPageWidgets(pageId);
    enhanceCrmTables(pageContainer);
    if (window.CrmInstantTooltip && typeof window.CrmInstantTooltip.refresh === 'function') {
      window.CrmInstantTooltip.refresh(pageContainer);
    }
    if (window.CA_RBAC && typeof window.CA_RBAC.onPageChange === 'function') {
      window.CA_RBAC.onPageChange(pageId);
    }
    icons();
    scrollCrmContentToTop();
  }

  function navigateTo(pageId) {
    if (!window.CAPages || !pageContainer) return;
    if (pageId === 'leads-segments') {
      pageId = 'leads';
      window._leadSegmentFilter = window._leadSegmentFilter || 'hot';
    }
    pageId = resolveLeadHubPage(pageId);
    state.currentPage = pageId;
    applyPageContent(pageId);

    document.querySelectorAll('[data-page]').forEach(function (link) {
      var commPages = ['communication', 'consent-dnd', 'email', 'whatsapp', 'sms', 'campaigns', 'notifications', 'reception'];
      var isActive = link.dataset.page === pageId;
      if (!isActive && link.dataset.page === 'communication' && commPages.indexOf(pageId) >= 0) isActive = true;
      if (!isActive && link.dataset.page === 'reports' && REPORTS_PAGES.indexOf(pageId) >= 0) isActive = true;
      if (!isActive && link.dataset.page === 'assignment' && ASSIGNMENT_PAGES.indexOf(pageId) >= 0) isActive = true;
      if (!isActive && link.dataset.page === 'ca-master' && CA_MASTER_PAGES.indexOf(pageId) >= 0) isActive = true;
      if (!isActive && link.dataset.page === 'dashboard' && pageId === 'demo-calendar') isActive = true;
      link.classList.toggle('active', isActive);
    });

    var settingsBtn = document.getElementById('settings-btn');
    if (settingsBtn) {
      settingsBtn.classList.toggle('active', SETTINGS_PAGES.indexOf(pageId) >= 0);
    }

    if (window.innerWidth < 1024) closeAllOverlays();
    syncLocationForPage(pageId);
    updateFabVisibility(pageId);
  }

  function initRouter() {
    document.querySelectorAll('[data-page]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        if (link.dataset.page === 'leads') window._leadSegmentFilter = 'all';
        navigateTo(link.dataset.page);
      });
    });
    navigateTo(resolvePageFromLocation());
    window.addEventListener('hashchange', function () {
      const id = (location.hash || '#dashboard').replace('#', '');
      if (CAPages.get(id)) navigateTo(id);
    });
    window.addEventListener('popstate', function () {
      navigateTo(resolvePageFromLocation());
    });
  }

  /* ─── Tab Panels (content switching) ─── */
  function initTabPanels() {
    if (document.body._tabPanelsBound) return;
    document.body._tabPanelsBound = true;
    document.body.addEventListener('click', function (e) {
      var tab = e.target.closest('.ca-tab');
      if (!tab) return;
      const group = tab.dataset.tabGroup || 'main';
      const tabId = tab.dataset.tab;
      document.querySelectorAll('.ca-tab[data-tab-group="' + group + '"]').forEach(function (t) {
        t.classList.remove('active', 'is-active');
        t.setAttribute('aria-selected', 'false');
      });
      tab.classList.add('active');
      if (tab.classList.contains('page-hero-tab-btn')) {
        tab.classList.add('is-active');
        tab.setAttribute('aria-selected', 'true');
      }
      document.querySelectorAll('.ca-tab-panel[data-tab-group="' + group + '"]').forEach(function (p) {
        p.classList.toggle('active', p.dataset.panel === tabId);
      });
      icons();
      if (group === 'reports-hub' && tabId !== 'activity') {
        var tl = document.getElementById('activity-timeline');
        if (tl) tl.innerHTML = '';
      }
      if (tabId === 'team' && window.CA_CRM) CA_CRM.onPage('employees');
      if (tabId === 'performance' && window.CA_CRM) CA_CRM.onPage('employees');
      if (tabId === 'activity' && group === 'reports-hub') {
        if (window.CA_CRM && CA_CRM.initActivityLogsPage) CA_CRM.initActivityLogsPage();
        else renderActivityTimeline();
        icons();
      }
      if (tabId === 'bulk') {
        if (window.CA_CRM && CA_CRM.initBulkImportWizard) CA_CRM.initBulkImportWizard();
        if (window.CA_CRM && CA_CRM.initBulkAssignmentPanel) CA_CRM.initBulkAssignmentPanel();
        if (window.CA_CRM && CA_CRM.loadBulkImportHistory) CA_CRM.loadBulkImportHistory();
        icons();
      }
      if ((group === 'leads-view' || group === 'cam-view') && window.CA_CRM) {
        if (tabId === 'pipeline' && CA_CRM.loadKanbanLeads) {
          CA_CRM.loadKanbanLeads();
        }
        if (tabId === 'all' && CA_CRM.reloadListing) {
          CA_CRM.reloadListing('ca_masters');
        }
        icons();
      }
    });
  }

  function initLeadsHub() {
    if (document.body._leadsHubBound) return;
    document.body._leadsHubBound = true;
    document.body.addEventListener('click', function (e) {
      if (e.target.closest('#leads-filter-btn')) {
        toggleFilterDrawer();
        return;
      }
      if (e.target.closest('#leads-clear-selection')) {
        CAData.setSelectedLeadId('');
        document.getElementById('leads-selected-bar')?.classList.add('hidden');
        document.querySelectorAll('.ca-table-row.selected, .kanban-card-selected').forEach(function (el) {
          el.classList.remove('selected', 'kanban-card-selected');
        });
        showToast('Selection cleared', 'info');
      }
    });
  }

  /* ─── Page Widgets ─── */
  function initSettingsHubNav() {
    document.querySelectorAll('[data-settings-nav]').forEach(function (btn) {
      if (btn._settingsNavBound) return;
      btn._settingsNavBound = true;
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var page = btn.getAttribute('data-settings-nav');
        if (page && typeof navigateTo === 'function') navigateTo(page);
      });
    });
    if (window.CA_RBAC && typeof window.CA_RBAC.applySettingsHubAccess === 'function') {
      window.CA_RBAC.applySettingsHubAccess();
    }
  }

  function initPageWidgets(pageId) {
    initTabPanels();
    initChips();
    initSecurityPanels();
    initBulkActions();
    initActionButtons();
    initPermMatrix();
    initReportCards();
    initCommunicationCards();
    if (pageId === 'reports' || pageId === 'analytics' || document.querySelector('[data-chart-key]')) {
      renderCharts();
    }
    if (SETTINGS_PAGES.indexOf(pageId) >= 0) initSettingsHubNav();
    if (pageId === 'leads' || pageId === 'leads-segments') initLeadsHub();
    if (pageId === 'activity') {
      if (window.CA_CRM && CA_CRM.initActivityLogsPage) CA_CRM.initActivityLogsPage();
      else renderActivityTimeline();
    } else {
      var activityPanel = document.querySelector('.ca-tab-panel[data-panel="activity"].active #activity-timeline');
      if (activityPanel && window.CA_CRM && CA_CRM.initActivityLogsPage) CA_CRM.initActivityLogsPage();
      else if (activityPanel) renderActivityTimeline();
    }
    if (window.CA_CRM) CA_CRM.onPage(pageId);
    if (window.CrmDateTimePicker) {
      window.CrmDateTimePicker.initAll(pageContainer);
    }
    if (pageId === 'notifications') {
      if (window.CA_CRM && typeof CA_CRM.loadNotifications === 'function') {
        CA_CRM.loadNotifications(true).then(function () {
          renderNotificationsUI();
        }).catch(function () {
          renderNotificationsUI();
        });
      } else {
        renderNotificationsUI();
      }
    }
  }

  function escapeCsvCell(value) {
    var text = value === null || value === undefined ? '' : String(value);
    if (/[",\n\r]/.test(text)) return '"' + text.replace(/"/g, '""') + '"';
    return text;
  }

  function downloadCsv(filename, columns, rows) {
    var headerLine = columns.map(function (col) { return escapeCsvCell(col.label); }).join(',');
    var body = rows.map(function (row) {
      return columns.map(function (col) {
        var value = typeof col.get === 'function' ? col.get(row) : row[col.key];
        return escapeCsvCell(value);
      }).join(',');
    });
    var csv = [headerLine].concat(body).join('\n');
    var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    return rows.length;
  }

  function getFirmExportColumns() {
    return [
      { key: 'ca_id', label: 'ca_id' },
      { key: 'firm_name', label: 'firm_name' },
      { key: 'ca_name', label: 'ca_name' },
      { key: 'mobile_no', label: 'mobile_no' },
      { key: 'alternate_mobile_no', label: 'alternate_mobile_no' },
      { key: 'email_id', label: 'email_id' },
      { key: 'gst_no', label: 'gst_no' },
      { key: 'state', label: 'state' },
      { key: 'city', label: 'city' },
      { key: 'team_size', label: 'team_size' },
      { key: 'existing_software', label: 'existing_software' },
      { key: 'website', label: 'website' },
      { key: 'rating', label: 'rating' },
      { key: 'is_newly_established', label: 'is_newly_established', get: function (r) { return r.is_newly_established ? 'yes' : 'no'; } },
      { key: 'status', label: 'status' },
      { key: 'source', label: 'source' },
      { key: 'stage', label: 'stage' },
      { key: 'executive', label: 'employee' },
      { key: 'created_at', label: 'created_at' },
      { key: 'updated', label: 'updated' },
    ];
  }

  function resolveExportKind(btn) {
    var key = btn.dataset.export;
    if (key) return key;
    var label = btn.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
    if (label.indexOf('firm') >= 0) return 'firms';
    if (label.indexOf('team') >= 0) return 'team';
    if (label.indexOf('transaction') >= 0) return 'transactions';
    if (label.indexOf('audit') >= 0) return 'audit';
    if (label.indexOf('report') >= 0) return 'report';
    if (label.indexOf('log') >= 0) return 'logs';
    return 'firms';
  }

  function handleExportClick(btn) {
    var kind = resolveExportKind(btn);
    var listingMap = {
      leads: 'ca_masters',
      firms: 'ca_masters',
      'export-firms': 'ca_masters',
      team: 'employees',
      'export-team': 'employees',
      logs: 'activity_logs',
      'export-logs': 'activity_logs',
    };
    if (window.CA_LISTING_SEARCH && listingMap[kind]) {
      CA_LISTING_SEARCH.exportListing(listingMap[kind]);
      showToast('Export started for filtered data', 'success');
      return;
    }

    if (kind === 'export-report-pdf') {
      if (window.CA_CRM && typeof CA_CRM.exportReportsSummary === 'function') {
        CA_CRM.exportReportsSummary('pdf');
        return;
      }
      showToast('PDF export unavailable', 'warning');
      return;
    }

    if (kind === 'export-report' || kind === 'report') {
      if (window.CA_CRM && typeof CA_CRM.exportReportsSummary === 'function') {
        CA_CRM.exportReportsSummary();
        return;
      }
      showToast('Report export unavailable — reload the page and try again', 'warning');
      return;
    }

    if ((kind === 'export-logs' || kind === 'logs') && window.CA_CRM && typeof CA_CRM.getActivityLogsForExport === 'function') {
      var logColumns = [
        { key: 'id', label: 'id' },
        { key: 'performed_by', label: 'performed_by' },
        { key: 'module_name', label: 'module_name' },
        { key: 'record_id', label: 'record_id' },
        { key: 'action', label: 'action' },
        { key: 'description', label: 'description' },
        { key: 'timestamp', label: 'timestamp' },
      ];
      var logRows = CA_CRM.getActivityLogsForExport();
      if (logRows.length) {
        downloadCsv('activity-logs.csv', logColumns, logRows);
        showToast('Exported ' + logRows.length + ' activity log row(s)', 'success');
        return;
      }
    }

    if (!window.CAData || !USE_DEMO_FALLBACKS) {
      showToast('Export unavailable for this view', 'warning');
      return;
    }

    var count = 0;
    var filename = 'ca-cloud-desk-export.csv';
    var columns = [];
    var rows = [];

    if (kind === 'firms' || kind === 'leads' || kind === 'export-firms') {
      columns = getFirmExportColumns();
      rows = kind === 'leads' && window.CA_CRM
        ? CAData.filterLeads(window._leadSegmentFilter || 'all', window._leadFilterPrefs || null)
        : CAData.getLeads();
      filename = kind === 'leads' ? 'leads-export.csv' : 'ca-master-firms.csv';
    } else if (kind === 'export-team' || kind === 'team') {
      columns = [
        { key: 'employee_id', label: 'employee_id' },
        { key: 'name', label: 'name' },
        { key: 'email_id', label: 'email_id' },
        { key: 'mobile_no', label: 'mobile_no' },
        { key: 'role', label: 'role' },
        { key: 'manager', label: 'manager' },
        { key: 'city', label: 'city' },
        { key: 'date_of_joining', label: 'date_of_joining' },
        { key: 'status', label: 'status' },
        { key: 'target_leads', label: 'target_leads' },
        { key: 'achieved_leads', label: 'achieved_leads' },
      ];
      rows = CAData.getExecutives();
      filename = 'team-export.csv';
    } else if (kind === 'export-logs' || kind === 'logs') {
      columns = [
        { key: 'id', label: 'id' },
        { key: 'performed_by', label: 'performed_by' },
        { key: 'module_name', label: 'module_name' },
        { key: 'record_id', label: 'record_id' },
        { key: 'action', label: 'action' },
        { key: 'description', label: 'description' },
        { key: 'timestamp', label: 'timestamp' },
      ];
      rows = window.CA_CRM && CA_CRM.getActivityLogsForExport ? CA_CRM.getActivityLogsForExport() : CAData.getActivityLog();
      filename = 'activity-logs.csv';
    } else if (kind === 'export-audit' || kind === 'audit') {
      columns = [
        { key: 'log_id', label: 'audit_id' },
        { key: 'user', label: 'user' },
        { key: 'module', label: 'module' },
        { key: 'action', label: 'action' },
        { key: 'detail', label: 'detail' },
        { key: 'time', label: 'time' },
      ];
      rows = CAData.getActivityLog();
      filename = 'audit-logs.csv';
    } else if (kind === 'export-report-pdf' || kind === 'export-report' || kind === 'report') {
      showToast('Report export unavailable', 'warning');
      return;
    } else if (kind === 'export-transactions' || kind === 'transactions') {
      columns = [
        { key: 'firm_name', label: 'firm_name' },
        { key: 'city', label: 'city' },
        { key: 'status', label: 'status' },
        { key: 'executive', label: 'employee' },
        { key: 'stage', label: 'stage' },
        { key: 'updated', label: 'updated' },
      ];
      rows = CAData.getLeads().filter(function (l) { return l.status === 'Active' || l.stage === 'Won'; });
      filename = 'payment-transactions.csv';
    } else {
      columns = getFirmExportColumns();
      rows = CAData.getLeads();
      filename = 'ca-master-firms.csv';
    }

    if (!rows.length) {
      showToast('Nothing to export for this view', 'warning');
      return;
    }

    count = downloadCsv(filename, columns, rows);
    showToast('Exported ' + count + ' row' + (count === 1 ? '' : 's') + ' to ' + filename, 'success');
  }

  function initChips() {
    if (document.body._chipsBound) return;
    document.body._chipsBound = true;
    document.body.addEventListener('click', function (e) {
      var chip = e.target.closest('.ca-chip');
      if (!chip) return;
      if (chip.classList.contains('ca-chip-action')) return;
      if (chip.dataset.filter) {
          var label = chip.dataset.filter;
          if (window.CA_LISTING_SEARCH) {
            var map = { Status: 'status', City: 'city', Source: 'source_id', State: 'state' };
            var field = map[label] || 'status';
            var value = label === 'Status' ? 'Hot' : label;
            CA_LISTING_SEARCH.setState('ca_masters', { page: 1, filters: (function () { var f = {}; f[field] = value; return f; })() });
            if (window.CA_CRM) CA_CRM.reloadListing('ca_masters');
          }
          showToast('Filter: ' + label + ' applied', 'info');
        } else if (chip.dataset.fuType) {
          document.querySelectorAll('[data-fu-type]').forEach(function (c) {
            c.classList.remove('active');
            c.setAttribute('aria-pressed', 'false');
          });
          chip.classList.add('active');
          chip.setAttribute('aria-pressed', 'true');
          if (window.CA_CRM && typeof CA_CRM.applyFollowupTypeFilter === 'function') {
            CA_CRM.applyFollowupTypeFilter(chip.dataset.fuType);
          } else if (window.CA_LISTING_SEARCH) {
            CA_LISTING_SEARCH.setState('follow_ups', { page: 1, filters: { followup_type: chip.dataset.fuType } });
            if (window.CA_CRM) CA_CRM.reloadListing('follow_ups');
            showToast('Showing ' + chip.dataset.fuType + ' follow-ups', 'info');
          }
        } else if (!chip.classList.contains('saved-filter')) chip.classList.toggle('active');
    });
  }

  function initActionButtons() {
    document.querySelectorAll('[data-action="export"]').forEach(function (btn) {
      if (btn._exportBound) return;
      btn._exportBound = true;
      btn.addEventListener('click', function () { handleExportClick(btn); });
    });
    document.querySelectorAll('[data-page-action]').forEach(function (btn) {
      if (btn._pageActionBound) return;
      btn._pageActionBound = true;
      btn.addEventListener('click', function () {
        if (!USE_DEMO_FALLBACKS) return;
        var label = btn.dataset.pageAction || 'Action';
        var isSave = label.toLowerCase().indexOf('save') >= 0;
        showToast(label + ' (demo)', isSave ? 'success' : 'info');
      });
    });
    document.querySelectorAll('[data-nav-bulk]').forEach(function (btn) {
      if (btn._bulkNavBound) return;
      btn._bulkNavBound = true;
      btn.addEventListener('click', function () {
        navigateTo('bulk');
        if (typeof window.openBulkImportWizard === 'function') {
          window.openBulkImportWizard();
        } else if (window.CA_CRM && typeof window.CA_CRM.initBulkImportWizard === 'function') {
          window.CA_CRM.initBulkImportWizard();
          if (typeof window.openBulkImportWizard === 'function') window.openBulkImportWizard();
        }
      });
    });
  }

  function initBulkActions() {
    document.querySelectorAll('.bulk-action-card').forEach(function (card) {
      if (card._bulkCardBound) return;
      card._bulkCardBound = true;
      card.addEventListener('click', function () {
        var isImport = card.dataset.bulk === 'Bulk Import';
        var isAssign = card.dataset.bulk === 'Bulk Assignment';
        var isExport = card.dataset.bulk === 'Bulk Export';
        var isStatus = card.dataset.bulk === 'Bulk Status Update';
        var wizard = document.getElementById('bulk-import-wizard');
        var assignPanel = document.getElementById('bulk-assignment-panel');
        var exportPanel = document.getElementById('bulk-export-panel');
        var statusPanel = document.getElementById('bulk-status-update-panel');
        if (wizard) wizard.classList.toggle('hidden', !isImport);
        if (exportPanel) exportPanel.classList.toggle('hidden', !isExport);
        if (statusPanel) statusPanel.classList.toggle('hidden', !isStatus);
        if (assignPanel) {
          assignPanel.classList.toggle('hidden', !isAssign);
          if (isAssign && window.CA_CRM && typeof window.CA_CRM.initBulkAssignmentPanel === 'function') {
            window.CA_CRM.initBulkAssignmentPanel();
          }
        }
        if (isImport) {
          if (window.CA_CRM && typeof window.CA_CRM.initBulkImportWizard === 'function') {
            window.CA_CRM.initBulkImportWizard();
          }
          if (typeof window.openBulkImportWizard === 'function') {
            window.openBulkImportWizard();
          } else {
            showToast('Bulk import wizard ready — upload a CSV or Excel file', 'info');
          }
        } else if (isAssign) {
          showToast('Select leads and employees, then preview assignment', 'info');
        } else if (isExport) {
          if (window.CA_CRM && typeof window.CA_CRM.initBulkExportPanel === 'function') {
            window.CA_CRM.initBulkExportPanel();
          }
          if (typeof window.openBulkExportPanel === 'function') {
            window.openBulkExportPanel();
          } else {
            showToast('Bulk export panel ready', 'info');
          }
        } else if (isStatus) {
          if (window.CA_CRM && typeof window.CA_CRM.initBulkStatusUpdatePanel === 'function') {
            window.CA_CRM.initBulkStatusUpdatePanel();
          }
          if (typeof window.openBulkStatusUpdatePanel === 'function') {
            window.openBulkStatusUpdatePanel();
          } else {
            showToast('Select records and preview status changes', 'info');
          }
        } else {
          showToast(card.dataset.bulk + ' wizard opened', 'info');
        }
      });
    });
  }

  function initSecurityPanels() {
    document.querySelectorAll('[data-security-panel]').forEach(function (card) {
      card.addEventListener('click', function () {
        const panelId = card.dataset.securityPanel;
        document.querySelectorAll('.security-card').forEach(function (c) { c.classList.remove('active'); });
        card.classList.add('active');
        document.querySelectorAll('.ca-tab-panel[data-tab-group="security"]').forEach(function (p) {
          p.classList.toggle('active', p.dataset.panel === panelId);
        });
        icons();
      });
    });
  }

  function initPermMatrix() {
    // Permission matrix toggles are loaded and persisted via CA_CRM.initSecurityPage().
  }

  function initCommunicationCards() {
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-comm-page]'));
    cards.forEach(function (card, idx) {
      card.addEventListener('mouseenter', function () {
        cards.forEach(function (c) { c.classList.remove('comm-card-live'); });
        card.classList.add('comm-card-live');
      });
      card.addEventListener('mouseleave', function () {
        card.classList.remove('comm-card-live');
      });
      card.addEventListener('click', function (e) {
        var inner = card.querySelector('.comm-card-inner');
        if (inner) {
          var ripple = document.createElement('span');
          ripple.className = 'comm-card-ripple';
          var rect = inner.getBoundingClientRect();
          var size = Math.max(rect.width, rect.height);
          ripple.style.width = size + 'px';
          ripple.style.height = size + 'px';
          ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
          ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
          inner.appendChild(ripple);
          setTimeout(function () { ripple.remove(); }, 600);
        }
        navigateTo(card.dataset.commPage);
      });
      card.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
          e.preventDefault();
          cards[(idx + 1) % cards.length].focus();
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
          e.preventDefault();
          cards[(idx - 1 + cards.length) % cards.length].focus();
        }
      });
    });
  }

  function initReportCards() {
    document.querySelectorAll('.report-card').forEach(function (card) {
      card.addEventListener('click', function () {
        var navPage = card.dataset.navPage;
        if (navPage && typeof navigateTo === 'function') {
          navigateTo(navPage);
          return;
        }
        var slug = card.dataset.reportSlug;
        if (window.CA_CRM && typeof CA_CRM.openReport === 'function' && slug) {
          CA_CRM.openReport(slug);
          return;
        }
        showToast('Generating: ' + card.dataset.report, 'info');
      });
    });

    if (window.CA_CRM && typeof CA_CRM.initReportsFilters === 'function') {
      CA_CRM.initReportsFilters();
    }
  }

  function initTableRows() {
    document.querySelectorAll('.ca-table-row').forEach(function (row) {
      row.addEventListener('click', function () {
        document.querySelectorAll('.ca-table-row').forEach(function (r) { r.classList.remove('selected'); });
        row.classList.add('selected');
        if (row.dataset.row) {
          try {
            const data = JSON.parse(row.dataset.row.replace(/&#39;/g, "'"));
            openDetailDrawer({
              firm: data.firm,
              fields: [
                { label: 'Reference', value: data.id },
                { label: 'Firm Name', value: data.firm },
                { label: 'CA Name', value: data.ca },
                { label: 'Mobile', value: data.mobile },
                { label: 'Email', value: data.email },
                { label: 'GST No.', value: data.gst },
                { label: 'State', value: data.state },
                { label: 'City', value: data.city },
                { label: 'Team Size', value: data.team },
                { label: 'Existing Software', value: data.software },
                { label: 'Website', value: data.website },
                { label: 'Rating', value: data.rating + ' / 5' },
                { label: 'Newly Established', value: data.newFirm ? 'Yes' : 'No' },
                { label: 'Status', value: data.status },
                { label: 'Source', value: data.source },
              ],
            });
          } catch (e) { /* ignore */ }
        }
      });
    });
  }

  function renderCharts() {
    if (window.CA_CRM && typeof CA_CRM.renderReportCharts === 'function') {
      CA_CRM.renderReportCharts();
      return;
    }
    document.querySelectorAll('.ca-chart').forEach(function (el) {
      const bars = Array.from({ length: 12 }, function () { return Math.random() * 60 + 20; });
      el.innerHTML = '<div class="flex items-end justify-between gap-1.5 h-full px-2 pb-2" style="height:100%">' +
        bars.map(function (h, i) {
          return '<div class="ca-chart-bar flex-1 rounded-t-md" style="height:' + h + '%;transition-delay:' + (i * 40) + 'ms"></div>';
        }).join('') + '</div>';
    });
  }

  function renderFollowupCalendar() {
    const container = document.getElementById('followup-calendar');
    if (!container) return;
    const days = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    const events = [3, 5, 8, 12, 15, 18, 22, 25];
    let html = '<div class="ca-cal-grid mb-2">' + days.map(function (d) {
      return '<div class="text-caption font-medium text-slate-400 py-1">' + d + '</div>';
    }).join('') + '</div><div class="ca-cal-grid">';
    for (var i = 1; i <= 30; i++) {
      var cls = 'ca-cal-day';
      if (i === 17) cls += ' today';
      if (events.indexOf(i) >= 0) cls += ' has-event';
      html += '<div class="' + cls + '" data-day="' + i + '">' + i + '</div>';
    }
    html += '</div>' +
      '<div class="crm-cal-footer mt-3">' +
        '<p class="crm-cal-footer__title">Follow-up Schedule</p>' +
        '<p class="crm-cal-footer__month">June 2026</p>' +
      '</div>';
    container.innerHTML = html;
    container.querySelectorAll('.ca-cal-day').forEach(function (day) {
      day.addEventListener('click', function () {
        container.querySelectorAll('.ca-cal-day').forEach(function (d) { d.classList.remove('today'); });
        day.classList.add('today');
        showToast(day.classList.contains('has-event') ? '3 meetings scheduled' : 'No meetings scheduled', 'info');
      });
    });
  }

  function renderActivityTimeline() {
    if (window.CA_CRM && CA_CRM.renderActivityTimeline) {
      CA_CRM.renderActivityTimeline();
      return;
    }
    const container = document.getElementById('activity-timeline');
    if (!container) return;
    container.innerHTML = '<p class="text-caption text-slate-400">Loading activity…</p>';
  }

  function renderLeaderboard() {
    if (window.CA_CRM && typeof CA_CRM.renderLeaderboard === 'function') {
      CA_CRM.renderLeaderboard();
      return;
    }
    const container = document.getElementById('leaderboard');
    if (!container) return;
    container.innerHTML = '<p class="text-caption text-slate-500 p-2">Loading leaderboard…</p>';
  }

  function notificationIcon(type) {
    var map = { info: 'info', success: 'check-circle', warning: 'alert-triangle', error: 'alert-circle' };
    return map[type] || 'bell';
  }

  function notificationItemHtml(n) {
    var readCls = n.read ? ' notif-item--read' : ' notif-item--unread';
    var typeCls = ' notif-item__icon--' + (n.type || 'info');
    return '<button type="button" class="notif-item ca-modal-item' + readCls + '" data-notification-id="' + n.notification_id + '">' +
      (n.read ? '' : '<span class="notif-unread-dot" aria-hidden="true"></span>') +
      '<span class="notif-item__icon' + typeCls + '" aria-hidden="true"><i data-lucide="' + notificationIcon(n.type) + '" class="h-4 w-4"></i></span>' +
      '<span class="notif-item__body">' +
        '<p class="notif-item__title">' + n.title + '</p>' +
        '<p class="notif-item__message">' + n.message + '</p>' +
        '<p class="notif-item__time">' + n.time + '</p>' +
      '</span>' +
    '</button>';
  }

  function updateNotificationBadges(unread) {
    var headerBadge = document.getElementById('header-notification-badge');
    var drawerCount = document.getElementById('notification-drawer-count');
    var tabCount = document.getElementById('notifications-unread-tab-count');

    if (headerBadge) {
      headerBadge.textContent = String(unread);
      headerBadge.classList.toggle('is-hidden', unread === 0);
      headerBadge.setAttribute('aria-hidden', unread === 0 ? 'true' : 'false');
    }
    if (drawerCount) {
      drawerCount.textContent = unread === 0 ? 'All caught up' : unread + ' new';
      drawerCount.classList.toggle('badge-brand', unread > 0);
      drawerCount.classList.toggle('badge-success', unread === 0);
    }
    if (tabCount) tabCount.textContent = String(unread);

    document.querySelectorAll('[data-action="mark-all-read"]').forEach(function (btn) {
      btn.disabled = unread === 0;
    });
  }

  function renderNotificationsUI() {
    var all = [];
    var unread = 0;

    if (window.CA_CRM && typeof CA_CRM.getNotificationsCache === 'function') {
      all = CA_CRM.getNotificationsCache();
      unread = CA_CRM.getUnreadNotificationCount();
    } else if (window.CAData) {
      all = CAData.getNotifications();
      unread = CAData.getUnreadNotificationCount();
    } else {
      return;
    }

    var unreadList = all.filter(function (n) { return !n.read; });
    var drawerList = document.getElementById('notification-drawer-list');
    var allList = document.getElementById('notifications-all-list');
    var unreadPanel = document.getElementById('notifications-unread-list');

    if (drawerList) {
      drawerList.innerHTML = all.slice(0, 5).map(notificationItemHtml).join('') ||
        '<p class="notif-empty">No notifications</p>';
    }
    if (allList) {
      allList.innerHTML = all.map(notificationItemHtml).join('') ||
        '<p class="notif-empty">No notifications</p>';
    }
    if (unreadPanel) {
      unreadPanel.innerHTML = unreadList.length
        ? unreadList.map(notificationItemHtml).join('')
        : '<p class="notif-empty">All notifications read</p>';
    }

    updateNotificationBadges(unread);
    icons();
  }

  function handleMarkAllNotificationsRead() {
    if (window.CA_CRM && typeof CA_CRM.markAllNotificationsReadApi === 'function') {
      CA_CRM.markAllNotificationsReadApi().then(function (marked) {
        if (!marked) {
          showToast('No unread notifications', 'info');
          return;
        }
        renderNotificationsUI();
        showToast(marked + ' notification' + (marked === 1 ? '' : 's') + ' marked as read', 'success');
      }).catch(function (err) {
        showToast(err.message || 'Unable to mark notifications as read', 'error');
      });
      return;
    }

    if (!window.CAData) return;
    var marked = CAData.markAllNotificationsRead();
    if (!marked) {
      showToast('No unread notifications', 'info');
      return;
    }
    renderNotificationsUI();
    showToast(marked + ' notification' + (marked === 1 ? '' : 's') + ' marked as read', 'success');
  }

  function initNotificationUI() {
    if (window._notificationsUiBound) return;
    window._notificationsUiBound = true;

    document.addEventListener('click', function (e) {
      var markBtn = e.target.closest('[data-action="mark-all-read"]');
      if (markBtn) {
        e.preventDefault();
        if (!markBtn.disabled) handleMarkAllNotificationsRead();
        return;
      }

      var notifBtn = e.target.closest('[data-notification-id]');
      if (notifBtn) {
        var id = notifBtn.dataset.notificationId;
        if (window.CA_CRM && typeof CA_CRM.markNotificationReadApi === 'function') {
          var cached = CA_CRM.getNotificationsCache().find(function (n) { return n.notification_id === id; });
          if (cached && !cached.read) {
            CA_CRM.markNotificationReadApi(id).then(function () {
              renderNotificationsUI();
              showToast('Notification marked as read', 'success');
            }).catch(function (err) {
              showToast(err.message || 'Unable to mark notification as read', 'error');
            });
          }
          return;
        }
        if (window.CAData) {
          var item = CAData.getNotifications().find(function (n) { return n.notification_id === id; });
          if (item && !item.read) {
            CAData.markNotificationRead(id);
            renderNotificationsUI();
            showToast('Notification marked as read', 'success');
          }
        }
      }

      var viewAll = e.target.closest('#notification-drawer [data-nav-page]');
      if (viewAll) {
        e.preventDefault();
        closeAllOverlays();
        navigateTo(viewAll.dataset.navPage);
      }

      var notifSettings = e.target.closest('[data-notification-settings]');
      if (notifSettings) {
        e.preventDefault();
        closeAllOverlays();
        navigateTo('notifications');
      }
    });

    renderNotificationsUI();
  }

  function initHeaderActions() {
    document.getElementById('calendar-btn')?.addEventListener('click', function () {
      navigateTo(this.dataset.page || 'demo-calendar');
    });
    initSettingsMenu();
    initProfileMenu();
  }

  function initProfileMenu() {
    var btn = document.getElementById('profile-menu-btn');
    var menu = document.getElementById('profile-menu');
    var wrap = document.getElementById('header-user-wrap');
    if (!btn || !menu || !wrap) return;

    function closeProfileMenu() {
      menu.classList.add('hidden');
      btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var isOpen = !menu.classList.contains('hidden');
      if (isOpen) closeProfileMenu();
      else {
        menu.classList.remove('hidden');
        btn.setAttribute('aria-expanded', 'true');
        icons();
      }
    });

    menu.querySelectorAll('[data-profile-action]').forEach(function (item) {
      item.addEventListener('click', function () {
        closeProfileMenu();
        var action = item.dataset.profileAction;
        var u = window.__CRM_USER__ || {};
        if (action === 'profile') {
          if (typeof openDetailDrawer === 'function') {
            openDetailDrawer({
              mode: 'profile',
              firm: u.name || 'Profile',
              fields: [
                { label: 'Name', value: u.name || '—' },
                { label: 'Email', value: u.email || '—' },
                { label: 'Role', value: u.role_label || u.role || '—' },
                { label: 'Designation', value: u.designation || '—' },
              ],
            });
          }
          return;
        }
        if (action === 'password') {
          var changeModal = document.getElementById('modal-change-password');
          var titleText = document.getElementById('change-password-title-text');
          if (titleText) titleText.textContent = (u.role === 'admin' || u.role === 'super_admin') ? 'My Password' : 'Change Password';
          if (changeModal && typeof openModal === 'function') {
            var form = document.getElementById('form-change-password');
            if (form) form.reset();
            openModal(changeModal);
            if (window.CA_CRM && typeof CA_CRM.initPasswordToggleButtons === 'function') {
              CA_CRM.initPasswordToggleButtons(changeModal);
            }
            icons();
          }
          return;
        }
        if (action === 'change-login-email') {
          if (window.CA_CRM && typeof CA_CRM.openChangeLoginEmailModal === 'function') {
            CA_CRM.openChangeLoginEmailModal();
          }
          return;
        }
        if (action === 'email-configuration') {
          if (typeof navigateTo === 'function') {
            navigateTo('email-configuration');
          }
          return;
        }
        if (action === 'reset-employee-password') {
          var resetModal = document.getElementById('modal-reset-employee-password');
          if (resetModal && typeof openModal === 'function') {
            var resetForm = document.getElementById('form-reset-employee-password');
            if (resetForm) resetForm.reset();
            openModal(resetModal);
            if (window.CA_CRM && typeof CA_CRM.populateResetPasswordEmployeeSelect === 'function') {
              CA_CRM.populateResetPasswordEmployeeSelect();
            }
            if (window.CA_CRM && typeof CA_CRM.initPasswordToggleButtons === 'function') {
              CA_CRM.initPasswordToggleButtons(resetModal);
            }
            icons();
          }
        }
      });
    });

    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) closeProfileMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !menu.classList.contains('hidden')) closeProfileMenu();
    });
  }

  function initSettingsMenu() {
    const btn = document.getElementById('settings-btn');
    if (!btn) return;

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      navigateTo('settings');
    });
  }

  /* ─── Filter Modal Interactions ─── */
  function padDatePart(n) { return String(n).padStart(2, '0'); }

  function toISODate(d) {
    return d.getFullYear() + '-' + padDatePart(d.getMonth() + 1) + '-' + padDatePart(d.getDate());
  }

  function formatDisplayDate(iso) {
    if (!iso) return '';
    var p = iso.split('-');
    return p[1] + '/' + p[2] + '/' + p[0];
  }

  function getActiveTimePeriod() {
    return document.querySelector('#filter-time-tabs .mgr-period-tab.active')?.dataset.period || 'any';
  }

  function getPeriodRange(period) {
    var now = new Date();
    var today = toISODate(now);
    if (period === 'today') return { from: today, to: today };
    if (period === 'week') {
      var day = now.getDay();
      var mondayOffset = day === 0 ? -6 : 1 - day;
      var start = new Date(now);
      start.setDate(now.getDate() + mondayOffset);
      return { from: toISODate(start), to: today };
    }
    if (period === 'month') {
      var monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
      var monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0);
      return { from: toISODate(monthStart), to: toISODate(monthEnd) };
    }
    return { from: '', to: '' };
  }

  function updateTimeHint(period, from, to) {
    var hint = document.getElementById('filter-time-hint');
    if (!hint) return;
    if (period === 'any' && !from && !to) {
      hint.textContent = 'No date filter applied';
      return;
    }
    if (from && to) {
      hint.textContent = 'Filtering leads created ' + formatDisplayDate(from) + ' – ' + formatDisplayDate(to);
      return;
    }
    if (from) hint.textContent = 'Filtering leads created from ' + formatDisplayDate(from);
    else if (to) hint.textContent = 'Filtering leads created until ' + formatDisplayDate(to);
    else hint.textContent = 'No date filter applied';
  }

  function setTimePeriod(period, options) {
    options = options || {};
    var fromEl = document.getElementById('filter-date-from');
    var toEl = document.getElementById('filter-date-to');
    var fields = document.getElementById('filter-date-fields');
    if (!fromEl || !toEl) return;

    document.querySelectorAll('#filter-time-tabs .mgr-period-tab').forEach(function (t) {
      t.classList.toggle('active', t.dataset.period === period);
    });

    var range = period === 'any' ? { from: '', to: '' } : getPeriodRange(period);
    if (options.from !== undefined) range.from = options.from;
    if (options.to !== undefined) range.to = options.to;

    fromEl.value = range.from;
    toEl.value = range.to;
    fromEl.disabled = period === 'any';
    toEl.disabled = period === 'any';
    fields?.classList.toggle('is-custom', period === 'any' && !!(range.from || range.to));

    if (range.from) toEl.min = range.from; else toEl.removeAttribute('min');
    if (range.to) fromEl.max = range.to; else fromEl.removeAttribute('max');

    updateTimeHint(period, range.from, range.to);
  }

  function clearTimePeriodTabs() {
    document.querySelectorAll('#filter-time-tabs .mgr-period-tab').forEach(function (t) {
      t.classList.remove('active');
    });
  }

  function readFilterPreferences() {
    var stateEl = document.getElementById('filter-state');
    var cityEl = document.getElementById('filter-city');
    var from = document.getElementById('filter-date-from')?.value || '';
    var to = document.getElementById('filter-date-to')?.value || '';
    var period = getActiveTimePeriod();
    if (!from && !to && period !== 'any') {
      var range = getPeriodRange(period);
      from = range.from;
      to = range.to;
    }
    return {
      state_id: stateEl?.value || '',
      city_id: cityEl?.value || '',
      timePeriod: period,
      dateFrom: from,
      dateTo: to,
    };
  }

  function initFilterModal() {
    var fromEl = document.getElementById('filter-date-from');
    var toEl = document.getElementById('filter-date-to');

    document.querySelectorAll('#filter-time-tabs .mgr-period-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        setTimePeriod(tab.dataset.period);
      });
    });

    function onCustomDateChange() {
      clearTimePeriodTabs();
      if (fromEl) fromEl.disabled = false;
      if (toEl) toEl.disabled = false;
      document.getElementById('filter-date-fields')?.classList.add('is-custom');
      if (fromEl?.value && toEl) toEl.min = fromEl.value;
      if (toEl?.value && fromEl) fromEl.max = toEl.value;
      if (fromEl?.value && toEl?.value && fromEl.value > toEl.value) {
        toEl.value = fromEl.value;
      }
      updateTimeHint('custom', fromEl?.value || '', toEl?.value || '');
    }

    fromEl?.addEventListener('change', onCustomDateChange);
    toEl?.addEventListener('change', onCustomDateChange);

    function enableCustomDates() {
      clearTimePeriodTabs();
      if (fromEl) fromEl.disabled = false;
      if (toEl) toEl.disabled = false;
      document.getElementById('filter-date-fields')?.classList.add('is-custom');
      updateTimeHint('custom', fromEl?.value || '', toEl?.value || '');
    }
    fromEl?.addEventListener('focus', function () { if (fromEl.disabled) enableCustomDates(); });
    toEl?.addEventListener('focus', function () { if (toEl.disabled) enableCustomDates(); });

    document.getElementById('filter-apply-btn')?.addEventListener('click', function () {
      window._leadFilterPrefs = readFilterPreferences();
      if (window.CA_LISTING_SEARCH) {
        CA_LISTING_SEARCH.setState('ca_masters', { page: 1, filters: CA_LISTING_SEARCH.readLeadDrawerFilters() });
      }
      closeAllOverlays();
      var onApplied = function () { showToast('Filters applied', 'success'); };
      var onFailed = function (err) { showToast((err && err.message) || 'Failed to apply filters', 'error'); };
      if (window.CA_CRM && typeof CA_CRM.reloadListing === 'function') {
        CA_CRM.reloadListing('ca_masters').then(onApplied).catch(onFailed);
      } else if (window.CA_CRM && document.getElementById('leads-kpi-strip')) {
        CA_CRM.renderLeadsHub();
        onApplied();
      } else {
        onApplied();
      }
    });
    document.getElementById('filter-reset-btn')?.addEventListener('click', function () {
      document.querySelectorAll('#filter-drawer select, #filter-drawer input').forEach(function (el) {
        if (el.id === 'filter-date-from' || el.id === 'filter-date-to') return;
        if (el.type === 'checkbox') el.checked = false;
        else if (el.tagName === 'SELECT') el.selectedIndex = 0;
        else if (el.type === 'number') el.value = el.defaultValue || el.getAttribute('value') || '';
        else el.value = '';
      });
      window._leadFilterPrefs = null;
      if (window.CA_LISTING_SEARCH) CA_LISTING_SEARCH.clearFilters('ca_masters');
      setTimePeriod('any');
      var onReset = function () { showToast('Filters reset', 'info'); };
      var onFailed = function (err) { showToast((err && err.message) || 'Failed to reset filters', 'error'); };
      if (window.CA_CRM && typeof CA_CRM.reloadListing === 'function') {
        CA_CRM.reloadListing('ca_masters').then(onReset).catch(onFailed);
      } else if (window.CA_CRM && document.getElementById('leads-kpi-strip')) {
        CA_CRM.renderLeadsHub();
        onReset();
      } else {
        onReset();
      }
    });
    document.querySelectorAll('.saved-filter-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var label = btn.textContent.trim();
        if (/mumbai/i.test(label)) {
          document.getElementById('filter-city').value = 'Mumbai';
          setTimePeriod('month');
        } else if (/pune/i.test(label)) {
          document.getElementById('filter-city').value = 'Pune';
          setTimePeriod('any');
        } else if (/bangalore/i.test(label)) {
          document.getElementById('filter-city').value = 'Bangalore';
          setTimePeriod('week');
        }
        window._leadFilterPrefs = readFilterPreferences();
        if (window.CA_LISTING_SEARCH) {
          CA_LISTING_SEARCH.setState('ca_masters', { page: 1, filters: CA_LISTING_SEARCH.readLeadDrawerFilters() });
        }
        var onLoaded = function () { showToast('Loaded: ' + label, 'success'); };
        var onFailed = function (err) { showToast((err && err.message) || 'Failed to load filter preset', 'error'); };
        if (window.CA_CRM && typeof CA_CRM.reloadListing === 'function') {
          CA_CRM.reloadListing('ca_masters').then(onLoaded).catch(onFailed);
        } else if (window.CA_CRM && document.getElementById('leads-kpi-strip')) {
          CA_CRM.renderLeadsHub();
          onLoaded();
        } else {
          onLoaded();
        }
      });
    });
    document.getElementById('filter-save-btn')?.addEventListener('click', function () {
      window._leadFilterPrefs = readFilterPreferences();
      var filters = window.CA_LISTING_SEARCH ? CA_LISTING_SEARCH.readLeadDrawerFilters() : window._leadFilterPrefs;
      var name = prompt('Name this filter preset');
      if (!name) return;
      if (window.CA_LISTING_SEARCH) {
        CA_LISTING_SEARCH.saveFilter('ca_masters', name, filters).then(function () {
          showToast('Filter saved', 'success');
        }).catch(function () {
          showToast('Filter saved locally', 'info');
        });
      } else {
        showToast('Filter saved successfully', 'success');
      }
    });

    setTimePeriod('any');
  }

  /* ─── Quick Actions ─── */
  function initQuickActions() {
    if (window.CA_CRM && typeof CA_CRM.initQuickActions === 'function') return;
    document.querySelectorAll('.ca-action-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const label = btn.querySelector('span')?.textContent || 'Action';
        showToast(label + ' opened', 'info');
        closeAllOverlays();
        if (label === 'Bulk Import') navigateTo('bulk');
        else if (label === 'Add Lead') navigateTo('ca-master');
        else if (label === 'Assign Lead') navigateTo('assignment');
        else if (label === 'Schedule Follow-up') navigateTo('followups');
      });
    });
  }

  /* ─── Global Search ─── */
  let searchActiveIndex = -1;
  let searchTimer = null;

  function initGlobalSearch() {
    const input = document.getElementById('global-search');
    const dropdown = document.getElementById('search-results');
    const wrapper = document.getElementById('search-wrapper');
    if (!input || !dropdown) return;

    function closeSearch() {
      dropdown.classList.add('hidden');
      input.setAttribute('aria-expanded', 'false');
      searchActiveIndex = -1;
    }

    function renderResults(results, query) {
      searchActiveIndex = results.length ? 0 : -1;
      if (!results.length) {
        dropdown.innerHTML = '<div class="search-empty">No results for "' + query + '"</div>';
        dropdown.classList.remove('hidden');
        return;
      }
      dropdown.innerHTML = results.map(function (r, idx) {
        return '<button type="button" class="search-result' + (idx === 0 ? ' active' : '') + '" data-page="' + r.page + '"' +
          (r.record_id ? ' data-record-id="' + r.record_id + '"' : '') + '>' +
          '<span class="search-result-icon"><i data-lucide="' + r.icon + '" class="h-4 w-4"></i></span>' +
          '<span class="search-result-body"><span class="search-result-type">' + r.type + '</span>' +
          '<span class="search-result-title">' + r.title + '</span>' +
          '<span class="search-result-meta">' + r.meta + '</span></span></button>';
      }).join('');
      dropdown.classList.remove('hidden');
      input.setAttribute('aria-expanded', 'true');
      icons();
      dropdown.querySelectorAll('.search-result').forEach(function (btn) {
        btn.addEventListener('click', function () {
          input.value = '';
          closeSearch();
          if (btn.dataset.recordId) window._selectedLeadId = btn.dataset.recordId;
          var page = (window.CA_NAV && window.CA_NAV.resolveLeadHubPage)
            ? window.CA_NAV.resolveLeadHubPage(btn.dataset.page)
            : btn.dataset.page;
          navigateTo(page);
        });
      });
    }

    function runSearch(query) {
      const q = query.trim();
      if (!q) { closeSearch(); dropdown.innerHTML = ''; return; }
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        fetch('/search?q=' + encodeURIComponent(q) + '&limit=8', {
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
          .then(function (response) { return response.json(); })
          .then(function (body) {
            renderResults((body.data && body.data.results) || body.results || [], q);
          })
          .catch(function () {
            dropdown.innerHTML = '<div class="search-empty">Search unavailable</div>';
            dropdown.classList.remove('hidden');
          });
      }, 200);
    }

    input.addEventListener('input', function () { runSearch(input.value); });
    input.addEventListener('focus', function () { if (input.value.trim()) runSearch(input.value); });
    input.addEventListener('keydown', function (e) {
      const buttons = dropdown.querySelectorAll('.search-result');
      if (e.key === 'ArrowDown' && buttons.length) { e.preventDefault(); searchActiveIndex = Math.min(searchActiveIndex + 1, buttons.length - 1); buttons.forEach(function (b, i) { b.classList.toggle('active', i === searchActiveIndex); }); }
      else if (e.key === 'ArrowUp' && buttons.length) { e.preventDefault(); searchActiveIndex = Math.max(searchActiveIndex - 1, 0); buttons.forEach(function (b, i) { b.classList.toggle('active', i === searchActiveIndex); }); }
      else if (e.key === 'Enter' && searchActiveIndex >= 0 && buttons[searchActiveIndex]) {
        e.preventDefault();
        var activeBtn = buttons[searchActiveIndex];
        input.value = '';
        closeSearch();
        if (activeBtn.dataset.recordId) window._selectedLeadId = activeBtn.dataset.recordId;
        var page = (window.CA_NAV && window.CA_NAV.resolveLeadHubPage)
          ? window.CA_NAV.resolveLeadHubPage(activeBtn.dataset.page)
          : activeBtn.dataset.page;
        navigateTo(page);
      }
      else if (e.key === 'Escape') { closeSearch(); input.blur(); }
    });
    document.addEventListener('click', function (e) { if (!wrapper.contains(e.target)) closeSearch(); });
  }

  /* ─── Bind Events ─── */
  document.getElementById('sidebar-toggle')?.addEventListener('click', toggleSidebar);
  document.getElementById('mobile-menu-btn')?.addEventListener('click', toggleMobileSidebar);
  document.getElementById('notification-btn')?.addEventListener('click', toggleNotificationDrawer);
  document.getElementById('filter-btn')?.addEventListener('click', toggleFilterDrawer);
  document.getElementById('fab')?.addEventListener('click', toggleQuickActions);
  document.getElementById('quick-actions-btn')?.addEventListener('click', toggleQuickActions);
  document.getElementById('shortcuts-btn')?.addEventListener('click', toggleShortcuts);
  document.getElementById('detail-drawer-close')?.addEventListener('click', closeDetailDrawer);
  overlay?.addEventListener('click', function () {
    if (state.detailDrawerOpen) closeDetailDrawer();
    else closeAllOverlays();
  });
  document.querySelectorAll('[data-close-overlay]').forEach(function (btn) { btn.addEventListener('click', closeAllOverlays); });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (window.CrmDateTimePicker && typeof window.CrmDateTimePicker.isOpen === 'function' && window.CrmDateTimePicker.isOpen()) {
        return;
      }
      if (state.detailDrawerOpen) { closeDetailDrawer(); return; }
      const dd = document.getElementById('search-results');
      if (dd && !dd.classList.contains('hidden')) { dd.classList.add('hidden'); document.getElementById('global-search')?.blur(); return; }
      closeAllOverlays();
    }
    if (e.key === '?' && !e.target.matches('input, textarea, select')) toggleShortcuts();
    if (e.key === 'q' && !e.target.matches('input, textarea, select')) toggleQuickActions();
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); const s = document.getElementById('global-search'); s?.focus(); s?.select(); }
    if (e.key === 'n' && !e.target.matches('input, textarea, select')) {
      if (window.CA_RBAC && !CA_RBAC.can('leads', 'create')) return;
      if (window.CA_CRM) {
        CA_CRM.populateSelects();
        CA_CRM.openLeadFormForAdd();
        CA_CRM.openExclusiveCrmModal(document.getElementById('modal-add-lead'));
      }
      else if (USE_DEMO_FALLBACKS) showToast('New lead form (UI demo)', 'info');
    }
  });

  window.showToast = showToast;
  window.openModal = openModal;
  window.setCrmScrollLock = setCrmScrollLock;
  window.closeAllOverlays = closeAllOverlays;
  window.openDetailDrawer = openDetailDrawer;
  window.closeDetailDrawer = closeDetailDrawer;
  window.navigateTo = navigateTo;
  window.refreshNotificationsUI = renderNotificationsUI;
  window.enhanceCrmTables = enhanceCrmTables;
  window.scrollCrmContentToTop = scrollCrmContentToTop;

  document.addEventListener('DOMContentLoaded', function () {
    icons();
    initHeaderActions();
    initSidebarToolbar();
    if (window.CA_CRM && typeof CA_CRM.preloadDashboardMetrics === 'function') {
      var initialPage = resolvePageFromLocation();
      if (initialPage === 'dashboard') {
        CA_CRM.preloadDashboardMetrics();
      }
    }
    initRouter();
    initGlobalSearch();
    initFilterModal();
    initQuickActions();
    initNotificationUI();
    enhanceCrmTables();
    if (window.CA_CRM) CA_CRM.init();
  });
})();
