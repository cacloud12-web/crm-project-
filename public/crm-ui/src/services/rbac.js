/* global window, document */
(function () {
  'use strict';

  var PAGE_ACCESS = {
    dashboard: { module: 'dashboard', permission: 'view' },
    'ca-master': { module: 'ca_master', permission: 'view' },
    leads: { module: 'leads', permission: 'view' },
    assignment: { module: 'assignment', permission: 'view' },
    followups: { module: 'followups', permission: 'view' },
    bulk: { module: 'bulk', permission: 'view' },
    communication: { module: 'campaigns', permission: 'view' },
    whatsapp: { module: 'campaigns', permission: 'view' },
    sms: { module: 'campaigns', permission: 'view' },
    email: { module: 'campaigns', permission: 'view' },
    'consent-dnd': { module: 'consent', permission: 'view' },
    reports: { module: 'reports', permission: 'reports' },
    activity: { module: 'activity', permission: 'view' },
    security: { module: 'security', permission: 'view' },
    queue: { module: 'admin', permission: 'view' },
    'db-health': { module: 'admin', permission: 'reports' },
    settings: { module: 'settings', permission: 'view' },
    notifications: { module: 'dashboard', permission: 'view' },
    payments: { module: 'dashboard', permission: 'view' },
  };

  var PAGE_MODULE = {
    dashboard: 'dashboard',
    'ca-master': 'ca_master',
    leads: 'leads',
    assignment: 'assignment',
    followups: 'followups',
    bulk: 'bulk',
    communication: 'campaigns',
    whatsapp: 'campaigns',
    sms: 'campaigns',
    email: 'campaigns',
    'consent-dnd': 'consent',
    reports: 'reports',
    activity: 'activity',
    security: 'security',
    queue: 'admin',
    'db-health': 'admin',
    settings: 'settings',
  };

  var ACTION_RULES = [
    { selector: '[data-open-modal="add-lead"]', module: 'ca_master', permission: 'create' },
    { selector: '[data-open-modal="add-employee"]', module: 'employees', permission: 'create' },
    { selector: '[data-open-modal="assign-lead"]', module: 'assignment', permission: 'create' },
    { selector: '[data-open-modal="followup"]', module: 'followups', permission: 'create' },
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
  ];

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
    document.querySelectorAll('.nav-item[data-page]').forEach(function (item) {
      var page = item.dataset.page;
      var rule = PAGE_ACCESS[page];
      if (!rule || !can(rule.module, rule.permission)) hideElement(item);
    });

    document.querySelectorAll('.header-settings-item[data-page]').forEach(function (item) {
      var page = item.dataset.page;
      var rule = PAGE_ACCESS[page];
      if (!rule || !can(rule.module, rule.permission)) hideElement(item);
    });

  }

  function applyActionAccess() {
    ACTION_RULES.forEach(function (rule) {
      document.querySelectorAll(rule.selector).forEach(function (el) {
        var module = rule.usePageModule ? currentPageModule() : rule.module;
        if (!can(module, rule.permission)) hideElement(el);
      });
    });

    document.querySelectorAll('form[data-entity-delete]').forEach(function (el) {
      if (!can(el.dataset.entityModule || currentPageModule(), 'delete')) hideElement(el);
    });

    document.querySelectorAll('[data-sms-campaign-create]').forEach(function (el) {
      var u = user();
      if (!u.authenticated || (u.role !== 'admin' && u.role !== 'super_admin')) hideElement(el);
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
    var passwordItem = document.getElementById('profile-change-password');
    var resetItem = document.getElementById('profile-reset-employee-password');
    var settingsBtn = document.getElementById('settings-btn');
    var passwordLabel = document.getElementById('profile-password-label');

    if (passwordItem) {
      if (isCredentialAdmin) passwordItem.classList.remove('hidden');
      else hideElement(passwordItem);
    }
    if (resetItem) {
      if (isCredentialAdmin) resetItem.classList.remove('hidden');
      else hideElement(resetItem);
    }
    if (settingsBtn) {
      if (isEmployee) hideElement(settingsBtn.closest('.header-settings-wrap') || settingsBtn);
      else if (can('settings', 'view')) {
        var wrap = settingsBtn.closest('.header-settings-wrap');
        if (wrap) wrap.classList.remove('hidden');
      }
    }
    if (passwordLabel && isCredentialAdmin) passwordLabel.textContent = 'Change Password';
  }

  function enforce() {
    applyNavAccess();
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
    var footerBtns = document.querySelectorAll('.ca-sidebar-footer-btn');
    footerBtns.forEach(function (btn, index) {
      if (index === 2) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          logout();
        });
      } else if (index === 1) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          if (can('security', 'view') && window.navigateTo) window.navigateTo('security');
        });
      }
    });
  }

  function onPageChange(pageId) {
    window.__CRM_CURRENT_PAGE__ = pageId;
    setTimeout(enforce, 0);
  }

  window.CA_RBAC = {
    can: can,
    enforce: enforce,
    onPageChange: onPageChange,
    logout: logout,
    user: user,
  };

  document.addEventListener('DOMContentLoaded', function () {
    window.__CRM_CURRENT_PAGE__ = window.__CRM_INITIAL_PAGE__ || 'dashboard';
    enforce();
    bindLogout();
  });
})();
