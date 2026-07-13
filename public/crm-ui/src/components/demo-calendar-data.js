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
      meetingLink: extras.meetingLink || 'https://meet.google.com/demo-' + id.replace(/\D/g, '').slice(-6),
      lastFollowup: extras.lastFollowup || '',
      remarks: extras.remarks || '',
      description: extras.description || 'Product demo for CA Cloud Desk CRM.',
    };
  }

  var SEED_DEMOS = [
    demo('dm-001', 'Sharma & Associates', 'Rakesh Sharma', 'Rahul Mehta', '9876543210', '2026-07-11', '10:00', '10:45', 'scheduled', 'high', { lastFollowup: '2026-07-10', remarks: 'Interested in GST module' }),
    demo('dm-002', 'ABC & Co Chartered Accountants', 'Priya ABC', 'Priya Sharma', '9812345678', '2026-07-11', '11:30', '12:15', 'completed', 'medium', { remarks: 'Demo went well — pricing discussion next' }),
    demo('dm-003', 'Jain & Co', 'Ankit Jain', 'Ankit Verma', '9988776655', '2026-07-11', '14:00', '14:45', 'pending', 'high'),
    demo('dm-004', 'Verma Tax Consultants', 'Amit Verma', 'Neha Gupta', '9123456780', '2026-07-11', '16:30', '17:15', 'follow_up', 'high', { lastFollowup: '2026-07-11', remarks: 'Needs partner approval' }),
    demo('dm-005', 'Gupta Enterprises', 'Neha Gupta', 'Rahul Mehta', '9012345678', '2026-07-11', '18:00', '19:00', 'scheduled', 'medium'),
    demo('dm-006', 'Mehta Industries', 'Rahul Mehta', 'Amit Verma', '9876501234', '2026-07-13', '10:30', '11:15', 'scheduled', 'high'),
    demo('dm-007', 'Patel & Partners', 'Kiran Patel', 'Priya Sharma', '9898989898', '2026-07-13', '11:00', '11:45', 'rescheduled', 'medium', { remarks: 'Rescheduled from Jul 10' }),
    demo('dm-008', 'Singh Audit Firm', 'Harpreet Singh', 'Neha Gupta', '9765432109', '2026-07-13', '14:30', '15:15', 'pending', 'low'),
    demo('dm-009', 'Reddy & Associates', 'Suresh Reddy', 'Rahul Mehta', '9654321098', '2026-07-14', '10:00', '10:45', 'scheduled', 'high'),
    demo('dm-010', 'Kapoor CA Services', 'Meera Kapoor', 'Ankit Verma', '9543210987', '2026-07-14', '12:00', '12:45', 'completed', 'medium'),
    demo('dm-011', 'Desai Tax Advisors', 'Vikram Desai', 'Priya Sharma', '9432109876', '2026-07-14', '15:00', '15:45', 'follow_up', 'high', { lastFollowup: '2026-07-13' }),
    demo('dm-012', 'Iyer & Co', 'Lakshmi Iyer', 'Amit Verma', '9321098765', '2026-07-15', '10:00', '10:45', 'scheduled', 'medium'),
    demo('dm-013', 'Bose Chartered Accountants', 'Arjun Bose', 'Neha Gupta', '9210987654', '2026-07-15', '11:30', '12:15', 'cancelled', 'low', { remarks: 'Client unavailable' }),
    demo('dm-014', 'Malhotra & Sons CA', 'Sanjay Malhotra', 'Rahul Mehta', '9109876543', '2026-07-15', '14:00', '14:45', 'scheduled', 'high'),
    demo('dm-015', 'Chopra Tax Solutions', 'Divya Chopra', 'Priya Sharma', '9098765432', '2026-07-16', '10:30', '11:15', 'pending', 'medium'),
    demo('dm-016', 'Nair Associates', 'Rajesh Nair', 'Ankit Verma', '8987654321', '2026-07-16', '13:00', '13:45', 'completed', 'high'),
    demo('dm-017', 'Khan & Co CA Firm', 'Imran Khan', 'Amit Verma', '8876543210', '2026-07-17', '10:30', '11:15', 'scheduled', 'medium'),
    demo('dm-018', 'Roy Tax Consultants', 'Subhash Roy', 'Neha Gupta', '8765432109', '2026-07-17', '11:00', '11:45', 'rescheduled', 'high'),
    demo('dm-019', 'Pillai & Associates', 'Thomas Pillai', 'Rahul Mehta', '8654321098', '2026-07-18', '10:00', '10:45', 'scheduled', 'low'),
    demo('dm-020', 'Saxena CA Office', 'Pooja Saxena', 'Priya Sharma', '8543210987', '2026-07-18', '15:30', '16:15', 'follow_up', 'medium'),
    demo('dm-021', 'Bhatt & Partners', 'Harsh Bhatt', 'Ankit Verma', '8432109876', '2026-07-24', '10:00', '10:45', 'completed', 'high'),
    demo('dm-022', 'Tripathi Tax Services', 'Om Tripathi', 'Amit Verma', '8321098765', '2026-07-24', '12:30', '13:15', 'scheduled', 'medium'),
    demo('dm-023', 'Mishra & Co', 'Alok Mishra', 'Neha Gupta', '8210987654', '2026-07-20', '10:00', '10:45', 'pending', 'high'),
    demo('dm-024', 'Dubey Chartered Accountants', 'Ravi Dubey', 'Rahul Mehta', '8109876543', '2026-07-20', '14:00', '14:45', 'cancelled', 'low'),
    demo('dm-025', 'Joshi Audit & Tax', 'Kavita Joshi', 'Priya Sharma', '8098765432', '2026-07-21', '11:00', '11:45', 'scheduled', 'medium'),
    demo('dm-026', 'Agarwal & Associates', 'Manish Agarwal', 'Ankit Verma', '7987654321', '2026-07-21', '16:00', '16:45', 'completed', 'high'),
    demo('dm-027', 'Thakur CA Firm', 'Vivek Thakur', 'Amit Verma', '7876543210', '2026-07-22', '10:30', '11:15', 'scheduled', 'high'),
    demo('dm-028', 'Yadav Tax Advisors', 'Sunita Yadav', 'Neha Gupta', '7765432109', '2026-07-22', '13:30', '14:15', 'follow_up', 'medium'),
    demo('dm-029', 'Chaudhary & Co', 'Deepak Chaudhary', 'Rahul Mehta', '7654321098', '2026-07-23', '10:30', '11:15', 'rescheduled', 'high'),
    demo('dm-030', 'Banerjee Associates', 'Ananya Banerjee', 'Priya Sharma', '7543210987', '2026-07-23', '15:00', '15:45', 'scheduled', 'low'),
    demo('dm-031', 'Kulkarni CA Services', 'Prakash Kulkarni', 'Ankit Verma', '7432109876', '2026-07-08', '11:00', '11:45', 'completed', 'medium'),
    demo('dm-032', 'Menon Tax Consultants', 'Rekha Menon', 'Neha Gupta', '7321098765', '2026-07-09', '14:30', '15:15', 'completed', 'high'),
    demo('dm-033', 'Fernandes & Co', 'Carlos Fernandes', 'Rahul Mehta', '7210987654', '2026-07-10', '10:00', '10:45', 'cancelled', 'medium'),
    demo('dm-034', 'Das Chartered Accountants', 'Arindam Das', 'Amit Verma', '7109876543', '2026-07-10', '12:00', '12:45', 'follow_up', 'high'),
    demo('dm-035', 'Rao & Associates', 'Srinivas Rao', 'Priya Sharma', '7098765432', '2026-07-10', '16:00', '16:45', 'scheduled', 'medium'),
    demo('dm-036', 'Gill Tax Solutions', 'Harleen Gill', 'Ankit Verma', '6987654321', '2026-07-07', '10:00', '10:45', 'completed', 'low'),
    demo('dm-037', 'Sethi & Partners', 'Navdeep Sethi', 'Neha Gupta', '6876543210', '2026-07-06', '11:30', '12:15', 'completed', 'medium'),
    demo('dm-038', 'Bansal CA Office', 'Rohit Bansal', 'Rahul Mehta', '6765432109', '2026-07-25', '10:00', '10:45', 'pending', 'high'),
    demo('dm-039', 'Tiwari & Co', 'Gaurav Tiwari', 'Amit Verma', '6654321098', '2026-07-25', '14:30', '15:15', 'scheduled', 'medium'),
    demo('dm-040', 'Pandey Tax Advisors', 'Shalini Pandey', 'Priya Sharma', '6543210987', '2026-07-28', '10:00', '10:45', 'scheduled', 'high'),
    demo('dm-041', 'Rawat Associates', 'Vikash Rawat', 'Ankit Verma', '6432109876', '2026-07-27', '11:00', '11:45', 'pending', 'low'),
    demo('dm-042', 'Sood Chartered Accountants', 'Manpreet Sood', 'Neha Gupta', '6321098765', '2026-07-28', '15:00', '15:45', 'scheduled', 'medium'),
  ];

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
    var store = readStore();
    var deleted = {};
    store.deletedIds.forEach(function (id) { deleted[id] = true; });
    var demos = [];
    SEED_DEMOS.forEach(function (d) {
      if (deleted[d.id]) return;
      demos.push(cloneDemo(store.overrides[d.id] || d));
    });
    store.customDemos.forEach(function (d) {
      if (deleted[d.id]) return;
      demos.push(cloneDemo(store.overrides[d.id] || d));
    });
    return demos.map(applyScheduleIntegrity);
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
    localStorage.removeItem(STORAGE_KEY);
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
    if (search.executive && demo.executive !== search.executive) return false;
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

  return {
    RULES: RULES,
    STATUS_FILTERS: STATUS_FILTERS,
    STATUS_COLORS: STATUS_COLORS,
    STATUS_LABELS: STATUS_LABELS,
    SEED_DEMOS: SEED_DEMOS,
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
