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
  };

  var SLUG_META = {
    lead_conversion: { title: 'Lead Conversion Report', icon: 'git-merge' },
    followup_performance: { title: 'Weekly Demo Report', icon: 'presentation' },
    monthly_trends: { title: 'Monthly Trend Report', icon: 'line-chart' },
    city_analysis: { title: 'City Analysis Report', icon: 'map-pin' },
    employee_performance: { title: 'Employee Performance Report', icon: 'trophy' },
    lost_lead_analysis: { title: 'Lost Lead Analysis', icon: 'trending-down' },
    duplicate_productivity: { title: 'Duplicate Productivity Report', icon: 'copy' },
    assignment_statistics: { title: 'Assignment Statistics', icon: 'users' },
    campaign_analytics: { title: 'Campaign Analytics', icon: 'megaphone' },
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
    if (values.length < 4) return { text: 'Live', cls: 'ra-trend--neutral', icon: 'minus' };
    var mid = Math.floor(values.length / 2);
    var first = values.slice(0, mid);
    var second = values.slice(mid);
    var avg = function (arr) { return arr.reduce(function (a, b) { return a + b; }, 0) / (arr.length || 1); };
    var a = avg(first);
    var b = avg(second);
    if (!a && !b) return { text: 'Live', cls: 'ra-trend--neutral', icon: 'minus' };
    if (!a) return { text: '▲ New', cls: 'ra-trend--up', icon: 'trending-up' };
    var pct = Math.round(((b - a) / a) * 100);
    if (pct > 0) return { text: '▲ +' + pct + '%', cls: 'ra-trend--up', icon: 'trending-up' };
    if (pct < 0) return { text: '▼ ' + pct + '%', cls: 'ra-trend--down', icon: 'trending-down' };
    return { text: '— Flat', cls: 'ra-trend--neutral', icon: 'minus' };
  }

  function getFilterQuery() {
    var parts = [];
    var from = $('ra-filter-from') || $('reports-filter-from');
    var to = $('ra-filter-to') || $('reports-filter-to');
    var emp = $('ra-filter-employee');
    if (from && from.value) parts.push('from=' + encodeURIComponent(from.value));
    if (to && to.value) parts.push('to=' + encodeURIComponent(to.value));
    if (emp && emp.value) parts.push('employee_id=' + encodeURIComponent(emp.value));
    return parts.length ? '?' + parts.join('&') : '';
  }

  function syncHubFilters() {
    var from = $('ra-filter-from');
    var to = $('ra-filter-to');
    var hubFrom = $('reports-filter-from');
    var hubTo = $('reports-filter-to');
    if (from && hubFrom && from.value) hubFrom.value = from.value;
    if (to && hubTo && to.value) hubTo.value = to.value;
  }

  function apiFetch(url) {
    if (window.CA_CRM && typeof window.CA_CRM.apiFetch === 'function') {
      return window.CA_CRM.apiFetch(url);
    }
    return fetch(url, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    }).then(function (r) { return r.json(); });
  }

  function paintColumnChart(series, opts) {
    opts = opts || {};
    if (!series || !series.length) {
      return '<p class="ra-chart-empty">No data for selected range</p>';
    }
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
    if (!rows.length) return '<p class="ra-chart-empty">No data</p>';
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
    if (!stages || !stages.length) return '<p class="ra-chart-empty">No funnel data</p>';
    var top = Math.max.apply(null, stages.map(function (s) { return Number(s.value) || 0; }).concat([1]));
    return '<div class="ra-funnel">' + stages.map(function (stage, i) {
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
    if (!rows || !rows.length) return '<p class="ra-chart-empty">No trend data</p>';
    var keys = seriesKeys || [{ key: 'value', color: '#4CB4D4' }];
    var allVals = [];
    rows.forEach(function (row) {
      keys.forEach(function (s) { allVals.push(Number(row[s.key]) || 0); });
    });
    var max = Math.max.apply(null, allVals.concat([1]));
    var w = Math.max(rows.length - 1, 1);
    var height = 120;
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
        return '<span>' + escapeHtml(lbl || '—') + '</span>';
      }).join('') + '</div></div>';
  }

  function buildKpis(report) {
    var s = report.summary || {};
    var slug = report.slug || state.slug;
    var rows = report.rows || [];
    var items = [];

    if (slug === 'lead_conversion') {
      items = [
        { icon: 'users', label: 'Total Leads', value: s.total_leads, trend: trendFromSeries(rows.map(function (r) { return r.new_leads; })) },
        { icon: 'check-circle', label: 'Converted', value: s.won_leads, trend: trendFromSeries(rows.map(function (r) { return r.converted_leads; })) },
        { icon: 'percent', label: 'Conversion %', value: fmtPct(s.conversion_rate_pct), trend: { text: 'Live', cls: 'ra-trend--neutral', icon: 'activity' } },
        { icon: 'presentation', label: 'Demo Scheduled', value: s.demo_scheduled, trend: { text: fmtPct(s.demo_ratio_pct) + ' of leads', cls: 'ra-trend--neutral', icon: 'calendar' } },
        { icon: 'trophy', label: 'Won Leads', value: s.won_leads, trend: { text: 'Pipeline ' + fmtNum(s.pipeline_leads), cls: 'ra-trend--neutral', icon: 'git-branch' } },
        { icon: 'x-circle', label: 'Lost Leads', value: s.lost_leads, trend: { text: 'Hot ' + fmtNum(s.hot_leads), cls: 'ra-trend--neutral', icon: 'flame' } },
      ];
    } else if (slug === 'employee_performance') {
      items = [
        { icon: 'users', label: 'Active Employees', value: s.active_employees, trend: { text: 'Team', cls: 'ra-trend--neutral', icon: 'users' } },
        { icon: 'target', label: 'Assigned Leads', value: s.total_assigned_leads, trend: trendFromSeries(rows.map(function (r) { return r.assigned_leads; })) },
        { icon: 'percent', label: 'Avg Achievement', value: fmtPct(s.avg_achievement_pct), trend: { text: 'Target vs achieved', cls: 'ra-trend--neutral', icon: 'bar-chart-2' } },
        { icon: 'phone', label: 'Overdue Follow-ups', value: s.total_overdue_followups, trend: { text: 'Needs attention', cls: 'ra-trend--down', icon: 'alert-circle' } },
      ];
    } else if (slug === 'monthly_trends') {
      items = [
        { icon: 'users', label: 'New Leads', value: s.total_new_leads, trend: trendFromSeries(rows.map(function (r) { return r.new_leads; })) },
        { icon: 'trophy', label: 'Won Leads', value: s.total_won_leads, trend: trendFromSeries(rows.map(function (r) { return r.won_leads; })) },
        { icon: 'calendar', label: 'Months', value: s.months || rows.length, trend: { text: 'Rolling view', cls: 'ra-trend--neutral', icon: 'calendar-range' } },
        { icon: 'presentation', label: 'Demo Leads', value: rows.reduce(function (a, r) { return a + (Number(r.demo_leads) || 0); }, 0), trend: trendFromSeries(rows.map(function (r) { return r.demo_leads; })) },
      ];
    } else if (slug === 'city_analysis') {
      items = [
        { icon: 'map-pin', label: 'Cities', value: s.cities, trend: { text: 'Coverage', cls: 'ra-trend--neutral', icon: 'globe' } },
        { icon: 'users', label: 'Total Leads', value: s.total_leads, trend: trendFromSeries(rows.map(function (r) { return r.total_leads; })) },
        { icon: 'trophy', label: 'Top City', value: rows[0] ? rows[0].city : '—', trend: { text: rows[0] ? fmtNum(rows[0].total_leads) + ' leads' : '—', cls: 'ra-trend--up', icon: 'trending-up' } },
        { icon: 'percent', label: 'Best Conversion', value: rows.length ? fmtPct(rows.slice().sort(function (a, b) { return (b.conversion_rate_pct || 0) - (a.conversion_rate_pct || 0); })[0].conversion_rate_pct) : '—', trend: { text: 'City benchmark', cls: 'ra-trend--neutral', icon: 'sparkles' } },
      ];
    } else {
      Object.keys(s).slice(0, 6).forEach(function (key) {
        var val = s[key];
        items.push({
          icon: 'bar-chart-2',
          label: fmtLabel(key),
          value: typeof val === 'number' ? fmtNum(val) : String(val),
          trend: { text: 'Live data', cls: 'ra-trend--neutral', icon: 'activity' },
        });
      });
    }

    return '<div class="ra-kpi-grid">' + items.map(function (kpi) {
      var t = kpi.trend || {};
      return '<article class="ra-kpi-card">' +
        '<span class="ra-kpi-card__icon"><i data-lucide="' + kpi.icon + '" class="h-5 w-5"></i></span>' +
        '<p class="ra-kpi-card__label">' + escapeHtml(kpi.label) + '</p>' +
        '<p class="ra-kpi-card__value">' + (typeof kpi.value === 'number' ? fmtNum(kpi.value) : escapeHtml(String(kpi.value))) + '</p>' +
        '<p class="ra-kpi-card__trend ' + (t.cls || '') + '"><i data-lucide="' + (t.icon || 'minus') + '" class="h-3 w-3"></i> ' + escapeHtml(t.text || '') + '</p>' +
      '</article>';
    }).join('') + '</div>';
  }

  function buildCharts(report) {
    var slug = report.slug || state.slug;
    var s = report.summary || {};
    var rows = report.rows || [];
    var breakdown = report.breakdown || [];
    var html = '<div class="ra-charts-grid">';

    if (slug === 'lead_conversion') {
      html += chartCard('Daily Lead Growth', 'line-chart', paintLineChart(rows, 'report_date', [
        { key: 'new_leads', color: '#4CB4D4' },
        { key: 'converted_leads', color: '#10b981' },
      ]));
      html += chartCard('Lead Status Distribution', 'pie-chart', paintColumnChart(breakdown.map(function (b) {
        return { label: b.status, value: b.lead_count };
      })));
      html += chartCard('Conversion Rate', 'donut', paintDonut(s.conversion_rate_pct, 'Overall Conversion'));
      var total = Number(s.total_leads) || 1;
      var funnelStages = [
        { label: 'Total Leads', value: s.total_leads, pct: '100%' },
        { label: 'Hot & Warm', value: (Number(s.hot_leads) || 0) + (Number(s.warm_leads) || 0), pct: fmtPct((((Number(s.hot_leads) || 0) + (Number(s.warm_leads) || 0)) / total) * 100) },
        { label: 'Demo Scheduled', value: s.demo_scheduled, pct: fmtPct((Number(s.demo_scheduled) / total) * 100), drop: 'Stage drop-off' },
        { label: 'Pipeline', value: s.pipeline_leads, pct: fmtPct((Number(s.pipeline_leads) / total) * 100) },
        { label: 'Converted', value: s.won_leads, pct: fmtPct((Number(s.won_leads) / total) * 100) },
      ];
      html += '<section class="ra-chart-card ra-chart-card--wide"><header class="ra-chart-card__head"><i data-lucide="filter" class="h-4 w-4 text-brand"></i><h4>Conversion Funnel</h4></header><div class="ra-chart-card__body">' + paintFunnel(funnelStages) + '</div></section>';
    } else if (slug === 'monthly_trends') {
      html += chartCard('Monthly Lead Trend', 'bar-chart-3', paintColumnChart(rows.map(function (r) { return { label: r.month, value: r.new_leads }; })));
      html += chartCard('Conversion Trend', 'line-chart', paintLineChart(rows, 'month', [{ key: 'conversion_rate_pct', color: '#8b5cf6' }]));
      html += chartCard('Demo Trend', 'presentation', paintColumnChart(rows.map(function (r) { return { label: r.month, value: r.demo_leads }; }), { color: 'linear-gradient(to top,#f59e0bcc,#f59e0b44)' }));
      html += chartCard('Won vs Lost', 'bar-chart-2', paintColumnChart(rows.map(function (r) { return { label: r.month, value: r.won_leads }; })));
      html += '<section class="ra-chart-card ra-chart-card--wide"><header class="ra-chart-card__head"><i data-lucide="grid-3x3" class="h-4 w-4 text-brand"></i><h4>Monthly Performance Heatmap</h4></header><div class="ra-chart-card__body">' + paintHeatmap(rows) + '</div></section>';
    } else if (slug === 'employee_performance') {
      html += chartCard('Top Performers', 'trophy', paintHorizontalBars(rows, 'employee_name', 'achievement_pct', 8));
      html += chartCard('Assigned Leads', 'users', paintHorizontalBars(rows, 'employee_name', 'assigned_leads', 8));
      html += chartCard('Demo Follow-ups', 'presentation', paintHorizontalBars(rows, 'employee_name', 'demo_followups', 8));
      html += chartCard('Overdue Items', 'alert-circle', paintHorizontalBars(rows, 'employee_name', 'overdue_followups', 8));
    } else if (slug === 'city_analysis') {
      html += chartCard('Top Cities by Leads', 'map-pin', paintHorizontalBars(rows, 'city', 'total_leads', 10));
      html += chartCard('Conversion by City', 'percent', paintHorizontalBars(rows.slice().sort(function (a, b) { return (b.conversion_rate_pct || 0) - (a.conversion_rate_pct || 0); }), 'city', 'conversion_rate_pct', 10));
      html += chartCard('Lead Distribution', 'pie-chart', paintColumnChart(rows.slice(0, 10).map(function (r) { return { label: r.city, value: r.total_leads }; })));
      html += chartCard('Lost Leads by City', 'trending-down', paintHorizontalBars(rows, 'city', 'lost_leads', 8));
    } else if (slug === 'followup_performance') {
      html += chartCard('Follow-up Volume', 'phone', paintColumnChart(rows.map(function (r) { return { label: r.followup_type, value: r.total_followups }; })));
      html += chartCard('Completion Rate', 'check-circle', paintHorizontalBars(rows, 'followup_type', 'completion_rate_pct', 10));
    } else {
      html += chartCard('Report Overview', 'bar-chart-3', paintColumnChart(rows.slice(0, 12).map(function (r, i) {
        var keys = Object.keys(r);
        var numKey = keys.find(function (k) { return typeof r[k] === 'number'; }) || keys[0];
        var labelKey = keys.find(function (k) { return typeof r[k] === 'string'; }) || ('Row ' + (i + 1));
        return { label: r[labelKey] || ('Row ' + (i + 1)), value: Number(r[numKey]) || 0 };
      })));
    }

    html += '</div>';
    return html;
  }

  function chartCard(title, icon, bodyHtml) {
    return '<section class="ra-chart-card"><header class="ra-chart-card__head"><i data-lucide="' + icon + '" class="h-4 w-4 text-brand"></i><h4>' + escapeHtml(title) + '</h4></header><div class="ra-chart-card__body">' + bodyHtml + '</div></section>';
  }

  function paintHeatmap(rows) {
    if (!rows || !rows.length) return '<p class="ra-chart-empty">No monthly data</p>';
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
    var rows = filterRows(report.rows || [], columns);
    var slug = report.slug || state.slug;

    var thead = keys.map(function (key) {
      var sorted = state.sortKey === key ? (state.sortDir === 'asc' ? ' ▲' : ' ▼') : '';
      return '<th scope="col" data-ra-sort="' + escapeHtml(key) + '">' + escapeHtml(columns[key]) + sorted + '</th>';
    }).join('');

    var tbody = rows.length ? rows.map(function (row, idx) {
      var tds = keys.map(function (key) {
        var val = row[key];
        if (key === 'achievement_pct' || key === 'conversion_rate_pct' || key === 'completion_rate_pct') {
          var pct = Math.min(100, Math.max(0, Number(val) || 0));
          return '<td><div class="ra-progress-cell"><span>' + fmtPct(val) + '</span><div class="ra-progress-track"><div class="ra-progress-fill" style="width:' + pct + '%"></div></div></div></td>';
        }
        if (slug === 'employee_performance' && key === 'employee_name') {
          return '<td><span class="ra-rank">' + (idx + 1) + '</span> ' + escapeHtml(val == null ? '—' : String(val)) + '</td>';
        }
        return '<td>' + escapeHtml(val == null ? '—' : String(val)) + '</td>';
      }).join('');
      return '<tr>' + tds + '</tr>';
    }).join('') : '<tr><td colspan="' + keys.length + '" class="ra-table-empty">No rows match your search.</td></tr>';

    return '<section class="ra-table-section">' +
      '<header class="ra-table-section__head">' +
        '<div><h4>Detailed Data</h4><p>' + fmtNum(rows.length) + ' records · sortable · searchable</p></div>' +
        '<label class="ra-table-search"><i data-lucide="search" class="h-4 w-4"></i>' +
          '<input type="search" id="ra-table-search" placeholder="Search table…" value="' + escapeHtml(state.tableSearch) + '" aria-label="Search report table" /></label>' +
      '</header>' +
      '<div class="ra-table-wrap scrollbar-thin"><table class="ra-table"><thead><tr>' + thead + '</tr></thead><tbody>' + tbody + '</tbody></table></div>' +
    '</section>';
  }

  function buildInsights(report) {
    var slug = report.slug || state.slug;
    var s = report.summary || {};
    var rows = report.rows || [];
    var breakdown = report.breakdown || [];
    var insights = [];

    if (slug === 'employee_performance' && rows.length) {
      var best = rows.slice().sort(function (a, b) { return (b.achievement_pct || 0) - (a.achievement_pct || 0); })[0];
      var low = rows.slice().sort(function (a, b) { return (a.achievement_pct || 0) - (b.achievement_pct || 0); })[0];
      insights.push({ icon: 'sparkles', title: 'Highest converting employee', text: (best.employee_name || '—') + ' · ' + fmtPct(best.achievement_pct) + ' achievement' });
      insights.push({ icon: 'alert-triangle', title: 'Needs coaching', text: (low.employee_name || '—') + ' · ' + fmtPct(low.achievement_pct) + ' achievement' });
    }
    if (slug === 'city_analysis' && rows.length) {
      var topCity = rows[0];
      var lowCity = rows.slice().sort(function (a, b) { return (a.conversion_rate_pct || 0) - (b.conversion_rate_pct || 0); })[0];
      insights.push({ icon: 'map-pin', title: 'Top city', text: (topCity.city || '—') + ' with ' + fmtNum(topCity.total_leads) + ' leads' });
      insights.push({ icon: 'trending-down', title: 'Lowest performing city', text: (lowCity.city || '—') + ' · ' + fmtPct(lowCity.conversion_rate_pct) + ' conversion' });
    }
    if (slug === 'lead_conversion') {
      var topStatus = breakdown.slice().sort(function (a, b) { return (b.lead_count || 0) - (a.lead_count || 0); })[0];
      insights.push({ icon: 'filter', title: 'Best lead source signal', text: topStatus ? (topStatus.status + ' · ' + fmtNum(topStatus.lead_count) + ' leads (' + fmtPct(topStatus.share_pct) + ')') : 'Review lead statuses' });
      insights.push({ icon: 'presentation', title: 'Demo conversion', text: fmtPct(s.demo_ratio_pct) + ' of leads reached demo stage' });
      insights.push({ icon: 'target', title: 'Today\'s recommendation', text: (Number(s.lost_leads) || 0) > (Number(s.won_leads) || 0) ? 'Focus on recovering lost pipeline leads.' : 'Double down on demo-to-close follow-ups.' });
    }
    if (slug === 'monthly_trends' && rows.length) {
      var bestMonth = rows.slice().sort(function (a, b) { return (b.new_leads || 0) - (a.new_leads || 0); })[0];
      insights.push({ icon: 'calendar', title: 'Top performing month', text: (bestMonth.month || '—') + ' · ' + fmtNum(bestMonth.new_leads) + ' new leads' });
      insights.push({ icon: 'line-chart', title: 'Monthly trend', text: 'Won ' + fmtNum(s.total_won_leads) + ' of ' + fmtNum(s.total_new_leads) + ' leads in view' });
    }
    if (!insights.length) {
      insights.push({ icon: 'lightbulb', title: 'Business insight', text: 'Report loaded with ' + fmtNum(rows.length) + ' detail rows for the selected period.' });
      insights.push({ icon: 'shield-check', title: 'System alert', text: (Number(s.total_overdue_followups) || 0) > 0 ? fmtNum(s.total_overdue_followups) + ' overdue follow-ups need review.' : 'No critical alerts for this report.' });
    }

    return '<section class="ra-insights-panel">' +
      '<header class="ra-insights-panel__head"><i data-lucide="sparkles" class="h-4 w-4 text-brand"></i><h4>Business Insights</h4><span class="ra-ai-badge">AI Insights</span></header>' +
      '<div class="ra-insights-list">' +
      insights.map(function (item) {
        return '<article class="ra-insight-card"><span class="ra-insight-card__icon"><i data-lucide="' + item.icon + '" class="h-4 w-4"></i></span><div><h5>' + escapeHtml(item.title) + '</h5><p>' + escapeHtml(item.text) + '</p></div></article>';
      }).join('') +
      '</div></section>';
  }

  function metaLine(report) {
    var from = ($('ra-filter-from') || $('reports-filter-from') || {}).value || '—';
    var to = ($('ra-filter-to') || $('reports-filter-to') || {}).value || '—';
    var user = (window.currentUser && window.currentUser.name) || 'System';
    return '<span><i data-lucide="calendar-range" class="h-3.5 w-3.5"></i> ' + escapeHtml(from) + ' → ' + escapeHtml(to) + '</span>' +
      '<span><i data-lucide="clock" class="h-3.5 w-3.5"></i> Updated ' + escapeHtml(new Date().toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })) + '</span>' +
      '<span><i data-lucide="user" class="h-3.5 w-3.5"></i> Generated by ' + escapeHtml(user) + '</span>';
  }

  function renderShell(report) {
    var meta = SLUG_META[state.slug] || {};
    var title = report.label || meta.title || fmtLabel(state.slug);
    var root = $('ra-root');
    if (!root) return;

    root.innerHTML =
      '<header class="ra-drawer__header">' +
        '<button type="button" class="ra-back-btn" id="ra-back" aria-label="Back">' +
          '<i data-lucide="arrow-left" class="h-5 w-5"></i><span>Back</span>' +
        '</button>' +
        '<div class="ra-drawer__header-main">' +
          '<p class="ra-drawer__eyebrow"><i data-lucide="' + (meta.icon || 'file-text') + '" class="h-4 w-4"></i> Analytics Dashboard</p>' +
          '<h2 id="ra-title" class="ra-drawer__title">' + escapeHtml(title) + '</h2>' +
          '<div class="ra-drawer__meta">' + metaLine(report) + '</div>' +
        '</div>' +
        '<div class="ra-drawer__actions">' +
          '<div class="ra-export-menu">' +
            '<button type="button" class="btn-secondary btn-sm" id="ra-export-toggle" aria-haspopup="true"><i data-lucide="download" class="h-4 w-4"></i> Export</button>' +
            '<div class="ra-export-menu__list hidden" id="ra-export-list">' +
              '<button type="button" data-ra-export="pdf">PDF</button>' +
              '<button type="button" data-ra-export="csv">Excel / CSV</button>' +
              '<button type="button" data-ra-export="print">Print</button>' +
            '</div>' +
          '</div>' +
          '<button type="button" class="crm-toolbar-icon-btn" id="ra-refresh" title="Refresh" aria-label="Refresh"><i data-lucide="refresh-cw" class="h-4 w-4"></i></button>' +
          '<button type="button" class="crm-toolbar-icon-btn" id="ra-share" title="Share" aria-label="Share"><i data-lucide="share-2" class="h-4 w-4"></i></button>' +
          '<button type="button" class="ca-modal-close" id="ra-close" aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>' +
        '</div>' +
      '</header>' +
      '<div class="ra-drawer__layout">' +
        '<aside class="ra-drawer__filters">' +
          '<h3>Filters</h3>' +
          '<label class="ra-filter-field"><span>Date From</span><input type="date" id="ra-filter-from" class="input-field input-field-sm" /></label>' +
          '<label class="ra-filter-field"><span>Date To</span><input type="date" id="ra-filter-to" class="input-field input-field-sm" /></label>' +
          '<label class="ra-filter-field"><span>Employee</span><select id="ra-filter-employee" class="input-field input-field-sm"><option value="">All employees</option></select></label>' +
          '<label class="ra-filter-field"><span>Status</span><select id="ra-filter-status" class="input-field input-field-sm"><option value="">All statuses</option><option>Hot</option><option>Warm</option><option>New</option><option>Demo Scheduled</option><option>Lost</option></select></label>' +
          '<label class="ra-filter-field"><span>Lead Source</span><input type="search" id="ra-filter-source" class="input-field input-field-sm" placeholder="Filter table…" /></label>' +
          '<div class="ra-filter-actions">' +
            '<button type="button" class="btn-primary btn-sm w-full" id="ra-filter-apply">Apply</button>' +
            '<button type="button" class="btn-secondary btn-sm w-full" id="ra-filter-reset">Reset</button>' +
            '<button type="button" class="btn-ghost btn-sm w-full" id="ra-filter-save">Save Filter</button>' +
          '</div>' +
        '</aside>' +
        '<main class="ra-drawer__main">' +
          '<div id="ra-kpis">' + buildKpis(report) + '</div>' +
          '<div id="ra-charts">' + buildCharts(report) + '</div>' +
          '<div id="ra-table">' + buildTable(report) + '</div>' +
        '</main>' +
        '<aside class="ra-drawer__insights" id="ra-insights">' + buildInsights(report) + '</aside>' +
      '</div>';

    seedFilters();
    iconsIn(root);
  }

  function seedFilters() {
    var hubFrom = $('reports-filter-from');
    var hubTo = $('reports-filter-to');
    var from = $('ra-filter-from');
    var to = $('ra-filter-to');
    if (from && hubFrom && hubFrom.value) from.value = hubFrom.value;
    if (to && hubTo && hubTo.value) to.value = hubTo.value;
    if (from && !from.value) {
      var start = new Date();
      start.setDate(start.getDate() - 30);
      from.value = start.toISOString().slice(0, 10);
    }
    if (to && !to.value) to.value = new Date().toISOString().slice(0, 10);
  }

  function renderLoading() {
    var root = $('ra-root');
    if (!root) return;
    root.innerHTML = '<div class="ra-loading"><div class="ra-loading__spinner"></div><p>Loading analytics dashboard…</p></div>';
  }

  function openDrawer() {
    var drawer = $('report-analytics-drawer');
    if (!drawer) return;
    if (window.CA_CRM && typeof window.CA_CRM.openExclusiveCrmModal === 'function') {
      window.CA_CRM.openExclusiveCrmModal(drawer);
    } else {
      drawer.classList.add('open');
    }
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ra-drawer-open');
    iconsIn(drawer);
  }

  function closeDrawer() {
    var drawer = $('report-analytics-drawer');
    if (!drawer) return;
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('ra-drawer-open');
    var exportList = $('ra-export-list');
    if (exportList) exportList.classList.add('hidden');
    if (window.CA_CRM && typeof window.CA_CRM.closeModal === 'function') {
      window.CA_CRM.closeModal(drawer);
    } else {
      var overlay = document.getElementById('overlay');
      if (overlay) overlay.classList.remove('active');
      if (typeof window.setCrmScrollLock === 'function') window.setCrmScrollLock(false);
      else document.body.style.overflow = '';
    }
  }

  function loadReport(slug, opts) {
    opts = opts || {};
    var isRefresh = slug === state.slug;
    state.slug = slug;
    if (!isRefresh || !opts.preserveSearch) {
      state.tableSearch = '';
      state.sortKey = null;
      state.sortDir = 'desc';
    }
    state.loading = true;
    openDrawer();
    renderLoading();
    return apiFetch('/reports/' + encodeURIComponent(slug) + getFilterQuery())
      .then(function (body) {
        state.report = body.data || {};
        state.loading = false;
        renderShell(state.report);
      })
      .catch(function (err) {
        state.loading = false;
        toast(err.message || 'Unable to load report', 'error');
        closeDrawer();
      });
  }

  function refreshReport() {
    if (!state.slug) return;
    loadReport(state.slug, { preserveSearch: true });
  }

  function applyFilters() {
    syncHubFilters();
    var status = $('ra-filter-status');
    var source = $('ra-filter-source');
    state.tableSearch = (source && source.value) || '';
    if (status && status.value) state.tableSearch = status.value + ' ' + state.tableSearch;
    refreshReport();
  }

  function resetFilters() {
    seedFilters();
    state.tableSearch = '';
    state.sortKey = null;
    state.sortDir = 'desc';
    ['ra-filter-status', 'ra-filter-source', 'ra-filter-employee'].forEach(function (id) {
      var el = $(id);
      if (el) el.value = '';
    });
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

  function bindGlobal() {
    if (state.bound) return;
    state.bound = true;

    var drawer = $('report-analytics-drawer');
    if (!drawer) return;

    drawer.addEventListener('click', function (e) {
      if (e.target.closest('#ra-back, [data-ra-close], .ra-drawer__backdrop, #ra-close')) {
        closeDrawer();
        return;
      }
      if (e.target.closest('#ra-refresh')) {
        refreshReport();
        return;
      }
      if (e.target.closest('#ra-share')) {
        var url = window.location.href.split('#')[0] + '#reports/' + (state.slug || '');
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
      if (e.target.closest('#ra-filter-save')) {
        try {
          localStorage.setItem('ca_crm_report_filter_' + state.slug, JSON.stringify({
            from: ($('ra-filter-from') || {}).value,
            to: ($('ra-filter-to') || {}).value,
            employee: ($('ra-filter-employee') || {}).value,
          }));
          toast('Filter preset saved.', 'success');
        } catch (err) {
          toast('Could not save filter.', 'error');
        }
        return;
      }
      var th = e.target.closest('[data-ra-sort]');
      if (th) {
        handleTableSort(th.getAttribute('data-ra-sort'));
      }
    });

    drawer.addEventListener('input', function (e) {
      if (e.target && e.target.id === 'ra-table-search') {
        handleTableSearch(e.target.value);
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && drawer.classList.contains('open')) {
        closeDrawer();
      }
    });
  }

  bindGlobal();

  return {
    open: loadReport,
    close: closeDrawer,
    refresh: refreshReport,
  };
})();
