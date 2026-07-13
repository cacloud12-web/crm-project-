/* global window, document */
(function () {
  'use strict';

  var PAGE_ACCESS = {
    dashboard: { module: 'dashboard', permission: 'view' },
    'ca-master': { module: 'ca_master', permission: 'view' },
    leads: { module: 'leads', permission: 'view' },
    'sales-list': { module: 'sales_list', permission: 'view' },
    assignment: { module: 'assignment', permission: 'view' },
    followups: { module: 'followups', permission: 'view' },
    bulk: { module: 'bulk', permission: 'view' },
    communication: { module: 'campaigns', permission: 'view' },
    whatsapp: { module: 'campaigns', permission: 'view' },
    sms: { module: 'campaigns', permission: 'view' },
    email: { module: 'campaigns', permission: 'view' },
    campaigns: { module: 'campaigns', permission: 'view' },
    'consent-dnd': { module: 'consent', permission: 'view' },
    reports: { module: 'reports', permission: 'view_reports' },
    activity: { module: 'activity', permission: 'view' },
    security: { module: 'security', permission: 'view' },
    queue: { module: 'admin', permission: 'view' },
    'db-health': { module: 'admin', permission: 'reports' },
    settings: { module: 'settings', permission: 'view' },
    'roles-permissions': { module: 'roles_permissions', permission: 'view' },
    'email-configuration': { module: 'email_configuration', permission: 'view' },
    'settings-email-templates': { module: 'email_templates', permission: 'view' },
    'settings-whatsapp-templates': { module: 'whatsapp_templates', permission: 'view' },
    'settings-google-api': { module: 'google_api', permission: 'view' },
    notifications: { module: 'dashboard', permission: 'view' },
    payments: { module: 'dashboard', permission: 'view' },
  };

  var PAGE_MODULE = {
    dashboard: 'dashboard',
    'ca-master': 'ca_master',
    leads: 'leads',
    'sales-list': 'sales_list',
    assignment: 'assignment',
    followups: 'followups',
    bulk: 'bulk',
    communication: 'campaigns',
    whatsapp: 'campaigns',
    sms: 'campaigns',
    email: 'campaigns',
    campaigns: 'campaigns',
    'consent-dnd': 'consent',
    reports: 'reports',
    activity: 'activity',
    security: 'security',
    queue: 'admin',
    'db-health': 'admin',
    settings: 'settings',
    'roles-permissions': 'roles_permissions',
    'email-configuration': 'email_configuration',
    'settings-email-templates': 'email_templates',
    'settings-whatsapp-templates': 'whatsapp_templates',
    'settings-google-api': 'google_api',
  };

  var ACTION_RULES = [
    { selector: '#cam-add-firm-btn', module: 'ca_master', permission: 'create' },
    { selector: '#leads-kpi-add-btn', module: 'leads', permission: 'create' },
    { selector: '[data-open-modal="add-lead"]:not(#cam-add-firm-btn)', module: 'leads', permission: 'create' },
    { selector: '[data-master-add]', module: 'ca_master', permission: 'create' },
    { selector: '[data-open-modal="add-employee"]', module: 'employees', permission: 'create' },
    { selector: '[data-open-modal="assign-lead"]', module: 'assignment', permission: 'create' },
    { selector: '[data-open-modal="followup"]', module: 'followups', permission: 'schedule_followup' },
    { selector: '[data-action="export"]', permission: 'export', usePageModule: true },
    { selector: '#bulk-export-run-btn', module: 'bulk', permission: 'export' },
    { selector: '#bulk-export-preview-btn', module: 'bulk', permission: 'export' },
    { selector: '#bulk-wizard-import-btn', module: 'bulk', permission: 'import' },
    { selector: '#bulk-assign-confirm-btn', module: 'bulk', permission: 'edit' },
    { selector: '#bulk-status-apply-btn', module: 'bulk', permission: 'edit' },
    { selector: '#bulk-status-confirm-btn', module: 'bulk', permission: 'edit' },
    { selector: '[data-nav-bulk="import"]', module: 'bulk', permission: 'import' },
    { selector: '.bulk-action-card[data-bulk="Bulk Import"]', module: 'bulk', permission: 'import' },
    { selector: '.bulk-action-card[data-bulk="Bulk Export"]', module: 'bulk', permission: 'export' },
    { selector: '.bulk-action-card[data-bulk="Bulk Status Update"]', module: 'bulk', permission: 'edit' },
    { selector: '.bulk-action-card[data-bulk="Bulk Assignment"]', module: 'bulk', permission: 'edit' },
    { selector: '[data-page-action*="Delete"]', permission: 'delete', usePageModule: true },
    { selector: 'button[data-open-modal="whatsapp-campaign"]', module: 'campaigns', permission: 'campaigns' },
    { selector: 'button[data-open-modal="email-campaign"]', module: 'campaigns', permission: 'campaigns' },
    { selector: 'button[data-open-modal="sms-campaign"]', module: 'campaigns', permission: 'campaigns' },
    { selector: '[data-open-modal="add-campaign"]', module: 'campaigns', permission: 'campaigns' },
    { selector: '[data-manager-schedule-followup]', module: 'followups', permission: 'schedule_followup' },
    { selector: '[data-inbox-action="assign"]', module: 'assignment', permission: 'create' },
    { selector: '[data-inbox-action="import"]', permission: 'import', usePageModule: true },
    { selector: '[data-inbox-action="export"]', permission: 'export', usePageModule: true },
  ];

  var INBOX_MODULE_MAP = {
    'ca-master': 'ca_master',
    leads: 'leads',
    followups: 'followups',
    assignment: 'assignment',
  };

  function user() {
    return window.__CRM_USER__ || { authenticated: false, permissions: {} };
  }

  function can(module, permission) {
    var u = user();
    if (!u.authenticated) return false;
    if (u.role === 'super_admin') return true;
    var perms = (u.permissions && u.permissions[module]) || [];
    return perms.indexOf(permission) >= 0;
  }

  function currentPageModule() {
    var page = window.__CRM_CURRENT_PAGE__ || 'dashboard';
    return PAGE_MODULE[page] || 'dashboard';
  }

  function hideElement(el) {
    if (!el) return;
    el.classList.add('hidden');
    el.setAttribute('aria-hidden', 'true');
    el.setAttribute('data-rbac-hidden', '1');
  }

  function applyNavAccess() {
    var u = user();
    document.querySelectorAll('.nav-item[data-page]').forEach(function (item) {
      var page = item.dataset.page;
      var rule = PAGE_ACCESS[page];
      if (!rule || !can(rule.module, rule.permission)) hideElement(item);
      // Lead Management is employee-only; Super Admin and Manager use Master Data.
      if (page === 'leads' && u.role !== 'employee') hideElement(item);
    });

    document.querySelectorAll('.header-settings-item[data-page]').forEach(function (item) {
      var page = item.dataset.page;
      var rule = PAGE_ACCESS[page];
      if (!rule || !can(rule.module, rule.permission)) hideElement(item);
    });

  }

  function inboxModuleFromElement(el) {
    var bar = el.closest('[data-inbox-module]');
    var key = bar ? bar.getAttribute('data-inbox-module') : '';
    return INBOX_MODULE_MAP[key] || currentPageModule();
  }

  function applyInboxBulkAccess() {
    document.querySelectorAll('[data-inbox-action="delete"]').forEach(function (btn) {
      if (!can(inboxModuleFromElement(btn), 'delete')) hideElement(btn);
    });
    document.querySelectorAll('.crm-inbox-bulk-toolbar').forEach(function (toolbar) {
      var visible = toolbar.querySelectorAll('[data-inbox-action]:not(.hidden):not([data-rbac-hidden="1"])');
      if (!visible.length) hideElement(toolbar);
      var bar = toolbar.closest('.crm-inbox-bulk-bar');
      if (bar) {
        var anyVisible = bar.querySelectorAll('[data-inbox-action]:not(.hidden):not([data-rbac-hidden="1"])');
        if (!anyVisible.length) hideElement(bar);
      }
    });
  }

  function applyActionAccess() {
    ACTION_RULES.forEach(function (rule) {
      document.querySelectorAll(rule.selector).forEach(function (el) {
        var module = rule.module;
        if (rule.usePageModule) {
          module = el.closest('[data-inbox-module]') ? inboxModuleFromElement(el) : currentPageModule();
        }
        if (!can(module, rule.permission)) hideElement(el);
      });
    });

    applyInboxBulkAccess();

    document.querySelectorAll('form[data-entity-delete]').forEach(function (el) {
      if (!can(el.dataset.entityModule || currentPageModule(), 'delete')) hideElement(el);
    });
  }

  function updateUserPill() {
    var u = user();
    var pill = document.querySelector('.header-user-pill-text');
    if (pill && u.authenticated) {
      pill.textContent = (u.name || 'User') + ' · ' + (u.role_label || u.role || 'Employee');
    }
  }

  function applyProfileAccess() {
    var u = user();
    var isEmployee = u.authenticated && u.role === 'employee';
    var isCredentialAdmin = u.authenticated && (u.role === 'admin' || u.role === 'super_admin');
    var isSuperAdmin = u.authenticated && u.role === 'super_admin';
    var passwordItem = document.getElementById('profile-change-password');
    var loginEmailItem = document.getElementById('profile-change-login-email');
    var emailConfigProfileItem = document.getElementById('profile-email-configuration');
    var resetItem = document.getElementById('profile-reset-employee-password');
    var settingsBtn = document.getElementById('settings-btn');
    var passwordLabel = document.getElementById('profile-password-label');

    if (passwordItem) {
      if (isCredentialAdmin) passwordItem.classList.remove('hidden');
      else hideElement(passwordItem);
    }
    if (loginEmailItem) {
      if (isSuperAdmin) loginEmailItem.classList.remove('hidden');
      else hideElement(loginEmailItem);
    }
    if (emailConfigProfileItem) {
      if (isSuperAdmin) emailConfigProfileItem.classList.remove('hidden');
      else hideElement(emailConfigProfileItem);
    }
    if (resetItem) {
      if (isCredentialAdmin) resetItem.classList.remove('hidden');
      else hideElement(resetItem);
    }
    var emailConfigItem = document.getElementById('header-email-configuration');
    if (emailConfigItem) {
      if (isSuperAdmin) emailConfigItem.classList.remove('hidden');
      else hideElement(emailConfigItem);
    }
    if (settingsBtn) {
      if (isEmployee || !can('settings', 'view')) hideElement(settingsBtn);
      else settingsBtn.classList.remove('hidden');
    }
    var recycleBtn = document.getElementById('sidebar-recycle-btn');
    if (recycleBtn) {
      if (!can('ca_master', 'delete')) hideElement(recycleBtn);
      else recycleBtn.classList.remove('hidden');
    }
    if (passwordLabel && isCredentialAdmin) passwordLabel.textContent = 'Change Password';
  }

  function applySettingsHubAccess() {
    var role = user().role || '';
    document.querySelectorAll('[data-settings-nav][data-settings-roles]').forEach(function (btn) {
      var roles = (btn.getAttribute('data-settings-roles') || '').split(',').filter(Boolean);
      if (roles.length && roles.indexOf(role) === -1) hideElement(btn);
    });
  }

  function enforce() {
    applyNavAccess();
    applySettingsHubAccess();
    applyActionAccess();
    applyProfileAccess();
    updateUserPill();
  }

  function logout() {
    var token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fetch('/logout', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': token,
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    }).finally(function () {
      window.location.href = '/login';
    });
  }

  function bindLogout() {
    var logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn && !logoutBtn._logoutBound) {
      logoutBtn._logoutBound = true;
      logoutBtn.addEventListener('click', function (e) {
        e.preventDefault();
        logout();
      });
    }

    var securityBtn = document.getElementById('sidebar-security-btn');
    if (securityBtn && !securityBtn._securityBound) {
      securityBtn._securityBound = true;
      securityBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (can('security', 'view') && window.navigateTo) window.navigateTo('security');
      });
    }
  }

  function onPageChange(pageId) {
    window.__CRM_CURRENT_PAGE__ = pageId;
    setTimeout(enforce, 0);
  }

  window.CA_RBAC = {
    can: can,
    enforce: enforce,
    onPageChange: onPageChange,
    applySettingsHubAccess: applySettingsHubAccess,
    logout: logout,
    user: user,
  };

  document.addEventListener('DOMContentLoaded', function () {
    window.__CRM_CURRENT_PAGE__ = window.__CRM_INITIAL_PAGE__ || 'dashboard';
    enforce();
    bindLogout();
  });
})();
