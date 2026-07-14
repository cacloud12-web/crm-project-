/* CA Cloud Desk — Custom Date/Time Picker (no third-party calendar library) */
(function () {
  'use strict';

  var instances = new WeakMap();
  var activePopup = null;
  var triggerBound = new WeakSet();
  var WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

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

  function validateTimeParts(hour12, minute) {
    if (Number.isNaN(hour12) || hour12 < 1 || hour12 > 12) {
      return { valid: false, message: 'Hour must be between 1 and 12.' };
    }
    if (Number.isNaN(minute) || minute < 0 || minute > 59) {
      return { valid: false, message: 'Minute must be between 00 and 59.' };
    }
    return { valid: true, hour12: hour12, minute: minute };
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

  function isSameDay(a, b) {
    return a && b &&
      a.getFullYear() === b.getFullYear() &&
      a.getMonth() === b.getMonth() &&
      a.getDate() === b.getDate();
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
      minDate: allowPast ? null : startOfDay(new Date()),
    };
  }

  function isInsideClosedModal(el) {
    var modal = el && el.closest ? el.closest('.ca-modal') : null;
    return !!(modal && !modal.classList.contains('open'));
  }

  function isDayDisabled(date, options) {
    if (!options.minDate) return false;
    return startOfDay(date).getTime() < startOfDay(options.minDate).getTime();
  }

  function buildPopupShell(options) {
    var el = document.createElement('div');
    el.className = 'crm-dtp';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', options.mode === 'date' ? 'Date picker' : 'Date and time picker');
    el.hidden = true;

    var header = document.createElement('div');
    header.className = 'crm-dtp__header';
    header.innerHTML =
      '<button type="button" class="crm-dtp__nav" data-dtp-prev aria-label="Previous month">' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>' +
      '</button>' +
      '<span class="crm-dtp__title" data-dtp-title></span>' +
      '<button type="button" class="crm-dtp__nav" data-dtp-next aria-label="Next month">' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>' +
      '</button>';
    el.appendChild(header);

    var weekdays = document.createElement('div');
    weekdays.className = 'crm-dtp__weekdays';
    weekdays.innerHTML = WEEKDAYS.map(function (d) {
      return '<span class="crm-dtp__weekday">' + d + '</span>';
    }).join('');
    el.appendChild(weekdays);

    var grid = document.createElement('div');
    grid.className = 'crm-dtp__grid';
    grid.setAttribute('data-dtp-grid', '');
    el.appendChild(grid);

    if (options.mode !== 'date') {
      var timeRow = document.createElement('div');
      timeRow.className = 'crm-dtp__time';
      timeRow.innerHTML =
        '<span class="crm-dtp__time-label">Time</span>' +
        '<div class="crm-dtp__time-fields">' +
          '<input type="text" inputmode="numeric" maxlength="2" class="crm-dtp__time-input" data-dtp-hour aria-label="Hour" />' +
          '<span class="crm-dtp__time-sep">:</span>' +
          '<input type="text" inputmode="numeric" maxlength="2" class="crm-dtp__time-input" data-dtp-minute aria-label="Minute" />' +
          '<div class="crm-dtp__ampm" role="group" aria-label="AM or PM">' +
            '<button type="button" class="crm-dtp__ampm-btn" data-dtp-ampm="AM">AM</button>' +
            '<button type="button" class="crm-dtp__ampm-btn" data-dtp-ampm="PM">PM</button>' +
          '</div>' +
        '</div>';
      el.appendChild(timeRow);
    }

    if (options.mode !== 'date' && !options.hideCalendarPreview) {
      var preview = document.createElement('div');
      preview.className = 'crm-dtp__preview crm-dtp__preview--empty';
      preview.setAttribute('data-dtp-preview', '');
      preview.setAttribute('aria-live', 'polite');
      preview.textContent = 'Select date and time';
      el.appendChild(preview);
    }

    var footer = document.createElement('div');
    footer.className = 'crm-dtp__footer';
    footer.innerHTML =
      '<div class="crm-dtp__error" data-dtp-error role="alert"></div>' +
      '<div class="crm-dtp__actions">' +
        '<div class="crm-dtp__actions-left">' +
          '<button type="button" class="crm-dtp__btn" data-dtp-today>Today</button>' +
          '<button type="button" class="crm-dtp__btn" data-dtp-clear>Clear</button>' +
        '</div>' +
        '<div class="crm-dtp__actions-right">' +
          '<button type="button" class="crm-dtp__btn" data-dtp-cancel>Cancel</button>' +
          '<button type="button" class="crm-dtp__btn crm-dtp__btn--primary" data-dtp-done>Done</button>' +
        '</div>' +
      '</div>';
    el.appendChild(footer);

    return el;
  }

  function PickerController(ctx) {
    this.ctx = ctx;
    this.viewYear = new Date().getFullYear();
    this.viewMonth = new Date().getMonth();
    this.draft = null;
    this.snapshot = '';
    this.isOpen = false;
    this.suppressFocusOpen = false;
  }

  PickerController.prototype.el = function () {
    return this.ctx.popup;
  };

  PickerController.prototype.showError = function (message) {
    var err = this.el().querySelector('[data-dtp-error]');
    if (!err) return;
    err.textContent = message || 'Please check the selected date and time.';
    err.classList.add('is-visible');
    if (this.ctx.enhancement) this.ctx.enhancement.showError(message);
  };

  PickerController.prototype.hideError = function () {
    var err = this.el().querySelector('[data-dtp-error]');
    if (err) {
      err.textContent = '';
      err.classList.remove('is-visible');
    }
    if (this.ctx.enhancement) this.ctx.enhancement.clearError();
  };

  PickerController.prototype.syncTimeInputs = function () {
    if (this.ctx.options.mode === 'date' || !this.draft) return;
    var hourInput = this.el().querySelector('[data-dtp-hour]');
    var minuteInput = this.el().querySelector('[data-dtp-minute]');
    if (!hourInput || !minuteInput) return;
    var h24 = this.draft.getHours();
    var isPM = h24 >= 12;
    var h12 = h24 % 12;
    if (h12 === 0) h12 = 12;
    hourInput.value = pad(h12);
    minuteInput.value = pad(this.draft.getMinutes());
    this.el().querySelectorAll('[data-dtp-ampm]').forEach(function (btn) {
      var active = btn.getAttribute('data-dtp-ampm') === (isPM ? 'PM' : 'AM');
      btn.classList.toggle('is-active', active);
    });
  };

  PickerController.prototype.readTimeFromInputs = function () {
    if (this.ctx.options.mode === 'date') return this.draft;
    var hourInput = this.el().querySelector('[data-dtp-hour]');
    var minuteInput = this.el().querySelector('[data-dtp-minute]');
    var pmBtn = this.el().querySelector('[data-dtp-ampm="PM"]');
    if (!hourInput || !minuteInput) return this.draft;
    var hour12 = parseInt(hourInput.value, 10);
    var minute = parseInt(minuteInput.value, 10);
    var isPM = pmBtn && pmBtn.classList.contains('is-active');
    var check = validateTimeParts(hour12, minute);
    if (!check.valid) return null;
    var hour24 = convert12To24(hour12, isPM);
    if (hour24 === null) return null;
    var base = this.draft ? new Date(this.draft) : new Date();
    base.setHours(hour24, minute, 0, 0);
    return base;
  };

  PickerController.prototype.updatePreview = function () {
    var preview = this.el().querySelector('[data-dtp-preview]');
    if (!preview) return;
    if (!this.draft) {
      preview.textContent = 'Select date and time';
      preview.classList.add('crm-dtp__preview--empty');
      return;
    }
    preview.classList.remove('crm-dtp__preview--empty');
    preview.textContent = 'Selected: ' + formatPreview(this.draft, this.ctx.options.mode);
  };

  PickerController.prototype.updateFieldPreview = function () {
    var previewEl = this.ctx.enhancement && this.ctx.enhancement.preview;
    var options = this.ctx.options;
    if (!previewEl || options.hidePreview) return;
    var date = this.isOpen ? this.draft : parseInputValue(this.ctx.input.value, options.mode);
    if (!date) {
      previewEl.classList.add('hidden');
      previewEl.textContent = '';
      return;
    }
    previewEl.classList.remove('hidden');
    previewEl.textContent = options.previewPrefix + ' ' + formatPreview(date, options.mode);
  };

  PickerController.prototype.renderGrid = function () {
    var grid = this.el().querySelector('[data-dtp-grid]');
    var title = this.el().querySelector('[data-dtp-title]');
    if (!grid || !title) return;

    var first = new Date(this.viewYear, this.viewMonth, 1);
    var startPad = first.getDay();
    var daysInMonth = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();
    var prevMonthDays = new Date(this.viewYear, this.viewMonth, 0).getDate();
    var today = startOfDay(new Date());
    var options = this.ctx.options;

    title.textContent = first.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });

    var cells = [];
    var i;
    for (i = 0; i < startPad; i++) {
      var pd = prevMonthDays - startPad + i + 1;
      var pDate = new Date(this.viewYear, this.viewMonth - 1, pd);
      cells.push({ date: pDate, outside: true });
    }
    for (i = 1; i <= daysInMonth; i++) {
      cells.push({ date: new Date(this.viewYear, this.viewMonth, i), outside: false });
    }
    while (cells.length % 7 !== 0) {
      var nd = cells.length - startPad - daysInMonth + 1;
      cells.push({ date: new Date(this.viewYear, this.viewMonth + 1, nd), outside: true });
    }

    var self = this;
    grid.innerHTML = cells.map(function (cell) {
      var cls = ['crm-dtp__day'];
      if (cell.outside) cls.push('crm-dtp__day--outside');
      if (isSameDay(cell.date, today)) cls.push('crm-dtp__day--today');
      if (self.draft && isSameDay(cell.date, self.draft)) cls.push('crm-dtp__day--selected');
      if (isDayDisabled(cell.date, options)) cls.push('crm-dtp__day--disabled');
      var ts = cell.date.getTime();
      return '<button type="button" class="' + cls.join(' ') + '" data-dtp-day="' + ts + '" tabindex="-1"' +
        (isDayDisabled(cell.date, options) ? ' disabled' : '') + '>' + cell.date.getDate() + '</button>';
    }).join('');
  };

  PickerController.prototype.setDraft = function (date, skipTimeSync) {
    this.draft = date ? new Date(date) : null;
    if (!skipTimeSync) this.syncTimeInputs();
    this.renderGrid();
    this.updatePreview();
    this.updateFieldPreview();
  };

  PickerController.prototype.open = function () {
    if (isInsideClosedModal(this.ctx.trigger)) return;
    if (this.isOpen) {
      this.close(false);
      return;
    }
    closeOtherPickers(this);
    this.snapshot = this.ctx.input.value || '';
    this.hideError();

    var parsed = parseInputValue(this.snapshot, this.ctx.options.mode);
    if (parsed) {
      this.viewYear = parsed.getFullYear();
      this.viewMonth = parsed.getMonth();
      this.draft = new Date(parsed);
    } else {
      var now = new Date();
      this.viewYear = now.getFullYear();
      this.viewMonth = now.getMonth();
      this.draft = this.ctx.options.mode === 'date' ? null : new Date();
      if (this.ctx.options.mode === 'datetime' && !this.ctx.options.allowPast) {
        var mins = this.draft.getMinutes();
        var inc = this.ctx.options.minuteIncrement || 5;
        this.draft.setMinutes(mins + (inc - (mins % inc)), 0, 0);
      }
    }

    this.renderGrid();
    this.syncTimeInputs();
    this.updatePreview();
    this.updateFieldPreview();

    var popup = this.el();
    popup.hidden = false;
    document.body.appendChild(popup);
    this.isOpen = true;
    activePopup = this;

    var self = this;
    requestAnimationFrame(function () {
      positionPopup(self);
      var focusDay = self.el().querySelector('.crm-dtp__day--selected:not(:disabled)') ||
        self.el().querySelector('.crm-dtp__day--today:not(:disabled)') ||
        self.el().querySelector('[data-dtp-day]:not(:disabled)');
      if (focusDay) focusDay.focus();
    });
  };

  PickerController.prototype.close = function (committed) {
    if (!this.isOpen) return;
    this.isOpen = false;
    if (activePopup === this) activePopup = null;
    this.el().hidden = true;

    if (!committed) {
      this.ctx.input.value = this.snapshot;
      this.ctx.confirmedValue = this.snapshot;
      this.syncTriggerDisplay();
    }

    this.updateFieldPreview();
    var trigger = this.ctx.trigger;
    var ctrl = this;
    this.suppressFocusOpen = true;
    window.setTimeout(function () {
      if (trigger) {
        try { trigger.focus({ preventScroll: true }); } catch (e) { trigger.focus(); }
      }
      window.setTimeout(function () {
        ctrl.suppressFocusOpen = false;
      }, 120);
    }, 0);
  };

  PickerController.prototype.syncTriggerDisplay = function () {
    var parsed = parseInputValue(this.ctx.input.value, this.ctx.options.mode);
    this.ctx.trigger.value = parsed ? formatDisplay(parsed, this.ctx.options.mode) : '';
  };

  PickerController.prototype.commit = function () {
    var options = this.ctx.options;
    var input = this.ctx.input;
    this.hideError();

    if (options.mode !== 'date') {
      var merged = this.readTimeFromInputs();
      if (!merged && this.draft) {
        var hourInput = this.el().querySelector('[data-dtp-hour]');
        var minuteInput = this.el().querySelector('[data-dtp-minute]');
        var check = validateTimeParts(
          hourInput ? parseInt(hourInput.value, 10) : NaN,
          minuteInput ? parseInt(minuteInput.value, 10) : NaN,
        );
        if (!check.valid) {
          this.showError(check.message);
          return false;
        }
      }
      if (merged) this.draft = merged;
    }

    if (!this.draft) {
      if (!options.optional) {
        this.showError(options.mode === 'date' ? 'Please select a date.' : 'Please select a date and time.');
        return false;
      }
      input.value = '';
      this.ctx.confirmedValue = '';
      this.syncTriggerDisplay();
      input.dispatchEvent(new Event('change', { bubbles: true }));
      input.dispatchEvent(new Event('input', { bubbles: true }));
      this.close(true);
      return true;
    }

    if (options.requireFuture && isPast(this.draft)) {
      this.showError('Please select a future date and time.');
      return false;
    }

    var newValue = toLocalInputValue(this.draft, options.mode);
    input.value = newValue;
    this.ctx.confirmedValue = newValue;
    this.syncTriggerDisplay();
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.dispatchEvent(new Event('input', { bubbles: true }));
    this.close(true);
    return true;
  };

  PickerController.prototype.clear = function () {
    this.hideError();
    this.draft = null;
    this.ctx.input.value = '';
    this.ctx.confirmedValue = '';
    this.syncTriggerDisplay();
    this.renderGrid();
    this.syncTimeInputs();
    this.updatePreview();
    this.ctx.input.dispatchEvent(new Event('change', { bubbles: true }));
    this.ctx.input.dispatchEvent(new Event('input', { bubbles: true }));
    this.close(true);
  };

  PickerController.prototype.bindEvents = function () {
    var self = this;
    var popup = this.el();

    popup.querySelector('[data-dtp-prev]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      self.viewMonth -= 1;
      if (self.viewMonth < 0) { self.viewMonth = 11; self.viewYear -= 1; }
      self.renderGrid();
    });

    popup.querySelector('[data-dtp-next]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      self.viewMonth += 1;
      if (self.viewMonth > 11) { self.viewMonth = 0; self.viewYear += 1; }
      self.renderGrid();
    });

    popup.addEventListener('click', function (e) {
      var dayBtn = e.target.closest('[data-dtp-day]');
      if (!dayBtn || dayBtn.disabled) return;
      e.preventDefault();
      e.stopPropagation();
      var date = new Date(parseInt(dayBtn.getAttribute('data-dtp-day'), 10));
      if (self.draft && self.ctx.options.mode !== 'date') {
        date.setHours(self.draft.getHours(), self.draft.getMinutes(), 0, 0);
      }
      self.setDraft(date);
      self.hideError();
      if (self.ctx.options.mode === 'date') {
        self.commit();
      }
    });

    popup.querySelector('[data-dtp-today]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var today = self.ctx.options.mode === 'date' ? startOfDay(new Date()) : new Date();
      if (!self.ctx.options.allowPast && self.ctx.options.mode === 'datetime' && isPast(today)) {
        var inc = self.ctx.options.minuteIncrement || 5;
        today.setMinutes(today.getMinutes() + (inc - (today.getMinutes() % inc)), 0, 0);
      }
      self.viewYear = today.getFullYear();
      self.viewMonth = today.getMonth();
      self.setDraft(today);
      self.hideError();
      if (self.ctx.options.mode === 'date') self.commit();
    });

    popup.querySelector('[data-dtp-clear]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      self.clear();
    });

    popup.querySelector('[data-dtp-cancel]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      self.close(false);
    });

    popup.querySelector('[data-dtp-done]').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      self.commit();
    });

    if (self.ctx.options.mode !== 'date') {
      popup.querySelectorAll('[data-dtp-ampm]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var merged = self.readTimeFromInputs() || self.draft || new Date();
          var hour12 = merged.getHours() % 12;
          if (hour12 === 0) hour12 = 12;
          var isPM = btn.getAttribute('data-dtp-ampm') === 'PM';
          var hour24 = convert12To24(hour12, isPM);
          if (hour24 !== null) merged.setHours(hour24, merged.getMinutes(), 0, 0);
          self.setDraft(merged, true);
          self.syncTimeInputs();
          self.hideError();
        });
      });

      ['[data-dtp-hour]', '[data-dtp-minute]'].forEach(function (sel) {
        var input = popup.querySelector(sel);
        if (!input) return;
        input.addEventListener('input', function () {
          self.hideError();
          var merged = self.readTimeFromInputs();
          if (merged) {
            self.draft = merged;
            self.updatePreview();
            self.updateFieldPreview();
          }
        });
        input.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            self.commit();
          }
        });
      });
    }

    popup.addEventListener('mousedown', function (e) {
      e.stopPropagation();
    });

    popup.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.matches('[data-dtp-done]')) {
        e.preventDefault();
        self.commit();
        return;
      }
      var dayBtn = e.target.closest('[data-dtp-day]');
      if (!dayBtn || dayBtn.disabled) return;
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' && e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
      e.preventDefault();
      var buttons = Array.prototype.slice.call(popup.querySelectorAll('[data-dtp-day]:not(:disabled)'));
      var idx = buttons.indexOf(dayBtn);
      if (idx < 0) return;
      var next = idx;
      if (e.key === 'ArrowLeft') next = Math.max(0, idx - 1);
      if (e.key === 'ArrowRight') next = Math.min(buttons.length - 1, idx + 1);
      if (e.key === 'ArrowUp') next = Math.max(0, idx - 7);
      if (e.key === 'ArrowDown') next = Math.min(buttons.length - 1, idx + 7);
      if (buttons[next]) buttons[next].focus();
    });
  };

  function positionPopup(ctrl) {
    var popup = ctrl.el();
    var trigger = ctrl.ctx.trigger;
    if (!popup || !trigger) return;

    var options = ctrl.ctx.options;
    var inFollowupModal = !!trigger.closest('#modal-followup');
    var preferAbove = options.preferAbove || inFollowupModal;
    var isMobile = window.matchMedia('(max-width: 640px)').matches;

    popup.style.position = 'fixed';
    popup.style.margin = '0';
    popup.style.zIndex = '12050';
    popup.classList.remove('crm-dtp--above');

    if (isMobile) {
      popup.style.left = '50%';
      popup.style.top = 'auto';
      popup.style.bottom = 'max(12px, env(safe-area-inset-bottom))';
      popup.style.transform = 'translateX(-50%)';
      return;
    }

    popup.style.visibility = 'hidden';
    popup.style.display = 'block';
    popup.style.left = '-9999px';
    var calWidth = popup.offsetWidth || 320;
    var calHeight = popup.offsetHeight || 380;
    popup.style.visibility = '';

    var rect = trigger.getBoundingClientRect();
    var margin = 8;
    var gap = 8;
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    var left = Math.max(margin, Math.min(rect.left, vw - calWidth - margin));
    var spaceBelow = vh - rect.bottom - gap;
    var spaceAbove = rect.top - gap;
    var useAbove = preferAbove ? (spaceAbove >= calHeight || spaceAbove >= spaceBelow) : (spaceBelow < calHeight + margin && spaceAbove > spaceBelow);
    var top = useAbove ? (rect.top - calHeight - gap) : (rect.bottom + gap);
    if (useAbove) popup.classList.add('crm-dtp--above');
    if (top < margin) top = margin;
    if (top + calHeight > vh - margin) top = Math.max(margin, vh - calHeight - margin);

    popup.style.left = left + 'px';
    popup.style.top = top + 'px';
    popup.style.bottom = 'auto';
    popup.style.transform = '';
  }

  function closeOtherPickers(keep) {
    if (!activePopup || activePopup === keep || !activePopup.isOpen) return;
    activePopup.close(false);
  }

  function bindPickerTriggers(ctx, ctrl) {
    var trigger = ctx.trigger;
    if (!trigger || triggerBound.has(trigger)) return;

    function openHandler(e) {
      if (isInsideClosedModal(trigger)) return;
      if (e) { e.preventDefault(); e.stopPropagation(); }
      if (ctrl.suppressFocusOpen) return;
      ctrl.open();
    }

    trigger.classList.add('crm-datetime-field__trigger', 'input-field');
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.readOnly = true;
    if (!trigger.placeholder) trigger.placeholder = ctx.options.placeholder;

    trigger.addEventListener('click', openHandler);
    trigger.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') openHandler(e);
    });

    var iconWrap = ctx.wrap.querySelector('.crm-datetime-field__icon-wrap');
    if (iconWrap) {
      iconWrap.addEventListener('click', openHandler);
      iconWrap.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') openHandler(e);
      });
    }

    triggerBound.add(trigger);
  }

  function linkLabelToTrigger(trigger, fieldRoot) {
    if (!trigger || !fieldRoot) return;
    var parent = fieldRoot.parentElement;
    if (!parent) return;
    parent.querySelectorAll('label[for]').forEach(function (label) {
      var forId = label.getAttribute('for');
      if (!forId) return;
      var target = document.getElementById(forId);
      if (!target || target === trigger) {
        if (!trigger.id) trigger.id = forId + '-trigger';
        label.setAttribute('for', trigger.id);
      }
    });
    if (!trigger.id) {
      trigger.id = 'crm-dt-' + Math.random().toString(36).slice(2, 9);
    }
  }

  function unwrapField(input) {
    var field = input.closest('.crm-datetime-field');
    if (!field || !field.parentNode) return;
    field.parentNode.insertBefore(input, field);
    field.remove();
    input.classList.remove('crm-datetime-source');
    input.removeAttribute('tabindex');
    input.removeAttribute('aria-hidden');
  }

  function destroyInstance(input) {
    if (!instances.has(input)) {
      unwrapField(input);
      return;
    }
    var inst = instances.get(input);
    if (inst.controller && inst.controller.isOpen) inst.controller.close(false);
    if (inst.popup && inst.popup.parentNode) inst.popup.parentNode.removeChild(inst.popup);
    if (inst.trigger) triggerBound.delete(inst.trigger);
    instances.delete(input);
    unwrapField(input);
  }

  function enhanceInput(input, overrideOptions) {
    if (!input || input.disabled) return null;

    if (instances.has(input)) {
      var existing = instances.get(input);
      if (existing && existing.trigger && document.contains(existing.trigger)) return existing;
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
    input.classList.add('crm-datetime-source');
    input.setAttribute('tabindex', '-1');
    input.setAttribute('aria-hidden', 'true');

    var trigger = document.createElement('input');
    trigger.type = 'text';
    trigger.className = 'crm-datetime-field__trigger input-field';
    trigger.setAttribute('autocomplete', 'off');
    wrap.insertBefore(trigger, input.nextSibling);

    var inputWrap = document.createElement('div');
    inputWrap.className = 'crm-datetime-field__input-wrap';
    wrap.insertBefore(inputWrap, trigger);
    inputWrap.appendChild(trigger);
    var iconWrap = document.createElement('span');
    iconWrap.className = 'crm-datetime-field__icon-wrap';
    iconWrap.setAttribute('role', 'button');
    iconWrap.setAttribute('tabindex', '0');
    iconWrap.innerHTML = '<i data-lucide="' + (isDateOnly ? 'calendar' : 'calendar-clock') + '" class="crm-datetime-field__icon h-4 w-4"></i>';
    inputWrap.appendChild(iconWrap);
    if (typeof lucide !== 'undefined') lucide.createIcons();

    var preview = document.createElement('p');
    preview.className = 'crm-datetime-field__preview hidden text-caption mt-1';
    if (options.hidePreview) preview.classList.add('crm-datetime-field__preview--hidden');
    wrap.appendChild(preview);

    var error = document.createElement('p');
    error.className = 'crm-datetime-field__error hidden text-caption mt-1';
    error.textContent = isDateOnly ? 'Please select a date.' : 'Please select a future date and time.';
    wrap.appendChild(error);

    var popup = buildPopupShell(options);
    var controller = new PickerController({
      input: input,
      trigger: trigger,
      wrap: wrap,
      popup: popup,
      options: options,
      confirmedValue: input.value || '',
      enhancement: null,
    });
    controller.bindEvents();

    var instance = {
      input: input,
      trigger: trigger,
      preview: preview,
      error: error,
      popup: popup,
      controller: controller,
      options: options,
      open: function () { controller.open(); },
      getValue: function () { return parseInputValue(input.value, mode); },
      showError: function (message) {
        error.textContent = message || error.textContent;
        error.classList.remove('hidden');
        trigger.classList.add('crm-datetime-field__trigger--error');
      },
      clearError: function () {
        error.classList.add('hidden');
        trigger.classList.remove('crm-datetime-field__trigger--error');
      },
      setValue: function (date, opts) {
        opts = opts || {};
        this.clearError();
        if (!date) {
          input.value = '';
          controller.ctx.confirmedValue = '';
          trigger.value = '';
        } else {
          var nextValue = toLocalInputValue(date, mode);
          input.value = nextValue;
          controller.ctx.confirmedValue = nextValue;
          trigger.value = formatDisplay(date, mode);
        }
        controller.updateFieldPreview();
        if (!opts.silent) {
          input.dispatchEvent(new Event('change', { bubbles: true }));
          input.dispatchEvent(new Event('input', { bubbles: true }));
        }
      },
      destroy: function () { destroyInstance(input); },
    };

    controller.ctx.enhancement = instance;

    if (input.value) {
      var parsed = parseInputValue(input.value, mode);
      if (parsed) {
        controller.ctx.confirmedValue = toLocalInputValue(parsed, mode);
        trigger.value = formatDisplay(parsed, mode);
      }
    }
    controller.updateFieldPreview();
    bindPickerTriggers({ trigger: trigger, wrap: wrap, options: options }, controller);
    linkLabelToTrigger(trigger, wrap);

    instances.set(input, instance);
    return instance;
  }

  function shouldAutoEnhanceDateInput(input) {
    if (!input || input.disabled || input.readOnly) return false;
    if (input.classList.contains('crm-datetime-source')) return false;
    if (input.hasAttribute('data-skip-crm-datepicker')) return false;
    if (input.closest('.crm-datetime-field')) return false;
    return input.type === 'date' || input.type === 'datetime-local';
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
    root.querySelectorAll('input[type="date"], input[type="datetime-local"]').forEach(function (input) {
      if (!shouldAutoEnhanceDateInput(input)) return;
      if (opts.force) destroyInstance(input);
      var isDateTime = input.type === 'datetime-local' || input.hasAttribute('data-crm-datetime-input');
      enhanceInput(input, isDateTime
        ? { mode: 'datetime' }
        : { mode: 'date', allowPast: true, requireFuture: false, hidePreview: input.classList.contains('crm-col-filter-input') });
    });
  }

  function closePopup(opts) {
    if (!activePopup || !activePopup.isOpen) return;
    activePopup.close(false);
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
    if (!instance) instance = enhanceInput(input);
    if (instance) {
      instance.open();
      return true;
    }
    return false;
  }

  document.addEventListener('mousedown', function (e) {
    if (!activePopup || !activePopup.isOpen) return;
    if (e.target.closest && e.target.closest('.crm-dtp')) return;
    var field = e.target.closest('.crm-datetime-field');
    if (field) {
      var source = field.querySelector('[data-crm-datetime-input], [data-crm-date-input]');
      var inst = source ? instances.get(source) : null;
      if (inst && inst.controller === activePopup) return;
    }
    activePopup.close(false);
  }, true);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && activePopup && activePopup.isOpen) {
      activePopup.close(false);
    }
  }, true);

  window.addEventListener('resize', function () {
    if (activePopup && activePopup.isOpen) positionPopup(activePopup);
  });

  window.addEventListener('scroll', function () {
    if (!activePopup || !activePopup.isOpen) return;
    var rect = activePopup.ctx.trigger.getBoundingClientRect();
    if (rect.bottom < 0 || rect.top > window.innerHeight) {
      activePopup.close(false);
      return;
    }
    positionPopup(activePopup);
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
    isOpen: function () { return !!(activePopup && activePopup.isOpen); },
    testHelpers: {
      parseInputValue: parseInputValue,
      convert12To24: convert12To24,
      validateTimeParts: validateTimeParts,
      toLocalInputValue: toLocalInputValue,
      formatDisplay: formatDisplay,
    },
  };
})();
