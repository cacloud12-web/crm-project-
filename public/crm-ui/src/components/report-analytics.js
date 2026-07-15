/* global window, document */
window.CrmReportAnalytics = (function () {
  'use strict';

  var state = {
    slug: null,
    report: null,
    loading: false,
    tableSearch: '',
    sortKey: null,
    sortDir: 'desc',
    bound: false,
    drillDown: null,
    /** Applied filter values — survive Apply / soft refresh / full re-render. */
    filters: null,
  };

  var RA_FILTER_FIELDS = {
    from: 'ra-filter-from',
    to: 'ra-filter-to',
    employee: 'ra-filter-employee',
    status: 'ra-filter-status',
    search: 'ra-filter-source',
  };

  var FILTER_STORAGE_PREFIX = 'ca_crm_report_filter_';

  var CHART_EMPTY = 'No data is available for the selected filters.';

  var SLUG_META = {
    lead_conversion: { title: 'Daily Lead Report', icon: 'calendar-days', hub: 'Daily Lead Report' },
    followup_performance: { title: 'Weekly Demo Report', icon: 'presentation', hub: 'Weekly Demo Report' },
    monthly_trends: { title: 'Monthly Trends', icon: 'line-chart', hub: 'Monthly Trends', notice: 'Revenue is not tracked in this CRM. Metrics show lead and conversion trends only.' },
    city_analysis: { title: 'City-wise Analysis', icon: 'map-pin', hub: 'City-wise Analysis' },
    employee_performance: { title: 'Employee Performance', icon: 'trophy', hub: 'Employee Performance' },
    lost_lead_analysis: { title: 'Lost Lead Analysis', icon: 'trending-down', hub: 'Lost Lead Analysis' },
    duplicate_productivity: { title: 'Duplicate Productivity', icon: 'copy', hub: 'Duplicate Productivity' },
    assignment_statistics: { title: 'Assignment Statistics', icon: 'users' },
    campaign_analytics: { title: 'Campaign Analytics', icon: 'megaphone' },
  };

  var FILTER_PRESETS = {
    lead_conversion: ['date', 'employee', 'status', 'search'],
    followup_performance: ['date', 'employee', 'search'],
    monthly_trends: ['date', 'employee'],
    city_analysis: ['date', 'employee', 'search'],
    employee_performance: ['date', 'employee', 'search'],
    lost_lead_analysis: ['date', 'employee', 'search'],
    duplicate_productivity: ['date', 'employee', 'search'],
    assignment_statistics: ['date', 'employee'],
    campaign_analytics: ['date'],
  };

  var DRAWER_FIELD_DEFS = {
    fromId: 'ra-filter-from',
    toId: 'ra-filter-to',
    employee: { id: 'ra-filter-employee', grow: true },
    status: {
      id: 'ra-filter-status',
      options: '<option value="">All statuses</option><option>Hot</option><option>Warm</option><option>New</option><option>Demo Scheduled</option><option>Lost</option>',
    },
    search: { id: 'ra-filter-source', placeholder: 'Search table…' },
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

  function escapeHtml(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtNum(n) {
    var v = Number(n);
    if (!isFinite(v)) return '—';
    return v.toLocaleString('en-IN');
  }

  function fmtPct(n) {
    var v = Number(n);
    if (!isFinite(v)) return '—';
    return v.toFixed(1) + '%';
  }

  function fmtLabel(key) {
    return String(key || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  function trendFromSeries(values) {
    values = (values || []).filter(function (v) { return isFinite(Number(v)); }).map(Number);
    if (values.length < 4) return null;
    var mid = Math.floor(values.length / 2);
    var first = values.slice(0, mid);
    var second = values.slice(mid);
    var avg = function (arr) { return arr.reduce(function (a, b) { return a + b; }, 0) / (arr.length || 1); };
    var a = avg(first);
    var b = avg(second);
    if (!a && !b) return null;
    if (!a) return { text: '↑ New', cls: 'ra-trend--up', icon: 'trending-up' };
    var pct = Math.round(((b - a) / a) * 100);
    if (pct > 0) return { text: '↑ +' + pct + '%', cls: 'ra-trend--up', icon: 'trending-up' };
    if (pct < 0) return { text: '↓ ' + pct + '%', cls: 'ra-trend--down', icon: 'trending-down' };
    return null;
  }

  function compactTrend(values) {
    var t = trendFromSeries(values);
    return t || { cls: 'ra-trend--neutral', icon: 'minus', text: '' };
  }

  function defaultDateRange() {
    var end = new Date();
    var start = new Date();
    start.setDate(start.getDate() - 30);
    return {
      from: start.toISOString().slice(0, 10),
      to: end.toISOString().slice(0, 10),
    };
  }

  function filterStorageKey(slug) {
    return FILTER_STORAGE_PREFIX + (slug || state.slug || 'report');
  }

  function readPersistedFilters(slug) {
    try {
      var raw = localStorage.getItem(filterStorageKey(slug));
      if (!raw) return null;
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (err) {
      return null;
    }
  }

  function persistFilters(slug, filters) {
    try {
      localStorage.setItem(filterStorageKey(slug), JSON.stringify(filters || {}));
    } catch (err) { /* ignore quota */ }
    if (window.CrmReportFilterToolbar) {
      window.CrmReportFilterToolbar.setSharedState(filterStorageKey(slug), filters || {});
    }
  }

  function clearPersistedFilters(slug) {
    try {
      localStorage.removeItem(filterStorageKey(slug));
    } catch (err) { /* ignore */ }
    if (window.CrmReportFilterToolbar) {
      window.CrmReportFilterToolbar.clearSharedState(filterStorageKey(slug));
    }
  }

  function emptyFilterState(withDefaults) {
    var range = withDefaults ? defaultDateRange() : { from: '', to: '' };
    return {
      from: range.from || '',
      to: range.to || '',
      employee: '',
      status: '',
      search: '',
    };
  }

  function captureFiltersFromDom() {
    var toolbar = window.CrmReportFilterToolbar;
    var next = Object.assign({}, state.filters || emptyFilterState(false));
    if (toolbar && typeof toolbar.captureFields === 'function' && document.getElementById('ra-filter-from')) {
      next = Object.assign(next, toolbar.captureFields(filterStorageKey(state.slug), RA_FILTER_FIELDS));
    } else {
      Object.keys(RA_FILTER_FIELDS).forEach(function (name) {
        var el = $(RA_FILTER_FIELDS[name]);
        if (el) next[name] = String(el.value || '').trim();
      });
    }
    state.filters = next;
    state.tableSearch = next.search || state.tableSearch || '';
    return next;
  }

  function applyFiltersToDom(filters) {
    filters = filters || state.filters || emptyFilterState(true);
    state.filters = Object.assign({}, filters);
    var toolbar = window.CrmReportFilterToolbar;
    if (toolbar && typeof toolbar.restoreFields === 'function') {
      toolbar.restoreFields(filterStorageKey(state.slug), RA_FILTER_FIELDS, filters);
    } else {
      Object.keys(RA_FILTER_FIELDS).forEach(function (name) {
        var el = $(RA_FILTER_FIELDS[name]);
        if (el) el.value = filters[name] || '';
      });
      if (window.CrmDateTimePicker) {
        window.CrmDateTimePicker.syncAll($('ra-root') || document);
      }
    }
  }

  function ensureFilterState(slug, opts) {
    opts = opts || {};
    slug = slug || state.slug;
    if (!opts.reset && state.filters && state.slug === slug) {
      return state.filters;
    }
    var shared = window.CrmReportFilterToolbar
      ? window.CrmReportFilterToolbar.getSharedState(filterStorageKey(slug))
      : null;
    var persisted = shared || readPersistedFilters(slug);
    if (persisted && !opts.reset) {
      state.filters = Object.assign(emptyFilterState(false), persisted);
      return state.filters;
    }
    state.filters = emptyFilterState(true);
    return state.filters;
  }

  function getFilterQuery() {
    var parts = [];
    /* Prefer live DOM when present; otherwise use shared applied state (critical after Apply wipe). */
    if (document.getElementById('ra-filter-from') || document.getElementById('ra-filter-employee')) {
      captureFiltersFromDom();
    } else {
      ensureFilterState(state.slug);
    }
    var f = state.filters || emptyFilterState(true);
    var range = defaultDateRange();
    var fromVal = f.from || range.from;
    var toVal = f.to || range.to;
    parts.push('from=' + encodeURIComponent(fromVal));
    parts.push('to=' + encodeURIComponent(toVal));
    if (f.employee) parts.push('employee_id=' + encodeURIComponent(f.employee));
    return '?' + parts.join('&');
  }

  function syncHubFilters() {
    /* Hub-level date filters removed from Reports page. */
  }

  function validateDateRange() {
    if (window.CrmReportFilterToolbar) {
      return window.CrmReportFilterToolbar.validateDateRange('ra-filter-from', 'ra-filter-to', 'ra-filter-date-error');
    }
    var fromEl = $('ra-filter-from');
    var toEl = $('ra-filter-to');
    if (!fromEl || !toEl) return true;
    hideDateRangeError();
    if (fromEl.value && toEl.value && fromEl.value > toEl.value) {
      toast('From Date cannot be later than To Date.', 'error');
      return false;
    }
    if ((fromEl.value && !toEl.value) || (!fromEl.value && toEl.value)) {
      toast('Please complete the date range with both From Date and To Date.', 'warning');
      return false;
    }
    return true;
  }

  function hideDateRangeError() {
    if (window.CrmReportFilterToolbar) {
      window.CrmReportFilterToolbar.hideDateRangeError('ra-filter-date-error');
    }
  }

  function apiFetch(url) {
    if (window.CA_CRM && typeof window.CA_CRM.apiFetch === 'function') {
      return window.CA_CRM.apiFetch(url);
    }
    return fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    }).then(function (r) { return r.json(); });
  }

  function breakdownCount(breakdown, statuses) {
    var list = Array.isArray(statuses) ? statuses : [statuses];
    return (breakdown || []).reduce(function (sum, row) {
      return list.indexOf(row.status) >= 0 ? sum + (Number(row.lead_count) || 0) : sum;
    }, 0);
  }

  function sumRows(rows, key) {
    return (rows || []).reduce(function (a, r) { return a + (Number(r[key]) || 0); }, 0);
  }

  function paintColumnChart(series, opts) {
    opts = opts || {};
    if (!series || !series.length) return '<p class="ra-chart-empty">' + CHART_EMPTY + '</p>';
    var max = Math.max.apply(null, series.map(function (p) { return Number(p.value) || 0; }).concat([1]));
    return '<div class="dash-column-chart__inner ra-bar-chart">' +
      series.map(function (point, i) {
        var val = Number(point.value) || 0;
        var h = Math.max(8, Math.round((val / max) * 100));
        var label = String(point.label || '—');
        var shortLabel = label.length > 8 ? label.slice(0, 7) + '…' : label;
        return '<div class="dash-column-chart__col" title="' + escapeHtml(label) + ': ' + val + '">' +
          '<span class="dash-column-chart__value">' + val + '</span>' +
          '<div class="ca-chart-bar dash-column-chart__bar" style="height:' + h + '%;transition-delay:' + (i * 35) + 'ms;background:' + (opts.color || '') + '"></div>' +
          '<span class="dash-column-chart__label">' + escapeHtml(shortLabel) + '</span></div>';
      }).join('') + '</div>';
  }

  function paintHorizontalBars(rows, labelKey, valueKey, limit) {
    rows = (rows || []).slice().sort(function (a, b) {
      return (Number(b[valueKey]) || 0) - (Number(a[valueKey]) || 0);
    }).slice(0, limit || 8);
    if (!rows.length) return '<p class="ra-chart-empty">' + CHART_EMPTY + '</p>';
    var max = Math.max.apply(null, rows.map(function (r) { return Number(r[valueKey]) || 0; }).concat([1]));
    return '<div class="ra-hbar-list">' + rows.map(function (row) {
      var val = Number(row[valueKey]) || 0;
      var pct = Math.max(4, Math.round((val / max) * 100));
      return '<div class="ra-hbar-item">' +
        '<div class="ra-hbar-item__head"><span>' + escapeHtml(row[labelKey] || '—') + '</span><strong>' + fmtNum(val) + '</strong></div>' +
        '<div class="ra-hbar-track"><div class="ra-hbar-fill" style="width:' + pct + '%"></div></div></div>';
    }).join('') + '</div>';
  }

  function paintDonut(pct, label) {
    var p = Math.min(100, Math.max(0, Number(pct) || 0));
    var deg = Math.round(p * 3.6);
    return '<div class="ra-donut-wrap">' +
      '<div class="ra-donut" style="--ra-pct:' + deg + 'deg" aria-hidden="true"><span class="ra-donut__value">' + fmtPct(p) + '</span></div>' +
      '<p class="ra-donut__label">' + escapeHtml(label || 'Conversion') + '</p></div>';
  }

  function paintFunnel(stages) {
    if (!stages || !stages.length || !stages.some(function (s) { return (Number(s.value) || 0) > 0; })) {
      return '<p class="ra-chart-empty">' + CHART_EMPTY + '</p>';
    }
    var top = Math.max.apply(null, stages.map(function (s) { return Number(s.value) || 0; }).concat([1]));
    return '<div class="ra-funnel ra-lc-funnel">' + stages.map(function (stage, i) {
      var val = Number(stage.value) || 0;
      var width = Math.max(18, Math.round((val / top) * 100));
      var drop = stage.drop != null ? '<span class="ra-funnel__drop">' + stage.drop + '</span>' : '';
      return (i > 0 ? '<div class="ra-funnel__arrow" aria-hidden="true"><i data-lucide="chevron-down" class="h-4 w-4"></i></div>' : '') +
        '<div class="ra-funnel__stage" style="width:' + width + '%">' +
        '<span class="ra-funnel__label">' + escapeHtml(stage.label) + '</span>' +
        '<strong class="ra-funnel__value">' + fmtNum(val) + '</strong>' +
        (stage.pct != null ? '<span class="ra-funnel__pct">' + stage.pct + '</span>' : '') +
        drop + '</div>';
    }).join('') + '</div>';
  }

  function paintLineChart(rows, xKey, seriesKeys) {
    if (!rows || !rows.length) return '<p class="ra-chart-empty">' + CHART_EMPTY + '</p>';
    var keys = seriesKeys || [{ key: 'value', color: '#4CB4D4' }];
    var allVals = [];
    rows.forEach(function (row) {
      keys.forEach(function (s) { allVals.push(Number(row[s.key]) || 0); });
    });
    if (!allVals.some(function (v) { return v > 0; })) return '<p class="ra-chart-empty">' + CHART_EMPTY + '</p>';
    var max = Math.max.apply(null, allVals.concat([1]));
    var w = Math.max(rows.length - 1, 1);
    var paths = keys.map(function (s) {
      var points = rows.map(function (row, i) {
        var x = (i / w) * 100;
        var y = 100 - ((Number(row[s.key]) || 0) / max) * 100;
        return x.toFixed(2) + ',' + y.toFixed(2);
      }).join(' ');
      return '<polyline fill="none" stroke="' + s.color + '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="' + points + '" />';
    }).join('');
    return '<div class="ra-line-chart">' +
      '<svg viewBox="0 0 100 100" preserveAspectRatio="none" class="ra-line-chart__svg">' + paths + '</svg>' +
      '<div class="ra-line-chart__labels">' +
      rows.map(function (row, i) {
        if (rows.length > 12 && i % Math.ceil(rows.length / 6) !== 0 && i !== rows.length - 1) return '';
        var lbl = String(row[xKey] || '').slice(5);
        return '<span>' + escapeHtml(lbl || row[xKey] || '—') + '</span>';
      }).join('') + '</div></div>';
  }

  function paintMultiDonut(segments, centerLabel) {
    segments = (segments || []).filter(function (s) { return (Number(s.value) || 0) > 0; });
    if (!segments.length) return '<p class="ra-chart-empty">' + CHART_EMPTY + '</p>';
    var total = segments.reduce(function (a, s) { return a + (Number(s.value) || 0); }, 0) || 1;
    var cum = 0;
    var gradient = segments.map(function (seg) {
      var pct = ((Number(seg.value) || 0) / total) * 100;
      var start = cum;
      cum += pct;
      return (seg.color || '#4CB4D4') + ' ' + start + '% ' + cum + '%';
    }).join(', ');
    return '<div class="ra-lc-donut-block">' +
      '<div class="ra-lc-donut" style="background:conic-gradient(' + gradient + ')" aria-hidden="true">' +
        '<div class="ra-lc-donut__hole"><strong>' + fmtNum(total) + '</strong><span>' + escapeHtml(centerLabel || 'Total') + '</span></div>' +
      '</div>' +
      '<ul class="ra-lc-donut-legend">' + segments.map(function (seg) {
        var pct = Math.round(((Number(seg.value) || 0) / total) * 100);
        return '<li><span class="ra-lc-dot" style="background:' + (seg.color || '#4CB4D4') + '"></span>' +
          escapeHtml(seg.label) + ' <strong>' + fmtNum(seg.value) + '</strong> <em>(' + pct + '%)</em></li>';
      }).join('') + '</ul></div>';
  }

  function paintStackedBar(segments) {
    segments = (segments || []).filter(function (s) { return (Number(s.value) || 0) > 0; });
    var total = segments.reduce(function (a, s) { return a + (Number(s.value) || 0); }, 0);
    if (!total) return '<p class="ra-chart-empty">' + CHART_EMPTY + '</p>';
    return '<div class="ra-lc-stacked">' +
      '<div class="ra-lc-stacked__bar">' + segments.map(function (seg) {
        var w = Math.max(2, Math.round(((Number(seg.value) || 0) / total) * 100));
        return '<div class="ra-lc-stacked__seg" style="width:' + w + '%;background:' + (seg.color || '#4CB4D4') + '" title="' +
          escapeHtml(seg.label) + ': ' + fmtNum(seg.value) + '"></div>';
      }).join('') + '</div>' +
      '<ul class="ra-lc-stacked-legend">' + segments.map(function (seg) {
        return '<li><span class="ra-lc-dot" style="background:' + (seg.color || '#4CB4D4') + '"></span>' +
          escapeHtml(seg.label) + ' <strong>' + fmtNum(seg.value) + '</strong></li>';
      }).join('') + '</ul></div>';
  }

  function paintHeatmap(rows) {
    if (!rows || !rows.length) return '<p class="ra-chart-empty">' + CHART_EMPTY + '</p>';
    var metrics = ['new_leads', 'won_leads', 'demo_leads', 'conversion_rate_pct'];
    var labels = { new_leads: 'New', won_leads: 'Won', demo_leads: 'Demo', conversion_rate_pct: 'Conv %' };
    return '<div class="ra-heatmap"><table><thead><tr><th>Month</th>' +
      metrics.map(function (m) { return '<th>' + labels[m] + '</th>'; }).join('') +
      '</tr></thead><tbody>' +
      rows.map(function (row) {
        return '<tr><td>' + escapeHtml(row.month || '—') + '</td>' +
          metrics.map(function (m) {
            var val = row[m];
            var intensity = m === 'conversion_rate_pct' ? Math.min(100, Number(val) || 0) : Math.min(100, (Number(val) || 0) * 2);
            return '<td><span class="ra-heat-cell" style="--ra-intensity:' + intensity + '">' + (m === 'conversion_rate_pct' ? fmtPct(val) : fmtNum(val)) + '</span></td>';
          }).join('') + '</tr>';
      }).join('') + '</tbody></table></div>';
  }

  function chartSection(title, icon, bodyHtml, span) {
    var cls = 'ra-lc-chart' + (span === 2 ? ' ra-lc-chart--span2' : span === 'full' ? ' ra-lc-chart--full' : '');
    return '<section class="' + cls + '">' +
      '<header class="ra-lc-chart__head"><i data-lucide="' + icon + '" class="h-4 w-4"></i><h4>' + escapeHtml(title) + '</h4></header>' +
      '<div class="ra-lc-chart__body' + (title.indexOf('Funnel') >= 0 ? ' ra-lc-chart__body--funnel' : '') + '">' + bodyHtml + '</div></section>';
  }

  function buildKpiCard(card) {
    var t = card.trend || {};
    var trendHtml = t.text ? '<span class="ra-lc-kpi-card__trend ' + (t.cls || '') + '" title="' + escapeHtml(t.text) + '">' + escapeHtml(t.text) + '</span>' : '';
    var tip = card.tooltip ? ' title="' + escapeHtml(card.tooltip) + '"' : '';
    return '<article class="ra-lc-kpi-card ra-lc-kpi-card--' + (card.tone || 'blue') + '"' + tip + '>' +
      '<span class="ra-lc-kpi-card__icon"><i data-lucide="' + card.icon + '" class="h-4 w-4"></i></span>' +
      '<p class="ra-lc-kpi-card__value">' + escapeHtml(String(card.value)) + '</p>' +
      '<p class="ra-lc-kpi-card__label">' + escapeHtml(card.label) + '</p>' +
      trendHtml +
    '</article>';
  }

  function buildKpis(report) {
    var slug = report.slug || state.slug;
    var s = report.summary || {};
    var rows = report.rows || [];
    var breakdown = report.breakdown || [];
    var cards = [];

    if (slug === 'lead_conversion') {
      var contacted = (Number(s.hot_leads) || 0) + (Number(s.warm_leads) || 0) + (Number(s.pipeline_leads) || 0);
      cards = [
        { icon: 'users', tone: 'blue', label: 'Leads Created', value: fmtNum(s.total_leads), tooltip: 'Total leads created in the selected date range.', trend: compactTrend(rows.map(function (r) { return r.new_leads; })) },
        { icon: 'phone', tone: 'blue', label: 'Leads Contacted', value: fmtNum(contacted), tooltip: 'Hot, warm, and pipeline status leads.', trend: null },
        { icon: 'calendar-check', tone: 'blue', label: 'Demos Scheduled', value: fmtNum(s.demo_scheduled), tooltip: 'Leads with Demo Scheduled status.', trend: null },
        { icon: 'trophy', tone: 'green', label: 'Leads Converted', value: fmtNum(s.won_leads), tooltip: 'Leads marked as purchased (software_purchased).', trend: compactTrend(rows.map(function (r) { return r.converted_leads; })) },
        { icon: 'user-x', tone: 'gray', label: 'New / Unassigned', value: fmtNum(s.new_leads || breakdownCount(breakdown, 'New')), tooltip: 'Leads still in New status.', trend: null },
        { icon: 'x-circle', tone: 'red', label: 'Lost Leads', value: fmtNum(s.lost_leads), tooltip: 'Leads in Lost or Inactive status.', trend: null },
      ];
    } else if (slug === 'followup_performance') {
      var completionPct = Number(s.total_followups) ? fmtPct((Number(s.completed) / Number(s.total_followups)) * 100) : '—';
      cards = [
        { icon: 'presentation', tone: 'blue', label: 'Total Follow-ups', value: fmtNum(s.total_followups), tooltip: 'All follow-ups scheduled in range.' },
        { icon: 'check-circle', tone: 'green', label: 'Completed', value: fmtNum(s.completed), tooltip: 'Follow-ups marked completed.' },
        { icon: 'alert-circle', tone: 'red', label: 'Overdue', value: fmtNum(s.overdue), tooltip: 'Open follow-ups past scheduled date.' },
        { icon: 'calendar', tone: 'blue', label: 'Demo Related', value: fmtNum(s.demo_followups), tooltip: 'Follow-ups with Demo in the type.' },
        { icon: 'percent', tone: 'orange', label: 'Completion Rate', value: completionPct, tooltip: 'Completed ÷ total follow-ups × 100.' },
      ];
    } else if (slug === 'monthly_trends') {
      var lostTotal = sumRows(rows, 'lost_leads');
      var demoTotal = sumRows(rows, 'demo_leads');
      var convAvg = rows.length ? fmtPct(rows.reduce(function (a, r) { return a + (Number(r.conversion_rate_pct) || 0); }, 0) / rows.length) : '—';
      cards = [
        { icon: 'users', tone: 'blue', label: 'New Leads', value: fmtNum(s.total_new_leads), tooltip: 'Sum of monthly new leads.', trend: compactTrend(rows.map(function (r) { return r.new_leads; })) },
        { icon: 'presentation', tone: 'blue', label: 'Demos', value: fmtNum(demoTotal), tooltip: 'Demo Scheduled leads per month.' },
        { icon: 'trophy', tone: 'green', label: 'Converted', value: fmtNum(s.total_won_leads), tooltip: 'Purchased leads per month (won_leads).' },
        { icon: 'x-circle', tone: 'red', label: 'Lost', value: fmtNum(lostTotal), tooltip: 'Lost/Inactive leads per month.' },
        { icon: 'percent', tone: 'orange', label: 'Avg Conversion', value: convAvg, tooltip: 'Average monthly conversion rate (won ÷ new × 100).' },
        { icon: 'indian-rupee', tone: 'gray', label: 'Revenue', value: 'N/A', tooltip: 'Revenue is not stored in this CRM.' },
      ];
    } else if (slug === 'city_analysis') {
      var top = rows[0];
      var bestConv = rows.length ? rows.slice().sort(function (a, b) { return (b.conversion_rate_pct || 0) - (a.conversion_rate_pct || 0); })[0] : null;
      cards = [
        { icon: 'map-pin', tone: 'blue', label: 'Total Cities', value: fmtNum(s.cities), tooltip: 'Cities with at least one lead.' },
        { icon: 'building-2', tone: 'blue', label: 'Top City', value: top ? top.city : '—', tooltip: 'City with the most leads.' },
        { icon: 'users', tone: 'blue', label: 'Leads in Top City', value: top ? fmtNum(top.total_leads) : '—', tooltip: 'Lead count in the top city.' },
        { icon: 'percent', tone: 'green', label: 'Best Conversion', value: bestConv ? fmtPct(bestConv.conversion_rate_pct) : '—', tooltip: 'Highest city conversion (won ÷ total × 100).' },
        { icon: 'users', tone: 'blue', label: 'Total Leads', value: fmtNum(s.total_leads), tooltip: 'All leads across listed cities.' },
      ];
    } else if (slug === 'employee_performance') {
      var topEmp = rows[0];
      cards = [
        { icon: 'users', tone: 'blue', label: 'Employees', value: fmtNum(s.active_employees), tooltip: 'Active employees in report.' },
        { icon: 'trophy', tone: 'green', label: 'Top Performer', value: topEmp ? topEmp.employee_name : '—', tooltip: 'Highest achieved leads.' },
        { icon: 'target', tone: 'blue', label: 'Total Assigned', value: fmtNum(s.total_assigned_leads), tooltip: 'Active assignment count.' },
        { icon: 'check-circle', tone: 'green', label: 'Total Achieved', value: fmtNum(sumRows(rows, 'achieved_leads')), tooltip: 'Sum of achieved leads vs target.' },
        { icon: 'percent', tone: 'orange', label: 'Avg Conversion', value: fmtPct(s.avg_achievement_pct), tooltip: 'Average achievement % (achieved ÷ target × 100).' },
        { icon: 'alert-circle', tone: 'red', label: 'Overdue Follow-ups', value: fmtNum(s.total_overdue_followups), tooltip: 'Overdue open follow-ups team-wide.' },
      ];
    } else if (slug === 'duplicate_productivity') {
      cards = [
        { icon: 'copy', tone: 'blue', label: 'Unique Leads', value: fmtNum(s.total_unique_leads), tooltip: 'Distinct leads worked by the team.' },
        { icon: 'shield-alert', tone: 'red', label: 'Duplicate Attempts', value: fmtNum(s.total_duplicate_attempts), tooltip: 'Duplicate entry attempts logged.' },
        { icon: 'trophy', tone: 'green', label: 'Top Collector', value: s.top_collector || '—', tooltip: 'Employee with most unique leads.' },
        { icon: 'star', tone: 'green', label: 'Top Quality', value: s.top_quality_score || '—', tooltip: 'Highest quality score employee.' },
        { icon: 'alert-triangle', tone: 'orange', label: 'Least Accurate', value: s.least_accurate || '—', tooltip: 'Employee with lowest accuracy signals.' },
      ];
    } else if (slug === 'lost_lead_analysis') {
      cards = [
        { icon: 'x-circle', tone: 'red', label: 'Lost Leads', value: fmtNum(s.lost_leads), tooltip: 'Total lost/inactive leads in range.' },
        { icon: 'list', tone: 'blue', label: 'Listed Rows', value: fmtNum(s.listed_rows), tooltip: 'Detail rows shown (max 500).' },
      ];
    } else if (slug === 'assignment_statistics') {
      cards = [
        { icon: 'users', tone: 'blue', label: 'Active Assignments', value: fmtNum(s.active_assignments), tooltip: 'Currently active lead assignments.' },
        { icon: 'git-branch', tone: 'blue', label: 'Total Assignments', value: fmtNum(s.total_assignments), tooltip: 'Assignments in selected period.' },
        { icon: 'repeat', tone: 'purple', label: 'Reassignments', value: fmtNum(s.reassignments), tooltip: 'Assignments with a previous owner.' },
        { icon: 'layers', tone: 'blue', label: 'Assignment Types', value: fmtNum(s.assignment_types), tooltip: 'Distinct assignment type buckets.' },
      ];
    } else if (slug === 'campaign_analytics') {
      cards = [
        { icon: 'megaphone', tone: 'blue', label: 'Campaigns', value: fmtNum(s.campaigns_total), tooltip: 'WhatsApp, email, and SMS campaigns.' },
        { icon: 'send', tone: 'blue', label: 'Messages Sent', value: fmtNum(s.messages_total), tooltip: 'Total outbound messages.' },
        { icon: 'check-circle', tone: 'green', label: 'Delivered', value: fmtNum(s.delivered_total), tooltip: 'Successfully delivered messages.' },
        { icon: 'x-circle', tone: 'red', label: 'Failed', value: fmtNum(s.failed_total), tooltip: 'Failed delivery attempts.' },
        { icon: 'percent', tone: 'orange', label: 'Delivery Rate', value: fmtPct(s.delivery_rate_pct), tooltip: 'Delivered ÷ sent × 100.' },
      ];
    } else {
      Object.keys(s).slice(0, 6).forEach(function (key) {
        var val = s[key];
        cards.push({
          icon: 'bar-chart-2',
          tone: 'blue',
          label: fmtLabel(key),
          value: typeof val === 'number' ? fmtNum(val) : String(val == null ? '—' : val),
        });
      });
    }

    return '<div class="ra-lc-kpi-grid">' + cards.map(buildKpiCard).join('') + '</div>';
  }

  function buildCharts(report) {
    var slug = report.slug || state.slug;
    var s = report.summary || {};
    var rows = report.rows || [];
    var breakdown = report.breakdown || [];
    var html = '<div class="ra-lc-charts">';

    if (slug === 'lead_conversion') {
      html += chartSection('Daily Lead Growth', 'line-chart', paintLineChart(rows, 'report_date', [
        { key: 'new_leads', color: '#3b82f6' },
        { key: 'converted_leads', color: '#10b981' },
      ]), 2);
      html += chartSection('Status Distribution', 'pie-chart', paintMultiDonut(
        breakdown.map(function (b, i) {
          var colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b'];
          return { label: b.status, value: b.lead_count, color: colors[i % colors.length] };
        }), 'Leads'
      ));
      html += chartSection('Overall Conversion', 'donut', paintDonut(s.conversion_rate_pct, 'Won ÷ Total'));
      var total = Number(s.total_leads) || 1;
      html += chartSection('Conversion Funnel', 'filter', paintFunnel([
        { label: 'Total Leads', value: s.total_leads, pct: '100%' },
        { label: 'Hot & Warm', value: (Number(s.hot_leads) || 0) + (Number(s.warm_leads) || 0), pct: fmtPct((((Number(s.hot_leads) || 0) + (Number(s.warm_leads) || 0)) / total) * 100) },
        { label: 'Demo Scheduled', value: s.demo_scheduled, pct: fmtPct((Number(s.demo_scheduled) / total) * 100) },
        { label: 'Pipeline', value: s.pipeline_leads, pct: fmtPct((Number(s.pipeline_leads) / total) * 100) },
        { label: 'Converted', value: s.won_leads, pct: fmtPct((Number(s.won_leads) / total) * 100) },
      ]), 'full');
    } else if (slug === 'followup_performance') {
      html += chartSection('Follow-up Volume', 'phone', paintColumnChart(rows.map(function (r) {
        return { label: r.followup_type, value: r.total_followups };
      })));
      html += chartSection('Completion Rate', 'check-circle', paintHorizontalBars(rows, 'followup_type', 'completion_rate_pct', 10));
      html += chartSection('Demo Related', 'presentation', paintHorizontalBars(rows, 'followup_type', 'demo_related', 10));
      html += chartSection('Overdue by Type', 'alert-circle', paintHorizontalBars(rows, 'followup_type', 'overdue', 10));
    } else if (slug === 'monthly_trends') {
      html += chartSection('Monthly Lead Trend', 'bar-chart-3', paintColumnChart(rows.map(function (r) { return { label: r.month, value: r.new_leads }; })), 2);
      html += chartSection('Conversion Trend', 'line-chart', paintLineChart(rows, 'month', [{ key: 'conversion_rate_pct', color: '#8b5cf6' }]));
      html += chartSection('Demo Trend', 'presentation', paintColumnChart(rows.map(function (r) { return { label: r.month, value: r.demo_leads }; }), { color: '#f59e0b' }));
      html += chartSection('Won Leads', 'trophy', paintColumnChart(rows.map(function (r) { return { label: r.month, value: r.won_leads }; }), { color: '#10b981' }));
      html += chartSection('Lost Leads', 'trending-down', paintColumnChart(rows.map(function (r) { return { label: r.month, value: r.lost_leads }; }), { color: '#ef4444' }));
      html += chartSection('Month Comparison', 'grid-3x3', paintHeatmap(rows), 'full');
    } else if (slug === 'employee_performance') {
      html += chartSection('Target Achievement', 'trophy', paintHorizontalBars(rows, 'employee_name', 'achievement_pct', 8), 2);
      html += chartSection('Assigned Leads', 'users', paintHorizontalBars(rows, 'employee_name', 'assigned_leads', 8));
      html += chartSection('Demo Follow-ups', 'presentation', paintHorizontalBars(rows, 'employee_name', 'demo_followups', 8));
      html += chartSection('Overdue Items', 'alert-circle', paintHorizontalBars(rows, 'employee_name', 'overdue_followups', 8));
    } else if (slug === 'city_analysis') {
      html += chartSection('Top Cities', 'map-pin', paintHorizontalBars(rows, 'city', 'total_leads', 10), 2);
      html += chartSection('Conversion by City', 'percent', paintHorizontalBars(rows.slice().sort(function (a, b) {
        return (b.conversion_rate_pct || 0) - (a.conversion_rate_pct || 0);
      }), 'city', 'conversion_rate_pct', 10));
      html += chartSection('Status Mix (Hot)', 'flame', paintHorizontalBars(rows, 'city', 'hot_leads', 8));
      html += chartSection('Lost by City', 'trending-down', paintHorizontalBars(rows, 'city', 'lost_leads', 8));
    } else if (slug === 'lost_lead_analysis') {
      html += chartSection('Lost by City', 'map-pin', paintHorizontalBars(
        aggregateRows(rows, 'city').slice(0, 10), 'label', 'count', 10
      ), 2);
      html += chartSection('Lost by Source', 'megaphone', paintHorizontalBars(
        aggregateRows(rows, 'source').slice(0, 10), 'label', 'count', 10
      ));
      html += chartSection('Lost by Employee', 'user', paintHorizontalBars(
        aggregateRows(rows, 'executive').slice(0, 10), 'label', 'count', 10
      ));
    } else if (slug === 'duplicate_productivity') {
      html += chartSection('Duplicate Attempts', 'shield-alert', paintHorizontalBars(rows, 'employee_name', 'duplicate_attempts', 10), 2);
      html += chartSection('Unique Leads', 'users', paintHorizontalBars(rows, 'employee_name', 'unique_leads', 10));
      html += chartSection('Quality Score', 'star', paintHorizontalBars(rows, 'employee_name', 'quality_score', 10));
      html += chartSection('Follow-up Completion', 'check-circle', paintHorizontalBars(rows, 'employee_name', 'followup_completion_pct', 10));
    } else if (slug === 'assignment_statistics') {
      var typeRows = report.breakdown || [];
      html += chartSection('Daily Assignments', 'calendar', paintLineChart(rows, 'report_date', [{ key: 'assignments', color: '#3b82f6' }]), 2);
      html += chartSection('By Assignment Type', 'layers', paintColumnChart(typeRows.map(function (r) {
        return { label: r.assignment_type, value: r.assignment_count };
      })));
    } else if (slug === 'campaign_analytics') {
      var channels = report.breakdown || [];
      html += chartSection('Channel Delivery', 'send', paintHorizontalBars(channels, 'channel', 'delivered', 5), 2);
      html += chartSection('Failed Messages', 'x-circle', paintHorizontalBars(channels, 'channel', 'failed', 5));
      html += chartSection('Delivery Rate', 'percent', paintHorizontalBars(channels, 'channel', 'delivery_rate_pct', 5));
    } else if (rows.length) {
      html += chartSection('Report Overview', 'bar-chart-3', paintColumnChart(rows.slice(0, 12).map(function (r, i) {
        var keys = Object.keys(r);
        var numKey = keys.find(function (k) { return typeof r[k] === 'number'; }) || keys[0];
        var labelKey = keys.find(function (k) { return typeof r[k] === 'string'; }) || ('Row ' + (i + 1));
        return { label: r[labelKey] || ('Row ' + (i + 1)), value: Number(r[numKey]) || 0 };
      })), 'full');
    }

    html += '</div>';
    return html;
  }

  function aggregateRows(rows, key) {
    var map = {};
    (rows || []).forEach(function (row) {
      var label = row[key] || '—';
      map[label] = (map[label] || 0) + 1;
    });
    return Object.keys(map).map(function (label) {
      return { label: label, count: map[label] };
    }).sort(function (a, b) { return b.count - a.count; });
  }

  function filterRows(rows, columns) {
    rows = rows.slice();
    var q = (state.tableSearch || '').toLowerCase().trim();
    if (q) {
      rows = rows.filter(function (row) {
        return Object.keys(columns).some(function (key) {
          return String(row[key] == null ? '' : row[key]).toLowerCase().indexOf(q) >= 0;
        });
      });
    }
    if (state.drillDown && state.drillDown.key) {
      var dk = state.drillDown.key;
      var dv = state.drillDown.value;
      rows = rows.filter(function (row) {
        return String(row[dk] || '') === String(dv);
      });
    }
    if (state.sortKey) {
      var key = state.sortKey;
      var dir = state.sortDir === 'asc' ? 1 : -1;
      rows.sort(function (a, b) {
        var av = a[key];
        var bv = b[key];
        if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * dir;
        return String(av || '').localeCompare(String(bv || '')) * dir;
      });
    }
    return rows;
  }

  function buildTable(report) {
    var columns = report.columns || {};
    var keys = Object.keys(columns);
    if (!keys.length) return '';
    var allRows = report.rows || [];
    var rows = filterRows(allRows, columns);
    var slug = report.slug || state.slug;

    var thead = keys.map(function (key) {
      var sorted = state.sortKey === key ? (state.sortDir === 'asc' ? ' ▲' : ' ▼') : '';
      return '<th scope="col" data-ra-sort="' + escapeHtml(key) + '">' + escapeHtml(columns[key]) + sorted + '</th>';
    }).join('');

    var tbody = rows.length ? rows.map(function (row, idx) {
      var tds = keys.map(function (key) {
        var val = row[key];
        if (key === 'achievement_pct' || key === 'conversion_rate_pct' || key === 'completion_rate_pct' || key === 'followup_completion_pct' || key === 'communication_success_pct') {
          var pct = Math.min(100, Math.max(0, Number(val) || 0));
          return '<td><div class="ra-progress-cell"><span>' + fmtPct(val) + '</span><div class="ra-progress-track"><div class="ra-progress-fill" style="width:' + pct + '%"></div></div></div></td>';
        }
        if ((slug === 'employee_performance' || slug === 'duplicate_productivity') && key === 'employee_name') {
          return '<td><span class="ra-rank">' + (idx + 1) + '</span> ' + escapeHtml(val == null ? '—' : String(val)) + '</td>';
        }
        if (key === 'rank') {
          return '<td><span class="ra-rank">' + escapeHtml(val == null ? String(idx + 1) : String(val)) + '</span></td>';
        }
        return '<td>' + escapeHtml(val == null ? '—' : String(val)) + '</td>';
      }).join('');
      return '<tr>' + tds + '</tr>';
    }).join('') : '<tr><td colspan="' + keys.length + '" class="ra-table-empty">' +
      (allRows.length ? 'No rows match your search or drill-down.' : CHART_EMPTY) + '</td></tr>';

    var drillHtml = '';
    if (state.drillDown && state.drillDown.label) {
      drillHtml = '<div class="ra-drill-chip">' +
        '<span>Filtered: ' + escapeHtml(state.drillDown.label) + '</span>' +
        '<button type="button" class="crm-toolbar-icon-btn" id="ra-drill-clear" title="Clear drill-down" aria-label="Clear drill-down"><i data-lucide="x" class="h-3.5 w-3.5"></i></button>' +
      '</div>';
    }

    return '<section class="ra-lc-table-section">' +
      '<header class="ra-lc-table-section__head">' +
        '<div><i data-lucide="table" class="h-4 w-4"></i><h4>Detailed Data</h4></div>' +
        drillHtml +
        '<label class="ra-table-search"><i data-lucide="search" class="h-4 w-4"></i>' +
          '<input type="search" id="ra-table-search" placeholder="Search table…" value="' + escapeHtml(state.tableSearch) + '" aria-label="Search report table" /></label>' +
      '</header>' +
      '<p class="ra-table-meta">' + fmtNum(rows.length) + ' of ' + fmtNum(allRows.length) + ' rows · click column headers to sort</p>' +
      '<div class="ra-table-wrap scrollbar-thin"><table class="ra-table ra-lc-table"><thead><tr>' + thead + '</tr></thead><tbody>' + tbody + '</tbody></table></div>' +
    '</section>';
  }

  function buildFilterBar(slug) {
    var toolbar = window.CrmReportFilterToolbar;
    if (!toolbar) {
      /* Fallback if shared toolbar script failed to load — preserves date/employee/status/search filters. */
      var filters = FILTER_PRESETS[slug] || ['date', 'employee', 'search'];
      var parts = [];
      if (filters.indexOf('date') >= 0) {
        parts.push('<label class="ra-lc-filter" title="Date From"><i data-lucide="calendar" class="h-3.5 w-3.5"></i><input type="text" id="ra-filter-from" class="input-field input-field-sm" data-crm-date-input data-allow-past data-hide-preview data-optional aria-label="Date From" /></label>');
        parts.push('<label class="ra-lc-filter" title="Date To"><i data-lucide="calendar-range" class="h-3.5 w-3.5"></i><input type="text" id="ra-filter-to" class="input-field input-field-sm" data-crm-date-input data-allow-past data-hide-preview data-optional aria-label="Date To" /></label>');
      }
      if (filters.indexOf('employee') >= 0) {
        parts.push('<label class="ra-lc-filter ra-lc-filter--grow" title="Employee"><i data-lucide="user" class="h-3.5 w-3.5"></i><select id="ra-filter-employee" class="input-field input-field-sm" aria-label="Employee"><option value="">All employees</option></select></label>');
      }
      if (filters.indexOf('status') >= 0) {
        parts.push('<label class="ra-lc-filter" title="Status"><i data-lucide="filter" class="h-3.5 w-3.5"></i><select id="ra-filter-status" class="input-field input-field-sm" aria-label="Status"><option value="">All statuses</option><option>Hot</option><option>Warm</option><option>New</option><option>Demo Scheduled</option><option>Lost</option></select></label>');
      }
      if (filters.indexOf('search') >= 0) {
        parts.push('<label class="ra-lc-filter ra-lc-filter--grow" title="Search table"><i data-lucide="search" class="h-3.5 w-3.5"></i><input type="search" id="ra-filter-source" class="input-field input-field-sm" placeholder="Search table…" aria-label="Search table" /></label>');
      }
      parts.push('<button type="button" class="btn-primary btn-sm ra-lc-apply" id="ra-filter-apply">Apply</button>');
      parts.push('<button type="button" class="crm-toolbar-icon-btn" id="ra-filter-reset" title="Reset filters" aria-label="Reset filters"><i data-lucide="rotate-ccw" class="h-4 w-4"></i></button>');
      return '<div class="ra-lc-toolbar"><div class="ra-lc-filters">' + parts.join('') + '</div></div>';
    }
    var enabled = FILTER_PRESETS[slug] || ['date', 'employee', 'search'];
    return toolbar.build({
      wrapperClass: 'crm-report-filter-toolbar card ra-lc-toolbar',
      errorId: 'ra-filter-date-error',
      applyId: 'ra-filter-apply',
      resetId: 'ra-filter-reset',
      fields: toolbar.buildFieldsFromPreset(enabled, DRAWER_FIELD_DEFS),
    });
  }

  function buildNotice(slug) {
    var meta = SLUG_META[slug] || {};
    if (!meta.notice) return '';
    return '<div class="ra-notice" role="status"><i data-lucide="info" class="h-4 w-4"></i><span>' + escapeHtml(meta.notice) + '</span></div>';
  }

  function renderReportBody(report) {
    var noticeHost = document.querySelector('.ra-lc-main');
    var existingNotice = noticeHost ? noticeHost.querySelector('.ra-notice') : null;
    var noticeHtml = buildNotice(report.slug || state.slug);
    if (noticeHost) {
      if (existingNotice) existingNotice.remove();
      if (noticeHtml) noticeHost.insertAdjacentHTML('afterbegin', noticeHtml);
    }
    var titleEl = $('ra-title');
    var meta = SLUG_META[report.slug || state.slug] || {};
    if (titleEl) titleEl.textContent = report.label || meta.title || fmtLabel(report.slug || state.slug);
    var kpis = $('ra-kpis');
    var charts = $('ra-charts');
    var table = $('ra-table');
    if (kpis) kpis.innerHTML = buildKpis(report);
    if (charts) charts.innerHTML = buildCharts(report);
    if (table) table.innerHTML = buildTable(report);
    iconsIn($('ra-root'));
  }

  function renderSoftLoading() {
    var kpis = $('ra-kpis');
    var charts = $('ra-charts');
    var table = $('ra-table');
    if (kpis) {
      kpis.innerHTML = '<div class="ra-skeleton__kpis">' + Array(6).join('<div class="ra-skeleton__kpi"></div>') + '</div>';
    }
    if (charts) {
      charts.innerHTML = '<div class="ra-skeleton__charts">' + Array(3).join('<div class="ra-skeleton__chart"></div>') + '</div>';
    }
    if (table) {
      table.innerHTML = '<div class="ra-skeleton__table"></div>';
    }
  }

  function canSoftRefresh() {
    var page = document.getElementById('page-container');
    var root = $('ra-root');
    return !!(
      page &&
      root &&
      page.contains(root) &&
      $('ra-filter-apply') &&
      ($('ra-kpis') || $('ra-charts') || $('ra-table'))
    );
  }

  function renderUnifiedShell(report) {
    var slug = report.slug || state.slug;
    var meta = SLUG_META[slug] || {};
    var title = report.label || meta.title || fmtLabel(slug);
    var subtitle = report.description || meta.description || '';
    var root = ensurePageRoot();
    if (!root) return;

    var actionsHtml = window.CrmReportShell
      ? (window.CrmReportShell.buildPageActions || window.CrmReportShell.buildDrawerActions)()
      : '';

    root.innerHTML =
      (window.CrmReportShell
        ? window.CrmReportShell.buildHeader({
          title: title,
          subtitle: subtitle,
          icon: meta.icon || 'file-text',
          titleId: 'ra-title',
          backId: 'ra-back',
          backNav: 'reports',
          showBack: true,
          actionsHtml: actionsHtml,
        })
        : '') +
      buildFilterBar(slug) +
      '<div class="crm-report-page__body">' +
        '<main class="ra-lc-main">' +
          buildNotice(slug) +
          '<div id="ra-kpis">' + buildKpis(report) + '</div>' +
          '<div id="ra-charts">' + buildCharts(report) + '</div>' +
          '<div id="ra-table">' + buildTable(report) + '</div>' +
        '</main>' +
      '</div>';

    seedFilters();
    if (window.CrmReportShell) {
      window.CrmReportShell.init(root);
    } else if (window.CrmReportFilterToolbar) {
      window.CrmReportFilterToolbar.initToolbar(root);
    }
    /* Re-apply after date-picker enhancement so display triggers keep applied values. */
    applyFiltersToDom(state.filters);
    loadEmployeeOptions();
    if (window.CrmDateTimePicker) window.CrmDateTimePicker.initAll(root);
    applyFiltersToDom(state.filters);
    iconsIn(root);
  }

  function renderShell(report) {
    renderUnifiedShell(report);
  }

  function seedFilters() {
    ensureFilterState(state.slug);
    applyFiltersToDom(state.filters);
  }

  function loadEmployeeOptions() {
    var select = $('ra-filter-employee');
    if (!select) return;
    var current = (state.filters && state.filters.employee) || select.value || '';
    apiFetch('/employees?per_page=200&status=Active&sort_by=name&sort_dir=asc')
      .then(function (body) {
        var items = (body.data && body.data.items) || body.data || [];
        if (!Array.isArray(items) && body.data && body.data.data) items = body.data.data;
        if (!Array.isArray(items)) items = [];
        var html = '<option value="">All employees</option>';
        items.forEach(function (emp) {
          var id = emp.employee_id || emp.id;
          var name = emp.name || emp.employee_name || ('Employee ' + id);
          if (!id) return;
          html += '<option value="' + escapeHtml(String(id)) + '">' + escapeHtml(name) + '</option>';
        });
        select.innerHTML = html;
        var desired = (state.filters && state.filters.employee) || current;
        if (desired) select.value = desired;
        if (state.filters) state.filters.employee = select.value || '';
      })
      .catch(function () { /* optional */ });
  }

  function renderLoading() {
    var root = ensurePageRoot();
    if (!root) return;
    root.innerHTML =
      '<div class="ra-skeleton">' +
        '<div class="ra-skeleton__header"></div>' +
        '<div class="ra-skeleton__filters"></div>' +
        '<div class="ra-skeleton__kpis">' + Array(6).join('<div class="ra-skeleton__kpi"></div>') + '</div>' +
        '<div class="ra-skeleton__charts">' + Array(4).join('<div class="ra-skeleton__chart"></div>') + '</div>' +
        '<div class="ra-skeleton__table"></div>' +
      '</div>';
  }

  function renderError(message) {
    var root = ensurePageRoot();
    if (!root) return;
    root.innerHTML =
      '<div class="ra-error-state">' +
        '<i data-lucide="alert-circle" class="h-10 w-10"></i>' +
        '<h3>Unable to load report</h3>' +
        '<p>' + escapeHtml(message || 'Something went wrong.') + '</p>' +
        '<button type="button" class="btn-primary btn-sm" id="ra-error-retry">Retry</button>' +
        '<button type="button" class="btn-secondary btn-sm" id="ra-back">Back to Reports</button>' +
      '</div>';
    iconsIn(root);
  }

  var RESERVED_REPORT_SEGMENTS = { analytics: true, export: true };

  function normalizePathname(path) {
    return String(path || '/').replace(/\/+$/, '') || '/';
  }

  function reportPathForSlug(slug) {
    return '/reports/' + encodeURIComponent(slug || '');
  }

  /** Ensure report HTML mounts inside #page-container — never the fullscreen overlay. */
  function ensurePageRoot() {
    var page = document.getElementById('page-container');
    if (!page) return null;
    var root = document.getElementById('ra-root');
    if (root && page.contains(root)) return root;
    page.innerHTML = '<div class="crm-report-page" id="ra-root" data-report-in-shell="1"></div>';
    return document.getElementById('ra-root');
  }

  function markReportsNavActive() {
    document.querySelectorAll('[data-page]').forEach(function (link) {
      link.classList.toggle('active', link.dataset.page === 'reports');
    });
    window.__CRM_CURRENT_PAGE__ = 'reports';
    window._currentPageId = 'reports';
  }

  function syncReportLocation(slug) {
    if (!slug) return;
    var path = reportPathForSlug(slug);
    if (normalizePathname(location.pathname) !== path) {
      history.pushState({ pageId: 'reports', reportSlug: slug }, '', path);
    }
    markReportsNavActive();
    document.title = ((SLUG_META[slug] && SLUG_META[slug].title) || fmtLabel(slug)) + ' — CA Cloud Desk';
  }

  function mountReportInShell() {
    document.body.classList.remove('ra-drawer-open');
    ensurePageRoot();
    syncReportLocation(state.slug);
  }

  /** Leave the report detail and restore the Reports hub inside the CRM shell. */
  function closeReport() {
    state.slug = null;
    state.report = null;
    state.drillDown = null;
    state.loading = false;
    var exportList = $('ra-export-list');
    if (exportList) exportList.classList.add('hidden');
    document.body.classList.remove('ra-drawer-open');
    if (typeof navigateTo === 'function') {
      navigateTo('reports');
      return;
    }
    history.pushState({ pageId: 'reports' }, '', '/reports');
    if (window.CAPages && document.getElementById('page-container')) {
      document.getElementById('page-container').innerHTML = (window.CAPages.get('reports') || {}).html || '';
    }
  }

  function loadReport(slug, opts) {
    opts = opts || {};
    if (state.loading && !opts.force) return Promise.resolve();
    var previousSlug = state.slug;
    var isRefresh = slug === previousSlug;
    if (!isRefresh) {
      /* New report page — load that report's saved filters (or defaults), do not keep prior report values. */
      state.filters = null;
    }
    state.slug = slug;
    if (!isRefresh || !opts.preserveSearch) {
      if (!opts.preserveDrill) state.drillDown = null;
      if (!opts.preserveSearch) state.tableSearch = '';
      state.sortKey = null;
      state.sortDir = 'desc';
    }
    if (isRefresh && document.getElementById('ra-filter-from') && document.getElementById('page-container')
        && document.getElementById('page-container').contains(document.getElementById('ra-filter-from'))) {
      captureFiltersFromDom();
    } else {
      ensureFilterState(slug);
    }
    if (state.filters && state.filters.search && (isRefresh || opts.preserveSearch)) {
      state.tableSearch = state.filters.search;
    }
    state.loading = true;
    mountReportInShell();

    var soft = !!opts.soft || (isRefresh && canSoftRefresh());
    if (soft) {
      renderSoftLoading();
    } else {
      renderLoading();
    }

    var query = getFilterQuery();
    return apiFetch('/reports/' + encodeURIComponent(slug) + query)
      .then(function (body) {
        state.report = body.data || {};
        state.loading = false;
        if (soft && canSoftRefresh()) {
          renderReportBody(state.report);
          applyFiltersToDom(state.filters);
          loadEmployeeOptions();
        } else {
          renderShell(state.report);
        }
        persistFilters(state.slug, state.filters);
        syncReportLocation(state.slug);
      })
      .catch(function (err) {
        state.loading = false;
        renderError(err.message || 'Unable to load report');
        toast(err.message || 'Unable to load report', 'error');
      });
  }

  function refreshReport() {
    if (!state.slug) return;
    loadReport(state.slug, { preserveSearch: true, preserveDrill: true, soft: true });
  }

  function applyFilters() {
    if (state.loading) return;
    if (!validateDateRange()) return;
    hideDateRangeError();
    syncHubFilters();
    captureFiltersFromDom();
    var status = state.filters.status || '';
    state.tableSearch = state.filters.search || '';
    if (status) state.tableSearch = (status + ' ' + state.tableSearch).trim();
    state.drillDown = null;
    persistFilters(state.slug, state.filters);
    refreshReport();
  }

  function resetFilters() {
    state.tableSearch = '';
    state.sortKey = null;
    state.sortDir = 'desc';
    state.drillDown = null;
    clearPersistedFilters(state.slug);
    state.filters = emptyFilterState(true);
    if (canSoftRefresh()) {
      applyFiltersToDom(state.filters);
    }
    syncHubFilters();
    persistFilters(state.slug, state.filters);
    refreshReport();
  }

  function toggleExportMenu() {
    $('ra-export-list')?.classList.toggle('hidden');
  }

  function handleTableSearch(value) {
    state.tableSearch = value || '';
    var tableEl = $('ra-table');
    if (tableEl && state.report) tableEl.innerHTML = buildTable(state.report);
    iconsIn(tableEl);
  }

  function handleTableSort(key) {
    if (state.sortKey === key) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
    else { state.sortKey = key; state.sortDir = 'desc'; }
    var tableEl = $('ra-table');
    if (tableEl && state.report) tableEl.innerHTML = buildTable(state.report);
    iconsIn(tableEl);
  }

  function isReportShellEvent(target) {
    if (!target || !target.closest) return false;
    return !!(target.closest('#ra-root') || target.closest('[data-report-in-shell]'));
  }

  function bindGlobal() {
    if (state.bound) return;
    state.bound = true;

    document.addEventListener('click', function (e) {
      if (!isReportShellEvent(e.target)) return;

      if (e.target.closest('#ra-back, #ra-close, [data-ra-close]')) {
        e.preventDefault();
        closeReport();
        return;
      }
      if (e.target.closest('#ra-error-retry')) {
        if (state.slug) loadReport(state.slug);
        return;
      }
      if (e.target.closest('#ra-refresh')) {
        refreshReport();
        return;
      }
      if (e.target.closest('#ra-print')) {
        window.print();
        return;
      }
      if (e.target.closest('#ra-share')) {
        var url = window.location.origin + reportPathForSlug(state.slug || '');
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(function () { toast('Report link copied.', 'success'); });
        } else {
          toast('Share this report view with your team.', 'info');
        }
        return;
      }
      if (e.target.closest('#ra-export-toggle')) {
        toggleExportMenu();
        return;
      }
      var exportBtn = e.target.closest('[data-ra-export]');
      if (exportBtn) {
        var fmt = exportBtn.getAttribute('data-ra-export');
        $('ra-export-list')?.classList.add('hidden');
        if (fmt === 'print') {
          window.print();
          return;
        }
        if (window.CA_CRM && typeof window.CA_CRM.exportReport === 'function') {
          window.CA_CRM.exportReport(state.slug, fmt === 'pdf' ? 'pdf' : 'csv');
        }
        return;
      }
      if (e.target.closest('#ra-filter-apply')) {
        applyFilters();
        return;
      }
      if (e.target.closest('#ra-filter-reset')) {
        resetFilters();
        return;
      }
      if (e.target.closest('#ra-drill-clear')) {
        state.drillDown = null;
        var tableEl = $('ra-table');
        if (tableEl && state.report) tableEl.innerHTML = buildTable(state.report);
        iconsIn(tableEl);
        return;
      }
      var th = e.target.closest('[data-ra-sort]');
      if (th) {
        handleTableSort(th.getAttribute('data-ra-sort'));
      }
    });

    document.addEventListener('input', function (e) {
      if (!isReportShellEvent(e.target)) return;
      if (e.target && e.target.id === 'ra-table-search') {
        handleTableSearch(e.target.value);
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (!state.slug || !document.getElementById('ra-root')) return;
      if (!document.getElementById('page-container') || !document.getElementById('page-container').contains(document.getElementById('ra-root'))) return;
      closeReport();
    });
  }

  bindGlobal();

  return {
    open: loadReport,
    close: closeReport,
    refresh: refreshReport,
    getFilterQuery: getFilterQuery,
    parseSlugFromPath: function (path) {
      path = normalizePathname(path);
      var match = path.match(/^\/reports\/([a-z0-9_-]+)$/i);
      if (!match) return null;
      var slug = match[1];
      if (RESERVED_REPORT_SEGMENTS[slug]) return null;
      return slug;
    },
  };
})();
