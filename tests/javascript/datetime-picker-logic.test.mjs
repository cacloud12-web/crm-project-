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
  return null;
}

function convert12To24(hour12, isPM) {
  if (Number.isNaN(hour12) || hour12 < 1 || hour12 > 12) return null;
  if (isPM) return hour12 === 12 ? 12 : hour12 + 12;
  return hour12 === 12 ? 0 : hour12;
}

function toLocalInputValue(date) {
  return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) +
    'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
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

console.log('datetime-picker-logic: all checks passed');
