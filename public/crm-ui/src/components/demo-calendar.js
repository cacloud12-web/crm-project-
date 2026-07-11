/* global window, document */
window.CrmDemoCalendar = (function () {
  'use strict';

  var state = {
    view: 'week',
    date: new Date().toISOString().slice(0, 10),
    providerId: '',
    employeeId: '',
    status: '',
    events: [],
    summary: null,
    slots: null,
    providers: [],
    prefix: 'demo-cal',
    bound: false,
  };

  function apiFetch(url, options) {
    return window.CA_CRM.apiFetch(url, options);
  }

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function iconsIn(el) {
    if (window.CA_CRM && typeof window.CA_CRM.iconsIn === 'function') {
      window.CA_CRM.iconsIn(el);
    } else if (window.lucide) {
      window.lucide.createIcons({ nodes: [el || document] });
    }
  }

  function toast(msg, type) {
    if (window.toast) window.toast(msg, type || 'info');
  }

  function canSchedule() {
    return window.CrmRbac && window.CrmRbac.can('followups', 'schedule_demo');
  }

  function statusClass(status) {
    return 'demo-cal-slot demo-cal-slot--' + String(status || 'available').replace(/[^a-z_]/g, '_');
  }

  function qs(extra) {
    var params = new URLSearchParams({
      view: state.view,
      date: state.date,
    });
    if (state.providerId) params.set('demo_provider_id', state.providerId);
    if (state.employeeId) params.set('employee_id', state.employeeId);
    if (state.status) params.set('status', state.status);
    if (extra) {
      Object.keys(extra).forEach(function (k) {
        if (extra[k] !== undefined && extra[k] !== null && extra[k] !== '') params.set(k, extra[k]);
      });
    }
    return params.toString();
  }

  function loadProviders() {
    return apiFetch('/demo-calendar/providers').then(function (body) {
      state.providers = body.data || [];
      return state.providers;
    });
  }

  function loadSummary() {
    return apiFetch('/demo-calendar/summary?' + qs()).then(function (body) {
      state.summary = body.data || null;
      renderSummary();
      return state.summary;
    });
  }

  function loadEvents() {
    var section = document.getElementById(state.prefix + '-body');
    if (section) section.innerHTML = '<div class="crm-inline-loading py-8"><i data-lucide="loader-2" class="h-5 w-5 animate-spin text-brand"></i><span>Loading demo calendar…</span></div>';
    iconsIn(section);
    return apiFetch('/demo-calendar/events?' + qs()).then(function (body) {
      state.events = Array.isArray(body.data) ? body.data : (body.data && body.data.data ? body.data.data : []);
      renderCalendarBody();
      return state.events;
    }).catch(function (err) {
      if (section) section.innerHTML = '<p class="text-rose-500 text-sm py-4">' + escapeHtml(err.message || 'Unable to load demo calendar.') + '</p>';
    });
  }

  function loadAvailableSlots() {
    if (!state.providerId) {
      state.slots = null;
      renderSlotsPanel();
      return Promise.resolve(null);
    }
    return apiFetch('/demo-calendar/available-slots?' + qs()).then(function (body) {
      state.slots = body.data || null;
      renderSlotsPanel();
      return state.slots;
    });
  }

  function renderSummary() {
    var el = document.getElementById(state.prefix + '-summary');
    if (!el || !state.summary) return;
    var s = state.summary;
    el.innerHTML =
      '<div class="demo-cal-kpi"><span>Total Today</span><strong>' + (s.total_demos || 0) + '</strong></div>' +
      '<div class="demo-cal-kpi"><span>Completed</span><strong>' + (s.completed || 0) + '</strong></div>' +
      '<div class="demo-cal-kpi"><span>Upcoming</span><strong>' + (s.upcoming || 0) + '</strong></div>' +
      '<div class="demo-cal-kpi"><span>Missed</span><strong>' + (s.missed || 0) + '</strong></div>' +
      '<div class="demo-cal-kpi"><span>Cancelled</span><strong>' + (s.cancelled || 0) + '</strong></div>' +
      '<div class="demo-cal-kpi"><span>Available Slots</span><strong>' + (s.available_slots || 0) + '</strong></div>' +
      '<div class="demo-cal-kpi demo-cal-kpi--warn"><span>Fully Booked</span><strong>' + (s.providers_fully_booked || 0) + '</strong></div>';
  }

  function renderFilters() {
    var providerSel = document.getElementById(state.prefix + '-provider');
    if (providerSel && providerSel.options.length <= 1) {
      providerSel.innerHTML = '<option value="">All Demo Providers</option>' +
        state.providers.map(function (p) {
          return '<option value="' + p.id + '">' + escapeHtml(p.name) + '</option>';
        }).join('');
      providerSel.value = state.providerId;
    }
  }

  function renderCalendarBody() {
    var el = document.getElementById(state.prefix + '-body');
    if (!el) return;

    if (state.view === 'agenda' || window.matchMedia('(max-width: 768px)').matches) {
      el.innerHTML = renderAgendaView();
    } else if (state.view === 'day') {
      el.innerHTML = renderDayView();
    } else if (state.view === 'month') {
      el.innerHTML = renderMonthView();
    } else {
      el.innerHTML = renderWeekView();
    }
    iconsIn(el);
    bindEventCards(el);
  }

  function renderAgendaView() {
    if (!state.events.length) return '<p class="text-slate-500 text-sm py-6 text-center">No demos scheduled for this period.</p>';
    return '<div class="demo-cal-agenda">' + state.events.map(renderEventCard).join('') + '</div>';
  }

  function renderWeekView() {
    var days = groupEventsByDate(state.events);
    var keys = Object.keys(days).sort();
    if (!keys.length) return '<p class="text-slate-500 text-sm py-6 text-center">No demos scheduled this week.</p>';
    return '<div class="demo-cal-week">' + keys.map(function (day) {
      return '<div class="demo-cal-day-col"><h4 class="demo-cal-day-title">' + escapeHtml(formatDayLabel(day)) + '</h4>' +
        (days[day].map(renderEventCard).join('') || '<p class="text-caption text-slate-400">No demos</p>') + '</div>';
    }).join('') + '</div>';
  }

  function renderDayView() {
    return renderAgendaView();
  }

  function renderMonthView() {
    return renderAgendaView();
  }

  function groupEventsByDate(events) {
    var map = {};
    (events || []).forEach(function (ev) {
      var day = (ev.demo_at || '').slice(0, 10);
      if (!day) return;
      map[day] = map[day] || [];
      map[day].push(ev);
    });
    return map;
  }

  function formatDayLabel(day) {
    try {
      return new Date(day + 'T12:00:00').toLocaleDateString('en-IN', { weekday: 'short', day: '2-digit', month: 'short' });
    } catch (e) {
      return day;
    }
  }

  function renderEventCard(ev) {
    return '<article class="demo-cal-event ' + statusClass(ev.status) + '" data-demo-id="' + ev.id + '" data-ca-id="' + (ev.ca_id || '') + '">' +
      '<div class="demo-cal-event__head">' +
        '<span class="demo-cal-event__time">' + escapeHtml(ev.time_label || '') + '</span>' +
        '<span class="demo-cal-event__status">' + escapeHtml(ev.status_label || ev.status || '') + '</span>' +
      '</div>' +
      '<p class="demo-cal-event__firm">' + escapeHtml(ev.firm_name || '—') + '</p>' +
      '<p class="demo-cal-event__meta">' + escapeHtml(ev.ca_name || '') + ' · ' + escapeHtml(ev.demo_provider_name || 'Provider') + '</p>' +
      '<p class="demo-cal-event__meta">' + escapeHtml(ev.employee_name || '') + (ev.team_size ? ' · Team ' + ev.team_size : '') + '</p>' +
      '<div class="demo-cal-event__actions">' +
        (ev.meeting_link ? '<a href="' + escapeHtml(ev.meeting_link) + '" target="_blank" rel="noopener" class="demo-cal-icon-btn" title="Open meeting"><i data-lucide="video" class="h-3.5 w-3.5"></i></a>' : '') +
        (ev.notes ? '<span class="demo-cal-icon-btn" title="' + escapeHtml(ev.notes) + '"><i data-lucide="sticky-note" class="h-3.5 w-3.5"></i></span>' : '') +
        '<button type="button" class="demo-cal-icon-btn" data-demo-action="view" data-demo-id="' + ev.id + '"><i data-lucide="eye" class="h-3.5 w-3.5"></i></button>' +
      '</div></article>';
  }

  function renderSlotsPanel() {
    var el = document.getElementById(state.prefix + '-slots');
    if (!el) return;
    if (!state.providerId) {
      el.innerHTML = '<p class="text-caption text-slate-400">Select a provider to see available slots.</p>';
      return;
    }
    if (!state.slots) {
      el.innerHTML = '<p class="text-caption text-slate-400">Loading slots…</p>';
      return;
    }
    var slots = state.slots.slots || [];
    el.innerHTML =
      '<p class="demo-cal-slots-label">' + escapeHtml(state.slots.availability_label || 'Available') + '</p>' +
      (slots.length
        ? '<div class="demo-cal-slots-list">' + slots.map(function (slot) {
          return '<button type="button" class="demo-cal-slot-btn" data-slot-start="' + escapeHtml(slot.start_at) + '">' + escapeHtml(slot.label) + '</button>';
        }).join('') + '</div>'
        : '<p class="text-caption text-slate-500">No open slots for this date.</p>');
  }

  function bindEventCards(root) {
    if (!root) return;
    root.querySelectorAll('[data-demo-action="view"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openDemoDetail(parseInt(btn.getAttribute('data-demo-id'), 10));
      });
    });
    root.querySelectorAll('.demo-cal-slot-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openScheduleModal({ start_at: btn.getAttribute('data-slot-start') });
      });
    });
  }

  function openDemoDetail(id) {
    var ev = state.events.find(function (e) { return String(e.id) === String(id); });
    if (!ev) return;
    if (typeof window.openDetailDrawer !== 'function') {
      toast(ev.firm_name + ' · ' + (ev.time_label || ''), 'info');
      return;
    }
    window.openDetailDrawer({
      firm: ev.firm_name || ('Demo #' + ev.id),
      fields: [
        { label: 'Time', value: (ev.date_label || '') + ' ' + (ev.time_label || '') },
        { label: 'Provider', value: ev.demo_provider_name || '—' },
        { label: 'Employee', value: ev.employee_name || '—' },
        { label: 'CA Name', value: ev.ca_name || '—' },
        { label: 'Team Size', value: ev.team_size ? String(ev.team_size) : '—' },
        { label: 'Status', value: ev.status_label || ev.status || '—' },
        { label: 'Meeting Link', value: ev.meeting_link || '—' },
        { label: 'Notes', value: ev.notes || '—' },
      ],
      extraHtml: (ev.meeting_link ? '<a class="btn-secondary btn-sm mt-3 inline-flex" href="' + escapeHtml(ev.meeting_link) + '" target="_blank" rel="noopener">Open Meeting Link</a>' : ''),
    });
    iconsIn(document.getElementById('detail-drawer'));
  }

  function openScheduleModal(prefill) {
    if (!canSchedule()) {
      toast('You do not have permission to schedule demos.', 'warning');
      return;
    }
    prefill = prefill || {};
    var modal = document.getElementById('modal-demo-calendar-schedule');
    var form = document.getElementById('form-demo-calendar-schedule');
    if (!modal || !form) {
      toast('Schedule demo form is unavailable.', 'warning');
      return;
    }
    form.reset();
    if (prefill.start_at) {
      var d = new Date(prefill.start_at);
      document.getElementById('demo-cal-schedule-date').value = d.toISOString().slice(0, 10);
      document.getElementById('demo-cal-schedule-start').value = d.toTimeString().slice(0, 5);
    } else {
      document.getElementById('demo-cal-schedule-date').value = state.date;
    }
    var providerSel = document.getElementById('demo-cal-schedule-provider');
    if (providerSel) {
      providerSel.innerHTML = state.providers.map(function (p) {
        return '<option value="' + p.id + '">' + escapeHtml(p.name) + '</option>';
      }).join('');
      providerSel.value = state.providerId || (state.providers[0] ? state.providers[0].id : '');
    }
    if (window.CA_CRM && typeof window.CA_CRM.enhanceEntityLookups === 'function') {
      window.CA_CRM.enhanceEntityLookups(modal);
    }
    if (window.CA_CRM && typeof window.CA_CRM.openExclusiveCrmModal === 'function') {
      window.CA_CRM.openExclusiveCrmModal(modal);
    } else {
      modal.classList.add('open');
    }
    iconsIn(modal);
  }

  function submitScheduleForm(event) {
    event.preventDefault();
    var form = event.target;
    var caSelect = document.getElementById('demo-cal-schedule-lead');
    var caId = caSelect ? caSelect.value : '';
    if (!caId) {
      toast('Please select a lead.', 'warning');
      return;
    }
    var providerId = parseInt(document.getElementById('demo-cal-schedule-provider').value, 10);
    var date = document.getElementById('demo-cal-schedule-date').value;
    var start = document.getElementById('demo-cal-schedule-start').value;
    var end = document.getElementById('demo-cal-schedule-end').value;
    var payload = {
      ca_id: parseInt(caId, 10),
      demo_provider_id: providerId,
      demo_date: date,
      start_time: start,
      end_time: end || null,
      meeting_link: document.getElementById('demo-cal-schedule-link').value || null,
      notes: document.getElementById('demo-cal-schedule-notes').value || null,
      team_size: parseInt(document.getElementById('demo-cal-schedule-team-size').value || '0', 10) || null,
    };

    apiFetch('/demo-calendar/check-conflict', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({}, payload, { demo_at: date + ' ' + start })),
    }).then(function (body) {
      var data = body.data || {};
      if (!data.available) {
        var msg = (data.conflict && data.conflict.message) || 'Slot not available.';
        var suggestions = (data.suggestions || []).map(function (s) { return s.label; }).join(', ');
        toast(msg + (suggestions ? ' Try: ' + suggestions : ''), 'error');
        return;
      }
      return apiFetch('/demo-calendar/schedule', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({}, payload, { demo_at: date + ' ' + start })),
      });
    }).then(function (body) {
      if (!body) return;
      toast('Demo scheduled successfully.', 'success');
      if (window.CA_CRM && typeof window.CA_CRM.closeModal === 'function') {
        window.CA_CRM.closeModal(document.getElementById('modal-demo-calendar-schedule'));
      }
      refresh();
      if (window.CA_CRM && typeof window.CA_CRM.refreshFollowupsPage === 'function') {
        window.CA_CRM.refreshFollowupsPage({ reload: true, timelineSilent: true });
      }
    }).catch(function (err) {
      toast(err.message || 'Unable to schedule demo.', 'error');
    });
  }

  function bindUi() {
    if (state.bound) return;
    var section = document.getElementById(state.prefix + '-section');
    if (!section) return;
    state.bound = true;

    section.addEventListener('click', function (e) {
      var viewBtn = e.target.closest('[data-demo-cal-view]');
      if (viewBtn) {
        state.view = viewBtn.getAttribute('data-demo-cal-view');
        section.querySelectorAll('[data-demo-cal-view]').forEach(function (btn) {
          btn.classList.toggle('active', btn.getAttribute('data-demo-cal-view') === state.view);
        });
        loadEvents();
        return;
      }
      if (e.target.closest('#demo-cal-schedule-btn')) {
        openScheduleModal();
      }
      if (e.target.closest('#demo-cal-refresh-btn')) {
        refresh();
      }
    });

    ['provider', 'date', 'status'].forEach(function (key) {
      var el = document.getElementById(state.prefix + '-' + key);
      if (!el) return;
      el.addEventListener('change', function () {
        if (key === 'provider') state.providerId = el.value;
        if (key === 'date') state.date = el.value || state.date;
        if (key === 'status') state.status = el.value;
        refresh();
      });
    });

    section.querySelectorAll('[data-demo-cal-view]').forEach(function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-demo-cal-view') === state.view);
    });

    document.getElementById('form-demo-calendar-schedule')?.addEventListener('submit', submitScheduleForm);

    section.querySelectorAll('.demo-cal-slots-list').forEach(function (list) {
      bindEventCards(list.parentElement);
    });
  }

  function refresh() {
    return Promise.all([loadSummary(), loadEvents(), loadAvailableSlots()]);
  }

  function init(prefix) {
    state.prefix = prefix || 'demo-cal';
    if (!document.getElementById(state.prefix + '-section')) return;
    state.bound = false;
    bindUi();
    loadProviders().then(function () {
      renderFilters();
      return refresh();
    });
    iconsIn(document.getElementById(state.prefix + '-section'));
  }

  return {
    init: init,
    refresh: refresh,
    openScheduleModal: openScheduleModal,
  };
})();
