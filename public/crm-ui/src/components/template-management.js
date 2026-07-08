/* global window, document, lucide */
(function () {
  'use strict';

  var BODY_MAX = 1024;

  var CHANNELS = {
    email: {
      rootId: 'settings-email-templates-page',
      listPath: '/email-templates',
      typeLabel: 'Email',
      hasSubject: true,
      bodyMax: 10000,
      saveActiveLabel: 'Save Template',
      saveSubmitLabel: 'Save Template',
    },
    whatsapp: {
      rootId: 'settings-whatsapp-templates-page',
      listPath: '/message-templates/whatsapp',
      typeLabel: 'WhatsApp',
      hasSubject: false,
      bodyMax: BODY_MAX,
      saveActiveLabel: 'Save & Submit for WhatsApp',
      saveSubmitLabel: 'Save & Submit for WhatsApp',
    },
  };

  function apiFetch(url, opts) {
    if (window.CA_CRM && typeof window.CA_CRM.apiFetch === 'function') {
      return window.CA_CRM.apiFetch(url, opts);
    }
    return Promise.reject(new Error('API client not ready'));
  }

  function toast(msg, type) {
    if (window.showToast) window.showToast(msg, type);
    else if (window.CA_CRM && window.CA_CRM.toast) window.CA_CRM.toast(msg, type);
  }

  function escapeHtml(value) {
    if (window.CA_CRM && window.CA_CRM.escapeHtml) return window.CA_CRM.escapeHtml(value);
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function unwrapList(body) {
    if (window.CA_LISTING_SEARCH) {
      var listing = window.CA_LISTING_SEARCH.unwrapListingBody(body);
      if (listing.items) return listing.items;
    }
    if (body && body.data) {
      if (Array.isArray(body.data.items)) return body.data.items;
      if (Array.isArray(body.data.data)) return body.data.data;
      if (Array.isArray(body.data)) return body.data;
    }
    if (Array.isArray(body)) return body;
    return [];
  }

  function leadId(lead) {
    return lead && (lead.ca_id != null ? lead.ca_id : lead.id);
  }

  function leadPreviewLabel(lead) {
    if (!lead) return 'Lead';
    var ca = String(lead.ca_name || '').trim();
    var firm = String(lead.firm_name || '').trim();
    if (ca && firm) return ca + ' · ' + firm;
    return ca || firm || ('Lead #' + leadId(lead));
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/'/g, '&#39;');
  }

  function canManage() {
    var role = (window.__CRM_USER__ || {}).role || '';
    return ['super_admin', 'admin', 'manager'].indexOf(role) >= 0;
  }

  function statusBadge(status) {
    var s = (status || 'draft').toLowerCase();
    if (s === 'active') return '<span class="badge-success">Active</span>';
    if (s === 'disabled') return '<span class="badge-neutral">Disabled</span>';
    return '<span class="badge-warning">Draft</span>';
  }

  function formatDate(iso) {
    if (!iso) return '—';
    try {
      return new Date(iso).toLocaleString();
    } catch (e) {
      return iso;
    }
  }

  function extractVariables(text) {
    var found = {};
    String(text || '').replace(/\{\{[A-Z0-9_]+\}\}/g, function (m) {
      found[m] = true;
      return m;
    });
    return Object.keys(found);
  }

  function plainTextLength(html) {
    var div = document.createElement('div');
    div.innerHTML = html || '';
    return (div.textContent || div.innerText || '').length;
  }

  function getEditorPlainText(editor) {
    if (!editor) return '';
    return editor.innerText || editor.textContent || '';
  }

  function setEditorPlainText(editor, text) {
    if (!editor) return;
    editor.textContent = text || '';
  }

  function wrapSelection(editor, before, after) {
    editor.focus();
    var sel = window.getSelection();
    if (!sel || !sel.rangeCount) return;
    var range = sel.getRangeAt(0);
    var selected = range.toString();
    var node = document.createTextNode(before + selected + after);
    range.deleteContents();
    range.insertNode(node);
    range.setStart(node, before.length);
    range.setEnd(node, before.length + selected.length);
    sel.removeAllRanges();
    sel.addRange(range);
  }

  function initChannel(channelKey) {
    var cfg = CHANNELS[channelKey];
    var root = document.getElementById(cfg.rootId);
    if (!root || root._tmBound) return;
    root._tmBound = true;

    var state = {
      view: 'list',
      page: 1,
      perPage: 15,
      sort: 'updated_at',
      dir: 'desc',
      filters: { search: '', category: '', publish_status: '' },
      items: [],
      pagination: { total: 0, last_page: 1 },
      catalog: { groups: {}, categories: [], publish_statuses: ['draft', 'active', 'disabled'] },
      editingId: null,
      editing: null,
    };

    function mount() {
      root.innerHTML =
        '<div class="crm-tm-shell" data-tm-view="list">' +
          '<div class="crm-tm-list-view" id="crm-tm-list-' + channelKey + '"></div>' +
          '<div class="crm-tm-editor-view hidden" id="crm-tm-editor-' + channelKey + '"></div>' +
        '</div>' +
        '<div id="crm-tm-preview-modal-' + channelKey + '" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40">' +
          '<div class="card p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">' +
            '<div class="flex items-start justify-between gap-4">' +
              '<div><h2 class="text-card-heading text-slate-800">Template Preview</h2>' +
              '<p class="text-body text-slate-500 mt-1" id="crm-tm-preview-title-' + channelKey + '"></p></div>' +
              '<button type="button" class="btn-ghost btn-sm" data-tm-preview-close="' + channelKey + '"><i data-lucide="x" class="h-4 w-4"></i></button>' +
            '</div>' +
            '<div class="mt-4"><label class="text-caption text-slate-500" for="crm-tm-preview-lead-' + channelKey + '">Preview with lead</label>' +
            '<select id="crm-tm-preview-lead-' + channelKey + '" class="input-field mt-1"><option value="">Select lead…</option></select></div>' +
            '<div id="crm-tm-preview-subject-wrap-' + channelKey + '" class="mt-4 hidden"><p class="text-caption text-slate-500">Subject</p><p id="crm-tm-preview-subject-' + channelKey + '" class="text-body font-medium text-slate-800 mt-1"></p></div>' +
            '<div class="mt-4"><p class="text-caption text-slate-500">Message</p><div id="crm-tm-preview-body-' + channelKey + '" class="crm-tm-preview-body mt-2"></div></div>' +
            '<div class="mt-4 flex justify-end"><button type="button" class="btn-primary" id="crm-tm-preview-run-' + channelKey + '">Generate Preview</button></div>' +
          '</div>' +
        '</div>';

      renderListShell();
      loadCatalog().then(function () {
        loadList();
      });
      bindGlobalHandlers();
      if (window.lucide) lucide.createIcons();
    }

    function renderListShell() {
      var listRoot = document.getElementById('crm-tm-list-' + channelKey);
      if (!listRoot) return;
      var createBtn = canManage()
        ? '<button type="button" class="btn-primary" id="crm-tm-create-' + channelKey + '"><i data-lucide="plus" class="h-4 w-4"></i> Create Template</button>'
        : '';
      var categoryOpts = ['<option value="">All categories</option>']
        .concat((state.catalog.categories || []).map(function (c) {
          return '<option value="' + escapeAttr(c) + '">' + escapeHtml(c) + '</option>';
        })).join('');
      var statusOpts = ['<option value="">All statuses</option>']
        .concat((state.catalog.publish_statuses || []).map(function (s) {
          return '<option value="' + escapeAttr(s) + '">' + escapeHtml(s.charAt(0).toUpperCase() + s.slice(1)) + '</option>';
        })).join('');

      listRoot.innerHTML =
        '<header class="page-hero page-hero--standard crm-tm-hero">' +
          '<div><h1 class="text-page-title">' + cfg.typeLabel + ' Templates</h1>' +
          '<p class="text-body text-slate-500">Create reusable ' + cfg.typeLabel.toLowerCase() + ' templates with dynamic variables for Communication campaigns.</p></div>' +
          '<div class="crm-tm-hero-actions">' + createBtn + '</div>' +
        '</header>' +
        '<div class="crm-listing-filter-bar card mb-4">' +
          '<div class="crm-listing-filter-grid">' +
            '<div class="crm-listing-filter-cell"><label class="crm-listing-filter-label" for="crm-tm-search-' + channelKey + '">SEARCH</label>' +
            '<input type="search" id="crm-tm-search-' + channelKey + '" class="crm-col-filter-input crm-listing-filter-input" placeholder="Search templates" /></div>' +
            '<div class="crm-listing-filter-cell"><label class="crm-listing-filter-label" for="crm-tm-cat-' + channelKey + '">CATEGORY</label>' +
            '<select id="crm-tm-cat-' + channelKey + '" class="crm-col-filter-input crm-listing-filter-input">' + categoryOpts + '</select></div>' +
            '<div class="crm-listing-filter-cell"><label class="crm-listing-filter-label" for="crm-tm-status-' + channelKey + '">STATUS</label>' +
            '<select id="crm-tm-status-' + channelKey + '" class="crm-col-filter-input crm-listing-filter-input">' + statusOpts + '</select></div>' +
          '</div>' +
        '</div>' +
        '<div class="crm-table-card card">' +
          '<div class="table-scroll-container crm-table-container scrollbar-thin">' +
            '<table class="crm-table ca-table ca-table--enterprise w-full">' +
              '<thead><tr>' +
                '<th>Title</th><th>Type</th><th>Category</th><th>Status</th><th>Created By</th><th>Last Updated</th><th class="text-right">Actions</th>' +
              '</tr></thead>' +
              '<tbody id="crm-tm-tbody-' + channelKey + '"><tr><td colspan="7" class="text-center text-slate-500 p-6">Loading…</td></tr></tbody>' +
            '</table>' +
          '</div>' +
          '<div class="crm-table-footer" id="crm-tm-pagination-' + channelKey + '"></div>' +
        '</div>';
    }

    function renderEditor(template) {
      var editorRoot = document.getElementById('crm-tm-editor-' + channelKey);
      var listRoot = document.getElementById('crm-tm-list-' + channelKey);
      if (!editorRoot || !listRoot) return;

      state.editing = template || null;
      state.editingId = template ? template.id : null;
      listRoot.classList.add('hidden');
      editorRoot.classList.remove('hidden');

      var isEdit = !!template;
      var title = isEdit ? 'Edit Template' : 'Create Template';
      var categoryOpts = (state.catalog.categories || []).map(function (c) {
        var sel = (template && template.category === c) ? ' selected' : '';
        return '<option value="' + escapeAttr(c) + '"' + sel + '>' + escapeHtml(c) + '</option>';
      }).join('');

      var chipsHtml = Object.keys(state.catalog.groups || {}).map(function (group) {
        var chips = (state.catalog.groups[group] || []).map(function (item) {
          return '<button type="button" class="crm-tm-var-chip" data-tm-insert-var="' + escapeAttr(item.key) + '" title="' + escapeAttr(item.key) + '">' + escapeHtml(item.label) + '</button>';
        }).join('');
        return '<div class="crm-tm-var-group"><p class="crm-tm-var-group-label">' + escapeHtml(group) + '</p><div class="crm-tm-var-chips">' + chips + '</div></div>';
      }).join('');

      var subjectField = cfg.hasSubject
        ? '<div class="crm-tm-field"><label class="crm-tm-label" for="crm-tm-subject-' + channelKey + '">Subject <span class="crm-tm-req">*</span></label>' +
          '<input type="text" id="crm-tm-subject-' + channelKey + '" class="input-field" maxlength="255" value="' + escapeAttr(template ? template.subject || '' : '') + '" placeholder="Email subject line" /></div>'
        : '';

      var actionBtns = canManage()
        ? '<button type="button" class="btn-secondary" data-tm-save-draft="' + channelKey + '">Save Draft</button>' +
          '<button type="button" class="btn-primary" data-tm-save-active="' + channelKey + '">' + escapeHtml(cfg.saveActiveLabel) + '</button>' +
          '<button type="button" class="btn-ghost" data-tm-cancel="' + channelKey + '">Cancel</button>'
        : '<button type="button" class="btn-ghost" data-tm-cancel="' + channelKey + '">Back</button>';

      editorRoot.innerHTML =
        '<div class="card crm-tm-editor-card">' +
          '<header class="crm-tm-editor-head"><h1 class="text-page-title">' + title + '</h1></header>' +
          '<div class="crm-tm-editor-grid">' +
            '<div class="crm-tm-field"><label class="crm-tm-label" for="crm-tm-name-' + channelKey + '">Title <span class="crm-tm-req">*</span></label>' +
            '<input type="text" id="crm-tm-name-' + channelKey + '" class="input-field" maxlength="120" ' + (canManage() ? '' : 'readonly') +
            ' value="' + escapeAttr(template ? template.name || template.title || '' : '') + '" placeholder="Template display name" /></div>' +
            '<div class="crm-tm-field"><label class="crm-tm-label" for="crm-tm-category-' + channelKey + '">Category <span class="crm-tm-req">*</span></label>' +
            '<select id="crm-tm-category-' + channelKey + '" class="input-field"' + (canManage() ? '' : ' disabled') + '>' + categoryOpts + '</select></div>' +
            subjectField +
            '<div class="crm-tm-field crm-tm-field--full"><label class="crm-tm-label" for="crm-tm-header-' + channelKey + '">Header <span class="crm-tm-hint">(max 60 characters)</span></label>' +
            '<input type="text" id="crm-tm-header-' + channelKey + '" class="input-field" maxlength="120" ' + (canManage() ? '' : 'readonly') +
            ' value="' + escapeAttr(template ? template.header || '{{COMPANY_NAME}}' : '{{COMPANY_NAME}}') + '" />' +
            '<p class="crm-tm-help" id="crm-tm-header-help-' + channelKey + '"></p></div>' +
            '<div class="crm-tm-field crm-tm-field--full"><p class="crm-tm-label">Body variables</p>' + chipsHtml + '</div>' +
            '<div class="crm-tm-field crm-tm-field--full"><label class="crm-tm-label">Body <span class="crm-tm-req">*</span></label>' +
              '<div class="crm-tm-rich-editor">' +
                '<div class="crm-tm-rich-toolbar">' +
                  '<button type="button" class="crm-tm-rich-btn" data-tm-fmt="bold" title="Bold"><strong>B</strong></button>' +
                  '<button type="button" class="crm-tm-rich-btn" data-tm-fmt="italic" title="Italic"><em>I</em></button>' +
                  '<button type="button" class="crm-tm-rich-btn" data-tm-fmt="strike" title="Strikethrough"><s>S</s></button>' +
                '</div>' +
                '<div id="crm-tm-body-' + channelKey + '" class="crm-tm-rich-area" contenteditable="' + (canManage() ? 'true' : 'false') + '"></div>' +
                '<div class="crm-tm-rich-footer">' +
                  '<span id="crm-tm-body-count-' + channelKey + '">0 / ' + cfg.bodyMax + ' characters</span>' +
                  '<span class="crm-tm-format-hints">Bold: *text* · Italic: _text_ · Strikethrough: ~text~</span>' +
                '</div>' +
              '</div>' +
            '</div>' +
            '<div class="crm-tm-field crm-tm-field--full"><label class="crm-tm-label" for="crm-tm-footer-' + channelKey + '">Footer</label>' +
            '<input type="text" id="crm-tm-footer-' + channelKey + '" class="input-field" maxlength="255" ' + (canManage() ? '' : 'readonly') +
            ' value="' + escapeAttr(template ? template.footer || '— {{COMPANY_NAME}}' : '— {{COMPANY_NAME}}') + '" /></div>' +
          '</div>' +
          '<div class="crm-tm-editor-actions">' + actionBtns + '</div>' +
        '</div>';

      var bodyEditor = document.getElementById('crm-tm-body-' + channelKey);
      setEditorPlainText(bodyEditor, template ? template.body || template.body_template || '' : '');
      if (bodyEditor) {
        bodyEditor.addEventListener('input', updateBodyCount);
      }
      updateBodyCount();
      updateHeaderHelp();
      bindEditorHandlers();
      if (window.lucide) lucide.createIcons();
    }

    function showList() {
      state.view = 'list';
      state.editingId = null;
      state.editing = null;
      document.getElementById('crm-tm-list-' + channelKey)?.classList.remove('hidden');
      document.getElementById('crm-tm-editor-' + channelKey)?.classList.add('hidden');
    }

    function updateBodyCount() {
      var editor = document.getElementById('crm-tm-body-' + channelKey);
      var countEl = document.getElementById('crm-tm-body-count-' + channelKey);
      if (!editor || !countEl) return;
      var len = getEditorPlainText(editor).length;
      countEl.textContent = len + ' / ' + cfg.bodyMax + ' characters';
      countEl.classList.toggle('text-red-600', len > cfg.bodyMax);
    }

    function updateHeaderHelp() {
      var input = document.getElementById('crm-tm-header-' + channelKey);
      var help = document.getElementById('crm-tm-header-help-' + channelKey);
      if (!input || !help) return;
      var resolved = input.value.replace(/\{\{COMPANY_NAME\}\}/g, 'CA CloudDesk - Demo Account');
      help.textContent = 'Resolved header length: ' + resolved.length + ' / 60';
    }

    function loadCatalog() {
      return apiFetch('/template-variables').then(function (body) {
        var data = body.data || {};
        state.catalog.groups = data.groups || {};
        state.catalog.categories = data.categories || [];
        state.catalog.publish_statuses = data.publish_statuses || state.catalog.publish_statuses;
        renderListShell();
      }).catch(function () {
        renderListShell();
      });
    }

    function loadList() {
      var params = new URLSearchParams({
        paginate: '1',
        page: String(state.page),
        per_page: String(state.perPage),
        sort: state.sort,
        dir: state.dir,
      });
      if (state.filters.search) params.set('search', state.filters.search);
      if (state.filters.category) params.set('category', state.filters.category);
      if (state.filters.publish_status) params.set('publish_status', state.filters.publish_status);

      return apiFetch(cfg.listPath + '?' + params.toString()).then(function (body) {
        var data = body.data || {};
        state.items = data.items || [];
        state.pagination = data.pagination || state.pagination;
        renderTable();
        renderPagination();
      }).catch(function (err) {
        var tbody = document.getElementById('crm-tm-tbody-' + channelKey);
        if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-center text-red-600 p-6">' + escapeHtml(err.message || 'Failed to load templates') + '</td></tr>';
      });
    }

    function renderTable() {
      var tbody = document.getElementById('crm-tm-tbody-' + channelKey);
      if (!tbody) return;
      if (!state.items.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-slate-500 p-6">No templates found.</td></tr>';
        return;
      }
      tbody.innerHTML = state.items.map(function (item) {
        var actions = '<button type="button" class="btn-ghost btn-sm" data-tm-action="preview" data-tm-id="' + item.id + '">Preview</button>';
        if (canManage()) {
          actions += ' <button type="button" class="btn-ghost btn-sm" data-tm-action="edit" data-tm-id="' + item.id + '">Edit</button>' +
            ' <button type="button" class="btn-ghost btn-sm" data-tm-action="duplicate" data-tm-id="' + item.id + '">Duplicate</button>';
          if (item.publish_status === 'active') {
            actions += ' <button type="button" class="btn-ghost btn-sm" data-tm-action="disable" data-tm-id="' + item.id + '">Disable</button>';
          } else {
            actions += ' <button type="button" class="btn-ghost btn-sm" data-tm-action="enable" data-tm-id="' + item.id + '">Enable</button>';
          }
          actions += ' <button type="button" class="btn-ghost btn-sm text-red-600" data-tm-action="delete" data-tm-id="' + item.id + '">Delete</button>';
        }
        return '<tr class="ca-table-row">' +
          '<td class="font-medium text-slate-800">' + escapeHtml(item.name || item.title || '—') + '</td>' +
          '<td>' + escapeHtml(cfg.typeLabel) + '</td>' +
          '<td>' + escapeHtml(item.category || '—') + '</td>' +
          '<td>' + statusBadge(item.publish_status) + '</td>' +
          '<td>' + escapeHtml(item.created_by_name || '—') + '</td>' +
          '<td>' + escapeHtml(formatDate(item.updated_at)) + '</td>' +
          '<td class="text-right whitespace-nowrap">' + actions + '</td>' +
        '</tr>';
      }).join('');
    }

    function renderPagination() {
      var footer = document.getElementById('crm-tm-pagination-' + channelKey);
      if (!footer || !window.CATablePagination) return;
      var scope = 'crm-tm-' + channelKey;
      var total = state.pagination.total || 0;
      var last = state.pagination.last_page || 1;
      var from = total ? ((state.page - 1) * state.perPage) + 1 : 0;
      var to = total ? Math.min(state.page * state.perPage, total) : 0;
      window.CATablePagination.renderInto(footer, {
        scope: scope,
        pagination: {
          current_page: state.page,
          last_page: last,
          total: total,
          from: from,
          to: to,
          per_page: state.perPage,
        },
        perPage: state.perPage,
        showPerPage: true,
      });
      if (!footer._tmPagBound) {
        footer._tmPagBound = true;
        window.CATablePagination.register(scope, {
          onPageChange: function (page) {
            state.page = page;
            loadList();
          },
          onPerPageChange: function (perPage) {
            state.perPage = perPage;
            state.page = 1;
            loadList();
          },
        });
      }
    }

    function collectFormPayload(publishStatus) {
      var bodyEditor = document.getElementById('crm-tm-body-' + channelKey);
      var body = getEditorPlainText(bodyEditor);
      var payload = {
        name: document.getElementById('crm-tm-name-' + channelKey)?.value?.trim() || '',
        category: document.getElementById('crm-tm-category-' + channelKey)?.value || '',
        header: document.getElementById('crm-tm-header-' + channelKey)?.value?.trim() || '',
        body: body,
        footer: document.getElementById('crm-tm-footer-' + channelKey)?.value?.trim() || '',
        publish_status: publishStatus,
      };
      if (cfg.hasSubject) {
        payload.subject = document.getElementById('crm-tm-subject-' + channelKey)?.value?.trim() || '';
      }
      return payload;
    }

    function validatePayload(payload) {
      if (!payload.name) return 'Title is required.';
      if (!payload.category) return 'Category is required.';
      if (cfg.hasSubject && !payload.subject) return 'Subject is required.';
      if (!payload.body) return 'Body is required.';
      if (payload.body.length > cfg.bodyMax) return 'Body exceeds maximum length.';
      return '';
    }

    function saveTemplate(publishStatus) {
      var payload = collectFormPayload(publishStatus);
      var err = validatePayload(payload);
      if (err) {
        toast(err, 'warning');
        return;
      }
      var isEdit = !!state.editingId;
      var url = isEdit ? cfg.listPath + (channelKey === 'whatsapp' ? '/' + state.editingId : '/' + state.editingId) : cfg.listPath;
      var method = isEdit ? 'PUT' : 'POST';

      apiFetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }).then(function (body) {
        toast(body.message || 'Template saved', 'success');
        var saved = body.data || {};
        if (channelKey === 'whatsapp' && publishStatus === 'active' && saved.id && canManage()) {
          return apiFetch(cfg.listPath + '/' + saved.id + '/submit-meta', { method: 'POST' })
            .then(function (metaBody) {
              toast(metaBody.message || 'Submitted to WhatsApp', 'success');
            })
            .catch(function () { /* draft saved */ });
        }
      }).then(function () {
        showList();
        loadList();
      }).catch(function (error) {
        toast(error.message || 'Unable to save template', 'error');
      });
    }

    function openPreview(templateId) {
      var modal = document.getElementById('crm-tm-preview-modal-' + channelKey);
      var item = state.items.find(function (t) { return String(t.id) === String(templateId); });
      if (!modal || !item) return;
      modal.classList.remove('hidden');
      modal.dataset.templateId = String(templateId);
      document.getElementById('crm-tm-preview-title-' + channelKey).textContent = item.name || item.title || '';
      var subjectWrap = document.getElementById('crm-tm-preview-subject-wrap-' + channelKey);
      if (subjectWrap) subjectWrap.classList.toggle('hidden', !cfg.hasSubject);
      loadPreviewLeads();
    }

    function loadPreviewLeads() {
      var select = document.getElementById('crm-tm-preview-lead-' + channelKey);
      if (!select) return;
      select.innerHTML = '<option value="">Loading leads…</option>';
      apiFetch('/ca-masters?per_page=25&page=1&sort_by=firm_name&sort_dir=asc').then(function (body) {
        var leads = unwrapList(body);
        if (!leads.length) {
          select.innerHTML = '<option value="">No leads available</option>';
          return;
        }
        select.innerHTML = '<option value="">Select a lead for preview</option>' +
          leads.map(function (lead) {
            return '<option value="' + escapeAttr(String(leadId(lead))) + '">' + escapeHtml(leadPreviewLabel(lead)) + '</option>';
          }).join('');
      }).catch(function () {
        select.innerHTML = '<option value="">Unable to load leads</option>';
      });
    }

    function runPreview() {
      var modal = document.getElementById('crm-tm-preview-modal-' + channelKey);
      var templateId = modal?.dataset.templateId;
      var leadId = parseInt(document.getElementById('crm-tm-preview-lead-' + channelKey)?.value || '', 10);
      if (!templateId || !leadId) {
        toast('Select a lead to preview', 'warning');
        return;
      }
      var previewPath = channelKey === 'email'
        ? '/email-templates/preview'
        : cfg.listPath + '/' + templateId + '/preview';
      var payload = channelKey === 'email'
        ? { email_template_id: parseInt(templateId, 10), lead_id: leadId }
        : { lead_id: leadId };

      apiFetch(previewPath, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }).then(function (body) {
        var preview = body.data || {};
        if (cfg.hasSubject) {
          var subjectEl = document.getElementById('crm-tm-preview-subject-' + channelKey);
          if (subjectEl) subjectEl.textContent = preview.subject || '';
        }
        var bodyEl = document.getElementById('crm-tm-preview-body-' + channelKey);
        if (bodyEl) bodyEl.innerHTML = preview.preview || escapeHtml(preview.body || '').replace(/\n/g, '<br>');
      }).catch(function (err) {
        toast(err.message || 'Preview failed', 'error');
      });
    }

    function handleRowAction(action, id) {
      if (action === 'preview') {
        openPreview(id);
        return;
      }
      if (action === 'edit') {
        apiFetch(cfg.listPath + '/' + id).then(function (body) {
          renderEditor(body.data || {});
        }).catch(function (err) {
          toast(err.message || 'Unable to load template', 'error');
        });
        return;
      }
      if (action === 'duplicate') {
        apiFetch(cfg.listPath + '/' + id + '/duplicate', { method: 'POST' }).then(function () {
          toast('Template duplicated', 'success');
          loadList();
        }).catch(function (err) {
          toast(err.message || 'Duplicate failed', 'error');
        });
        return;
      }
      if (action === 'enable' || action === 'disable') {
        var status = action === 'enable' ? 'active' : 'disabled';
        apiFetch(cfg.listPath + '/' + id + '/status', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ publish_status: status }),
        }).then(function () {
          toast('Template ' + status, 'success');
          loadList();
        }).catch(function (err) {
          toast(err.message || 'Status update failed', 'error');
        });
        return;
      }
      if (action === 'delete') {
        if (!window.confirm('Delete this template?')) return;
        apiFetch(cfg.listPath + '/' + id, { method: 'DELETE' }).then(function () {
          toast('Template deleted', 'success');
          loadList();
        }).catch(function (err) {
          toast(err.message || 'Delete failed', 'error');
        });
      }
    }

    function bindEditorHandlers() {
      var editorRoot = document.getElementById('crm-tm-editor-' + channelKey);
      if (!editorRoot || editorRoot._editorBound) return;
      editorRoot._editorBound = true;

      editorRoot.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-tm-insert-var]');
        if (chip) {
          var editor = document.getElementById('crm-tm-body-' + channelKey);
          var key = chip.getAttribute('data-tm-insert-var');
          if (editor && key) {
            editor.focus();
            document.execCommand('insertText', false, key);
            updateBodyCount();
          }
          return;
        }
        var fmt = e.target.closest('[data-tm-fmt]');
        if (fmt) {
          var bodyEditor = document.getElementById('crm-tm-body-' + channelKey);
          var kind = fmt.getAttribute('data-tm-fmt');
          if (kind === 'bold') wrapSelection(bodyEditor, '*', '*');
          if (kind === 'italic') wrapSelection(bodyEditor, '_', '_');
          if (kind === 'strike') wrapSelection(bodyEditor, '~', '~');
          updateBodyCount();
        }
      });

      editorRoot.addEventListener('input', function (e) {
        if (e.target.id === 'crm-tm-header-' + channelKey) updateHeaderHelp();
        if (e.target.id === 'crm-tm-body-' + channelKey) updateBodyCount();
      });

      editorRoot.addEventListener('click', function (e) {
        if (e.target.closest('[data-tm-save-draft="' + channelKey + '"]')) saveTemplate('draft');
        if (e.target.closest('[data-tm-save-active="' + channelKey + '"]')) saveTemplate('active');
        if (e.target.closest('[data-tm-cancel="' + channelKey + '"]')) showList();
      });
    }

    function bindGlobalHandlers() {
      if (root._globalBound) return;
      root._globalBound = true;

      root.addEventListener('click', function (e) {
        if (e.target.closest('#crm-tm-create-' + channelKey)) {
          renderEditor(null);
          return;
        }
        var actionBtn = e.target.closest('[data-tm-action]');
        if (actionBtn) {
          handleRowAction(actionBtn.getAttribute('data-tm-action'), actionBtn.getAttribute('data-tm-id'));
        }
        if (e.target.closest('[data-tm-preview-close="' + channelKey + '"]')) {
          document.getElementById('crm-tm-preview-modal-' + channelKey)?.classList.add('hidden');
        }
      });

      document.getElementById('crm-tm-preview-run-' + channelKey)?.addEventListener('click', runPreview);

      var debounce;
      root.addEventListener('input', function (e) {
        if (e.target.id === 'crm-tm-search-' + channelKey) {
          clearTimeout(debounce);
          debounce = setTimeout(function () {
            state.filters.search = e.target.value.trim();
            state.page = 1;
            loadList();
          }, 300);
        }
      });
      root.addEventListener('change', function (e) {
        if (e.target.id === 'crm-tm-cat-' + channelKey) {
          state.filters.category = e.target.value;
          state.page = 1;
          loadList();
        }
        if (e.target.id === 'crm-tm-status-' + channelKey) {
          state.filters.publish_status = e.target.value;
          state.page = 1;
          loadList();
        }
      });
    }

    mount();
  }

  window.CrmTemplateManagement = {
    initEmail: function () { initChannel('email'); },
    initWhatsApp: function () { initChannel('whatsapp'); },
  };
})();
