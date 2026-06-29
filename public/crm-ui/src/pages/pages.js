/* CA Cloud Desk — CRM page templates */
window.CAPages = (function () {
  'use strict';

  function actExport(label, exportKey) {
    label = label || 'Export';
    exportKey = exportKey || label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    return '<button type="button" class="btn-secondary" data-action="export" data-export="' + exportKey + '"><i data-lucide="download" class="h-4 w-4"></i> ' + label + '</button>';
  }

  function actPrimary(label, attrs) {
    return '<button type="button" class="btn-primary" ' + (attrs || '') + '><i data-lucide="plus" class="h-4 w-4"></i> ' + label + '</button>';
  }

  function actSecondary(label, attrs) {
    return '<button type="button" class="btn-secondary" ' + (attrs || '') + '>' + label + '</button>';
  }

  function hdr(title, sub, er, actions) {
    var actionsHtml = actions
      ? '<div class="flex flex-wrap gap-2 shrink-0">' + actions + '</div>'
      : '';
    var subHtml = sub
      ? '<p class="text-body text-slate-500 mt-1">' + sub + '</p>'
      : '';
    return '<div class="page-hero card mb-6 border-0 p-6 lg:p-8">' +
      '<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">' +
        '<div><h1 class="text-page-title text-slate-900">' + title + '</h1>' +
        subHtml + '</div>' +
        actionsHtml +
      '</div></div>';
  }

  function tabs(items, active, group) {
    group = group || 'main';
    return '<div class="ca-tabs mb-4" data-tab-group="' + group + '">' + items.map(function (t) {
      return '<button class="ca-tab' + (t.id === active ? ' active' : '') + '" data-tab="' + t.id + '" data-tab-group="' + group + '">' +
        (t.icon ? '<i data-lucide="' + t.icon + '" class="h-4 w-4"></i>' : '') + t.label +
        (t.count !== undefined ? '<span class="ca-tab-count"' + (t.countId ? ' id="' + t.countId + '"' : '') + '>' + t.count + '</span>' : '') + '</button>';
    }).join('') + '</div>';
  }

  function panel(id, active, html, group) {
    group = group || 'main';
    return '<div class="ca-tab-panel' + (active ? ' active' : '') + '" data-panel="' + id + '" data-tab-group="' + group + '">' + html + '</div>';
  }

  function employeeDashboardPage() {
    return '<div class="emp-dashboard">' +
      '<header class="emp-top card" id="emp-top-header"></header>' +
      '<div class="emp-kpi-grid" id="emp-kpi-grid"></div>' +
      '<section class="card p-4 mb-4"><div class="emp-panel-head"><h3 class="emp-panel-title">Today\'s Work</h3></div><div class="emp-today-grid" id="emp-today-grid"></div></section>' +
      '<div class="emp-grid-2 mb-4">' +
        '<section class="card p-4"><div class="emp-panel-head"><h3 class="emp-panel-title">My Assigned Leads</h3><button type="button" class="mgr-link-btn" data-emp-nav="leads">Open My Leads</button></div><div id="emp-assigned-leads" class="emp-list"></div></section>' +
        '<section class="card p-4"><div class="emp-panel-head"><h3 class="emp-panel-title">My Follow-ups</h3><button type="button" class="mgr-link-btn" data-emp-nav="followups">View All</button></div><div id="emp-followups-tabs" class="emp-tabs"></div><div id="emp-followups-list" class="emp-list"></div></section>' +
      '</div>' +
      '<div class="emp-grid-2 mb-4">' +
        '<section class="card p-4"><div class="emp-panel-head"><h3 class="emp-panel-title">My Calendar</h3><button type="button" class="mgr-link-btn" data-emp-nav="followups">Open Calendar</button></div><div id="emp-calendar-list" class="emp-list"></div></section>' +
        '<section class="card p-4"><div class="emp-panel-head"><h3 class="emp-panel-title">Quick Actions</h3></div><div class="emp-quick-actions" id="emp-quick-actions"></div></section>' +
      '</div>' +
      '<section class="card p-4"><div class="emp-panel-head"><h3 class="emp-panel-title">My Recent Activity</h3></div><div id="emp-activity-list" class="mgr-activity-feed"></div></section>' +
    '</div>';
  }

  function dashboardPage() {
    return '<div class="mgr-dashboard">' +
      '<header class="mgr-top card" id="mgr-top-header"></header>' +
      '<div class="mgr-kpi-grid dash-kpi-grid" id="mgr-kpi-grid"></div>' +
      '<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4" id="dash-sms-widgets">' +
        '<div class="card p-4"><p class="text-caption text-slate-500">SMS Mapped Campaigns</p><p class="text-xl font-bold text-slate-900 mt-1" id="dash-sms-mapped">—</p></div>' +
        '<div class="card p-4"><p class="text-caption text-slate-500">SMS Pending Campaigns</p><p class="text-xl font-bold text-slate-900 mt-1" id="dash-sms-pending">—</p></div>' +
        '<div class="card p-4"><p class="text-caption text-slate-500">SMS Simulation Mode</p><p class="text-xl font-bold text-slate-900 mt-1" id="dash-sms-simulation">—</p></div>' +
        '<div class="card p-4"><p class="text-caption text-slate-500">SMS Live Mode</p><p class="text-xl font-bold text-slate-900 mt-1" id="dash-sms-live">—</p></div>' +
      '</div>' +
      '<div class="dash-filter-chips" id="dash-filter-chips"></div>' +
      '<div class="dash-charts-grid mb-4">' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title">Lead Source Distribution</h3></div><div id="dash-chart-source" class="mgr-bar-chart"></div></section>' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title">Lead Status Distribution</h3></div><div id="dash-chart-status" class="mgr-bar-chart"></div></section>' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title">Monthly Leads</h3></div><div class="ca-chart h-44" data-chart="monthly"></div></section>' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title">Employee Performance</h3></div><div class="ca-chart h-44" data-chart="employee"></div></section>' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title">Campaign Performance</h3></div><div id="dash-chart-campaign" class="mgr-bar-chart"></div></section>' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title">City Performance</h3></div><div id="dash-chart-city" class="mgr-bar-chart"></div></section>' +
      '</div>' +
      '<div class="mgr-grid-3 mb-4">' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="git-branch" class="h-5 w-5 text-brand"></i> Sales Pipeline</h3><button type="button" class="mgr-link-btn" data-nav-page="leads">View all</button></div><div id="mgr-pipeline-funnel" class="mgr-pipeline"></div></section>' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="flame" class="h-5 w-5 text-amber-500"></i> Priority Today</h3><button type="button" class="mgr-link-btn" data-nav-page="followups">Follow-ups</button></div><div id="mgr-priority-list" class="mgr-priority-list"></div></section>' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="users" class="h-5 w-5 text-brand"></i> Team Snapshot</h3><button type="button" class="mgr-link-btn" data-nav-page="employees">Manage team</button></div><div id="mgr-team-cards" class="mgr-team-scroll"></div></section>' +
      '</div>' +
      '<div class="mgr-grid-2 mb-4">' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title">Team Performance</h3><button type="button" class="mgr-link-btn" data-nav-page="reports">Reports</button></div><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full mgr-table"><thead><tr><th>Executive</th><th>City</th><th>Leads</th><th>Target</th><th>Calls</th><th>Demos</th></tr></thead><tbody id="team-overview-table"></tbody></table></div></section>' +
        '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title">Recent Leads</h3><button type="button" class="mgr-link-btn" data-nav-page="leads">View all</button></div><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full mgr-table"><thead><tr><th>Firm</th><th>Status</th><th>Executive</th><th>Updated</th></tr></thead><tbody id="dashboard-leads-table"></tbody></table></div></section>' +
      '</div>' +
      '<section id="mgr-followup-automation-panel" class="mgr-panel card mb-4">' +
        '<div class="mgr-panel-head"><h3 class="mgr-panel-title"><i data-lucide="calendar-clock" class="h-5 w-5 text-brand"></i> Follow-up Automation</h3><button type="button" class="mgr-link-btn" data-nav-page="followups">Open follow-ups</button></div>' +
        '<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-4">' +
          '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">Today</span><strong id="mgr-fu-today">—</strong></div>' +
          '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">Upcoming</span><strong id="mgr-fu-upcoming">—</strong></div>' +
          '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">Completed Today</span><strong id="mgr-fu-completed-today">—</strong></div>' +
          '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">Missed</span><strong id="mgr-fu-missed">—</strong></div>' +
          '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">Overdue</span><strong id="mgr-fu-overdue" class="text-rose-600">—</strong></div>' +
          '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">Follow-up Conv.</span><strong id="mgr-fu-conversion">—</strong></div>' +
          '<div class="mgr-fu-stat"><span class="text-caption text-slate-500">Demo Conv.</span><strong id="mgr-fu-demo-conversion">—</strong></div>' +
        '</div>' +
        '<div id="mgr-fu-employee-list" class="mgr-fu-employee-list"></div>' +
      '</section>' +
      '<section class="mgr-panel card"><div class="mgr-panel-head"><h3 class="mgr-panel-title">Activity Feed</h3><button type="button" class="mgr-link-btn" data-nav-page="activity">View all</button></div><div id="recent-activity-list" class="mgr-activity-feed"></div></section>' +
    '</div>';
  }

  function kpis(items) {
    return '<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 mb-6">' +
      items.map(function (k) {
        return '<div class="card-interactive p-4" data-kpi="' + k.label + '"><div class="flex justify-between mb-2">' +
          '<div class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand"><i data-lucide="' + k.icon + '" class="h-5 w-5"></i></div>' +
          '<span class="stat-pill bg-emerald-50 text-emerald-700">' + k.trend + '</span></div>' +
          '<p class="text-caption text-slate-500">' + k.label + '</p>' +
          '<p class="text-xl font-bold text-slate-900 mt-1"' + (k.valueId ? ' id="' + k.valueId + '"' : '') + '>' + k.value + '</p></div>';
      }).join('') + '</div>';
  }

  function table(cols, rows, opts) {
    opts = opts || {};
    var cls = opts.cls || '';
    var tbodyId = opts.tbodyId ? ' id="' + opts.tbodyId + '"' : '';
    var body = rows.length ? rows.map(function (r, i) {
      var data = opts.rowData && opts.rowData[i] ? ' data-row=\'' + JSON.stringify(opts.rowData[i]).replace(/'/g, '&#39;') + '\'' : '';
      return '<tr class="ca-table-row"' + data + '>' + r.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>';
    }).join('') : '';
    return '<div class="card overflow-hidden ' + cls + '"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full">' +
      '<thead><tr>' + cols.map(function (c) { return '<th>' + c + '</th>'; }).join('') + '</tr></thead>' +
      '<tbody' + tbodyId + '>' + body + '</tbody></table></div></div>';
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
  function caMasterPage(activeTab) {
    activeTab = activeTab || 'all';
    var cols = ['Firm', 'Lead Name', 'Mobile', 'Alternate Mobile', 'Email', 'GST No.', 'State', 'City', 'Team', 'Software', 'Website', 'Rating', 'New Firm?', 'Status', 'Source', 'Created', 'Updated'];
    var masterToolbar = function (entity, label) {
      return '<div class="flex justify-end mb-3"><button type="button" class="btn-primary btn-sm" data-master-add="' + entity + '"><i data-lucide="plus" class="h-4 w-4"></i> Add ' + label + '</button></div>';
    };
    var masterPanels =
      tabs([{ id: 'state', label: 'States' }, { id: 'city', label: 'Cities' }, { id: 'source', label: 'Lead Sources' }, { id: 'team', label: 'Team Sizes' }, { id: 'roles', label: 'Roles' }], 'state', 'masters') +
      panel('state', true,
        masterToolbar('state', 'State') +
        table(['State Name', 'Cities', 'Created', 'Actions'], [], { tbodyId: 'master-states-table' }), 'masters') +
      panel('city', false,
        masterToolbar('city', 'City') +
        table(['City Name', 'State', 'Leads', 'Created', 'Actions'], [], { tbodyId: 'master-cities-table' }), 'masters') +
      panel('source', false,
        masterToolbar('source', 'Source') +
        table(['Source Name', 'Description', 'Leads', 'Actions'], [], { tbodyId: 'master-sources-table' }), 'masters') +
      panel('team', false,
        masterToolbar('team', 'Team Size') +
        table(['Min', 'Max', 'Label', 'Firms', 'Actions'], [], { tbodyId: 'master-team-sizes-table' }), 'masters') +
      panel('roles', false,
        masterToolbar('role', 'Role') +
        table(['Role Name', 'Description', 'Actions'], [], { tbodyId: 'master-roles-table' }), 'masters');

    return hdr('CA Master', 'Manage firms, reference data, and bulk operations.', null,
      actExport('Export Firms', 'firms') + actPrimary('Add Firm', 'data-open-modal="add-lead"')) +
      tabs([{ id: 'all', label: 'All Firms', icon: 'database' }, { id: 'new', label: 'New Firms' }, { id: 'masters', label: 'Master Tables', icon: 'layers' }, { id: 'bulk', label: 'Bulk Tools', icon: 'upload' }], activeTab) +
      panel('all', activeTab === 'all',
        '<div class="flex flex-wrap gap-2 mb-4">' +
          ['City', 'State', 'Team Size', 'Existing Software', 'Lead Source', 'Newly Established', 'Rating', 'Status'].map(function (f) {
            return '<button class="ca-chip" data-filter="' + f + '">' + f + ' ▾</button>';
          }).join('') +
        '</div>' +
        table(cols, [], { tbodyId: 'ca-master-data-table' })) +
      panel('new', activeTab === 'new',
        '<p class="text-caption text-slate-500 mb-4">Recently established firms</p>' +
        table(cols, [], { tbodyId: 'ca-master-new-data-table' })) +
      panel('masters', activeTab === 'masters', masterPanels) +
      panel('bulk', activeTab === 'bulk', bulkBody());
  }

  function bulkBody() {
    return '<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">' +
        ['Bulk Import', 'Bulk Assignment', 'Bulk Export', 'Bulk Status Update'].map(function (b) {
          var icon = b === 'Bulk Assignment' ? 'user-check' : (b === 'Bulk Export' ? 'download' : (b === 'Bulk Status Update' ? 'refresh-cw' : 'layers'));
          return '<div class="card-interactive p-5 text-center bulk-action-card" data-bulk="' + b + '"><i data-lucide="' + icon + '" class="h-10 w-10 text-brand mx-auto mb-3"></i><p class="text-card-heading">' + b + '</p></div>';
        }).join('') +
      '</div>' +
      '<div id="bulk-import-wizard" class="card p-5 mb-6 hidden">' +
        '<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-5">' +
          '<div class="shrink-0"><h3 class="text-card-heading flex items-center gap-2"><i data-lucide="upload" class="h-5 w-5 text-brand"></i> Bulk Import Wizard</h3></div>' +
          '<div class="bulk-wizard-steps" id="bulk-wizard-steps">' +
            ['Upload File', 'Column Mapping', 'Validation', 'Import Summary'].map(function (label, idx) {
              return '<div class="bulk-wizard-step' + (idx === 0 ? ' active' : '') + '" data-step="' + (idx + 1) + '"><span class="bulk-wizard-step-no">' + (idx + 1) + '</span><span>' + label + '</span></div>';
            }).join('') +
          '</div></div>' +
        '<div id="bulk-wizard-panel-1" class="bulk-wizard-panel">' +
          '<div id="bulk-upload-zone" class="upload-zone"><input type="file" id="bulk-import-file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="hidden" aria-hidden="true" />' +
            '<i data-lucide="upload" class="h-10 w-10 text-brand mx-auto mb-2"></i>' +
            '<p class="text-body font-medium">Drop CSV / Excel here or click to browse</p>' +
            '<p class="text-caption text-slate-500 mt-1">Supported: .csv, .xlsx · Max 10,000 rows · UTF-8</p></div>' +
          '<div id="bulk-upload-meta" class="hidden mt-4 grid sm:grid-cols-3 gap-3">' +
            '<div class="card p-4"><p class="text-caption text-slate-500">File Name</p><p id="bulk-file-name" class="font-medium text-slate-900 truncate">—</p></div>' +
            '<div class="card p-4"><p class="text-caption text-slate-500">Total Rows</p><p id="bulk-file-rows" class="font-medium text-slate-900">—</p></div>' +
            '<div class="card p-4"><p class="text-caption text-slate-500">File Size</p><p id="bulk-file-size" class="font-medium text-slate-900">—</p></div>' +
          '</div>' +
          '<div class="flex flex-wrap gap-2 mt-4">' +
            '<button type="button" class="btn-secondary btn-sm" id="bulk-reupload-btn"><i data-lucide="refresh-cw" class="h-4 w-4"></i> Re-upload</button>' +
            '<a href="/ca-masters/bulk-import/sample.csv" class="btn-secondary btn-sm inline-flex items-center gap-2" download><i data-lucide="download" class="h-4 w-4"></i> Sample CSV</a>' +
            '<a href="/ca-masters/bulk-import/sample.xlsx" class="btn-secondary btn-sm inline-flex items-center gap-2" download><i data-lucide="file-spreadsheet" class="h-4 w-4"></i> Sample Excel</a>' +
          '</div>' +
        '</div>' +
        '<div id="bulk-wizard-panel-2" class="bulk-wizard-panel hidden">' +
          '<div class="flex flex-wrap gap-3 items-end mb-4">' +
            '<div class="min-w-[12rem]"><label class="form-label">Saved Mapping Template</label><select id="bulk-mapping-template-select" class="input-field"><option value="">Auto-detect mapping</option></select></div>' +
            '<div class="min-w-[12rem]"><label class="form-label">Save As Template</label><input type="text" id="bulk-mapping-template-name" class="input-field" placeholder="e.g. CA Master Default" /></div>' +
          '</div>' +
          '<div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full"><thead><tr><th>CRM Field</th><th>Required</th><th>Excel Column</th></tr></thead><tbody id="bulk-mapping-table"></tbody></table></div>' +
        '</div>' +
        '<div id="bulk-wizard-panel-3" class="bulk-wizard-panel hidden">' +
          '<div class="grid sm:grid-cols-3 gap-3 mb-4">' +
            '<div class="card p-4"><p class="text-caption text-slate-500">Valid Rows</p><p id="bulk-valid-count" class="text-2xl font-semibold text-emerald-600">0</p></div>' +
            '<div class="card p-4"><p class="text-caption text-slate-500">Invalid Rows</p><p id="bulk-invalid-count" class="text-2xl font-semibold text-rose-600">0</p></div>' +
            '<div class="card p-4"><p class="text-caption text-slate-500">Duplicates</p><p id="bulk-duplicate-count" class="text-2xl font-semibold text-amber-600">0</p></div>' +
          '</div>' +
          '<div class="overflow-x-auto scrollbar-thin max-h-[420px]"><table class="ca-table w-full"><thead><tr><th>Row</th><th>Status</th><th>CA Name</th><th>Firm</th><th>Mobile</th><th>Email</th><th>GST</th><th>State</th><th>City</th><th>Issues</th></tr></thead><tbody id="bulk-validation-table"></tbody></table></div>' +
          '<div class="flex flex-wrap gap-2 mt-4 hidden" id="bulk-validation-downloads">' +
            '<button type="button" class="btn-secondary btn-sm" id="bulk-download-validation-errors"><i data-lucide="download" class="h-4 w-4"></i> Download Error Report</button>' +
            '<button type="button" class="btn-secondary btn-sm" id="bulk-download-validation-reimport"><i data-lucide="file-up" class="h-4 w-4"></i> Download Failed Rows for Re-import</button>' +
          '</div>' +
        '</div>' +
        '<div id="bulk-wizard-panel-4" class="bulk-wizard-panel hidden">' +
          '<div id="bulk-import-summary" class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3"></div>' +
          '<div class="flex flex-wrap gap-2 mt-4 hidden" id="bulk-import-summary-downloads">' +
            '<button type="button" class="btn-secondary btn-sm" id="bulk-download-import-errors"><i data-lucide="download" class="h-4 w-4"></i> Download Error Report</button>' +
            '<button type="button" class="btn-secondary btn-sm" id="bulk-download-import-reimport"><i data-lucide="file-up" class="h-4 w-4"></i> Download Failed Rows for Re-import</button>' +
            '<button type="button" class="btn-primary btn-sm" id="bulk-start-reimport-btn"><i data-lucide="upload" class="h-4 w-4"></i> Re-upload Corrected File</button>' +
          '</div>' +
        '</div>' +
        '<div class="flex flex-wrap justify-between gap-2 mt-6 pt-4 border-t border-slate-100">' +
          '<button type="button" class="btn-secondary" id="bulk-wizard-back-btn" disabled>Back</button>' +
          '<div class="flex gap-2">' +
            '<button type="button" class="btn-secondary" id="bulk-wizard-cancel-btn">Cancel</button>' +
            '<button type="button" class="btn-primary" id="bulk-wizard-next-btn" disabled>Next</button>' +
            '<button type="button" class="btn-primary hidden" id="bulk-wizard-import-btn"><i data-lucide="database" class="h-4 w-4"></i> Import Valid Rows</button>' +
          '</div></div></div>' +
      '<div id="bulk-assignment-panel" class="card p-5 mb-6 hidden bulk-assign-panel">' +
        '<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-5">' +
          '<h3 class="text-card-heading flex items-center gap-2"><i data-lucide="user-check" class="h-5 w-5 text-brand"></i> Bulk Assignment</h3>' +
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
                '<button type="button" class="btn-secondary btn-sm" id="bulk-assign-batch-clear">Clear Selection</button>' +
              '</div>' +
            '</div>' +
            '<div class="grid sm:grid-cols-3 gap-2 mb-3">' +
              '<select class="input-field" id="bulk-assign-batch-state" name="state_id"><option value="">Any state</option></select>' +
              '<select class="input-field" id="bulk-assign-batch-city" name="city_id" disabled><option value="">Any city</option></select>' +
              '<select class="input-field" id="bulk-assign-batch-source"><option value="">Any source</option></select>' +
            '</div>' +
            '<div class="mb-3"><select class="input-field" id="bulk-assign-batch-assignment"><option value="">All leads in batch</option><option value="unassigned">Unassigned only</option><option value="assigned">Assigned only</option></select></div>' +
            '<div id="bulk-assign-batches-list" class="bulk-assign-scroll-list"><div class="bulk-assign-skeleton">Loading import batches…</div></div>' +
            '<div class="bulk-assign-pagination" id="bulk-assign-batches-pagination"></div>' +
          '</div>' +
          '<div class="bulk-assign-card">' +
            '<div class="bulk-assign-card-head">' +
              '<h4 class="bulk-assign-card-title">Available Employees</h4>' +
              '<div class="flex flex-wrap gap-2">' +
                '<span class="text-caption text-slate-500">Click to toggle · multi-select</span>' +
                '<button type="button" class="btn-secondary btn-sm" id="bulk-assign-employees-clear">Clear</button>' +
              '</div>' +
            '</div>' +
            '<div class="mb-3"><input type="search" class="input-field" id="bulk-assign-employee-search" placeholder="Search employee name, email, role…" autocomplete="off" /></div>' +
            '<div id="bulk-assign-employees-list" class="bulk-assign-scroll-list"><div class="bulk-assign-skeleton">Loading employees…</div></div>' +
            '<div class="bulk-assign-pagination" id="bulk-assign-employees-pagination"></div>' +
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
            '<button type="button" class="btn-secondary flex-1" id="bulk-assign-preview-btn" disabled><i data-lucide="eye" class="h-4 w-4"></i> Preview</button>' +
            '<button type="button" class="btn-primary flex-1" id="bulk-assign-confirm-btn" disabled><i data-lucide="check" class="h-4 w-4"></i> Confirm Assignment</button>' +
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
          '<div class="shrink-0"><h3 class="text-card-heading flex items-center gap-2"><i data-lucide="download" class="h-5 w-5 text-brand"></i> Bulk Export</h3></div>' +
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
          '<div class="card p-4"><p class="text-caption text-slate-500">Matching Rows</p><p id="bulk-export-preview-count" class="text-2xl font-semibold text-slate-900">0</p></div>' +
          '<div class="card p-4"><p class="text-caption text-slate-500">Background Job</p><p id="bulk-export-preview-bg" class="font-medium text-slate-900">—</p></div>' +
          '<div class="card p-4"><p class="text-caption text-slate-500">Format</p><p id="bulk-export-preview-format" class="font-medium text-slate-900">—</p></div>' +
        '</div>' +
        '<div id="bulk-export-progress-wrap" class="hidden mb-4">' +
          '<div class="flex items-center justify-between mb-2"><p class="text-caption text-slate-500">Export progress</p><p id="bulk-export-progress-label" class="text-caption font-medium text-slate-700">0%</p></div>' +
          '<div class="bulk-export-progress"><div id="bulk-export-progress-bar" class="bulk-export-progress-bar" style="width:0%"></div></div>' +
        '</div>' +
        '<div class="flex flex-wrap gap-2">' +
          '<button type="button" class="btn-secondary" id="bulk-export-preview-btn"><i data-lucide="eye" class="h-4 w-4"></i> Preview Count</button>' +
          '<button type="button" class="btn-primary" id="bulk-export-run-btn"><i data-lucide="download" class="h-4 w-4"></i> Start Export</button>' +
          '<button type="button" class="btn-secondary hidden" id="bulk-export-download-btn"><i data-lucide="file-down" class="h-4 w-4"></i> Download File</button>' +
        '</div>' +
      '</div>' +
      '<div id="bulk-status-update-panel" class="card p-5 mb-6 hidden">' +
        '<h3 class="text-card-heading mb-4 flex items-center gap-2"><i data-lucide="refresh-cw" class="h-5 w-5 text-brand"></i> Bulk Status Update</h3>' +
        '<div class="grid lg:grid-cols-2 gap-4 mb-4">' +
          '<div><label class="form-label">Select Records</label><select multiple class="input-field min-h-[160px]" id="bulk-status-leads" size="8"></select><p class="text-caption text-slate-500 mt-1">Hold Ctrl/Cmd to select multiple firms</p></div>' +
          '<div><label class="form-label">New Status</label><select class="input-field" id="bulk-status-target"><option value="">Choose status…</option></select>' +
            '<p class="text-caption text-slate-500 mt-2">All selected records will be updated to this status in a single transaction.</p></div>' +
        '</div>' +
        '<div id="bulk-status-preview-meta" class="hidden grid sm:grid-cols-3 gap-3 mb-4">' +
          '<div class="card p-4"><p class="text-caption text-slate-500">Will Update</p><p id="bulk-status-preview-update" class="text-2xl font-semibold text-emerald-600">0</p></div>' +
          '<div class="card p-4"><p class="text-caption text-slate-500">Already at Status</p><p id="bulk-status-preview-skip" class="text-2xl font-semibold text-amber-600">0</p></div>' +
          '<div class="card p-4"><p class="text-caption text-slate-500">Target Status</p><p id="bulk-status-preview-target" class="font-medium text-slate-900">—</p></div>' +
        '</div>' +
        '<div class="flex flex-wrap gap-2 mb-4">' +
          '<button type="button" class="btn-secondary" id="bulk-status-preview-btn"><i data-lucide="eye" class="h-4 w-4"></i> Preview Changes</button>' +
          '<button type="button" class="btn-primary" id="bulk-status-apply-btn" disabled><i data-lucide="check" class="h-4 w-4"></i> Apply Status Update</button>' +
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
            '<button type="button" class="btn-secondary" data-close-bulk-status-confirm>Cancel</button>' +
            '<button type="button" class="btn-primary" id="bulk-status-confirm-btn"><i data-lucide="check" class="h-4 w-4"></i> Confirm Update</button>' +
          '</div></div></div>' +
      '<div id="modal-bulk-import-detail" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-import-detail-title">' +
        '<div class="ca-modal-panel ca-modal-panel-lg">' +
          '<div class="ca-modal-header">' +
            '<h3 id="bulk-import-detail-title" class="ca-modal-title"><span class="ca-modal-icon"><i data-lucide="file-text" class="h-5 w-5"></i></span> Import Details</h3>' +
            '<button type="button" class="ca-modal-close" data-close-bulk-import-detail aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>' +
          '</div>' +
          '<div class="ca-modal-body space-y-4" id="bulk-import-detail-body"></div>' +
          '<div class="ca-modal-footer">' +
            '<button type="button" class="btn-secondary" data-close-bulk-import-detail>Close</button>' +
            '<button type="button" class="btn-secondary" id="bulk-detail-error-report-btn"><i data-lucide="download" class="h-4 w-4"></i> Error Report</button>' +
            '<button type="button" class="btn-secondary" id="bulk-detail-reimport-btn"><i data-lucide="file-up" class="h-4 w-4"></i> Failed Rows CSV</button>' +
            '<button type="button" class="btn-primary" id="bulk-detail-reupload-btn"><i data-lucide="upload" class="h-4 w-4"></i> Re-upload Corrected File</button>' +
          '</div></div></div>' +
      table(['Reference', 'Type', 'File', 'Total', 'Success', 'Failed', 'Status', 'Performed By', 'Created'], [], { tbodyId: 'bulk-actions-data-table' });
  }

  /* ─── Leads (unified hub) ─── */
  function leadsPage() {
    var actions = ['Move to Demo Tab', 'Details Shared', 'Not Interested', 'Pipeline', 'Mark Inactive'];
    var listCols = ['Firm', 'Lead Name', 'Mobile', 'Alternate Mobile', 'City', 'Stage', 'Status', 'Executive', 'Source', 'Priority', 'Updated', 'Quick Actions'];
    return '<div class="leads-hub">' +
      '<div class="page-hero card mb-4 border-0 p-6 lg:p-8">' +
        '<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">' +
          '<div><h1 class="text-page-title text-slate-900">Lead Management</h1>' +
          '<p class="text-body text-slate-500 mt-1">Manage leads, assignments and follow-ups.</p></div>' +
          '<div class="flex flex-wrap gap-2 shrink-0">' +
            '<button type="button" class="btn-secondary" id="leads-filter-btn"><i data-lucide="sliders-horizontal" class="h-4 w-4"></i> Filters</button>' +
            '<button type="button" class="btn-secondary" data-open-modal="assign-lead"><i data-lucide="user-check" class="h-4 w-4"></i> Assign</button>' +
            '<button type="button" class="btn-secondary" data-action="export" data-export="leads"><i data-lucide="download" class="h-4 w-4"></i> Export</button>' +
            '<button type="button" class="btn-primary" data-open-modal="add-lead"><i data-lucide="plus" class="h-4 w-4"></i> Add Lead</button>' +
          '</div></div></div>' +
      '<div class="leads-kpi-strip card p-3 mb-4" id="leads-kpi-strip" role="tablist" aria-label="Lead segments"></div>' +
      tabs([{ id: 'pipeline', label: 'Pipeline', icon: 'git-branch' }, { id: 'all', label: 'All Leads', icon: 'list' }], 'pipeline', 'leads-view') +
      panel('pipeline', true,
        '<div class="card p-4 overflow-x-auto scrollbar-thin"><div id="kanban-board" class="flex gap-3 min-w-max pb-2"></div></div>', 'leads-view') +
      panel('all', false,
        table(listCols, [], { tbodyId: 'leads-data-table' }), 'leads-view') +
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
  function assignmentPage(activeTab) {
    activeTab = activeTab || 'assign';
    var assignBody =
      kpis([
        { icon: 'user-cog', label: 'Active Assignments', value: '—', trend: 'Live', valueId: 'assign-kpi-active' },
        { icon: 'refresh-cw', label: 'Auto (Rotation)', value: '—', trend: 'Live', valueId: 'assign-kpi-auto' },
        { icon: 'user-plus', label: 'Manual', value: '—', trend: 'Live', valueId: 'assign-kpi-manual' },
        { icon: 'target', label: 'Assigned Leads', value: '—', trend: 'Live', valueId: 'assign-kpi-target' },
      ]) +
      '<div class="grid lg:grid-cols-3 gap-4 mb-6">' +
        '<div class="card p-5 lg:col-span-1"><h3 class="text-card-heading mb-4">Rotation Logic</h3>' +
          '<div class="space-y-3">' +
            ['Round Robin', 'Workload Balance', 'Priority Score', 'City Match', 'Hot Lead First'].map(function (r, i) {
              return '<label class="flex items-center justify-between p-3 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-50">' +
                '<span class="text-body">' + r + '</span>' +
                '<label class="ca-toggle"><input type="checkbox"' + (i < 3 ? ' checked' : '') + '><span class="ca-toggle-slider"></span></label></label>';
            }).join('') +
          '</div></div>' +
        '<div class="card p-5 lg:col-span-2"><h3 class="text-card-heading mb-4">Active Assignments</h3>' +
          table(['Lead', 'Executive', 'Type', 'Method', 'Priority', 'Target', 'Achieved', 'Status', 'Date', 'Actions'], [], { tbodyId: 'assignment-data-table' }) + '</div></div>' +
      '<div class="card p-5"><h3 class="text-card-heading mb-4">Assignment History</h3>' +
        table(['From', 'To', 'Lead', 'Reassigned By', 'Reason', 'Date'], [], { tbodyId: 'assignment-history-table' }) + '</div>';

    var teamBody =
      '<div id="leaderboard" class="card p-5 mb-4"></div>' +
      table(['Name', 'Email', 'Mobile', 'Role', 'Login', 'City', 'Joined', 'Status', 'Actions'], [], { tbodyId: 'employees-data-table' });

    return hdr('Assignment', 'Assign leads to executives and manage your sales team.', null,
      actSecondary('<i data-lucide="user-check" class="h-4 w-4"></i> Assign Lead', 'data-open-modal="assign-lead"') +
      actPrimary('Add Executive', 'data-open-modal="add-employee"')) +
      tabs([{ id: 'assign', label: 'Assignments', icon: 'user-check' }, { id: 'team', label: 'Team', icon: 'users' }], activeTab, 'assign-hub') +
      panel('assign', activeTab === 'assign', assignBody, 'assign-hub') +
      panel('team', activeTab === 'team', teamBody, 'assign-hub');
  }

  /* ─── Follow Ups ─── */
  function followupsPage() {
    var types = ['Call Status', 'Demo Scheduled', 'Demo Completed', 'Details Shared', 'Negotiation', 'Not Interested', 'Follow Up Reminder', 'Follow Up Scheduled'];
    return hdr('Follow-ups', 'Schedule and track calls, demos, and client touchpoints.', null,
      actExport('Export Logs') + actPrimary('Schedule Follow-up', 'data-open-modal="followup"')) +
      kpis([
        { icon: 'phone', label: 'Due Today', value: '—', trend: 'Live', valueId: 'fu-kpi-due-today' },
        { icon: 'clock', label: 'Pending', value: '—', trend: 'Open', valueId: 'fu-kpi-pending' },
        { icon: 'alert-triangle', label: 'Overdue', value: '—', trend: 'Alert', valueId: 'fu-kpi-overdue' },
        { icon: 'video', label: 'Completed', value: '—', trend: 'Done', valueId: 'fu-kpi-completed' },
      ]) +
      '<div class="grid lg:grid-cols-3 gap-4 mb-6">' +
        '<div class="card p-5"><h3 class="text-card-heading mb-4"><i data-lucide="calendar" class="h-5 w-5 text-brand inline"></i> Calendar</h3><div id="followup-calendar"></div></div>' +
        '<div class="card p-5 lg:col-span-2"><h3 class="text-card-heading mb-4">Follow-Up Types</h3>' +
          '<div class="flex flex-wrap gap-2 mb-4">' + types.map(function (t) {
            return '<button class="ca-chip" data-fu-type="' + t + '">' + t + '</button>';
          }).join('') + '</div>' +
          table(['Type', 'Firm', 'Executive', 'Remarks', 'Scheduled', 'Next Follow-up', 'Status', 'Actions'], [], { tbodyId: 'followups-data-table' }) + '</div></div>';
  }

  /* ─── Communications Hub ─── */
  var COMM_ASSETS = (window.__CRM_COMM_ASSETS__ || '/crm-ui/assets/communication/');
  if (COMM_ASSETS.charAt(COMM_ASSETS.length - 1) !== '/') {
    COMM_ASSETS += '/';
  }

  function communicationPage() {
    var cards = [
      { id: 'email', label: 'Email', page: 'email', desc: 'Bulk email & templates', icon: 'mail' },
      { id: 'sms', label: 'SMS', page: 'sms', desc: 'SMS campaigns & logs', icon: 'smartphone' },
      { id: 'notification', label: 'Notifications', page: 'notifications', desc: 'Alerts & push messages', icon: 'bell' },
      { id: 'chat', label: 'Chat', page: 'whatsapp', desc: 'WhatsApp & live chat', icon: 'messages-square' },
      { id: 'consent', label: 'Consent & DND', page: 'consent-dnd', desc: 'Consent tracking & opt-out', icon: 'shield-check' },
      { id: 'appointment', label: 'Appointments', page: 'followups', desc: 'Schedule & reminders', icon: 'calendar-check' },
    ];
    return '<div class="comm-page">' +
      '<h2 class="comm-page-title">Communications</h2>' +
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

  function smsPage() {
    return hdr('SMS', 'Prepare SMS campaigns, preview mapped payloads, and audit logs before live integration.', null,
      actExport('Export Logs') + actPrimary('New Campaign', 'data-open-modal="add-campaign" data-campaign-channel="sms" data-sms-campaign-create')) +
      kpis([
        { icon: 'smartphone', label: 'Total Campaigns', value: '—', trend: 'Live', valueId: 'sms-kpi-campaigns' },
        { icon: 'map', label: 'Mapped Payloads', value: '—', trend: 'Audit', valueId: 'sms-kpi-mapped' },
        { icon: 'clock', label: 'Pending Campaigns', value: '—', trend: 'Draft', valueId: 'sms-kpi-pending' },
        { icon: 'flask-conical', label: 'Mode', value: '—', trend: 'Mapping', valueId: 'sms-kpi-mode' },
      ]) +
      '<div id="sms-payload-preview-panel" class="card p-4 mb-6 hidden">' +
        '<div class="flex items-center justify-between mb-3"><h3 class="text-card-heading">Developer Payload Preview</h3><span class="badge-brand">Mapped · Not Sent</span></div>' +
        '<pre id="sms-payload-preview-json" class="text-xs bg-slate-900 text-emerald-300 rounded-lg p-4 overflow-x-auto whitespace-pre-wrap"></pre>' +
        '<p id="sms-payload-preview-meta" class="text-caption text-slate-500 mt-2"></p>' +
      '</div>' +
      '<div id="campaigns-grid-sms" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6"></div>' +
      '<div class="card overflow-hidden"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full"><thead><tr>' +
        '<th>Campaign</th><th>Lead</th><th>Mobile</th><th>Message</th><th>Status</th><th>Provider Response</th><th>Created</th>' +
        '</tr></thead><tbody id="sms-logs-table"></tbody></table></div></div>';
  }

  function notificationsPage() {
    return hdr('Notifications', 'View alerts, reminders, and system messages.', null,
      actSecondary('<i data-lucide="check-check" class="h-4 w-4"></i> Mark All Read', 'data-action="mark-all-read"')) +
      tabs([{ id: 'all', label: 'All', icon: 'bell' }, { id: 'unread', label: 'Unread', count: '0', countId: 'notifications-unread-tab-count' }], 'all') +
      panel('all', true, '<div id="notifications-all-list" class="space-y-3"></div>') +
      panel('unread', false, '<div id="notifications-unread-list"></div>');
  }

  function receptionPage() {
    return hdr('Reception', 'Manage visitor queue, calls, and front-desk routing.', null,
      actPrimary('Add Visitor', 'data-page-action="Add visitor to queue"')) +
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
    return hdr('Chat', 'WhatsApp campaigns, templates, and message tracking.', null,
      actExport('Export Logs') + actPrimary('New Campaign', 'data-open-modal="add-campaign" data-campaign-channel="whatsapp"')) +
      kpis([
        { icon: 'message-circle', label: 'Total Campaigns', value: '—', trend: 'Live', valueId: 'wa-kpi-campaigns' },
        { icon: 'send', label: 'Total Messages', value: '—', trend: 'Live', valueId: 'wa-kpi-messages' },
        { icon: 'check-circle-2', label: 'Delivered', value: '—', trend: 'Simulated', valueId: 'wa-kpi-delivered' },
        { icon: 'alert-circle', label: 'Failed', value: '—', trend: 'Simulated', valueId: 'wa-kpi-failed' },
        { icon: 'clock', label: 'Queued', value: '—', trend: 'Pending', valueId: 'wa-kpi-queued' },
      ]) +
      tabs([{ id: 'campaigns', label: 'Campaigns', icon: 'message-circle' }, { id: 'logs', label: 'Message Logs', icon: 'list' }], 'campaigns') +
      panel('campaigns', true, '<div id="campaigns-grid-whatsapp" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>') +
      panel('logs', false,
        '<div class="card overflow-hidden"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full"><thead><tr>' +
        '<th>Campaign</th><th>Lead</th><th>Mobile</th><th>Status</th><th>Message</th><th>Queued</th><th>Delivered</th>' +
        '</tr></thead><tbody id="wa-message-logs-table"></tbody></table></div></div>');
  }

  /* ─── Email ─── */
  function emailPage() {
    return hdr('Email', 'Bulk email campaigns, delivery logs, and bounce tracking.', null,
      actExport('Export Logs') + actPrimary('New Campaign', 'data-open-modal="add-campaign" data-campaign-channel="email"')) +
      kpis([
        { icon: 'mail', label: 'Total Campaigns', value: '—', trend: 'Live', valueId: 'email-kpi-campaigns' },
        { icon: 'send', label: 'Total Emails', value: '—', trend: 'Live', valueId: 'email-kpi-messages' },
        { icon: 'check-circle-2', label: 'Delivered', value: '—', trend: 'Simulated', valueId: 'email-kpi-delivered' },
        { icon: 'alert-circle', label: 'Failed', value: '—', trend: 'Simulated', valueId: 'email-kpi-failed' },
        { icon: 'clock', label: 'Queued', value: '—', trend: 'Pending', valueId: 'email-kpi-queued' },
      ]) +
      tabs([{ id: 'campaigns', label: 'Campaigns' }, { id: 'logs', label: 'Email Logs' }, { id: 'bounce', label: 'Failed / Bounce' }], 'campaigns') +
      panel('campaigns', true, '<div id="campaigns-grid-email" class="grid sm:grid-cols-3 gap-4"></div>') +
      panel('logs', false,
        '<div class="card overflow-hidden"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full"><thead><tr>' +
        '<th>Campaign</th><th>Lead</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Failed Reason</th>' +
        '</tr></thead><tbody id="email-logs-table"></tbody></table></div></div>') +
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
      '<div class="flex flex-wrap gap-2 mb-4">' +
        '<label class="text-caption text-slate-500 flex items-center gap-2">Channel filter' +
          '<select id="consent-dnd-channel-filter" class="ca-input ca-input-sm">' +
            '<option value="">All channels</option>' +
            '<option value="WhatsApp">WhatsApp</option>' +
            '<option value="Email">Email</option>' +
            '<option value="SMS">SMS</option>' +
          '</select>' +
        '</label>' +
      '</div>' +
      tabs([{ id: 'consent-tab', label: 'Consent Records' }, { id: 'dnd-tab', label: 'DND List' }], 'consent-tab') +
      panel('consent-tab', true,
        '<div class="card overflow-hidden"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full"><thead><tr>' +
        '<th>Firm</th><th>Type</th><th>Status</th><th>Consent Date</th><th>Updated</th>' +
        '</tr></thead><tbody id="consent-records-table"></tbody></table></div></div>') +
      panel('dnd-tab', false,
        '<div class="card overflow-hidden"><div class="overflow-x-auto scrollbar-thin"><table class="ca-table w-full"><thead><tr>' +
        '<th>Firm</th><th>Mobile</th><th>Email</th><th>DND Type</th><th>Reason</th><th>Added</th><th></th>' +
        '</tr></thead><tbody id="dnd-records-table"></tbody></table></div></div>');
  }

  /* ─── Security ─── */
  function securityPage() {
    return hdr('Security & Compliance', 'Role access, consent, encryption, and API protection.', null) +
      '<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-6" id="security-nav">' +
        [
          { id: 'rbac', t: 'Role Access Control', i: 'shield-check', c: '6 roles · 24 users' },
          { id: 'consent', t: 'Consent Tracking', i: 'fingerprint', c: 'WhatsApp · Email · DND' },
          { id: 'dnd', t: 'DND Management', i: 'ban', c: '1,247 contacts' },
          { id: 'encrypt', t: 'Encryption Keys', i: 'lock', c: 'AES-256 at rest' },
          { id: 'locking', t: 'Lead Locking', i: 'key', c: '28 active locks' },
          { id: 'api', t: 'API Protection', i: 'zap', c: '1,000 req/min' },
        ].map(function (w) {
          return '<div class="card p-4 security-card' + (w.id === 'rbac' ? ' active' : '') + '" data-security-panel="' + w.id + '">' +
            '<div class="flex items-center gap-3 mb-2"><i data-lucide="' + w.i + '" class="h-5 w-5 text-brand"></i><p class="text-card-heading">' + w.t + '</p></div>' +
            '<p class="text-caption text-slate-500 mt-2">' + w.c + '</p></div>';
        }).join('') + '</div>' +
      '<div id="security-content">' +
        panel('rbac', true,
          '<h3 class="text-card-heading mb-4">Permission Matrix</h3>' +
          '<p id="security-matrix-note" class="text-caption text-slate-500 mb-3">Loading permissions…</p>' +
          '<div class="card overflow-hidden mb-4"><div class="overflow-x-auto"><table class="ca-table w-full"><thead><tr><th>Role</th><th>Module</th><th>Permission</th><th>Enabled</th></tr></thead><tbody id="security-rbac-matrix"></tbody></table></div></div>' +
          '<h4 class="text-card-heading mb-3">Users</h4>' +
          '<div class="overflow-x-auto"><table class="ca-table w-full"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Modules</th></tr></thead><tbody id="security-users-table"></tbody></table></div>', 'security') +
      '</div>';
  }

  /* ─── Employees ─── */
  function employeesPage() {
    return hdr('Employee Management', 'Manage team members, roles, and performance.', null,
      actExport('Export Team') + actPrimary('Add Executive', 'data-open-modal="add-employee"')) +
      tabs([{ id: 'employees', label: 'Employees', icon: 'users' }, { id: 'roles', label: 'Roles', icon: 'shield' }, { id: 'performance', label: 'Performance', icon: 'trophy' }], 'employees') +
      panel('employees', true,
        table(['Name', 'Email', 'Mobile', 'Role', 'Manager', 'City', 'Joined', 'Status'], [], { tbodyId: 'employees-data-table' })) +
      panel('roles', false,
        table(['Role ID', 'Role Name', 'Description', 'Users', 'Modules'], [
          ['', 'Admin', 'Full access', '2', 'All'],
          ['', 'Sales Manager', 'Team management', '4', 'Leads, Reports, Analytics'],
          ['', 'Executive', 'Lead operations', '18', 'Leads, Follow-ups, Comms'],
        ])) +
      panel('performance', false,
        '<div id="leaderboard" class="card p-5 mb-4"></div>' +
        table(['Employee', 'Daily Calls', 'Demos', 'Conversion', 'Revenue', 'Target %'], [
          ['Rahul Verma', '48', '12', '32%', '₹8.4L', '94%'],
          ['Priya Sharma', '42', '10', '28%', '₹6.2L', '88%'],
          ['Anita Desai', '38', '9', '26%', '₹5.1L', '82%'],
        ]));
  }

  function bulkPage() {
    return hdr('Bulk Operations', 'Import, export, assign, and update records in bulk.', null,
      actPrimary('Bulk Import', 'data-nav-bulk="import"')) + bulkBody();
  }

  /* ─── Queue ─── */
  function dbHealthPage() {
    // TODO: Protect this dev-only page with admin authentication before production.
    return hdr(
      'Database Health',
      'Development admin view — table counts, duplicate checks, foreign keys, API routes, and database size.',
      'DEV · DB_HEALTH',
      actSecondary('<i data-lucide="refresh-cw" class="h-4 w-4"></i> Refresh', 'id="db-health-refresh-btn" type="button"'),
    ) +
      '<p class="text-caption text-amber-700 bg-amber-50 border border-amber-100 rounded-xl px-4 py-3 mb-6">Administrator access only. Restricted to authorized users.</p>' +
      '<div id="db-health-kpi-grid" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6"></div>' +
      '<div class="grid lg:grid-cols-2 gap-4 mb-6">' +
        '<div class="card p-4"><p class="text-caption text-slate-500">Database</p><p id="db-health-db-name" class="font-semibold text-slate-900">Loading…</p><p id="db-health-db-size" class="text-caption text-slate-500 mt-1">—</p></div>' +
        '<div class="card p-4"><p class="text-caption text-slate-500">Report Generated</p><p id="db-health-generated-at" class="font-semibold text-slate-900">—</p><p class="text-caption text-slate-500 mt-1">Use Refresh to reload checks</p></div>' +
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
      actSecondary('<i data-lucide="refresh-cw" class="h-4 w-4"></i> Refresh', 'id="queue-refresh-btn" type="button"')) +
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
    return '<div class="card p-5 mb-4">' +
      '<div class="flex flex-wrap gap-4 items-end">' +
        '<div class="min-w-[10rem]"><label class="text-caption text-slate-500 block mb-1" for="audit-filter-module">Module</label>' +
        '<select id="audit-filter-module" class="ca-select w-full"><option value="">All modules</option></select></div>' +
        '<div class="min-w-[10rem]"><label class="text-caption text-slate-500 block mb-1" for="audit-filter-action">Action</label>' +
        '<select id="audit-filter-action" class="ca-select w-full"><option value="">All actions</option></select></div>' +
        '<div class="min-w-[10rem]"><label class="text-caption text-slate-500 block mb-1" for="audit-filter-from">From</label>' +
        '<input type="date" id="audit-filter-from" class="ca-input w-full"></div>' +
        '<div class="min-w-[10rem]"><label class="text-caption text-slate-500 block mb-1" for="audit-filter-to">To</label>' +
        '<input type="date" id="audit-filter-to" class="ca-input w-full"></div>' +
        '<div class="min-w-[10rem]"><label class="text-caption text-slate-500 block mb-1" for="audit-filter-user">User</label>' +
        '<input type="text" id="audit-filter-user" class="ca-input w-full" placeholder="Performed by"></div>' +
        '<div class="flex gap-2">' +
          '<button type="button" id="audit-filter-apply" class="btn-primary btn-sm"><i data-lucide="filter" class="h-4 w-4"></i> Apply</button>' +
          '<button type="button" id="audit-filter-clear" class="btn-secondary btn-sm">Clear</button>' +
        '</div>' +
      '</div></div>' +
      '<div class="card p-5"><div class="overflow-x-auto scrollbar-thin">' +
        '<table class="ca-table w-full"><thead><tr>' +
          '<th>Timestamp</th><th>User</th><th>Module</th><th>Record ID</th><th>Action</th>' +
          '<th>Before</th><th>After</th><th>IP</th><th>Details</th>' +
        '</tr></thead><tbody id="audit-logs-table"></tbody></table>' +
      '</div></div>';
  }

  function activityBody() {
    return '<div class="card p-5 mb-4">' +
      '<div class="flex flex-wrap gap-4 items-end">' +
        '<div class="min-w-[10rem]"><label class="text-caption text-slate-500 block mb-1" for="activity-filter-module">Module</label>' +
        '<select id="activity-filter-module" class="ca-select w-full"><option value="">All modules</option></select></div>' +
        '<div class="min-w-[10rem]"><label class="text-caption text-slate-500 block mb-1" for="activity-filter-action">Action</label>' +
        '<select id="activity-filter-action" class="ca-select w-full"><option value="">All actions</option></select></div>' +
        '<div class="min-w-[10rem]"><label class="text-caption text-slate-500 block mb-1" for="activity-filter-date">Date</label>' +
        '<input type="date" id="activity-filter-date" class="ca-input w-full"></div>' +
        '<div class="min-w-[10rem]"><label class="text-caption text-slate-500 block mb-1" for="activity-filter-user">User</label>' +
        '<input type="text" id="activity-filter-user" class="ca-input w-full" placeholder="Performed by"></div>' +
        '<div class="flex gap-2">' +
          '<button type="button" id="activity-filter-apply" class="btn-primary btn-sm"><i data-lucide="filter" class="h-4 w-4"></i> Apply</button>' +
          '<button type="button" id="activity-filter-clear" class="btn-secondary btn-sm">Clear</button>' +
        '</div>' +
      '</div></div>' +
      '<div class="card p-6 mb-4"><div id="activity-timeline"></div></div>' +
      '<div class="card p-5"><div class="flex items-center justify-between gap-3 mb-3">' +
        '<h3 class="text-card-heading">Activity Logs</h3></div>' +
        '<div class="overflow-x-auto scrollbar-thin">' +
          '<table class="ca-table w-full"><thead><tr>' +
            '<th>Timestamp</th><th>User</th><th>Module</th><th>Record ID</th><th>Action</th><th>Description</th>' +
          '</tr></thead><tbody id="activity-logs-table"></tbody></table>' +
        '</div></div>';
  }

  function reportsHubPage(activeTab) {
    activeTab = activeTab || 'reports';
    var reportDefs = [
      { card: 'Daily Lead Report', slug: 'lead_conversion' },
      { card: 'Weekly Demo Report', slug: 'followup_performance' },
      { card: 'Monthly Revenue', slug: 'monthly_trends' },
      { card: 'City-wise Analysis', slug: 'city_analysis' },
      { card: 'Employee Performance', slug: 'employee_performance' },
      { card: 'Lost Lead Analysis', slug: 'lost_lead_analysis' },
    ];
    var reportCards = '<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">' +
      reportDefs.map(function (r) {
        return '<div class="card-interactive p-5 flex items-center gap-4 report-card" data-report="' + r.card + '" data-report-slug="' + r.slug + '">' +
          '<div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand"><i data-lucide="file-text" class="h-6 w-6"></i></div>' +
          '<div><p class="text-card-heading">' + r.card + '</p><p class="text-caption text-slate-500 report-card-meta" data-report-slug="' + r.slug + '">Live data</p></div></div>';
      }).join('') + '</div>';
    var analyticsCharts = [
      { label: 'Daily Calls', key: 'daily_calls' },
      { label: 'Demo Ratio', key: 'demo_ratio' },
      { label: 'Conversion %', key: 'conversion' },
      { label: 'City Performance', key: 'city_performance' },
      { label: 'Lead Source', key: 'lead_source' },
      { label: 'Target Achievement', key: 'target_achievement' },
    ];

    return hdr('Reports', 'Reports, analytics, activity logs, and audit trail.', null,
      actExport('Export Report')) +
      '<div class="card p-4 mb-4 flex flex-wrap items-end gap-3" id="reports-filter-bar">' +
        '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">From</label><input type="date" class="input-field" id="reports-filter-from" /></div>' +
        '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">To</label><input type="date" class="input-field" id="reports-filter-to" /></div>' +
        '<button type="button" class="btn-secondary btn-sm" data-action="apply-reports-filter"><i data-lucide="filter" class="h-4 w-4"></i> Apply</button>' +
        '<button type="button" class="btn-secondary btn-sm" data-action="export" data-export="export-report"><i data-lucide="download" class="h-4 w-4"></i> Export Summary</button>' +
        '<button type="button" class="btn-secondary btn-sm" data-action="export" data-export="export-report-pdf"><i data-lucide="file-text" class="h-4 w-4"></i> Export PDF</button>' +
      '</div>' +
      tabs([
        { id: 'reports', label: 'Reports', icon: 'file-text' },
        { id: 'analytics', label: 'Analytics', icon: 'bar-chart-3' },
        { id: 'activity', label: 'Activity', icon: 'activity' },
        { id: 'audit', label: 'Audit', icon: 'history' },
      ], activeTab, 'reports-hub') +
      panel('reports', activeTab === 'reports', reportCards, 'reports-hub') +
      panel('analytics', activeTab === 'analytics', charts(analyticsCharts), 'reports-hub') +
      panel('activity', activeTab === 'activity', activityBody(), 'reports-hub') +
      panel('audit', activeTab === 'audit', auditBody(), 'reports-hub');
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

  /* ─── Settings ─── */
  function settingsPage() {
    return hdr('Settings', 'Configure assignment rules, filters, and integrations.', null,
      actPrimary('Save Settings', 'id="settings-save-btn" type="button"')) +
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
          [{ n: 'WhatsApp API', s: 'Connected', i: 'message-circle', badge: 'badge-success' }, { n: 'Email SMTP', s: 'Connected', i: 'mail', badge: 'badge-success' }, { n: 'Cashfree Payments', s: 'Connected', i: 'credit-card', badge: 'badge-success' }].map(function (x) {
            return '<div class="card p-4 flex items-center justify-between"><div class="flex items-center gap-3"><i data-lucide="' + x.i + '" class="h-5 w-5 text-brand"></i><span class="text-card-heading">' + x.n + '</span></div><span class="' + x.badge + '">' + x.s + '</span></div>';
          }).join('') +
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
              '<div class="flex items-center gap-3 pt-6"><input type="checkbox" id="sms-settings-is-active" class="rounded border-slate-300" checked /><label for="sms-settings-is-active" class="text-caption font-medium text-slate-600">Provider Active</label></div>' +
            '</div>' +
            '<p class="text-caption text-slate-400" id="sms-settings-api-key-note">API key is encrypted at rest and never returned by the API.</p>' +
            '</div>' +
            '<div class="sms-settings-actions flex flex-wrap gap-2 p-4 border-t border-slate-100 bg-white">' +
              '<button type="button" class="btn-primary" id="sms-settings-save-btn">Save Settings</button>' +
              '<button type="button" class="btn-secondary" id="sms-settings-test-btn">Validate Configuration</button>' +
              '<button type="button" class="btn-secondary" id="sms-settings-reset-btn">Reset</button>' +
              '<button type="button" class="btn-secondary" id="sms-settings-cancel-btn">Cancel</button>' +
            '</div>' +
          '</div>' +
          '<div class="card p-4 space-y-4">' +
            '<div class="flex items-center gap-3"><i data-lucide="mail" class="h-5 w-5 text-brand"></i><span class="text-card-heading">GoDaddy Email</span><span class="badge-neutral" id="email-settings-mode-badge">Simulation</span></div>' +
            '<p class="text-caption text-slate-500">Mapping-only configuration for GoDaddy Business Email SMTP. No emails are sent until Live mode and credentials are configured.</p>' +
            '<div class="grid lg:grid-cols-2 gap-4">' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Provider Name</label><input id="email-settings-provider-name" class="input-field" value="GoDaddy SMTP" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Mode</label><select id="email-settings-mode" class="input-field"><option value="simulation">Simulation</option><option value="live">Live</option></select></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">SMTP Host</label><input id="email-settings-smtp-host" class="input-field" value="smtpout.secureserver.net" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">SMTP Port</label><input id="email-settings-smtp-port" class="input-field" type="number" value="465" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Username</label><input id="email-settings-smtp-username" class="input-field" placeholder="To be provided by manager" autocomplete="off" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Password</label><input id="email-settings-smtp-password" class="input-field" type="password" placeholder="To be provided by manager" autocomplete="new-password" /></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Encryption</label><select id="email-settings-smtp-encryption" class="input-field"><option value="ssl">SSL</option><option value="tls">TLS</option><option value="starttls">STARTTLS</option></select></div>' +
              '<div><label class="text-caption font-medium text-slate-600 mb-1.5 block">From Email</label><input id="email-settings-from-email" class="input-field" placeholder="To be provided by manager" autocomplete="off" /></div>' +
              '<div class="lg:col-span-2"><label class="text-caption font-medium text-slate-600 mb-1.5 block">From Name</label><input id="email-settings-from-name" class="input-field" placeholder="CA Cloud Desk" autocomplete="off" /></div>' +
            '</div>' +
            '<p class="text-caption text-slate-400" id="email-settings-password-note">SMTP password is encrypted at rest and never returned by the API.</p>' +
            '<button type="button" class="btn-primary" id="email-settings-save-btn">Save Email Settings</button>' +
          '</div>' +
        '</div>');
  }

  const pages = {
    dashboard: {
      title: 'Dashboard', breadcrumb: 'Dashboard', er: 'ADMIN_DASHBOARD_METRICS',
      html: dashboardPage(),
    },
    'ca-master': { title: 'CA Master', breadcrumb: 'CA Master', er: 'CA_MASTER', html: caMasterPage('all') },
    leads: { title: 'Lead Management', breadcrumb: 'Leads', er: 'LEAD_ACTION', html: leadsPage() },
    'leads-segments': { title: 'Lead Management', breadcrumb: 'Leads', er: 'LEAD_ACTION', html: leadsPage() },
    assignment: { title: 'Assignment', breadcrumb: 'Assignment', er: 'LEAD_ASSIGNMENT_ENGINE', html: assignmentPage('assign') },
    followups: { title: 'Follow-ups', breadcrumb: 'Follow-ups', er: 'FOLLOW_UP_MANAGEMENT', html: followupsPage() },
    communication: { title: 'Communications', breadcrumb: 'Communication', er: 'COMMUNICATION_MODULE', html: communicationPage() },
    'consent-dnd': { title: 'Consent & DND', breadcrumb: 'Communication / Consent & DND', er: 'CONSENT_DND', html: consentDndPage() },
    whatsapp: { title: 'Chat', breadcrumb: 'Communication / Chat', er: 'WHATSAPP_CAMPAIGN', html: whatsappPage() },
    email: { title: 'Email', breadcrumb: 'Communication / Email', er: 'EMAIL_CAMPAIGN', html: emailPage() },
    sms: { title: 'SMS', breadcrumb: 'Communication / SMS', er: 'SMS_CAMPAIGN', html: smsPage() },
    notifications: { title: 'Notifications', breadcrumb: 'Communication / Notifications', er: 'NOTIFICATION_MODULE', html: notificationsPage() },
    reception: { title: 'Reception Hub', breadcrumb: 'Communication / Reception Hub', er: 'RECEPTION_HUB', html: receptionPage() },
    reports: { title: 'Reports', breadcrumb: 'Reports', er: 'Reports', html: reportsHubPage('reports') },
    analytics: { title: 'Analytics', breadcrumb: 'Reports / Analytics', er: 'ADMIN_DASHBOARD_METRICS', html: reportsHubPage('analytics') },
    activity: { title: 'Activity Logs', breadcrumb: 'Reports / Activity', er: 'ACTIVITY_LOGS', html: reportsHubPage('activity') },
    audit: { title: 'Audit Logs', breadcrumb: 'Reports / Audit', er: 'ACTIVITY_LOGS', html: reportsHubPage('audit') },
    bulk: { title: 'Bulk Operations', breadcrumb: 'CA Master / Bulk', er: 'BULK_ACTIONS', html: caMasterPage('bulk') },
    employees: { title: 'Team', breadcrumb: 'Assignment / Team', er: 'EMPLOYEE_MASTER', html: assignmentPage('team') },
    security: { title: 'Security', breadcrumb: 'Security', er: 'Security Module', html: securityPage() },
    queue: { title: 'System Health', breadcrumb: 'Queue', er: 'QUEUE_SYSTEM', html: queuePage() },
    'db-health': { title: 'Database Health', breadcrumb: 'Admin / Database Health', er: 'DEV_DB_HEALTH', html: dbHealthPage() },
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
      return pages[id] || pages.dashboard;
    },
    employeeDashboardPage: employeeDashboardPage,
    ids: function () { return Object.keys(pages); },
    all: pages,
  };
})();
