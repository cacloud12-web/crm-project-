/* Unified Campaign Management Hub — Email, SMS, WhatsApp */
(function () {
  'use strict';

  var state = {
    items: [],
    page: 1,
    perPage: 10,
    total: 0,
    filters: {
      channel: '',
      status: '',
      q: '',
      audience_mode: '',
    },
    loading: false,
    bound: false,
  };

  function apiFetch(url, options) {
    if (window.CA_CRM && typeof CA_CRM.apiFetch === 'function') {
      return CA_CRM.apiFetch(url, options);
    }
    return fetch(url, options).then(function (r) { return r.json(); });
  }

  function toast(msg, type) {
    if (window.showToast) window.showToast(msg, type);
    else if (window.CA_CRM && CA_CRM.toast) CA_CRM.toast(msg, type);
  }

  function escapeHtml(value) {
    if (window.CA_CRM && CA_CRM.escapeHtml) return CA_CRM.escapeHtml(value);
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function formatDateTime(value) {
    if (!value) return '—';
    var d = new Date(value);
    if (isNaN(d.getTime())) return value;
    return d.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function statusBadge(status) {
    var map = {
      Draft: 'badge-neutral',
      Scheduled: 'badge-brand',
      Processing: 'badge-warning',
      Completed: 'badge-success',
      Partial: 'badge-warning',
      Failed: 'badge-danger',
      Cancelled: 'badge-neutral',
      Paused: 'badge-warning',
    };
    return '<span class="badge ' + (map[status] || 'badge-neutral') + '">' + escapeHtml(status || '—') + '</span>';
  }

  function channelBadge(channel) {
    var key = String(channel || '').toLowerCase();
    var cls = key === 'email' ? 'cam-channel-email' : key === 'sms' ? 'cam-channel-sms' : 'cam-channel-whatsapp';
    return '<span class="cam-channel-badge ' + cls + '">' + escapeHtml(channel) + '</span>';
  }

  function progressBar(rate, tone) {
    tone = tone || 'brand';
    return '<div class="cam-progress"><div class="cam-progress__bar cam-progress__bar--' + tone + '" style="width:' + Math.max(0, Math.min(100, rate)) + '%"></div></div>';
  }

  function campaignActionItems(c) {
    var iconMap = {
      view: 'eye',
      edit: 'pencil',
      delete: 'trash-2',
      retry_failed: 'rotate-ccw',
      export: 'download',
      launch: 'rocket',
    };
    return (c.available_actions || ['view']).map(function (action) {
      return {
        action: action,
        label: action.replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); }),
        icon: iconMap[action] || 'more-horizontal',
        danger: action === 'delete',
        dataAttrs: { channel: c.channel_key, 'campaign-id': c.id },
      };
    });
  }

  function campaignCardHtml(c) {
    var stats = c.stats || {};
    var progress = c.progress || {};
    var actions = (window.CAActionDropdown && (c.available_actions || []).length)
      ? CAActionDropdown.renderInline(campaignActionItems(c), {
        scope: 'unified-campaign',
        rowId: c.channel_key + ':' + c.id,
        ariaLabel: 'Campaign actions',
      })
      : '';

    return '<article class="card p-4 cam-unified-card" data-channel="' + c.channel_key + '" data-id="' + c.id + '">' +
      '<div class="flex items-start justify-between gap-3 mb-2">' +
        '<div><h3 class="text-card-heading">' + escapeHtml(c.campaign_name) + '</h3>' +
        '<p class="text-caption text-slate-500 mt-1">' + channelBadge(c.channel) + ' · ' + escapeHtml(c.campaign_type || '—') + '</p></div>' +
        statusBadge(c.status) +
      '</div>' +
      '<div class="grid grid-cols-2 gap-2 text-caption text-slate-600 mb-3">' +
        '<div><span class="text-slate-400">Audience</span><br>' + escapeHtml(c.audience_label || c.audience_mode) + '</div>' +
        '<div><span class="text-slate-400">Sender</span><br>' + escapeHtml(c.sender_used || '—') + '</div>' +
        '<div><span class="text-slate-400">Template</span><br>' + escapeHtml(c.template_name || '—') + '</div>' +
        '<div><span class="text-slate-400">Created By</span><br>' + escapeHtml(c.created_by || '—') + '</div>' +
      '</div>' +
      '<div class="cam-stats-row">' +
        '<span>Total ' + (stats.total_recipients || 0) + '</span>' +
        '<span>Sent ' + (stats.sent || 0) + '</span>' +
        '<span>Delivered ' + (stats.delivered || 0) + '</span>' +
        '<span>Failed ' + (stats.failed || 0) + '</span>' +
      '</div>' +
      progressBar(progress.delivery_rate || 0, 'success') +
      '<p class="text-caption text-slate-400 mt-1">Delivery ' + (progress.delivery_rate || 0) + '% · Failure ' + (progress.failure_rate || 0) + '%</p>' +
      '<p class="text-caption text-slate-400">Scheduled: ' + escapeHtml(formatDateTime(c.scheduled_at)) + '</p>' +
      (actions ? '<div class="flex justify-end mt-3">' + actions + '</div>' : '') +
    '</article>';
  }

  function buildQuery() {
    var params = new URLSearchParams();
    params.set('page', String(state.page));
    params.set('per_page', String(state.perPage));
    if (state.filters.channel) params.set('channel', state.filters.channel);
    if (state.filters.status) params.set('status', state.filters.status);
    if (state.filters.q) params.set('q', state.filters.q);
    if (state.filters.audience_mode) params.set('audience_mode', state.filters.audience_mode);
    return params.toString();
  }

  function loadCampaigns() {
    state.loading = true;
    renderGridLoading();
    return apiFetch('/campaigns?' + buildQuery()).then(function (body) {
      var data = body.data || {};
      state.items = data.items || [];
      state.total = data.total || 0;
      state.page = data.page || 1;
      state.loading = false;
      renderGrid();
      renderPagination();
    }).catch(function (err) {
      state.loading = false;
      renderGridError(err.message || 'Failed to load campaigns');
    });
  }

  function renderGridLoading() {
    var grid = document.getElementById('unified-campaigns-grid');
    if (grid) grid.innerHTML = '<p class="text-slate-500 col-span-full p-6 text-center">Loading campaigns…</p>';
  }

  function renderGridError(message) {
    var grid = document.getElementById('unified-campaigns-grid');
    if (grid) grid.innerHTML = '<p class="text-red-600 col-span-full p-6 text-center">' + escapeHtml(message) + '</p>';
  }

  function renderGrid() {
    var grid = document.getElementById('unified-campaigns-grid');
    if (!grid) return;
    if (!state.items.length) {
      grid.innerHTML = '<p class="text-slate-500 col-span-full p-6 text-center">No campaigns found. Create one from Email, SMS, or WhatsApp.</p>';
      return;
    }
    grid.innerHTML = state.items.map(campaignCardHtml).join('');
    if (typeof lucide !== 'undefined') lucide.createIcons();
  }

  function renderPagination() {
    var el = document.getElementById('unified-campaigns-pagination');
    if (!el || !window.CATablePagination) return;
    var pages = Math.max(1, Math.ceil(state.total / state.perPage));
    var from = state.total ? ((state.page - 1) * state.perPage) + 1 : 0;
    var to = state.total ? Math.min(state.page * state.perPage, state.total) : 0;
    CATablePagination.renderInto(el, {
      scope: 'unified-campaigns',
      pagination: {
        current_page: state.page,
        last_page: pages,
        total: state.total,
        from: from,
        to: to,
        per_page: state.perPage,
      },
      perPage: state.perPage,
      showPerPage: true,
    });
  }

  function openDetail(channel, id) {
    apiFetch('/campaigns/' + encodeURIComponent(channel) + '/' + encodeURIComponent(id)).then(function (body) {
      var c = body.data || {};
      var modal = document.getElementById('modal-campaign-detail');
      if (!modal) return;
      document.getElementById('campaign-detail-title').textContent = c.campaign_name || 'Campaign Detail';
      document.getElementById('campaign-detail-meta').innerHTML =
        channelBadge(c.channel) + ' ' + statusBadge(c.status) +
        '<span class="text-caption text-slate-500 ml-2">' + escapeHtml(c.audience_label || '') + '</span>';
      document.getElementById('campaign-detail-summary').innerHTML =
        '<div class="grid md:grid-cols-2 gap-4">' +
          '<div class="card p-4"><h4 class="font-semibold mb-2">Campaign Details</h4>' +
            '<p><strong>Type:</strong> ' + escapeHtml(c.campaign_type) + '</p>' +
            '<p><strong>Created:</strong> ' + escapeHtml(formatDateTime(c.created_at)) + '</p>' +
            '<p><strong>Scheduled:</strong> ' + escapeHtml(formatDateTime(c.scheduled_at)) + '</p>' +
            '<p><strong>Created By:</strong> ' + escapeHtml(c.created_by) + '</p></div>' +
          '<div class="card p-4"><h4 class="font-semibold mb-2">Sender & Template</h4>' +
            '<p><strong>Sender:</strong> ' + escapeHtml(c.sender_used) + '</p>' +
            '<p><strong>Template:</strong> ' + escapeHtml(c.template_name) + '</p>' +
            '<pre class="text-xs bg-slate-50 rounded p-3 mt-2 whitespace-pre-wrap">' + escapeHtml(JSON.stringify(c.template_preview || {}, null, 2)) + '</pre></div>' +
        '</div>';
      var stats = c.stats || {};
      document.getElementById('campaign-detail-stats').innerHTML =
        '<div class="cam-stats-row cam-stats-row--detail">' +
        ['total_recipients', 'valid_recipients', 'sent', 'delivered', 'failed', 'pending', 'invalid', 'duplicate', 'skipped', 'bounce']
          .map(function (key) {
            return '<div class="cam-stat-pill"><span>' + key.replace(/_/g, ' ') + '</span><strong>' + (stats[key] || 0) + '</strong></div>';
          }).join('') + '</div>' +
        progressBar((c.progress || {}).delivery_rate || 0, 'success');
      var recipients = c.recipients || [];
      var headers = recipients[0] ? Object.keys(recipients[0]) : [];
      document.getElementById('campaign-detail-recipients').innerHTML = recipients.length
        ? '<div class="overflow-x-auto"><table class="ca-table w-full"><thead><tr>' +
          headers.map(function (h) { return '<th>' + escapeHtml(h.replace(/_/g, ' ')) + '</th>'; }).join('') +
          '</tr></thead><tbody>' +
          recipients.slice(0, 100).map(function (row) {
            return '<tr>' + headers.map(function (h) {
              return '<td>' + escapeHtml(row[h]) + '</td>';
            }).join('') + '</tr>';
          }).join('') +
          '</tbody></table></div>'
        : '<p class="text-slate-500">No recipient logs yet.</p>';
      var timeline = c.activity_timeline || c.status_history || [];
      document.getElementById('campaign-detail-timeline').innerHTML = timeline.length
        ? '<ul class="space-y-2">' + timeline.map(function (item) {
          return '<li class="text-sm"><strong>' + escapeHtml(item.action || item.status || 'Event') + '</strong> — ' +
            escapeHtml(item.detail || item.note || '') + ' <span class="text-slate-400">· ' + escapeHtml(formatDateTime(item.created_at || item.at)) + '</span></li>';
        }).join('') + '</ul>'
        : '<p class="text-slate-500">No activity yet.</p>';
      modal.dataset.channel = channel;
      modal.dataset.campaignId = String(id);
      var actions = c.available_actions || [];
      var retryBtn = document.getElementById('campaign-detail-retry-btn');
      var exportBtn = document.getElementById('campaign-detail-export-btn');
      if (retryBtn) retryBtn.classList.toggle('hidden', actions.indexOf('retry_failed') < 0);
      if (exportBtn) exportBtn.classList.toggle('hidden', actions.indexOf('export') < 0);
      if (window.CA_CRM && CA_CRM.openExclusiveCrmModal) CA_CRM.openExclusiveCrmModal(modal);
      else modal.classList.add('open');
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }).catch(function (err) {
      toast(err.message || 'Unable to load campaign detail', 'error');
    });
  }

  function runAction(channel, id, action) {
    if (action === 'view') {
      openDetail(channel, id);
      return;
    }
    if (action === 'export') {
      var token = document.querySelector('meta[name="csrf-token"]')?.content || '';
      fetch('/campaigns/' + encodeURIComponent(channel) + '/' + encodeURIComponent(id) + '/export', {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': token,
          'Accept': 'text/csv',
        },
      }).then(function (response) {
        if (!response.ok) throw new Error('Export failed');
        return response.blob().then(function (blob) {
          var disposition = response.headers.get('Content-Disposition') || '';
          var match = disposition.match(/filename="?([^";]+)"?/i);
          var filename = match ? match[1] : channel + '-campaign-' + id + '.csv';
          var url = URL.createObjectURL(blob);
          var link = document.createElement('a');
          link.href = url;
          link.download = filename;
          link.click();
          URL.revokeObjectURL(url);
          toast('Report exported', 'success');
        });
      }).catch(function (err) {
        toast(err.message || 'Export failed', 'error');
      });
      return;
    }
    if (action === 'edit') {
      toast('Open channel page to edit draft campaigns.', 'info');
      return;
    }
    var method = 'POST';
    var url = '/campaigns/' + encodeURIComponent(channel) + '/' + encodeURIComponent(id);
    if (action === 'delete') {
      if (!window.confirm('Delete this campaign?')) return;
      method = 'DELETE';
      url = '/campaigns/' + encodeURIComponent(channel) + '/' + encodeURIComponent(id);
    } else if (action === 'duplicate') {
      url += '/duplicate';
    } else if (action === 'retry_failed') {
      url += '/retry-failed';
    } else if (action === 'pause') {
      url += '/pause';
    } else if (action === 'resume') {
      url += '/resume';
    } else if (action === 'cancel') {
      url += '/cancel';
    } else {
      return;
    }
    apiFetch(url, { method: method, headers: { 'Content-Type': 'application/json' } }).then(function () {
      toast('Campaign updated', 'success');
      loadCampaigns();
    }).catch(function (err) {
      toast(err.message || 'Action failed', 'error');
    });
  }

  function bindFilters() {
    if (state.bound) return;
    state.bound = true;
    var search = document.getElementById('unified-campaigns-search');
    var channel = document.getElementById('unified-campaigns-channel');
    var status = document.getElementById('unified-campaigns-status');
    var audience = document.getElementById('unified-campaigns-audience');
    var apply = document.getElementById('unified-campaigns-apply-filters');
    if (apply) {
      apply.addEventListener('click', function () {
        state.page = 1;
        state.filters.q = search ? search.value.trim() : '';
        state.filters.channel = channel ? channel.value : '';
        state.filters.status = status ? status.value : '';
        state.filters.audience_mode = audience ? audience.value : '';
        loadCampaigns();
      });
    }
    if (search) {
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (apply) apply.click();
        }
      });
    }
    if (window.CATablePagination) {
      CATablePagination.register('unified-campaigns', {
        onPageChange: function (page) {
          state.page = page;
          loadCampaigns();
        },
        onPerPageChange: function (perPage) {
          state.perPage = perPage;
          state.page = 1;
          loadCampaigns();
        },
      });
    }
    document.getElementById('unified-campaigns-grid')?.addEventListener('click', function (e) {
      if (e.target.closest('[data-action-menu-trigger], [data-row-action]')) return;
      var btn = e.target.closest('[data-campaign-action]');
      if (!btn) return;
      runAction(btn.dataset.channel, btn.dataset.id, btn.dataset.campaignAction);
    });
    if (window.CAActionDropdown) {
      CAActionDropdown.register('unified-campaign', function (action, dataset) {
        runAction(dataset.channel, dataset.campaignId, action);
      });
    }
    document.getElementById('campaign-detail-retry-btn')?.addEventListener('click', function () {
      var modal = document.getElementById('modal-campaign-detail');
      if (!modal) return;
      runAction(modal.dataset.channel, modal.dataset.campaignId, 'retry_failed');
    });
    document.getElementById('campaign-detail-export-btn')?.addEventListener('click', function () {
      var modal = document.getElementById('modal-campaign-detail');
      if (!modal) return;
      runAction(modal.dataset.channel, modal.dataset.campaignId, 'export');
    });
  }

  function refresh() {
    if (!document.getElementById('unified-campaigns-grid')) return;
    bindFilters();
    loadCampaigns();
  }

  window.CA_CAMPAIGNS_HUB = {
    refresh: refresh,
    loadCampaigns: loadCampaigns,
    openDetail: openDetail,
  };
})();
