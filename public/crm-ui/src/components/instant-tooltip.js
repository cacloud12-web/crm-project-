/**
 * Instant CRM tooltips — replaces native title delay with zero-latency floating tips.
 */
(function () {
  'use strict';

  var tipEl = null;
  var activeEl = null;
  var placement = 'top';
  var initialized = false;
  var observer = null;

  function esc(text) {
    return String(text || '').trim();
  }

  function ensureTip() {
    if (tipEl) return tipEl;
    tipEl = document.createElement('div');
    tipEl.id = 'crm-instant-tooltip';
    tipEl.setAttribute('role', 'tooltip');
    tipEl.hidden = true;
    document.body.appendChild(tipEl);
    return tipEl;
  }

  function tipText(el) {
    if (!el) return '';
    return esc(el.getAttribute('data-crm-tip') || el.getAttribute('title') || el.getAttribute('aria-label'));
  }

  function migrateElement(el) {
    if (!el || el.hasAttribute('data-crm-no-tip')) return;
    var text = el.getAttribute('title');
    if (text && !el.hasAttribute('data-crm-tip')) {
      el.setAttribute('data-crm-tip', text);
    }
    if (el.hasAttribute('title')) {
      el.removeAttribute('title');
    }
  }

  function migrateTree(root) {
    if (!root || root.nodeType !== 1) return;
    if (root.matches && root.matches('[title], [data-crm-tip]')) migrateElement(root);
    if (!root.querySelectorAll) return;
    root.querySelectorAll('[title], [data-crm-tip]').forEach(migrateElement);
  }

  function position(el) {
    var tip = ensureTip();
    if (!el || tip.hidden) return;

    var rect = el.getBoundingClientRect();
    var tipRect = tip.getBoundingClientRect();
    var gap = 8;
    var left = rect.left + rect.width / 2 - tipRect.width / 2;
    var top = rect.top - tipRect.height - gap;

    placement = 'top';
    if (top < 8) {
      top = rect.bottom + gap;
      placement = 'bottom';
    }

    left = Math.max(8, Math.min(left, window.innerWidth - tipRect.width - 8));
    top = Math.max(8, Math.min(top, window.innerHeight - tipRect.height - 8));

    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
    tip.setAttribute('data-placement', placement);
  }

  function show(el) {
    migrateElement(el);
    var text = tipText(el);
    if (!text) {
      hide();
      return;
    }

    var tip = ensureTip();
    tip.textContent = text;
    tip.hidden = false;
    tip.classList.add('is-visible');
    activeEl = el;
    position(el);
  }

  function hide() {
    if (!tipEl) return;
    tipEl.hidden = true;
    tipEl.classList.remove('is-visible');
    activeEl = null;
  }

  function onPointerOver(e) {
    var el = e.target && e.target.closest ? e.target.closest('[data-crm-tip], [title]') : null;
    if (!el || el.hasAttribute('data-crm-no-tip')) {
      if (activeEl && e.target && !activeEl.contains(e.target)) hide();
      return;
    }
    if (el === activeEl) return;
    show(el);
  }

  function onPointerOut(e) {
    if (!activeEl) return;
    var related = e.relatedTarget;
    if (related && activeEl.contains(related)) return;
    var from = e.target && e.target.closest ? e.target.closest('[data-crm-tip], [title]') : null;
    if (from !== activeEl) return;
    if (related && from && from.contains(related)) return;
    hide();
  }

  function onFocusIn(e) {
    var el = e.target && e.target.closest ? e.target.closest('[data-crm-tip], [title]') : null;
    if (!el || el.hasAttribute('data-crm-no-tip')) return;
    show(el);
  }

  function onFocusOut(e) {
    if (!activeEl) return;
    if (e.target === activeEl || activeEl.contains(e.target)) {
      var related = e.relatedTarget;
      if (!related || !activeEl.contains(related)) hide();
    }
  }

  function onScrollOrResize() {
    if (activeEl) position(activeEl);
  }

  function bindObserver() {
    if (observer || typeof MutationObserver === 'undefined') return;
    observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        mutation.addedNodes.forEach(function (node) {
          migrateTree(node);
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  function init() {
    if (initialized) return;
    initialized = true;

    migrateTree(document.body);
    bindObserver();

    document.addEventListener('pointerover', onPointerOver, true);
    document.addEventListener('pointerout', onPointerOut, true);
    document.addEventListener('focusin', onFocusIn, true);
    document.addEventListener('focusout', onFocusOut, true);
    window.addEventListener('scroll', onScrollOrResize, true);
    window.addEventListener('resize', onScrollOrResize);
  }

  window.CrmInstantTooltip = {
    init: init,
    refresh: migrateTree,
    show: show,
    hide: hide,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
