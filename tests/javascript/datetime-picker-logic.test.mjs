/**
 * Pure-logic checks for datetime-picker (mirrors public/crm-ui/src/components/datetime-picker.js).
 * Run: node tests/javascript/datetime-picker-logic.test.mjs
 */

function pad(n) {
  return String(n).padStart(2, '0');
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

function toLocalInputValue(date, mode) {
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

function validateTimeParts(hour12, minute) {
  if (Number.isNaN(hour12) || hour12 < 1 || hour12 > 12) {
    return { valid: false, message: 'Hour must be between 1 and 12.' };
  }
  if (Number.isNaN(minute) || minute < 0 || minute > 59) {
    return { valid: false, message: 'Minute must be between 00 and 59.' };
  }
  return { valid: true, hour12: hour12, minute: minute };
}

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

var cases = [
  ['2026-07-15T00:00', 0, 0, '12 AM midnight'],
  ['2026-07-15T12:00', 12, 0, '12 PM noon'],
  ['2026-07-15T13:00', 13, 0, '1 PM'],
  ['2026-07-15T23:59', 23, 59, '11:59 PM'],
  ['2026-07-15 14:30:00', 14, 30, 'SQL datetime'],
];

cases.forEach(function (item) {
  var parsed = parseInputValue(item[0], 'datetime');
  assert(parsed, 'parse failed: ' + item[0]);
  assert(parsed.getHours() === item[1], item[3] + ' hour');
  assert(parsed.getMinutes() === item[2], item[3] + ' minute');
});

assert(convert12To24(12, false) === 0, '12 AM');
assert(convert12To24(12, true) === 12, '12 PM');
assert(convert12To24(1, true) === 13, '1 PM');
assert(convert12To24(11, true) === 23, '11 PM');
assert(convert12To24(0, false) === null, 'reject hour 0');

var roundTrip = toLocalInputValue(parseInputValue('2026-07-15T23:59', 'datetime'));
assert(roundTrip === '2026-07-15T23:59', 'round trip 11:59 PM');

var display = formatDisplay(parseInputValue('2026-07-14T12:44', 'datetime'), 'datetime');
assert(display === '14/07/2026, 12:44 PM', 'display format datetime');

var displayDate = formatDisplay(parseInputValue('2026-07-14', 'date'), 'date');
assert(displayDate === '14/07/2026', 'display format date');

var parsedDisplay = parseInputValue('14/07/2026, 12:44 PM', 'datetime');
assert(parsedDisplay && parsedDisplay.getHours() === 12 && parsedDisplay.getMinutes() === 44, 'parse display format');

assert(validateTimeParts(12, 30).valid, 'valid noon');
assert(!validateTimeParts(13, 30).valid, 'reject hour 13');
assert(!validateTimeParts(0, 30).valid, 'reject hour 0');
assert(!validateTimeParts(10, 60).valid, 'reject minute 60');

console.log('datetime-picker-logic: all checks passed');
