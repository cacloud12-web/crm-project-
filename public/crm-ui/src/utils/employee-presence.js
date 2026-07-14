/**
 * Shared employee presence UI labels for assignment screens.
 * Maps backend is_online → Present / Absent (wording only; logic unchanged).
 */
window.CAEmployeePresence = (function () {
  'use strict';

  var PRESENT = {
    label: 'Present',
    className: 'is-online',
    tooltip: 'Employee is currently present',
    ariaLabel: 'Present',
  };

  var ABSENT = {
    label: 'Absent',
    className: 'is-offline',
    tooltip: 'Employee is currently absent',
    ariaLabel: 'Absent',
  };

  function resolve(isOnline) {
    return isOnline === true ? PRESENT : ABSENT;
  }

  /**
   * Compact presence indicator HTML (dot + text).
   * @param {boolean} isOnline
   * @returns {string}
   */
  function indicatorHtml(isOnline) {
    var s = resolve(isOnline);
    return (
      '<span class="crm-presence ' +
      s.className +
      '" title="' +
      s.tooltip +
      '" aria-label="' +
      s.ariaLabel +
      '">' +
      '<span class="crm-presence__dot" aria-hidden="true"></span>' +
      '<span class="crm-presence__label">' +
      s.label +
      '</span>' +
      '</span>'
    );
  }

  return {
    resolve: resolve,
    indicatorHtml: indicatorHtml,
    PRESENT: PRESENT,
    ABSENT: ABSENT,
  };
})();
