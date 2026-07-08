/* CA Cloud Desk — Flatpickr-based date/time picker */
(function () {
  'use strict';

  var MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var instances = new WeakMap();
  var activeFlatpickr = null;
  var triggerBound = new WeakSet();

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function startOfDay(date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
  }

  function parseInputValue(value, mode) {
    if (!value || !String(value).trim()) return null;
    var raw = String(value).trim();
    if (mode === 'date' || /^\d{4}-\d{2}-\d{2}$/.test(raw)) {
      var dateOnly = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (dateOnly) {
        return new Date(parseInt(dateOnly[1], 10), parseInt(dateOnly[2], 10) - 1, parseInt(dateOnly[3], 10), 0, 0, 0, 0);
      }
    }
    var normalized = raw.length === 16 && raw.indexOf('T') === 10 ? raw + ':00' : raw;
    var d = new Date(normalized);
    if (!Number.isNaN(d.getTime())) return d;
    var match = raw.match(/^(\d{2})\/(\d{2})\/(\d{4}),?\s+(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
    if (!match) return null;
    var hour = parseInt(match[4], 10);
    var minute = parseInt(match[5], 10);
    var ampm = match[6].toUpperCase();
    if (ampm === 'PM' && hour < 12) hour += 12;
    if (ampm === 'AM' && hour === 12) hour = 0;
    return new Date(parseInt(match[3], 10), parseInt(match[2], 10) - 1, parseInt(match[1], 10), hour, minute, 0, 0);
  }

  function toLocalInputValue(date, mode) {
    if (!date || Number.isNaN(date.getTime())) return '';
    if (mode === 'date') {
      return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) +
      'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
  }

  function formatDisplay(date, mode) {
    if (!date || Number.isNaN(date.getTime())) return '';
    var dd = pad(date.getDate());
    var mon = MONTHS_SHORT[date.getMonth()];
    var yyyy = date.getFullYear();
    if (mode === 'date') return dd + ' ' + mon + ' ' + yyyy;
    var hour = date.getHours();
    var ampm = hour >= 12 ? 'PM' : 'AM';
    var h12 = hour % 12;
    if (h12 === 0) h12 = 12;
    return dd + ' ' + mon + ' ' + yyyy + ' • ' + pad(h12) + ':' + pad(date.getMinutes()) + ' ' + ampm;
  }

  function formatPreview(date, mode) {
    return formatDisplay(date, mode || 'datetime');
  }

  function isPast(date) {
    return date.getTime() <= Date.now();
  }

  function readEnhanceOptions(input) {
    var mode = input.getAttribute('data-crm-date-input') !== null || input.getAttribute('data-mode') === 'date'
      ? 'date'
      : 'datetime';
    var optional = input.hasAttribute('data-optional');
    var allowPast = input.hasAttribute('data-allow-past') || mode === 'date';
    var hidePreview = input.hasAttribute('data-hide-preview');
    return {
      mode: mode,
      optional: optional,
      allowPast: allowPast,
      hidePreview: hidePreview,
      requireFuture: mode === 'datetime' && !allowPast && !optional,
      previewPrefix: input.getAttribute('data-preview-prefix') || (mode === 'date' ? 'Date' : 'Scheduled for'),
      placeholder: input.getAttribute('data-placeholder') ||
        (optional ? 'Leave blank to send immediately' : (mode === 'date' ? 'Select Date' : 'Select Date & Time')),
      minDate: allowPast ? null : new Date(),
    };
  }

  function decorateCalendar(instance, options) {
    if (!instance || !instance.calendarContainer) return;
    instance.calendarContainer.classList.add('crm-fp-theme');
    if (instance.calendarContainer.querySelector('.crm-fp-footer')) return;

    var footer = document.createElement('div');
    footer.className = 'crm-fp-footer';
    footer.innerHTML =
      '<button type="button" class="btn-secondary btn-sm" data-fp-today type="button">Today</button>' +
      '<button type="button" class="btn-secondary btn-sm" data-fp-clear type="button">Clear</button>';
    instance.calendarContainer.appendChild(footer);

    footer.querySelector('[data-fp-today]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var today = options.mode === 'date' ? startOfDay(new Date()) : new Date();
      if (!options.allowPast && options.mode === 'datetime' && isPast(today)) {
        today.setMinutes(today.getMinutes() + 5 - (today.getMinutes() % 5), 0, 0);
      }
      instance.setDate(today, true);
      if (options.mode === 'date') instance.close();
    });

    footer.querySelector('[data-fp-clear]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      instance.clear();
      instance.close();
    });
  }

  function linkLabelToTrigger(alt) {
    if (!alt) return;
    var fieldRoot = alt.closest('.crm-datetime-field');
    if (!fieldRoot) return;

    var parent = fieldRoot.parentElement;
    if (!parent) return;

    var labels = parent.querySelectorAll('label[for]');
    labels.forEach(function (label) {
      var forId = label.getAttribute('for');
      if (!forId) return;
      var target = document.getElementById(forId);
      if (!target || target === alt || target.classList.contains('crm-datetime-flatpickr-source')) {
        if (!alt.id) {
          alt.id = forId.indexOf('trigger') >= 0 ? forId : (forId + '-trigger');
        }
        label.setAttribute('for', alt.id);
      }
    });

    if (!alt.id) {
      var source = fieldRoot.querySelector('.crm-datetime-flatpickr-source');
      alt.id = source && source.id ? source.id + '-trigger' : ('crm-dt-' + Math.random().toString(36).slice(2, 9));
    }
  }

  function isInsideClosedModal(el) {
    var modal = el && el.closest ? el.closest('.ca-modal') : null;
    return !!(modal && !modal.classList.contains('open'));
  }

  function bindPickerTriggers(fp, options) {
    var alt = fp.altInput;
    if (!alt || triggerBound.has(alt)) return;

    var wrap = alt.closest('.crm-datetime-field__input-wrap');
    var iconWrap = wrap ? wrap.querySelector('.crm-datetime-field__icon-wrap') : null;

    function openPicker(e) {
      if (isInsideClosedModal(alt)) return;
      if (e) {
        e.preventDefault();
        e.stopPropagation();
      }
      if (!fp.isOpen) {
        fp.open();
      }
    }

    alt.classList.add('crm-datetime-field__trigger', 'input-field');
    alt.setAttribute('aria-haspopup', 'dialog');
    if (!alt.placeholder) alt.placeholder = options.placeholder;

    alt.addEventListener('click', openPicker);
    alt.addEventListener('focus', function () {
      if (isInsideClosedModal(alt)) {
        try { alt.blur(); } catch (err) { /* ignore */ }
        return;
      }
      if (!fp.isOpen) fp.open();
    });

    if (iconWrap) {
      iconWrap.style.pointerEvents = 'auto';
      iconWrap.style.cursor = 'pointer';
      iconWrap.setAttribute('role', 'button');
      iconWrap.setAttribute('tabindex', '0');
      iconWrap.setAttribute('aria-label', options.mode === 'date' ? 'Open calendar' : 'Open date and time picker');
      iconWrap.addEventListener('click', openPicker);
      iconWrap.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') openPicker(e);
      });
    }

    linkLabelToTrigger(alt);
    triggerBound.add(alt);
  }

  function decorateAltInput(instance, options) {
    var alt = instance.altInput;
    if (!alt || alt.parentElement.classList.contains('crm-datetime-field__input-wrap')) return;

    var inputWrap = document.createElement('div');
    inputWrap.className = 'crm-datetime-field__input-wrap';
    alt.parentNode.insertBefore(inputWrap, alt);
    inputWrap.appendChild(alt);

    var iconWrap = document.createElement('span');
    iconWrap.className = 'crm-datetime-field__icon-wrap';
    iconWrap.innerHTML = '<i data-lucide="' + (options.mode === 'date' ? 'calendar' : 'calendar-clock') + '" class="crm-datetime-field__icon h-4 w-4"></i>';
    inputWrap.appendChild(iconWrap);

    if (typeof lucide !== 'undefined') lucide.createIcons();
  }

  function updatePreviewRow(instance, options, previewEl) {
    if (!previewEl || options.hidePreview) {
      if (previewEl) {
        previewEl.classList.add('hidden');
        previewEl.textContent = '';
      }
      return;
    }
    if (!instance.selectedDates.length) {
      previewEl.classList.add('hidden');
      previewEl.textContent = '';
      return;
    }
    previewEl.classList.remove('hidden');
    previewEl.textContent = options.previewPrefix + ' ' + formatPreview(instance.selectedDates[0], options.mode);
  }

  function buildFlatpickrPlugins(isDateOnly) {
    if (isDateOnly || typeof confirmDatePlugin === 'undefined') return [];
    return [new confirmDatePlugin({
      confirmText: 'Done',
      showAlways: true,
      theme: 'light',
    })];
  }

  function unwrapField(input) {
    var field = input.closest('.crm-datetime-field');
    if (!field || !field.parentNode) return;
    field.parentNode.insertBefore(input, field);
    field.remove();
    input.classList.remove('crm-datetime-flatpickr-source');
    input.removeAttribute('tabindex');
    input.removeAttribute('aria-hidden');
  }

  function destroyInstance(input) {
    if (!instances.has(input)) {
      unwrapField(input);
      return;
    }
    var inst = instances.get(input);
    if (inst.flatpickr && inst.flatpickr.altInput) {
      triggerBound.delete(inst.flatpickr.altInput);
    }
    try {
      inst.flatpickr.destroy();
    } catch (e) { /* ignore */ }
    instances.delete(input);
    unwrapField(input);
  }

  function hideSourceInput(input) {
    input.classList.add('crm-datetime-flatpickr-source');
    input.setAttribute('tabindex', '-1');
    input.setAttribute('aria-hidden', 'true');
  }

  function enhanceInput(input, overrideOptions) {
    if (!input || input.disabled) return null;
    if (typeof flatpickr === 'undefined') return null;

    if (instances.has(input)) {
      var existing = instances.get(input);
      if (existing && existing.flatpickr && existing.trigger && document.contains(existing.trigger)) {
        return existing;
      }
      destroyInstance(input);
    }

    var options = Object.assign(readEnhanceOptions(input), overrideOptions || {});
    var mode = options.mode || 'datetime';
    var isDateOnly = mode === 'date';

    var wrap = document.createElement('div');
    wrap.className = 'crm-datetime-field' + (isDateOnly ? ' crm-datetime-field--date' : '');
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    if (input.type === 'hidden') input.type = 'text';
    if (input.type === 'date') input.type = 'text';
    input.setAttribute('autocomplete', 'off');
    input.classList.remove('crm-datetime-flatpickr-source');

    var preview = document.createElement('p');
    preview.className = 'crm-datetime-field__preview hidden text-caption mt-1';
    if (options.hidePreview) preview.classList.add('crm-datetime-field__preview--hidden');
    wrap.appendChild(preview);

    var error = document.createElement('p');
    error.className = 'crm-datetime-field__error hidden text-caption mt-1';
    error.textContent = isDateOnly ? 'Please select a date.' : 'Please select a future date and time.';
    wrap.appendChild(error);

    var fp = flatpickr(input, {
      enableTime: !isDateOnly,
      dateFormat: isDateOnly ? 'Y-m-d' : 'Y-m-d\\TH:i',
      altInput: true,
      altFormat: isDateOnly ? 'j M Y' : 'j M Y • h:i K',
      altInputClass: 'crm-datetime-field__trigger input-field',
      allowInput: true,
      clickOpens: true,
      minuteIncrement: 5,
      time_24hr: false,
      appendTo: document.body,
      position: 'auto',
      disableMobile: true,
      monthSelectorType: 'dropdown',
      shorthandCurrentMonth: false,
      minDate: options.allowPast ? null : (options.minDate || 'today'),
      plugins: buildFlatpickrPlugins(isDateOnly),
      onReady: function (selectedDates, dateStr, instance) {
        hideSourceInput(input);
        decorateCalendar(instance, options);
        decorateAltInput(instance, options);
        bindPickerTriggers(instance, options);
        updatePreviewRow(instance, options, preview);
      },
      onOpen: function (selectedDates, dateStr, instance) {
        activeFlatpickr = instance;
        error.classList.add('hidden');
        if (instance.altInput) instance.altInput.classList.remove('crm-datetime-field__trigger--error');
        requestAnimationFrame(function () {
          instance._positionCalendar();
        });
      },
      onClose: function () {
        if (activeFlatpickr && activeFlatpickr.input === input) activeFlatpickr = null;
      },
      onChange: function (selectedDates, dateStr, instance) {
        updatePreviewRow(instance, options, preview);
        error.classList.add('hidden');
        if (instance.altInput) instance.altInput.classList.remove('crm-datetime-field__trigger--error');
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.dispatchEvent(new Event('input', { bubbles: true }));
        if (isDateOnly && selectedDates.length) instance.close();
      },
    });

    var instance = {
      input: input,
      trigger: fp.altInput,
      preview: preview,
      error: error,
      flatpickr: fp,
      options: options,
      open: function () {
        if (!fp.isOpen) fp.open();
      },
      getValue: function () {
        return parseInputValue(input.value, mode);
      },
      showError: function (message) {
        error.textContent = message || error.textContent;
        error.classList.remove('hidden');
        if (fp.altInput) fp.altInput.classList.add('crm-datetime-field__trigger--error');
      },
      clearError: function () {
        error.classList.add('hidden');
        if (fp.altInput) fp.altInput.classList.remove('crm-datetime-field__trigger--error');
      },
      setValue: function (date, opts) {
        opts = opts || {};
        this.clearError();
        if (!date) {
          fp.clear();
        } else {
          fp.setDate(date, false);
        }
        updatePreviewRow(fp, options, preview);
        if (!opts.silent) {
          input.dispatchEvent(new Event('change', { bubbles: true }));
          input.dispatchEvent(new Event('input', { bubbles: true }));
        }
      },
      destroy: function () {
        destroyInstance(input);
      },
    };

    if (input.value) {
      var parsed = parseInputValue(input.value, mode);
      if (parsed) fp.setDate(parsed, false);
    }
    updatePreviewRow(fp, options, preview);

    instances.set(input, instance);
    return instance;
  }

  function initAll(root, opts) {
    opts = opts || {};
    root = root || document;

    root.querySelectorAll('[data-crm-datetime-input]').forEach(function (input) {
      if (opts.force) destroyInstance(input);
      enhanceInput(input);
    });

    root.querySelectorAll('[data-crm-date-input]').forEach(function (input) {
      if (opts.force) destroyInstance(input);
      enhanceInput(input, { mode: 'date', allowPast: true, requireFuture: false });
    });
  }

  function closePopup(opts) {
    opts = opts || {};
    if (activeFlatpickr) {
      activeFlatpickr.close();
      if (opts.focus !== false && activeFlatpickr.altInput) {
        try { activeFlatpickr.altInput.focus(); } catch (e) { /* ignore */ }
      }
    }
  }

  function syncInput(input) {
    var instance = instances.get(input);
    if (!instance) return;
    var mode = instance.options.mode || 'datetime';
    if (!input.value) {
      instance.setValue(null, { silent: true });
      return;
    }
    var parsed = parseInputValue(input.value, mode);
    if (parsed) instance.setValue(parsed, { silent: true });
  }

  function syncAll(root) {
    root = root || document;
    root.querySelectorAll('[data-crm-datetime-input], [data-crm-date-input]').forEach(syncInput);
  }

  function openForInput(input) {
    if (isInsideClosedModal(input)) return false;
    var instance = instances.get(input);
    if (instance) {
      instance.open();
      return true;
    }
    var enhanced = enhanceInput(input);
    if (enhanced) {
      enhanced.open();
      return true;
    }
    return false;
  }

  document.addEventListener('mousedown', function (e) {
    if (!activeFlatpickr) return;
    if (e.target.closest && e.target.closest('.flatpickr-calendar')) return;
    if (e.target.closest && e.target.closest('.crm-datetime-field')) return;
    activeFlatpickr.close();
  }, true);

  document.addEventListener('click', function (e) {
    var icon = e.target.closest('.crm-datetime-field__icon-wrap');
    if (icon) {
      var field = icon.closest('.crm-datetime-field');
      var source = field && field.querySelector('[data-crm-datetime-input], [data-crm-date-input]');
      if (source) {
        e.preventDefault();
        e.stopPropagation();
        openForInput(source);
      }
      return;
    }
    var trigger = e.target.closest('.crm-datetime-field__trigger');
    if (trigger) {
      var fieldRoot = trigger.closest('.crm-datetime-field');
      var src = fieldRoot && fieldRoot.querySelector('[data-crm-datetime-input], [data-crm-date-input]');
      if (src && !instances.has(src)) {
        openForInput(src);
      }
    }
  }, true);

  window.addEventListener('resize', function () {
    if (activeFlatpickr) activeFlatpickr._positionCalendar();
  });

  window.CrmDateTimePicker = {
    initAll: initAll,
    enhance: enhanceInput,
    syncAll: syncAll,
    syncInput: syncInput,
    open: openForInput,
    formatDisplay: formatDisplay,
    formatPreview: formatPreview,
    parseValue: function (value) { return parseInputValue(value, 'datetime'); },
    parseDateValue: function (value) { return parseInputValue(value, 'date'); },
    toLocalInputValue: toLocalInputValue,
    isPast: isPast,
    close: closePopup,
    isOpen: function () { return !!(activeFlatpickr && activeFlatpickr.isOpen); },
  };
})();
