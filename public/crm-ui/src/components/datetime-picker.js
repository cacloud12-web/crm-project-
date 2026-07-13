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
    var minuteIncrement = parseInt(input.getAttribute('data-minute-increment'), 10);
    if (!minuteIncrement || minuteIncrement < 1) minuteIncrement = 5;
    return {
      mode: mode,
      optional: optional,
      allowPast: allowPast,
      hidePreview: hidePreview,
      hideCalendarPreview: input.hasAttribute('data-hide-calendar-preview'),
      preferAbove: input.hasAttribute('data-picker-prefer-above'),
      minuteIncrement: minuteIncrement,
      requireFuture: mode === 'datetime' && !allowPast && !optional,
      previewPrefix: input.getAttribute('data-preview-prefix') || (mode === 'date' ? 'Date' : 'Scheduled for'),
      placeholder: input.getAttribute('data-placeholder') ||
        (optional ? 'Leave blank to send immediately' : (mode === 'date' ? 'Select Date' : 'Select Date & Time')),
      minDate: allowPast ? null : new Date(),
    };
  }

  function decorateCalendar(instance, options) {
    if (!instance || !instance.calendarContainer) return;
    instance.calendarContainer.classList.add('crm-fp-theme', 'crm-fp-body-portal');
    instance.calendarContainer.classList.toggle('crm-fp-no-preview', !!options.hideCalendarPreview);
    if (options.mode !== 'date') {
      decorateTimeSection(instance);
    }
    if (instance.calendarContainer.querySelector('.crm-fp-footer')) {
      updateCalendarPreview(instance, options);
      return;
    }

    if (options.mode !== 'date' && !options.hideCalendarPreview) {
      var preview = document.createElement('div');
      preview.className = 'crm-fp-selection-preview crm-fp-selection-preview--empty';
      preview.setAttribute('aria-live', 'polite');
      preview.textContent = 'Select date and time';
      instance._crmPreviewEl = preview;
      instance.calendarContainer.appendChild(preview);
    }

    var footer = document.createElement('div');
    footer.className = 'crm-fp-footer';
    footer.innerHTML =
      '<div class="crm-fp-footer__left">' +
        '<button type="button" class="btn-secondary btn-sm" data-fp-today type="button">Today</button>' +
        '<button type="button" class="btn-secondary btn-sm" data-fp-clear type="button">Clear</button>' +
      '</div>' +
      '<div class="crm-fp-footer__right">' +
        '<button type="button" class="btn-secondary btn-sm" data-fp-cancel type="button">Cancel</button>' +
        '<button type="button" class="btn-primary btn-sm" data-fp-done type="button">Done</button>' +
      '</div>';
    instance.calendarContainer.appendChild(footer);

    footer.querySelector('[data-fp-today]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var today = options.mode === 'date' ? startOfDay(new Date()) : new Date();
      if (!options.allowPast && options.mode === 'datetime' && isPast(today)) {
        today.setMinutes(today.getMinutes() + 5 - (today.getMinutes() % 5), 0, 0);
      }
      instance.setDate(today, true);
      updateCalendarPreview(instance, options);
      if (options.mode === 'date') instance.close();
    });

    footer.querySelector('[data-fp-clear]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      instance.clear();
      updateCalendarPreview(instance, options);
      instance.close();
    });

    footer.querySelector('[data-fp-cancel]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      revertPickerSnapshot(instance);
      instance.close();
    });

    footer.querySelector('[data-fp-done]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      instance.close();
    });
  }

  function decorateTimeSection(instance) {
    var timeEl = instance.calendarContainer.querySelector('.flatpickr-time');
    if (!timeEl || timeEl.closest('.crm-fp-time-wrap')) return;
    var wrap = document.createElement('div');
    wrap.className = 'crm-fp-time-wrap';
    var label = document.createElement('div');
    label.className = 'crm-fp-time-label';
    label.id = 'crm-fp-time-label-' + (instance.input.id || 'picker');
    label.textContent = 'Time';
    timeEl.setAttribute('aria-labelledby', label.id);
    timeEl.parentNode.insertBefore(wrap, timeEl);
    wrap.appendChild(label);
    wrap.appendChild(timeEl);
  }

  function updateCalendarPreview(instance, options) {
    if (!instance._crmPreviewEl || options.mode === 'date') return;
    if (!instance.selectedDates.length) {
      instance._crmPreviewEl.textContent = 'Select date and time';
      instance._crmPreviewEl.classList.add('crm-fp-selection-preview--empty');
      return;
    }
    instance._crmPreviewEl.classList.remove('crm-fp-selection-preview--empty');
    instance._crmPreviewEl.textContent = 'Selected: ' + formatPreview(instance.selectedDates[0], options.mode);
  }

  function capturePickerSnapshot(instance) {
    instance._crmOpenSnapshot = instance.input.value || '';
  }

  function revertPickerSnapshot(instance) {
    var snapshot = instance._crmOpenSnapshot || '';
    if (!snapshot) {
      instance.clear();
      return;
    }
    var mode = instance.input.getAttribute('data-crm-date-input') !== null ? 'date' : 'datetime';
    var parsed = parseInputValue(snapshot, mode);
    if (parsed) instance.setDate(parsed, false);
    else instance.clear();
  }

  function positionPickerCalendar(instance) {
    if (!instance || !instance.calendarContainer) return;
    var trigger = instance.altInput || instance.input;
    var cal = instance.calendarContainer;
    if (!trigger || !cal) return;

    var options = instances.get(instance.input);
    var pickerOptions = options && options.options ? options.options : readEnhanceOptions(instance.input);

    if (cal.parentNode !== document.body) {
      document.body.appendChild(cal);
    }
    instance.config.appendTo = document.body;

    var isMobile = window.matchMedia('(max-width: 640px)').matches;
    var inFollowupModal = !!trigger.closest('#modal-followup');
    var preferAbove = pickerOptions.preferAbove || inFollowupModal;

    cal.style.position = 'fixed';
    cal.style.margin = '0';
    cal.style.right = 'auto';
    cal.style.bottom = 'auto';
    cal.style.zIndex = '12050';
    cal.classList.remove('crm-fp-above', 'crm-fp-below', 'crm-fp-mobile-sheet', 'crm-fp-modal');

    if (isMobile) {
      cal.classList.add('crm-fp-mobile-sheet', 'crm-fp-modal');
      cal.style.left = '50%';
      cal.style.top = 'auto';
      cal.style.bottom = 'max(12px, env(safe-area-inset-bottom))';
      cal.style.transform = 'translateX(-50%)';
      cal.style.width = '';
      return;
    }

    var prevVisibility = cal.style.visibility;
    var prevDisplay = cal.style.display;
    cal.style.visibility = 'hidden';
    cal.style.display = 'block';
    cal.style.left = '-9999px';
    cal.style.top = '0';
    var calWidth = cal.offsetWidth || 340;
    var calHeight = cal.offsetHeight || 400;
    cal.style.visibility = prevVisibility;
    cal.style.display = prevDisplay || 'block';

    var rect = trigger.getBoundingClientRect();
    var margin = 8;
    var gap = 8;
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    var left = rect.left;

    if (left + calWidth > vw - margin) left = vw - calWidth - margin;
    if (left < margin) left = margin;

    var spaceBelow = vh - rect.bottom - gap;
    var spaceAbove = rect.top - gap;
    var useAbove;

    if (preferAbove) {
      useAbove = spaceAbove >= calHeight || spaceAbove >= spaceBelow;
    } else {
      useAbove = spaceBelow < calHeight + margin && spaceAbove > spaceBelow;
    }

    var top = useAbove ? (rect.top - calHeight - gap) : (rect.bottom + gap);
    cal.classList.add(useAbove ? 'crm-fp-above' : 'crm-fp-below');

    if (inFollowupModal) {
      var footer = document.querySelector('#modal-followup .ca-modal-footer');
      if (footer) {
        var footerRect = footer.getBoundingClientRect();
        if (top + calHeight > footerRect.top - gap) {
          var aboveTop = rect.top - calHeight - gap;
          if (aboveTop >= margin) {
            top = aboveTop;
            cal.classList.remove('crm-fp-below');
            cal.classList.add('crm-fp-above');
          } else {
            top = Math.max(margin, footerRect.top - calHeight - gap);
          }
        }
      }
      cal.classList.add('crm-fp-modal');
    }

    if (top < margin) top = margin;
    if (top + calHeight > vh - margin) {
      top = Math.max(margin, vh - calHeight - margin);
    }

    cal.style.left = left + 'px';
    cal.style.top = top + 'px';
    cal.style.transform = '';
    cal.style.width = '';
  }

  function handlePickerScrollSync(instance) {
    if (!instance || !instance.isOpen) return;
    var trigger = instance.altInput || instance.input;
    if (!trigger) return;
    var rect = trigger.getBoundingClientRect();
    if (rect.bottom < 0 || rect.top > window.innerHeight) {
      revertPickerSnapshot(instance);
      instance.close();
      return;
    }
    positionPickerCalendar(instance);
  }

  function enhanceCalendarA11y(instance, options) {
    if (!instance || !instance.calendarContainer) return;
    var cal = instance.calendarContainer;
    cal.setAttribute('role', 'dialog');
    cal.setAttribute('aria-modal', 'true');
    cal.setAttribute('aria-label', options.mode === 'date' ? 'Date picker' : 'Date and time picker');

    var prev = cal.querySelector('.flatpickr-prev-month');
    var next = cal.querySelector('.flatpickr-next-month');
    if (prev) prev.setAttribute('aria-label', 'Previous month');
    if (next) next.setAttribute('aria-label', 'Next month');
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

  function buildFlatpickrPlugins() {
    return [];
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
      closeOnSelect: isDateOnly,
      minuteIncrement: options.minuteIncrement || 5,
      time_24hr: false,
      appendTo: document.body,
      position: 'auto',
      disableMobile: true,
      monthSelectorType: 'static',
      shorthandCurrentMonth: false,
      minDate: options.allowPast ? null : (options.minDate || 'today'),
      plugins: buildFlatpickrPlugins(),
      onReady: function (selectedDates, dateStr, instance) {
        hideSourceInput(input);
        decorateCalendar(instance, options);
        decorateAltInput(instance, options);
        bindPickerTriggers(instance, options);
        enhanceCalendarA11y(instance, options);
        updatePreviewRow(instance, options, preview);
        updateCalendarPreview(instance, options);
      },
      onOpen: function (selectedDates, dateStr, instance) {
        activeFlatpickr = instance;
        capturePickerSnapshot(instance);
        error.classList.add('hidden');
        if (instance.altInput) instance.altInput.classList.remove('crm-datetime-field__trigger--error');
        requestAnimationFrame(function () {
          positionPickerCalendar(instance);
          enhanceCalendarA11y(instance, options);
        });
      },
      onClose: function (selectedDates, dateStr, instance) {
        if (activeFlatpickr && activeFlatpickr.input === input) activeFlatpickr = null;
        if (instance.altInput) {
          try { instance.altInput.focus(); } catch (err) { /* ignore */ }
        }
      },
      onChange: function (selectedDates, dateStr, instance) {
        updatePreviewRow(instance, options, preview);
        updateCalendarPreview(instance, options);
        error.classList.add('hidden');
        if (instance.altInput) instance.altInput.classList.remove('crm-datetime-field__trigger--error');
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.dispatchEvent(new Event('input', { bubbles: true }));
        if (isDateOnly && selectedDates.length) instance.close();
      },
      onMonthChange: function (selectedDates, dateStr, instance) {
        requestAnimationFrame(function () { positionPickerCalendar(instance); });
      },
      onYearChange: function (selectedDates, dateStr, instance) {
        requestAnimationFrame(function () { positionPickerCalendar(instance); });
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

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || !activeFlatpickr) return;
    revertPickerSnapshot(activeFlatpickr);
    activeFlatpickr.close();
  }, true);

  window.addEventListener('resize', function () {
    if (activeFlatpickr) positionPickerCalendar(activeFlatpickr);
  });

  window.addEventListener('scroll', function () {
    if (activeFlatpickr) handlePickerScrollSync(activeFlatpickr);
  }, true);

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
