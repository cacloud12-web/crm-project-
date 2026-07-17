/* CA Cloud Desk — Master Data → OCR Import page (Document AI library) */
(function () {
  'use strict';

  var state = {
    page: 1,
    perPage: 15,
    search: '',
    status: '',
    selectedId: null,
    pollTimer: null,
    pollInFlight: false,
    pollStartedAt: null,
    loading: false,
    uploading: false,
    retryingId: null,
    deletingId: null,
    listAbort: null,
    mountedHost: null,
    detailDirty: false,
    openInFlight: null,
    importType: '',
  };

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function toast(message, type) {
    if (typeof window.toast === 'function') window.toast(message, type || 'info');
  }

  function apiFetch(url, options) {
    if (window.CA_CRM && typeof window.CA_CRM.apiFetch === 'function') {
      return window.CA_CRM.apiFetch(url, options);
    }
    if (typeof window.apiFetch === 'function' && window.apiFetch !== apiFetch) {
      return window.apiFetch(url, options);
    }
    return Promise.reject(new Error('CRM API is not ready yet. Please refresh the page.'));
  }

  function maxFileMb() {
    return Number((window.__CRM_DOCUMENT_AI__ && window.__CRM_DOCUMENT_AI__.max_file_mb) || 20);
  }

  function statusClass(status, item) {
    var stage = (item && item.pipeline_stage) || status;
    if (stage === 'completed' || status === 'completed') return 'ocr-status--completed';
    if (stage === 'failed' || status === 'failed' || status === 'cancelled') return 'ocr-status--failed';
    if (stage === 'uploading' || stage === 'ocr' || stage === 'parsing' || stage === 'mapping'
      || status === 'processing' || status === 'queued' || status === 'uploading_to_cloud' || status === 'finalizing') {
      return 'ocr-status--processing';
    }
    return 'ocr-status--pending';
  }

  function statusLabel(status, item) {
    if (item && item.pipeline_stage_label) return item.pipeline_stage_label;
    if (item && item.status_label) return item.status_label;
    if (!status) return 'Pending';
    if (status === 'uploading_to_cloud') return 'Uploading';
    if (status === 'finalizing') return 'OCR';
    return status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');
  }

  function isActiveStatus(status, item) {
    var stage = item && item.pipeline_stage;
    if (stage === 'uploading' || stage === 'ocr' || stage === 'parsing' || stage === 'mapping') return true;
    return status === 'pending'
      || status === 'queued'
      || status === 'uploading_to_cloud'
      || status === 'processing'
      || status === 'finalizing';
  }

  function isBusyItem(item) {
    if (!item) return false;
    return isActiveStatus(item.status, item)
      || item.parse_status === 'queued'
      || item.parse_status === 'processing'
      || (item.pipeline_stage && item.pipeline_stage !== 'completed' && item.pipeline_stage !== 'failed');
  }

  function selectedImportType() {
    var el = document.getElementById('ocr-import-type');
    var value = el ? String(el.value || '') : String(state.importType || '');
    state.importType = value;
    return value;
  }

  function importTypeDescription(type) {
    if (type === 'master_ca') {
      return 'Loads official CA records directly into Master Data. No sales mapping.';
    }
    if (type === 'sales_team') {
      return 'Maps employee-collected records against Master Data and adds mobiles/leads.';
    }
    return 'Select an import type before uploading.';
  }

  function formatBytes(bytes) {
    var n = Number(bytes) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return Math.round(n / 1024) + ' KB';
    return (Math.round((n / 1048576) * 10) / 10) + ' MB';
  }

  function formatDate(value) {
    if (!value) return '—';
    try {
      return new Date(value).toLocaleString();
    } catch (e) {
      return String(value);
    }
  }

  function canUseOcrImport() {
    if (window.CA_RBAC && typeof CA_RBAC.can === 'function') {
      return CA_RBAC.can('ocr', 'view')
        || CA_RBAC.can('ocr', 'upload')
        || CA_RBAC.can('ca_master', 'view')
        || CA_RBAC.can('bulk', 'view')
        || CA_RBAC.can('leads', 'view');
    }
    return true;
  }

  function buildPageShell() {
    return '' +
      '<div class="ocr-import-page" id="ocr-import-page">' +
        '<header class="ocr-import-page__header card">' +
          '<div>' +
            '<h1 class="text-page-title">Smart Import</h1>' +
            '<p class="ocr-import-page__subtitle">Upload PDF or image documents for Google Document AI. Choose Master CA Data for official bulk load, or Sales Team Data to map mobiles against Master.</p>' +
          '</div>' +
          '<div class="ocr-import-page__header-meta">' +
            '<span class="ocr-import-pill">Google Document AI</span>' +
            '<span class="text-caption text-slate-500">PDF · JPG · PNG · TIFF · Max ' + esc(String(maxFileMb())) + ' MB</span>' +
          '</div>' +
        '</header>' +
        '<div class="ocr-import-layout">' +
          '<section class="card ocr-import-upload">' +
            '<h2 class="text-card-heading mb-3">Upload Document</h2>' +
            '<div class="ocr-import-type-picker mb-3">' +
              '<label class="text-caption font-medium text-slate-700 block mb-1" for="ocr-import-type">Import Type <span class="text-rose-600">*</span></label>' +
              '<select id="ocr-import-type" class="input-field" required>' +
                '<option value="">Select import type…</option>' +
                '<option value="master_ca">Master CA Data</option>' +
                '<option value="sales_team">Sales Team Data</option>' +
              '</select>' +
              '<p class="text-caption text-slate-500 mt-2" id="ocr-import-type-help">' + esc(importTypeDescription('')) + '</p>' +
            '</div>' +
            '<label class="ocr-import-dropzone" data-ocr-import-dropzone>' +
              '<input type="file" class="sr-only" data-ocr-import-upload multiple accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff,application/pdf,image/jpeg,image/png,image/tiff" />' +
              '<i data-lucide="scan-text" class="h-8 w-8 text-brand"></i>' +
              '<p class="font-medium mt-2" data-ocr-import-drop-title>Drop a document here or click to browse</p>' +
              '<p class="text-caption text-slate-500 mt-1" data-ocr-import-drop-caption>Select import type first · then upload</p>' +
            '</label>' +
            '<p class="text-caption text-slate-500 mt-3" id="ocr-import-upload-hint">Master CA imports go straight into Master Data. Sales Team imports run mapping and add mobiles.</p>' +
            '<p class="text-caption text-rose-600 mt-2 hidden" id="ocr-import-upload-error" role="alert"></p>' +
          '</section>' +
          '<section class="card ocr-import-history">' +
            '<div class="ocr-import-history__toolbar">' +
              '<div class="ocr-import-history__filters">' +
                '<input type="search" id="ocr-import-search" class="input-field" placeholder="Search filename or firm…" />' +
                '<select id="ocr-import-status" class="input-field">' +
                  '<option value="">All statuses</option>' +
                  '<option value="pending">Pending</option>' +
                  '<option value="queued">Queued</option>' +
                  '<option value="uploading_to_cloud">Uploading to cloud</option>' +
                  '<option value="processing">Processing</option>' +
                  '<option value="finalizing">Finalizing</option>' +
                  '<option value="completed">Completed</option>' +
                  '<option value="failed">Failed</option>' +
                  '<option value="cancelled">Cancelled</option>' +
                '</select>' +
              '</div>' +
              '<button type="button" class="btn-secondary btn-sm" id="ocr-import-refresh"><i data-lucide="refresh-cw" class="h-4 w-4"></i> Refresh</button>' +
            '</div>' +
            '<div class="crm-table-container scrollbar-thin">' +
              '<table class="ca-table w-full ocr-import-table">' +
                '<thead><tr><th>Document</th><th>Status</th><th>Uploaded</th><th>Pages</th><th class="text-right">Actions</th></tr></thead>' +
                '<tbody id="ocr-import-tbody"></tbody>' +
              '</table>' +
            '</div>' +
            '<div id="ocr-import-pagination" class="ocr-import-pagination"></div>' +
          '</section>' +
        '</div>' +
        '<section class="card ocr-import-detail hidden" id="ocr-import-detail" role="dialog" aria-modal="false" aria-labelledby="ocr-import-detail-title">' +
          '<div class="ocr-import-detail__head">' +
            '<h2 class="text-card-heading" id="ocr-import-detail-title">OCR Preview</h2>' +
            '<button type="button" class="btn-secondary btn-sm" id="ocr-import-detail-close" aria-label="Close detail panel">Close</button>' +
          '</div>' +
          '<div id="ocr-import-detail-body" class="ocr-import-detail__body"></div>' +
        '</section>' +
        '<div id="ocr-import-confirm" class="ocr-import-confirm hidden" role="dialog" aria-modal="true" aria-labelledby="ocr-import-confirm-title">' +
          '<div class="ocr-import-confirm__backdrop" data-ocr-confirm-cancel></div>' +
          '<div class="ocr-import-confirm__panel card">' +
            '<h3 class="text-card-heading" id="ocr-import-confirm-title">Delete OCR document?</h3>' +
            '<p class="text-body text-slate-600 mt-2" id="ocr-import-confirm-message">This will remove the OCR record and its stored document. This action cannot be undone.</p>' +
            '<div class="ocr-import-confirm__actions">' +
              '<button type="button" class="btn-secondary btn-sm" data-ocr-confirm-cancel>Cancel</button>' +
              '<button type="button" class="btn-primary btn-sm ocr-import-confirm__delete" id="ocr-import-confirm-delete">Delete</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  function setUploadHint(message, isError) {
    var hint = document.getElementById('ocr-import-upload-hint');
    var err = document.getElementById('ocr-import-upload-error');
    if (err) {
      err.textContent = isError ? (message || '') : '';
      err.classList.toggle('hidden', !isError || !message);
    }
    if (hint && !isError && message) hint.textContent = message;
  }

  function actionButtons(item) {
    var can = item.can || {};
    var id = esc(item.id);
    var html = '<div class="ocr-import-actions" role="group" aria-label="Document actions">' +
      '<button type="button" class="ocr-import-action-btn" data-action="open-ocr" data-ocr-open="' + id + '" data-ocr-id="' + id + '"' +
        ' title="Open document" aria-label="Open document">' +
        '<i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>' +
      '</button>';

    if (can.retry) {
      html += '<button type="button" class="ocr-import-action-btn" data-action="retry-ocr" data-ocr-import-retry="' + id + '"' +
        ' title="Retry OCR" aria-label="Retry OCR"' +
        (state.retryingId === String(item.id) || state.retryingId === item.id ? ' disabled' : '') + '>' +
        '<i data-lucide="rotate-cw" class="h-4 w-4" aria-hidden="true"></i>' +
      '</button>';
    }

    if (can.delete) {
      html += '<button type="button" class="ocr-import-action-btn ocr-import-action-btn--danger" data-action="delete-ocr" data-ocr-import-delete="' + id + '"' +
        ' title="Delete document" aria-label="Delete document">' +
        '<i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>' +
      '</button>';
    } else if (isActiveStatus(item.status, item)) {
      html += '<button type="button" class="ocr-import-action-btn" disabled title="Processing in progress" aria-label="Processing in progress">' +
        '<i data-lucide="loader-2" class="h-4 w-4 animate-spin" aria-hidden="true"></i>' +
      '</button>';
    }

    html += '</div>';
    return html;
  }

  function renderRows(items) {
    var tbody = document.getElementById('ocr-import-tbody');
    if (!tbody) return;
    if (!items || !items.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-slate-500 py-6">No OCR documents uploaded yet.</td></tr>';
      return;
    }
    tbody.innerHTML = items.map(function (item) {
      var firm = item.firm_name ? esc(item.firm_name) : '<span class="text-slate-400">Library</span>';
      var mode = item.processing_mode_label ? ' · ' + esc(item.processing_mode_label) : '';
      return '<tr class="ocr-import-row' + (String(state.selectedId) === String(item.id) ? ' is-selected' : '') + '" data-ocr-row="' + esc(item.id) + '">' +
        '<td>' +
          '<strong class="block truncate" title="' + esc(item.original_filename) + '">' + esc(item.original_filename) + '</strong>' +
          '<span class="text-caption text-slate-500">' +
            esc(item.import_type_label || (item.import_type === 'master_ca' ? 'Master CA Data' : 'Sales Team Data')) +
            ' · ' + firm + ' · ' + esc(item.file_size_label || '') + mode +
            (item.parsed_firm_count != null && item.status === 'completed'
              ? ' · ' + esc(String(item.parsed_firm_count)) + ' firms'
              : '') +
          '</span>' +
        '</td>' +
        '<td>' +
          '<span class="ocr-status ' + statusClass(item.status, item) + '">' + esc(statusLabel(item.status, item)) + '</span>' +
          (item.processing_progress && isBusyItem(item)
            ? '<span class="block text-caption text-slate-500 mt-1">' + esc(item.processing_progress) + '</span>'
            : '') +
          (item.error_message && (item.status === 'failed' || item.pipeline_stage === 'failed')
            ? '<span class="block text-caption text-rose-600 mt-1">' + esc(item.error_message) + '</span>'
            : '') +
        '</td>' +
        '<td class="text-caption">' + esc(formatDate(item.created_at)) + '</td>' +
        '<td>' + esc(item.page_count != null ? String(item.page_count) : (item.total_pages != null ? String(item.total_pages) : '—')) + '</td>' +
        '<td class="text-right">' + actionButtons(item) + '</td>' +
      '</tr>';
    }).join('');
    if (typeof window.icons === 'function') window.icons();
  }

  function renderPagination(pagination) {
    var slot = document.getElementById('ocr-import-pagination');
    if (!slot) return;
    var page = pagination.current_page || 1;
    var last = pagination.last_page || 1;
    var total = pagination.total || 0;
    if (last <= 1) {
      slot.innerHTML = '<span class="text-caption text-slate-500" data-ocr-doc-count>' + total + ' document' + (total === 1 ? '' : 's') + '</span>';
      return;
    }
    slot.innerHTML =
      '<button type="button" class="btn-secondary btn-xs" data-ocr-page="prev"' + (page <= 1 ? ' disabled' : '') + '>Previous</button>' +
      '<span class="text-caption text-slate-600" data-ocr-doc-count>Page ' + page + ' / ' + last + ' · ' + total + '</span>' +
      '<button type="button" class="btn-secondary btn-xs" data-ocr-page="next"' + (page >= last ? ' disabled' : '') + '>Next</button>';
  }

  function queryString() {
    var qs = new URLSearchParams({
      page: String(state.page),
      per_page: String(state.perPage),
    });
    if (state.search) qs.set('search', state.search);
    if (state.status) qs.set('status', state.status);
    return qs.toString();
  }

  function unwrapItem(payload) {
    if (!payload) return null;
    if (payload.data && payload.data.id) return payload.data;
    if (payload.id) return payload;
    if (payload.data && payload.data.data && payload.data.data.id) return payload.data.data;
    return payload.data || null;
  }

  function loadList() {
    if (!canUseOcrImport()) {
      var blocked = document.getElementById('ocr-import-tbody');
      if (blocked) {
        blocked.innerHTML = '<tr><td colspan="5" class="text-center text-slate-500 py-4">You do not have permission to view OCR history.</td></tr>';
      }
      return Promise.resolve();
    }

    state.loading = true;
    var tbody = document.getElementById('ocr-import-tbody');
    if (tbody && !tbody.querySelector('[data-ocr-row]')) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-slate-500 py-4">Loading…</td></tr>';
    }

    return apiFetch('/ocr-documents?' + queryString()).then(function (body) {
      var data = body && body.data ? body.data : {};
      var items = Array.isArray(data.items) ? data.items : (Array.isArray(data.data) ? data.data : []);
      var pagination = data.pagination || {
        current_page: data.current_page || 1,
        last_page: data.last_page || 1,
        total: data.total != null ? data.total : items.length,
      };
      renderRows(items);
      renderPagination(pagination);
      schedulePoll(items);
      if (typeof window.icons === 'function') window.icons();
      return items;
    }).catch(function (err) {
      if (tbody) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-rose-600 py-4">' +
          esc(err.message || 'Unable to load OCR history') +
          ' <button type="button" class="btn-secondary btn-xs ml-2" id="ocr-import-retry-load">Refresh</button></td></tr>';
        var retry = document.getElementById('ocr-import-retry-load');
        if (retry) retry.addEventListener('click', function () { loadList(); });
      }
      throw err;
    }).finally(function () {
      state.loading = false;
    });
  }

  function schedulePoll(items) {
    clearPoll();
    var busyItems = (items || []).filter(isBusyItem);
    if (!busyItems.length) {
      state.pollStartedAt = null;
      return;
    }
    if (state.pollInFlight) return;
    if (!state.pollStartedAt) state.pollStartedAt = Date.now();

    var hasOnline = busyItems.some(function (item) {
      return item.processing_mode !== 'batch';
    });
    var elapsed = Date.now() - state.pollStartedAt;
    var delay = hasOnline
      ? (elapsed < 60000 ? 2000 : 8000)
      : 7000;

    state.pollTimer = window.setTimeout(function () {
      if (state.pollInFlight) return;
      state.pollInFlight = true;
      loadList().then(function (fresh) {
        if (state.selectedId) openDetail(state.selectedId, true);
        if (hasOnline && elapsed >= 60000 && (fresh || []).some(isBusyItem)) {
          setUploadHint('Processing is taking longer than expected. You may continue using the CRM.', false);
        }
      }).catch(function (err) {
        setUploadHint((err && err.message) || 'Unable to refresh OCR list from the server.', true);
      }).finally(function () {
        state.pollInFlight = false;
      });
    }, delay);
  }

  function clearPoll() {
    if (state.pollTimer) {
      window.clearTimeout(state.pollTimer);
      state.pollTimer = null;
    }
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  /** Browser→Laravel upload with real progress (XHR). OCR runs separately after this resolves. */
  function uploadFile(file, onProgress, forceReimport) {
    return new Promise(function (resolve, reject) {
      var formData = new FormData();
      formData.append('document', file);
      formData.append('import_type', selectedImportType());
      if (forceReimport) formData.append('force_reimport', '1');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', '/ocr-documents');
      xhr.withCredentials = true;
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken());
      if (xhr.upload && typeof onProgress === 'function') {
        xhr.upload.onprogress = function (event) {
          if (!event.lengthComputable) return;
          onProgress(Math.min(100, Math.round((event.loaded / event.total) * 100)), event.loaded, event.total);
        };
      }
      xhr.onload = function () {
        var body = {};
        try {
          body = xhr.responseText ? JSON.parse(xhr.responseText) : {};
        } catch (parseError) {
          reject(Object.assign(new Error('Something went wrong. Please try again.'), { status: xhr.status }));
          return;
        }
        if (xhr.status === 401) {
          window.location.href = '/login';
          reject(new Error('Session expired. Please sign in again.'));
          return;
        }
        if (xhr.status >= 200 && xhr.status < 300) {
          resolve(body);
          return;
        }
        var message = body.message || 'Unable to complete the request. Please try again.';
        if (xhr.status === 419) {
          message = 'The upload request expired. Please refresh the page and retry.';
        } else if (xhr.status === 413) {
          message = 'The file is too large for the server. Please use a smaller file or ask your host to raise upload limits.';
        } else if (xhr.status === 422 && body.errors) {
          var firstKey = Object.keys(body.errors)[0];
          if (firstKey && body.errors[firstKey] && body.errors[firstKey][0]) {
            message = body.errors[firstKey][0];
          }
        } else if (xhr.status === 403) {
          message = body.message || 'You do not have permission to upload OCR documents.';
        }
        reject(Object.assign(new Error(message), {
          status: xhr.status,
          errors: body.errors || null,
          duplicateFile: !!(body.errors && body.errors.duplicate_file),
        }));
      };
      xhr.onerror = function () {
        reject(new Error('Unable to reach the server. Please refresh the page and try again.'));
      };
      xhr.send(formData);
    });
  }

  function showDetailPanel() {
    var detail = document.getElementById('ocr-import-detail');
    if (!detail) return;
    detail.classList.remove('hidden');
    detail.classList.add('is-open');
    try {
      detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) {
      detail.scrollIntoView(true);
    }
  }

  function hideDetailPanel() {
    var detail = document.getElementById('ocr-import-detail');
    if (detail) {
      detail.classList.add('hidden');
      detail.classList.remove('is-open');
    }
    state.selectedId = null;
    state.detailDirty = false;
  }

  function closeDetailWithGuard() {
    if (state.detailDirty) {
      if (!window.confirm('You have unsaved text corrections. Close without saving?')) return;
    }
    hideDetailPanel();
  }

  function metaRow(label, value) {
    if (value == null || value === '') return '';
    return '<div class="ocr-import-detail__meta-row"><span class="text-caption text-slate-500">' + esc(label) + '</span><span>' + value + '</span></div>';
  }

  function firmField(label, value, fieldKey, lowFields) {
    if (value == null || value === '') return '';
    var low = Array.isArray(lowFields) && fieldKey && lowFields.indexOf(fieldKey) >= 0;
    return '<div class="ocr-firm-card__field' + (low ? ' ocr-firm-card__field--low-confidence' : '') + '">' +
      '<span class="ocr-firm-card__label">' + esc(label) + (low ? ' <span class="ocr-low-conf-tag">low confidence</span>' : '') + '</span>' +
      '<span class="ocr-firm-card__value">' + esc(value) + '</span></div>';
  }

  function matchStatusLabel(status) {
    if (status === 'imported') return 'Imported';
    if (status === 'updated_official') return 'Updated official record';
    if (status === 'duplicate') return 'Duplicate';
    if (status === 'auto_mapped') return 'Auto-updated';
    if (status === 'auto_created') return 'Auto-created';
    if (status === 'needs_review') return 'Needs review';
    if (status === 'conflict') return 'Conflict';
    if (status === 'rejected') return 'Rejected';
    if (status === 'failed') return 'Failed';
    if (status === 'pending' || !status) return 'Pending';
    return status || '';
  }

  function mappingStageIndex(item, importBatch) {
    var status = (item && item.status) || '';
    var parseStatus = (item && item.parse_status) || '';
    var pipeline = (item && item.pipeline_stage) || '';
    var progress = String((item && item.processing_progress) || '').toLowerCase();
    var isMaster = (item && item.import_type) === 'master_ca';
    var stage = (importBatch && importBatch.progress_stage) || '';

    if (pipeline === 'completed' || stage === 'completed' || (importBatch && importBatch.status === 'completed')
      || (status === 'completed' && parseStatus === 'completed' && progress.indexOf('completed') >= 0 && progress.indexOf('queued') < 0 && progress.indexOf('mapping') < 0 && progress.indexOf('importing') < 0 && progress.indexOf('validat') < 0)) {
      return 5;
    }
    if (isMaster) {
      if (pipeline === 'importing' || stage === 'importing' || progress.indexOf('importing') >= 0) return 4;
      if (pipeline === 'validating' || stage === 'validating' || progress.indexOf('validat') >= 0) return 3;
      if (parseStatus === 'processing' || parseStatus === 'queued' || pipeline === 'parsing') return 2;
      if (status === 'processing' || status === 'queued' || status === 'uploading_to_cloud' || status === 'finalizing' || pipeline === 'ocr' || pipeline === 'uploading') return 1;
      return 0;
    }
    if (pipeline === 'updating' || stage === 'creating' || stage === 'updating') return 4;
    if (pipeline === 'mapping' || stage === 'mapping' || progress.indexOf('mapping') >= 0 || progress.indexOf('queued for sales') >= 0) return 3;
    if (parseStatus === 'processing' || parseStatus === 'queued' || pipeline === 'parsing') return 2;
    if (status === 'processing' || status === 'queued' || status === 'uploading_to_cloud' || status === 'finalizing' || pipeline === 'ocr' || pipeline === 'uploading') return 1;
    if (status === 'completed') return 5;
    return 0;
  }

  function renderMappingProgressDashboard(item, mapping, importBatch) {
    var isMaster = (item && item.import_type) === 'master_ca';
    var masterImport = (item && item.structured_data && item.structured_data.master_import) || {};
    var stages = isMaster
      ? ['Uploading', 'OCR', 'Parsing', 'Validating', 'Importing', 'Completed']
      : ['Uploading', 'OCR', 'Parsing', 'Mapping', 'Updating/Creating', 'Completed'];
    var active = mappingStageIndex(item, importBatch);
    var created = isMaster
      ? ((importBatch && importBatch.created_count != null) ? importBatch.created_count : (masterImport.imported || 0))
      : ((importBatch && importBatch.created_count != null) ? importBatch.created_count : (mapping.auto_created || 0));
    var updated = isMaster
      ? ((importBatch && importBatch.updated_count != null) ? importBatch.updated_count : (masterImport.updated || 0))
      : ((importBatch && importBatch.updated_count != null) ? importBatch.updated_count : (mapping.auto_updated || 0));
    var review = isMaster
      ? ((importBatch && importBatch.review_count != null) ? importBatch.review_count : (masterImport.review || 0))
      : ((importBatch && importBatch.review_count != null) ? importBatch.review_count : (mapping.needs_review || 0));
    var conflict = isMaster
      ? ((importBatch && importBatch.duplicate_count != null) ? importBatch.duplicate_count : (masterImport.duplicates || 0))
      : ((importBatch && importBatch.conflict_count != null) ? importBatch.conflict_count : (mapping.conflicts || 0));
    var failed = (importBatch && importBatch.failed_count != null)
      ? importBatch.failed_count
      : (isMaster ? (masterImport.failed || 0) : 0);
    var total = (importBatch && importBatch.total_records != null)
      ? importBatch.total_records
      : (isMaster ? (masterImport.processed || 0) : (mapping.processed || 0));
    var pct = importBatch && importBatch.progress_pct != null ? importBatch.progress_pct : (active >= 5 ? 100 : null);
    var html = '<div class="ocr-mapping-dashboard mt-3">';
    html += '<p class="text-caption text-slate-600 mb-2"><strong>' + esc(item.import_type_label || (isMaster ? 'Master CA Data' : 'Sales Team Data')) + '</strong></p>';
    html += '<div class="ocr-mapping-dashboard__stages">' + stages.map(function (label, idx) {
      var cls = 'ocr-mapping-stage';
      if (idx < active) cls += ' is-done';
      else if (idx === active) cls += ' is-active';
      return '<span class="' + cls + '">' + esc(label) + '</span>';
    }).join('') + '</div>';
    if (pct != null) {
      html += '<div class="ocr-mapping-dashboard__bar"><span style="width:' + Math.max(0, Math.min(100, pct)) + '%"></span></div>';
    }
    html += '<p class="text-caption text-slate-500 mt-2">Total ' + esc(String(total)) +
      (isMaster
        ? (' · ' + esc(String(created)) + ' imported · ' + esc(String(updated)) + ' updated · ' +
          esc(String(conflict)) + ' duplicate · ' + esc(String(review)) + ' review · ' + esc(String(failed)) + ' failed')
        : (' · ' + esc(String(created)) + ' created · ' + esc(String(updated)) + ' updated · ' +
          esc(String(review)) + ' review · ' + esc(String(conflict)) + ' conflict · ' + esc(String(failed)) + ' failed')) +
      '</p>';
    if (importBatch && importBatch.rollbackable && importBatch.id) {
      html += '<button type="button" class="btn-secondary btn-sm mt-2" data-ocr-rollback-batch="' +
        esc(String(importBatch.id)) + '">Rollback this import</button>';
    }
    html += '</div>';
    return html;
  }

  function firmMatchesFilter(firm, filter) {
    if (!filter || filter === 'all') return true;
    var matchStatus = firm.match_status || '';
    var review = firm.review_status || 'pending';
    if (filter === 'auto_created') return matchStatus === 'auto_created';
    if (filter === 'auto_updated') return matchStatus === 'auto_mapped';
    if (filter === 'needs_review') return matchStatus === 'needs_review' || (!matchStatus && review === 'pending');
    if (filter === 'conflict') return matchStatus === 'conflict';
    if (filter === 'failed') return matchStatus === 'failed' || matchStatus === 'rejected' || review === 'rejected';
    return true;
  }

  function renderFirmCard(firm, can) {
    var members = Array.isArray(firm.members) ? firm.members : [];
    var review = firm.review_status || 'pending';
    var matchStatus = firm.match_status || '';
    var caId = firm.crm_ca_id || firm.ca_id || null;
    var isApproved = review === 'approved' || matchStatus === 'auto_mapped' || matchStatus === 'auto_created';
    var isRejected = review === 'rejected' || matchStatus === 'rejected';
    var needsManual = !isApproved && !isRejected && (matchStatus === 'needs_review' || matchStatus === 'conflict' || matchStatus === '' || matchStatus === 'pending');
    var lowFields = Array.isArray(firm.low_confidence_fields) ? firm.low_confidence_fields : [];
    var partnersHtml = members.length
      ? '<ul class="ocr-firm-card__partners">' + members.map(function (m) {
          return '<li><i data-lucide="check" class="h-3.5 w-3.5 text-emerald-600" aria-hidden="true"></i> ' +
            esc(m.ca_name || '—') +
            (m.role ? ' <span class="text-caption text-slate-500">(' + esc(m.role) + ')</span>' : '') +
            (m.membership_no ? ' <span class="text-caption text-slate-500">· ' + esc(m.membership_no) + '</span>' : '') +
          '</li>';
        }).join('') + '</ul>'
      : '<p class="text-caption text-slate-500 mt-2">No partners detected</p>';

    var actionsHtml = '';
    if (can.update && needsManual) {
      actionsHtml = '<div class="ocr-firm-card__actions">' +
        '<label class="ocr-firm-card__select"><input type="checkbox" data-ocr-firm-select value="' + esc(firm.id) + '" /> Select</label>' +
        '<button type="button" class="btn-secondary btn-xs" data-ocr-firm-review="approved" data-ocr-firm-id="' + esc(firm.id) + '">' +
          (matchStatus === 'conflict' ? 'Confirm match' : 'Approve') +
        '</button>' +
        '<button type="button" class="btn-secondary btn-xs text-rose-600" data-ocr-firm-review="rejected" data-ocr-firm-id="' + esc(firm.id) + '">Reject</button>' +
      '</div>';
    } else if (isApproved && caId) {
      actionsHtml = '<div class="ocr-firm-card__actions">' +
        '<span class="text-caption text-emerald-700">' +
          esc(matchStatusLabel(matchStatus) || 'Linked') + ' · CA #' + esc(caId) +
          (firm.match_reason ? ' · ' + esc(firm.match_reason) : '') +
        '</span>' +
      '</div>';
    }

    return '<article class="ocr-firm-card" data-ocr-firm-id="' + esc(firm.id) + '" data-ocr-match-status="' + esc(matchStatus || review) + '">' +
      '<div class="ocr-firm-card__top">' +
        '<div>' +
          '<h4 class="ocr-firm-card__title' + (lowFields.indexOf('firm_name') >= 0 ? ' ocr-firm-card__title--low-confidence' : '') + '">' +
            esc(firm.firm_name || 'Untitled firm') +
          '</h4>' +
          (firm.city ? '<p class="text-caption text-slate-500">' + esc(firm.city) + (firm.state ? ', ' + esc(firm.state) : '') +
            (firm.page_number != null ? ' · p.' + esc(firm.page_number) : '') + '</p>' : '') +
        '</div>' +
        '<span class="ocr-firm-card__badge ocr-firm-card__badge--' + esc(matchStatus || review || 'pending') + '">' +
          esc(matchStatusLabel(matchStatus) || review) +
        '</span>' +
      '</div>' +
      '<div class="ocr-firm-card__grid">' +
        firmField('Firm Type', firm.firm_type) +
        firmField('Address', firm.address, 'address', lowFields) +
        firmField('PIN Code', firm.pincode, 'pincode', lowFields) +
        firmField('FRN', firm.frn, 'frn', lowFields) +
        firmField('GST', firm.gst_no, 'gst_no', lowFields) +
        firmField('PAN', firm.pan_no, 'pan_no', lowFields) +
        firmField('Phone', firm.phone, 'phone', lowFields) +
        firmField('Email', firm.email, 'email', lowFields) +
        firmField('Website', firm.website) +
        (firm.match_confidence != null ? firmField('Match confidence', Math.round(Number(firm.match_confidence) * 100) + '%') : '') +
        (caId ? firmField('Master CA ID', caId) : '') +
      '</div>' +
      '<div class="mt-3">' +
        '<p class="ocr-firm-card__label">Partners</p>' +
        partnersHtml +
      '</div>' +
      actionsHtml +
    '</article>';
  }

  function renderDetailBody(item) {
    var can = item.can || {};
    var id = esc(item.id);
    var textValue = item.corrected_text != null && item.corrected_text !== ''
      ? item.corrected_text
      : (item.extracted_text || '');
    var uploadedBy = item.uploaded_by && item.uploaded_by.name ? item.uploaded_by.name : '—';
    var previewHref = '/ocr-documents/' + id + '/preview';
    var downloadHref = '/ocr-documents/' + id + '/download';

    var fileActions = '';
    if (can.download) {
      fileActions =
        '<a class="btn-secondary btn-sm" href="' + previewHref + '" target="_blank" rel="noopener noreferrer">View Original</a>' +
        '<a class="btn-secondary btn-sm" href="' + downloadHref + '" rel="noopener noreferrer">Download</a>';
    }

    var html =
      '<div class="ocr-import-detail__meta">' +
        '<span class="ocr-status ' + statusClass(item.status, item) + '">' + esc(statusLabel(item.status, item)) + '</span>' +
        (item.processing_mode_label ? '<span class="text-caption">' + esc(item.processing_mode_label) + '</span>' : '') +
      '</div>' +
      '<div class="ocr-import-detail__grid mt-3">' +
        metaRow('Filename', esc(item.original_filename || '—')) +
        metaRow('File type', esc(item.mime_type || '—')) +
        metaRow('File size', esc(item.file_size_label || formatBytes(item.file_size))) +
        metaRow('Uploaded by', esc(uploadedBy)) +
        metaRow('Uploaded', esc(formatDate(item.created_at))) +
        metaRow('Processed', esc(formatDate(item.processed_at))) +
        metaRow('Pages', esc(item.page_count != null ? String(item.page_count) : (item.total_pages != null ? String(item.total_pages) : '—'))) +
        (item.average_confidence != null ? metaRow('Confidence', esc(String(item.average_confidence))) : '') +
      '</div>';

    if (item.batch_notice) {
      html += '<p class="ocr-panel__processing mt-3">' + esc(item.batch_notice) + '</p>';
    }
    if (item.processing_progress && isBusyItem(item)) {
      html += '<p class="text-caption text-slate-500 mt-2">Progress: ' + esc(item.processing_progress) + '</p>';
    }
    if (isActiveStatus(item.status, item) && item.pipeline_stage !== 'failed') {
      html += '<p class="ocr-panel__processing mt-3"><i data-lucide="loader-2" class="h-3.5 w-3.5 animate-spin"></i> ' +
        esc(statusLabel(item.status, item)) + '…</p>';
      html += '<div class="ocr-panel__actions mt-4">' + fileActions + '</div>';
      return html;
    }

    if (item.status === 'failed' || item.pipeline_stage === 'failed') {
      html += item.error_message
        ? '<p class="ocr-panel__error mt-3" role="alert">' + esc(item.error_message) + '</p>'
        : '<p class="ocr-panel__error mt-3" role="alert">OCR processing failed.</p>';
      html += '<div class="ocr-panel__actions mt-4">' +
        fileActions +
        (can.retry ? '<button type="button" class="btn-secondary btn-sm" data-ocr-import-retry="' + id + '"' + (state.retryingId == item.id ? ' disabled' : '') + '>Retry</button>' : '') +
        (can.delete ? '<button type="button" class="btn-secondary btn-sm text-rose-600" data-ocr-import-delete="' + id + '">Delete</button>' : '') +
      '</div>';
      return html;
    }

    if (item.status === 'completed') {
      var firms = Array.isArray(item.parsed_firms) ? item.parsed_firms : [];
      var parseStatus = item.parse_status || '';
      var firmCount = item.parsed_firm_count != null ? item.parsed_firm_count : firms.length;

      html += '<div class="mt-4 ocr-structured-results">';
      html += '<div class="ocr-structured-results__head">' +
        '<h3 class="text-card-heading">Structured Firms</h3>' +
        '<span class="text-caption text-slate-500">' +
          (parseStatus === 'queued' || parseStatus === 'processing'
            ? 'Structuring in progress…'
            : esc(String(firmCount)) + ' firm' + (firmCount === 1 ? '' : 's') + ' detected') +
        '</span>' +
      '</div>';

      if (parseStatus === 'queued' || parseStatus === 'processing') {
        html += '<p class="ocr-panel__processing mt-3"><i data-lucide="loader-2" class="h-3.5 w-3.5 animate-spin"></i> Converting OCR text into structured CA records…</p>';
      } else if (firms.length) {
        var mapping = (item.structured_data && item.structured_data.mapping) || {};
        var completeness = (item.structured_data && item.structured_data.parsed && item.structured_data.parsed.completeness) || {};
        var quality = (item.structured_data && item.structured_data.parsed && item.structured_data.parsed.quality_report) || {};
        if (quality.total_rows_detected != null || quality.total_firms_parsed != null) {
          html += '<div class="ocr-quality-report mt-2">' +
            '<p class="text-caption text-slate-600"><strong>OCR Quality Report</strong> · ' +
            'Pages ' + esc(String(quality.total_pages != null ? quality.total_pages : '—')) +
            ' (' + esc(String(quality.pages_with_text != null ? quality.pages_with_text : '—')) + ' with text) · ' +
            'Rows ' + esc(String(quality.total_rows_detected != null ? quality.total_rows_detected : '—')) +
            ' · Parsed ' + esc(String(quality.total_firms_parsed != null ? quality.total_firms_parsed : '—')) +
            ' · Missing ' + esc(String(quality.missing_row_count != null ? quality.missing_row_count : 0)) +
            ' · Dup rows ' + esc(String(quality.duplicate_row_count != null ? quality.duplicate_row_count : 0)) +
            ' · Accuracy ' + esc(String(quality.parsing_accuracy != null ? quality.parsing_accuracy + '%' : '—')) +
            (quality.ocr_confidence != null ? ' · OCR conf ' + esc(String(Math.round(Number(quality.ocr_confidence) * 100) / 100)) : '') +
            '</p></div>';
        }
        if (completeness.warnings && completeness.warnings.length) {
          html += '<div class="ocr-completeness-warn mt-2">' + completeness.warnings.map(function (w) {
            return '<p class="text-caption text-amber-700">⚠ ' + esc(w) + '</p>';
          }).join('') + '</div>';
        }
        var importBatch = item.import_batch || (item.structured_data && item.structured_data.import_batch) || null;
        if (importBatch || mapping.processed != null) {
          html += renderMappingProgressDashboard(item, mapping, importBatch);
        }
        html += '<div class="ocr-firm-bulk-toolbar mt-3">' +
          '<select id="ocr-firm-filter" class="input-field input-field--sm" data-ocr-firm-filter>' +
            '<option value="all">All firms</option>' +
            '<option value="auto_created">Auto-created</option>' +
            '<option value="auto_updated">Auto-updated</option>' +
            '<option value="needs_review">Needs review</option>' +
            '<option value="conflict">Conflict</option>' +
            '<option value="failed">Rejected / failed</option>' +
          '</select>' +
          (can.update
            ? '<button type="button" class="btn-primary btn-sm" data-ocr-approve-safe="' + id + '">Approve All Safe</button>' +
              '<button type="button" class="btn-secondary btn-sm" data-ocr-reject-selected="' + id + '">Reject selected</button>' +
              '<button type="button" class="btn-secondary btn-sm" data-ocr-retry-mapping="' + id + '">Retry mapping</button>'
            : '') +
        '</div>';
        html += '<div class="ocr-firm-cards" data-ocr-firm-cards>' + firms.map(function (firm) {
          return renderFirmCard(firm, can);
        }).join('') + '</div>';
      } else if (parseStatus === 'failed') {
        var parseErr = item.parse_error
          || (item.structured_data && item.structured_data.parsed && item.structured_data.parsed.error)
          || null;
        var errMsg = (parseErr && parseErr.message)
          ? String(parseErr.message)
          : 'Structured parsing failed. You can retry structuring or review the raw text.';
        var errCode = (parseErr && parseErr.code) ? String(parseErr.code) : '';
        html += '<div class="ocr-panel__error mt-3">' +
          '<p>' + esc(errMsg) + '</p>' +
          (errCode ? '<p class="text-caption mt-1">Error code: ' + esc(errCode) + '</p>' : '') +
        '</div>';
      } else {
        html += '<p class="text-caption text-slate-500 mt-3">No firms could be detected automatically. Review the optional raw text below.</p>';
      }

      html += '<div class="ocr-panel__editor-actions mt-4">' +
        fileActions +
        (can.update ? '<button type="button" class="btn-secondary btn-sm" data-ocr-import-reparse="' + id + '">Re-structure</button>' : '') +
        '<button type="button" class="btn-secondary btn-sm" data-ocr-toggle-raw>Show raw OCR text</button>' +
      '</div>';

      html +=
        '<div class="mt-4 ocr-raw-text-panel hidden" id="ocr-import-raw-panel">' +
          '<label class="form-label" for="ocr-import-text">Raw OCR Text (advanced)</label>' +
          '<textarea id="ocr-import-text" class="input-field ocr-panel__textarea" rows="10">' + esc(textValue) + '</textarea>' +
          '<div class="ocr-panel__editor-actions mt-3">' +
            '<button type="button" class="btn-secondary btn-sm" data-ocr-import-copy>Copy Text</button>' +
            (can.update ? '<button type="button" class="btn-primary btn-sm" data-ocr-import-save="' + id + '">Save Corrections</button>' : '') +
          '</div>' +
          '<p class="text-caption text-slate-500 mt-2">Raw text is kept for audit only. Primary review uses structured firm cards above.</p>' +
        '</div>';

      html += '</div>';
    }

    html += '<div class="ocr-panel__actions mt-4">' +
      (can.retry ? '<button type="button" class="btn-secondary btn-sm" data-ocr-import-retry="' + id + '"' + (state.retryingId == item.id ? ' disabled' : '') + '>Retry</button>' : '') +
      (can.delete ? '<button type="button" class="btn-secondary btn-sm text-rose-600" data-ocr-import-delete="' + id + '">Delete</button>' : '') +
    '</div>';

    return html;
  }

  function openDetail(id, soft) {
    if (!id) return;
    state.selectedId = id;
    var detail = document.getElementById('ocr-import-detail');
    var body = document.getElementById('ocr-import-detail-body');
    var title = document.getElementById('ocr-import-detail-title');
    if (!detail || !body) {
      toast('OCR detail panel is not available. Please refresh the page.', 'error');
      return;
    }

    var openBtn = document.querySelector('[data-ocr-open="' + String(id).replace(/"/g, '') + '"]');
    if (openBtn && !soft) openBtn.disabled = true;

    if (!soft) {
      state.detailDirty = false;
      body.innerHTML = '<div class="ocr-import-detail__skeleton" aria-busy="true">' +
        '<p class="text-caption text-slate-500"><i data-lucide="loader-2" class="h-4 w-4 animate-spin inline"></i> Loading document…</p>' +
      '</div>';
      showDetailPanel();
      if (typeof window.icons === 'function') window.icons();
    }

    var requestId = String(id);
    state.openInFlight = requestId;

    apiFetch('/ocr-documents/' + encodeURIComponent(id) + '?include_text=1').then(function (res) {
      if (state.openInFlight !== requestId) return;
      var item = unwrapItem(res) || {};
      if (title) title.textContent = item.original_filename || 'OCR Preview';
      body.innerHTML = renderDetailBody(item);
      showDetailPanel();
      var textarea = document.getElementById('ocr-import-text');
      if (textarea) {
        textarea.addEventListener('input', function () {
          state.detailDirty = true;
        });
      }
      if (typeof window.icons === 'function') window.icons();
      document.querySelectorAll('.ocr-import-row').forEach(function (row) {
        row.classList.toggle('is-selected', String(row.getAttribute('data-ocr-row')) === String(id));
      });
    }).catch(function (err) {
      if (state.openInFlight !== requestId) return;
      toast(err.message || 'Unable to load document', 'error');
      if (!soft) {
        body.innerHTML = '<p class="ocr-panel__error">' + esc(err.message || 'Unable to load document') + '</p>';
        showDetailPanel();
      }
    }).finally(function () {
      if (openBtn) openBtn.disabled = false;
      if (state.openInFlight === requestId) state.openInFlight = null;
    });
  }

  function showDeleteConfirm(id) {
    if (!id || state.deletingId) return;
    var modal = document.getElementById('ocr-import-confirm');
    var deleteBtn = document.getElementById('ocr-import-confirm-delete');
    if (!modal || !deleteBtn) {
      if (!window.confirm('Delete OCR document?\n\nThis will remove the OCR record and its stored document. This action cannot be undone.')) {
        return;
      }
      performDelete(id);
      return;
    }
    modal.classList.remove('hidden');
    deleteBtn.setAttribute('data-ocr-pending-delete', String(id));
    deleteBtn.disabled = false;
    deleteBtn.focus();
  }

  function hideDeleteConfirm() {
    var modal = document.getElementById('ocr-import-confirm');
    var deleteBtn = document.getElementById('ocr-import-confirm-delete');
    if (modal) modal.classList.add('hidden');
    if (deleteBtn) {
      deleteBtn.removeAttribute('data-ocr-pending-delete');
      deleteBtn.disabled = false;
    }
  }

  function performDelete(id) {
    if (!id || state.deletingId) return;
    state.deletingId = String(id);
    var deleteBtn = document.getElementById('ocr-import-confirm-delete');
    if (deleteBtn) deleteBtn.disabled = true;

    apiFetch('/ocr-documents/' + encodeURIComponent(id), {
      method: 'DELETE',
      headers: { Accept: 'application/json' },
    }).then(function (body) {
      toast((body && body.message) || 'OCR document deleted successfully.', 'success');
      hideDeleteConfirm();
      if (String(state.selectedId) === String(id)) {
        hideDetailPanel();
      }
      return loadList();
    }).catch(function (err) {
      var message = err.message || 'Delete failed.';
      if (err.status === 403) message = err.message || 'You do not have permission to delete this document.';
      if (err.status === 404) message = 'Document not found.';
      if (err.status === 409) message = err.message || 'This OCR document is still processing and cannot be deleted yet.';
      if (err.status === 419) message = 'Session expired. Please refresh the page and try again.';
      toast(message, 'error');
      if (deleteBtn) deleteBtn.disabled = false;
    }).finally(function () {
      state.deletingId = null;
    });
  }

  function handleRetry(retryId, retryBtn) {
    if (!retryId || state.retryingId) return;
    state.retryingId = retryId;
    if (retryBtn) retryBtn.disabled = true;
    apiFetch('/ocr-documents/' + encodeURIComponent(retryId) + '/retry', { method: 'POST' })
      .then(function (body) {
        toast((body && body.message) || 'OCR retry queued.', 'success');
        return loadList().then(function () { openDetail(retryId); });
      })
      .catch(function (err) { toast(err.message || 'Retry failed.', 'error'); })
      .finally(function () {
        state.retryingId = null;
        if (retryBtn) retryBtn.disabled = false;
      });
  }

  function handleDelegatedClick(e) {
    var pageRoot = document.getElementById('ocr-import-page');
    if (!pageRoot || !pageRoot.contains(e.target)) {
      var confirmRoot = document.getElementById('ocr-import-confirm');
      if (!confirmRoot || !confirmRoot.contains(e.target)) return;
    }

    var cancelBtn = e.target.closest('[data-ocr-confirm-cancel]');
    if (cancelBtn) {
      e.preventDefault();
      hideDeleteConfirm();
      return;
    }

    var confirmDelete = e.target.closest('#ocr-import-confirm-delete');
    if (confirmDelete) {
      e.preventDefault();
      var pending = confirmDelete.getAttribute('data-ocr-pending-delete');
      if (pending) performDelete(pending);
      return;
    }

    var openBtn = e.target.closest('[data-action="open-ocr"], [data-ocr-open]');
    if (openBtn && pageRoot && pageRoot.contains(openBtn)) {
      e.preventDefault();
      e.stopPropagation();
      var openId = openBtn.getAttribute('data-ocr-open') || openBtn.getAttribute('data-ocr-id');
      openDetail(openId);
      return;
    }

    var pageBtn = e.target.closest('[data-ocr-page]');
    if (pageBtn && pageRoot && pageRoot.contains(pageBtn)) {
      if (pageBtn.getAttribute('data-ocr-page') === 'prev' && state.page > 1) {
        state.page -= 1;
        loadList();
      }
      if (pageBtn.getAttribute('data-ocr-page') === 'next') {
        state.page += 1;
        loadList();
      }
      return;
    }

    var retryBtn = e.target.closest('[data-ocr-import-retry]');
    if (retryBtn && pageRoot && pageRoot.contains(retryBtn)) {
      e.preventDefault();
      handleRetry(retryBtn.getAttribute('data-ocr-import-retry'), retryBtn);
      return;
    }

    var deleteBtn = e.target.closest('[data-ocr-import-delete]');
    if (deleteBtn && pageRoot && pageRoot.contains(deleteBtn)) {
      e.preventDefault();
      showDeleteConfirm(deleteBtn.getAttribute('data-ocr-import-delete'));
      return;
    }

    var toggleRaw = e.target.closest('[data-ocr-toggle-raw]');
    if (toggleRaw && pageRoot && pageRoot.contains(toggleRaw)) {
      e.preventDefault();
      var rawPanel = document.getElementById('ocr-import-raw-panel');
      if (rawPanel) {
        var willShow = rawPanel.classList.contains('hidden');
        rawPanel.classList.toggle('hidden', !willShow);
        toggleRaw.textContent = willShow ? 'Hide raw OCR text' : 'Show raw OCR text';
      }
      return;
    }

    var firmFilter = e.target.closest('[data-ocr-firm-filter]');
    if (firmFilter && pageRoot && pageRoot.contains(firmFilter) && e.type === 'change') {
      var filterVal = firmFilter.value || 'all';
      pageRoot.querySelectorAll('.ocr-firm-card').forEach(function (card) {
        var firmId = card.getAttribute('data-ocr-firm-id');
        var fakeFirm = {
          match_status: card.getAttribute('data-ocr-match-status'),
          review_status: card.getAttribute('data-ocr-match-status'),
        };
        card.classList.toggle('hidden', !firmMatchesFilter(fakeFirm, filterVal));
        if (filterVal === 'needs_review' && fakeFirm.match_status === 'pending') card.classList.remove('hidden');
      });
      return;
    }

    var approveSafeBtn = e.target.closest('[data-ocr-approve-safe]');
    if (approveSafeBtn && pageRoot && pageRoot.contains(approveSafeBtn)) {
      e.preventDefault();
      var approveDocId = approveSafeBtn.getAttribute('data-ocr-approve-safe');
      approveSafeBtn.disabled = true;
      apiFetch('/ocr-documents/' + encodeURIComponent(approveDocId) + '/approve-safe', { method: 'POST' })
        .then(function (body) {
          toast((body && body.message) || 'Safe records approved.', 'success');
          openDetail(approveDocId, true);
        })
        .catch(function (err) { toast(err.message || 'Approve All Safe failed.', 'error'); })
        .finally(function () { approveSafeBtn.disabled = false; });
      return;
    }

    var rejectSelectedBtn = e.target.closest('[data-ocr-reject-selected]');
    if (rejectSelectedBtn && pageRoot && pageRoot.contains(rejectSelectedBtn)) {
      e.preventDefault();
      var rejectDocId = rejectSelectedBtn.getAttribute('data-ocr-reject-selected');
      var selectedIds = Array.prototype.map.call(
        pageRoot.querySelectorAll('[data-ocr-firm-select]:checked'),
        function (el) { return parseInt(el.value, 10); },
      ).filter(Boolean);
      if (!selectedIds.length) {
        toast('Select at least one firm to reject.', 'warning');
        return;
      }
      rejectSelectedBtn.disabled = true;
      apiFetch('/ocr-documents/' + encodeURIComponent(rejectDocId) + '/reject-selected', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ firm_ids: selectedIds }),
      }).then(function (body) {
        toast((body && body.message) || 'Selected firms rejected.', 'success');
        openDetail(rejectDocId, true);
      }).catch(function (err) {
        toast(err.message || 'Reject selected failed.', 'error');
      }).finally(function () { rejectSelectedBtn.disabled = false; });
      return;
    }

    var retryMapBtn = e.target.closest('[data-ocr-retry-mapping]');
    if (retryMapBtn && pageRoot && pageRoot.contains(retryMapBtn)) {
      e.preventDefault();
      var retryDocId = retryMapBtn.getAttribute('data-ocr-retry-mapping');
      retryMapBtn.disabled = true;
      apiFetch('/ocr-documents/' + encodeURIComponent(retryDocId) + '/retry-mapping', { method: 'POST' })
        .then(function (body) {
          toast((body && body.message) || 'Mapping retry completed.', 'success');
          openDetail(retryDocId, true);
        })
        .catch(function (err) { toast(err.message || 'Retry mapping failed.', 'error'); })
        .finally(function () { retryMapBtn.disabled = false; });
      return;
    }

    var rollbackBtn = e.target.closest('[data-ocr-rollback-batch]');
    if (rollbackBtn && pageRoot && pageRoot.contains(rollbackBtn)) {
      e.preventDefault();
      var batchId = rollbackBtn.getAttribute('data-ocr-rollback-batch');
      if (!batchId || !window.confirm('Rollback this import? Auto-created masters without follow-ups will be removed and updates restored.')) {
        return;
      }
      rollbackBtn.disabled = true;
      apiFetch('/master-import-batches/' + encodeURIComponent(batchId) + '/rollback', { method: 'POST' })
        .then(function (body) {
          toast((body && body.message) || 'Import rolled back.', 'success');
          if (state.selectedId) openDetail(state.selectedId, true);
          loadList().catch(function () {});
        })
        .catch(function (err) { toast(err.message || 'Rollback failed.', 'error'); })
        .finally(function () { rollbackBtn.disabled = false; });
      return;
    }

    var reparseBtn = e.target.closest('[data-ocr-import-reparse]');
    if (reparseBtn && pageRoot && pageRoot.contains(reparseBtn)) {
      e.preventDefault();
      var reparseId = reparseBtn.getAttribute('data-ocr-import-reparse');
      reparseBtn.disabled = true;
      apiFetch('/ocr-documents/' + encodeURIComponent(reparseId) + '/reparse', { method: 'POST' })
        .then(function (body) {
          toast((body && body.message) || 'OCR document restructured successfully.', 'success');
          openDetail(reparseId);
        })
        .catch(function (err) { toast(err.message || 'Re-structure failed.', 'error'); })
        .finally(function () { reparseBtn.disabled = false; });
      return;
    }

    var reviewBtn = e.target.closest('[data-ocr-firm-review]');
    if (reviewBtn && pageRoot && pageRoot.contains(reviewBtn)) {
      e.preventDefault();
      var firmId = reviewBtn.getAttribute('data-ocr-firm-id');
      var status = reviewBtn.getAttribute('data-ocr-firm-review');
      var docId = state.selectedId;
      if (!firmId || !docId) return;
      reviewBtn.disabled = true;
      var siblingActions = reviewBtn.closest('.ocr-firm-card__actions');
      if (siblingActions) {
        siblingActions.querySelectorAll('button').forEach(function (btn) { btn.disabled = true; });
      }
      apiFetch('/ocr-documents/' + encodeURIComponent(docId) + '/firms/' + encodeURIComponent(firmId) + '/review', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ review_status: status }),
      }).then(function (body) {
        var data = (body && body.data) || {};
        var msg = (body && body.message) || (status === 'approved' ? 'Firm approved.' : 'Firm rejected.');
        if (status === 'approved' && data.ca_id) {
          msg = msg + ' (CA #' + data.ca_id + ')';
        }
        toast(msg, 'success');
        openDetail(docId, true);
      }).catch(function (err) {
        toast(err.message || 'Unable to update review status.', 'error');
        if (siblingActions) {
          siblingActions.querySelectorAll('button').forEach(function (btn) { btn.disabled = false; });
        } else {
          reviewBtn.disabled = false;
        }
      });
      return;
    }

    var saveBtn = e.target.closest('[data-ocr-import-save]');
    if (saveBtn && pageRoot && pageRoot.contains(saveBtn)) {
      e.preventDefault();
      var textarea = document.getElementById('ocr-import-text');
      saveBtn.disabled = true;
      apiFetch('/ocr-documents/' + encodeURIComponent(saveBtn.getAttribute('data-ocr-import-save')) + '/text', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ corrected_text: textarea ? textarea.value : '' }),
      }).then(function () {
        state.detailDirty = false;
        toast('Corrected OCR text saved.', 'success');
      }).catch(function (err) {
        toast(err.message || 'Save failed.', 'error');
      }).finally(function () { saveBtn.disabled = false; });
      return;
    }

    var copyBtn = e.target.closest('[data-ocr-import-copy]');
    if (copyBtn && pageRoot && pageRoot.contains(copyBtn)) {
      e.preventDefault();
      var textEl = document.getElementById('ocr-import-text');
      var plain = textEl ? textEl.value : '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(plain || '').then(function () {
          toast('Text copied.', 'success');
        }).catch(function () {
          toast('Unable to copy text.', 'error');
        });
      } else if (textEl) {
        textEl.select();
        try {
          document.execCommand('copy');
          toast('Text copied.', 'success');
        } catch (copyErr) {
          toast('Unable to copy text.', 'error');
        }
      }
    }
  }

  function handleDelegatedKeydown(e) {
    if (e.key !== 'Escape') return;
    var confirm = document.getElementById('ocr-import-confirm');
    if (confirm && !confirm.classList.contains('hidden')) {
      hideDeleteConfirm();
      return;
    }
    var detail = document.getElementById('ocr-import-detail');
    if (detail && !detail.classList.contains('hidden')) {
      closeDetailWithGuard();
    }
  }

  function ensureGlobalDelegation() {
    if (window.__ocrImportDelegationBound) return;
    window.__ocrImportDelegationBound = true;
    document.addEventListener('click', handleDelegatedClick);
    document.addEventListener('change', handleDelegatedClick);
    document.addEventListener('keydown', handleDelegatedKeydown);
  }

  function bindPage(root) {
    if (!root) return;
    ensureGlobalDelegation();
    root._ocrImportBound = true;

    var uploadInput = root.querySelector('[data-ocr-import-upload]');
    var dropzone = root.querySelector('[data-ocr-import-dropzone]');
    var searchInput = document.getElementById('ocr-import-search');
    var statusSelect = document.getElementById('ocr-import-status');
    var importTypeSelect = document.getElementById('ocr-import-type');
    var importTypeHelp = document.getElementById('ocr-import-type-help');
    var refreshBtn = document.getElementById('ocr-import-refresh');
    var closeBtn = document.getElementById('ocr-import-detail-close');
    var dropTitle = root.querySelector('[data-ocr-import-drop-title]');
    var dropCaption = root.querySelector('[data-ocr-import-drop-caption]');

    if (root._ocrImportUploadBound) return;
    root._ocrImportUploadBound = true;

    if (importTypeSelect && !importTypeSelect._ocrBound) {
      importTypeSelect._ocrBound = true;
      importTypeSelect.addEventListener('change', function () {
        state.importType = String(importTypeSelect.value || '');
        if (importTypeHelp) importTypeHelp.textContent = importTypeDescription(state.importType);
        setUploadHint(importTypeDescription(state.importType), false);
      });
    }

    function resetUploadUi(caption) {
      state.uploading = false;
      if (dropTitle) dropTitle.textContent = 'Drop a document here or click to browse';
      if (dropCaption) dropCaption.textContent = caption || 'Queued for OCR · browser stays responsive';
      if (uploadInput) {
        uploadInput.disabled = false;
        uploadInput.value = '';
      }
    }

    function handleFiles(files) {
      if (!files || !files.length || state.uploading) return;
      var importType = selectedImportType();
      if (importType !== 'master_ca' && importType !== 'sales_team') {
        setUploadHint('Select Import Type: Master CA Data or Sales Team Data before uploading.', true);
        toast('Select an import type before uploading.', 'error');
        if (uploadInput) uploadInput.value = '';
        return;
      }
      // Upload every selected file sequentially — each must create its own ocr_documents row.
      var queue = Array.prototype.slice.call(files);
      state.uploading = true;
      if (uploadInput) uploadInput.disabled = true;

      function onUploadProgress(file, percent) {
        if (percent >= 100) {
          if (dropTitle) dropTitle.textContent = 'Saving document…';
          setUploadHint('Upload finished. Saving “' + file.name + '” on the server…', false);
          return;
        }
        if (dropTitle) dropTitle.textContent = 'Uploading… ' + percent + '%';
        setUploadHint('Uploading “' + file.name + '”… ' + percent + '%', false);
      }

      function uploadOne(file, forceReimport) {
        if (dropTitle) dropTitle.textContent = 'Uploading… 0%';
        if (dropCaption) dropCaption.textContent = file.name + ' · ' + formatBytes(file.size);
        setUploadHint('Uploading “' + file.name + '” (' + formatBytes(file.size) + ')…', false);
        return uploadFile(file, function (percent) { onUploadProgress(file, percent); }, forceReimport)
          .catch(function (err) {
            if (!forceReimport && err.duplicateFile && window.confirm(err.message + '\n\nRe-import this file anyway?')) {
              return uploadOne(file, true);
            }
            throw err;
          });
      }

      function uploadNext(index, createdIds) {
        if (index >= queue.length) {
          resetUploadUi(queue.length > 1
            ? queue.length + ' documents uploaded · OCR running in background'
            : 'Queued for OCR · browser stays responsive');
          toast(queue.length > 1
            ? queue.length + ' documents uploaded. OCR processing has started.'
            : 'Document uploaded successfully. OCR processing has started.', 'success');
          state.page = 1;
          if (createdIds.length) {
            state.selectedId = createdIds[createdIds.length - 1];
            state.pollStartedAt = Date.now();
          }
          // Always refresh from GET /ocr-documents — never keep client-only rows.
          return loadList().then(function (items) {
            schedulePoll(items || []);
            if (state.selectedId) openDetail(state.selectedId);
          }).catch(function (err) {
            setUploadHint((err && err.message) || 'Upload saved, but the document list could not be refreshed.', true);
          });
        }

        var file = queue[index];
        return uploadOne(file, false).then(function (body) {
          var item = unwrapItem(body) || {};
          if (!item.id) {
            throw new Error('Upload succeeded but the server did not return an OCR document id.');
          }
          createdIds.push(item.id);
          return uploadNext(index + 1, createdIds);
        });
      }

      uploadNext(0, []).catch(function (err) {
        var message = err.message || 'Upload failed.';
        if (err.status === 419) {
          message = 'The upload request expired. Please refresh the page and retry.';
        } else if (err.status === 413) {
          message = 'The file is too large for the server. Please use a smaller file or ask your host to raise upload limits.';
        } else if (err.status === 403) {
          message = err.message || 'You do not have permission to upload OCR documents.';
        } else if (err.status === 422 && err.errors && err.errors.document && err.errors.document[0]) {
          message = err.errors.document[0];
        } else if (err.status === 401) {
          message = 'Your session expired. Please sign in again.';
        }
        resetUploadUi();
        setUploadHint(message, true);
        toast(message, 'error');
        loadList().catch(function () { /* list may still be valid */ });
      }).finally(function () {
        if (state.uploading) resetUploadUi();
      });
    }

    if (uploadInput) {
      uploadInput.addEventListener('change', function () { handleFiles(uploadInput.files); });
    }
    if (dropzone) {
      dropzone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropzone.classList.add('is-dragover');
      });
      dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('is-dragover'); });
      dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropzone.classList.remove('is-dragover');
        handleFiles(e.dataTransfer && e.dataTransfer.files);
      });
    }

    var searchTimer = null;
    if (searchInput && !searchInput._ocrBound) {
      searchInput._ocrBound = true;
      searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
          state.search = searchInput.value || '';
          state.page = 1;
          loadList();
        }, 250);
      });
    }
    if (statusSelect && !statusSelect._ocrBound) {
      statusSelect._ocrBound = true;
      statusSelect.addEventListener('change', function () {
        state.status = statusSelect.value || '';
        state.page = 1;
        loadList();
      });
    }
    if (refreshBtn && !refreshBtn._ocrBound) {
      refreshBtn._ocrBound = true;
      refreshBtn.addEventListener('click', function () { loadList(); });
    }
    if (closeBtn && !closeBtn._ocrBound) {
      closeBtn._ocrBound = true;
      closeBtn.addEventListener('click', function () {
        closeDetailWithGuard();
      });
    }
  }

  function mount(container) {
    if (!container) return;
    clearPoll();
    state.page = 1;
    state.search = '';
    state.status = '';
    state.selectedId = null;
    state.detailDirty = false;
    state.mountedHost = container;
    container._ocrImportUploadBound = false;
    container.innerHTML = buildPageShell();
    bindPage(container);
    loadList().catch(function () { /* error row already rendered */ });
    if (typeof window.icons === 'function') window.icons();
  }

  function mountIntoSecondary() {
    var host = document.getElementById('cam-secondary-ocr');
    if (!host) return;
    ensureGlobalDelegation();
    /* Remount when host content was wiped by Master Data re-paint, even if flag remains. */
    if (host.querySelector('#ocr-import-page') && host._ocrImportBound) {
      bindPage(host);
      loadList().catch(function () { /* keep last good rows */ });
      return;
    }
    mount(host);
  }

  window.CrmOcrImportPage = {
    mount: mount,
    mountIntoSecondary: mountIntoSecondary,
    refresh: loadList,
    clearPolling: clearPoll,
    openDocument: openDetail,
  };
})();
