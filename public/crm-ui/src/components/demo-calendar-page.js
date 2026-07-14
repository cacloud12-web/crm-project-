/* global window, document */
window.CrmDemoCalendarPage = (function () {
  'use strict';

  var Data = window.CrmDemoCalendarData;
  var SLOT_HEIGHT = 52;
  var WEEK_COL_BUFFER = 48;
  var GRID_START_HOUR = 10;
  var GRID_END_HOUR = 19;
  var GRID_SLOT_COUNT = GRID_END_HOUR - GRID_START_HOUR + 1;
  var bound = false;

  var state = {
    view: 'month',
    anchor: new Date(),
    filter: 'all',
    search: { date: '' },
    editingId: null,
  };

  function $(id) { return document.getElementById(id); }

  function toast(msg, type) {
    if (window.toast) window.toast(msg, type || 'info');
  }

  function iconsIn(el) {
    if (window.CA_CRM && typeof window.CA_CRM.iconsIn === 'function') {
      window.CA_CRM.iconsIn(el);
    } else if (window.lucide) {
      window.lucide.createIcons({ nodes: [el || document] });
    }
  }

  function openModal(el) {
    if (window.CA_CRM && typeof window.CA_CRM.openExclusiveCrmModal === 'function') {
      window.CA_CRM.openExclusiveCrmModal(el);
    } else if (el) el.classList.add('open');
    iconsIn(el);
  }

  function closeModal(el) {
    if (window.CA_CRM && typeof window.CA_CRM.closeModal === 'function') {
      window.CA_CRM.closeModal(el);
    } else if (el) el.classList.remove('open');
  }

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function pad(n) { return n < 10 ? '0' + n : String(n); }

  function toDateStr(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  function todayStr() { return toDateStr(new Date()); }

  function parseDate(str) {
    var p = String(str || '').split('-');
    return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
  }

  function parseTimeMinutes(t) {
    if (!t) return 0;
    var p = String(t).split(':');
    return parseInt(p[0], 10) * 60 + parseInt(p[1] || '0', 10);
  }

  function formatTime12(t) {
    if (!t) return '';
    var p = String(t).split(':');
    var h = parseInt(p[0], 10);
    var m = parseInt(p[1] || '0', 10);
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12 || 12;
    return h12 + ':' + pad(m) + ' ' + ampm;
  }

  function formatTimeRange(d) {
    return formatTime12(d.startTime) + ' – ' + formatTime12(d.endTime);
  }

  function sameDay(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  }

  function startOfWeek(d) {
    var copy = new Date(d);
    copy.setDate(copy.getDate() - copy.getDay());
    copy.setHours(0, 0, 0, 0);
    return copy;
  }

  function endOfWeek(d) {
    var s = startOfWeek(d);
    s.setDate(s.getDate() + 6);
    return s;
  }

  function addDays(d, n) {
    var copy = new Date(d);
    copy.setDate(copy.getDate() + n);
    return copy;
  }

  function isSundayDate(d) {
    return d.getDay() === 0;
  }

  function isSundayStr(dateStr) {
    return Data.isSunday(dateStr);
  }

  function gridHourLabel(h) {
    if (h < 12) return h + ':00 AM';
    if (h === 12) return '12:00 PM';
    return (h - 12) + ':00 PM';
  }

  function demosForDayStats(dayDemos, dateStr) {
    if (isSundayStr(dateStr)) return [];
    return dayDemos.filter(function (d) { return d.status !== 'invalid_schedule'; });
  }

  function getActiveDemos() {
    return Data.filterDemos(Data.getAllDemos(), state.filter, state.search, todayStr());
  }

  function eventsInRange(demos, start, end) {
    var startStr = toDateStr(start);
    var endStr = toDateStr(end);
    return demos.filter(function (d) {
      return d.date >= startStr && d.date <= endStr;
    }).sort(function (a, b) {
      if (a.date !== b.date) return a.date < b.date ? -1 : 1;
      return parseTimeMinutes(a.startTime) - parseTimeMinutes(b.startTime);
    });
  }

  function getViewRange() {
    var a = state.anchor;
    if (state.view === 'month') {
      return { start: new Date(a.getFullYear(), a.getMonth(), 1), end: new Date(a.getFullYear(), a.getMonth() + 1, 0) };
    }
    if (state.view === 'week') return { start: startOfWeek(a), end: endOfWeek(a) };
    if (state.view === 'day') {
      return { start: new Date(a.getFullYear(), a.getMonth(), a.getDate()), end: new Date(a.getFullYear(), a.getMonth(), a.getDate()) };
    }
    var ms = new Date(a.getFullYear(), a.getMonth(), 1);
    var me = new Date(a.getFullYear(), a.getMonth() + 1, 0);
    me.setDate(me.getDate() + 30);
    return { start: ms, end: me };
  }

  function getVisibleRange() {
    var range = getViewRange();
    if (state.view !== 'month') return range;
    var a = state.anchor;
    var first = new Date(a.getFullYear(), a.getMonth(), 1);
    var gridStart = new Date(a.getFullYear(), a.getMonth(), 1 - first.getDay());
    return { start: gridStart, end: addDays(gridStart, 41) };
  }

  function demoCardHtml(d, mode) {
    var style = Data.getStatusStyle(d.status);
    var isWeek = mode === 'week';
    var isCompact = mode === 'compact' || isWeek;
    var cls = 'dcp-demo-card' + (isWeek ? ' dcp-demo-card--week' : isCompact ? ' dcp-demo-card--compact' : '');
    var foot = '<span class="dcp-demo-card__foot">' +
      '<span class="dcp-status-pill" style="color:' + style.text + ';background:' + style.bg + ';border-color:' + style.border + '">' + escapeHtml(Data.statusLabel(d.status)) + '</span>' +
      (isWeek ? '' : '<span class="dcp-priority-pill dcp-priority-pill--' + escapeHtml(d.priority) + '">' + escapeHtml((d.priority || 'medium').charAt(0).toUpperCase() + (d.priority || 'medium').slice(1)) + '</span>') +
      '</span>';
    return '<button type="button" class="' + cls + '" data-dcp-event="' + escapeHtml(d.id) + '" style="border-color:' + style.border + ';background:' + style.bg + '">' +
      '<span class="dcp-demo-card__time">' + escapeHtml(formatTimeRange(d)) + '</span>' +
      '<span class="dcp-demo-card__firm">' + escapeHtml(d.firmName) + '</span>' +
      (isWeek ? '' : '<span class="dcp-demo-card__meta">CA: ' + escapeHtml(d.caName) + '</span>') +
      (isWeek ? '' : '<span class="dcp-demo-card__meta">Executive: ' + escapeHtml(d.executive) + '</span>') +
      foot + '</button>';
  }

  function renderSummary() {
    var el = $('dcp-summary');
    if (!el) return;
    var stats = Data.getSummaryStats(Data.getAllDemos(), todayStr());
    var cards = [
      { label: "Today's Demos", value: stats.today, icon: 'calendar-clock', mod: 'brand' },
      { label: 'Upcoming This Week', value: stats.week, icon: 'calendar-range', mod: 'blue' },
      { label: 'Completed Today', value: stats.completedToday, icon: 'check-circle', mod: 'green' },
      { label: 'Pending', value: stats.pending, icon: 'clock', mod: 'slate' },
      { label: 'Cancelled', value: stats.cancelled, icon: 'x-circle', mod: 'red' },
      { label: 'Follow-up Required', value: stats.followUp, icon: 'phone-forwarded', mod: 'orange' },
    ];
    el.innerHTML = cards.map(function (c) {
      return '<div class="dcp-kpi dcp-kpi--' + c.mod + '">' +
        '<span class="dcp-kpi__icon"><i data-lucide="' + c.icon + '" class="h-4 w-4"></i></span>' +
        '<div class="dcp-kpi__body"><span class="dcp-kpi__label">' + escapeHtml(c.label) + '</span>' +
        '<strong class="dcp-kpi__value">' + c.value + '</strong></div></div>';
    }).join('');
  }

  function renderFilters() {
    var el = $('dcp-filters');
    if (!el) return;
    var demos = Data.getAllDemos();
    var counts = Data.countByFilter(demos, todayStr());
    el.innerHTML = Data.STATUS_FILTERS.map(function (f) {
      var count = counts[f.key] || 0;
      var label = f.label + ' (' + count + ')';
      return '<button type="button" class="dcp-filter-btn' + (state.filter === f.key ? ' active' : '') + '" data-dcp-filter="' + f.key + '" role="tab">' + escapeHtml(label) + '</button>';
    }).join('');
  }

  function renderSearchFilters() {
    var dt = $('dcp-search-date');
    if (dt) dt.value = state.search.date || '';
  }

  function renderQueue() {
    var el = $('dcp-queue-list');
    if (!el) return;
    if (isSundayDate(new Date())) {
      el.innerHTML = '<p class="dcp-queue-empty">No demos scheduled — Sunday is a non-working day.</p>';
      return;
    }
    var today = todayStr();
    var queue = Data.getAllDemos().filter(function (d) {
      return d.date === today && Data.isQueueEligible(d);
    }).sort(function (a, b) { return parseTimeMinutes(a.startTime) - parseTimeMinutes(b.startTime); });
    if (!queue.length) {
      el.innerHTML = '<p class="dcp-queue-empty">No demos scheduled for today.</p>';
      return;
    }
    el.innerHTML = queue.map(function (d, i) {
      var style = Data.getStatusStyle(d.status);
      return (i > 0 ? '<div class="dcp-queue-divider"></div>' : '') +
        '<button type="button" class="dcp-queue-item" data-dcp-event="' + escapeHtml(d.id) + '">' +
        '<span class="dcp-queue-item__time">' + escapeHtml(formatTime12(d.startTime)) + '</span>' +
        '<span class="dcp-queue-item__firm">' + escapeHtml(d.firmName) + '</span>' +
        '<span class="dcp-queue-item__exec">' + escapeHtml(d.executive) + '</span>' +
        '<span class="dcp-queue-item__status" style="color:' + style.text + '">' + escapeHtml(Data.statusLabel(d.status)) + '</span>' +
        '</button>';
    }).join('');
  }

  function renderTitle() {
    var el = $('dcp-title');
    if (!el) return;
    var range = getViewRange();
    var a = state.anchor;
    if (state.view === 'month') {
      el.textContent = a.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
      return;
    }
    if (state.view === 'week') {
      el.textContent = range.start.toLocaleDateString('en-IN', { month: 'short', day: 'numeric' }) +
        ' – ' + range.end.toLocaleDateString('en-IN', { month: 'short', day: 'numeric', year: 'numeric' });
      return;
    }
    if (state.view === 'day') {
      el.textContent = a.toLocaleDateString('en-IN', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
      return;
    }
    el.textContent = range.start.toLocaleDateString('en-IN') + ' – ' + range.end.toLocaleDateString('en-IN');
  }

  function monthStatusBadges(dayDemos) {
    var agg = Data.aggregateByStatus(dayDemos.filter(function (d) { return d.status !== 'invalid_schedule'; }));
    var order = ['scheduled', 'completed', 'follow_up', 'pending', 'rescheduled', 'cancelled'];
    return order.filter(function (s) { return agg[s]; }).map(function (s) {
      var style = Data.getStatusStyle(s);
      return '<span class="dcp-month-stat" style="color:' + style.text + '">' + agg[s] + ' ' + escapeHtml(Data.statusLabel(s).replace(' Required', '')) + '</span>';
    }).join('');
  }

  function renderMonthView(demos) {
    var a = state.anchor;
    var year = a.getFullYear();
    var month = a.getMonth();
    var first = new Date(year, month, 1);
    var gridStart = new Date(year, month, 1 - first.getDay());
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var byDate = {};
    demos.forEach(function (d) {
      byDate[d.date] = byDate[d.date] || [];
      byDate[d.date].push(d);
    });
    var html = '<div class="dcp-month"><div class="dcp-month-head">';
    ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(function (d, idx) {
      html += '<div class="dcp-month-head-cell' + (idx === 0 ? ' dcp-month-head-cell--closed' : '') + '">' + d + '</div>';
    });
    html += '</div><div class="dcp-month-grid">';
    for (var i = 0; i < 42; i++) {
      var cellDate = addDays(gridStart, i);
      var dateStr = toDateStr(cellDate);
      var isOther = cellDate.getMonth() !== month;
      var isToday = sameDay(cellDate, today);
      var isClosed = isSundayDate(cellDate);
      var dayDemos = byDate[dateStr] || [];
      var statsDemos = demosForDayStats(dayDemos, dateStr);
      html += '<div class="dcp-month-cell' +
        (isOther ? ' dcp-month-cell--muted' : '') +
        (isToday ? ' dcp-month-cell--today' : '') +
        (isClosed ? ' dcp-month-cell--closed' : '') +
        '" data-dcp-date="' + dateStr + '" data-dcp-closed="' + (isClosed ? '1' : '0') + '">' +
        '<div class="dcp-month-date">' + cellDate.getDate() + '</div>' +
        (isClosed ? '<div class="dcp-month-closed-label">Closed</div>' : '') +
        '<div class="dcp-month-stats">' + (statsDemos.length ? monthStatusBadges(statsDemos) : '') + '</div></div>';
    }
    html += '</div></div>';
    return html;
  }

  function renderTimeGrid(demos, days) {
    var today = new Date();
    var now = new Date();
    var nowMinutes = now.getHours() * 60 + now.getMinutes();
    var showNowLine = nowMinutes >= GRID_START_HOUR * 60 && nowMinutes <= GRID_END_HOUR * 60;
    var nowTop = showNowLine ? ((nowMinutes - GRID_START_HOUR * 60) / 60) * SLOT_HEIGHT : 0;
    var html = '<div class="dcp-time-wrap"><div class="dcp-time-grid">';
    html += '<div class="dcp-time-days-head" style="--dcp-days:' + days.length + '"><div class="dcp-time-corner-cell"></div>';
    days.forEach(function (d) {
      var isToday = sameDay(d, today);
      var isClosed = isSundayDate(d);
      html += '<div class="dcp-time-day-head' + (isToday ? ' dcp-time-day-head--today' : '') + (isClosed ? ' dcp-time-day-head--closed' : '') + '">' +
        '<span class="dcp-time-day-num">' + pad(d.getDate()) + '</span>' +
        '<span class="dcp-time-day-name">' + d.toLocaleDateString('en-IN', { weekday: 'short' }) + '</span>' +
        (isClosed ? '<span class="dcp-time-day-closed">No Demos</span>' : '') +
        '</div>';
    });
    html += '</div><div class="dcp-time-body"><div class="dcp-time-labels">';
    for (var h = GRID_START_HOUR; h <= GRID_END_HOUR; h++) {
      html += '<div class="dcp-time-label" style="height:' + SLOT_HEIGHT + 'px">' + gridHourLabel(h) + '</div>';
    }
    html += '<div class="dcp-time-label dcp-time-label--buffer" style="height:' + WEEK_COL_BUFFER + 'px"></div>';
    html += '</div><div class="dcp-time-columns">';
    days.forEach(function (d) {
      var dateStr = toDateStr(d);
      var isToday = sameDay(d, today);
      var isClosed = isSundayDate(d);
      var colHeight = GRID_SLOT_COUNT * SLOT_HEIGHT + WEEK_COL_BUFFER;
      html += '<div class="dcp-time-col' + (isToday ? ' dcp-time-col--today' : '') + (isClosed ? ' dcp-time-col--closed' : '') + '" style="height:' + colHeight + 'px" data-dcp-closed="' + (isClosed ? '1' : '0') + '">';
      for (var hr = GRID_START_HOUR; hr <= GRID_END_HOUR; hr++) {
        html += '<div class="dcp-time-slot" style="height:' + SLOT_HEIGHT + 'px"></div>';
      }
      if (isClosed) {
        html += '<div class="dcp-time-col-overlay"><span>Closed</span></div>';
      } else {
        demos.filter(function (ev) { return ev.date === dateStr; }).forEach(function (ev, idx) {
          var top = ((parseTimeMinutes(ev.startTime) - GRID_START_HOUR * 60) / 60) * SLOT_HEIGHT;
          html += '<div class="dcp-time-event-wrap" style="top:' + Math.max(top, 0) + 'px;z-index:' + (idx + 1) + '">' +
            demoCardHtml(ev, 'week') + '</div>';
        });
        if (isToday && showNowLine) {
          html += '<div class="dcp-time-now-line" style="top:' + nowTop + 'px"></div>';
        }
      }
      html += '</div>';
    });
    html += '</div></div></div></div>';
    return html;
  }

  function renderDayViewGrouped(demos) {
    var dateStr = toDateStr(state.anchor);
    if (isSundayStr(dateStr)) {
      return '<p class="dcp-empty dcp-empty--closed">Sunday is closed — demos cannot be scheduled on Sundays.</p>';
    }
    var dayDemos = demos.filter(function (d) { return d.date === dateStr; });
    if (!dayDemos.length) return '<p class="dcp-empty">No demos scheduled for this day.</p>';
    var byHour = {};
    dayDemos.forEach(function (d) {
      var hour = parseInt(String(d.startTime).split(':')[0], 10);
      if (hour < GRID_START_HOUR || hour > GRID_END_HOUR) return;
      byHour[hour] = byHour[hour] || [];
      byHour[hour].push(d);
    });
    var hours = Object.keys(byHour).map(Number).sort(function (a, b) { return a - b; });
    if (!hours.length) return '<p class="dcp-empty">No demos scheduled during working hours (10:00 AM – 7:00 PM).</p>';
    var html = '<div class="dcp-day-groups">';
    hours.forEach(function (hr) {
      var label = gridHourLabel(hr);
      html += '<section class="dcp-day-group"><h4 class="dcp-day-group__hour">' + label + '</h4><div class="dcp-day-group__list">';
      byHour[hr].forEach(function (d) { html += demoCardHtml(d, false); });
      html += '</div></section>';
    });
    html += '</div>';
    return html;
  }

  function renderAgendaView(demos) {
    var range = getViewRange();
    var list = eventsInRange(demos, range.start, range.end);
    if (!list.length) return '<p class="dcp-empty">No demos found for this period.</p>';
    var html = '<div class="dcp-agenda-wrap"><table class="dcp-agenda-table"><thead><tr>' +
      '<th>Date</th><th>Time</th><th>Firm</th><th>CA Name</th><th>Executive</th><th>Status</th><th>Priority</th></tr></thead><tbody>';
    list.forEach(function (d) {
      var style = Data.getStatusStyle(d.status);
      html += '<tr class="dcp-agenda-row" data-dcp-event="' + escapeHtml(d.id) + '">' +
        '<td>' + escapeHtml(parseDate(d.date).toLocaleDateString('en-IN', { weekday: 'short', month: 'short', day: 'numeric' })) + '</td>' +
        '<td>' + escapeHtml(formatTimeRange(d)) + '</td>' +
        '<td>' + escapeHtml(d.firmName) + '</td>' +
        '<td>' + escapeHtml(d.caName) + '</td>' +
        '<td>' + escapeHtml(d.executive) + '</td>' +
        '<td><span class="dcp-status-pill" style="color:' + style.text + ';background:' + style.bg + ';border-color:' + style.border + '">' + escapeHtml(Data.statusLabel(d.status)) + '</span></td>' +
        '<td><span class="dcp-priority-pill dcp-priority-pill--' + escapeHtml(d.priority) + '">' + escapeHtml(d.priority) + '</span></td></tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  function renderBody() {
    var body = $('dcp-body');
    if (!body) return;
    var range = getVisibleRange();
    var demos = eventsInRange(getActiveDemos(), range.start, range.end);
    if (state.view === 'month') body.innerHTML = renderMonthView(demos);
    else if (state.view === 'week') body.innerHTML = renderTimeGrid(demos, (function () {
      var start = startOfWeek(state.anchor); var days = []; for (var i = 0; i < 7; i++) days.push(addDays(start, i)); return days;
    })());
    else if (state.view === 'day') body.innerHTML = renderDayViewGrouped(getActiveDemos());
    else body.innerHTML = renderAgendaView(getActiveDemos());
    iconsIn(body);
  }

  function renderAll() {
    var body = $('dcp-body');
    if (body) {
      body.innerHTML = '<div class="crm-inline-loading py-8"><i data-lucide="loader-2" class="h-5 w-5 animate-spin text-brand"></i><span>Loading demo calendar…</span></div>';
      iconsIn(body);
    }

    var apiView = state.view === 'agenda' ? 'agenda' : state.view;
    Data.loadFromApi({ view: apiView, date: toDateStr(state.anchor) }).then(function () {
      renderSummary();
      renderFilters();
      renderSearchFilters();
      renderQueue();
      renderTitle();
      renderBody();
      document.querySelectorAll('[data-dcp-view]').forEach(function (btn) {
        btn.classList.toggle('active', btn.getAttribute('data-dcp-view') === state.view);
      });
    });
  }

  function findDemo(id) {
    return Data.getAllDemos().find(function (d) { return d.id === id; });
  }

  function populateTimeSelects(startVal, endVal) {
    var startSel = $('dcp-form-start');
    var endSel = $('dcp-form-end');
    if (!startSel || !endSel) return;
    var starts = Data.getStartTimeOptions();
    startSel.innerHTML = starts.map(function (s) {
      return '<option value="' + s.value + '">' + escapeHtml(s.label) + '</option>';
    }).join('');
    startSel.value = startVal || Data.RULES.startTime;
    refreshEndTimeSelect(startSel.value, endVal);
  }

  function refreshEndTimeSelect(startTime, endVal) {
    var endSel = $('dcp-form-end');
    if (!endSel) return;
    var ends = Data.getEndTimeOptions(startTime || Data.RULES.startTime);
    endSel.innerHTML = ends.map(function (s) {
      return '<option value="' + s.value + '">' + escapeHtml(s.label) + '</option>';
    }).join('');
    if (endVal && ends.some(function (e) { return e.value === endVal; })) {
      endSel.value = endVal;
    } else if (ends.length) {
      endSel.value = ends[0].value;
    }
  }

  function openScheduleForm(mode, demo, presetDate) {
    var modal = $('modal-dcp-form');
    if (!modal) return;
    state.editingId = demo ? demo.id : null;
    modal.setAttribute('data-dcp-form-mode', mode);
    $('dcp-form-title').textContent = mode === 'add' ? 'Schedule Demo' : mode === 'reschedule' ? 'Reschedule Demo' : 'Edit Demo';
    $('dcp-form-firm').value = demo ? demo.firmName || '' : '';
    $('dcp-form-ca').value = demo ? demo.caName || '' : '';
    $('dcp-form-executive').value = demo ? demo.executive || '' : '';
    $('dcp-form-phone').value = demo ? demo.phone || '' : '';
    var dateVal = demo ? demo.date : (presetDate || todayStr());
    if (isSundayStr(dateVal)) {
      dateVal = Data.addDaysStr(dateVal, 1);
    }
    $('dcp-form-date').value = dateVal;
    $('dcp-form-priority').value = demo ? (demo.priority || 'medium') : 'medium';
    $('dcp-form-remarks').value = demo ? (demo.remarks || '') : '';
    populateTimeSelects(demo ? demo.startTime : Data.RULES.startTime, demo ? demo.endTime : null);
    var err = $('dcp-form-error');
    if (err) err.textContent = '';
    if (window.CrmDateTimePicker) {
      window.CrmDateTimePicker.initAll(modal);
      window.CrmDateTimePicker.syncInput($('dcp-form-date'));
    }
    openModal(modal);
  }

  function submitScheduleForm() {
    var mode = $('modal-dcp-form')?.getAttribute('data-dcp-form-mode') || 'add';
    var date = $('dcp-form-date')?.value || '';
    var startTime = $('dcp-form-start')?.value || '';
    var endTime = $('dcp-form-end')?.value || '';
    var errEl = $('dcp-form-error');
    var check = Data.validateSchedule(date, startTime, endTime);
    if (!check.valid) {
      if (errEl) errEl.textContent = check.message;
      toast(check.message, 'error');
      return;
    }
    if (isSundayStr(date)) {
      var msg = Data.RULES.messages.sunday;
      if (errEl) errEl.textContent = msg;
      toast(msg, 'error');
      return;
    }
    var payload = {
      id: state.editingId || Data.nextCustomId(),
      firmName: ($('dcp-form-firm')?.value || '').trim() || 'New Firm',
      caName: ($('dcp-form-ca')?.value || '').trim() || 'CA Contact',
      executive: ($('dcp-form-executive')?.value || '').trim() || 'Unassigned',
      phone: ($('dcp-form-phone')?.value || '').trim(),
      date: date,
      startTime: startTime,
      endTime: endTime,
      priority: $('dcp-form-priority')?.value || 'medium',
      remarks: ($('dcp-form-remarks')?.value || '').trim(),
      status: mode === 'reschedule' ? 'rescheduled' : 'scheduled',
    };
    var existing = state.editingId ? findDemo(state.editingId) : null;
    if (existing) {
      payload.status = mode === 'reschedule' ? 'rescheduled' : (existing._priorStatus || existing.status === 'invalid_schedule' ? 'scheduled' : existing.status);
      payload.meetingLink = existing.meetingLink;
      payload.lastFollowup = existing.lastFollowup;
      payload.description = existing.description;
      delete payload._priorStatus;
      Data.updateDemo(payload);
      toast(mode === 'reschedule' ? 'Demo rescheduled.' : 'Demo updated.', 'success');
    } else {
      Data.saveCustomDemo(payload);
      toast('Demo scheduled.', 'success');
    }
    closeModal($('modal-dcp-form'));
    closeModal($('modal-dcp-detail'));
    state.editingId = null;
    renderAll();
  }

  function openDetailModal(id) {
    var d = findDemo(id);
    if (!d) return;
    var modal = $('modal-dcp-detail');
    if (!modal) return;
    $('dcp-detail-title').innerHTML = '<i data-lucide="presentation" class="h-5 w-5 text-brand"></i> ' + escapeHtml(d.firmName);
    $('dcp-detail-firm').textContent = d.firmName || '—';
    $('dcp-detail-ca').textContent = d.caName || '—';
    $('dcp-detail-employee').textContent = d.executive || '—';
    $('dcp-detail-phone').textContent = d.phone || '—';
    $('dcp-detail-date').textContent = parseDate(d.date).toLocaleDateString('en-IN', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    $('dcp-detail-time').textContent = formatTimeRange(d);
    var linkEl = $('dcp-detail-meeting');
    if (linkEl) {
      linkEl.href = d.meetingLink || '#';
      linkEl.textContent = d.meetingLink || '—';
    }
    $('dcp-detail-status').textContent = Data.statusLabel(d.status);
    if (d.status === 'invalid_schedule' && d.invalidReason) {
      $('dcp-detail-status').textContent += ' — ' + d.invalidReason;
    }
    $('dcp-detail-priority').textContent = (d.priority || 'medium').charAt(0).toUpperCase() + (d.priority || 'medium').slice(1);
    $('dcp-detail-followup').textContent = d.lastFollowup || '—';
    $('dcp-detail-desc').textContent = d.remarks || d.description || '—';
    modal.setAttribute('data-dcp-detail-id', d.id);
    openModal(modal);
  }

  function updateDemoStatus(id, status, msg) {
    var d = findDemo(id);
    if (!d) return;
    d.status = status;
    Data.updateDemo(d);
    toast(msg || 'Demo status updated (demo mode).', 'success');
    closeModal($('modal-dcp-detail'));
    renderAll();
  }

  function exportDemos(label, demos) {
    toast(label + ': ' + demos.length + ' demo(s) ready for export (demo mode — no file generated).', 'info');
  }

  function bindUi() {
    if (bound) return;
    var root = $('dcp-root');
    if (!root) return;
    bound = true;

    root.addEventListener('click', function (e) {
      var evBtn = e.target.closest('[data-dcp-event]');
      if (evBtn) {
        openDetailModal(evBtn.getAttribute('data-dcp-event'));
        return;
      }
      var cell = e.target.closest('.dcp-month-cell');
      if (cell && cell.getAttribute('data-dcp-date')) {
        if (cell.getAttribute('data-dcp-closed') === '1') {
          toast(Data.RULES.messages.sunday, 'warning');
          return;
        }
        state.anchor = parseDate(cell.getAttribute('data-dcp-date'));
        state.view = 'day';
        renderAll();
      }
    });

    $('dcp-filters')?.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-dcp-filter]');
      if (!btn) return;
      state.filter = btn.getAttribute('data-dcp-filter');
      renderAll();
    });

    root.querySelectorAll('[data-dcp-view]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        state.view = btn.getAttribute('data-dcp-view');
        renderAll();
      });
    });

    $('dcp-add-btn')?.addEventListener('click', function () {
      if (isSundayDate(new Date())) {
        toast(Data.RULES.messages.sunday, 'warning');
        return;
      }
      openScheduleForm('add', null, todayStr());
    });
    $('dcp-reset-demo')?.addEventListener('click', function () {
      Data.resetDemoData();
      renderAll();
      toast('Demo calendar refreshed.', 'success');
    });

    function applySearch() {
      state.search.date = $('dcp-search-date')?.value || '';
      renderAll();
    }

    $('dcp-search-btn')?.addEventListener('click', applySearch);
    $('dcp-search-date')?.addEventListener('change', applySearch);
    $('dcp-search-clear')?.addEventListener('click', function () {
      state.search = { date: '' };
      renderAll();
    });

    $('dcp-export-today')?.addEventListener('click', function () {
      var t = todayStr();
      exportDemos("Export Today's Demos", Data.getAllDemos().filter(function (d) {
        return d.date === t && Data.isQueueEligible(d);
      }));
    });
    $('dcp-export-week')?.addEventListener('click', function () {
      var t = todayStr();
      var end = Data.addDaysStr(t, 7);
      exportDemos('Export Weekly Demos', Data.getAllDemos().filter(function (d) { return d.date >= t && d.date <= end; }));
    });
    $('dcp-export-print')?.addEventListener('click', function () { window.print(); });

    $('dcp-action-start')?.addEventListener('click', function () {
      var id = $('modal-dcp-detail')?.getAttribute('data-dcp-detail-id');
      if (id) updateDemoStatus(id, 'scheduled', 'Demo marked as started.');
    });
    $('dcp-action-complete')?.addEventListener('click', function () {
      var id = $('modal-dcp-detail')?.getAttribute('data-dcp-detail-id');
      if (id) updateDemoStatus(id, 'completed', 'Demo marked as completed.');
    });
    $('dcp-action-reschedule')?.addEventListener('click', function () {
      var id = $('modal-dcp-detail')?.getAttribute('data-dcp-detail-id');
      if (id) openScheduleForm('reschedule', findDemo(id));
    });
    $('dcp-action-edit')?.addEventListener('click', function () {
      var id = $('modal-dcp-detail')?.getAttribute('data-dcp-detail-id');
      if (id) openScheduleForm('edit', findDemo(id));
    });
    $('dcp-action-cancel')?.addEventListener('click', function () {
      var id = $('modal-dcp-detail')?.getAttribute('data-dcp-detail-id');
      if (id) updateDemoStatus(id, 'cancelled', 'Demo cancelled (demo mode).');
    });
    $('dcp-action-followup')?.addEventListener('click', function () {
      var id = $('modal-dcp-detail')?.getAttribute('data-dcp-detail-id');
      if (id) updateDemoStatus(id, 'follow_up', 'Follow-up flagged on demo.');
    });
    $('dcp-form-start')?.addEventListener('change', function (e) {
      refreshEndTimeSelect(e.target.value);
    });
    $('dcp-form-date')?.addEventListener('change', function (e) {
      if (isSundayStr(e.target.value)) {
        toast(Data.RULES.messages.sunday, 'warning');
        e.target.value = Data.addDaysStr(e.target.value, 1);
      }
    });
    $('dcp-form-save')?.addEventListener('click', submitScheduleForm);
    $('dcp-form-cancel')?.addEventListener('click', function () { closeModal($('modal-dcp-form')); });
    $('modal-dcp-form')?.addEventListener('click', function (e) {
      if (e.target === $('modal-dcp-form')) closeModal($('modal-dcp-form'));
    });

    $('dcp-detail-close')?.addEventListener('click', function () { closeModal($('modal-dcp-detail')); });

    $('modal-dcp-detail')?.addEventListener('click', function (e) {
      if (e.target === $('modal-dcp-detail')) closeModal($('modal-dcp-detail'));
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      ['modal-dcp-detail', 'modal-dcp-form'].forEach(function (id) {
        var m = $(id);
        if (m && m.classList.contains('open')) closeModal(m);
      });
    });
  }

  function init() {
    if (!$('dcp-root')) return;
    bound = false;
    state.view = 'month';
    state.filter = 'all';
    state.search = { date: '' };
    state.anchor = new Date();
    bindUi();
    renderAll();
    if (window.CrmDateTimePicker) window.CrmDateTimePicker.initAll($('dcp-root'));
    iconsIn($('dcp-root'));
  }

  return { init: init, renderAll: renderAll };
})();
