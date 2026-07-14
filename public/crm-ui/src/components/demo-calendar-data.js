/* global window */
window.CrmDemoCalendarData = (function () {
  'use strict';

  var STORAGE_KEY = 'ca_crm_demo_mgmt_calendar_v3';

  var RULES = {
    startTime: '10:00',
    endTime: '19:00',
    slotMinutes: 30,
    closedDays: [0],
    messages: {
      sunday: 'Demos cannot be scheduled on Sundays.',
      start_time: 'Demo start time must be 10:00 AM or later.',
      end_time: 'Demo end time must be 7:00 PM or earlier.',
      end_after_start: 'Demo end time must be after the start time.',
    },
  };

  var STATUS_FILTERS = [
    { key: 'all', label: 'All Demos' },
    { key: 'scheduled', label: 'Scheduled' },
    { key: 'today', label: 'Today' },
    { key: 'completed', label: 'Completed' },
    { key: 'pending', label: 'Pending' },
    { key: 'cancelled', label: 'Cancelled' },
    { key: 'rescheduled', label: 'Rescheduled' },
    { key: 'follow_up', label: 'Follow-up Required' },
    { key: 'invalid_schedule', label: 'Invalid Schedule' },
  ];

  var STATUS_LABELS = {
    scheduled: 'Scheduled',
    completed: 'Completed',
    pending: 'Pending',
    cancelled: 'Cancelled',
    rescheduled: 'Rescheduled',
    follow_up: 'Follow-up Required',
    invalid_schedule: 'Invalid Schedule',
  };

  var STATUS_COLORS = {
    scheduled: { bg: '#eff6ff', border: '#93c5fd', text: '#1d4ed8', dot: '#3b82f6' },
    completed: { bg: '#ecfdf5', border: '#6ee7b7', text: '#047857', dot: '#10b981' },
    follow_up: { bg: '#fff7ed', border: '#fdba74', text: '#c2410c', dot: '#f97316' },
    cancelled: { bg: '#fef2f2', border: '#fca5a5', text: '#b91c1c', dot: '#ef4444' },
    rescheduled: { bg: '#f5f3ff', border: '#c4b5fd', text: '#6d28d9', dot: '#8b5cf6' },
    pending: { bg: '#f1f5f9', border: '#cbd5e1', text: '#475569', dot: '#64748b' },
    invalid_schedule: { bg: '#fef2f2', border: '#f87171', text: '#991b1b', dot: '#dc2626' },
  };

  function demo(id, firm, ca, exec, phone, date, start, end, status, priority, extras) {
    extras = extras || {};
    return {
      id: id,
      firmName: firm,
      caName: ca,
      executive: exec,
      phone: phone,
      date: date,
      startTime: start,
      endTime: end,
      status: status,
      priority: priority,
      meetingLink: extras.meetingLink || '',
      lastFollowup: extras.lastFollowup || '',
      remarks: extras.remarks || '',
      description: extras.description || '',
    };
  }

  var SEED_DEMOS = [];

  var cachedApiDemos = [];
  var loadPromise = null;

  function isoToDateStr(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  function isoToTimeStr(iso) {
    if (!iso) return '10:00';
    var d = new Date(iso);
    return pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function mapApiStatus(status) {
    if (status === 'missed') return 'pending';
    return status || 'scheduled';
  }

  function mapApiEvent(event) {
    return demo(
      'api-' + event.id,
      event.firm_name || '',
      event.ca_name || '',
      event.employee_name || '',
      '',
      isoToDateStr(event.demo_at),
      isoToTimeStr(event.demo_at),
      isoToTimeStr(event.demo_end_at || event.demo_at),
      mapApiStatus(event.status),
      'medium',
      {
        meetingLink: event.meeting_link || '',
        remarks: event.notes || '',
        description: event.notes || '',
      },
    );
  }

  function loadFromApi(params) {
    params = params || {};
    if (!window.CA_CRM || typeof window.CA_CRM.apiFetch !== 'function') {
      cachedApiDemos = [];
      return Promise.resolve([]);
    }

    var qs = new URLSearchParams({
      view: params.view || 'month',
      date: params.date || new Date().toISOString().slice(0, 10),
    });

    loadPromise = window.CA_CRM.apiFetch('/demo-calendar/events?' + qs.toString())
      .then(function (body) {
        var rows = Array.isArray(body.data) ? body.data : (body.data && body.data.data ? body.data.data : []);
        cachedApiDemos = rows.map(mapApiEvent).map(applyScheduleIntegrity);
        return cachedApiDemos;
      })
      .catch(function () {
        cachedApiDemos = [];
        return [];
      });

    return loadPromise;
  }

  function readStore() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return { customDemos: [], overrides: {}, deletedIds: [] };
      var parsed = JSON.parse(raw);
      return {
        customDemos: Array.isArray(parsed.customDemos) ? parsed.customDemos : (parsed.customEvents || []),
        overrides: parsed.overrides && typeof parsed.overrides === 'object' ? parsed.overrides : {},
        deletedIds: Array.isArray(parsed.deletedIds) ? parsed.deletedIds : [],
      };
    } catch (e) {
      return { customDemos: [], overrides: {}, deletedIds: [] };
    }
  }

  function writeStore(store) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(store));
  }

  function cloneDemo(d) {
    return JSON.parse(JSON.stringify(d));
  }

  function parseLocalDate(dateStr) {
    var p = String(dateStr || '').split('-');
    return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
  }

  function timeToMinutes(t) {
    if (!t) return 0;
    var p = String(t).split(':');
    return parseInt(p[0], 10) * 60 + parseInt(p[1] || '0', 10);
  }

  function minutesToTime(total) {
    var h = Math.floor(total / 60);
    var m = total % 60;
    return pad(h) + ':' + pad(m);
  }

  function formatTime12FromMinutes(total) {
    var h = Math.floor(total / 60);
    var m = total % 60;
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12 || 12;
    return h12 + ':' + pad(m) + ' ' + ampm;
  }

  function isSunday(dateStr) {
    return parseLocalDate(dateStr).getDay() === 0;
  }

  function isClosedDay(dateStr) {
    return RULES.closedDays.indexOf(parseLocalDate(dateStr).getDay()) >= 0;
  }

  function validateSchedule(date, startTime, endTime) {
    if (isSunday(date)) {
      return { valid: false, message: RULES.messages.sunday };
    }
    var start = timeToMinutes(startTime);
    var end = timeToMinutes(endTime);
    var workStart = timeToMinutes(RULES.startTime);
    var workEnd = timeToMinutes(RULES.endTime);
    if (start < workStart) {
      return { valid: false, message: RULES.messages.start_time };
    }
    if (end > workEnd) {
      return { valid: false, message: RULES.messages.end_time };
    }
    if (end <= start) {
      return { valid: false, message: RULES.messages.end_after_start };
    }
    return { valid: true, message: null };
  }

  function applyScheduleIntegrity(d) {
    var copy = cloneDemo(d);
    if (copy.status === 'invalid_schedule') {
      return copy;
    }
    var check = validateSchedule(copy.date, copy.startTime, copy.endTime);
    if (!check.valid) {
      copy._priorStatus = copy.status;
      copy.status = 'invalid_schedule';
      copy.invalidReason = check.message;
    }
    return copy;
  }

  function isQueueEligible(demo) {
    if (demo.status === 'invalid_schedule') return false;
    return validateSchedule(demo.date, demo.startTime, demo.endTime).valid;
  }

  function getStartTimeOptions() {
    var slots = [];
    var start = timeToMinutes(RULES.startTime);
    var end = timeToMinutes(RULES.endTime);
    for (var m = start; m < end; m += RULES.slotMinutes) {
      slots.push({ value: minutesToTime(m), label: formatTime12FromMinutes(m) });
    }
    return slots;
  }

  function getEndTimeOptions(startTime) {
    var slots = [];
    var start = timeToMinutes(startTime);
    var workEnd = timeToMinutes(RULES.endTime);
    for (var m = start + RULES.slotMinutes; m <= workEnd; m += RULES.slotMinutes) {
      slots.push({ value: minutesToTime(m), label: formatTime12FromMinutes(m) });
    }
    return slots;
  }

  function getAllDemos() {
    return cachedApiDemos.slice();
  }

  function saveCustomDemo(demoObj) {
    var store = readStore();
    var idx = store.customDemos.findIndex(function (d) { return d.id === demoObj.id; });
    if (idx >= 0) store.customDemos[idx] = demoObj;
    else store.customDemos.push(demoObj);
    store.overrides[demoObj.id] = demoObj;
    writeStore(store);
  }

  function updateDemo(demoObj) {
    var store = readStore();
    var isSeed = SEED_DEMOS.some(function (d) { return d.id === demoObj.id; });
    if (isSeed) store.overrides[demoObj.id] = demoObj;
    else {
      var idx = store.customDemos.findIndex(function (d) { return d.id === demoObj.id; });
      if (idx >= 0) store.customDemos[idx] = demoObj;
      else store.customDemos.push(demoObj);
      store.overrides[demoObj.id] = demoObj;
    }
    writeStore(store);
  }

  function deleteDemo(id) {
    var store = readStore();
    if (store.deletedIds.indexOf(id) < 0) store.deletedIds.push(id);
    delete store.overrides[id];
    store.customDemos = store.customDemos.filter(function (d) { return d.id !== id; });
    writeStore(store);
  }

  function resetDemoData() {
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch (e) {
      /* ignore */
    }
    cachedApiDemos = [];
  }

  function nextCustomId() {
    return 'dm-custom-' + Date.now();
  }

  function getStatusStyle(status) {
    return STATUS_COLORS[status] || STATUS_COLORS.pending;
  }

  function statusLabel(status) {
    return STATUS_LABELS[status] || status || 'Pending';
  }

  function countByFilter(demos, todayStr) {
    var counts = { all: demos.length };
    STATUS_FILTERS.forEach(function (f) {
      if (f.key !== 'all') counts[f.key] = 0;
    });
    demos.forEach(function (d) {
      if (d.date === todayStr) counts.today += 1;
      if (counts[d.status] !== undefined) counts[d.status] += 1;
    });
    return counts;
  }

  function matchesFilter(demo, filterKey, todayStr) {
    if (filterKey === 'all') return true;
    if (filterKey === 'today') return demo.date === todayStr;
    return demo.status === filterKey;
  }

  function matchesSearch(demo, search) {
    search = search || {};
    var q = (search.q || '').toLowerCase().trim();
    if (q) {
      var hay = [demo.firmName, demo.caName, demo.executive, demo.phone, demo.remarks].join(' ').toLowerCase();
      if (hay.indexOf(q) < 0) return false;
    }
    if (search.status && demo.status !== search.status) return false;
    if (search.priority && demo.priority !== search.priority) return false;
    if (search.date && demo.date !== search.date) return false;
    return true;
  }

  function filterDemos(demos, filterKey, search, todayStr) {
    return demos.filter(function (d) {
      return matchesFilter(d, filterKey, todayStr) && matchesSearch(d, search);
    });
  }

  function getSummaryStats(demos, todayStr) {
    var weekEnd = addDaysStr(todayStr, 7);
    var eligible = demos.filter(isQueueEligible);
    return {
      today: eligible.filter(function (d) { return d.date === todayStr; }).length,
      week: eligible.filter(function (d) { return d.date >= todayStr && d.date <= weekEnd; }).length,
      completedToday: eligible.filter(function (d) { return d.date === todayStr && d.status === 'completed'; }).length,
      pending: demos.filter(function (d) { return d.status === 'pending'; }).length,
      cancelled: demos.filter(function (d) { return d.status === 'cancelled'; }).length,
      followUp: demos.filter(function (d) { return d.status === 'follow_up'; }).length,
    };
  }

  function addDaysStr(dateStr, days) {
    var p = dateStr.split('-');
    var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
    d.setDate(d.getDate() + days);
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  function pad(n) { return n < 10 ? '0' + n : String(n); }

  function getExecutives(demos) {
    var map = {};
    demos.forEach(function (d) { if (d.executive) map[d.executive] = true; });
    return Object.keys(map).sort();
  }

  function aggregateByStatus(dayDemos) {
    var agg = {};
    dayDemos.forEach(function (d) {
      agg[d.status] = (agg[d.status] || 0) + 1;
    });
    return agg;
  }

  try {
    localStorage.removeItem(STORAGE_KEY);
  } catch (e) {
    /* ignore */
  }

  return {
    RULES: RULES,
    STATUS_FILTERS: STATUS_FILTERS,
    STATUS_COLORS: STATUS_COLORS,
    STATUS_LABELS: STATUS_LABELS,
    SEED_DEMOS: SEED_DEMOS,
    loadFromApi: loadFromApi,
    getAllDemos: getAllDemos,
    saveCustomDemo: saveCustomDemo,
    updateDemo: updateDemo,
    deleteDemo: deleteDemo,
    resetDemoData: resetDemoData,
    nextCustomId: nextCustomId,
    getStatusStyle: getStatusStyle,
    statusLabel: statusLabel,
    countByFilter: countByFilter,
    filterDemos: filterDemos,
    getSummaryStats: getSummaryStats,
    getExecutives: getExecutives,
    aggregateByStatus: aggregateByStatus,
    addDaysStr: addDaysStr,
    validateSchedule: validateSchedule,
    isSunday: isSunday,
    isClosedDay: isClosedDay,
    isQueueEligible: isQueueEligible,
    getStartTimeOptions: getStartTimeOptions,
    getEndTimeOptions: getEndTimeOptions,
    timeToMinutes: timeToMinutes,
    STORAGE_KEY: STORAGE_KEY,
  };
})();
