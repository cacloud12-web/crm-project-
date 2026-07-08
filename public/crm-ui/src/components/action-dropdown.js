/**
 * Reusable three-dot row action dropdown for CRM data tables.
 * window.CAActionDropdown — render menus, position in viewport, delegated events.
 */
(function () {
  'use strict';

  var _handlers = {};
  var _initialized = false;

  function esc(val) {
    if (val == null) return '';
    return String(val)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function attrToKey(name) {
    return name.replace(/-([a-z])/g, function (_, c) { return c.toUpperCase(); });
  }

  function register(scope, handler) {
    if (!scope || typeof handler !== 'function') return;
    _handlers[scope] = handler;
  }

  function restoreMenu(menu) {
    if (!menu) return;
    var anchor = menu._actionMenuAnchor;
    if (anchor && menu.parentElement === document.body) {
      anchor.appendChild(menu);
    }
  }

  function iconsIn(root) {
    if (typeof lucide === 'undefined' || !root) return;
    var nodes = root.querySelectorAll ? root.querySelectorAll('[data-lucide]') : [];
    if (!nodes.length) return;
    try {
      lucide.createIcons({ nodes: nodes });
      return;
    } catch (e1) {
      try {
        lucide.createIcons({ root: root });
      } catch (e2) {
        /* fallback: skip full-document scan */
      }
    }
  }

  function closeAll() {
    document.querySelectorAll('.crm-row-menu-open').forEach(function (row) {
      row.classList.remove('crm-row-menu-open');
    });
    document.querySelectorAll('.crm-actions-dropdown--open, .action-dropdown--open').forEach(function (menu) {
      menu.classList.add('hidden');
      menu.classList.remove('crm-actions-dropdown--open', 'action-dropdown--open');
      menu.style.position = '';
      menu.style.top = '';
      menu.style.left = '';
      menu.style.right = '';
      menu.style.zIndex = '';
      menu.style.visibility = '';
      menu.setAttribute('aria-hidden', 'true');
      restoreMenu(menu);
    });
    document.querySelectorAll('[data-action-menu-trigger][aria-expanded="true"]').forEach(function (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
    });
  }

  function findMenu(trigger) {
    if (!trigger) return null;
    var wrap = trigger.closest('.crm-actions-menu, .action-menu');
    return wrap ? wrap.querySelector('.crm-actions-dropdown, .action-dropdown') : null;
  }

  function position(trigger, menu) {
    if (!menu || !trigger) return;
    if (!menu._actionMenuAnchor) {
      menu._actionMenuAnchor = menu.parentElement;
    }
    if (menu.parentElement !== document.body) {
      document.body.appendChild(menu);
    }
    menu.style.visibility = 'hidden';
    menu.style.position = 'fixed';
    menu.style.zIndex = '1300';
    menu.classList.remove('hidden');
    var rect = trigger.getBoundingClientRect();
    var menuWidth = menu.offsetWidth || 200;
    var menuHeight = menu.offsetHeight || 0;
    var gap = 6;
    var pad = 8;
    var left = Math.min(Math.max(pad, rect.right - menuWidth), window.innerWidth - menuWidth - pad);
    var spaceBelow = window.innerHeight - rect.bottom - pad;
    var spaceAbove = rect.top - pad;
    var top;
    if (spaceBelow >= menuHeight + gap || spaceBelow >= spaceAbove) {
      top = rect.bottom + gap;
      menu.setAttribute('data-action-menu-placement', 'bottom');
    } else {
      top = Math.max(pad, rect.top - menuHeight - gap);
      menu.setAttribute('data-action-menu-placement', 'top');
    }
    if (top + menuHeight > window.innerHeight - pad) {
      top = Math.max(pad, window.innerHeight - menuHeight - pad);
    }
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    menu.style.right = 'auto';
    menu.style.visibility = '';
  }

  function renderItem(item) {
    var cls = 'crm-actions-item action-dropdown-item' +
      (item.danger ? ' crm-actions-item--danger action-dropdown-item--danger' : '');
    var attrs = ' data-row-action="' + esc(item.action) + '" role="menuitem"';
    if (item.disabled) attrs += ' disabled aria-disabled="true"';
    if (item.dataAttrs) {
      Object.keys(item.dataAttrs).forEach(function (key) {
        attrs += ' data-' + key + '="' + esc(item.dataAttrs[key]) + '"';
      });
    }
    var icon = item.icon
      ? '<i data-lucide="' + esc(item.icon) + '" class="h-4 w-4" aria-hidden="true"></i> '
      : '';
    return '<button type="button" class="' + cls + '"' + attrs + '>' + icon + esc(item.label) + '</button>';
  }

  function renderDivider() {
    return '<div class="crm-actions-divider" role="separator" aria-hidden="true"></div>';
  }

  function renderCommunicationRow(items) {
    if (!items || !items.length) return '';
    return '<div class="crm-actions-comm" role="group" aria-label="Communication">' +
      items.map(function (item) {
        var cls = 'crm-actions-comm-btn crm-actions-comm-btn--' + esc(item.action || 'item');
        var attrs = ' data-row-action="' + esc(item.action) + '" role="menuitem"';
        attrs += ' data-crm-tip="' + esc(item.label) + '" aria-label="' + esc(item.label) + '"';
        if (item.disabled) attrs += ' disabled aria-disabled="true"';
        if (item.dataAttrs) {
          Object.keys(item.dataAttrs).forEach(function (key) {
            attrs += ' data-' + key + '="' + esc(item.dataAttrs[key]) + '"';
          });
        }
        return '<button type="button" class="' + cls + '"' + attrs + '>' +
          '<i data-lucide="' + esc(item.icon) + '" class="h-4 w-4" aria-hidden="true"></i>' +
        '</button>';
      }).join('') +
    '</div>';
  }

  function renderMenuEntry(item) {
    if (!item) return '';
    if (item.type === 'divider') return renderDivider();
    if (item.type === 'communication') return renderCommunicationRow(item.items);
    return renderItem(item);
  }

  function renderMenu(items, opts) {
    opts = opts || {};
    if (!items || !items.length) return '';
    var scope = esc(opts.scope || 'row');
    var rowId = esc(opts.rowId != null ? String(opts.rowId) : '');
    var icon = esc(opts.icon || 'more-vertical');
    var alignClass = opts.align === 'left' ? 'action-menu--align-left' : '';
    return '<div class="crm-actions-menu action-menu ' + alignClass + '">' +
      '<button type="button" class="crm-actions-trigger action-menu-trigger" data-action-menu-trigger data-action-menu-scope="' + scope + '" data-action-menu-id="' + rowId + '" aria-label="' + esc(opts.ariaLabel || 'Row actions') + '" aria-haspopup="menu" aria-expanded="false">' +
        '<i data-lucide="' + icon + '" class="h-4 w-4" aria-hidden="true"></i>' +
      '</button>' +
      '<div class="crm-actions-dropdown action-dropdown hidden" role="menu" aria-hidden="true" data-action-menu data-action-menu-scope="' + scope + '" data-action-menu-id="' + rowId + '">' +
        items.map(renderMenuEntry).join('') +
      '</div>' +
    '</div>';
  }

  function renderCell(items, opts) {
    opts = opts || {};
    var cellClass = opts.cellClass || 'crm-actions-cell sticky-right col-actions';
    if (!items || !items.length) {
      return '<td class="' + cellClass + '"><span class="cam-cell-empty">—</span></td>';
    }
    return '<td class="' + cellClass + '">' + renderMenu(items, opts) + '</td>';
  }

  function renderInline(items, opts) {
    if (!items || !items.length) return '<span class="cam-cell-empty">—</span>';
    return renderMenu(items, opts);
  }

  function readDataset(btn) {
    var dataset = {};
    Array.prototype.forEach.call(btn.attributes, function (attr) {
      if (attr.name.indexOf('data-') !== 0 || attr.name === 'data-row-action') return;
      var raw = attr.name.slice(5);
      dataset[attrToKey(raw)] = attr.value;
    });
    var menu = btn.closest('[data-action-menu]');
    if (menu) {
      dataset.menuScope = menu.getAttribute('data-action-menu-scope') || '';
      dataset.menuId = menu.getAttribute('data-action-menu-id') || '';
    }
    return dataset;
  }

  function openMenu(trigger, menu) {
    closeAll();
    if (!menu._actionMenuAnchor) {
      menu._actionMenuAnchor = menu.parentElement;
    }
    menu.classList.remove('hidden');
    position(trigger, menu);
    menu.classList.add('crm-actions-dropdown--open', 'action-dropdown--open');
    menu.setAttribute('aria-hidden', 'false');
    trigger.setAttribute('aria-expanded', 'true');
    var row = trigger.closest('tr, .assign-mobile-card, article, .campaign-card');
    if (row) row.classList.add('crm-row-menu-open');
    iconsIn(menu);
    requestAnimationFrame(function () {
      var first = menu.querySelector('[role="menuitem"]:not([disabled])');
      if (first) first.focus();
    });
  }

  function handleActionClick(btn) {
    var action = btn.getAttribute('data-row-action');
    if (!action) return;
    var dataset = readDataset(btn);
    var scope = dataset.menuScope || '';
    delete dataset.menuScope;
    var handler = _handlers[scope];
    closeAll();
    if (handler) {
      handler(action, dataset, btn);
      return;
    }
    document.dispatchEvent(new CustomEvent('ca-action-dropdown', {
      detail: { scope: scope, action: action, dataset: dataset, button: btn },
    }));
  }

  function bindScrollDismiss(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var nodes = scope.querySelectorAll
      ? scope.querySelectorAll('.table-scroll-container, .crm-table-container, .assign-active__table-wrap, #assignment-table-wrap, .ecfg-table-wrap')
      : [];
    nodes.forEach(function (el) {
      if (el._actionMenuScrollDismiss) return;
      el._actionMenuScrollDismiss = true;
      el.addEventListener('scroll', closeAll, { passive: true });
    });
  }

  function init() {
    if (_initialized) return;
    _initialized = true;

    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-action-menu-trigger]');
      if (trigger) {
        e.preventDefault();
        e.stopPropagation();
        var menu = findMenu(trigger);
        var wasOpen = menu && menu.classList.contains('crm-actions-dropdown--open');
        if (wasOpen) closeAll();
        else if (menu) openMenu(trigger, menu);
        return;
      }

      var actionBtn = e.target.closest('[data-row-action]');
      if (actionBtn && actionBtn.closest('[data-action-menu]')) {
        e.preventDefault();
        e.stopPropagation();
        handleActionClick(actionBtn);
        return;
      }

      if (!e.target.closest('.crm-actions-dropdown, .action-dropdown')) {
        closeAll();
      }
    }, true);

    document.addEventListener('keydown', function (e) {
      var menu = document.querySelector('.crm-actions-dropdown--open, .action-dropdown--open');
      if (e.key === 'Escape') {
        if (menu) {
          e.preventDefault();
          closeAll();
        }
        return;
      }
      if (!menu) return;
      var items = Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]:not([disabled])'));
      if (!items.length) return;
      var idx = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        items[(idx + 1) % items.length].focus();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        items[idx <= 0 ? items.length - 1 : idx - 1].focus();
      } else if (e.key === 'Home') {
        e.preventDefault();
        items[0].focus();
      } else if (e.key === 'End') {
        e.preventDefault();
        items[items.length - 1].focus();
      } else if (e.key === 'Enter' || e.key === ' ') {
        if (document.activeElement && document.activeElement.matches('[role="menuitem"]')) {
          e.preventDefault();
          handleActionClick(document.activeElement);
        }
      }
    });

    document.addEventListener('keydown', function (e) {
      var trigger = e.target.closest('[data-action-menu-trigger]');
      if (!trigger) return;
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        var menu = findMenu(trigger);
        if (menu && !menu.classList.contains('crm-actions-dropdown--open')) openMenu(trigger, menu);
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        var openMenuEl = findMenu(trigger);
        if (openMenuEl) openMenu(trigger, openMenuEl);
      }
    });

    window.addEventListener('scroll', closeAll, true);
    window.addEventListener('resize', closeAll);
  }

  window.CAActionDropdown = {
    register: register,
    renderMenu: renderMenu,
    renderCell: renderCell,
    renderInline: renderInline,
    closeAll: closeAll,
    position: position,
    findMenu: findMenu,
    bindScrollDismiss: bindScrollDismiss,
    iconsIn: iconsIn,
    init: init,
  };
})();
