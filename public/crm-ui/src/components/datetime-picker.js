/* CA Cloud Desk — Flatpickr-based date/time picker */
(function () {
  'use strict';

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
    var sqlDateTime = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{1,2}):(\d{2})(?::(\d{2}))?/);
    if (sqlDateTime) {
      return new Date(
        parseInt(sqlDateTime[1], 10),
        parseInt(sqlDateTime[2], 10) - 1,
        parseInt(sqlDateTime[3], 10),
        parseInt(sqlDateTime[4], 10),
        parseInt(sqlDateTime[5], 10),
        parseInt(sqlDateTime[6] || '0', 10),
        0,
      );
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

  function convert12To24(hour12, isPM) {
    if (Number.isNaN(hour12) || hour12 < 1 || hour12 > 12) return null;
    if (isPM) return hour12 === 12 ? 12 : hour12 + 12;
    return hour12 === 12 ? 0 : hour12;
  }

  function readAmpmFromTimeEl(timeEl) {
    if (!timeEl) return false;
    var pmBtn = timeEl.querySelector('.crm-fp-ampm-btn[data-ampm="PM"]');
    if (pmBtn) return pmBtn.classList.contains('is-active');
    var native = timeEl.querySelector('.flatpickr-am-pm');
    return native ? String(native.textContent || '').trim().toUpperCase() === 'PM' : false;
  }

  function validateTimeParts(hour12, minute) {
    if (Number.isNaN(hour12) || hour12 < 1 || hour12 > 12) {
      return { valid: false, message: 'Hour must be between 1 and 12.' };
    }
    if (Number.isNaN(minute) || minute < 0 || minute > 59) {
      return { valid: false, message: 'Minute must be between 00 and 59.' };
    }
    return { valid: true, hour12: hour12, minute: minute };
  }

  function syncFlatpickrTimeFromInputs(instance) {
    if (!instance || !instance.selectedDates.length) return null;
    var timeEl = instance.calendarContainer && instance.calendarContainer.querySelector('.flatpickr-time');
    if (!timeEl) return instance.selectedDates[0];
    var hourInput = timeEl.querySelector('input.flatpickr-hour');
    var minuteInput = timeEl.querySelector('input.flatpickr-minute');
    if (!hourInput || !minuteInput) return instance.selectedDates[0];

    var hour12 = parseInt(hourInput.value, 10);
    var minute = parseInt(minuteInput.value, 10);
    var isPM = readAmpmFromTimeEl(timeEl);
    var hour24 = convert12To24(hour12, isPM);
    if (hour24 === null) return instance.selectedDates[0];

    var merged = new Date(instance.selectedDates[0]);
    merged.setHours(hour24, Number.isNaN(minute) ? 0 : minute, 0, 0);
    instance.setDate(merged, false);
    if (instance._crmSyncAmpm) instance._crmSyncAmpm();
    return merged;
  }

  function hidePickerInlineError(instance) {
    if (!instance || !instance.calendarContainer) return;
    var el = instance.calendarContainer.querySelector('.crm-fp-inline-error');
    if (el) {
      el.classList.add('hidden');
      el.textContent = '';
    }
  }

  function showPickerInlineError(instance, message) {
    if (!instance || !instance.calendarContainer) return;
    var el = instance.calendarContainer.querySelector('.crm-fp-inline-error');
    if (!el) return;
    el.textContent = message || 'Please check the selected date and time.';
    el.classList.remove('hidden');
  }

  function initDraftFromConfirmed(instance, mode) {
    if (!instance) return;
    var confirmed = instance._crmConfirmedValue || instance.input.value || '';
    var parsed = parseInputValue(confirmed, mode);
    if (parsed) {
      instance.setDate(parsed, false);
    } else {
      instance.clear(false);
    }
    if (instance._crmSyncAmpm) instance._crmSyncAmpm();
  }

  function syncAltInputFromConfirmed(instance, mode) {
    if (!instance || !instance.altInput) return;
    var parsed = parseInputValue(instance.input.value || '', mode);
    if (!parsed) {
      instance.altInput.value = '';
      return;
    }
    try {
      instance.altInput.value = instance.formatDate(parsed, instance.config.altFormat);
    } catch (err) {
      instance.altInput.value = formatDisplay(parsed, mode);
    }
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
    var mm = pad(date.getMonth() + 1);
    var yyyy = date.getFullYear();
    if (mode === 'date') return dd + '/' + mm + '/' + yyyy;
    var hour = date.getHours();
    var ampm = hour >= 12 ? 'PM' : 'AM';
    var h12 = hour % 12;
    if (h12 === 0) h12 = 12;
    return dd + '/' + mm + '/' + yyyy + ', ' + pad(h12) + ':' + pad(date.getMinutes()) + ' ' + ampm;
  }

  function formatPreview(date, mode) {
    return formatDisplay(date, mode || 'datetime');
  }

  function isPast(date) {
    return date.getTime() <= Date.now();
  }

  function closePickerSafely(instance, opts) {
    if (!instance) return;
    opts = opts || {};
    instance._crmSuppressFocusOpen = true;
    try {
      instance.close();
    } catch (err) { /* ignore */ }
    window.setTimeout(function () {
      instance._crmSuppressFocusOpen = false;
      if (opts.focus !== false) {
        var target = instance.altInput || instance.input;
        if (target) {
          try { target.focus({ preventScroll: true }); } catch (focusErr) { /* ignore */ }
        }
      }
    }, 0);
  }

  function closeOtherPickers(keepInstance) {
    if (!activeFlatpickr || activeFlatpickr === keepInstance || !activeFlatpickr.isOpen) return;
    dismissPicker(activeFlatpickr, { commit: false });
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
      '<div class="crm-fp-inline-error hidden" role="alert" aria-live="polite"></div>' +
      '<div class="crm-fp-footer__actions">' +
        '<div class="crm-fp-footer__left">' +
          '<button type="button" class="btn-secondary btn-sm" data-fp-today>Today</button>' +
          '<button type="button" class="btn-secondary btn-sm" data-fp-clear>Clear</button>' +
        '</div>' +
        '<div class="crm-fp-footer__right">' +
          '<button type="button" class="btn-secondary btn-sm" data-fp-cancel>Cancel</button>' +
          '<button type="button" class="btn-primary btn-sm" data-fp-done>Done</button>' +
        '</div>' +
      '</div>';
    footer.addEventListener('mousedown', function (e) {
      e.stopPropagation();
    }, true);
    footer.addEventListener('click', function (e) {
      e.stopPropagation();
    }, true);
    instance.calendarContainer.appendChild(footer);

    footer.querySelector('[data-fp-today]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      hidePickerInlineError(instance);
      var today = options.mode === 'date' ? startOfDay(new Date()) : new Date();
      if (!options.allowPast && options.mode === 'datetime' && isPast(today)) {
        today.setMinutes(today.getMinutes() + (options.minuteIncrement || 5) - (today.getMinutes() % (options.minuteIncrement || 5)), 0, 0);
      }
      instance.setDate(today, false);
      updateCalendarPreview(instance, options);
      if (instance._crmSyncAmpm) instance._crmSyncAmpm();
      notifyDraftChange(instance);
      if (options.mode === 'date') {
        instance._crmDidCommit = true;
        var enhToday = getPickerEnhancement(instance);
        commitPickerSelection(instance, options, enhToday ? enhToday.preview : null, instance.input);
      }
    });

    footer.querySelector('[data-fp-clear]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      hidePickerInlineError(instance);
      commitEmptySelection(instance, options, getPickerEnhancement(instance));
    });

    footer.querySelector('[data-fp-cancel]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      dismissPicker(instance, { commit: false });
    });

    footer.querySelector('[data-fp-done]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      var enhancement = getPickerEnhancement(instance);
      commitPickerSelection(instance, options, enhancement ? enhancement.preview : null, instance.input);
    });
  }

  function bindTimeInputListeners(instance, options) {
    var timeEl = instance.calendarContainer && instance.calendarContainer.querySelector('.flatpickr-time');
    if (!timeEl || timeEl.dataset.crmTimeBound === '1') return;
    timeEl.dataset.crmTimeBound = '1';

    ['.flatpickr-hour', '.flatpickr-minute'].forEach(function (selector) {
      var input = timeEl.querySelector(selector);
      if (!input) return;
      input.addEventListener('input', function () {
        hidePickerInlineError(instance);
        notifyDraftChange(instance);
      });
      input.addEventListener('blur', function () {
        syncFlatpickrTimeFromInputs(instance);
        notifyDraftChange(instance);
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          syncFlatpickrTimeFromInputs(instance);
          notifyDraftChange(instance);
        }
      });
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
    decorateAmpmSegment(instance, timeEl);
    bindTimeInputListeners(instance);
  }

  function decorateAmpmSegment(instance, timeEl) {
    if (!timeEl || timeEl.dataset.crmAmpmDecorated === '1') return;
    var nativeAmpm = timeEl.querySelector('.flatpickr-am-pm');
    if (!nativeAmpm) return;
    timeEl.dataset.crmAmpmDecorated = '1';
    nativeAmpm.classList.add('crm-fp-ampm-native');
    nativeAmpm.setAttribute('aria-hidden', 'true');
    nativeAmpm.tabIndex = -1;

    var seg = document.createElement('div');
    seg.className = 'crm-fp-ampm-segment';
    seg.setAttribute('role', 'group');
    seg.setAttribute('aria-label', 'AM or PM');
    seg.setAttribute('tabindex', '0');

    var amBtn = document.createElement('button');
    amBtn.type = 'button';
    amBtn.className = 'crm-fp-ampm-btn';
    amBtn.textContent = 'AM';
    amBtn.dataset.ampm = 'AM';
    amBtn.setAttribute('aria-pressed', 'false');

    var pmBtn = document.createElement('button');
    pmBtn.type = 'button';
    pmBtn.className = 'crm-fp-ampm-btn';
    pmBtn.textContent = 'PM';
    pmBtn.dataset.ampm = 'PM';
    pmBtn.setAttribute('aria-pressed', 'false');

    seg.appendChild(amBtn);
    seg.appendChild(pmBtn);
    timeEl.appendChild(seg);

    function syncAmpmUI() {
      var d = instance.selectedDates[0];
      if (!d) {
        amBtn.classList.remove('is-active');
        pmBtn.classList.remove('is-active');
        amBtn.setAttribute('aria-pressed', 'false');
        pmBtn.setAttribute('aria-pressed', 'false');
        return;
      }
      var isPM = d.getHours() >= 12;
      amBtn.classList.toggle('is-active', !isPM);
      pmBtn.classList.toggle('is-active', isPM);
      amBtn.setAttribute('aria-pressed', isPM ? 'false' : 'true');
      pmBtn.setAttribute('aria-pressed', isPM ? 'true' : 'false');
      nativeAmpm.textContent = isPM ? 'PM' : 'AM';
    }

    function setAmpm(ampm) {
      var base = instance.selectedDates[0]
        ? new Date(instance.selectedDates[0])
        : (parseInputValue(instance._crmConfirmedValue || '', 'datetime') || new Date());
      var hour12 = base.getHours() % 12;
      if (hour12 === 0) hour12 = 12;
      var nextHour = convert12To24(hour12, ampm === 'PM');
      if (nextHour === null) return;
      base.setHours(nextHour, base.getMinutes(), 0, 0);
      instance.setDate(base, false);
      syncAmpmUI();
      hidePickerInlineError(instance);
    }

    amBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      setAmpm('AM');
      notifyDraftChange(instance);
    });
    pmBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      setAmpm('PM');
      notifyDraftChange(instance);
    });

    seg.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
      e.preventDefault();
      setAmpm(e.key === 'ArrowRight' ? 'PM' : 'AM');
      notifyDraftChange(instance);
    });

    instance._crmSyncAmpm = syncAmpmUI;
    syncAmpmUI();
  }

  function getPickerEnhancement(instance) {
    if (!instance || !instance.input) return null;
    return instances.get(instance.input) || null;
  }

  function restoreConfirmedInputValue(instance) {
    if (!instance || !instance.input) return;
    var confirmed = instance._crmConfirmedValue || '';
    if (instance.input.value !== confirmed) instance.input.value = confirmed;
  }

  function notifyDraftChange(instance) {
    var enhancement = getPickerEnhancement(instance);
    if (!enhancement) return;
    var options = enhancement.options || readEnhanceOptions(instance.input);
    updateCalendarPreview(instance, options);
    updatePreviewRow(instance, options, enhancement.preview, true);
    restoreConfirmedInputValue(instance);
  }

  function commitEmptySelection(instance, options, enhancement) {
    var input = instance.input;
    var preview = enhancement ? enhancement.preview : null;
    hidePickerInlineError(instance);
    instance._crmCommitting = true;
    instance._crmDidCommit = true;
    instance.clear(false);
    input.value = '';
    instance._crmConfirmedValue = '';
    if (instance.altInput) instance.altInput.value = '';
    updatePreviewRow(instance, options, preview, false);
    if (enhancement) enhancement.clearError();
    instance._crmCommitting = false;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.dispatchEvent(new Event('input', { bubbles: true }));
    closePickerSafely(instance);
    return true;
  }

  function commitPickerSelection(instance, options, preview, input) {
    hidePickerInlineError(instance);
    var enhancement = getPickerEnhancement(instance);

    if (!instance.selectedDates.length) {
      if (!options.optional) {
        if (preview) preview.classList.add('hidden');
        if (enhancement) enhancement.showError();
        showPickerInlineError(instance, options.mode === 'date' ? 'Please select a date.' : 'Please select a date and time.');
        return false;
      }
      instance._crmCommitting = true;
      instance._crmDidCommit = true;
      input.value = '';
      instance._crmConfirmedValue = '';
      if (instance.altInput) instance.altInput.value = '';
      updatePreviewRow(instance, options, preview, false);
      instance._crmCommitting = false;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      input.dispatchEvent(new Event('input', { bubbles: true }));
      closePickerSafely(instance);
      return true;
    }

    if (options.mode !== 'date') {
      var timeEl = instance.calendarContainer && instance.calendarContainer.querySelector('.flatpickr-time');
      var hourInput = timeEl && timeEl.querySelector('input.flatpickr-hour');
      var minuteInput = timeEl && timeEl.querySelector('input.flatpickr-minute');
      var timeCheck = validateTimeParts(
        hourInput ? parseInt(hourInput.value, 10) : NaN,
        minuteInput ? parseInt(minuteInput.value, 10) : NaN,
      );
      if (!timeCheck.valid) {
        if (enhancement) enhancement.showError(timeCheck.message);
        showPickerInlineError(instance, timeCheck.message);
        return false;
      }
      syncFlatpickrTimeFromInputs(instance);
    }

    var selected = instance.selectedDates[0];
    if (!selected || Number.isNaN(selected.getTime())) {
      showPickerInlineError(instance, 'Please select a valid date and time.');
      return false;
    }

    if (options.requireFuture && isPast(selected)) {
      if (enhancement) enhancement.showError('Please select a future date and time.');
      showPickerInlineError(instance, 'Please select a future date and time.');
      return false;
    }

    instance._crmCommitting = true;
    instance._crmDidCommit = true;
    var newValue = toLocalInputValue(selected, options.mode);
    input.value = newValue;
    instance._crmConfirmedValue = newValue;
    syncAltInputFromConfirmed(instance, options.mode);
    updatePreviewRow(instance, options, preview, false);
    if (enhancement) enhancement.clearError();
    instance._crmCommitting = false;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.dispatchEvent(new Event('input', { bubbles: true }));
    closePickerSafely(instance);
    return true;
  }

  function dismissPicker(instance, opts) {
    opts = opts || {};
    hidePickerInlineError(instance);
    if (!opts.commit) {
      revertPickerSnapshot(instance);
      var enhancement = getPickerEnhancement(instance);
      if (enhancement) {
        enhancement.clearError();
        updatePreviewRow(instance, enhancement.options, enhancement.preview, false);
      }
    }
    instance._crmDidCommit = true;
    closePickerSafely(instance, { focus: opts.focus });
  }

  function clearPickerDraft(instance, options) {
    instance.clear(false);
    restoreConfirmedInputValue(instance);
    updateCalendarPreview(instance, options);
    var enhancement = getPickerEnhancement(instance);
    if (enhancement) {
      enhancement.clearError();
      updatePreviewRow(instance, options, enhancement.preview, true);
    }
    if (instance._crmSyncAmpm) instance._crmSyncAmpm();
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
    var mode = instance.input.getAttribute('data-crm-date-input') !== null ? 'date' : 'datetime';
    instance.input.value = snapshot;
    instance._crmConfirmedValue = snapshot;
    var parsed = parseInputValue(snapshot, mode);
    if (parsed) instance.setDate(parsed, false);
    else instance.clear(false);
    syncAltInputFromConfirmed(instance, mode);
    if (instance._crmSyncAmpm) instance._crmSyncAmpm();
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
      dismissPicker(instance, { commit: false });
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
      if (fp.isOpen) {
        dismissPicker(fp, { commit: false });
        return;
      }
      closeOtherPickers(fp);
      fp.open();
    }

    alt.classList.add('crm-datetime-field__trigger', 'input-field');
    alt.setAttribute('aria-haspopup', 'dialog');
    if (!alt.placeholder) alt.placeholder = options.placeholder;

    alt.addEventListener('click', openPicker);
    alt.addEventListener('focus', function () {
      if (fp._crmSuppressFocusOpen) return;
      if (isInsideClosedModal(alt)) {
        try { alt.blur(); } catch (err) { /* ignore */ }
        return;
      }
      if (!fp.isOpen) {
        closeOtherPickers(fp);
        fp.open();
      }
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

  function updatePreviewRow(instance, options, previewEl, draft) {
    if (!previewEl || options.hidePreview) {
      if (previewEl) {
        previewEl.classList.add('hidden');
        previewEl.textContent = '';
      }
      return;
    }
    var date = null;
    if (draft && instance.isOpen) {
      date = instance.selectedDates[0] || null;
    } else if (instance.input.value) {
      date = parseInputValue(instance.input.value, options.mode);
    } else if (instance.selectedDates[0]) {
      date = instance.selectedDates[0];
    }
    if (!date) {
      previewEl.classList.add('hidden');
      previewEl.textContent = '';
      return;
    }
    previewEl.classList.remove('hidden');
    previewEl.textContent = options.previewPrefix + ' ' + formatPreview(date, options.mode);
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
      altFormat: isDateOnly ? 'd/m/Y' : 'd/m/Y, h:i K',
      altInputClass: 'crm-datetime-field__trigger input-field',
      allowInput: true,
      clickOpens: false,
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
        closeOtherPickers(instance);
        activeFlatpickr = instance;
        instance._crmDidCommit = false;
        instance._crmSuppressFocusOpen = false;
        instance._crmConfirmedValue = input.value || '';
        capturePickerSnapshot(instance);
        error.classList.add('hidden');
        if (instance.altInput) instance.altInput.classList.remove('crm-datetime-field__trigger--error');
        initDraftFromConfirmed(instance, mode);
        hidePickerInlineError(instance);
        requestAnimationFrame(function () {
          positionPickerCalendar(instance);
          enhanceCalendarA11y(instance, options);
          if (instance._crmSyncAmpm) instance._crmSyncAmpm();
          updateCalendarPreview(instance, options);
          updatePreviewRow(instance, options, preview, true);
        });
      },
      onClose: function (selectedDates, dateStr, instance) {
        if (!instance._crmDidCommit) {
          revertPickerSnapshot(instance);
          updatePreviewRow(instance, options, preview, false);
        }
        if (activeFlatpickr && activeFlatpickr.input === input) activeFlatpickr = null;
      },
      onChange: function (selectedDates, dateStr, instance) {
        if (instance.isOpen && !instance._crmCommitting && !isDateOnly) {
          restoreConfirmedInputValue(instance);
          updatePreviewRow(instance, options, preview, true);
          updateCalendarPreview(instance, options);
          if (instance._crmSyncAmpm) instance._crmSyncAmpm();
          return;
        }
        updatePreviewRow(instance, options, preview, false);
        updateCalendarPreview(instance, options);
        error.classList.add('hidden');
        if (instance.altInput) instance.altInput.classList.remove('crm-datetime-field__trigger--error');
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.dispatchEvent(new Event('input', { bubbles: true }));
        if (isDateOnly && selectedDates.length) {
          instance._crmCommitting = true;
          instance._crmDidCommit = true;
          var selectedDate = selectedDates[0];
          var committedValue = toLocalInputValue(selectedDate, 'date');
          input.value = committedValue;
          instance._crmConfirmedValue = committedValue;
          syncAltInputFromConfirmed(instance, 'date');
          updatePreviewRow(instance, options, preview, false);
          instance._crmCommitting = false;
          closePickerSafely(instance);
        }
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
          input.value = '';
          fp._crmConfirmedValue = '';
          if (fp.altInput) fp.altInput.value = '';
        } else {
          fp.setDate(date, false);
          var nextValue = toLocalInputValue(date, mode);
          input.value = nextValue;
          fp._crmConfirmedValue = nextValue;
          syncAltInputFromConfirmed(fp, mode);
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
      if (parsed) {
        fp.setDate(parsed, false);
        fp._crmConfirmedValue = toLocalInputValue(parsed, mode);
      }
    }
    syncAltInputFromConfirmed(fp, mode);
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
    if (!activeFlatpickr) return;
    var fp = activeFlatpickr;
    dismissPicker(fp, { commit: false });
    if (opts.focus !== false && fp.altInput) {
      try { fp.altInput.focus(); } catch (e) { /* ignore */ }
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
      closeOtherPickers(instance.flatpickr);
      instance.open();
      return true;
    }
    var enhanced = enhanceInput(input);
    if (enhanced) {
      closeOtherPickers(enhanced.flatpickr);
      enhanced.open();
      return true;
    }
    return false;
  }

  document.addEventListener('mousedown', function (e) {
    if (!activeFlatpickr) return;
    if (e.target.closest && e.target.closest('.flatpickr-calendar')) return;
    var field = e.target.closest('.crm-datetime-field');
    if (field) {
      var source = field.querySelector('[data-crm-datetime-input], [data-crm-date-input]');
      var enhancement = source ? instances.get(source) : null;
      if (enhancement && enhancement.flatpickr === activeFlatpickr) return;
    }
    dismissPicker(activeFlatpickr, { commit: false });
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
    dismissPicker(activeFlatpickr, { commit: false });
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
    testHelpers: {
      parseInputValue: parseInputValue,
      convert12To24: convert12To24,
      validateTimeParts: validateTimeParts,
      toLocalInputValue: toLocalInputValue,
      formatDisplay: formatDisplay,
    },
  };
})();
