/* CA Cloud Desk — CRM page templates */
window.CAPages = (function () {
  'use strict';

  var PAGE_ACTION_ICONS = {
    'Add Employee': 'user-plus',
    'Assign Lead': 'user-check',
    'Add Lead': 'plus',
    'Email Campaign': 'mail',
    'SMS Campaign': 'smartphone',
    'WhatsApp Campaign': 'message-circle',
    'New Campaign': 'megaphone',
    'Add Visitor': 'user-plus',
    'Add Consent': 'shield-check',
    'New Assignment': 'user-plus',
    'Add DND': 'ban',
    'Bulk Import': 'upload',
    'Save Settings': 'save',
    'Save Email Settings': 'save',
    'Add Account': 'plus',
    'Add Template': 'plus',
    'Mark All Read': 'check-check',
    'Refresh': 'refresh-cw',
    'Refresh Inbox': 'refresh-cw',
    'Export': 'download',
    'Export Logs': 'download',
    'Export Report': 'download',
    'Export Audit': 'download',
    'Export Summary': 'download',
    'Export PDF': 'file-text',
    'WhatsApp Settings': 'settings',
    'Schedule Follow-up': 'plus',
    'Calendar': 'calendar',
    'Apply': 'filter',
    'Apply Filters': 'filter',
    'Reset': 'rotate-ccw',
    'Reset Filters': 'rotate-ccw',
    'Clear': 'x',
    'Clear Selection': 'x',
    'Save': 'save',
    'Delete': 'trash-2',
    'Delete Forever': 'trash-2',
    'Edit': 'pencil',
    'View': 'eye',
    'View All': 'eye',
    'Preview': 'eye',
    'Preview Count': 'eye',
    'Preview Changes': 'eye',
    'Preview Message': 'eye',
    'Preview Email': 'eye',
    'Import': 'upload',
    'Assign': 'user-check',
    'Create': 'plus',
    'Send': 'send',
    'Download': 'download',
    'Download File': 'file-down',
    'Download Error Report': 'download',
    'Error Report': 'download',
    'Failed Rows CSV': 'file-up',
    'Download Failed Rows for Re-import': 'file-up',
    'Re-upload': 'refresh-cw',
    'Re-upload Corrected File': 'upload',
    'Start Export': 'download',
    'Sample CSV': 'download',
    'Sample Excel': 'file-spreadsheet',
    'Back to Firms': 'arrow-left',
    'Validate Configuration': 'shield-check',
    'Test Connection': 'plug',
    'Test SMTP Connection': 'plug',
    'Test IMAP Connection': 'inbox',
    'Sync IMAP Now': 'refresh-cw',
    'Notification Settings': 'settings',
    'Follow Up': 'phone',
    'Restore': 'rotate-ccw',
    'Open': 'external-link',
    'Process': 'rocket',
    'Retry Failed': 'rotate-ccw',
    'Call': 'phone',
    'Demo': 'video',
    'Result': 'clipboard-check',
    'Send Test Template': 'send',
    'Send Test Email': 'send',
    'Test SMS Connection': 'plug',
    'Confirm Assignment': 'check',
    'Apply Status Update': 'check',
    'Confirm Update': 'check',
    'Start Export': 'download',
  };

  function plainActionLabel(label) {
    return String(label || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function escapeAttr(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function resolveActionIcon(label, icon) {
    if (icon) return icon;
    var plain = plainActionLabel(label);
    return PAGE_ACTION_ICONS[plain] || 'plus';
  }

  /** Icon-only action button (primary / secondary / danger). */
  function actIcon(icon, label, attrs, variant) {
    var plain = plainActionLabel(label);
    var title = escapeAttr(plain);
    var cls = 'crm-toolbar-icon-btn';
    if (variant === 'primary') cls += ' crm-toolbar-icon-btn--primary';
    else if (variant === 'danger') cls += ' crm-toolbar-icon-btn--danger';
    attrs = String(attrs || '').trim();
    var classMatch = attrs.match(/(?:^|\s)class="([^"]*)"/);
    if (classMatch) {
      cls = (cls + ' ' + classMatch[1]).replace(/\s+/g, ' ').trim();
      attrs = attrs.replace(/(?:^|\s)class="[^"]*"/, ' ').trim();
    }
    var tag = attrs.indexOf('href=') >= 0 ? 'a' : 'button';
    var typeAttr = tag === 'button' && attrs.indexOf('type=') < 0 ? ' type="button"' : '';
    return '<' + tag + typeAttr + ' class="' + cls + '"' + (attrs ? ' ' + attrs : '') +
      ' data-crm-tip="' + title + '" aria-label="' + title + '">' +
      '<i data-lucide="' + resolveActionIcon(plain, icon) + '" class="h-4 w-4"></i></' + tag + '>';
  }

  function actExport(label, exportKey) {
    label = label || 'Export';
    exportKey = exportKey || plainActionLabel(label).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    return actIcon('download', label, 'data-action="export" data-export="' + exportKey + '"', 'secondary');
  }

  function actPrimary(label, attrs, icon) {
    return actIcon(icon || null, label, attrs, 'primary');
  }

  function actSecondary(label, attrs, icon) {
    return actIcon(icon || null, label, attrs, 'secondary');
  }

  function actDanger(label, attrs, icon) {
    return actIcon(icon || 'trash-2', label, attrs, 'danger');
  }

  // Shared by crm.js and other UI modules.
  window.CA_ICON_BTN = {
    icon: actIcon,
    primary: actPrimary,
    secondary: actSecondary,
    danger: actDanger,
    export: actExport,
  };

  function hdr(title, sub, er, actions) {
    var actionsHtml = actions
      ? '<div class="page-hero-actions" role="toolbar" aria-label="Page actions">' + actions + '</div>'
      : '';
    var subHtml = sub
      ? '<p class="text-body text-slate-500">' + sub + '</p>'
      : '';
    return '<div class="page-hero page-hero--standard">' +
      '<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">' +
        '<div><h1 class="text-page-title">' + title + '</h1>' +
        subHtml + '</div>' +
        actionsHtml +
      '</div></div>';
  }

  function settingsSubPageHero(sectionTitle, description, actions) {
    var actionsHtml = actions
      ? '<div class="page-hero-actions" role="toolbar" aria-label="Page actions">' + actions + '</div>'
      : '';
    var descHtml = description
      ? '<p class="text-body text-slate-500 max-w-3xl">' + description + '</p>'
      : '';
    return '<div class="page-hero page-hero--standard ecfg-settings-hero">' +
      '<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">' +
        '<div>' +
          '<p class="ecfg-settings-kicker">Settings</p>' +
          '<h1 class="text-page-title">' + sectionTitle + '</h1>' +
          descHtml +
        '</div>' +
        actionsHtml +
      '</div></div>';
  }

  function settingsUnderDevelopmentPage(navId, title, description) {
    var content =
      settingsSubPageHero(title, description || 'Configure and manage this area from Settings.') +
      '<section class="card p-10 text-center settings-dev-placeholder">' +
        '<div class="settings-dev-placeholder__icon" aria-hidden="true">' +
          '<i data-lucide="layers" class="h-10 w-10 text-slate-300"></i>' +
        '</div>' +
        '<h2 class="text-card-heading text-slate-800 mt-4">Module Under Development</h2>' +
        '<p class="text-body text-slate-500 mt-2 max-w-lg mx-auto">This configuration module is not available yet. You can continue using all other Settings sections in the meantime.</p>' +
      '</section>';
    return settingsHubLayout(navId, content);
  }

  function settingsNavCatalog() {
    return [
      { id: 'general', page: 'settings', label: 'General', icon: 'settings' },
      { id: 'sales-list', page: 'sales-list', label: 'Sales List', icon: 'receipt', roles: ['super_admin', 'manager'] },
      { id: 'roles', page: 'roles-permissions', label: 'Roles & Permissions', icon: 'shield', roles: ['super_admin'] },
      { id: 'email-templates', page: 'settings-email-templates', label: 'Email Templates', icon: 'mail', group: 'Template Management', roles: ['super_admin', 'admin', 'manager'] },
      { id: 'whatsapp-templates', page: 'settings-whatsapp-templates', label: 'WhatsApp Templates', icon: 'message-circle', group: 'Template Management', roles: ['super_admin', 'admin', 'manager'] },
      { id: 'google-api', page: 'settings-google-api', label: 'Google API Settings', icon: 'map-pin' },
      { id: 'demo-providers', page: 'settings-demo-providers', label: 'Demo Providers', icon: 'calendar-range', roles: ['super_admin'] },
      { id: 'email-configuration', page: 'email-configuration', label: 'Email Configuration', icon: 'at-sign', roles: ['super_admin'] },
    ];
  }

  function settingsHubNav(activeId) {
    var lastGroup = '';
    var itemsHtml = settingsNavCatalog().map(function (item) {
      var groupHtml = '';
      if (item.group && item.group !== lastGroup) {
        lastGroup = item.group;
        groupHtml = '<p class="settings-hub-nav__group">' + item.group + '</p>';
      }
      var isActive = item.id === activeId || (activeId === 'settings' && item.id === 'general');
      var cls = 'settings-hub-nav__item' + (isActive ? ' active' : '');
      var roleAttr = item.roles ? ' data-settings-roles="' + item.roles.join(',') + '"' : '';
      return groupHtml + '<button type="button" class="' + cls + '" data-settings-nav="' + item.page + '" data-settings-nav-id="' + item.id + '"' + roleAttr + '>' +
        '<i data-lucide="' + item.icon + '" class="h-4 w-4 shrink-0"></i>' +
        '<span class="settings-hub-nav__label">' + item.label + '</span></button>';
    }).join('');
    return '<nav class="settings-hub-nav card p-2" aria-label="Settings sections">' +
      '<p class="settings-hub-nav__title">Configuration</p>' +
      itemsHtml +
    '</nav>';
  }

  function settingsHubLayout(activeSection, contentHtml) {
    return '<div class="settings-hub-layout">' +
      '<aside class="settings-hub-aside">' + settingsHubNav(activeSection) + '</aside>' +
      '<div class="settings-hub-content">' + contentHtml + '</div>' +
    '</div>';
  }

  function tabs(items, active, group) {
    group = group || 'main';
    return '<div class="ca-tabs mb-4" data-tab-group="' + group + '">' + items.map(function (t) {
      return '<button class="ca-tab' + (t.id === active ? ' active' : '') + '" data-tab="' + t.id + '" data-tab-group="' + group + '">' +
        (t.icon ? '<i data-lucide="' + t.icon + '" class="h-4 w-4"></i>' : '') + t.label +
        (t.count !== undefined ? '<span class="ca-tab-count"' + (t.countId ? ' id="' + t.countId + '"' : '') + '>' + t.count + '</span>' : '') + '</button>';
    }).join('') + '</div>';
  }

  function heroIconTabs(items, active, group) {
    group = group || 'main';
    return '<div class="page-hero-icon-tabs" role="tablist" aria-label="Section navigation" data-tab-group="' + group + '">' +
      items.map(function (t) {
        var isActive = t.id === active;
        return '<button type="button" class="ca-tab crm-toolbar-icon-btn page-hero-tab-btn' + (isActive ? ' active is-active' : '') + '"' +
          ' data-tab="' + t.id + '" data-tab-group="' + group + '"' +
          ' role="tab" aria-selected="' + (isActive ? 'true' : 'false') + '"' +
          ' title="' + escapeAttr(t.label) + '" aria-label="' + escapeAttr(t.label) + '">' +
          '<i data-lucide="' + t.icon + '" class="h-4 w-4"></i>' +
          (t.count !== undefined ? '<span class="ca-tab-count"' + (t.countId ? ' id="' + t.countId + '"' : '') + '>' + t.count + '</span>' : '') +
        '</button>';
      }).join('') +
    '</div>';
  }

  function pageHeroToolbar(html) {
    return '<div class="page-hero-toolbar">' + html + '</div>';
  }

  function panel(id, active, html, group) {
    group = group || 'main';
    return '<div class="ca-tab-panel' + (active ? ' active' : '') + '" data-panel="' + id + '" data-tab-group="' + group + '">' + html + '</div>';
  }

  function demoCalendarPage() {
    return '<div class="dcp-page card mgr-panel" id="dcp-root">' +
      '<header class="dcp-header">' +
        '<div class="dcp-header__title">' +
          '<h2 class="dcp-header__heading"><i data-lucide="presentation" class="h-5 w-5 text-brand"></i> Demo Management Calendar</h2>' +
        '</div>' +
        '<div class="dcp-top-actions">' +
          '<div class="dcp-export-icons" aria-label="Export actions">' +
            '<button type="button" class="crm-toolbar-icon-btn" id="dcp-export-today" data-crm-tip="Export Today\'s Demos" aria-label="Export Today\'s Demos"><i data-lucide="download" class="h-4 w-4"></i></button>' +
            '<button type="button" class="crm-toolbar-icon-btn" id="dcp-export-week" data-crm-tip="Export Weekly Demos" aria-label="Export Weekly Demos"><i data-lucide="calendar-range" class="h-4 w-4"></i></button>' +
            '<button type="button" class="crm-toolbar-icon-btn" id="dcp-export-print" data-crm-tip="Print Demo Schedule" aria-label="Print Demo Schedule"><i data-lucide="printer" class="h-4 w-4"></i></button>' +
          '</div>' +
          '<button type="button" class="crm-toolbar-icon-btn crm-toolbar-icon-btn--primary" id="dcp-add-btn" data-crm-tip="Schedule Demo" aria-label="Schedule Demo"><i data-lucide="plus" class="h-4 w-4"></i></button>' +
          '<button type="button" class="btn-secondary btn-sm" id="dcp-reset-demo" title="Reset demo data"><i data-lucide="rotate-ccw" class="h-4 w-4"></i></button>' +
        '</div>' +
      '</header>' +
      '<section class="dcp-summary" id="dcp-summary" aria-label="Demo summary"></section>' +
      '<div class="dcp-search-bar">' +
        '<div class="dcp-search-row">' +
          '<input type="date" id="dcp-search-date" class="input-field input-field-sm dcp-search-date" aria-label="Filter by date" />' +
          '<button type="button" class="crm-toolbar-icon-btn crm-toolbar-icon-btn--primary" id="dcp-search-btn" aria-label="Search"><i data-lucide="search" class="h-4 w-4" aria-hidden="true"></i></button>' +
          '<button type="button" class="crm-toolbar-icon-btn" id="dcp-search-clear" aria-label="Clear"><i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i></button>' +
        '</div>' +
      '</div>' +
      '<div class="dcp-filters" id="dcp-filters" role="tablist" aria-label="Demo status filters"></div>' +
      '<div class="dcp-layout">' +
        '<div class="dcp-main">' +
          '<div class="dcp-toolbar">' +
            '<div class="dcp-toolbar-title" id="dcp-title" aria-live="polite"></div>' +
            '<div class="dcp-view-tabs" role="tablist" aria-label="Calendar view">' +
              '<button type="button" class="dcp-view-tab active" data-dcp-view="month" role="tab">Month</button>' +
              '<button type="button" class="dcp-view-tab" data-dcp-view="week" role="tab">Week</button>' +
              '<button type="button" class="dcp-view-tab" data-dcp-view="day" role="tab">Day</button>' +
              '<button type="button" class="dcp-view-tab" data-dcp-view="agenda" role="tab">Agenda</button>' +
            '</div>' +
          '</div>' +
          '<div class="dcp-body" id="dcp-body"></div>' +
        '</div>' +
        '<aside class="dcp-queue card" aria-label="Today\'s demo queue">' +
          '<div class="dcp-queue-head"><h3 class="dcp-queue-title"><i data-lucide="list-ordered" class="h-4 w-4 text-brand"></i> Today\'s Demo Queue</h3></div>' +
          '<div class="dcp-queue-list" id="dcp-queue-list"></div>' +
        '</aside>' +
      '</div>' +
    '</div>';
  }

  function employeeDashboardPage() {
    return '<div class="emp-dashboard mgr-dashboard">' +
      '<header class="mgr-top card" id="emp-top-header"></header>' +
      '<section class="mgr-panel card dash-section" id="emp-daily-targets-panel"></section>' +
      '<section class="dash-section" aria-label="Key metrics"><div class="dash-kpi-sections" id="emp-kpi-sections"></div></section>' +
      '<div id="emp-productivity-panel" class="mgr-panel card dash-productivity-panel"></div>' +
      '<div class="dash-toolbar-row">' +
        '<section class="mgr-panel card dash-quick-actions-panel"><div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="zap" class="h-5 w-5 text-brand"></i> Quick Actions</h3></div><div class="emp-quick-actions dash-quick-actions" id="emp-quick-actions"></div></section>' +
        '<section class="mgr-panel card dash-activity-panel"><div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="activity" class="h-5 w-5 text-brand"></i> Recent Activity</h3></div><div id="emp-activity-list" class="mgr-activity-feed dash-activity-feed"></div></section>' +
      '</div>' +
      '<div class="emp-grid-2 dash-section">' +
        '<section class="mgr-panel card"><div class="emp-panel-head"><h3 class="emp-panel-title mgr-panel-title">My Assigned Leads</h3><button type="button" class="mgr-link-btn" data-emp-nav="leads">Open My Leads</button></div><div id="emp-assigned-leads" class="emp-list"></div></section>' +
        '<section class="mgr-panel card"><div class="emp-panel-head"><h3 class="emp-panel-title mgr-panel-title">Today Follow-ups</h3><button type="button" class="mgr-link-btn" data-emp-nav="followups">View All</button></div><div id="emp-followups-tabs" class="emp-tabs"></div><div id="emp-followups-list" class="emp-list"></div></section>' +
      '</div>' +
      '<section class="mgr-panel card dash-section"><div class="emp-panel-head"><h3 class="emp-panel-title mgr-panel-title">My Demo Schedule</h3></div><div id="emp-demo-schedule" class="emp-list"></div></section>' +
    '</div>';
  }

  function dashboardPage() {
    return '<div class="mgr-dashboard">' +
      '<header class="mgr-top card" id="mgr-top-header"></header>' +
      '<div id="mgr-organization-target-panel" class="mgr-panel card dash-productivity-panel hidden"></div>' +
      '<div id="mgr-employee-productivity-panel" class="mgr-panel card dash-productivity-panel hidden"></div>' +
      '<section class="dash-section" aria-label="Key metrics"><div class="dash-kpi-sections" id="mgr-kpi-sections"></div></section>' +
      '<div class="dash-toolbar-row">' +
        '<section class="mgr-panel card dash-quick-actions-panel"><div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="zap" class="h-5 w-5 text-brand"></i> Quick Actions</h3></div><div id="dash-quick-actions" class="dash-quick-actions"></div></section>' +
        '<section class="mgr-panel card dash-activity-panel"><div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="activity" class="h-5 w-5 text-brand"></i> Recent Activity</h3><button type="button" class="mgr-link-btn" data-nav-page="activity">View all</button></div><div id="recent-activity-list" class="mgr-activity-feed dash-activity-feed"></div></section>' +
      '</div>' +
      '<div id="mgr-productivity-panel" class="mgr-productivity-slot dash-section"></div>' +
      '<section id="mgr-duplicate-monitoring" class="mgr-panel card dash-section hidden"></section>' +
      '<div class="dash-filter-chips dash-section" id="dash-filter-chips"></div>' +
      '<section class="dash-section" aria-label="SMS overview"><div class="dash-sms-kpi-grid" id="dash-sms-widgets">' +
        '<div class="dash-sms-kpi"><span class="dash-sms-kpi__icon"><i data-lucide="link" class="h-4 w-4"></i></span><div class="dash-sms-kpi__body"><p class="dash-sms-kpi__label">SMS Mapped Campaigns</p><p class="dash-sms-kpi__value" id="dash-sms-mapped">—</p></div></div>' +
        '<div class="dash-sms-kpi"><span class="dash-sms-kpi__icon dash-sms-kpi__icon--warn"><i data-lucide="clock" class="h-4 w-4"></i></span><div class="dash-sms-kpi__body"><p class="dash-sms-kpi__label">SMS Pending Campaigns</p><p class="dash-sms-kpi__value" id="dash-sms-pending">—</p></div></div>' +
        '<div class="dash-sms-kpi"><span class="dash-sms-kpi__icon dash-sms-kpi__icon--info"><i data-lucide="flask-conical" class="h-4 w-4"></i></span><div class="dash-sms-kpi__body"><p class="dash-sms-kpi__label">SMS Simulation Mode</p><p class="dash-sms-kpi__value" id="dash-sms-simulation">—</p></div></div>' +
        '<div class="dash-sms-kpi"><span class="dash-sms-kpi__icon dash-sms-kpi__icon--success"><i data-lucide="radio" class="h-4 w-4"></i></span><div class="dash-sms-kpi__body"><p class="dash-sms-kpi__label">SMS Live Mode</p><p class="dash-sms-kpi__value" id="dash-sms-live">—</p></div></div>' +
      '</div></section>' +
      '<section class="dash-section" aria-label="Analytics charts"><div class="dash-charts-grid">' +
        '<section class="mgr-panel card dash-chart-panel"><div class="mgr-panel-head"><div><h3 class="mgr-panel-title">Lead Source</h3><p class="mgr-panel-subtitle">Distribution by acquisition channel</p></div></div><div class="dash-chart-legend"><span class="dash-chart-legend__swatch"></span><span>Lead count</span></div><div id="dash-chart-source" class="mgr-bar-chart dash-bar-chart" data-dashboard-chart="source"></div></section>' +
        '<section class="mgr-panel card dash-chart-panel"><div class="mgr-panel-head"><div><h3 class="mgr-panel-title">Lead Pipeline</h3><p class="mgr-panel-subtitle">Status breakdown across funnel</p></div></div><div class="dash-chart-legend"><span class="dash-chart-legend__swatch dash-chart-legend__swatch--alt"></span><span>Lead count</span></div><div id="dash-chart-status" class="mgr-bar-chart dash-bar-chart" data-dashboard-chart="status"></div></section>' +
        '<section class="mgr-panel card dash-chart-panel"><div class="mgr-panel-head"><div><h3 class="mgr-panel-title">Monthly Leads</h3><p class="mgr-panel-subtitle">New leads over time</p></div></div><div class="dash-chart-legend"><span class="dash-chart-legend__swatch"></span><span>New leads</span></div><div class="ca-chart dash-column-chart h-44" data-chart="monthly" data-dashboard-chart="monthly"></div></section>' +
        '<section class="mgr-panel card dash-chart-panel"><div class="mgr-panel-head"><div><h3 class="mgr-panel-title">Employee Productivity</h3><p class="mgr-panel-subtitle">Target achievement by executive</p></div></div><div class="dash-chart-legend"><span class="dash-chart-legend__swatch dash-chart-legend__swatch--alt"></span><span>Achievement %</span></div><div class="ca-chart dash-column-chart h-44" data-chart="employee" data-dashboard-chart="employee"></div></section>' +
        '<section class="mgr-panel card dash-chart-panel"><div class="mgr-panel-head"><div><h3 class="mgr-panel-title">Campaign Performance</h3><p class="mgr-panel-subtitle">Delivered messages by channel</p></div></div><div class="dash-chart-legend"><span class="dash-chart-legend__swatch"></span><span>Delivered</span></div><div id="dash-chart-campaign" class="mgr-bar-chart dash-bar-chart" data-dashboard-chart="campaign"></div></section>' +
        '<section class="mgr-panel card dash-chart-panel"><div class="mgr-panel-head"><div><h3 class="mgr-panel-title">City Performance</h3><p class="mgr-panel-subtitle">Leads by geography</p></div></div><div class="dash-chart-legend"><span class="dash-chart-legend__swatch dash-chart-legend__swatch--alt"></span><span>Total leads</span></div><div id="dash-chart-city" class="mgr-bar-chart dash-bar-chart" data-dashboard-chart="city"></div></section>' +
      '</div></section>' +
      '<div class="mgr-grid-3 dash-section">' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="git-branch" class="h-5 w-5 text-brand"></i> Sales Pipeline</h3><button type="button" class="mgr-link-btn" data-nav-page="ca-master">View all</button></div><div id="mgr-pipeline-funnel" class="mgr-pipeline"></div></section>' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="flame" class="h-5 w-5 text-amber-500"></i> Priority Today</h3><button type="button" class="mgr-link-btn" data-nav-page="followups">Follow-ups</button></div><div id="mgr-priority-list" class="mgr-priority-list"></div></section>' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="users" class="h-5 w-5 text-brand"></i> Team Snapshot</h3><button type="button" class="mgr-link-btn" data-nav-page="employees">Manage team</button></div><div id="mgr-team-cards" class="mgr-team-scroll"></div></section>' +
      '</div>' +
      '<div class="mgr-grid-2 dash-section">' +
        '<section class="mgr-panel card dash-table-panel"><div class="mgr-panel-head"><h3 class="mgr-panel-title">Team Performance</h3><button type="button" class="mgr-link-btn" data-nav-page="reports">Reports</button></div><div class="crm-table-container scrollbar-thin dash-table-scroll"><table class="ca-table w-full mgr-table dash-table"><thead><tr><th>Employee</th><th>City</th><th>Leads</th><th>Target</th><th>Calls</th><th>Demos</th></tr></thead><tbody id="team-overview-table"></tbody></table></div></section>' +
        '<section class="mgr-panel card dash-table-panel"><div class="mgr-panel-head"><h3 class="mgr-panel-title">Recent Leads</h3><button type="button" class="mgr-link-btn" data-nav-page="ca-master">View all</button></div><div class="crm-table-container scrollbar-thin dash-table-scroll"><table class="ca-table w-full mgr-table dash-table"><thead><tr><th>Firm</th><th>Status</th><th>Employee</th><th>Updated</th></tr></thead><tbody id="dashboard-leads-table"></tbody></table></div></section>' +
      '</div>' +
      '<section id="mgr-followup-automation-panel" class="mgr-panel card dash-section">' +
        '<div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="calendar-clock" class="h-5 w-5 text-brand"></i> Follow-up Status</h3><button type="button" class="mgr-link-btn" data-nav-page="followups">Open follow-ups</button></div>' +
        '<div class="dash-fu-kpi-grid">' +
          '<div class="dash-fu-kpi"><span class="dash-fu-kpi__label">Today</span><strong class="dash-fu-kpi__value" id="mgr-fu-today">—</strong></div>' +
          '<div class="dash-fu-kpi"><span class="dash-fu-kpi__label">Upcoming</span><strong class="dash-fu-kpi__value" id="mgr-fu-upcoming">—</strong></div>' +
          '<div class="dash-fu-kpi"><span class="dash-fu-kpi__label">Completed Today</span><strong class="dash-fu-kpi__value" id="mgr-fu-completed-today">—</strong></div>' +
          '<div class="dash-fu-kpi"><span class="dash-fu-kpi__label">Missed</span><strong class="dash-fu-kpi__value" id="mgr-fu-missed">—</strong></div>' +
          '<div class="dash-fu-kpi dash-fu-kpi--danger"><span class="dash-fu-kpi__label">Overdue</span><strong class="dash-fu-kpi__value" id="mgr-fu-overdue">—</strong></div>' +
          '<div class="dash-fu-kpi"><span class="dash-fu-kpi__label">Follow-up Conv.</span><strong class="dash-fu-kpi__value" id="mgr-fu-conversion">—</strong></div>' +
          '<div class="dash-fu-kpi"><span class="dash-fu-kpi__label">Demo Conv.</span><strong class="dash-fu-kpi__value" id="mgr-fu-demo-conversion">—</strong></div>' +
        '</div>' +
        '<div id="mgr-fu-employee-list" class="mgr-fu-employee-list"></div>' +
      '</section>' +
      '<section id="mgr-workflow-panel" class="mgr-panel card dash-section">' +
        '<div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="workflow" class="h-5 w-5 text-brand"></i> Lead Workflow</h3></div>' +
        '<div class="dash-workflow-counts" id="mgr-workflow-counts"></div>' +
        '<div class="emp-tabs" id="mgr-workflow-tabs"></div>' +
        '<div id="mgr-workflow-list" class="emp-list mt-3"></div>' +
        '<div id="mgr-workflow-performance" class="mt-4"></div>' +
      '</section>' +
    '</div>';
  }

  function summaryNavCards(items, opts) {
    opts = opts || {};
    var gridCls = opts.gridCls || 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3 gap-4 mb-6';
    var idAttr = opts.id ? ' id="' + opts.id + '"' : '';
    return '<div class="' + gridCls + '"' + idAttr + '>' +
      items.map(function (w) {
        var clickable = w.static !== true;
        var activeCls = w.active ? ' is-active' : '';
        var staticCls = w.static ? ' crm-summary-nav-card--static' : '';
        var panelAttr = w.panel ? ' data-security-panel="' + w.panel + '"' : '';
        var navAttr = w.page ? ' data-nav-page="' + w.page + '"' : '';
        var tabAttr = w.tab ? ' data-consent-tab="' + w.tab + '"' : '';
        var metricId = w.metricId ? ' id="' + w.metricId + '"' : '';
        var roleAttrs = clickable
          ? ' role="button" tabindex="0"'
          : '';
        var ariaLabel = ' aria-label="' + w.title + '"';
        return '<div class="card-interactive p-4 crm-kpi-card crm-summary-nav-card security-card' +
          (clickable ? ' crm-kpi-card--clickable' : '') + activeCls + staticCls + '"' +
          panelAttr + navAttr + tabAttr + roleAttrs + ariaLabel + '>' +
          '<div class="flex justify-between mb-2">' +
            '<div class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand">' +
              '<i data-lucide="' + w.icon + '" class="h-5 w-5"></i>' +
            '</div>' +
            '<span class="stat-pill bg-emerald-50 text-emerald-700">' + w.badge + '</span>' +
          '</div>' +
          '<p class="crm-summary-nav-card__title">' + w.title + '</p>' +
          '<p class="crm-summary-nav-card__metric text-caption text-slate-500 mt-1"' + metricId + '>' + (w.metric || '—') + '</p>' +
        '</div>';
      }).join('') + '</div>';
  }

  function kpis(items, opts) {
    opts = opts || {};
    var compact = !!opts.compact;
    var gridCls = compact
      ? 'crm-kpi-grid crm-kpi-grid--compact grid grid-cols-2 md:grid-cols-4 gap-2 mb-3'
      : 'grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 mb-6';
    return '<div class="' + gridCls + '">' +
      items.map(function (k) {
        var filterAttrs = k.filterKey
          ? ' data-kpi-filter="' + k.filterKey + '"' + (k.listing ? ' data-kpi-listing="' + k.listing + '"' : '') + ' role="button" tabindex="0"'
          : '';
        var clickableCls = k.filterKey ? ' crm-kpi-card--clickable' : '';
        if (compact) {
          return '<div class="card-interactive crm-kpi-card crm-kpi-card--compact' + clickableCls + '" data-kpi="' + k.label + '"' + filterAttrs + '>' +
            '<div class="crm-kpi-card__icon"><i data-lucide="' + k.icon + '" class="h-4 w-4"></i></div>' +
            '<div class="crm-kpi-card__body">' +
              '<p class="crm-kpi-card__label">' + k.label + '</p>' +
              '<p class="crm-kpi-card__value"' + (k.valueId ? ' id="' + k.valueId + '"' : '') + '>' + k.value + '</p>' +
            '</div>' +
            '<span class="stat-pill crm-kpi-card__pill bg-emerald-50 text-emerald-700">' + k.trend + '</span>' +
          '</div>';
        }
        return '<div class="card-interactive p-4 crm-kpi-card' + clickableCls + '" data-kpi="' + k.label + '"' + filterAttrs + '><div class="flex justify-between mb-2">' +
          '<div class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand"><i data-lucide="' + k.icon + '" class="h-5 w-5"></i></div>' +
          '<span class="stat-pill bg-emerald-50 text-emerald-700">' + k.trend + '</span></div>' +
          '<p class="text-caption text-slate-500">' + k.label + '</p>' +
          '<p class="text-xl font-bold text-slate-900 mt-1"' + (k.valueId ? ' id="' + k.valueId + '"' : '') + '>' + k.value + '</p></div>';
      }).join('') + '</div>';
  }

  function table(cols, rows, opts) {
    opts = opts || {};
    if (opts.enterprise) {
      var ecols = cols.map(function (c) {
        if (typeof c === 'string') return { label: c };
        return c;
      });
      return enterpriseTable(ecols, opts);
    }
    var cls = opts.cls || '';
    var tbodyId = opts.tbodyId ? ' id="' + opts.tbodyId + '"' : '';
    var tableIdAttr = opts.tableId ? ' id="' + opts.tableId + '"' : '';
    var footer = opts.paginationId
      ? '<div class="crm-table-footer" id="' + opts.paginationId + '"></div>'
      : '';
    var body = rows.length ? rows.map(function (r, i) {
      var data = opts.rowData && opts.rowData[i] ? ' data-row=\'' + JSON.stringify(opts.rowData[i]).replace(/'/g, '&#39;') + '\'' : '';
      return '<tr class="ca-table-row"' + data + '>' + r.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>';
    }).join('') : '';
    return '<div class="card overflow-hidden crm-table-card ' + cls + '"><div class="crm-table-container scrollbar-thin"><table class="ca-table w-full"' + tableIdAttr + '>' +
      '<thead><tr>' + cols.map(function (c) { return '<th>' + (typeof c === 'string' ? c : c.label) + '</th>'; }).join('') + '</tr></thead>' +
      '<tbody' + tbodyId + '>' + body + '</tbody></table></div>' + footer + '</div>';
  }

  function inboxBulkIconBtn(action, icon, tooltip) {
    return '<button type="button" class="crm-bulk-icon-btn" data-inbox-action="' + action + '" data-crm-tip="' + tooltip + '" aria-label="' + tooltip + '">' +
      '<i data-lucide="' + icon + '" class="h-4 w-4"></i>' +
    '</button>';
  }

  function rbacCan(module, permission) {
    if (window.CA_RBAC && typeof CA_RBAC.can === 'function') {
      return CA_RBAC.can(module, permission);
    }
    return true;
  }

  function inboxRbacModule(module) {
    if (module === 'ca-master') return 'ca_master';
    if (module === 'leads') return 'leads';
    if (module === 'followups') return 'followups';
    if (module === 'assignment') return 'assignment';
    return module;
  }

  function inboxBulkActionsForModule(module) {
    var rbacModule = inboxRbacModule(module);
    var actions = [];
    if ((module === 'ca-master' || module === 'leads') && rbacCan('assignment', 'create')) {
      actions.push(inboxBulkIconBtn('assign', 'user-check', 'Assign'));
    }
    if (rbacCan(rbacModule, 'delete')) {
      actions.push(inboxBulkIconBtn('delete', 'trash-2', 'Delete Selected'));
    }
    return actions.join('');
  }

  function inboxBulkBarHtml(inboxKey, module) {
    module = module || 'leads';
    var actions = inboxBulkActionsForModule(module);
    if (!actions) return '';
    return '<div class="crm-inbox-bulk-bar hidden" id="' + inboxKey + '-bulk-bar" data-inbox-table="' + inboxKey + '" data-inbox-module="' + module + '" aria-live="polite">' +
      '<span class="crm-inbox-bulk-count" data-inbox-count="' + inboxKey + '">0 selected</span>' +
      '<div class="crm-inbox-bulk-toolbar" role="toolbar" aria-label="Bulk actions">' +
        inboxBulkActionsForModule(module) +
      '</div>' +
    '</div>';
  }

  function columnFilterCellHtml(c, opts) {
    var cls = ['crm-col-filter-th'];
    if (c.thCls) cls.push(c.thCls);
    if (c.sticky === 'left') cls.push('sticky-left');
    if (c.sticky === 'left-2') cls.push('sticky-left-2');
    if (c.sticky === 'left-3') cls.push('sticky-left-3');
    if (c.sticky === 'right') cls.push('sticky-right');

    var filterType = c.filterType;
    if (!filterType) {
      if (c.filterReset) filterType = 'reset';
      else if (c.filterOptionsHtml !== undefined) filterType = 'select';
      else if (c.filterKey || c.filterId) filterType = 'search';
    }
    if (!filterType || filterType === 'none') {
      return '<th class="' + cls.join(' ') + '" scope="col"></th>';
    }

    var inputCls = 'crm-col-filter-input';
    var ariaLabel = 'Filter ' + (c.label || c.filterKey || '');
    var control = '';

    var groupAttr = opts.filterGroup ? ' data-col-filter-group="' + opts.filterGroup + '"' : '';
    var keyAttr = c.filterKey ? ' data-col-filter="' + c.filterKey + '"' : '';

    if (filterType === 'reset') {
      control = actSecondary('Reset', 'id="' + c.filterId + '" class="crm-col-filter-reset"', 'rotate-ccw');
    } else if (filterType === 'select') {
      var selectId = c.filterId ? ' id="' + c.filterId + '"' : '';
      control = '<select class="' + inputCls + ' crm-col-filter-select"' + selectId + keyAttr + groupAttr +
        ' aria-label="' + escapeAttr(ariaLabel) + '">' + (c.filterOptionsHtml || '') + '</select>';
    } else if (filterType === 'date') {
      var dateId = c.filterId ? ' id="' + c.filterId + '"' : '';
      control = '<input type="date" class="' + inputCls + '"' + dateId + keyAttr + groupAttr +
        (c.filterAttrs ? ' ' + c.filterAttrs : '') +
        ' aria-label="' + escapeAttr(ariaLabel) + '" />';
    } else if (filterType === 'number') {
      var numberId = c.filterId ? ' id="' + c.filterId + '"' : '';
      control = '<input type="number" class="' + inputCls + '"' + numberId + keyAttr + groupAttr +
        ' placeholder="' + escapeAttr(c.filterPlaceholder || 'search') + '"' +
        ' aria-label="' + escapeAttr(ariaLabel) + '" autocomplete="off" step="any" />';
    } else if (filterType === 'number-range') {
      control = '<div class="crm-col-filter-range">' +
        '<input type="number" class="' + inputCls + ' crm-col-filter-range-min" data-col-filter-min="' + c.filterMinKey + '"' + groupAttr +
        ' placeholder="min" aria-label="' + escapeAttr(ariaLabel) + ' min" autocomplete="off" step="any" />' +
        '<input type="number" class="' + inputCls + ' crm-col-filter-range-max" data-col-filter-max="' + c.filterMaxKey + '"' + groupAttr +
        ' placeholder="max" aria-label="' + escapeAttr(ariaLabel) + ' max" autocomplete="off" step="any" />' +
      '</div>';
    } else {
      var searchId = c.filterId ? ' id="' + c.filterId + '"' : '';
      var placeholder = c.filterPlaceholder || 'search';
      var dataAttrs = c.filterKey
        ? ' data-col-filter="' + c.filterKey + '"' +
          (opts.filterGroup ? ' data-col-filter-group="' + opts.filterGroup + '"' : '')
        : '';
      control = '<input type="search" class="' + inputCls + '"' + searchId + dataAttrs +
        ' placeholder="' + escapeAttr(placeholder) + '" aria-label="' + escapeAttr(ariaLabel) + '" autocomplete="off" />';
    }

    return '<th class="' + cls.join(' ') + '" scope="col">' + control + '</th>';
  }

  function listingFilterDataAttrs(f) {
    var attrs = f.attrs ? ' ' + f.attrs : '';
    if (f.filterKey) attrs += ' data-col-filter="' + f.filterKey + '"';
    if (f.filterGroup) attrs += ' data-col-filter-group="' + f.filterGroup + '"';
    return attrs;
  }

  function listingFilterBar(fields, actionsHtml, opts) {
    opts = opts || {};
    var idAttr = opts.id ? ' id="' + opts.id + '"' : '';
    var cells = (fields || []).map(function (f) {
      var label = String(f.label || '').toUpperCase();
      var inputCls = 'crm-col-filter-input crm-listing-filter-input';
      var control = '';
      var dataAttrs = listingFilterDataAttrs(f);
      var forAttr = f.type === 'number-range' ? '' : ' for="' + f.id + '"';
      if (f.type === 'select') {
        control = '<select id="' + f.id + '" class="' + inputCls + ' crm-col-filter-select"' + dataAttrs +
          ' aria-label="' + escapeAttr(f.label) + '">' + (f.options || '') + '</select>';
      } else if (f.type === 'date') {
        control = '<input type="date" id="' + f.id + '" class="' + inputCls + '"' + dataAttrs +
          ' aria-label="' + escapeAttr(f.label) + '" />';
      } else if (f.type === 'number-range') {
        var groupAttr = f.filterGroup ? ' data-col-filter-group="' + f.filterGroup + '"' : '';
        control = '<div class="crm-col-filter-range crm-listing-filter-range">' +
          '<input type="number" class="' + inputCls + ' crm-col-filter-range-min" data-col-filter-min="' + f.filterMinKey + '"' + groupAttr +
          ' placeholder="min" aria-label="' + escapeAttr(f.label) + ' min" autocomplete="off" step="any" />' +
          '<input type="number" class="' + inputCls + ' crm-col-filter-range-max" data-col-filter-max="' + f.filterMaxKey + '"' + groupAttr +
          ' placeholder="max" aria-label="' + escapeAttr(f.label) + ' max" autocomplete="off" step="any" />' +
        '</div>';
      } else {
        control = '<input type="' + (f.type || 'search') + '" id="' + f.id + '" class="' + inputCls + '"' + dataAttrs +
          ' placeholder="' + escapeAttr(f.placeholder || 'search') + '"' +
          ' aria-label="' + escapeAttr(f.label) + '" autocomplete="off" />';
      }
      return '<div class="crm-listing-filter-cell' + (f.type === 'number-range' ? ' crm-listing-filter-cell--range' : '') + '">' +
        '<label class="crm-listing-filter-label"' + forAttr + '>' + label + '</label>' +
        control +
      '</div>';
    }).join('');
    var actions = '';
    if (actionsHtml) {
      if (opts.actionsInline) {
        cells += '<div class="crm-listing-filter-cell crm-listing-filter-cell--actions">' +
          '<span class="crm-listing-filter-label" aria-hidden="true">&nbsp;</span>' +
          '<div class="crm-listing-filter-inline-actions">' + actionsHtml + '</div>' +
        '</div>';
      } else {
        actions = '<div class="crm-listing-filter-actions">' + actionsHtml + '</div>';
      }
    }
    return '<div class="crm-listing-filter-bar card mb-4"' + idAttr + '>' +
      '<div class="crm-listing-filter-grid">' + cells + '</div>' + actions +
    '</div>';
  }

  function bumpInboxStickySlot(sticky) {
    if (sticky === 'left') return 'left-2';
    if (sticky === 'left-2') return 'left-3';
    if (sticky === 'left-3') return 'left-4';
    return sticky;
  }

  function isStickyLeftSlot(sticky) {
    return sticky === 'left' || sticky === 'left-2' || sticky === 'left-3' || sticky === 'left-4';
  }

  function stickyLeftClass(sticky) {
    return sticky ? 'sticky-' + sticky : '';
  }

  function enterpriseTable(columns, opts) {
    opts = opts || {};
    var cols = columns.slice();
    var inboxKey = opts.inboxKey || opts.tbodyId || '';
    if (opts.inbox) {
      cols.unshift({
        label: '<input type="checkbox" class="crm-inbox-check-all" data-inbox-table="' + inboxKey + '" aria-label="Select all rows" />',
        colCls: 'crm-col-check',
        thCls: 'crm-th-check sticky-left',
        sticky: 'left',
        html: true,
      });
      for (var i = 1; i < cols.length; i++) {
        if (!isStickyLeftSlot(cols[i].sticky)) continue;
        cols[i].sticky = bumpInboxStickySlot(cols[i].sticky);
        cols[i].thCls = String(cols[i].thCls || '')
          .replace(/\bsticky-left(?:-2|-3|-4)?\b/g, '')
          .replace(/\s+/g, ' ')
          .trim();
        cols[i].thCls = (cols[i].thCls + ' ' + stickyLeftClass(cols[i].sticky)).trim();
      }
    }
    var tbodyAttr = opts.tbodyId ? ' id="' + opts.tbodyId + '"' : '';
    var tableAttr = opts.tableId ? ' id="' + opts.tableId + '"' : '';
    var wrapAttr = opts.wrapId ? ' id="' + opts.wrapId + '"' : '';
    var colgroup = '<colgroup>' + cols.map(function (c) {
      return '<col' + (c.colCls ? ' class="' + c.colCls + '"' : '') + ' />';
    }).join('') + '</colgroup>';
    var ths = cols.map(function (c) {
      var cls = [];
      if (c.thCls) cls.push(c.thCls);
      if (c.sticky === 'left') cls.push('sticky-left');
      if (c.sticky === 'left-2') cls.push('sticky-left-2');
      if (c.sticky === 'left-3') cls.push('sticky-left-3');
      if (c.sticky === 'right') cls.push('sticky-right');
      return '<th' + (cls.length ? ' class="' + cls.join(' ') + '"' : '') + ' scope="col">' +
        (c.html ? c.label : c.label) + '</th>';
    }).join('');
    var filterRow = '';
    if (opts.columnFilters) {
      filterRow = '<tr class="crm-col-filter-row">' + cols.map(function (c) {
        return columnFilterCellHtml(c, opts);
      }).join('') + '</tr>';
    }
    var footer = opts.paginationId
      ? '<div class="crm-table-footer" id="' + opts.paginationId + '"></div>'
      : '';
    var bulkBar = opts.inbox
      ? inboxBulkBarHtml(inboxKey, opts.inboxModule || 'leads')
      : '';
    return '<div class="crm-table-card card ' + (opts.cls || '') + (opts.inbox ? ' crm-table-card--inbox' : '') + (opts.columnFilters ? ' crm-table-card--col-filters' : '') + '"' +
      (opts.tbodyId ? ' data-crm-table="' + opts.tbodyId + '"' : '') +
      (opts.inbox ? ' data-inbox-table="' + inboxKey + '"' : '') + '>' +
      bulkBar +
      '<div class="table-scroll-container crm-table-container scrollbar-thin"' + wrapAttr + '>' +
        '<table class="crm-table ca-table ca-table--enterprise w-full"' + tableAttr + '>' +
          colgroup +
          '<thead><tr>' + ths + '</tr>' + filterRow + '</thead>' +
          '<tbody' + tbodyAttr + '></tbody>' +
        '</table></div>' +
      footer +
    '</div>';
  }

  function charts(ids) {
    return '<div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">' +
      ids.map(function (c) {
        var label = typeof c === 'string' ? c : c.label;
        var key = typeof c === 'string' ? c : c.key;
        return '<div class="card p-5 card-interactive"><h3 class="text-card-heading mb-4">' + label + '</h3><div class="ca-chart h-40" data-chart-key="' + key + '"></div></div>';
      }).join('') + '</div>';
  }

  /* ─── CA Master ─── */
  function caMasterSummaryCards() {
    var cards = [
      { id: 'cam-stat-total', key: 'total', label: 'Total Firms', icon: 'building-2', tone: 'brand' },
      { id: 'cam-stat-new', key: 'new', label: 'New Firms', icon: 'sparkles', tone: 'amber' },
      { id: 'cam-stat-active', key: 'active', label: 'Active Firms', icon: 'badge-check', tone: 'emerald' },
      { id: 'cam-stat-duplicates', key: 'duplicates', label: 'Duplicate Attempts', icon: 'copy-x', tone: 'rose' },
      { id: 'cam-stat-missing-mobile', key: 'missing-mobile', label: 'Missing Mobile', icon: 'phone-off', tone: 'slate' },
      { id: 'cam-stat-verified', key: 'verified', label: 'Verified Firms', icon: 'shield-check', tone: 'teal' },
    ];
    return '<div class="cam-summary-grid" id="cam-summary-grid">' + cards.map(function (c) {
      return '<button type="button" class="cam-summary-card cam-summary-card--clickable cam-summary-card--' + c.tone + '" data-cam-summary="' + c.key + '" title="Show ' + c.label + '">' +
        '<div class="cam-summary-icon"><i data-lucide="' + c.icon + '" class="h-5 w-5"></i></div>' +
        '<div class="cam-summary-body">' +
          '<p class="cam-summary-label">' + c.label + '</p>' +
          '<p class="cam-summary-value" id="' + c.id + '">—</p>' +
        '</div></button>';
    }).join('') + '</div>';
  }

  function caMasterStatusFilterOptions() {
    return [
      'Interested',
      'Thinking',
      'Purchasing',
      'Purchased',
      'Not Interested',
      'Next Week',
      'Next Month',
      'Hold',
    ];
  }

  function caMasterStatusFilterOptionsHtml(allLabel) {
    return '<option value="">' + (allLabel || 'All Status') + '</option>' +
      caMasterStatusFilterOptions().map(function (status) {
        return '<option value="' + escapeAttr(status) + '">' + status + '</option>';
      }).join('');
  }

  function caMasterFirmsTable(tbodyId, tableId, paginationId) {
    tbodyId = tbodyId || 'ca-master-data-table';
    tableId = tableId || 'ca-master-table';
    paginationId = paginationId || 'ca-master-pagination-slot';
    return enterpriseTable([
      { label: 'Firm Name', colCls: 'crm-col-firm col-firm', thCls: 'crm-th-firm col-firm', sticky: 'left', filterKey: 'firm_name', filterPlaceholder: 'search' },
      { label: 'CA Name', colCls: 'crm-col-ca col-ca', thCls: 'crm-th-ca col-ca', filterKey: 'ca_name', filterPlaceholder: 'search' },
      { label: 'Team Size', colCls: 'crm-col-team-size', thCls: 'crm-th-team-size', sortField: 'team_size', filterKey: 'team_size', filterPlaceholder: 'search' },
      { label: 'Last Activity', colCls: 'crm-col-last-activity', thCls: 'crm-th-last-activity', sortField: 'last_activity_at' },
      { label: 'Mobile', colCls: 'crm-col-mobile', thCls: 'crm-th-mobile', filterKey: 'mobile_no', filterPlaceholder: 'search' },
      { label: 'Call Log', colCls: 'crm-col-call-log', thCls: 'crm-th-call-log' },
      { label: 'Alt Mobile', colCls: 'crm-col-mobile', thCls: 'crm-th-mobile', filterKey: 'alternate_mobile_no', filterPlaceholder: 'search' },
      { label: 'City', colCls: 'crm-col-geo', thCls: 'crm-th-geo', filterKey: 'city', filterPlaceholder: 'search' },
      { label: 'State', colCls: 'crm-col-geo', thCls: 'crm-th-geo', filterKey: 'state', filterPlaceholder: 'search' },
      { label: 'Source', colCls: 'crm-col-source', thCls: 'crm-th-source', filterKey: 'source', filterPlaceholder: 'search' },
      { label: 'Rating', colCls: 'crm-col-rating', thCls: 'crm-th-rating' },
      { label: 'Status', colCls: 'crm-col-status', thCls: 'crm-th-status', filterKey: 'status', filterType: 'select', filterOptionsHtml: caMasterStatusFilterOptionsHtml('All Status') },
      { label: 'Employee', colCls: 'crm-col-person', thCls: 'crm-th-person', filterKey: 'executive', filterPlaceholder: 'search' },
      { label: 'Created By', colCls: 'crm-col-person', thCls: 'crm-th-person' },
      { label: 'Updated', colCls: 'crm-col-date', thCls: 'crm-th-date' },
      { label: 'Google', colCls: 'crm-col-research cam-col-google', thCls: 'crm-th-research crm-th-google cam-col-google' },
      { label: 'Actions', colCls: 'crm-col-actions col-actions', thCls: 'crm-th-actions col-actions', sticky: 'right' },
    ], {
      tbodyId: tbodyId,
      tableId: tableId,
      wrapId: tableId + '-wrap',
      paginationId: paginationId,
      cls: 'cam-table-card leads-table-card',
      inbox: true,
      inboxKey: tbodyId,
      inboxModule: 'ca-master',
      columnFilters: true,
      filterGroup: 'ca_masters',
    });
  }

  function leadsEnterpriseTable() {
    return enterpriseTable([
      { label: 'Firm', colCls: 'crm-col-firm col-firm', thCls: 'crm-th-firm col-firm', sticky: 'left', filterKey: 'firm_name', filterPlaceholder: 'search' },
      { label: 'Lead Name', colCls: 'crm-col-ca col-ca', thCls: 'crm-th-ca col-ca', sticky: 'left-2', filterKey: 'ca_name', filterPlaceholder: 'search' },
      { label: 'Mobile', colCls: 'crm-col-mobile', thCls: 'crm-th-mobile', filterKey: 'mobile_no', filterPlaceholder: 'search' },
      { label: 'Call Log', colCls: 'crm-col-call-log', thCls: 'crm-th-call-log' },
      { label: 'Alt Mobile', colCls: 'crm-col-mobile', thCls: 'crm-th-mobile', filterKey: 'alternate_mobile_no', filterPlaceholder: 'search' },
      { label: 'City', colCls: 'crm-col-geo', thCls: 'crm-th-geo', filterKey: 'city', filterPlaceholder: 'search' },
      { label: 'Stage', colCls: 'crm-col-status', thCls: 'crm-th-status' },
      { label: 'Status', colCls: 'crm-col-status', thCls: 'crm-th-status', filterKey: 'status', filterPlaceholder: 'search' },
      { label: 'Employee', colCls: 'crm-col-person', thCls: 'crm-th-person', filterKey: 'executive', filterPlaceholder: 'search' },
      { label: 'Source', colCls: 'crm-col-source', thCls: 'crm-th-source', filterKey: 'source', filterPlaceholder: 'search' },
      { label: 'Priority', colCls: 'crm-col-rating', thCls: 'crm-th-rating' },
      { label: 'Updated', colCls: 'crm-col-date', thCls: 'crm-th-date' },
      { label: 'Google Lookup', colCls: 'crm-col-research', thCls: 'crm-th-research' },
      { label: 'Actions', colCls: 'crm-col-actions col-actions', thCls: 'crm-th-actions col-actions', sticky: 'right' },
    ], {
      tbodyId: 'leads-data-table',
      tableId: 'leads-table',
      wrapId: 'leads-table-wrap',
      paginationId: 'leads-pagination-slot',
      cls: 'leads-table-card cam-table-card',
      inbox: true,
      inboxKey: 'leads-data-table',
      inboxModule: 'leads',
      columnFilters: true,
      filterGroup: 'ca_masters',
    });
  }

  function caMasterPage(activeTab) {
    activeTab = activeTab || 'all';
    var showSecondary = activeTab === 'masters' || activeTab === 'bulk';
    var primaryTab = showSecondary ? 'all' : (activeTab === 'pipeline' ? 'pipeline' : 'all');

    var masterToolbar = function (entity, label) {
      return '<div class="flex justify-end mb-3">' +
        actPrimary('Add ' + label, 'data-master-add="' + entity + '"', 'plus') +
      '</div>';
    };
    var masterPanels =
      tabs([{ id: 'state', label: 'States' }, { id: 'city', label: 'Cities' }, { id: 'source', label: 'Lead Sources' }, { id: 'team', label: 'Team Sizes' }, { id: 'roles', label: 'Roles' }], 'state', 'masters') +
      panel('state', true,
        masterToolbar('state', 'State') +
        table(['State Name', 'Cities', 'Created', 'Actions'], [], { tbodyId: 'master-states-table', tableId: 'master-states-table-el', cls: 'cam-master-subtable', paginationId: 'master-states-pagination-slot' }), 'masters') +
      panel('city', false,
        masterToolbar('city', 'City') +
        table(['City Name', 'State', 'Leads', 'Created', 'Actions'], [], { tbodyId: 'master-cities-table', tableId: 'master-cities-table-el', cls: 'cam-master-subtable', paginationId: 'master-cities-pagination-slot' }), 'masters') +
      panel('source', false,
        masterToolbar('source', 'Source') +
        table(['Source Name', 'Description', 'Leads', 'Actions'], [], { tbodyId: 'master-sources-table', tableId: 'master-sources-table-el', cls: 'cam-master-subtable', paginationId: 'master-sources-pagination-slot' }), 'masters') +
      panel('team', false,
        masterToolbar('team', 'Team Size') +
        table(['Min', 'Max', 'Label', 'Firms', 'Actions'], [], { tbodyId: 'master-team-sizes-table', tableId: 'master-team-sizes-table-el', cls: 'cam-master-subtable', paginationId: 'master-team-sizes-pagination-slot' }), 'masters') +
      panel('roles', false,
        masterToolbar('role', 'Role') +
        table(['Role Name', 'Description', 'Actions'], [], { tbodyId: 'master-roles-table', tableId: 'master-roles-table-el', cls: 'cam-master-subtable', paginationId: 'master-roles-pagination-slot' }), 'masters');

    return '<div class="cam-hub leads-hub cam-hub--compact" id="cam-hub" data-cam-secondary="' + (showSecondary ? activeTab : '') + '">' +
      '<div class="cam-hub-top">' +
        '<div class="cam-hub-title-row">' +
          '<h1 class="text-page-title cam-hub-title">Master Data</h1>' +
        '</div>' +
        '<div class="cam-control-row card" id="cam-kpi-strip" role="tablist" aria-label="Master Data views"></div>' +
      '</div>' +
      '<div class="cam-primary-views' + (showSecondary ? ' hidden' : '') + '" id="cam-primary-views">' +
        '<div class="ca-tab-panel' + (primaryTab === 'pipeline' ? ' active' : '') + '" data-panel="pipeline" data-tab-group="cam-view">' +
          '<div class="card cam-pipeline-card cam-pipeline-card--master">' +
            '<div class="cam-pipeline-scroll">' +
              '<div id="kanban-board" class="kanban-board kanban-board--master"></div>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div class="ca-tab-panel' + (primaryTab === 'all' ? ' active' : '') + '" data-panel="all" data-tab-group="cam-view">' +
          '<div class="cam-firms-stack">' +
            caMasterFirmsTable('ca-master-data-table') +
          '</div>' +
        '</div>' +
      '</div>' +
      '<div class="cam-secondary-views' + (showSecondary ? '' : ' hidden') + '" id="cam-secondary-views">' +
        '<div class="flex items-center gap-2 mb-3">' +
          actSecondary('Back to Firms', 'data-cam-action="back-to-firms"', 'arrow-left') +
          '<h2 class="text-card-heading" id="cam-secondary-title">' +
            (activeTab === 'masters' ? 'Master Tables' : (activeTab === 'bulk' ? 'Bulk Tools' : '')) +
          '</h2>' +
        '</div>' +
        '<div id="cam-secondary-masters" class="' + (activeTab === 'masters' ? '' : 'hidden') + '">' +
          '<div class="cam-master-panels">' + masterPanels + '</div>' +
        '</div>' +
        '<div id="cam-secondary-bulk" class="' + (activeTab === 'bulk' ? '' : 'hidden') + '">' +
          bulkBody() +
        '</div>' +
      '</div>' +
    '</div>';
  }

  function bulkBody() {
    var bulkItems = [
      { bulk: 'Bulk Import', icon: 'layers' },
      { bulk: 'Bulk Assignment', icon: 'user-check' },
      { bulk: 'Bulk Export', icon: 'download' },
      { bulk: 'Bulk Status Update', icon: 'refresh-cw' },
    ];
    return '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6 bulk-tools-grid">' +
        bulkItems.map(function (item) {
          return '<div class="card-interactive crm-kpi-card crm-kpi-card--clickable bulk-action-card" data-bulk="' + item.bulk + '" role="button" tabindex="0" aria-label="' + item.bulk + '">' +
            '<div class="bulk-action-card__top">' +
              '<div class="bulk-action-card__icon" aria-hidden="true">' +
                '<i data-lucide="' + item.icon + '"></i>' +
              '</div>' +
              '<span class="stat-pill bg-emerald-50 text-emerald-700">Ready</span>' +
            '</div>' +
            '<p class="bulk-action-card__title">' + item.bulk + '</p>' +
          '</div>';
        }).join('') +
      '</div>' +
      '<div id="bulk-import-wizard" class="card p-5 mb-6 hidden bulk-import-wizard">' +
        '<div class="bulk-import-wizard__head flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-5">' +
          '<div class="shrink-0"><h2 class="text-section-heading flex items-center gap-2"><i data-lucide="upload" class="h-5 w-5 text-brand" aria-hidden="true"></i> Bulk Import Wizard</h2></div>' +
          '<div class="bulk-wizard-steps" id="bulk-wizard-steps" role="list" aria-label="Import steps">' +
            ['Upload File', 'Map Columns', 'Preview Results', 'Confirm Import', 'Import Summary'].map(function (label, idx) {
              return '<div class="bulk-wizard-step' + (idx === 0 ? ' active' : '') + '" data-step="' + (idx + 1) + '" role="listitem" aria-current="' + (idx === 0 ? 'step' : 'false') + '">' +
                '<span class="bulk-wizard-step-no">' + (idx + 1) + '</span>' +
                '<span class="bulk-wizard-step-label">' + label + '</span>' +
              '</div>';
            }).join('') +
          '</div></div>' +
        '<div id="bulk-wizard-panel-1" class="bulk-wizard-panel">' +
          '<div id="bulk-upload-zone" class="upload-zone"><input type="file" id="bulk-import-file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="hidden" aria-hidden="true" />' +
            '<i data-lucide="upload" class="h-10 w-10 text-brand mx-auto mb-2"></i>' +
            '<p class="text-body font-medium">Drop CSV / Excel here or click to browse</p>' +
            '<p class="text-caption text-slate-500 mt-1">Supported: .csv, .xlsx · Max 10,000 rows · UTF-8</p></div>' +
          '<div id="bulk-upload-meta" class="hidden mt-4 grid sm:grid-cols-3 gap-3">' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">File Name</p><p id="bulk-file-name" class="text-body font-medium text-slate-900 truncate">—</p></div>' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Total Rows</p><p id="bulk-file-rows" class="text-body font-medium text-slate-900">—</p></div>' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">File Size</p><p id="bulk-file-size" class="text-body font-medium text-slate-900">—</p></div>' +
          '</div>' +
          '<div class="flex flex-wrap gap-2 mt-4 items-center">' +
            actSecondary('Re-upload', 'id="bulk-reupload-btn"', 'refresh-cw') +
            actSecondary('Sample CSV', 'href="/ca-masters/bulk-import/sample.csv" download', 'download') +
            actSecondary('Sample Excel', 'href="/ca-masters/bulk-import/sample.xlsx" download', 'file-spreadsheet') +
          '</div>' +
        '</div>' +
        '<div id="bulk-wizard-panel-2" class="bulk-wizard-panel hidden">' +
          '<p class="text-caption text-slate-500 mb-3">Map Excel columns to CRM fields. Only Firm Name is required. CA Name, Mobile Number, and Email are optional.</p>' +
          '<div class="flex flex-wrap gap-3 items-end mb-4">' +
            '<div class="min-w-[12rem]"><label class="form-label">Saved Mapping Template</label><select id="bulk-mapping-template-select" class="input-field"><option value="">Auto-detect mapping</option></select></div>' +
            '<div class="min-w-[12rem]"><label class="form-label">Save As Template</label><input type="text" id="bulk-mapping-template-name" class="input-field" placeholder="e.g. Master Data Default" /></div>' +
          '</div>' +
          '<div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full"><thead><tr><th>CRM Field</th><th>Required</th><th>Excel Column</th></tr></thead><tbody id="bulk-mapping-table"></tbody></table></div>' +
        '</div>' +
        '<div id="bulk-wizard-panel-3" class="bulk-wizard-panel hidden">' +
          '<div id="bulk-validation-progress" class="hidden mb-4" role="status" aria-live="polite">' +
            '<div class="flex items-center justify-between mb-1"><span class="text-caption text-slate-500">Validation progress</span><span id="bulk-validation-progress-label" class="text-caption text-slate-600">Preparing…</span></div>' +
            '<div class="ca-progress"><div id="bulk-validation-progress-bar" class="ca-progress-bar" style="width:0%"></div></div>' +
            '<div id="bulk-validation-progress-steps" class="text-caption text-slate-500 mt-2">Upload received</div>' +
          '</div>' +
          '<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4" id="bulk-preview-summary">' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Total Rows</p><p id="bulk-total-count" class="text-stat-number text-slate-900">0</p></div>' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Valid Rows</p><p id="bulk-valid-count" class="text-stat-number text-emerald-600">0</p></div>' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Duplicate Rows</p><p id="bulk-duplicate-count" class="text-stat-number text-amber-600">0</p></div>' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Invalid Rows</p><p id="bulk-invalid-count" class="text-stat-number text-rose-600">0</p></div>' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Missing Mobile</p><p id="bulk-missing-mobile-count" class="text-stat-number text-slate-700">0</p></div>' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Missing Email</p><p id="bulk-missing-email-count" class="text-stat-number text-slate-700">0</p></div>' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Landline Rows</p><p id="bulk-landline-count" class="text-stat-number text-sky-700">0</p></div>' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Rows Ready To Import</p><p id="bulk-ready-count" class="text-stat-number text-brand">0</p></div>' +
          '</div>' +
          '<div class="overflow-x-auto scrollbar-thin max-h-[420px]"><table class="ca-table w-full"><thead><tr><th>Row</th><th>Status</th><th>CA Name</th><th>Firm</th><th>Mobile</th><th>Email</th><th>GST</th><th>State</th><th>City</th><th>Issues</th></tr></thead><tbody id="bulk-validation-table"></tbody></table></div>' +
          '<div class="flex flex-wrap gap-2 mt-4 items-center hidden" id="bulk-validation-downloads">' +
            actSecondary('Download Error Report', 'id="bulk-download-validation-errors"', 'download') +
            actSecondary('Download Failed Rows for Re-import', 'id="bulk-download-validation-reimport"', 'file-up') +
          '</div>' +
        '</div>' +
        '<div id="bulk-wizard-panel-4" class="bulk-wizard-panel hidden">' +
          '<p class="text-caption text-slate-500 mb-3">Review duplicates before import. Blank mobile/email rows can still import. Duplicates are skipped unless Super Admin chooses Import Anyway, Merge, or Replace.</p>' +
          '<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4" id="bulk-confirm-summary">' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Rows Ready To Import</p><p id="bulk-confirm-ready-count" class="text-stat-number text-brand">0</p></div>' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Duplicate Rows</p><p id="bulk-confirm-duplicate-count" class="text-stat-number text-amber-600">0</p></div>' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Missing Mobile</p><p id="bulk-confirm-missing-mobile-count" class="text-stat-number text-slate-700">0</p></div>' +
            '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Missing Email</p><p id="bulk-confirm-missing-email-count" class="text-stat-number text-slate-700">0</p></div>' +
          '</div>' +
          '<p id="bulk-duplicate-report-note" class="text-caption text-slate-500 mb-3 hidden"></p>' +
          '<div id="bulk-import-progress-wrap" class="hidden mb-4">' +
            '<div class="flex items-center justify-between mb-1"><span class="text-caption text-slate-500">Import progress</span><span id="bulk-import-progress-label" class="text-caption text-slate-600">0%</span></div>' +
            '<div class="ca-progress"><div id="bulk-import-progress-bar" class="ca-progress-bar" style="width:0%"></div></div>' +
          '</div>' +
          '<div class="overflow-x-auto scrollbar-thin max-h-[420px]"><table class="ca-table w-full"><thead><tr><th>Row</th><th>CA Name</th><th>Firm Name</th><th>Mobile</th><th>Email</th><th>Duplicate Type</th><th>Matched Existing Lead</th><th>Action</th></tr></thead><tbody id="bulk-duplicate-actions-table"></tbody></table></div>' +
          '<p id="bulk-duplicate-actions-empty" class="text-caption text-slate-500 mt-3 hidden">No duplicates detected. Ready rows will be imported.</p>' +
        '</div>' +
        '<div id="bulk-wizard-panel-5" class="bulk-wizard-panel hidden">' +
          '<div id="bulk-import-summary" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3"></div>' +
          '<div class="flex flex-wrap gap-2 mt-4 hidden" id="bulk-import-summary-downloads">' +
            actSecondary('Download Error Report', 'id="bulk-download-import-errors"', 'download') +
            actSecondary('Download Failed Rows for Re-import', 'id="bulk-download-import-reimport"', 'file-up') +
            actPrimary('Re-upload Corrected File', 'id="bulk-start-reimport-btn"', 'upload') +
          '</div>' +
        '</div>' +
        '<div class="flex flex-wrap justify-between gap-2 mt-6 pt-4 border-t border-slate-100">' +
          '<button type="button" class="btn-secondary" id="bulk-wizard-back-btn" disabled>Back</button>' +
          '<div class="flex gap-2">' +
            '<button type="button" class="btn-secondary" id="bulk-wizard-cancel-btn">Cancel</button>' +
            '<button type="button" class="btn-primary" id="bulk-wizard-next-btn" disabled>Next</button>' +
            '<button type="button" class="btn-primary hidden" id="bulk-wizard-import-btn"><i data-lucide="database" class="h-4 w-4"></i> Confirm Import</button>' +
          '</div></div></div>' +
      '<div id="bulk-assignment-panel" class="card p-5 mb-6 hidden bulk-assign-panel">' +
        '<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-5">' +
          '<h2 class="text-section-heading flex items-center gap-2"><i data-lucide="user-check" class="h-5 w-5 text-brand" aria-hidden="true"></i> Bulk Assignment</h2>' +
          '<div class="flex flex-wrap items-center gap-2">' +
            '<span id="bulk-assign-selected-count" class="text-caption text-slate-500 hidden">0 leads selected</span>' +
            '<span id="bulk-assign-employee-count" class="text-caption text-slate-500 hidden">· 0 employees</span>' +
          '</div>' +
        '</div>' +
        '<div id="bulk-assign-selection-summary" class="bulk-assign-selection-summary flex flex-wrap items-center gap-3 mb-4 px-4 py-3 rounded-lg bg-slate-50 border border-slate-200">' +
          '<span id="bulk-assign-summary-batch" class="text-body font-medium text-slate-700">Selected Batch: <strong>None</strong></span>' +
          '<span class="text-slate-300 hidden sm:inline">|</span>' +
          '<span id="bulk-assign-summary-leads" class="text-body font-medium text-slate-700">Leads to Assign: <strong>0</strong></span>' +
          '<span class="text-slate-300 hidden sm:inline">|</span>' +
          '<span id="bulk-assign-summary-employees" class="text-body font-medium text-slate-700">Selected Employees: <strong>0</strong></span>' +
        '</div>' +
        '<div class="grid xl:grid-cols-2 gap-4 mb-4">' +
          '<div class="bulk-assign-card">' +
            '<div class="bulk-assign-card-head">' +
              '<h4 class="bulk-assign-card-title">Available Lead Batches</h4>' +
              '<div class="flex flex-wrap gap-2">' +
                actSecondary('Reset Filters', 'id="bulk-assign-filters-reset"', 'rotate-ccw') +
                actSecondary('Clear Selection', 'id="bulk-assign-batch-clear"', 'x') +
              '</div>' +
            '</div>' +
            '<div class="grid sm:grid-cols-3 gap-2 mb-3">' +
              '<div class="sc-location-pair sm:col-span-2 grid sm:grid-cols-2 gap-2">' +
                '<select class="input-field" id="bulk-assign-batch-state" name="state_id" data-sc-role="state"><option value="">Any State</option></select>' +
                '<select class="input-field" id="bulk-assign-batch-city" name="city_id" data-sc-role="city"><option value="">Any City</option></select>' +
              '</div>' +
              '<select class="input-field" id="bulk-assign-batch-source"><option value="">Any Source</option></select>' +
            '</div>' +
            '<div class="mb-3"><select class="input-field" id="bulk-assign-batch-assignment"><option value="">All Leads</option><option value="unassigned">Unassigned Only</option><option value="assigned">Assigned Only</option></select></div>' +
            '<div id="bulk-assign-batches-list" class="bulk-assign-scroll-list"><div class="bulk-assign-skeleton">Loading import batches…</div></div>' +
            '<div class="crm-table-footer bulk-assign-pagination" id="bulk-assign-batches-pagination"></div>' +
          '</div>' +
          '<div class="bulk-assign-card">' +
            '<div class="bulk-assign-card-head">' +
              '<h4 class="bulk-assign-card-title">Available Employees</h4>' +
              '<div class="flex flex-wrap gap-2">' +
                '<span class="text-caption text-slate-500">Click to toggle · multi-select</span>' +
                actSecondary('Clear', 'id="bulk-assign-employees-clear"', 'x') +
              '</div>' +
            '</div>' +
            '<div class="mb-3"><input type="search" class="input-field" id="bulk-assign-employee-search" placeholder="Search employee name, email, role…" autocomplete="off" /></div>' +
            '<div id="bulk-assign-employees-list" class="bulk-assign-scroll-list"><div class="bulk-assign-skeleton">Loading employees…</div></div>' +
            '<div class="crm-table-footer bulk-assign-pagination" id="bulk-assign-employees-pagination"></div>' +
          '</div>' +
        '</div>' +
        '<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">' +
          '<div><label class="form-label">Assignment Mode</label><select class="input-field" id="bulk-assign-mode">' +
            '<option value="round_robin">Round Robin — distribute among selected employees</option>' +
            '<option value="workload_balance">Workload Balance — fewer active leads first</option>' +
            '<option value="city_match">City Match — match lead city, then balance load</option>' +
            '<option value="state_match">State Match — match lead state, then balance load</option>' +
            '<option value="manual">Manual — one employee for all leads</option>' +
          '</select></div>' +
          '<div><label class="form-label">Reason</label><select class="input-field" id="bulk-assign-reason">' +
            '<option value="MANUAL_ASSIGN">Manual Assignment</option>' +
            '<option value="ROUND_ROBIN">Round Robin</option>' +
            '<option value="WORKLOAD_BALANCE">Workload Balance</option>' +
            '<option value="CITY_MATCH">City Match</option>' +
            '<option value="STATE_MATCH">State Match</option>' +
          '</select></div>' +
          '<div class="flex items-end gap-2 flex-wrap">' +
            actSecondary('Preview', 'id="bulk-assign-preview-btn" disabled', 'eye') +
            actPrimary('Confirm Assignment', 'id="bulk-assign-confirm-btn" disabled', 'check') +
          '</div>' +
        '</div>' +
        '<div id="bulk-assign-preview-wrap" class="hidden">' +
          '<h4 class="text-body font-semibold mb-2">Assignment Preview <span class="text-caption text-slate-500">(not saved)</span></h4>' +
          '<div class="overflow-x-auto scrollbar-thin max-h-[360px]"><table class="ca-table w-full bulk-assign-preview-table"><thead><tr><th>Lead</th><th>Current Owner</th><th>New Owner</th><th>Mode</th><th>Reason</th><th>Status</th></tr></thead><tbody id="bulk-assignment-preview-table"><tr><td colspan="6" class="text-center text-slate-500 p-4">Run preview to see planned assignments</td></tr></tbody></table></div>' +
        '</div>' +
        '<div id="bulk-assign-loading" class="bulk-assign-loading hidden"><div class="bulk-assign-spinner"></div><p>Processing assignment…</p></div>' +
        '<div id="bulk-assign-confirm-modal" class="ca-modal" role="dialog" aria-modal="true" aria-hidden="true">' +
          '<div class="ca-modal-backdrop" data-close-bulk-assign-modal></div>' +
          '<div class="ca-modal-panel max-w-md">' +
            '<h3 class="text-card-heading mb-2">Confirm Assignment</h3>' +
            '<p id="bulk-assign-confirm-text" class="text-body text-slate-600 mb-4">Assign selected leads?</p>' +
            '<div class="flex justify-end gap-2">' +
              '<button type="button" class="btn-secondary" data-close-bulk-assign-modal>Cancel</button>' +
              '<button type="button" class="btn-primary" id="bulk-assign-confirm-yes">Yes, Assign</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>' +
      '<div id="bulk-export-panel" class="card p-5 mb-6 hidden">' +
        '<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-5">' +
          '<div class="shrink-0"><h2 class="text-section-heading flex items-center gap-2"><i data-lucide="download" class="h-5 w-5 text-brand" aria-hidden="true"></i> Bulk Export</h2></div>' +
        '</div>' +
        '<div class="grid lg:grid-cols-2 gap-4 mb-4">' +
          '<div><label class="form-label">Export Scope</label>' +
            '<select class="input-field" id="bulk-export-scope">' +
              '<option value="all">All records</option>' +
              '<option value="filtered">Filtered records</option>' +
              '<option value="selected">Selected records</option>' +
            '</select></div>' +
          '<div><label class="form-label">File Format</label>' +
            '<select class="input-field" id="bulk-export-format">' +
              '<option value="csv">CSV (.csv)</option>' +
              '<option value="xlsx">Excel (.xlsx)</option>' +
            '</select></div>' +
        '</div>' +
        '<div id="bulk-export-selected-wrap" class="hidden mb-4">' +
          '<label class="form-label">Select Firms</label>' +
          '<select multiple class="input-field min-h-[160px]" id="bulk-export-selected-ids" size="8"></select>' +
          '<p class="text-caption text-slate-500 mt-1">Hold Ctrl/Cmd to select multiple firms</p>' +
        '</div>' +
        '<div id="bulk-export-filters-wrap" class="hidden grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">' +
          '<div><label class="form-label">Status</label><select class="input-field" id="bulk-export-filter-status"><option value="">Any</option><option>Hot</option><option>Warm</option><option>Cold</option><option>Active</option><option>Inactive</option></select></div>' +
          '<div class="sc-location-pair sm:col-span-2 grid sm:grid-cols-2 gap-4">' +
            '<div><label class="form-label">State</label><select class="input-field" id="bulk-export-filter-state" name="state_id"><option value="">Any</option></select></div>' +
            '<div><label class="form-label">City</label><select class="input-field" id="bulk-export-filter-city" name="city_id" disabled><option value="">Any</option></select></div>' +
          '</div>' +
          '<div><label class="form-label">Source</label><select class="input-field" id="bulk-export-filter-source"><option value="">Any</option></select></div>' +
          '<div><label class="form-label">New Firm</label><select class="input-field" id="bulk-export-filter-new"><option value="">Any</option><option value="true">Yes</option><option value="false">No</option></select></div>' +
          '<div><label class="form-label">Search</label><input type="text" class="input-field" id="bulk-export-filter-search" placeholder="Firm, CA, mobile, email, GST" /></div>' +
        '</div>' +
        '<div class="mb-4"><label class="form-label">Columns</label><div id="bulk-export-columns" class="flex flex-wrap gap-2"></div></div>' +
        '<div id="bulk-export-preview-meta" class="hidden grid sm:grid-cols-3 gap-3 mb-4">' +
          '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Matching Rows</p><p id="bulk-export-preview-count" class="text-stat-number text-slate-900">0</p></div>' +
          '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Background Job</p><p id="bulk-export-preview-bg" class="text-body font-medium text-slate-900">—</p></div>' +
          '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Format</p><p id="bulk-export-preview-format" class="text-body font-medium text-slate-900">—</p></div>' +
        '</div>' +
        '<div id="bulk-export-progress-wrap" class="hidden mb-4">' +
          '<div class="flex items-center justify-between mb-2"><p class="text-caption text-slate-500">Export progress</p><p id="bulk-export-progress-label" class="text-caption font-medium text-slate-700">0%</p></div>' +
          '<div class="bulk-export-progress"><div id="bulk-export-progress-bar" class="bulk-export-progress-bar" style="width:0%"></div></div>' +
        '</div>' +
        '<div class="flex flex-wrap gap-2">' +
          actSecondary('Preview Count', 'id="bulk-export-preview-btn"', 'eye') +
          actPrimary('Start Export', 'id="bulk-export-run-btn"', 'download') +
          actSecondary('Download File', 'id="bulk-export-download-btn" class="hidden"', 'file-down') +
        '</div>' +
      '</div>' +
      '<div id="bulk-status-update-panel" class="card p-5 mb-6 hidden">' +
        '<h2 class="text-section-heading mb-4 flex items-center gap-2"><i data-lucide="refresh-cw" class="h-5 w-5 text-brand" aria-hidden="true"></i> Bulk Status Update</h2>' +
        '<div class="grid lg:grid-cols-2 gap-4 mb-4">' +
          '<div><label class="form-label">Select Records</label><select multiple class="input-field min-h-[160px]" id="bulk-status-leads" size="8"></select><p class="text-caption text-slate-500 mt-1">Hold Ctrl/Cmd to select multiple firms</p></div>' +
          '<div><label class="form-label">New Status</label><select class="input-field" id="bulk-status-target"><option value="">Choose status…</option></select>' +
            '<p class="text-caption text-slate-500 mt-2">All selected records will be updated to this status in a single transaction.</p></div>' +
        '</div>' +
        '<div id="bulk-status-preview-meta" class="hidden grid sm:grid-cols-3 gap-3 mb-4">' +
          '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Will Update</p><p id="bulk-status-preview-update" class="text-stat-number text-emerald-600">0</p></div>' +
          '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Already at Status</p><p id="bulk-status-preview-skip" class="text-stat-number text-amber-600">0</p></div>' +
          '<div class="card p-4 crm-metric-card"><p class="text-caption text-slate-500">Target Status</p><p id="bulk-status-preview-target" class="text-body font-medium text-slate-900">—</p></div>' +
        '</div>' +
        '<div class="flex flex-wrap gap-2 mb-4 items-center">' +
          actSecondary('Preview Changes', 'id="bulk-status-preview-btn"', 'eye') +
          actPrimary('Apply Status Update', 'id="bulk-status-apply-btn" disabled', 'check') +
        '</div>' +
        '<div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full"><thead><tr><th>Firm</th><th>CA Name</th><th>Current Status</th><th>New Status</th><th>Result</th></tr></thead><tbody id="bulk-status-preview-table"><tr><td colspan="5" class="text-center text-slate-500 p-4">Preview changes before applying</td></tr></tbody></table></div>' +
      '</div>' +
      '<div id="modal-bulk-status-confirm" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-status-confirm-title">' +
        '<div class="ca-modal-panel ca-modal-panel-md">' +
          '<div class="ca-modal-header">' +
            '<h3 id="bulk-status-confirm-title" class="ca-modal-title"><span class="ca-modal-icon"><i data-lucide="alert-triangle" class="h-5 w-5"></i></span> Confirm Status Update</h3>' +
            '<button type="button" class="ca-modal-close" data-close-bulk-status-confirm aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>' +
          '</div>' +
          '<div class="ca-modal-body space-y-3" id="bulk-status-confirm-body"></div>' +
          '<div class="ca-modal-footer">' +
            '<div class="ca-modal-footer-buttons">' +
            '<button type="button" class="btn-secondary" data-close-bulk-status-confirm>Cancel</button>' +
            '<button type="button" class="btn-primary" id="bulk-status-confirm-btn"><i data-lucide="check" class="h-4 w-4"></i> Confirm Update</button>' +
            '</div>' +
          '</div></div></div>' +
      table(['', 'Reference', 'Type', 'File', 'Total', 'Success', 'Failed', 'Status', 'Performed By', 'Created', ''], [], { tbodyId: 'bulk-actions-data-table', tableId: 'bulk-actions-table', paginationId: 'bulk-operations-pagination-slot' });
  }

  /* ─── Leads (unified hub — same layout pattern as Master Data) ─── */
  function leadsPage() {
    var actions = ['Move to Demo Tab', 'Details Shared', 'Negotiation', 'Not Interested', 'Pipeline', 'Mark Inactive'];
    return '<div class="leads-hub cam-hub--compact" id="leads-hub">' +
      '<div class="cam-hub-top">' +
        '<div class="cam-hub-title-row">' +
          '<h1 class="text-page-title cam-hub-title">Lead Management</h1>' +
        '</div>' +
        '<div class="cam-control-row card" id="leads-kpi-strip" role="tablist" aria-label="Lead views"></div>' +
      '</div>' +
      '<div class="cam-primary-views" id="leads-primary-views">' +
        '<div class="ca-tab-panel" data-panel="pipeline" data-tab-group="leads-view">' +
          '<div class="card cam-pipeline-card overflow-x-auto scrollbar-thin"><div id="kanban-board" class="flex gap-3 min-w-max pb-2"></div></div>' +
        '</div>' +
        '<div class="ca-tab-panel active" data-panel="all" data-tab-group="leads-view">' +
          leadsEnterpriseTable() +
        '</div>' +
      '</div>' +
      '<div id="leads-selected-bar" class="leads-selected-bar hidden" aria-live="polite">' +
        '<div class="leads-selected-info">' +
          '<span class="leads-selected-label">Selected lead</span>' +
          '<strong id="leads-selected-name">—</strong>' +
          '<span id="leads-selected-meta" class="leads-selected-meta">—</span>' +
        '</div>' +
        '<div class="leads-selected-actions" id="lead-actions">' +
          actions.map(function (a) {
            return '<button type="button" class="ca-chip ca-chip-action" data-lead-action="' + a + '">' + a + '</button>';
          }).join('') +
          '<button type="button" class="ca-chip" data-open-modal="assign-lead"><i data-lucide="user-check" class="h-3 w-3"></i> Assign</button>' +
          '<button type="button" class="ca-chip" data-open-modal="followup"><i data-lucide="calendar" class="h-3 w-3"></i> Follow-up</button>' +
          '<button type="button" class="ca-chip" id="leads-clear-selection"><i data-lucide="x" class="h-3 w-3"></i> Clear</button>' +
        '</div></div>' +
      '<p id="lead-action-toast" class="text-caption text-brand-600 mt-2 hidden"></p>' +
    '</div>';
  }

  /* ─── Assignment + Team ─── */
  function assignmentDashboardWidgets() {
    return '<section class="assign-widgets-grid mb-6" id="assign-dashboard-widgets">' +
      '<div class="card assign-widget assign-widget--heatmap" id="assign-heatmap-widget">' +
        '<div class="assign-widget__head">' +
          '<div><h3 class="text-card-heading">Assignment Heat Map</h3>' +
          '<p class="assign-section__subtitle">Lead distribution by city for selected period.</p></div>' +
        '</div>' +
        '<div class="assign-heatmap-toolbar">' +
          '<select class="input-field input-field-sm" id="assign-heatmap-period" aria-label="Period">' +
            '<option value="today">Today</option>' +
            '<option value="this_week">This Week</option>' +
            '<option value="this_month">This Month</option>' +
            '<option value="custom">Custom Date Range</option>' +
          '</select>' +
          '<select class="input-field input-field-sm" id="assign-heatmap-sort" aria-label="Sort">' +
            '<option value="highest">Highest Assigned</option>' +
            '<option value="lowest">Lowest Assigned</option>' +
          '</select>' +
          '<select class="input-field input-field-sm" id="assign-heatmap-employee" aria-label="Employee"><option value="">All Employees</option></select>' +
          '<select class="input-field input-field-sm" id="assign-heatmap-state" aria-label="State"><option value="">All States</option></select>' +
          '<select class="input-field input-field-sm" id="assign-heatmap-source" aria-label="Source"><option value="">All Sources</option></select>' +
        '</div>' +
        '<div class="assign-heatmap-custom hidden" id="assign-heatmap-custom-range">' +
          '<input type="date" class="input-field input-field-sm" id="assign-heatmap-from" aria-label="From date" />' +
          '<span class="assign-heatmap-custom__sep">to</span>' +
          '<input type="date" class="input-field input-field-sm" id="assign-heatmap-to" aria-label="To date" />' +
        '</div>' +
        '<div class="assign-heatmap-summary" id="assign-heatmap-summary"></div>' +
        '<div class="assign-heatmap-list" id="assign-heatmap-list">' +
          '<p class="assign-widget__empty">Loading heat map…</p>' +
        '</div>' +
      '</div>' +
    '</section>';
  }

  function assignmentYearlyTargetsSection() {
    var year = new Date().getFullYear();
    return '<section class="assign-section card mb-6 assign-daily-targets-card" id="assign-yearly-targets-section">' +
      '<div class="assign-daily-targets-head">' +
        '<div><h3 class="text-card-heading">Yearly Employee Targets</h3></div>' +
        '<div class="assign-daily-targets-actions" id="assign-yearly-targets-actions">' +
          '<button type="button" class="btn-primary btn-sm hidden" id="assign-yearly-target-open-modal"><i data-lucide="target" class="h-3.5 w-3.5"></i> Assign Yearly Target</button>' +
        '</div>' +
      '</div>' +
      '<div class="assign-daily-targets-summary" id="assign-yearly-targets-summary"></div>' +
      '<div class="assign-daily-targets-toolbar">' +
        '<select class="input-field input-field-sm" id="assign-yearly-target-year" aria-label="Target year">' +
          '<option value="' + year + '">' + year + '</option>' +
          '<option value="' + (year + 1) + '">' + (year + 1) + '</option>' +
          '<option value="' + (year - 1) + '">' + (year - 1) + '</option>' +
        '</select>' +
        '<select class="input-field input-field-sm" id="assign-yearly-target-employee-filter" aria-label="Employee"><option value="">All Employees</option></select>' +
        '<select class="input-field input-field-sm" id="assign-yearly-target-status-filter" aria-label="Status">' +
          '<option value="">All Status</option>' +
          '<option value="not_started">Not Started</option>' +
          '<option value="in_progress">In Progress</option>' +
          '<option value="completed">Completed</option>' +
          '<option value="exceeded">Exceeded</option>' +
          '<option value="missed">Missed</option>' +
          '<option value="no_target">No Target</option>' +
        '</select>' +
      '</div>' +
      '<div class="crm-table-container scrollbar-thin assign-daily-targets-table-wrap">' +
        '<table class="ca-table w-full assign-daily-targets-table assign-daily-targets-table--compact">' +
          '<thead><tr>' +
            '<th>Employee</th><th>Year</th><th>Target Working Days</th><th>Leads/day</th><th>Calls/day</th><th>Demos/day</th><th>Follow-ups/day</th>' +
            '<th>YTD Progress</th><th>Status</th><th></th>' +
          '</tr></thead>' +
          '<tbody id="assign-yearly-targets-table"><tr><td colspan="10" class="text-center text-slate-500 py-4 text-sm">Loading…</td></tr></tbody>' +
        '</table>' +
      '</div>' +
    '</section>';
  }

  function assignmentRotationCard() {
    var rules = [
      {
        id: 'assign-rotation-round-robin',
        label: 'Round Robin',
        help: 'Assigns each new lead sequentially to available employees for equal distribution.',
        tooltip: 'Each new lead is assigned to the next employee in rotation so everyone receives an equal number of leads.',
        checked: true,
      },
      {
        id: 'assign-rotation-workload',
        label: 'Workload Balance',
        help: 'Prioritizes employees with fewer active leads to balance the team\'s workload.',
        tooltip: 'The system checks every employee\'s current workload before assigning the next lead.',
        checked: true,
      },
      {
        id: 'assign-rotation-priority',
        label: 'Priority Score',
        help: 'Assigns leads based on employee performance, success rate, and priority score.',
        tooltip: 'Employees with higher performance scores receive higher-value leads first.',
        checked: true,
      },
      {
        id: 'assign-rotation-city',
        label: 'City Match',
        help: 'Automatically assigns leads to employees responsible for the lead\'s city or region.',
        tooltip: 'Leads are assigned according to predefined city or regional ownership.',
        checked: false,
      },
      {
        id: 'assign-rotation-hot',
        label: 'Hot Lead First',
        help: 'Ensures high-priority leads are assigned immediately to the best available employee.',
        tooltip: 'Critical leads bypass normal assignment rules and are immediately assigned.',
        checked: false,
      },
    ];
    return '<section class="assign-section card mb-6" id="assign-rotation-section">' +
      '<div class="assign-section__head assign-section__head--rotation">' +
        '<div>' +
          '<h3 class="text-card-heading">Automatic Lead Assignment Rules</h3>' +
          '<p class="assign-section__subtitle">Configure how new leads are automatically distributed among employees.</p>' +
        '</div>' +
      '</div>' +
      '<div class="assign-rotation-grid">' +
        rules.map(function (rule) {
          return '<div class="assign-rotation-item">' +
            '<div class="assign-rotation-item__text">' +
              '<div class="assign-rotation-item__label-row">' +
                '<label class="assign-rotation-item__label" for="' + rule.id + '">' + rule.label + '</label>' +
                '<span class="assign-rotation-info" tabindex="0" role="button" aria-label="' + rule.label + ' — more information" onclick="event.preventDefault(); event.stopPropagation();">' +
                  '<i data-lucide="info" class="assign-rotation-info__icon" aria-hidden="true"></i>' +
                  '<span class="assign-rotation-tooltip" role="tooltip">' + rule.tooltip + '</span>' +
                '</span>' +
              '</div>' +
              '<p class="assign-rotation-item__help">' + rule.help + '</p>' +
            '</div>' +
            '<label class="ca-toggle shrink-0 assign-rotation-item__toggle" aria-label="Toggle ' + rule.label + '">' +
              '<input type="checkbox" id="' + rule.id + '"' + (rule.checked ? ' checked' : '') + '>' +
              '<span class="ca-toggle-slider"></span>' +
            '</label>' +
          '</div>';
        }).join('') +
      '</div></section>';
  }

  function activeAssignmentsSection() {
    return '<section class="assign-active card mb-6 crm-table-card--col-filters" id="assign-active-section" data-listing-toolbar="lead_assignments">' +
      '<div class="assign-active__header">' +
        '<div class="assign-active__header-main">' +
          '<h3 class="text-card-heading">Active Assignments</h3>' +
          '<p class="assign-section__subtitle">Current lead assignments across employees.</p>' +
          '<p class="assign-active__count" id="assignment-total-label">Showing: — Assignments</p>' +
        '</div>' +
        actPrimary('New Assignment', 'class="assign-active__new" data-open-modal="assign-lead"', 'user-plus') +
      '</div>' +
      '<div class="crm-inbox-bulk-bar assign-bulk-bar hidden" id="assignment-bulk-bar" data-inbox-module="assignment" aria-live="polite">' +
        '<span class="crm-inbox-bulk-count assign-bulk-bar__label" id="assignment-bulk-count">0 selected</span>' +
        '<div class="crm-inbox-bulk-toolbar" role="toolbar" aria-label="Bulk actions">' +
          inboxBulkActionsForModule('assignment') +
        '</div>' +
      '</div>' +
      '<div class="assign-active__table-wrap table-scroll-container crm-table-container scrollbar-thin" id="assignment-table-wrap">' +
        '<table class="assign-active-table w-full" id="assignment-table">' +
          '<colgroup>' +
            '<col class="assign-col-check" />' +
            '<col class="assign-col-lead" />' +
            '<col class="assign-col-exec" />' +
            '<col class="assign-col-num" />' +
            '<col class="assign-col-num" />' +
            '<col class="assign-col-status" />' +
            '<col class="assign-col-date" />' +
            '<col class="assign-col-more" />' +
          '</colgroup>' +
          '<thead><tr>' +
            '<th class="assign-col-check sticky-left" scope="col"><input type="checkbox" id="assignment-select-all" aria-label="Select all on page" /></th>' +
            '<th class="assign-col-lead sticky-left-2" scope="col">Lead</th>' +
            '<th class="assign-col-exec" scope="col">Employee</th>' +
            '<th class="assign-col-num" scope="col">Target</th>' +
            '<th class="assign-col-num" scope="col">Achieved</th>' +
            '<th class="assign-col-status" scope="col">Status</th>' +
            '<th class="assign-col-date" scope="col">Assignment Date</th>' +
            '<th class="assign-col-more sticky-right" scope="col"><span class="sr-only">More</span></th>' +
          '</tr>' +
          '<tr class="crm-col-filter-row">' +
            '<th class="crm-col-filter-th assign-col-check sticky-left" scope="col"></th>' +
            '<th class="crm-col-filter-th assign-col-lead sticky-left-2" scope="col">' +
              '<input type="search" id="assignment-search" class="crm-col-filter-input" placeholder="search" aria-label="Filter Lead" autocomplete="off" />' +
            '</th>' +
            '<th class="crm-col-filter-th assign-col-exec" scope="col">' +
              '<select id="assignment-executive-filter" class="crm-col-filter-input crm-col-filter-select" data-crm-entity-lookup="employee" data-crm-lookup-compact="true" data-crm-lookup-empty-label="Employee" data-crm-lookup-placeholder="Search employee…" aria-label="Filter Employee">' +
                '<option value="">Employee</option>' +
              '</select>' +
            '</th>' +
            '<th class="crm-col-filter-th assign-col-num" scope="col"></th>' +
            '<th class="crm-col-filter-th assign-col-num" scope="col"></th>' +
            '<th class="crm-col-filter-th assign-col-status" scope="col">' +
              '<select id="assignment-status-filter" class="crm-col-filter-input crm-col-filter-select" aria-label="Filter Status">' +
                '<option value="">Status</option><option value="Active">Active</option><option value="Inactive">Inactive</option>' +
              '</select>' +
            '</th>' +
            '<th class="crm-col-filter-th assign-col-date" scope="col">' +
              '<select id="assignment-type-filter" class="crm-col-filter-input crm-col-filter-select" aria-label="Filter Assignment Type">' +
                '<option value="">Assignment Type</option><option value="Manual">Manual</option><option value="Auto">Auto</option>' +
              '</select>' +
            '</th>' +
            '<th class="crm-col-filter-th assign-col-more sticky-right" scope="col">' +
              actSecondary('Reset', 'id="assignment-filters-reset" class="crm-col-filter-reset"', 'rotate-ccw') +
            '</th>' +
          '</tr></thead>' +
          '<tbody id="assignment-data-table"></tbody>' +
        '</table>' +
      '</div>' +
      '<div class="assign-active__cards" id="assignment-mobile-cards" aria-label="Assignments list"></div>' +
      '<div class="crm-table-footer assign-active__footer" id="assignment-pagination-slot"></div>' +
    '</section>';
  }

  function assignmentPageHeroActions() {
    return pageHeroToolbar(
      actIcon('file-spreadsheet', 'Import Excel',
        'id="assignment-import-btn" data-inbox-action="import" data-inbox-module="ca-master"') +
      actIcon('download', 'Export', 'id="assignment-export-btn"') +
      actIcon('user-check', 'Assign Lead', 'data-open-modal="assign-lead"') +
      actIcon('user-plus', 'Add Employee', 'data-open-modal="add-employee"', 'primary')
    );
  }

  function assignmentPage(activeTab) {
    activeTab = activeTab || 'assign';
    var assignBody =
      '<div class="assign-page" id="assignment-page-root">' +
      kpis([
        { icon: 'user-cog', label: 'Active Assignments', value: '—', trend: 'Live', valueId: 'assign-kpi-active' },
        { icon: 'refresh-cw', label: 'Auto (Rotation)', value: '—', trend: 'Live', valueId: 'assign-kpi-auto' },
        { icon: 'user-plus', label: 'Manual', value: '—', trend: 'Live', valueId: 'assign-kpi-manual' },
        { icon: 'target', label: 'Assigned Leads', value: '—', trend: 'Live', valueId: 'assign-kpi-target' },
      ]) +
      assignmentDashboardWidgets() +
      assignmentYearlyTargetsSection() +
      assignmentRotationCard() +
      activeAssignmentsSection() +
      '<section class="assign-section card mb-6" data-listing-toolbar="assignment_histories">' +
        '<div class="assign-section__head">' +
          '<div><h3 class="text-card-heading">Assignment History</h3>' +
          '<p class="text-caption text-slate-500 mt-1">Reassignments and ownership changes over time.</p></div>' +
        '</div>' +
        table([
          { label: 'From', colCls: 'crm-col-person assign-col-person', thCls: 'crm-th-person' },
          { label: 'To', colCls: 'crm-col-person assign-col-person', thCls: 'crm-th-person' },
          { label: 'Lead', colCls: 'crm-col-firm assign-col-lead', thCls: 'crm-th-firm', filterType: 'search', filterId: 'assignment-history-search', filterPlaceholder: 'search' },
          { label: 'Reassigned By', colCls: 'crm-col-person assign-col-person', thCls: 'crm-th-person' },
          { label: 'Reason', colCls: 'crm-col-source assign-col-reason', thCls: 'crm-th-source' },
          { label: 'Date', colCls: 'crm-col-date assign-col-date', thCls: 'crm-th-date' },
        ], [], {
          tbodyId: 'assignment-history-table',
          tableId: 'assignment-history-table-el',
          wrapId: 'assignment-history-table-wrap',
          enterprise: true,
          cls: 'assign-table-card',
          paginationId: 'assignment-history-pagination-slot',
          columnFilters: true,
        }) +
      '</section></div>';

    var teamBody =
      '<div id="leaderboard" class="card p-5 mb-4"></div>' +
      table([
        { label: 'Name', colCls: 'crm-col-person', thCls: 'crm-th-person', sticky: 'left' },
        { label: 'Email', colCls: 'crm-col-person', thCls: 'crm-th-person' },
        { label: 'Mobile', colCls: 'crm-col-mobile', thCls: 'crm-th-mobile' },
        { label: 'Role', colCls: 'crm-col-source', thCls: 'crm-th-source' },
        { label: 'Login', colCls: 'crm-col-status', thCls: 'crm-th-status' },
        { label: 'City', colCls: 'crm-col-geo', thCls: 'crm-th-geo' },
        { label: 'Joined', colCls: 'crm-col-date', thCls: 'crm-th-date' },
        { label: 'Status', colCls: 'crm-col-status', thCls: 'crm-th-status' },
        { label: 'Actions', colCls: 'crm-col-actions', thCls: 'crm-th-actions', sticky: 'right' },
      ], [], { tbodyId: 'employees-data-table', tableId: 'employees-table', paginationId: 'employees-pagination-slot', enterprise: true, inbox: true, inboxKey: 'employees-data-table', inboxModule: 'employees' });

    return hdr('Assignment', null, null, assignmentPageHeroActions()) +
      tabs([{ id: 'assign', label: 'Assignments', icon: 'user-check' }, { id: 'team', label: 'Team', icon: 'users' }], activeTab, 'assign-hub') +
      panel('assign', activeTab === 'assign', assignBody, 'assign-hub') +
      panel('team', activeTab === 'team', teamBody, 'assign-hub');
  }

  /* ─── Follow Ups ─── */
  function followupsPage() {
    var types = ['Call Status', 'Demo Scheduled', 'Demo Completed', 'Demo History', 'Details Shared', 'Negotiation', 'Not Interested', 'Follow Up Reminder', 'Follow Up Scheduled'];
    var headerActions =
      pageHeroToolbar(
        actSecondary('Calendar', 'id="followup-cal-toggle" aria-expanded="false" aria-controls="followup-cal-popover"', 'calendar') +
        actPrimary('Schedule Follow-up', 'data-open-modal="followup" data-manager-schedule-followup', 'plus')
      ) +
      '<div class="followup-cal-popover hidden" id="followup-cal-popover" role="dialog" aria-label="Follow-up calendar">' +
        '<div class="followup-cal-popover__head">' +
          '<h3 class="text-card-heading followup-cal-popover__title"><i data-lucide="calendar" class="h-4 w-4 text-brand inline"></i> Calendar</h3>' +
          '<button type="button" class="crm-toolbar-icon-btn followup-cal-popover__close" id="followup-cal-close" title="Close" aria-label="Close calendar">' +
            '<i data-lucide="x" class="h-4 w-4"></i>' +
          '</button>' +
        '</div>' +
        '<div id="followup-calendar"></div>' +
      '</div>';

    return hdr('Follow-ups', null, null, headerActions) +
      kpis([
        { icon: 'phone', label: 'Due Today', value: '—', trend: 'Live', valueId: 'fu-kpi-due-today', filterKey: 'today', listing: 'follow_ups' },
        { icon: 'clock', label: 'Pending', value: '—', trend: 'Open', valueId: 'fu-kpi-pending', filterKey: 'pending', listing: 'follow_ups' },
        { icon: 'alert-triangle', label: 'Overdue', value: '—', trend: 'Alert', valueId: 'fu-kpi-overdue', filterKey: 'overdue', listing: 'follow_ups' },
        { icon: 'video', label: 'Completed', value: '—', trend: 'Done', valueId: 'fu-kpi-completed', filterKey: 'completed', listing: 'follow_ups' },
      ], { compact: true }) +
      '<div class="card p-4 lg:p-5 mb-6 followups-table-card">' +
        '<div class="followups-table-card__head">' +
          '<h2 class="text-section-heading">Follow-Up Types</h2>' +
        '</div>' +
        '<div class="flex flex-wrap gap-2 mb-3" id="followup-type-chips">' + types.map(function (t) {
          return '<button type="button" class="ca-chip" data-fu-type="' + t + '" aria-pressed="false">' + t + '</button>';
        }).join('') + '</div>' +
        '<div id="followups-main-table-wrap">' +
        table([
          { label: 'Type', colCls: 'crm-col-status', thCls: 'crm-th-status' },
          { label: 'Firm', colCls: 'crm-col-firm', thCls: 'crm-th-firm', sticky: 'left' },
          { label: 'Mobile Number', colCls: 'crm-col-mobile', thCls: 'crm-th-mobile' },
          { label: 'Employee', colCls: 'crm-col-person', thCls: 'crm-th-person' },
          { label: 'Remarks', colCls: 'crm-col-remarks', thCls: 'crm-th-remarks' },
          { label: 'Scheduled', colCls: 'crm-col-date', thCls: 'crm-th-date' },
          { label: 'Next Follow-up', colCls: 'crm-col-date', thCls: 'crm-th-date' },
          { label: 'Status', colCls: 'crm-col-status', thCls: 'crm-th-status' },
          { label: 'Actions', colCls: 'crm-col-actions-wide', thCls: 'crm-th-actions', sticky: 'right' },
        ], [], { tbodyId: 'followups-data-table', tableId: 'followups-table', wrapId: 'followups-table-wrap', enterprise: true, cls: 'crm-table-card--nested', paginationId: 'followups-pagination-slot', inbox: true, inboxKey: 'followups-data-table', inboxModule: 'followups' }) +
        '</div>' +
        '<div id="demo-history-panel" class="hidden mt-2">' +
          '<div class="flex items-center justify-between gap-3 mb-3">' +
            '<h3 class="text-card-heading">Demo History</h3>' +
            '<span class="text-caption text-slate-500" id="demo-history-count">0 records</span>' +
          '</div>' +
          '<div class="overflow-x-auto">' +
            '<table class="ca-table w-full"><thead><tr>' +
              '<th>Firm</th><th>Phone No</th><th>Result</th><th>Remarks</th><th>Employee</th><th>Demo Date</th><th>Completed</th><th>Actions</th>' +
            '</tr></thead><tbody id="demo-history-table"></tbody></table>' +
          '</div>' +
        '</div>' +
      '</div>' +
      '<div class="card p-4 lg:p-5 mb-6 followup-history-card" id="followup-activity-history-section">' +
        '<div class="followup-history-panel">' +
          '<div class="followup-history-panel__header">' +
            '<div class="min-w-0">' +
              '<div class="flex items-center gap-2 flex-wrap">' +
                '<h2 class="text-section-heading">Activity History</h2>' +
                '<span class="followup-history-count" id="followup-activity-count" aria-live="polite"></span>' +
              '</div>' +
              '<p class="text-caption text-slate-500 mt-1">Calls, follow-ups, demos, communication, and outcomes for your leads</p>' +
            '</div>' +
            '<div class="followup-history-panel__actions">' +
              '<button type="button" class="btn-secondary btn-sm" id="followup-timeline-sort" data-sort="desc" aria-label="Sort activity history newest first">' +
                '<i data-lucide="arrow-down-narrow-wide" class="h-4 w-4"></i> Newest first' +
              '</button>' +
              '<button type="button" class="crm-toolbar-icon-btn" id="followup-timeline-refresh" title="Refresh activity history" aria-label="Refresh activity history">' +
                '<i data-lucide="refresh-cw" class="h-4 w-4"></i>' +
              '</button>' +
            '</div>' +
          '</div>' +
          '<div class="followup-history-panel__filters-wrap">' +
            '<div class="followup-history-card__filters" role="group" aria-label="Activity period">' +
              '<button type="button" class="followup-history-filter is-active" data-followup-activity-period="all" aria-pressed="true">All</button>' +
              '<button type="button" class="followup-history-filter" data-followup-activity-period="today" aria-pressed="false">Today</button>' +
              '<button type="button" class="followup-history-filter" data-followup-activity-period="week" aria-pressed="false">This Week</button>' +
              '<button type="button" class="followup-history-filter" data-followup-activity-period="month" aria-pressed="false">This Month</button>' +
            '</div>' +
          '</div>' +
          '<div class="followup-history-panel__scroll" id="followup-activity-scroll" aria-live="polite">' +
            '<div id="followup-activity-timeline" class="followup-activity-timeline followup-activity-timeline--compact">' +
              '<p class="text-caption text-slate-400 py-2">Loading activity history…</p>' +
            '</div>' +
          '</div>' +
          '<div class="crm-table-footer followup-history-panel__footer" id="followup-activity-pagination-slot"></div>' +
        '</div>' +
      '</div>';
  }

  /* ─── Communication Hub ─── */
  var COMM_ASSETS = (window.__CRM_COMM_ASSETS__ || '/crm-ui/assets/communication/');
  if (COMM_ASSETS.charAt(COMM_ASSETS.length - 1) !== '/') {
    COMM_ASSETS += '/';
  }

  function communicationPage() {
    var cards = [
      { id: 'campaigns', label: 'Campaigns', page: 'campaigns', desc: 'Unified Email, SMS & WhatsApp campaigns', icon: 'megaphone' },
      { id: 'email', label: 'Email', page: 'email', desc: 'Bulk email & templates', icon: 'mail' },
      { id: 'sms', label: 'SMS', page: 'sms', desc: 'SMS campaigns & logs', icon: 'smartphone' },
      { id: 'notification', label: 'Notifications', page: 'notifications', desc: 'Alerts & push messages', icon: 'bell' },
      { id: 'chat', label: 'Chat', page: 'whatsapp', desc: 'WhatsApp & live chat', icon: 'messages-square' },
      { id: 'consent', label: 'Consent & DND', page: 'consent-dnd', desc: 'Consent tracking & opt-out', icon: 'shield-check' },
      { id: 'appointment', label: 'Appointments', page: 'followups', desc: 'Schedule & reminders', icon: 'calendar-check' },
    ];
    return hdr('Communication') +
      '<div class="comm-page">' +
      '<div class="comm-grid">' +
        cards.map(function (c, i) {
          var artSvg = COMM_ASSETS + c.id + '.svg';
          return '<button type="button" class="comm-card" data-comm-page="' + c.page + '" data-comm-label="' + c.label + '" aria-label="Open ' + c.label + '" style="--i:' + i + '">' +
            '<span class="comm-card-inner">' +
              '<span class="comm-card-badge"><i data-lucide="' + c.icon + '" class="h-4 w-4"></i></span>' +
              '<span class="comm-card-art">' +
                '<img class="comm-card-img" src="' + artSvg + '" alt="" loading="' + (i < 3 ? 'eager' : 'lazy') + '" width="160" height="128" decoding="async" draggable="false" onerror="this.onerror=null;this.style.display=\'none\';" />' +
              '</span>' +
              '<span class="comm-card-body">' +
                '<span class="comm-card-label">' + c.label + '</span>' +
                '<span class="comm-card-desc">' + c.desc + '</span>' +
              '</span>' +
              '<span class="comm-card-shine" aria-hidden="true"></span>' +
              '<span class="comm-card-overlay" aria-hidden="true"></span>' +
            '</span>' +
          '</button>';
        }).join('') +
      '</div>' +
      '<div class="comm-footer">' +
        '<div class="comm-footer-links">' +
          [
            { label: 'Lead Management', page: 'leads' },
            { label: 'Follow-ups', page: 'followups' },
            { label: 'Consent & DND', page: 'consent-dnd' },
            { label: 'Reports', page: 'reports' },
            { label: 'Activity Logs', page: 'activity' },
            { label: 'Settings', page: 'settings' },
          ].map(function (item) {
            return '<button type="button" class="comm-footer-link" data-comm-page="' + item.page + '">' + item.label + '</button>';
          }).join('') +
        '</div>' +
        '<p class="comm-footer-copy">Copyright © 2020 Law Seva Management Pvt. Ltd. All Rights reserved. Version: 4.2.32</p>' +
      '</div></div>';
  }

  function campaignsPage() {
    return hdr('Campaign Management', 'Unified dashboard for Email, SMS, and WhatsApp campaigns — stats, logs, retry, and export.', null,
      actPrimary('Email Campaign', 'data-open-modal="add-campaign" data-campaign-channel="email"', 'mail') +
      actPrimary('SMS Campaign', 'data-open-modal="add-campaign" data-campaign-channel="sms"', 'smartphone') +
      actPrimary('WhatsApp Campaign', 'data-open-modal="add-campaign" data-campaign-channel="whatsapp"', 'message-circle')) +
      listingFilterBar([
        { label: 'Search', id: 'unified-campaigns-search', type: 'search', placeholder: 'search', attrs: 'autocomplete="off"' },
        { label: 'Channel', id: 'unified-campaigns-channel', type: 'select', options: '<option value="">All Channels</option><option value="email">Email</option><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option>' },
        { label: 'Status', id: 'unified-campaigns-status', type: 'select', options: '<option value="">All Statuses</option><option>Draft</option><option>Scheduled</option><option>Processing</option><option>Completed</option><option>Partial</option><option>Failed</option><option>Cancelled</option><option>Paused</option>' },
        { label: 'Audience', id: 'unified-campaigns-audience', type: 'select', options: '<option value="">All Audiences</option><option value="all_leads">All Leads</option><option value="selected_leads">Selected Leads</option><option value="city">City</option><option value="state">State</option><option value="source">Source</option><option value="rating">Rating</option>' },
      ], actPrimary('Apply Filters', 'id="unified-campaigns-apply-filters"', 'filter'), { actionsInline: true }) +
      '<div id="unified-campaigns-grid" class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4 mb-4"></div>' +
      '<div id="unified-campaigns-pagination" class="crm-table-footer"></div>';
  }

  function smsPage() {
    return hdr('SMS', 'Send DLT-compliant SMS using approved templates and track delivery logs.', null,
      actExport('Export Logs') + actPrimary('New Campaign', 'data-open-modal="add-campaign" data-campaign-channel="sms" data-sms-campaign-create')) +
      kpis([
        { icon: 'smartphone', label: 'Total Campaigns', value: '—', trend: 'Live', valueId: 'sms-kpi-campaigns' },
        { icon: 'send', label: 'Sent', value: '—', trend: 'Live', valueId: 'sms-kpi-sent' },
        { icon: 'clock', label: 'Pending', value: '—', trend: 'Queue', valueId: 'sms-kpi-pending-logs' },
        { icon: 'alert-circle', label: 'Failed / API Error', value: '—', trend: 'Logs', valueId: 'sms-kpi-failed-logs' },
      ]) +
      '<div class="card p-4 mb-6" id="sms-templates-panel">' +
        '<div class="flex items-center justify-between gap-3 mb-4">' +
          '<div><h3 class="text-card-heading">DLT SMS Templates</h3><p class="text-caption text-slate-500">Manage approved templates with {#var#} placeholders.</p></div>' +
          actPrimary('Add Template', 'id="sms-template-add-btn" class="hidden"', 'plus') +
        '</div>' +
        '<div id="sms-template-form-wrap" class="hidden rounded-lg border border-slate-200 bg-slate-50 p-4 mb-4 space-y-3">' +
          '<input type="hidden" id="sms-template-form-id" value="" />' +
          '<div class="grid lg:grid-cols-2 gap-4">' +
            '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Template Name</label><input id="sms-template-form-name" class="input-field" placeholder="Demo Reminder" /></div>' +
            '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Sender ID</label><input id="sms-template-form-sender" class="input-field" placeholder="CACLOD" /></div>' +
            '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">DLT Template ID</label><input id="sms-template-form-dlt-id" class="input-field" placeholder="e.g. 1107161234567890123" /></div>' +
            '<div class="lg:col-span-2"><label class="text-caption font-medium text-slate-600 mb-1.5 block">Template Body</label><textarea id="sms-template-form-body" class="input-field min-h-[90px]" placeholder="Dear {#var#}, your demo for {#var#} is scheduled."></textarea></div>' +
            '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Status</label><select id="sms-template-form-status" class="input-field"><option value="approved">Approved</option><option value="pending">Pending</option><option value="inactive">Inactive</option></select></div>' +
          '</div>' +
          '<div class="flex gap-2"><button type="button" class="btn-primary btn-sm" id="sms-template-form-save">Save Template</button><button type="button" class="btn-secondary btn-sm" id="sms-template-form-cancel">Cancel</button></div>' +
        '</div>' +
        '<div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full"><thead><tr><th>Name</th><th>Sender ID</th><th>DLT Template ID</th><th>Body</th><th>Status</th><th></th></tr></thead><tbody id="sms-templates-table"></tbody></table></div>' +
      '</div>' +
      '<div id="sms-payload-preview-panel" class="card p-4 mb-6 hidden">' +
        '<div class="flex items-center justify-between mb-3"><h3 class="text-card-heading">Developer Payload Preview</h3><span class="badge-brand">Mapped · Not Sent</span></div>' +
        '<pre id="sms-payload-preview-json" class="text-xs bg-slate-900 text-emerald-300 rounded-lg p-4 overflow-x-auto whitespace-pre-wrap"></pre>' +
        '<p id="sms-payload-preview-meta" class="text-caption text-slate-500 mt-2"></p>' +
      '</div>' +
      '<div id="campaigns-grid-sms" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6"></div>' +
      '<div class="card overflow-hidden crm-table-card"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full" id="sms-logs-table-el"><thead><tr>' +
        '<th>Campaign</th><th>Template</th><th>DLT Template ID</th><th>Lead</th><th>Mobile</th><th>Message</th><th>Status</th><th>Provider Response</th><th>Created</th>' +
        '</tr></thead><tbody id="sms-logs-table"></tbody></table></div><div class="crm-table-footer" id="sms-logs-pagination-slot"></div></div>';
  }

  function notificationsPage() {
    return hdr('Notifications', 'View alerts, reminders, and system messages.', null,
      actSecondary('Mark All Read', 'data-action="mark-all-read"', 'check-check')) +
      tabs([{ id: 'all', label: 'All', icon: 'bell' }, { id: 'unread', label: 'Unread', count: '0', countId: 'notifications-unread-tab-count' }], 'all') +
      panel('all', true, '<div id="notifications-all-list" class="space-y-3"></div>') +
      panel('unread', false, '<div id="notifications-unread-list"></div>');
  }

  function receptionPage() {
    return hdr('Reception', 'Manage visitor queue, calls, and front-desk routing.', null,
      actPrimary('Add Visitor', 'data-page-action="Add visitor to queue"')) +
      '<p class="text-caption text-slate-500 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 mb-4">Preview module — sample data shown below. Full reception workflow is not yet connected to the backend.</p>' +
      kpis([
        { icon: 'users', label: 'Visitors Today', value: '24', trend: '+3' },
        { icon: 'phone-incoming', label: 'Calls Routed', value: '86', trend: '+12%' },
        { icon: 'clock', label: 'Avg Wait', value: '4.2 min', trend: '-8%' },
        { icon: 'check-circle-2', label: 'Resolved', value: '92%', trend: '+2%' },
      ]) +
      table(['Visitor', 'Purpose', 'Assigned To', 'Status', 'Time In'], [
        ['', 'Mr. Gupta', 'Tax filing query', 'Reception Desk 1', '<span class="badge-warning">Waiting</span>', '10:05 AM'],
        ['', 'Sharma & Associates', 'Demo walk-in', 'Rahul Verma', '<span class="badge-brand">In Meeting</span>', '09:45 AM'],
        ['', 'Patel Tax', 'Document pickup', 'Reception Desk 2', '<span class="badge-success">Completed</span>', '09:30 AM'],
      ]);
  }

  /* ─── WhatsApp ─── */
  function whatsappPage() {
    return hdr('Chat', 'WhatsApp Cloud API — campaigns, templates, live send, and delivery logs.', null,
      actSecondary('WhatsApp Settings', 'id="whatsapp-settings-open-btn"', 'settings') +
      actExport('Export Logs') + actPrimary('New Campaign', 'data-open-modal="add-campaign" data-campaign-channel="whatsapp"', 'megaphone')) +
      kpis([
        { icon: 'message-circle', label: 'Total Campaigns', value: '—', trend: 'Live', valueId: 'wa-kpi-campaigns' },
        { icon: 'send', label: 'Total Messages', value: '—', trend: 'Live', valueId: 'wa-kpi-messages' },
        { icon: 'check-circle-2', label: 'Sent to Meta', value: '—', trend: 'Live', valueId: 'wa-kpi-delivered' },
        { icon: 'alert-circle', label: 'Failed', value: '—', trend: 'Live', valueId: 'wa-kpi-failed' },
        { icon: 'clock', label: 'Queued', value: '—', trend: 'Pending', valueId: 'wa-kpi-queued' },
      ]) +
      tabs([{ id: 'campaigns', label: 'Campaigns', icon: 'message-circle' }, { id: 'logs', label: 'Message Logs', icon: 'list' }], 'campaigns') +
      panel('campaigns', true, '<div id="campaigns-grid-whatsapp" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>') +
      panel('logs', false,
        '<div class="card overflow-hidden crm-table-card"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full" id="wa-message-logs-table-el"><thead><tr>' +
        '<th>Date &amp; Time</th><th>Recipient</th><th>Lead</th><th>Template</th><th>Status</th><th>Meta Message ID</th><th>Delivered</th><th>Read</th><th>Failed</th><th>Actions</th>' +
        '</tr></thead><tbody id="wa-message-logs-table"></tbody></table></div><div class="crm-table-footer" id="wa-logs-pagination-slot"></div></div>') +
      '<div class="hidden card p-0 border border-brand/20 overflow-hidden flex flex-col mt-4" id="whatsapp-settings-panel">' +
        '<div class="p-4 pb-0 space-y-4">' +
          '<div class="flex items-center justify-between gap-3">' +
            '<div class="flex items-center gap-3"><i data-lucide="message-circle" class="h-5 w-5 text-brand"></i><span class="text-card-heading">Meta WhatsApp Cloud API</span><span class="badge-neutral" id="whatsapp-settings-mode-badge">Simulation</span></div>' +
            '<button type="button" class="btn-secondary btn-sm" id="whatsapp-settings-close-btn" aria-label="Close"><i data-lucide="x" class="h-4 w-4"></i></button>' +
          '</div>' +
          '<p class="text-caption text-slate-400" id="whatsapp-settings-status-summary"></p>' +
          '<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3" id="whatsapp-connection-dashboard">' +
            '<div class="rounded-lg border border-slate-200 p-3"><p class="text-caption text-slate-500">Connection</p><p class="text-sm font-medium" id="whatsapp-dash-connection"><span class="badge-neutral">—</span></p></div>' +
            '<div class="rounded-lg border border-slate-200 p-3"><p class="text-caption text-slate-500">Webhook</p><p class="text-sm font-medium" id="whatsapp-dash-webhook"><span class="badge-neutral">—</span></p></div>' +
            '<div class="rounded-lg border border-slate-200 p-3"><p class="text-caption text-slate-500">API</p><p class="text-sm font-medium" id="whatsapp-dash-api"><span class="badge-neutral">—</span></p></div>' +
            '<div class="rounded-lg border border-slate-200 p-3"><p class="text-caption text-slate-500">Token</p><p class="text-sm font-medium" id="whatsapp-dash-token"><span class="badge-neutral">—</span></p></div>' +
            '<div class="rounded-lg border border-slate-200 p-3"><p class="text-caption text-slate-500">Approved Templates</p><p class="text-sm font-medium" id="whatsapp-dash-templates">—</p></div>' +
            '<div class="rounded-lg border border-slate-200 p-3"><p class="text-caption text-slate-500">Last Sync</p><p class="text-sm font-medium" id="whatsapp-dash-last-sync">—</p></div>' +
            '<div class="rounded-lg border border-slate-200 p-3 lg:col-span-3"><p class="text-caption text-slate-500">Callback URL</p><p class="text-sm font-mono break-all" id="whatsapp-dash-callback">—</p></div>' +
          '</div>' +
          '<div id="whatsapp-settings-error-box" class="hidden rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"></div>' +
          '<div class="grid lg:grid-cols-2 gap-4">' +
            '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Phone Number ID</label><input id="whatsapp-settings-phone-number-id" class="input-field" placeholder="From Meta Business Manager" /></div>' +
            '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Business Account ID</label><input id="whatsapp-settings-business-account-id" class="input-field" placeholder="From Meta Business Manager" /></div>' +
            '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">API Version</label><input id="whatsapp-settings-api-version" class="input-field" value="v23.0" /></div>' +
            '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Mode</label><select id="whatsapp-settings-mode" class="input-field"><option value="simulation">Simulation</option><option value="live">Live</option></select></div>' +
            '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Permanent Access Token</label><input id="whatsapp-settings-access-token" class="input-field" type="password" placeholder="Encrypted at rest" autocomplete="off" /></div>' +
            '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Test Mobile</label><input id="whatsapp-settings-test-mobile" class="input-field" placeholder="9876543210" /></div>' +
            '<div class="flex items-center gap-3 pt-6"><input type="checkbox" id="whatsapp-settings-is-active" class="rounded border-slate-300" checked /><label for="whatsapp-settings-is-active" class="text-caption font-medium text-slate-600">Provider Active</label></div>' +
          '</div>' +
          '<input type="hidden" id="whatsapp-settings-provider-name" value="Meta WhatsApp Cloud API" />' +
          '<p class="text-caption text-slate-400" id="whatsapp-settings-token-note">Access token is encrypted at rest and never returned by the API.</p>' +
        '</div>' +
        '<div class="sms-settings-actions">' +
          '<div class="ca-modal-footer-buttons">' +
          actPrimary('Save Settings', 'id="whatsapp-settings-save-btn"', 'save') +
          actSecondary('Validate Configuration', 'id="whatsapp-settings-validate-btn"', 'shield-check') +
          actSecondary('Test Connection', 'id="whatsapp-settings-test-connection-btn"', 'plug') +
          actSecondary('Reset', 'id="whatsapp-settings-reset-btn"', 'rotate-ccw') +
          '</div>' +
        '</div>' +
      '</div>';
  }

  /* ─── Email ─── */
  function emailPage() {
    return hdr('Email', 'Outbound campaigns, inbox replies, delivery logs, and bounce tracking.', null,
      actExport('Export Logs') + actPrimary('New Campaign', 'data-open-modal="add-campaign" data-campaign-channel="email"')) +
      kpis([
        { icon: 'mail', label: 'Total Campaigns', value: '—', trend: 'Live', valueId: 'email-kpi-campaigns' },
        { icon: 'send', label: 'Total Emails', value: '—', trend: 'Live', valueId: 'email-kpi-messages' },
        { icon: 'check-circle-2', label: 'Delivered', value: '—', trend: 'Live', valueId: 'email-kpi-delivered' },
        { icon: 'reply', label: 'Replies Received', value: '—', trend: 'IMAP', valueId: 'email-kpi-replies' },
        { icon: 'inbox', label: 'Unread Replies', value: '—', trend: 'Inbox', valueId: 'email-kpi-unread-replies' },
        { icon: 'alert-circle', label: 'Failed', value: '—', trend: 'Live', valueId: 'email-kpi-failed' },
      ]) +
      tabs([
        { id: 'inbox', label: 'Inbox' },
        { id: 'campaigns', label: 'Campaigns' },
        { id: 'logs', label: 'Email Logs' },
        { id: 'bounce', label: 'Failed / Bounce' },
      ], 'inbox') +
      panel('inbox', true,
        '<div class="flex flex-wrap items-center justify-between gap-3 mb-3">' +
          '<p class="text-caption text-slate-500">Customer replies · <span id="email-inbox-last-sync">Last updated: —</span></p>' +
          '<div class="flex items-center gap-2">' +
            '<span id="email-inbox-unread-badge" class="badge-info hidden">0 unread</span>' +
            actSecondary('Refresh Inbox', 'id="email-inbox-sync-btn"', 'refresh-cw') +
          '</div>' +
        '</div>' +
        '<div class="card overflow-hidden crm-table-card"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full" id="email-inbox-table-el"><thead><tr>' +
        '<th>From</th><th>Lead</th><th>Email</th><th>Subject</th><th>Received</th><th>Status</th><th></th>' +
        '</tr></thead><tbody id="email-inbox-table"></tbody></table></div><div class="crm-table-footer" id="email-inbox-pagination-slot"></div></div>') +
      panel('campaigns', false, '<div id="campaigns-grid-email" class="grid sm:grid-cols-3 gap-4"></div>') +
      panel('logs', false,
        '<div class="card overflow-hidden crm-table-card"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full" id="email-logs-table-el"><thead><tr>' +
        '<th>Campaign</th><th>Lead</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Failed Reason</th>' +
        '</tr></thead><tbody id="email-logs-table"></tbody></table></div><div class="crm-table-footer" id="email-logs-pagination-slot"></div></div>') +
      panel('bounce', false,
        '<div class="card overflow-hidden"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full"><thead><tr>' +
        '<th>Email</th><th>Type</th><th>Reason</th><th>Date</th><th>Action</th>' +
        '</tr></thead><tbody id="email-bounce-table"></tbody></table></div></div>');
  }

  function consentDndPage() {
    return hdr('Consent & DND', 'Manage consent records and do-not-disturb lists before outreach.', null,
      actPrimary('Add Consent', 'data-open-modal="add-consent"') + actPrimary('Add DND', 'data-open-modal="add-dnd"')) +
      kpis([
        { icon: 'ban', label: 'DND Contacts', value: '—', trend: 'Live', valueId: 'safety-kpi-dnd' },
        { icon: 'check-circle-2', label: 'Consent Approved', value: '—', trend: 'Yes', valueId: 'safety-kpi-consent-yes' },
        { icon: 'x-circle', label: 'Consent Denied', value: '—', trend: 'No', valueId: 'safety-kpi-consent-no' },
        { icon: 'shield-off', label: 'Skipped · DND', value: '—', trend: 'Campaigns', valueId: 'safety-kpi-skip-dnd' },
        { icon: 'fingerprint', label: 'Skipped · No Consent', value: '—', trend: 'Campaigns', valueId: 'safety-kpi-skip-consent' },
      ]) +
      listingFilterBar([
        { label: 'Channel', id: 'consent-dnd-channel-filter', type: 'select', options: '<option value="">All channels</option><option value="WhatsApp">WhatsApp</option><option value="Email">Email</option><option value="SMS">SMS</option>' },
      ], '', { id: 'consent-dnd-filter-bar' }) +
      tabs([{ id: 'consent-tab', label: 'Consent Records' }, { id: 'dnd-tab', label: 'DND List' }], 'consent-tab') +
      panel('consent-tab', true,
        '<div class="card overflow-hidden crm-table-card"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full" id="consent-records-table-el"><thead><tr>' +
        '<th>Firm</th><th>Type</th><th>Status</th><th>Consent Date</th><th>Updated</th>' +
        '</tr></thead><tbody id="consent-records-table"></tbody></table></div><div class="crm-table-footer" id="consent-pagination-slot"></div></div>') +
      panel('dnd-tab', false,
        '<div class="card overflow-hidden crm-table-card"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full" id="dnd-records-table-el"><thead><tr>' +
        '<th>Firm</th><th>Mobile</th><th>Email</th><th>DND Type</th><th>Reason</th><th>Added</th><th></th>' +
        '</tr></thead><tbody id="dnd-records-table"></tbody></table></div><div class="crm-table-footer" id="dnd-pagination-slot"></div></div>');
  }

  /* ─── Security ─── */
  function securityPage() {
    return hdr('Security & Compliance', 'Role access, consent, encryption, and API protection.', null) +
      summaryNavCards([
        { panel: 'rbac', title: 'Role Access Control', icon: 'shield-check', badge: 'Live', metric: 'Loading…', metricId: 'security-metric-rbac', active: true },
        { page: 'consent-dnd', tab: 'consent-tab', title: 'Consent Tracking', icon: 'fingerprint', badge: 'Active', metric: 'Loading…', metricId: 'security-metric-consent' },
        { page: 'consent-dnd', tab: 'dnd-tab', title: 'DND Management', icon: 'ban', badge: 'Active', metric: 'Loading…', metricId: 'security-metric-dnd' },
        { title: 'Encryption Keys', icon: 'lock', badge: 'Protected', metric: 'Laravel app encryption', metricId: 'security-metric-encrypt', static: true },
        { title: 'Lead Locking', icon: 'key', badge: 'Enabled', metric: 'Loading…', metricId: 'security-metric-locking', static: true },
        { title: 'API Protection', icon: 'zap', badge: 'Enabled', metric: 'Loading…', metricId: 'security-metric-api', static: true },
      ], { id: 'security-nav' }) +
      '<div id="security-content">' +
        panel('rbac', true,
          '<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">' +
            '<div>' +
              '<h3 class="text-card-heading mb-1">Permission Matrix</h3>' +
              '<p id="security-matrix-note" class="text-caption text-slate-500">Loading permissions…</p>' +
            '</div>' +
            '<div class="roles-perm-toolbar">' +
              '<label class="roles-perm-role-label" for="security-perm-role-select">Role</label>' +
              '<select id="security-perm-role-select" class="input-field roles-perm-role-select" aria-label="Select role">' +
                '<option value="manager">Manager</option>' +
                '<option value="employee">Employee</option>' +
                '<option value="admin">Admin</option>' +
              '</select>' +
            '</div>' +
          '</div>' +
          '<div id="security-perm-stats" class="roles-perm-stats mb-3" aria-live="polite">' +
            '<div class="roles-perm-stat"><span class="roles-perm-stat-label">Modules</span><strong id="security-perm-stat-modules">—</strong></div>' +
            '<div class="roles-perm-stat"><span class="roles-perm-stat-label">Enabled</span><strong id="security-perm-stat-enabled">—</strong></div>' +
            '<div class="roles-perm-stat"><span class="roles-perm-stat-label">Disabled</span><strong id="security-perm-stat-disabled">—</strong></div>' +
          '</div>' +
          '<div class="roles-perm-filters mb-3">' +
            '<div class="roles-perm-search-wrap">' +
              '<i data-lucide="search" class="h-4 w-4 roles-perm-search-icon" aria-hidden="true"></i>' +
              '<input type="search" id="security-perm-search" class="input-field roles-perm-search" placeholder="Search modules…" autocomplete="off" aria-label="Search modules" />' +
            '</div>' +
          '</div>' +
          '<div class="card overflow-hidden mb-4 roles-perm-matrix-card">' +
            '<div class="roles-perm-matrix-wrap scrollbar-thin" id="security-perm-matrix-scroll">' +
              '<table class="ca-table roles-perm-matrix" id="security-perm-matrix-table">' +
                '<thead id="security-perm-matrix-head"><tr><th>Module</th></tr></thead>' +
                '<tbody id="security-rbac-matrix"><tr><td class="text-center text-slate-500 p-6">Loading…</td></tr></tbody>' +
              '</table>' +
            '</div>' +
            '<div id="security-perm-mobile" class="roles-perm-mobile hidden"></div>' +
          '</div>' +
          '<h4 class="text-card-heading mb-3">Users</h4>' +
          '<div class="overflow-x-auto"><table class="ca-table w-full"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Modules</th></tr></thead><tbody id="security-users-table"></tbody></table></div>', 'security') +
      '</div>';
  }

  /* ─── Employees ─── */
  function employeesPage() {
    return hdr('Employee Management', 'Manage team members, roles, and performance.', null,
      actPrimary('Add Employee', 'data-open-modal="add-employee"')) +
      tabs([{ id: 'employees', label: 'Employees', icon: 'users' }, { id: 'roles', label: 'Roles', icon: 'shield' }, { id: 'performance', label: 'Performance', icon: 'trophy' }], 'employees') +
      panel('employees', true,
        table([
          { label: 'Name', colCls: 'crm-col-person', thCls: 'crm-th-person', sticky: 'left' },
          { label: 'Email', colCls: 'crm-col-person', thCls: 'crm-th-person' },
          { label: 'Mobile', colCls: 'crm-col-mobile', thCls: 'crm-th-mobile' },
          { label: 'Role', colCls: 'crm-col-source', thCls: 'crm-th-source' },
          { label: 'Login', colCls: 'crm-col-status', thCls: 'crm-th-status' },
          { label: 'City', colCls: 'crm-col-geo', thCls: 'crm-th-geo' },
          { label: 'Joined', colCls: 'crm-col-date', thCls: 'crm-th-date' },
          { label: 'Status', colCls: 'crm-col-status', thCls: 'crm-th-status' },
          { label: 'Actions', colCls: 'crm-col-actions', thCls: 'crm-th-actions', sticky: 'right' },
        ], [], { tbodyId: 'employees-data-table', tableId: 'employees-table-main', enterprise: true, inbox: true, inboxKey: 'employees-data-table', inboxModule: 'employees', paginationId: 'employees-pagination-slot' })) +
      panel('roles', false,
        '<div class="card p-5"><p class="text-caption text-slate-500">Role definitions are managed via the security matrix. Open Settings → Permissions to review access.</p></div>') +
      panel('performance', false,
        '<div id="leaderboard" class="card p-5 mb-4"></div>' +
        '<div class="card overflow-hidden"><div class="overflow-x-auto"><table class="ca-table w-full"><thead><tr><th>Employee</th><th>Daily Calls</th><th>Demos</th><th>Conversion</th><th>Revenue</th><th>Target %</th></tr></thead><tbody id="employees-performance-table"><tr><td colspan="6" class="text-center text-slate-500 p-4">Loading performance data…</td></tr></tbody></table></div></div>');
  }

  function bulkPage() {
    return hdr('Bulk Operations', 'Import, export, assign, and update records in bulk.', null,
      actPrimary('Bulk Import', 'data-nav-bulk="import"')) + bulkBody();
  }

  /* ─── Queue ─── */
  function dbHealthPage() {
    return hdr(
      'Database Health',
      'Super Admin view — table counts, duplicate checks, foreign keys, API routes, and database size.',
      'SUPER ADMIN · DB_HEALTH',
      actSecondary('Refresh', 'id="db-health-refresh-btn"', 'refresh-cw'),
    ) +
      '<p class="text-caption text-amber-700 bg-amber-50 border border-amber-100 rounded-xl px-4 py-3 mb-6">Super Admin access only. Other roles cannot open this page or API.</p>' +
      '<div id="db-health-kpi-grid" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6"></div>' +
      '<div class="grid lg:grid-cols-2 gap-4 mb-6">' +
        '<div class="card p-4"><p class="text-caption text-slate-500">Database</p><p id="db-health-db-name" class="font-semibold text-slate-900">Loading…</p><p id="db-health-db-size" class="text-caption text-slate-500 mt-1">—</p></div>' +
        '<div class="card p-4"><p class="text-caption text-slate-500">Last Updated</p><p id="db-health-generated-at" class="font-semibold text-slate-900">—</p><p class="text-caption text-slate-500 mt-1">Use Refresh to update checks</p></div>' +
      '</div>' +
      table(['Data Set', 'Records', 'Latest Record', 'Latest Created', 'Status'], [], { tbodyId: 'db-health-tables-body' }) +
      '<div class="grid lg:grid-cols-2 gap-4 mt-6">' +
        table(['Duplicate Field', 'Extra Rows', 'Groups', 'Sample Values', 'Status'], [], { tbodyId: 'db-health-duplicates-body' }) +
        table(['Foreign Key Check', 'Invalid Count', 'Sample Invalid Rows', 'Status'], [], { tbodyId: 'db-health-fk-body' }) +
      '</div>' +
      table(['API Route', 'Method', 'Route Exists', 'Status'], [], { tbodyId: 'db-health-api-body', cls: 'mt-6' });
  }

  function queuePage() {
    return hdr('System Health', 'Monitor background jobs, queue status, and worker health.', null,
      actSecondary('Refresh', 'id="queue-refresh-btn"', 'refresh-cw')) +
      kpis([
        { icon: 'server', label: 'Pending Jobs', value: '—', trend: 'Live', valueId: 'queue-kpi-pending' },
        { icon: 'alert-triangle', label: 'Failed Jobs', value: '—', trend: 'Live', valueId: 'queue-kpi-failed' },
        { icon: 'plug', label: 'Connection', value: '—', trend: 'Driver', valueId: 'queue-kpi-connection' },
        { icon: 'activity', label: 'Worker', value: '—', trend: 'Status', valueId: 'queue-kpi-worker' },
      ]) +
      '<div class="card p-4 mb-4"><p class="text-caption text-slate-500 mb-2">Recommended commands</p><ul id="queue-commands-list" class="text-sm text-slate-700 space-y-1 font-mono"></ul></div>' +
      table(['Reference', 'Queue', 'Job', 'Failed At', 'Exception'], [], { tbodyId: 'queue-failed-body' });
  }

  function auditBody() {
    return listingFilterBar([
      { label: 'Module', id: 'audit-filter-module', type: 'select', options: '<option value="">All modules</option>' },
      { label: 'Action', id: 'audit-filter-action', type: 'select', options: '<option value="">All actions</option>' },
      { label: 'From', id: 'audit-filter-from', type: 'date', attrs: 'data-crm-date-input data-allow-past data-hide-preview' },
      { label: 'To', id: 'audit-filter-to', type: 'date', attrs: 'data-crm-date-input data-allow-past data-hide-preview' },
      { label: 'User', id: 'audit-filter-user', type: 'search', placeholder: 'search' },
    ], actPrimary('Apply', 'id="audit-filter-apply"', 'filter') + actSecondary('Clear', 'id="audit-filter-clear"', 'x'), { id: 'audit-filter-bar', actionsInline: true }) +
      '<div class="card p-5"><div class="overflow-x-auto scrollbar-thin">' +
        '<table class="ca-table w-full"><thead><tr>' +
          '<th>Timestamp</th><th>User</th><th>Module</th><th>Record ID</th><th>Action</th>' +
          '<th>Before</th><th>After</th><th>IP</th><th>Details</th>' +
        '</tr></thead><tbody id="audit-logs-table"></tbody></table>' +
      '</div></div>';
  }

  function activityBody() {
    return listingFilterBar([
      { label: 'Module', id: 'activity-filter-module', type: 'select', options: '<option value="">All modules</option>' },
      { label: 'Action', id: 'activity-filter-action', type: 'select', options: '<option value="">All actions</option>' },
      { label: 'Date', id: 'activity-filter-date', type: 'date', attrs: 'data-crm-date-input data-allow-past data-hide-preview' },
      { label: 'User', id: 'activity-filter-user', type: 'search', placeholder: 'search' },
    ], actPrimary('Apply', 'id="activity-filter-apply"', 'filter') + actSecondary('Clear', 'id="activity-filter-clear"', 'x'), { id: 'activity-filter-bar', actionsInline: true }) +
      '<div class="card p-6 mb-4"><div id="activity-timeline"></div></div>' +
      '<div class="card p-5"><div class="flex items-center justify-between gap-3 mb-3">' +
        '<h3 class="text-card-heading">Activity Logs</h3></div>' +
        '<div class="overflow-x-auto scrollbar-thin">' +
          '<table class="ca-table w-full"><thead><tr>' +
            '<th>Timestamp</th><th>User</th><th>Module</th><th>Record ID</th><th>Action</th><th>Description</th>' +
          '</tr></thead><tbody id="activity-logs-table"></tbody></table>' +
        '</div><div class="crm-table-footer" id="activity-pagination-slot"></div></div>';
  }

  function reportsHubPage(activeTab) {
    activeTab = activeTab || 'reports';
    var reportDefs = [
      { card: 'Daily Lead Report', slug: 'lead_conversion', icon: 'calendar-days' },
      { card: 'Weekly Demo Report', slug: 'followup_performance', icon: 'presentation' },
      { card: 'Monthly Trends', slug: 'monthly_trends', icon: 'line-chart' },
      { card: 'City-wise Analysis', slug: 'city_analysis', icon: 'map-pin' },
      { card: 'Employee Performance', slug: 'employee_performance', icon: 'trophy' },
      { card: 'Duplicate Productivity', slug: 'duplicate_productivity', icon: 'copy' },
      { card: 'Duplicate Attempt Monitor', slug: 'duplicate_attempts_nav', nav: 'duplicate-attempts', icon: 'shield-alert' },
      { card: 'Lost Lead Analysis', slug: 'lost_lead_analysis', icon: 'trending-down' },
    ];
    var reportCards = '<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">' +
      reportDefs.map(function (r) {
        var navAttr = r.nav ? ' data-nav-page="' + r.nav + '"' : ' data-report="' + r.card + '" data-report-slug="' + r.slug + '"';
        return '<div class="card-interactive p-4 flex items-center gap-3 report-card"' + navAttr + '>' +
          '<div class="report-card__icon flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand"><i data-lucide="' + (r.icon || 'file-text') + '" class="h-5 w-5"></i></div>' +
          '<div><p class="text-card-heading report-card__title">' + r.card + '</p><p class="text-caption text-slate-500 report-card-meta" data-report-slug="' + r.slug + '">Open report</p></div></div>';
      }).join('') + '</div>';
    var analyticsCharts = [
      { label: 'Daily Calls', key: 'daily_calls' },
      { label: 'Demo Ratio', key: 'demo_ratio' },
      { label: 'Conversion %', key: 'conversion' },
      { label: 'City Performance', key: 'city_performance' },
      { label: 'Lead Source', key: 'lead_source' },
      { label: 'Target Achievement', key: 'target_achievement' },
    ];

    return '<div class="reports-hub-page">' +
      hdr('Reports', null, null,
      pageHeroToolbar(
        actSecondary('Export Summary', 'data-action="export" data-export="export-report"', 'download') +
        actSecondary('Export PDF', 'data-action="export" data-export="export-report-pdf"', 'file-text') +
        '<span class="page-hero-toolbar__sep" aria-hidden="true"></span>' +
        heroIconTabs([
          { id: 'reports', label: 'Reports', icon: 'file-text' },
          { id: 'analytics', label: 'Analytics', icon: 'bar-chart-3' },
          { id: 'activity', label: 'Activity', icon: 'activity' },
          { id: 'audit', label: 'Audit', icon: 'history' },
        ], activeTab, 'reports-hub')
      )) +
      panel('reports', activeTab === 'reports', reportCards, 'reports-hub') +
      panel('analytics', activeTab === 'analytics', charts(analyticsCharts), 'reports-hub') +
      panel('activity', activeTab === 'activity', activityBody(), 'reports-hub') +
      panel('audit', activeTab === 'audit', auditBody(), 'reports-hub') +
    '</div>';
  }

  /* ─── Activity / Audit (standalone kept for search) ─── */
  function activityPage() {
    return hdr('Activity Logs', 'Review user actions across the application.', null,
      actExport('Export Logs')) + activityBody();
  }

  function auditPage() {
    return hdr('Audit Logs', 'Compliance trail of changes with before and after values.', null,
      actExport('Export Audit')) + auditBody();
  }

  function salesListHeroSearchHtml() {
    return '<label class="sales-list-hero-search" aria-label="Quick search">' +
      '<i data-lucide="search" class="sales-list-hero-search__icon" aria-hidden="true"></i>' +
      '<input type="search" id="sales-list-search" class="crm-col-filter-input sales-list-hero-search__input" placeholder="Customer, firm, mobile, invoice…" autocomplete="off" />' +
    '</label>';
  }

  function salesListTableColumns() {
    return [
      { label: 'S.No', colCls: 'crm-col-num', thCls: 'crm-th-num', sticky: 'left', filterType: 'number', filterId: 'sales-filter-serial', filterKey: 'serial_number', filterPlaceholder: '#' },
      { label: 'Month', colCls: 'crm-col-date', thCls: 'crm-th-date', filterType: 'select', filterId: 'sales-filter-month', filterKey: 'sale_month', filterOptionsHtml: '<option value="">All months</option>' },
      { label: 'Point', colCls: 'crm-col-num', thCls: 'crm-th-num', filterType: 'number-range', filterMinKey: 'points_min', filterMaxKey: 'points_max' },
      { label: 'Customer Name', colCls: 'crm-col-person', thCls: 'crm-th-person', sticky: 'left-2', filterType: 'search', filterId: 'sales-filter-customer', filterKey: 'customer_name', filterPlaceholder: 'search' },
      { label: 'Firm Name', colCls: 'crm-col-firm', thCls: 'crm-th-firm', filterType: 'search', filterId: 'sales-filter-firm', filterKey: 'firm_name', filterPlaceholder: 'search' },
      { label: 'Reference', colCls: 'crm-col-person', thCls: 'crm-th-person', filterType: 'search', filterId: 'sales-filter-reference', filterKey: 'reference_name', filterPlaceholder: 'search' },
      { label: 'Mobile Number', colCls: 'crm-col-mobile', thCls: 'crm-th-mobile', filterType: 'search', filterId: 'sales-filter-mobile', filterKey: 'mobile_no', filterPlaceholder: 'search' },
      { label: 'City', colCls: 'crm-col-geo', thCls: 'crm-th-geo', filterType: 'search', filterId: 'sales-filter-city', filterKey: 'city_name', filterPlaceholder: 'search' },
      { label: 'Plan Purchased', colCls: 'crm-col-source', thCls: 'crm-th-source', filterType: 'select', filterId: 'sales-filter-plan', filterKey: 'plan_purchased', filterOptionsHtml: '<option value="">All plans</option>' },
      { label: 'Purchase Date', colCls: 'crm-col-date', thCls: 'crm-th-date', filterType: 'date', filterId: 'sales-filter-purchase-date', filterKey: 'purchase_date' },
      { label: 'Cooling Period', colCls: 'crm-col-num', thCls: 'crm-th-num', filterType: 'select', filterId: 'sales-filter-cooling', filterKey: 'cooling_period_days', filterOptionsHtml: '<option value="">All periods</option>' },
      { label: 'Expiry Date', colCls: 'crm-col-date', thCls: 'crm-th-date', filterType: 'date', filterId: 'sales-filter-expiry-date', filterKey: 'expiry_date' },
      { label: 'Total Amount', colCls: 'crm-col-num', thCls: 'crm-th-num', filterType: 'number-range', filterMinKey: 'total_amount_min', filterMaxKey: 'total_amount_max' },
      { label: 'Amount Received', colCls: 'crm-col-num', thCls: 'crm-th-num', filterType: 'number-range', filterMinKey: 'amount_received_min', filterMaxKey: 'amount_received_max' },
      { label: 'Balance Amount', colCls: 'crm-col-num', thCls: 'crm-th-num', filterType: 'number-range', filterMinKey: 'balance_amount_min', filterMaxKey: 'balance_amount_max' },
      { label: 'Invoice Number', colCls: 'crm-col-mono', thCls: 'crm-th-mono', filterType: 'search', filterId: 'sales-filter-invoice', filterKey: 'invoice_number', filterPlaceholder: 'search' },
      { label: 'Payment Status', colCls: 'crm-col-status', thCls: 'crm-th-status', filterType: 'select', filterId: 'sales-filter-payment-status', filterKey: 'payment_status', filterOptionsHtml: '<option value="">All statuses</option>' },
      { label: 'Sales Executive', colCls: 'crm-col-person', thCls: 'crm-th-person', filterType: 'select', filterId: 'sales-filter-executive', filterKey: 'employee_name', filterOptionsHtml: '<option value="">All executives</option>' },
      { label: 'Assigned Manager', colCls: 'crm-col-person', thCls: 'crm-th-person', filterType: 'select', filterId: 'sales-filter-manager', filterKey: 'manager_name', filterOptionsHtml: '<option value="">All managers</option>' },
      { label: 'Actions', colCls: 'crm-col-actions', thCls: 'crm-th-actions', sticky: 'right', filterType: 'reset', filterId: 'sales-filter-reset' },
    ];
  }

  function salesListPage() {
    var content =
      '<div class="sales-list-module" id="sales-list-module">' +
      hdr(
        'Sales List',
        'Converted customers and payment tracking.',
        null,
        salesListHeroSearchHtml() +
        actSecondary('Export Excel', 'id="sales-list-export-csv"', 'file-spreadsheet') +
        actSecondary('Export PDF', 'id="sales-list-export-pdf"', 'file-text')
      ) +
      enterpriseTable(salesListTableColumns(), {
        tbodyId: 'sales-list-data-table',
        tableId: 'sales-list-table',
        wrapId: 'sales-list-table-wrap',
        paginationId: 'sales-list-pagination-slot',
        cls: 'sales-list-table-card',
        columnFilters: true,
        filterGroup: 'sales_list',
      }) +
      '</div>' +
      '<div id="modal-sales-list-edit" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="sales-list-edit-title">' +
        '<div class="ca-modal-panel ca-modal-panel-lg">' +
          '<div class="ca-modal-header">' +
            '<h3 id="sales-list-edit-title" class="ca-modal-title"><span class="ca-modal-icon"><i data-lucide="pencil" class="h-5 w-5"></i></span> Edit Sales Record</h3>' +
            '<button type="button" class="ca-modal-close" data-close-sales-list-edit aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>' +
          '</div>' +
          '<form id="sales-list-edit-form" class="ca-modal-body space-y-5">' +
            '<input type="hidden" name="sales_list_id" id="sales-list-edit-id" />' +
            '<div class="rounded-lg border border-slate-200 bg-slate-50/80 p-3 text-sm text-slate-600">' +
              '<span class="font-medium text-slate-800">Invoice:</span> <span id="sales-list-edit-invoice-preview">—</span>' +
              ' · <span class="font-medium text-slate-800">S.No:</span> <span id="sales-list-edit-serial-preview">—</span>' +
            '</div>' +
            '<div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">' +
              '<div><label class="form-label">Point</label><input type="number" min="0" step="1" name="points" id="sales-list-edit-points" class="input-field" /></div>' +
              '<div><label class="form-label">Customer Name</label><input type="text" name="customer_name" id="sales-list-edit-customer" class="input-field" /></div>' +
              '<div><label class="form-label">Firm Name</label><input type="text" name="firm_name" id="sales-list-edit-firm" class="input-field" /></div>' +
              '<div><label class="form-label">Reference</label><input type="text" name="reference_name" id="sales-list-edit-reference" class="input-field" /></div>' +
              '<div><label class="form-label">Mobile Number</label><input type="text" name="mobile_no" id="sales-list-edit-mobile" class="input-field" /></div>' +
              '<div><label class="form-label">City</label><input type="text" name="city_name" id="sales-list-edit-city" class="input-field" /></div>' +
              '<div><label class="form-label">Plan Purchased</label><select name="plan_purchased" id="sales-list-edit-plan" class="input-field"></select></div>' +
              '<div><label class="form-label">Purchase Date</label><input type="date" name="purchase_date" id="sales-list-edit-purchase-date" class="input-field" data-crm-date-input data-allow-past /></div>' +
              '<div><label class="form-label">Cooling Period (days)</label><input type="number" min="0" step="1" name="cooling_period_days" id="sales-list-edit-cooling" class="input-field" /></div>' +
              '<div><label class="form-label">Expiry Date</label><input type="text" id="sales-list-edit-expiry" class="input-field bg-slate-50" readonly tabindex="-1" aria-readonly="true" /></div>' +
              '<div><label class="form-label">Total Amount</label><input type="number" min="0" step="0.01" name="total_amount" id="sales-list-edit-total" class="input-field" /></div>' +
              '<div><label class="form-label">Amount Received</label><input type="number" min="0" step="0.01" name="amount_received" id="sales-list-edit-received" class="input-field" /></div>' +
              '<div><label class="form-label">Balance Amount</label><input type="text" id="sales-list-edit-balance" class="input-field bg-slate-50" readonly tabindex="-1" aria-readonly="true" /></div>' +
              '<div><label class="form-label">Invoice Number</label><input type="text" name="invoice_number" id="sales-list-edit-invoice" class="input-field" /></div>' +
              '<div><label class="form-label">Payment Status</label><div id="sales-list-edit-status" class="pt-2"></div></div>' +
              '<div><label class="form-label">Sales Executive</label><select name="employee_id" id="sales-list-edit-executive" class="input-field" data-crm-entity-lookup="employee" data-crm-lookup-empty-label="Unassigned" data-crm-lookup-placeholder="Search executive…"><option value="">Unassigned</option></select></div>' +
              '<div><label class="form-label">Assigned Manager</label><select name="manager_id" id="sales-list-edit-manager" class="input-field" data-crm-entity-lookup="employee" data-crm-lookup-empty-label="Unassigned" data-crm-lookup-placeholder="Search manager…"><option value="">Unassigned</option></select></div>' +
            '</div>' +
            '<div><label class="form-label">Remarks / Notes</label><textarea name="notes" id="sales-list-edit-notes" class="input-field" rows="3"></textarea></div>' +
            '<p class="text-caption text-slate-500">Balance, expiry date, and payment status are calculated automatically when you change plan, dates, or amounts.</p>' +
          '</form>' +
          '<div class="ca-modal-footer"><div class="ca-modal-footer-buttons">' +
            '<button type="button" class="btn-secondary" data-close-sales-list-edit>Cancel</button>' +
            '<button type="button" class="btn-primary" id="sales-list-edit-save"><i data-lucide="save" class="h-4 w-4"></i> Save Changes</button>' +
          '</div></div>' +
        '</div>' +
      '</div>' +
      '<div id="modal-sales-list-history" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="sales-list-history-title">' +
        '<div class="ca-modal-panel ca-modal-panel-lg">' +
          '<div class="ca-modal-header">' +
            '<h3 id="sales-list-history-title" class="ca-modal-title"><span class="ca-modal-icon"><i data-lucide="history" class="h-5 w-5"></i></span> Edit History</h3>' +
            '<button type="button" class="ca-modal-close" data-close-sales-list-history aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>' +
          '</div>' +
          '<div class="ca-modal-body">' +
            '<p class="text-caption text-slate-500 mb-3" id="sales-list-history-subtitle">Audit trail for this sales record.</p>' +
            '<div class="crm-table-card crm-table-card--nested overflow-x-auto">' +
              '<table class="crm-table w-full text-sm">' +
                '<thead><tr>' +
                  '<th class="crm-th-date">When</th>' +
                  '<th class="crm-th-person">Edited By</th>' +
                  '<th class="crm-th-source">Field</th>' +
                  '<th>Previous Value</th>' +
                  '<th>New Value</th>' +
                '</tr></thead>' +
                '<tbody id="sales-list-history-body"></tbody>' +
              '</table>' +
            '</div>' +
          '</div>' +
          '<div class="ca-modal-footer"><div class="ca-modal-footer-buttons">' +
            '<button type="button" class="btn-secondary" data-close-sales-list-history>Close</button>' +
          '</div></div>' +
        '</div>' +
      '</div>';
    return content;
  }

  function duplicateAttemptsPage() {
    return hdr(
      'Duplicate Attempts',
      'Employee duplicate phone attempts and suspicious similar-number entries for fraud monitoring.',
      null,
      actSecondary('Export', 'id="dup-attempts-export-btn"', 'download')
    ) +
      listingFilterBar([
        { label: 'Search', id: 'dup-attempts-search', type: 'search', placeholder: 'search' },
        { label: 'Type', id: 'dup-attempts-type', type: 'select', options: '<option value="">All types</option><option value="duplicate">Duplicate</option><option value="potential_duplicate">Potential Duplicate</option>' },
        { label: 'Status', id: 'dup-attempts-status', type: 'select', options: '<option value="">All statuses</option><option value="open">Open</option><option value="changed_number">Changed</option><option value="resolved">Resolved</option>' },
        { label: 'From', id: 'dup-attempts-from', type: 'date', attrs: 'data-crm-date-input data-allow-past data-hide-preview' },
        { label: 'To', id: 'dup-attempts-to', type: 'date', attrs: 'data-crm-date-input data-allow-past data-hide-preview' },
      ], actPrimary('Apply', 'id="dup-attempts-apply"', 'filter') + actSecondary('Clear', 'id="dup-attempts-clear"', 'x'), { id: 'dup-attempts-filter-bar', actionsInline: true }) +
      '<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4" id="dup-attempts-metrics"></div>' +
      table(
        ['Employee', 'Duplicate Number', 'Existing Lead', 'Saved Number', 'Attempt Time', 'Attempt Type', 'Status', 'Actions'],
        [],
        { tbodyId: 'dup-attempts-table', paginationId: 'dup-attempts-pagination', cls: 'mb-4' }
      );
  }

  /* ─── Email Configuration (Super Admin) ─── */
  function ecfgReq() {
    return '<span class="ecfg-required" aria-hidden="true">*</span>';
  }

  function ecfgRow(label, fieldHtml, hint) {
    return '<div class="ecfg-form-row">' +
      '<label class="ecfg-form-label">' + label + '</label>' +
      '<div class="ecfg-form-field">' + fieldHtml + (hint ? '<p class="ecfg-hint">' + hint + '</p>' : '') + '</div>' +
    '</div>';
  }

  function ecfgPasswordInput(id, attrs) {
    attrs = attrs || '';
    return '<div class="password-field-wrap ecfg-password-wrap">' +
      '<div class="password-input-row">' +
        '<input type="password" id="' + id + '" class="input-field" autocomplete="new-password" ' + attrs + ' />' +
        '<button type="button" class="btn-secondary btn-sm password-toggle-btn" data-password-toggle aria-label="Show password"><i data-lucide="eye" class="h-4 w-4"></i></button>' +
      '</div></div>';
  }

  function emailConfigurationPage() {
    var guidesHtml = (window.CAGuide && typeof window.CAGuide.emailConfigurationSection === 'function')
      ? window.CAGuide.emailConfigurationSection()
      : '';

    var content = (
      '<div id="email-config-page-root" class="email-config-page">' +
        settingsSubPageHero(
          'Email Configuration',
          'Configure SMTP and IMAP accounts for outbound campaigns, notifications, and inbox sync.',
          actSecondary('Add Account', 'id="email-account-add-btn"', 'plus'),
        ) +
        '<div id="email-accounts-status" class="mb-4"></div>' +
        '<section class="card ecfg-card p-0 overflow-hidden mb-6">' +
          '<div class="ecfg-card-head ecfg-card-head--tabs border-b border-slate-100">' +
            '<div><h2 class="ecfg-section-title"><i data-lucide="mail" class="h-5 w-5"></i> SMTP &amp; IMAP Configuration</h2><p class="ecfg-section-sub">Communication email account for campaigns, notifications, and inbox sync.</p></div>' +
            '<div class="ca-tabs ecfg-tabs" data-tab-group="email-account">' +
              '<button type="button" class="ca-tab active" data-tab-group="email-account" data-tab="smtp">SMTP Configuration</button>' +
              '<button type="button" class="ca-tab" data-tab-group="email-account" data-tab="view">View SMTP</button>' +
            '</div>' +
          '</div>' +
          '<form id="email-account-form" class="ecfg-card-body">' +
            '<input type="hidden" id="email-account-id" value="" />' +
            '<input type="hidden" id="email-account-display-name" value="" />' +
            '<input type="hidden" id="email-account-smtp-username" value="" />' +
            '<input type="hidden" id="email-account-imap-username" value="" />' +
            '<input type="hidden" id="email-account-imap-password" value="" />' +
            '<input type="hidden" id="email-account-imap-encryption" value="ssl" />' +
            '<input type="checkbox" id="email-account-is-active" class="hidden" checked />' +
            '<div class="ca-tab-panel active" data-tab-group="email-account" data-panel="smtp">' +
              '<div class="ecfg-form-stack">' +
                ecfgRow('Email' + ecfgReq(), '<input type="email" id="email-account-from-email" class="input-field" required placeholder="you@company.com" />') +
                ecfgRow('App Password' + ecfgReq(), ecfgPasswordInput('email-account-smtp-password', 'required'), '<span id="email-account-smtp-password-note">Encrypted at rest. Leave blank when editing to keep existing password.</span>') +
                ecfgRow('SMTP Host' + ecfgReq(), '<input type="text" id="email-account-smtp-host" class="input-field" required placeholder="smtp.gmail.com" />') +
                ecfgRow('SMTP Port' + ecfgReq(), '<input type="number" id="email-account-smtp-port" class="input-field" required value="465" min="1" max="65535" />') +
                ecfgRow('Encryption', '<select id="email-account-smtp-encryption" class="input-field"><option value="ssl">SSL</option><option value="tls">TLS</option><option value="starttls">STARTTLS</option></select>') +
                ecfgRow('Default', '<label class="ecfg-checkbox-row"><input type="checkbox" id="email-account-is-default" class="rounded border-slate-300" /><span>Use as default sender for campaigns and system emails</span></label>') +
                ecfgRow('IMAP Enabled', '<label class="ecfg-checkbox-row"><input type="checkbox" id="email-account-imap-enabled" class="rounded border-slate-300" /><span>Enable inbox sync and reply detection</span></label>') +
              '</div>' +
              '<div id="email-account-imap-fields" class="hidden ecfg-form-stack ecfg-imap-fields">' +
                ecfgRow('IMAP Host' + ecfgReq(), '<input type="text" id="email-account-imap-host" class="input-field" placeholder="imap.gmail.com" />') +
                ecfgRow('IMAP Port' + ecfgReq(), '<input type="number" id="email-account-imap-port" class="input-field" value="993" min="1" max="65535" />') +
              '</div>' +
              '<div class="ecfg-test-row">' +
                actSecondary('Test SMTP Connection', 'id="email-account-test-smtp-btn"', 'plug') +
                '<span id="email-account-smtp-test-badge" class="badge-neutral">Not tested</span>' +
                actSecondary('Test IMAP Connection', 'id="email-account-test-imap-btn" class="hidden"', 'inbox') +
                '<span id="email-account-imap-test-badge" class="badge-neutral hidden">Not tested</span>' +
              '</div>' +
              '<div id="email-account-imap-test-details" class="hidden ecfg-imap-test-details"></div>' +
              '<div class="ecfg-form-actions ecfg-form-actions--bordered">' +
                '<button type="submit" class="btn-primary" id="email-account-save-btn" disabled>Save Configuration</button>' +
                actSecondary('Sync IMAP Now', 'id="email-account-sync-imap-btn" class="hidden"', 'refresh-cw') +
                actDanger('Delete', 'id="email-account-delete-btn" class="hidden"', 'trash-2') +
              '</div>' +
              '<p class="ecfg-hint ecfg-hint--block">Test SMTP before saving. When IMAP is enabled, test IMAP as well.</p>' +
            '</div>' +
            '<div class="ca-tab-panel" data-tab-group="email-account" data-panel="view">' +
              '<div class="ecfg-table-wrap">' +
                '<table class="ca-table ecfg-table">' +
                  '<thead><tr>' +
                    '<th>Email</th><th>SMTP Host</th><th>SMTP Port</th><th>IMAP</th><th>Default</th><th>Status</th><th class="text-right">Actions</th>' +
                  '</tr></thead>' +
                  '<tbody id="email-accounts-view-body"><tr><td colspan="7" class="text-center text-slate-500 p-6">Loading accounts…</td></tr></tbody>' +
                '</table>' +
              '</div>' +
              '<div id="email-account-connection-status" class="hidden"></div>' +
            '</div>' +
          '</form>' +
        '</section>' +
        (guidesHtml ? '<div class="mb-6" id="email-config-guides">' + guidesHtml + '</div>' : '') +
      '</div>'
    );
    return settingsHubLayout('email-configuration', content);
  }

  function emailTemplatesPage() {
    return settingsHubLayout('email-templates',
      '<div id="settings-email-templates-page" class="settings-templates-page crm-template-mgmt"></div>'
    );
  }

  function whatsAppTemplatesPage() {
    return settingsHubLayout('whatsapp-templates',
      '<div id="settings-whatsapp-templates-page" class="settings-templates-page crm-template-mgmt"></div>'
    );
  }

  function demoProvidersSettingsPage() {
    return settingsHubLayout('demo-providers',
      '<div id="settings-demo-providers-page" class="settings-demo-providers-page">' +
        settingsSubPageHero(
          'Demo Providers / Availability',
          'Configure demo provider working hours, slot duration, breaks, leave dates, and meeting links.'
        ) +
        '<div class="card p-5"><div id="demo-providers-settings-list"><p class="text-caption text-slate-400">Loading providers…</p></div></div>' +
      '</div>'
    );
  }

  function googleApiSettingsPage() {
    var content =
      '<div id="settings-google-api-page" class="settings-google-api-page">' +
        settingsSubPageHero(
          'Google API Settings',
          'Configure the Google Places API key used for firm research, geocoding, and location lookups. Keys saved here override the environment variable.'
        ) +
        '<section class="card p-6 max-w-2xl">' +
          '<div id="google-api-status" class="mb-4 flex flex-wrap items-center gap-2">' +
            '<span class="badge-neutral" id="google-api-status-badge">Loading…</span>' +
            '<span class="text-caption text-slate-500" id="google-api-source-label"></span>' +
          '</div>' +
          '<form id="google-api-settings-form" class="ecfg-form-stack">' +
            '<div>' +
              '<label class="text-caption text-slate-500" for="google-api-key-input">Google Places API Key</label>' +
              '<input type="password" id="google-api-key-input" class="input-field mt-1" autocomplete="off" placeholder="Enter API key to save in database" />' +
              '<p class="text-caption text-slate-500 mt-1" id="google-api-key-masked"></p>' +
              '<p class="text-caption text-slate-500 mt-1">Leave blank when saving to keep the current key. Clear the field and save to remove the stored key and fall back to <code>GOOGLE_PLACES_API_KEY</code> / <code>GOOGLE_MAPS_API_KEY</code> in <code>.env</code>. Maps JavaScript in the browser uses <code>VITE_GOOGLE_MAPS_API_KEY</code> only (never the server key).</p>' +
              '<p class="text-caption text-slate-500 mt-1">Use a <strong>server-side</strong> Google API key (Application restrictions: None or IP addresses). Android, iOS, or website referrer keys will not work — CRM calls Places API from Laravel, not the browser.</p>' +
            '</div>' +
            '<div class="ecfg-test-row">' +
              actPrimary('Save Settings', 'type="submit" id="google-api-save-btn"', 'save') +
              actSecondary('Test Connection', 'type="button" id="google-api-test-btn"', 'plug') +
            '</div>' +
            '<div id="google-api-test-result" class="hidden text-body mt-2"></div>' +
          '</form>' +
        '</section>' +
      '</div>';
    return settingsHubLayout('google-api', content);
  }

  function rolesPermissionsPage() {
    var content =
      '<div id="roles-permissions-page" class="roles-permissions-page">' +
        settingsSubPageHero(
          'Roles & Permissions',
          'Enterprise role permission matrix. Edit one role at a time. Only Super Admin can change access.',
          '<div class="roles-perm-toolbar">' +
            '<label class="roles-perm-role-label" for="roles-perm-role-select">Role</label>' +
            '<select id="roles-perm-role-select" class="input-field roles-perm-role-select" aria-label="Select role">' +
              '<option value="manager">Manager</option>' +
              '<option value="employee">Employee</option>' +
              '<option value="admin">Admin</option>' +
            '</select>' +
            actPrimary('Save Permissions', 'id="roles-perm-save-btn"', 'save') +
            actSecondary('Reset to Default', 'id="roles-perm-reset-btn"', 'rotate-ccw') +
          '</div>'
        ) +
        '<div id="roles-perm-status" class="roles-perm-status hidden" role="status"></div>' +
        '<div id="roles-perm-stats" class="roles-perm-stats" aria-live="polite">' +
          '<div class="roles-perm-stat"><span class="roles-perm-stat-label">Roles</span><strong id="roles-perm-stat-roles">—</strong></div>' +
          '<div class="roles-perm-stat"><span class="roles-perm-stat-label">Modules</span><strong id="roles-perm-stat-modules">—</strong></div>' +
          '<div class="roles-perm-stat"><span class="roles-perm-stat-label">Permissions Enabled</span><strong id="roles-perm-stat-enabled">—</strong></div>' +
          '<div class="roles-perm-stat"><span class="roles-perm-stat-label">Permissions Disabled</span><strong id="roles-perm-stat-disabled">—</strong></div>' +
        '</div>' +
        '<div class="roles-perm-filters">' +
          '<div class="roles-perm-search-wrap">' +
            '<i data-lucide="search" class="h-4 w-4 roles-perm-search-icon" aria-hidden="true"></i>' +
            '<input type="search" id="roles-perm-search" class="input-field roles-perm-search" placeholder="Search modules…" autocomplete="off" aria-label="Search modules" />' +
          '</div>' +
          '<p class="roles-perm-filter-hint text-caption text-slate-500">One row per module · toggles save with Save Permissions</p>' +
        '</div>' +
        '<section class="card p-0 overflow-hidden roles-perm-matrix-card">' +
          '<div class="roles-perm-matrix-wrap scrollbar-thin" id="roles-perm-matrix-scroll">' +
            '<table class="ca-table roles-perm-matrix" id="roles-perm-matrix-table">' +
              '<thead id="roles-perm-matrix-head"><tr><th>Module</th></tr></thead>' +
              '<tbody id="roles-perm-matrix-body"><tr><td class="text-center text-slate-500 p-6">Loading permissions…</td></tr></tbody>' +
            '</table>' +
          '</div>' +
          '<div id="roles-perm-mobile" class="roles-perm-mobile" aria-live="polite"></div>' +
        '</section>' +
        '<p class="text-caption text-slate-500 mt-3" id="roles-perm-note">Super Admin always has full access and cannot be edited here.</p>' +
      '</div>';
    return settingsHubLayout('roles', content);
  }

  /* ─── Settings ─── */
  function settingsPage() {
    var content = hdr('Settings', 'Configure assignment rules, filters, and integrations.', null,
      actPrimary('Save Settings', 'id="settings-save-btn"', 'save')) +
      tabs([{ id: 'general', label: 'General' }, { id: 'assignment', label: 'Assignment Rules' }, { id: 'filters', label: 'Filter Preferences' }, { id: 'integrations', label: 'Integrations' }], 'general') +
      panel('general', true,
        '<div class="grid lg:grid-cols-2 gap-4">' +
          '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Company Name</label><input id="settings-company-name" class="input-field" value="CA Cloud Desk" /></div>' +
          '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Timezone</label><input id="settings-timezone" class="input-field" value="Asia/Kolkata" /></div>' +
          '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Date Format</label><input id="settings-date-format" class="input-field" value="DD/MM/YYYY" /></div>' +
          '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Default City</label><input id="settings-default-city" class="input-field" value="Mumbai" /></div>' +
        '</div>') +
      panel('assignment', false,
        '<div class="space-y-4">' +
          '<label class="flex items-center justify-between p-4 card"><span class="text-body font-medium">Enable Auto Assignment</span><label class="ca-toggle"><input type="checkbox" id="settings-auto-assignment" checked><span class="ca-toggle-slider"></span></label></label>' +
          '<label class="flex items-center justify-between p-4 card"><span class="text-body font-medium">Hot Lead Priority</span><label class="ca-toggle"><input type="checkbox" id="settings-hot-lead-priority" checked><span class="ca-toggle-slider"></span></label></label>' +
          '<label class="flex items-center justify-between p-4 card"><span class="text-body font-medium">Workload Balancing</span><label class="ca-toggle"><input type="checkbox" id="settings-workload-balancing" checked><span class="ca-toggle-slider"></span></label></label>' +
          '<label class="flex items-center justify-between p-4 card"><span class="text-body font-medium">City-based Routing</span><label class="ca-toggle"><input type="checkbox" id="settings-city-routing" checked><span class="ca-toggle-slider"></span></label></label>' +
          '<div class="card p-4" id="settings-daily-capacity-wrap">' +
            '<label class="text-caption font-medium text-slate-600 mb-1.5 block" for="settings-daily-max-capacity">Maximum Daily Assignment Capacity</label>' +
            '<p class="text-caption text-slate-500 mb-3">Maximum leads each employee can receive per day before automatic assignment stops.</p>' +
            '<input type="number" min="1" max="500" step="1" id="settings-daily-max-capacity" class="input-field" value="50" />' +
          '</div>' +
        '</div>') +
      panel('filters', false,
        '<p class="text-caption text-slate-500 mb-4">Default filter preferences for new users</p>' +
        '<div class="grid lg:grid-cols-2 gap-4">' +
          '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">City</label><select class="input-field"><option>All</option><option>Mumbai</option></select></div>' +
          '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Team Size Min</label><input type="number" class="input-field" value="6" /></div>' +
          '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Team Size Max</label><input type="number" class="input-field" value="15" /></div>' +
          '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Existing Software</label><select class="input-field"><option>Any</option><option>Tally</option><option>Zoho</option></select></div>' +
          '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Rating Min</label><select class="input-field"><option>4+</option><option>3+</option></select></div>' +
          '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Newly Established</label><select class="input-field"><option>Any</option><option>Yes</option><option>No</option></select></div>' +
        '</div>') +
      panel('integrations', false,
        '<div class="grid gap-4" id="integration-cards">' +
          [{ n: 'Email SMTP', s: 'Configure in Settings', i: 'mail', badge: 'badge-neutral' }, { n: 'Cashfree Payments', s: 'Not configured', i: 'credit-card', badge: 'badge-neutral' }].map(function (x) {
            return '<div class="card p-4 flex items-center justify-between"><div class="flex items-center gap-3"><i data-lucide="' + x.i + '" class="h-5 w-5 text-brand"></i><span class="text-card-heading">' + x.n + '</span></div><span class="' + x.badge + '">' + x.s + '</span></div>';
          }).join('') +
          '<button type="button" class="card p-4 flex items-center justify-between w-full text-left integration-card hover:border-brand/40 transition-colors cursor-pointer" data-open-integration="whatsapp-cloud" id="whatsapp-integration-card">' +
            '<div class="flex items-center gap-3"><i data-lucide="message-circle" class="h-5 w-5 text-brand"></i><div><span class="text-card-heading block">WhatsApp API</span><span class="text-caption text-slate-500">Meta WhatsApp Cloud API · Live send & delivery logs</span></div></div>' +
            '<span class="badge-neutral" id="whatsapp-integration-status-badge">Not Configured</span>' +
          '</button>' +
          '<button type="button" class="card p-4 flex items-center justify-between w-full text-left integration-card hover:border-brand/40 transition-colors" data-open-integration="sms-alert" id="sms-integration-card">' +
            '<div class="flex items-center gap-3"><i data-lucide="smartphone" class="h-5 w-5 text-brand"></i><div><span class="text-card-heading block">SMS Alert</span><span class="text-caption text-slate-500">SMS Alert push API mapping</span></div></div>' +
            '<span class="badge-neutral" id="sms-integration-status-badge">Not Configured</span>' +
          '</button>' +
          '<div class="hidden card p-0 border border-brand/20 overflow-hidden flex flex-col sms-settings-panel" id="sms-settings-panel">' +
            '<div class="p-4 pb-0 space-y-4 sms-settings-panel-body">' +
            '<div class="flex items-center justify-between gap-3">' +
              '<div class="flex items-center gap-3"><i data-lucide="smartphone" class="h-5 w-5 text-brand"></i><span class="text-card-heading">SMS Alert Settings</span><span class="badge-neutral" id="sms-settings-mode-badge">Simulation</span></div>' +
              '<button type="button" class="btn-secondary btn-sm" id="sms-settings-close-btn" aria-label="Close SMS settings"><i data-lucide="x" class="h-4 w-4"></i></button>' +
            '</div>' +
            '<p class="text-caption text-slate-500">Mapping-only configuration for SMS Alert push API. No SMS is sent until Live mode and credentials are configured.</p>' +
            '<div id="sms-settings-error-box" class="hidden rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert"></div>' +
            '<div class="grid lg:grid-cols-2 gap-4">' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Provider Name</label><input id="sms-settings-provider-name" class="input-field" value="SMS Alert" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Mode</label><select id="sms-settings-mode" class="input-field"><option value="simulation">Simulation</option><option value="live">Live</option></select></div>' +
              '<div class="lg:col-span-2"><label class="text-caption font-medium text-slate-600 mb-1.5 block">API URL</label><input id="sms-settings-api-url" class="input-field" value="https://www.smsalert.co.in/api/push.json" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">API Key</label><input id="sms-settings-api-key" class="input-field" type="password" placeholder="To be provided by manager" autocomplete="off" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Sender ID</label><input id="sms-settings-sender-id" class="input-field" placeholder="To be provided by manager" autocomplete="off" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">DLT Template ID</label><input id="sms-settings-dlt-template-id" class="input-field" placeholder="Required for Live mode" autocomplete="off" /></div>' +
              '<div class="flex items-center gap-3 pt-6"><input type="checkbox" id="sms-settings-is-active" class="rounded border-slate-300" checked /><label for="sms-settings-is-active" class="text-caption font-medium text-slate-600">Provider Active</label></div>' +
            '</div>' +
            '<p class="text-caption text-slate-400" id="sms-settings-api-key-note">API key is encrypted at rest and never returned by the API.</p>' +
            '<div class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">' +
              '<p class="text-caption font-medium text-slate-600">Test SMS Alert Connection</p>' +
              '<p class="text-caption text-slate-500">Sends a live request to SMS Alert. Validate Configuration above checks fields only.</p>' +
              '<div class="grid lg:grid-cols-2 gap-4">' +
                '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Test Mobile Number</label><input id="sms-settings-test-mobile" class="input-field" placeholder="9876543210" autocomplete="off" /></div>' +
                '<div class="lg:col-span-2"><label class="text-caption font-medium text-slate-600 mb-1.5 block">Test Message</label><input id="sms-settings-test-message" class="input-field" value="CRM SMS Alert connection test" autocomplete="off" /></div>' +
              '</div>' +
              '<p class="text-caption text-slate-400 hidden" id="sms-settings-last-test-note"></p>' +
            '</div>' +
            '</div>' +
            '<div class="sms-settings-actions">' +
              '<div class="ca-modal-footer-buttons">' +
              actPrimary('Save Settings', 'id="sms-settings-save-btn"', 'save') +
              actSecondary('Validate Configuration', 'id="sms-settings-test-btn"', 'shield-check') +
              actSecondary('Test SMS Connection', 'id="sms-settings-test-connection-btn"', 'plug') +
              actSecondary('Reset', 'id="sms-settings-reset-btn"', 'rotate-ccw') +
              '<button type="button" class="btn-secondary" id="sms-settings-cancel-btn">Cancel</button>' +
              '</div>' +
            '</div>' +
          '</div>' +
          '<div class="hidden card p-0 border border-brand/20 overflow-hidden flex flex-col whatsapp-settings-panel" id="whatsapp-settings-panel">' +
            '<div class="p-4 pb-0 space-y-4">' +
              '<div class="flex items-center justify-between gap-3">' +
                '<div class="flex items-center gap-3"><i data-lucide="message-circle" class="h-5 w-5 text-brand"></i><span class="text-card-heading">Meta WhatsApp Cloud API</span><span class="badge-neutral" id="whatsapp-settings-mode-badge">Simulation</span></div>' +
                '<button type="button" class="btn-secondary btn-sm" id="whatsapp-settings-close-btn" aria-label="Close WhatsApp settings"><i data-lucide="x" class="h-4 w-4"></i></button>' +
              '</div>' +
              '<p class="text-caption text-slate-500">Meta WhatsApp Cloud API integration. Messages send only in Live mode after a successful connection test.</p>' +
              '<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3" id="whatsapp-connection-dashboard">' +
                '<div class="rounded-lg border border-slate-200 p-3"><p class="text-caption text-slate-500">Connection</p><p class="text-sm font-medium" id="whatsapp-dash-connection"><span class="badge-neutral">—</span></p></div>' +
                '<div class="rounded-lg border border-slate-200 p-3"><p class="text-caption text-slate-500">Webhook</p><p class="text-sm font-medium" id="whatsapp-dash-webhook"><span class="badge-neutral">—</span></p></div>' +
                '<div class="rounded-lg border border-slate-200 p-3"><p class="text-caption text-slate-500">API</p><p class="text-sm font-medium" id="whatsapp-dash-api"><span class="badge-neutral">—</span></p></div>' +
                '<div class="rounded-lg border border-slate-200 p-3"><p class="text-caption text-slate-500">Token</p><p class="text-sm font-medium" id="whatsapp-dash-token"><span class="badge-neutral">—</span></p></div>' +
                '<div class="rounded-lg border border-slate-200 p-3"><p class="text-caption text-slate-500">Approved Templates</p><p class="text-sm font-medium" id="whatsapp-dash-templates">—</p></div>' +
                '<div class="rounded-lg border border-slate-200 p-3"><p class="text-caption text-slate-500">Last Sync</p><p class="text-sm font-medium" id="whatsapp-dash-last-sync">—</p></div>' +
                '<div class="rounded-lg border border-slate-200 p-3 lg:col-span-3"><p class="text-caption text-slate-500">Callback URL</p><p class="text-sm font-mono break-all" id="whatsapp-dash-callback">—</p></div>' +
              '</div>' +
              '<p class="text-caption text-slate-400" id="whatsapp-settings-status-summary"></p>' +
              '<div id="whatsapp-settings-error-box" class="hidden rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert"></div>' +
              '<div class="grid lg:grid-cols-2 gap-4">' +
                '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Provider Name</label><input id="whatsapp-settings-provider-name" class="input-field" value="Meta WhatsApp Cloud API" /></div>' +
                '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Mode</label><select id="whatsapp-settings-mode" class="input-field"><option value="simulation">Simulation</option><option value="live">Live</option></select></div>' +
                '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Phone Number ID</label><input id="whatsapp-settings-phone-number-id" class="input-field" placeholder="From Meta Business Manager" autocomplete="off" /></div>' +
                '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Business Account ID</label><input id="whatsapp-settings-business-account-id" class="input-field" placeholder="From Meta Business Manager" autocomplete="off" /></div>' +
                '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">API Version</label><input id="whatsapp-settings-api-version" class="input-field" value="v23.0" /></div>' +
                '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Permanent Access Token</label><input id="whatsapp-settings-access-token" class="input-field" type="password" placeholder="Encrypted at rest" autocomplete="off" /></div>' +
                '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Webhook Verify Token</label><input id="whatsapp-settings-webhook-verify-token" class="input-field" type="password" placeholder="Optional — for Meta webhook verification" autocomplete="off" /></div>' +
                '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Test Mobile Number</label><input id="whatsapp-settings-test-mobile" class="input-field" placeholder="9876543210" autocomplete="off" /></div>' +
                '<div class="flex items-center gap-3 pt-6"><input type="checkbox" id="whatsapp-settings-is-active" class="rounded border-slate-300" checked /><label for="whatsapp-settings-is-active" class="text-caption font-medium text-slate-600">Provider Active</label></div>' +
              '</div>' +
              '<p class="text-caption text-slate-400" id="whatsapp-settings-token-note">Access token is encrypted at rest and never returned by the API.</p>' +
              '<p class="text-caption text-slate-400" id="whatsapp-settings-webhook-note">Optional — used when Meta verifies your webhook subscription.</p>' +
              '<div class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">' +
                '<p class="text-caption font-medium text-slate-600">Test Connection & Template</p>' +
                '<p class="text-caption text-slate-500">Test Connection verifies credentials without sending a customer message. Send Test Template delivers one approved template to the test mobile (Live mode only).</p>' +
                '<div class="grid lg:grid-cols-2 gap-4">' +
                  '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Test Template</label><select id="whatsapp-settings-test-template-id" class="input-field"><option value="">Select approved template</option></select></div>' +
                '</div>' +
                '<p class="text-caption text-slate-400 hidden" id="whatsapp-settings-last-test-note"></p>' +
              '</div>' +
            '</div>' +
            '<div class="sms-settings-actions">' +
              '<div class="ca-modal-footer-buttons">' +
              actPrimary('Save Settings', 'id="whatsapp-settings-save-btn"', 'save') +
              actSecondary('Validate Configuration', 'id="whatsapp-settings-validate-btn"', 'shield-check') +
              actSecondary('Test Connection', 'id="whatsapp-settings-test-connection-btn"', 'plug') +
              actSecondary('Send Test Template', 'id="whatsapp-settings-send-test-template-btn"', 'send') +
              actSecondary('Reset', 'id="whatsapp-settings-reset-btn"', 'rotate-ccw') +
              '<button type="button" class="btn-secondary" id="whatsapp-settings-cancel-btn">Cancel</button>' +
              '</div>' +
            '</div>' +
          '</div>' +
          '<div class="card p-4 space-y-4">' +
            '<div class="flex items-center gap-3"><i data-lucide="mail" class="h-5 w-5 text-brand"></i><span class="text-card-heading">SMTP Email</span><span class="badge-neutral" id="email-settings-mode-badge">Simulation</span></div>' +
            '<p class="text-caption text-slate-500" id="email-settings-status-summary">Configure GoDaddy / cloud desk SMTP for live campaign delivery.</p>' +
            '<div id="email-settings-error-box" class="hidden rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"></div>' +
            '<div class="grid lg:grid-cols-2 gap-4">' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Provider Name</label><input id="email-settings-provider-name" class="input-field" value="cloud desk" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Mode</label><select id="email-settings-mode" class="input-field"><option value="simulation">Simulation</option><option value="live">Live</option></select></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">SMTP Host</label><input id="email-settings-smtp-host" class="input-field" value="smtpout.secureserver.net" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">SMTP Port</label><input id="email-settings-smtp-port" class="input-field" type="number" value="465" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Username</label><input id="email-settings-smtp-username" class="input-field" value="CRM Email" autocomplete="off" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Password</label><input id="email-settings-smtp-password" class="input-field" type="password" placeholder="Leave blank to keep current password" autocomplete="new-password" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Encryption</label><select id="email-settings-smtp-encryption" class="input-field"><option value="ssl">SSL</option><option value="tls" selected>TLS</option><option value="starttls">STARTTLS</option></select></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">From Email</label><input id="email-settings-from-email" class="input-field" value="cacloud12@gmail.com" autocomplete="off" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Reply-To Email</label><input id="email-settings-reply-to-email" class="input-field" value="cacloud12@gmail.com" autocomplete="off" /></div>' +
              '<div class="lg:col-span-2"><label class="text-caption font-medium text-slate-600 mb-1.5 block">From Name</label><input id="email-settings-from-name" class="input-field" value="CA Cloud Desk" autocomplete="off" /></div>' +
              '<div class="lg:col-span-2"><label class="text-caption font-medium text-slate-600 mb-1.5 block">Test Recipient Email</label><input id="email-settings-test-recipient" class="input-field" type="email" placeholder="you@company.com" autocomplete="off" /></div>' +
            '</div>' +
            '<p class="text-caption text-slate-400" id="email-settings-password-note">SMTP password is encrypted at rest and never returned by the API.</p>' +
            '<div class="flex flex-wrap gap-2 items-center">' +
              actPrimary('Save Email Settings', 'id="email-settings-save-btn"', 'save') +
              actSecondary('Validate Configuration', 'id="email-settings-validate-btn"', 'shield-check') +
              actSecondary('Send Test Email', 'id="email-settings-send-test-btn"', 'send') +
            '</div>' +
          '</div>' +
        '</div>');
    return settingsHubLayout('general', content);
  }

  function recycleBinPage() {
    return hdr('Recycle Bin', 'Restore or permanently delete soft-deleted leads.', null, '') +
      '<div class="card p-4 mb-4">' +
        '<div class="flex flex-wrap items-center justify-between gap-3 mb-3">' +
          '<div>' +
            '<h2 class="text-card-heading">Deleted Leads</h2>' +
            '<p class="text-caption text-slate-500" id="recycle-bin-count">0 items</p>' +
          '</div>' +
          '<div class="flex flex-wrap gap-2">' +
            '<button type="button" class="btn-secondary btn-sm" id="recycle-bin-refresh"><i data-lucide="refresh-cw" class="h-4 w-4"></i> Refresh</button>' +
            '<button type="button" class="btn-secondary btn-sm" id="recycle-bin-restore-selected"><i data-lucide="rotate-ccw" class="h-4 w-4"></i> Restore Selected</button>' +
            '<button type="button" class="btn-primary btn-sm bg-rose-600 hover:bg-rose-700 border-rose-600" id="recycle-bin-delete-selected"><i data-lucide="trash-2" class="h-4 w-4"></i> Delete Forever</button>' +
          '</div>' +
        '</div>' +
        '<div class="overflow-x-auto">' +
          '<table class="ca-table w-full">' +
            '<thead><tr>' +
              '<th><input type="checkbox" id="recycle-bin-select-all" aria-label="Select all" /></th>' +
              '<th>Firm</th><th>Contact</th><th>Mobile</th><th>City</th><th>Status</th><th>Deleted</th><th>Actions</th>' +
            '</tr></thead>' +
            '<tbody id="recycle-bin-table"></tbody>' +
          '</table>' +
        '</div>' +
      '</div>';
  }

  const pages = {
    dashboard: {
      title: 'Dashboard', breadcrumb: 'Dashboard', er: 'ADMIN_DASHBOARD_METRICS',
      html: dashboardPage(),
    },
    'demo-calendar': {
      title: 'Demo Management Calendar', breadcrumb: 'Dashboard / Demo Management', er: 'DEMO_CALENDAR',
      html: demoCalendarPage(),
    },
    'ca-master': { title: 'Master Data', breadcrumb: 'Master Data', er: 'CA_MASTER', html: caMasterPage('all') },
    'recycle-bin': { title: 'Recycle Bin', breadcrumb: 'Recycle Bin', er: 'CA_MASTER', html: recycleBinPage() },
    leads: { title: 'Lead Management', breadcrumb: 'Leads', er: 'LEAD_ACTION', html: leadsPage() },
    'sales-list': { title: 'Sales List', breadcrumb: 'Sales List', er: 'SALES_LIST', html: salesListPage() },
    'leads-segments': { title: 'Lead Management', breadcrumb: 'Leads', er: 'LEAD_ACTION', html: leadsPage() },
    assignment: { title: 'Assignment', breadcrumb: 'Assignment', er: 'LEAD_ASSIGNMENT_ENGINE', html: assignmentPage('assign') },
    followups: { title: 'Follow-ups', breadcrumb: 'Follow-ups', er: 'FOLLOW_UP_MANAGEMENT', html: followupsPage() },
    communication: { title: 'Communication', breadcrumb: 'Communication', er: 'COMMUNICATION_MODULE', html: communicationPage() },
    'consent-dnd': { title: 'Consent & DND', breadcrumb: 'Communication / Consent & DND', er: 'CONSENT_DND', html: consentDndPage() },
    whatsapp: { title: 'Chat', breadcrumb: 'Communication / Chat', er: 'WHATSAPP_CAMPAIGN', html: whatsappPage() },
    email: { title: 'Email', breadcrumb: 'Communication / Email', er: 'EMAIL_CAMPAIGN', html: emailPage() },
    campaigns: { title: 'Campaigns', breadcrumb: 'Communication / Campaigns', er: 'CAMPAIGN_MANAGEMENT', html: campaignsPage() },
    sms: { title: 'SMS', breadcrumb: 'Communication / SMS', er: 'SMS_CAMPAIGN', html: smsPage() },
    notifications: { title: 'Notifications', breadcrumb: 'Communication / Notifications', er: 'NOTIFICATION_MODULE', html: notificationsPage() },
    reception: { title: 'Reception Hub', breadcrumb: 'Communication / Reception Hub', er: 'RECEPTION_HUB', html: receptionPage() },
    reports: { title: 'Reports', breadcrumb: 'Reports', er: 'Reports', html: reportsHubPage('reports') },
    analytics: { title: 'Analytics', breadcrumb: 'Reports / Analytics', er: 'ADMIN_DASHBOARD_METRICS', html: reportsHubPage('analytics') },
    activity: { title: 'Activity Logs', breadcrumb: 'Reports / Activity', er: 'ACTIVITY_LOGS', html: reportsHubPage('activity') },
    audit: { title: 'Audit Logs', breadcrumb: 'Reports / Audit', er: 'ACTIVITY_LOGS', html: reportsHubPage('audit') },
    'duplicate-attempts': { title: 'Duplicate Attempts', breadcrumb: 'Reports / Duplicate Attempts', er: 'CA_MASTER', html: duplicateAttemptsPage() },
    bulk: { title: 'Bulk Operations', breadcrumb: 'Master Data / Bulk', er: 'BULK_ACTIONS', html: caMasterPage('bulk') },
    employees: { title: 'Team', breadcrumb: 'Assignment / Team', er: 'EMPLOYEE_MASTER', html: assignmentPage('team') },
    security: { title: 'Security', breadcrumb: 'Security', er: 'Security Module', html: securityPage() },
    queue: { title: 'System Health', breadcrumb: 'Queue', er: 'QUEUE_SYSTEM', html: queuePage() },
    'db-health': { title: 'Database Health', breadcrumb: 'Admin / Database Health', er: 'DEV_DB_HEALTH', html: dbHealthPage() },
    'email-configuration': { title: 'Settings — Email Configuration', breadcrumb: 'Settings / Email Configuration', er: 'ENTERPRISE_EMAIL', html: emailConfigurationPage() },
    'roles-permissions': { title: 'Settings — Roles & Permissions', breadcrumb: 'Settings / Roles & Permissions', er: 'RBAC', html: rolesPermissionsPage() },
    'settings-email-templates': { title: 'Settings — Email Templates', breadcrumb: 'Settings / Email Templates', er: 'Configuration', html: emailTemplatesPage() },
    'settings-whatsapp-templates': { title: 'Settings — WhatsApp Templates', breadcrumb: 'Settings / WhatsApp Templates', er: 'Configuration', html: whatsAppTemplatesPage() },
    'settings-google-api': { title: 'Settings — Google API Settings', breadcrumb: 'Settings / Google API Settings', er: 'Configuration', html: googleApiSettingsPage() },
    'settings-demo-providers': { title: 'Settings — Demo Providers', breadcrumb: 'Settings / Demo Providers', er: 'Configuration', html: demoProvidersSettingsPage() },
    settings: { title: 'Settings', breadcrumb: 'Settings', er: 'Configuration', html: settingsPage() },
  };

  return {
    get: function (id) {
      var u = window.__CRM_USER__ || {};
      if (id === 'dashboard' && u.role === 'employee') {
        return {
          title: 'My Dashboard',
          breadcrumb: 'My Work',
          er: 'EMPLOYEE_DASHBOARD',
          html: employeeDashboardPage(),
        };
      }
      if (id === 'duplicate-attempts' && u.role === 'employee') {
        return pages.dashboard;
      }
      if (id === 'sales-list' && u.role !== 'super_admin' && u.role !== 'manager') {
        return pages.dashboard;
      }
      if (id === 'roles-permissions' && u.role !== 'super_admin') {
        return pages.dashboard;
      }
      if ((id === 'leads' || id === 'leads-segments') && u.role !== 'employee') {
        return pages['ca-master'];
      }
      return pages[id] || pages.dashboard;
    },
    employeeDashboardPage: employeeDashboardPage,
    ids: function () { return Object.keys(pages); },
    all: pages,
  };
})();
