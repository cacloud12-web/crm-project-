/* CA Cloud Desk — Document OCR panel for Lead / CA detail drawer */
(function () {
  'use strict';

  var pollTimers = {};

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function statusClass(status) {
    if (status === 'completed') return 'ocr-status--completed';
    if (status === 'processing') return 'ocr-status--processing';
    if (status === 'failed') return 'ocr-status--failed';
    return 'ocr-status--pending';
  }

  function statusLabel(status) {
    if (!status) return 'Pending';
    return status.charAt(0).toUpperCase() + status.slice(1);
  }

  function maxFileMb() {
    return 10;
  }

  function supportedFormatsLabel() {
    return 'PDF, JPG, PNG, TIFF · Max ' + maxFileMb() + ' MB';
  }

  function renderEmpty() {
    return '<div class="ocr-panel__empty">No OCR documents yet.</div>';
  }

  function renderItem(item) {
    var can = item.can || {};
    var showEditor = item.status === 'completed';
    var textValue = item.corrected_text || item.extracted_text || '';
    return '<article class="ocr-panel__item" data-ocr-id="' + esc(item.id) + '">' +
      '<div class="ocr-panel__item-head">' +
        '<div class="ocr-panel__item-title">' +
          '<i data-lucide="file-text" class="h-4 w-4"></i>' +
          '<span class="ocr-panel__filename" title="' + esc(item.original_filename) + '">' + esc(item.original_filename) + '</span>' +
        '</div>' +
        '<span class="ocr-status ' + statusClass(item.status) + '">' + esc(statusLabel(item.status)) + '</span>' +
      '</div>' +
      '<div class="ocr-panel__meta">' +
        '<span>' + esc(item.file_size_label || '') + '</span>' +
        '<span>·</span>' +
        '<span>' + esc(item.uploaded_by && item.uploaded_by.name ? item.uploaded_by.name : 'System') + '</span>' +
        '<span>·</span>' +
        '<span>' + esc(item.created_at ? new Date(item.created_at).toLocaleString() : '') + '</span>' +
      '</div>' +
      (item.status === 'processing' || item.status === 'pending'
        ? '<p class="ocr-panel__processing"><i data-lucide="loader-2" class="h-3.5 w-3.5 animate-spin"></i> Processing document…</p>'
        : '') +
      (item.status === 'failed' && item.error_message
        ? '<p class="ocr-panel__error">' + esc(item.error_message) + '</p>'
        : '') +
      (item.text_preview && item.status === 'completed'
        ? '<p class="ocr-panel__preview">' + esc(item.text_preview) + '</p>'
        : '') +
      '<div class="ocr-panel__actions">' +
        (can.download ? '<a class="btn-secondary btn-sm" href="/ocr-documents/' + esc(item.id) + '/original" target="_blank" rel="noopener noreferrer">View Original</a>' : '') +
        (can.retry ? '<button type="button" class="btn-secondary btn-sm" data-ocr-retry="' + esc(item.id) + '">Retry</button>' : '') +
        (showEditor ? '<button type="button" class="btn-secondary btn-sm" data-ocr-toggle="' + esc(item.id) + '">Review Text</button>' : '') +
        (can.delete ? '<button type="button" class="btn-secondary btn-sm text-rose-600" data-ocr-delete="' + esc(item.id) + '">Delete</button>' : '') +
      '</div>' +
      (showEditor
        ? '<div class="ocr-panel__editor hidden" id="ocr-editor-' + esc(item.id) + '">' +
            '<label class="form-label" for="ocr-text-' + esc(item.id) + '">Corrected Text</label>' +
            '<textarea id="ocr-text-' + esc(item.id) + '" class="input-field ocr-panel__textarea" rows="6">' + esc(textValue) + '</textarea>' +
            '<div class="ocr-panel__editor-actions">' +
              '<button type="button" class="btn-secondary btn-sm" data-ocr-copy="' + esc(item.id) + '">Copy Text</button>' +
              '<button type="button" class="btn-primary btn-sm" data-ocr-save="' + esc(item.id) + '">Save Corrections</button>' +
            '</div>' +
            '<p class="text-caption text-slate-500 mt-2">Review extracted text here. CRM fields are not updated automatically.</p>' +
          '</div>'
        : '') +
    '</article>';
  }

  function renderPanel(caId, payload) {
    var items = (payload && payload.items) ? payload.items : [];
    var pagination = payload && payload.pagination ? payload.pagination : {};
    return '<section class="ocr-panel card mt-4" id="lead-ocr-panel" data-ca-id="' + esc(caId) + '">' +
      '<div class="ocr-panel__head">' +
        '<div>' +
          '<h4 class="ocr-panel__title">Document OCR</h4>' +
          '<p class="ocr-panel__subtitle">' + esc(supportedFormatsLabel()) + '</p>' +
        '</div>' +
        '<label class="btn-secondary btn-sm ocr-panel__upload-btn">' +
          '<i data-lucide="upload" class="h-4 w-4"></i> Upload Document' +
          '<input type="file" class="sr-only" data-ocr-upload accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff,application/pdf,image/jpeg,image/png,image/tiff" />' +
        '</label>' +
      '</div>' +
      '<div class="ocr-panel__dropzone" data-ocr-dropzone>' +
        '<i data-lucide="scan-text" class="h-5 w-5 text-slate-400"></i>' +
        '<p>Drag and drop a document here, or use Upload Document.</p>' +
      '</div>' +
      '<div class="ocr-panel__list" id="lead-ocr-list">' +
        (items.length ? items.map(renderItem).join('') : renderEmpty()) +
      '</div>' +
      (pagination.total > (pagination.per_page || 10)
        ? '<p class="ocr-panel__count text-caption text-slate-500 mt-2">' + esc(String(pagination.total)) + ' documents</p>'
        : '') +
    '</section>';
  }

  function fetchList(caId) {
    return window.apiFetch('/ocr-documents?ca_id=' + encodeURIComponent(caId) + '&per_page=10&include_text=1');
  }

  function mountIntoDrawer(caId, containerId) {
    var host = document.getElementById(containerId || 'lead-ocr-panel-host');
    if (!host) return Promise.resolve();
    host.innerHTML = '<div class="ocr-panel__loading"><i data-lucide="loader-2" class="h-4 w-4 animate-spin"></i> Loading OCR documents…</div>';
    if (typeof window.iconsIn === 'function') window.iconsIn(host);
    return fetchList(caId).then(function (body) {
      host.innerHTML = renderPanel(caId, body.data || {});
      bindPanel(host, caId);
      if (typeof window.iconsIn === 'function') window.iconsIn(host);
      schedulePolling(caId);
    }).catch(function () {
      host.innerHTML = '<section class="ocr-panel card mt-4"><p class="text-caption text-rose-500">Unable to load OCR documents.</p></section>';
    });
  }

  function schedulePolling(caId) {
    clearPolling(caId);
    var host = document.getElementById('lead-ocr-panel-host');
    if (!host || !host.querySelector('.ocr-status--pending, .ocr-status--processing')) return;
    pollTimers[caId] = window.setTimeout(function () {
      mountIntoDrawer(caId).finally(function () {});
    }, 4000);
  }

  function clearPolling(caId) {
    if (pollTimers[caId]) {
      window.clearTimeout(pollTimers[caId]);
      delete pollTimers[caId];
    }
  }

  function uploadFile(caId, file) {
    var formData = new FormData();
    formData.append('ca_id', String(caId));
    formData.append('document', file);
    return window.apiFetch('/ocr-documents', {
      method: 'POST',
      body: formData,
      headers: { 'Accept': 'application/json' },
    });
  }

  function bindPanel(host, caId) {
    var uploadInput = host.querySelector('[data-ocr-upload]');
    var dropzone = host.querySelector('[data-ocr-dropzone]');

    function handleFiles(files) {
      if (!files || !files.length) return;
      var file = files[0];
      uploadInput.disabled = true;
      uploadFile(caId, file).then(function () {
        if (typeof window.toast === 'function') window.toast('Document uploaded for OCR processing.', 'success');
        return mountIntoDrawer(caId);
      }).catch(function (err) {
        if (typeof window.toast === 'function') window.toast(err.message || 'Upload failed.', 'error');
      }).finally(function () {
        uploadInput.disabled = false;
        uploadInput.value = '';
      });
    }

    if (uploadInput) {
      uploadInput.addEventListener('change', function () {
        handleFiles(uploadInput.files);
      });
    }

    if (dropzone) {
      dropzone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropzone.classList.add('is-dragover');
      });
      dropzone.addEventListener('dragleave', function () {
        dropzone.classList.remove('is-dragover');
      });
      dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropzone.classList.remove('is-dragover');
        handleFiles(e.dataTransfer && e.dataTransfer.files);
      });
    }

    host.addEventListener('click', function (e) {
      var retryBtn = e.target.closest('[data-ocr-retry]');
      if (retryBtn) {
        e.preventDefault();
        retryBtn.disabled = true;
        window.apiFetch('/ocr-documents/' + encodeURIComponent(retryBtn.getAttribute('data-ocr-retry')) + '/retry', { method: 'POST' })
          .then(function () {
            if (typeof window.toast === 'function') window.toast('OCR retry queued.', 'success');
            return mountIntoDrawer(caId);
          })
          .catch(function (err) {
            if (typeof window.toast === 'function') window.toast(err.message || 'Retry failed.', 'error');
          })
          .finally(function () { retryBtn.disabled = false; });
        return;
      }

      var deleteBtn = e.target.closest('[data-ocr-delete]');
      if (deleteBtn) {
        e.preventDefault();
        if (!window.confirm('Delete this OCR document?')) return;
        deleteBtn.disabled = true;
        window.apiFetch('/ocr-documents/' + encodeURIComponent(deleteBtn.getAttribute('data-ocr-delete')), { method: 'DELETE' })
          .then(function () {
            if (typeof window.toast === 'function') window.toast('OCR document deleted.', 'success');
            return mountIntoDrawer(caId);
          })
          .catch(function (err) {
            if (typeof window.toast === 'function') window.toast(err.message || 'Delete failed.', 'error');
          })
          .finally(function () { deleteBtn.disabled = false; });
        return;
      }

      var toggleBtn = e.target.closest('[data-ocr-toggle]');
      if (toggleBtn) {
        var editor = document.getElementById('ocr-editor-' + toggleBtn.getAttribute('data-ocr-toggle'));
        if (editor) editor.classList.toggle('hidden');
        return;
      }

      var copyBtn = e.target.closest('[data-ocr-copy]');
      if (copyBtn) {
        var textarea = document.getElementById('ocr-text-' + copyBtn.getAttribute('data-ocr-copy'));
        if (textarea && navigator.clipboard) {
          navigator.clipboard.writeText(textarea.value || '').then(function () {
            if (typeof window.toast === 'function') window.toast('Text copied.', 'success');
          });
        }
        return;
      }

      var saveBtn = e.target.closest('[data-ocr-save]');
      if (saveBtn) {
        e.preventDefault();
        var id = saveBtn.getAttribute('data-ocr-save');
        var field = document.getElementById('ocr-text-' + id);
        saveBtn.disabled = true;
        window.apiFetch('/ocr-documents/' + encodeURIComponent(id) + '/text', {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ corrected_text: field ? field.value : '' }),
        }).then(function () {
          if (typeof window.toast === 'function') window.toast('Corrected text saved.', 'success');
          return mountIntoDrawer(caId);
        }).catch(function (err) {
          if (typeof window.toast === 'function') window.toast(err.message || 'Save failed.', 'error');
        }).finally(function () { saveBtn.disabled = false; });
      }
    });
  }

  window.CrmOcrPanel = {
    mountIntoDrawer: mountIntoDrawer,
    clearPolling: clearPolling,
  };
})();
