/**
 * ResearchWorkspace — enterprise full-screen lead research UI.
 * Components: Header, Toolbar, GooglePanel, MapsPanel.
 * Loaded before crm.js; opened via window.CA_RESEARCH_WORKSPACE.
 */
(function (window, document) {
  'use strict';

  var HOST_ID = 'research-workspace-host';

  /** @type {object|null} */
  var deps = null;

  var state = {
    open: false,
    leadId: null,
    lead: null,
    place: null,
    results: [],
    current: null,
    googleQuery: '',
    mapsQuery: '',
    googleEmbed: '',
    googleExternal: '',
    mapsEmbed: '',
    mapsExternal: '',
    sourceNote: '',
    cached: false,
    canRefresh: false,
    canSave: true,
    apiError: null,
    apiRecommendation: null,
    apiGoogleReason: null,
    el: null,
  };

  function esc(value) {
    if (deps && typeof deps.escapeHtml === 'function') {
      return deps.escapeHtml(value);
    }
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function toast(message, type) {
    if (deps && typeof deps.toast === 'function') {
      deps.toast(message, type);
    }
  }

  function icons() {
    if (deps && typeof deps.icons === 'function') {
      deps.icons();
    } else if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
  }

  function leadMeta(lead, key) {
    if (!lead) return '—';
    var value = lead[key];
    if (value == null || value === '' || value === '—') return '—';
    return String(value);
  }

  function leadDisplay(lead, keys) {
    for (var i = 0; i < keys.length; i++) {
      var value = leadMeta(lead, keys[i]);
      if (value !== '—') return value;
    }
    return '—';
  }

  function buildUrls(type, query) {
    var encoded = encodeURIComponent(query || '');
    if (type === 'google') {
      return {
        embed: 'https://www.google.com/search?q=' + encoded + '&igu=1',
        external: 'https://www.google.com/search?q=' + encoded,
      };
    }
    return {
      embed: 'https://www.google.com/maps?q=' + encoded + '&output=embed',
      external: 'https://www.google.com/maps/search/?api=1&query=' + encoded,
    };
  }

  function hasSavedGoogleData(current) {
    if (!current) return false;
    return !!(current.verified_from_google || current.google_place_id);
  }

  function savedFieldValue(current, keys) {
    for (var i = 0; i < keys.length; i++) {
      var value = current[keys[i]];
      if (value != null && String(value).trim() !== '' && value !== '—') {
        return String(value);
      }
    }
    return '';
  }

  function formatOpenStatus(status) {
    var value = String(status || '').toUpperCase();
    if (value === 'OPERATIONAL') return 'Open';
    if (value === 'CLOSED_TEMPORARILY') return 'Temporarily closed';
    if (value === 'CLOSED_PERMANENTLY') return 'Permanently closed';
    return status || '—';
  }

  function savedGoogleDataHtml(current) {
    if (!hasSavedGoogleData(current)) return '';

    var businessName = savedFieldValue(current, ['firm_name']);
    var address = savedFieldValue(current, ['verified_address', 'address']);
    var mapsUrl = savedFieldValue(current, ['google_maps_url']);
    var placeId = savedFieldValue(current, ['google_place_id']);
    var latitude = savedFieldValue(current, ['latitude']);
    var longitude = savedFieldValue(current, ['longitude']);
    var rating = current.google_rating != null ? String(current.google_rating) : '';
    if (rating && current.google_review_count != null) {
      rating += ' (' + current.google_review_count + ' reviews)';
    }
    var openStatus = formatOpenStatus(current.google_business_status);
    var researchedAt = current.researched_at ? String(current.researched_at) : '';

    function row(label, value, link) {
      if (!value) return '';
      var valueHtml = link
        ? '<a class="rw-saved__link" href="' + esc(value) + '" target="_blank" rel="noopener noreferrer">' + esc(value) + '</a>'
        : esc(value);
      return '<div class="rw-saved__row"><span class="rw-saved__label">' + esc(label) + '</span><span class="rw-saved__value">' + valueHtml + '</span></div>';
    }

    return '<section class="rw-saved" data-rw-saved-panel>' +
      '<div class="rw-saved__head">' +
        '<i data-lucide="bookmark-check" class="h-4 w-4"></i>' +
        '<div>' +
          '<h3 class="rw-saved__title">Saved Google data on this lead</h3>' +
          '<p class="rw-saved__subtitle">Loaded from the database — no new search required unless you refresh.</p>' +
        '</div>' +
      '</div>' +
      '<div class="rw-saved__grid">' +
        row('Business name', businessName) +
        row('Address', address) +
        row('Google Maps URL', mapsUrl, true) +
        row('Place ID', placeId) +
        row('Latitude', latitude) +
        row('Longitude', longitude) +
        row('Rating', rating) +
        row('Open/Closed', openStatus) +
        row('Last saved', researchedAt) +
      '</div>' +
    '</section>';
  }

  function researchFieldLabel(key) {
    var labels = {
      business_name: 'Business name',
      verified_address: 'Address',
      address: 'Address',
      mobile_no: 'Phone',
      city_name: 'City',
      state_name: 'State',
      website: 'Website',
      google_rating: 'Google rating',
      google_review_count: 'Reviews',
      google_place_id: 'Place ID',
      google_business_status: 'Status',
      google_maps_url: 'Maps URL',
      latitude: 'Latitude',
      longitude: 'Longitude',
      open_status: 'Open/Closed',
    };
    return labels[key] || key;
  }

  function confidenceBadge(label, matched) {
    return '<span class="rw-confidence-badge' + (matched ? ' rw-confidence-badge--match' : '') + '">' +
      esc(label) + (matched ? ' ✓' : ' —') + '</span>';
  }

  function normalizeApiError(error, recommendation, reason) {
    if (!error) {
      return { error: null, recommendation: recommendation || null, reason: reason || null };
    }

    var lower = String(error).toLowerCase();

    if (lower.indexOf('android client application') !== -1 || reason === 'API_KEY_ANDROID_APP_BLOCKED') {
      return {
        error: 'Google blocked this request: the API key is restricted to Android apps, but CRM calls Places from the Laravel server.',
        recommendation: recommendation || 'In Google Cloud Console → APIs & Services → Credentials: edit this key and set Application restrictions to None (local dev) or IP addresses (server). Enable Places API (New). For browser map/search fallback, set VITE_GOOGLE_MAPS_API_KEY to a separate key with HTTP referrer restrictions. Values in .env override the key saved in Settings.',
        reason: reason || 'API_KEY_ANDROID_APP_BLOCKED',
      };
    }

    if (lower.indexOf('ios client application') !== -1) {
      return {
        error: 'Google blocked this request: the API key is restricted to iOS apps, but CRM calls Places from the Laravel server.',
        recommendation: recommendation || 'Use a server-side API key with Application restrictions set to None or IP addresses — not iOS bundle restrictions.',
        reason: reason || 'API_KEY_IOS_APP_BLOCKED',
      };
    }

    return { error: error, recommendation: recommendation || null, reason: reason || null };
  }

  function apiErrorPanelHtml() {
    if (!state.apiError) return '';

    return '<div class="rw-api-error" role="alert">' +
      '<div class="rw-api-error__head">' +
        '<i data-lucide="alert-circle" class="h-5 w-5"></i>' +
        '<strong>Google Places lookup failed</strong>' +
      '</div>' +
      '<p class="rw-api-error__message">' + esc(state.apiError) + '</p>' +
      (state.apiRecommendation
        ? '<p class="rw-api-error__recommendation"><span class="rw-api-error__label">How to fix</span>' + esc(state.apiRecommendation) + '</p>'
        : '') +
      '<div class="rw-api-error__actions">' +
        '<button type="button" class="btn-secondary btn-sm" data-rw-action="open-google-settings">' +
          '<i data-lucide="settings" class="h-4 w-4"></i> Google API Settings' +
        '</button>' +
        '<a class="btn-ghost btn-sm rw-api-error__link" href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">' +
          '<i data-lucide="external-link" class="h-4 w-4"></i> Google Cloud Console' +
        '</a>' +
      '</div>' +
    '</div>';
  }

  function confidenceHtml(place) {
    var confidence = (place && place.confidence) || {};
    return '<div class="rw-confidence-row">' +
      confidenceBadge('Firm', !!confidence.firm_name_match) +
      confidenceBadge('City', !!confidence.city_match) +
      confidenceBadge('State', !!confidence.state_match) +
      confidenceBadge('CA keyword', !!confidence.ca_keyword_match) +
      '</div>';
  }

  function resultsPanelHtml() {
    var results = state.results || [];
    if (!results.length) {
      return '<div class="rw-results rw-results--empty" data-rw-results-panel>' +
        (state.apiError
          ? apiErrorPanelHtml()
          : '<p class="rw-import__empty-text">No Google Places results yet. Run lookup or check API configuration.</p>') +
      '</div>';
    }

    var cards = results.map(function (place, index) {
      var placeId = place.place_id || place.google_place_id || '';
      var selected = state.place && (state.place.place_id === placeId || state.place.google_place_id === placeId);
      return '<article class="rw-result-card' + (selected ? ' rw-result-card--selected' : '') + '" data-place-id="' + esc(placeId) + '">' +
        '<div class="rw-result-card__head">' +
          '<h4 class="rw-result-card__title">' + esc(place.business_name || 'Unnamed business') + '</h4>' +
          '<span class="rw-result-card__score">' + esc(String(place.confidence_score || 0)) + '% match</span>' +
        '</div>' +
        '<p class="rw-result-card__address">' + esc(place.verified_address || place.address || '—') + '</p>' +
        confidenceHtml(place) +
        '<div class="rw-result-card__meta">' +
          (place.google_rating != null ? '<span>★ ' + esc(String(place.google_rating)) + '</span>' : '') +
          (place.mobile_no ? '<span>' + esc(place.mobile_no) + '</span>' : '') +
          (place.city_name || place.state_name
            ? '<span>' + esc([place.city_name, place.state_name].filter(Boolean).join(', ')) + '</span>'
            : '') +
        '</div>' +
        '<button type="button" class="btn-secondary btn-sm" data-rw-action="select-place" data-place-id="' + esc(placeId) + '">' +
          (selected ? 'Selected' : 'Use this result') +
        '</button>' +
      '</article>';
    }).join('');

    return '<div class="rw-results" data-rw-results-panel>' +
      '<div class="rw-results__head">' +
        '<h3 class="rw-results__title">Google Places results</h3>' +
        '<span class="rw-results__count">' + results.length + ' found</span>' +
      '</div>' +
      '<div class="rw-results__list">' + cards + '</div>' +
    '</div>';
  }

  function researchFieldValue(place, key) {
    if (!place) return '';
    if (key === 'google_place_id') return place.google_place_id || place.place_id || '';
    if (key === 'google_rating' && place.google_rating != null) {
      return String(place.google_rating) +
        (place.google_review_count != null ? ' (' + place.google_review_count + ' reviews)' : '');
    }
    if (key === 'open_status') return place.open_status || place.google_business_status || '';
    if (key === 'city_name') return place.city_name || place.city || '';
    if (key === 'state_name') return place.state_name || place.state || '';
    if (key === 'mobile_no') return place.mobile_no || place.phone || '';
    return place[key] == null ? '' : String(place[key]);
  }

  function isFieldEmptyOnLead(current, key) {
    if (!current) return true;
    var map = {
      verified_address: current.verified_address || current.address,
      address: current.address || current.verified_address,
      website: current.website,
      mobile_no: current.mobile_no,
      city_name: current.city || current.city_name,
      state_name: current.state || current.state_name,
      google_place_id: current.google_place_id,
      google_rating: current.google_rating,
      google_review_count: current.google_review_count,
      google_business_status: current.google_business_status,
      google_maps_url: current.google_maps_url,
      latitude: current.latitude,
      longitude: current.longitude,
    };
    var value = map[key];
    return value == null || String(value).trim() === '' || String(value).trim() === '—';
  }

  function saveFieldKeys() {
    return [
      { display: 'google_place_id', save: 'google_place_id', from: 'google_place_id' },
      { display: 'verified_address', save: 'address', from: 'verified_address' },
      { display: 'mobile_no', save: 'mobile_no', from: 'mobile_no' },
      { display: 'state_name', save: 'state_name', from: 'state_name' },
      { display: 'city_name', save: 'city_name', from: 'city_name' },
      { display: 'website', save: 'website', from: 'website' },
      { display: 'google_rating', save: 'google_rating', from: 'google_rating' },
      { display: 'google_maps_url', save: 'google_maps_url', from: 'google_maps_url' },
      { display: 'latitude', save: 'latitude', from: 'latitude' },
      { display: 'longitude', save: 'longitude', from: 'longitude' },
      { display: 'open_status', save: 'google_business_status', from: 'open_status' },
    ];
  }

  function toolbarButton(action, icon, title) {
    return '<button type="button" class="rw-toolbar-btn" data-rw-action="' + action +
      '" title="' + esc(title) + '" aria-label="' + esc(title) + '">' +
      '<i data-lucide="' + icon + '" class="h-4 w-4"></i></button>';
  }

  function panelHtml(type, title, embedUrl, fallbackText) {
    var useJsMap = type === 'maps' && window.CrmGoogleMaps && window.CrmGoogleMaps.hasKey();
    var mapBody = useJsMap
      ? '<div class="rw-panel__map-canvas" data-rw-map-canvas role="img" aria-label="Google Maps location preview"></div>' +
        '<iframe class="rw-panel__iframe hidden" data-rw-iframe="' + type + '" title="' + esc(title) + '"></iframe>'
      : '<iframe class="rw-panel__iframe" data-rw-iframe="' + type + '" src="' + esc(embedUrl) +
          '" title="' + esc(title) + '" referrerpolicy="no-referrer-when-downgrade" loading="eager"></iframe>';

    return '<section class="rw-panel rw-panel--' + type + '" data-rw-panel="' + type + '">' +
      '<div class="rw-panel__head">' +
        '<div class="rw-panel__head-left">' +
          '<span class="rw-panel__badge rw-panel__badge--' + type + '"></span>' +
          '<h3 class="rw-panel__title">' + esc(title) + '</h3>' +
        '</div>' +
        '<span class="rw-panel__hint">' + esc(useJsMap ? 'Interactive map (Maps JavaScript API)' : 'Preview may be limited by Google embed policy') + '</span>' +
      '</div>' +
      '<div class="rw-panel__body">' +
        '<div class="rw-panel__loading" data-rw-loading="' + type + '" aria-live="polite">' +
          '<i data-lucide="loader-2" class="h-5 w-5 animate-spin text-brand"></i>' +
          '<span>Loading ' + esc(title) + '…</span>' +
        '</div>' +
        '<div class="rw-panel__blocked hidden" data-rw-blocked="' + type + '">' +
          '<p>' + esc(fallbackText) + '</p>' +
          '<button type="button" class="btn-primary btn-sm" data-rw-action="open-' + type + '">Open in New Tab</button>' +
        '</div>' +
        mapBody +
      '</div>' +
    '</section>';
  }

  function renderInteractiveMap() {
    if (!state.el || !window.CrmGoogleMaps || !window.CrmGoogleMaps.hasKey()) return;

    var canvas = state.el.querySelector('[data-rw-map-canvas]');
    if (!canvas) return;

    var place = state.place || {};
    var lat = place.latitude;
    var lng = place.longitude;
    if (lat == null || lng == null) {
      hideLoading('maps');
      return;
    }

    window.CrmGoogleMaps.renderMap(canvas, lat, lng, {
      title: place.business_name || 'Selected firm',
      zoom: 16,
    }).then(function () {
      var panel = state.el.querySelector('[data-rw-panel="maps"]');
      if (panel) panel.classList.add('rw-panel--ready');
      hideLoading('maps');
    }).catch(function () {
      showBlocked('maps');
    });
  }

  function importBarHtml(place, current) {
    if (!place) {
      if (state.apiError) {
        return '';
      }
      return '<div class="rw-import rw-import--empty" data-rw-import-bar>' +
        '<p class="rw-import__empty-text">' + esc(state.sourceNote || 'Run Google Places lookup to find CA firm details.') + '</p>' +
      '</div>';
    }

    var chips = saveFieldKeys().map(function (field) {
      var found = researchFieldValue(place, field.from);
      if (!found && field.from === 'google_place_id') {
        found = place.place_id || place.google_place_id || '';
      }
      if (!found) return '';
      var canSave = state.canSave && isFieldEmptyOnLead(current, field.save);
      return '<label class="rw-import-chip' + (canSave ? '' : ' rw-import-chip--disabled') + '">' +
        '<input type="checkbox" data-research-field="' + field.save + '"' +
        (canSave ? ' checked' : ' disabled') + '> ' +
        '<span><strong>' + esc(researchFieldLabel(field.display)) + ':</strong> ' + esc(found) +
        (canSave ? '' : ' <em>(set)</em>') + '</span></label>';
    }).join('');

    if (place.business_name) {
      chips = '<span class="rw-import-chip rw-import-chip--info"><strong>Business:</strong> ' + esc(place.business_name) + '</span>' + chips;
    }

    return '<div class="rw-import" data-rw-import-bar>' +
      '<div class="rw-import__meta">' +
        '<span class="rw-import__label">Selected Google result</span>' +
        '<span class="rw-import__source" data-rw-source>' + esc(state.sourceNote || 'Places API result') + '</span>' +
      '</div>' +
      confidenceHtml(place) +
      '<div class="rw-import__chips">' + (chips || '<span class="rw-import__empty-text">No importable fields found.</span>') + '</div>' +
      (state.canSave
        ? '<button type="button" class="btn-primary btn-sm rw-import__btn" data-rw-action="import" data-research-save="1">' +
            '<i data-lucide="download" class="h-4 w-4"></i> Save Google Data' +
          '</button>'
        : '<p class="rw-import__empty-text">You can view Google data but cannot save changes for this lead.</p>') +
    '</div>';
  }

  function workspaceHtml() {
    var lead = state.lead || {};
    var caName = leadDisplay(lead, ['ca_name']);
    var firmName = leadDisplay(lead, ['firm_name']);
    var mobile = leadDisplay(lead, ['mobile_no']);
    var city = leadDisplay(lead, ['city', 'city_name']);
    var stateName = leadDisplay(lead, ['state', 'state_name']);

    return '<div class="rw-overlay" aria-hidden="false">' +
      '<div class="rw-modal" role="dialog" aria-modal="true" aria-labelledby="rw-title" data-rw-modal>' +
        '<header class="rw-header">' +
          '<div class="rw-header__identity">' +
            '<div class="rw-header__title-row">' +
              '<span class="rw-header__icon"><i data-lucide="scan-search" class="h-5 w-5"></i></span>' +
              '<div>' +
                '<h2 id="rw-title" class="rw-header__title">Google Places Lookup</h2>' +
                '<p class="rw-header__subtitle" data-rw-source-note>' + esc(state.sourceNote || 'Searching firm + CA + city + state + Chartered Accountant') + '</p>' +
              '</div>' +
            '</div>' +
            '<div class="rw-header__meta">' +
              '<div class="rw-meta-item"><span class="rw-meta-label">Lead</span><span class="rw-meta-value" title="' + esc(caName) + '">' + esc(caName) + '</span></div>' +
              '<div class="rw-meta-item"><span class="rw-meta-label">Firm</span><span class="rw-meta-value" title="' + esc(firmName) + '">' + esc(firmName) + '</span></div>' +
              '<div class="rw-meta-item"><span class="rw-meta-label">Mobile</span><span class="rw-meta-value" title="' + esc(mobile) + '">' + esc(mobile) + '</span></div>' +
              '<div class="rw-meta-item"><span class="rw-meta-label">City</span><span class="rw-meta-value" title="' + esc(city) + '">' + esc(city) + '</span></div>' +
              '<div class="rw-meta-item"><span class="rw-meta-label">State</span><span class="rw-meta-value" title="' + esc(stateName) + '">' + esc(stateName) + '</span></div>' +
            '</div>' +
          '</div>' +
          '<div class="rw-toolbar" role="toolbar" aria-label="Research actions">' +
            (state.canRefresh ? toolbarButton('refresh-places', 'refresh-cw', 'Refresh Google Data') : '') +
            toolbarButton('copy-website', 'globe', 'Copy Website') +
            toolbarButton('open-browser', 'external-link', 'Open in Browser') +
            toolbarButton('close', 'x', 'Close') +
          '</div>' +
        '</header>' +
        '<div class="rw-saved-wrap" data-rw-saved-wrap>' + savedGoogleDataHtml(state.current) + '</div>' +
        '<div class="rw-results-wrap" data-rw-results-wrap>' + resultsPanelHtml() + '</div>' +
        '<div class="rw-import-wrap" data-rw-import-wrap>' + importBarHtml(state.place, state.current) + '</div>' +
        '<div class="rw-split rw-split--compact">' +
          panelHtml('maps', 'Google Maps Preview', state.mapsEmbed,
            'Maps preview may be blocked. Open in a new tab for location, Street View, and reviews.') +
        '</div>' +
      '</div>' +
    '</div>';
  }

  function ensureHost() {
    var host = document.getElementById(HOST_ID);
    if (!host) {
      host = document.createElement('div');
      host.id = HOST_ID;
      host.className = 'rw-host';
      document.body.appendChild(host);
    }
    return host;
  }

  function bindIframe(type) {
    if (!state.el) return;
    var iframe = state.el.querySelector('[data-rw-iframe="' + type + '"]');
    var panel = state.el.querySelector('[data-rw-panel="' + type + '"]');
    if (!iframe || !panel || iframe._rwBound) return;
    iframe._rwBound = true;

    var timer = window.setTimeout(function () {
      if (!panel.classList.contains('rw-panel--ready')) {
        showBlocked(type);
      }
    }, 9000);

    iframe.addEventListener('load', function () {
      panel.classList.add('rw-panel--ready');
      window.clearTimeout(timer);
      window.setTimeout(function () { hideLoading(type); }, 200);
    });
  }

  function hideLoading(type) {
    var loading = state.el && state.el.querySelector('[data-rw-loading="' + type + '"]');
    if (loading) loading.classList.add('hidden');
  }

  function showBlocked(type) {
    hideLoading(type);
    var blocked = state.el && state.el.querySelector('[data-rw-blocked="' + type + '"]');
    var panel = state.el && state.el.querySelector('[data-rw-panel="' + type + '"]');
    if (blocked) blocked.classList.remove('hidden');
    if (panel) panel.classList.add('rw-panel--blocked');
  }

  function refreshPanel(type) {
    if (!state.el) return;
    var iframe = state.el.querySelector('[data-rw-iframe="' + type + '"]');
    var panel = state.el.querySelector('[data-rw-panel="' + type + '"]');
    var loading = state.el.querySelector('[data-rw-loading="' + type + '"]');
    var blocked = state.el.querySelector('[data-rw-blocked="' + type + '"]');
    if (!iframe) return;

    var query = type === 'google' ? state.googleQuery : state.mapsQuery;
    var urls = buildUrls(type, query);
    if (type === 'google') {
      state.googleEmbed = urls.embed;
      state.googleExternal = urls.external;
    } else {
      state.mapsEmbed = urls.embed;
      state.mapsExternal = urls.external;
    }

    if (panel) {
      panel.classList.remove('rw-panel--ready', 'rw-panel--blocked');
    }
    if (loading) loading.classList.remove('hidden');
    if (blocked) blocked.classList.add('hidden');

    iframe._rwBound = false;
    iframe.src = urls.embed;
    bindIframe(type);
    toast((type === 'google' ? 'Google Search' : 'Google Maps') + ' refreshed', 'info');
  }

  function copyText(value, label) {
    if (!value || value === '—') {
      toast('No ' + label + ' available to copy', 'warning');
      return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(function () {
        toast(label + ' copied', 'success');
      }).catch(function () {
        fallbackCopy(value, label);
      });
      return;
    }
    fallbackCopy(value, label);
  }

  function fallbackCopy(value, label) {
    var input = document.createElement('textarea');
    input.value = value;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.left = '-9999px';
    document.body.appendChild(input);
    input.select();
    try {
      document.execCommand('copy');
      toast(label + ' copied', 'success');
    } catch (err) {
      toast('Unable to copy ' + label, 'error');
    }
    document.body.removeChild(input);
  }

  function placeOrLeadValue(keys) {
    var place = state.place || {};
    var lead = state.lead || {};
    for (var i = 0; i < keys.length; i++) {
      var key = keys[i];
      if (place[key] != null && String(place[key]).trim() !== '') return String(place[key]).trim();
      if (lead[key] != null && String(lead[key]).trim() !== '' && lead[key] !== '—') {
        return String(lead[key]).trim();
      }
    }
    return '';
  }

  function openExternal(type) {
    var url = type === 'google' ? state.googleExternal : state.mapsExternal;
    if (!url) return;
    window.open(url, '_blank', 'noopener,noreferrer');
  }

  function saveImportDetails() {
    if (!state.leadId || !state.place) {
      toast('No research details to import yet', 'warning');
      return;
    }
    var fields = [];
    if (state.el) {
      state.el.querySelectorAll('input[data-research-field]:checked:not(:disabled)').forEach(function (input) {
        fields.push(input.getAttribute('data-research-field'));
      });
    }
    if (!fields.length) {
      toast('Select at least one empty field to import', 'warning');
      return;
    }
    if (!window.confirm('Save selected Google Places details into this lead? Existing values will not be overwritten unless you are a Manager/Super Admin refreshing data.')) {
      return;
    }

    var saveBtn = state.el && state.el.querySelector('[data-research-save]');
    if (saveBtn) saveBtn.disabled = true;

    var apiFetch = deps && deps.apiFetch;
    if (typeof apiFetch !== 'function') {
      toast('Unable to save research details', 'error');
      if (saveBtn) saveBtn.disabled = false;
      return;
    }

    apiFetch('/ca-masters/' + encodeURIComponent(state.leadId) + '/research/save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ fields: fields, place: state.place }),
    })
      .then(function (body) {
        var lead = body.data || {};
        state.current = Object.assign({}, state.current || {}, lead);
        state.lead = Object.assign({}, state.lead || {}, lead);
        if (!state.place && leadToSavedPlace(state.current)) {
          state.place = leadToSavedPlace(state.current);
          state.results = state.place ? [state.place] : [];
        }
        if (typeof deps.onSaved === 'function') {
          deps.onSaved(lead);
        }
        toast(body.message || 'Research details imported', 'success');
        updatePanels();
        closeWorkspace();
      })
      .catch(function (err) {
        toast((err && err.message) || 'Unable to import research details', 'error');
      })
      .finally(function () {
        if (saveBtn) saveBtn.disabled = false;
      });
  }

  function refreshGoogleData() {
    if (!state.leadId || !state.canRefresh) {
      toast('Only Manager or Super Admin can refresh Google data', 'warning');
      return;
    }
    var apiFetch = deps && deps.apiFetch;
    if (typeof apiFetch !== 'function') return;
    toast('Refreshing Google data…', 'info');
    apiFetch('/ca-masters/' + encodeURIComponent(state.leadId) + '/research/refresh', { method: 'POST' })
      .then(function (body) {
        applyResearchPayload(body.data || {});
        toast(body.message || 'Google data refreshed', 'success');
      })
      .catch(function (err) {
        toast((err && err.message) || 'Unable to refresh Google data', 'error');
      });
  }

  function selectPlace(placeId) {
    if (!state.leadId || !placeId) return;
    var apiFetch = deps && deps.apiFetch;
    if (typeof apiFetch !== 'function') return;
    apiFetch('/ca-masters/' + encodeURIComponent(state.leadId) + '/research/select', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ place_id: placeId }),
    })
      .then(function (body) {
        applyResearchPayload(body.data || {});
        toast('Google place selected', 'success');
      })
      .catch(function (err) {
        toast((err && err.message) || 'Unable to load place details', 'error');
      });
  }

  function leadToSavedPlace(current) {
    if (!hasSavedGoogleData(current)) return null;
    return {
      place_id: current.google_place_id,
      google_place_id: current.google_place_id,
      business_name: current.firm_name,
      verified_address: current.verified_address || current.address,
      address: current.address || current.verified_address,
      mobile_no: current.mobile_no,
      website: current.website,
      google_rating: current.google_rating,
      google_review_count: current.google_review_count,
      google_business_status: current.google_business_status,
      google_maps_url: current.google_maps_url,
      latitude: current.latitude,
      longitude: current.longitude,
      open_status: formatOpenStatus(current.google_business_status),
    };
  }

  function applyResearchPayload(data) {
    data = data || {};
    var apiMeta = normalizeApiError(data.api_error, data.api_recommendation, data.api_google_reason);
    state.apiError = apiMeta.error;
    state.apiRecommendation = apiMeta.recommendation;
    state.apiGoogleReason = apiMeta.reason;
    state.place = data.place || state.place || leadToSavedPlace(state.current);
    state.results = data.results || (state.place ? [state.place] : []);
    state.current = data.current || state.current;
    state.cached = !!data.cached;
    state.canRefresh = !!data.can_refresh;
    state.canSave = data.can_save !== false;
    state.sourceNote = data.cached
      ? 'Loaded cached Google data — use Refresh Google Data to fetch again'
      : (state.apiError
        ? state.apiError
        : (data.multiple_results
          ? 'Multiple results found — select the correct CA firm'
          : (data.place ? 'Google Places match ready to save' : 'No Google Places match found')));

    if (data.google_maps_embed_url) state.mapsEmbed = data.google_maps_embed_url;
    if (data.google_maps_url) state.mapsExternal = data.google_maps_url;
    if (!data.google_maps_embed_url && state.current && state.current.latitude != null && state.current.longitude != null) {
      state.mapsEmbed = 'https://www.google.com/maps?q=' + state.current.latitude + ',' + state.current.longitude + '&output=embed';
    }
    if (!data.google_maps_url && state.current && state.current.google_maps_url) {
      state.mapsExternal = state.current.google_maps_url;
    }

    updatePanels();
    renderInteractiveMap();
  }

  function updatePanels() {
    if (!state.el) return;
    var savedWrap = state.el.querySelector('[data-rw-saved-wrap]');
    if (savedWrap) savedWrap.innerHTML = savedGoogleDataHtml(state.current);
    var resultsWrap = state.el.querySelector('[data-rw-results-wrap]');
    if (resultsWrap) resultsWrap.innerHTML = resultsPanelHtml();
    var note = state.el.querySelector('[data-rw-source-note]');
    if (note) {
      note.textContent = state.apiError
        ? 'Google Places API error — see details below'
        : (state.sourceNote || 'Google Places lookup');
    }
    updateImportBar();
    var mapsFrame = state.el.querySelector('[data-rw-iframe="maps"]');
    if (mapsFrame && state.mapsEmbed && mapsFrame.src !== state.mapsEmbed) {
      mapsFrame.src = state.mapsEmbed;
    }
    renderInteractiveMap();
    icons();
  }

  function handleAction(action, target) {
    if (action === 'close') {
      closeWorkspace();
      return;
    }
    if (action === 'refresh-places') {
      refreshGoogleData();
      return;
    }
    if (action === 'select-place') {
      selectPlace(target && target.getAttribute('data-place-id'));
      return;
    }
    if (action === 'copy-website') {
      copyText(placeOrLeadValue(['website']), 'Website');
      return;
    }
    if (action === 'copy-address') {
      copyText(placeOrLeadValue(['verified_address', 'address']), 'Address');
      return;
    }
    if (action === 'copy-phone') {
      copyText(placeOrLeadValue(['mobile_no', 'phone']), 'Phone');
      return;
    }
    if (action === 'import') {
      saveImportDetails();
      return;
    }
    if (action === 'open-browser') {
      openExternal('google');
      openExternal('maps');
      return;
    }
    if (action === 'open-google') {
      openExternal('google');
      return;
    }
    if (action === 'open-maps') {
      openExternal('maps');
      return;
    }
    if (action === 'open-google-settings') {
      closeWorkspace();
      if (typeof window.navigateTo === 'function') {
        window.navigateTo('settings-google-api');
      } else {
        window.location.href = '/settings/google-api';
      }
      return;
    }
  }

  function bindWorkspaceEvents() {
    if (!state.el) return;

    if (!state.el._rwEventsBound) {
      state.el._rwEventsBound = true;
      state.el.addEventListener('click', function (e) {
        if (e.target.classList.contains('rw-overlay')) {
          closeWorkspace();
          return;
        }
        var btn = e.target.closest('[data-rw-action]');
        if (!btn || !state.el.contains(btn) || !btn.closest('.rw-modal')) {
          return;
        }
        e.preventDefault();
        e.stopPropagation();
        handleAction(btn.getAttribute('data-rw-action'), btn);
      });
    }

    bindIframe('maps');
    renderInteractiveMap();
  }

  function lockBodyScroll(lock) {
    document.documentElement.classList.toggle('rw-open', lock);
    document.body.classList.toggle('rw-open', lock);
  }

  function render() {
    var host = ensureHost();
    host.innerHTML = workspaceHtml();
    state.el = host;
    host.classList.add('rw-host--open');
    lockBodyScroll(true);
    bindWorkspaceEvents();
    requestAnimationFrame(function () {
      host.classList.add('rw-host--visible');
      icons();
    });
  }

  function updateImportBar() {
    if (!state.el) return;
    var wrap = state.el.querySelector('[data-rw-import-wrap]');
    if (!wrap) return;
    var hide = !!state.apiError && !state.place;
    wrap.style.display = hide ? 'none' : '';
    wrap.innerHTML = hide ? '' : importBarHtml(state.place, state.current);
    icons();
  }

  function openWorkspace(options) {
    options = options || {};
    deps = options.deps || deps || {};

    var lead = options.lead || {};
    var googleQuery = options.googleQuery || '';
    var mapsQuery = options.mapsQuery || googleQuery;
    if (!googleQuery && !mapsQuery) {
      toast('Insufficient lead data for research', 'warning');
      return;
    }

    var googleUrls = buildUrls('google', googleQuery);
    var mapsUrls = buildUrls('maps', mapsQuery);

    state.open = true;
    state.leadId = options.leadId;
    state.lead = lead;
    state.place = options.place || null;
    state.results = options.results || (state.place ? [state.place] : []);
    state.current = options.current || lead;
    state.cached = !!options.cached;
    state.canRefresh = !!options.canRefresh;
    state.canSave = options.canSave !== false;
    state.googleQuery = googleQuery;
    state.mapsQuery = mapsQuery;
    state.googleEmbed = options.googleEmbed || googleUrls.embed;
    state.googleExternal = options.googleExternal || googleUrls.external;
    state.mapsEmbed = options.mapsEmbed || mapsUrls.embed;
    state.mapsExternal = options.mapsExternal || mapsUrls.external;
    state.sourceNote = options.sourceNote || 'Loading research details…';

    render();
  }

  function setResearchData(data) {
    if (!state.open) return;
    applyResearchPayload(data);
  }

  function setSourceError(message) {
    var apiMeta = normalizeApiError(message);
    state.apiError = apiMeta.error;
    state.apiRecommendation = apiMeta.recommendation;
    state.apiGoogleReason = apiMeta.reason;
    state.sourceNote = apiMeta.error || 'Could not load Google Places details.';
    updatePanels();
  }

  function closeWorkspace() {
    if (!state.open && !state.el) return;
    var host = document.getElementById(HOST_ID);
    if (host) {
      host.classList.remove('rw-host--visible', 'rw-host--open');
      host.innerHTML = '';
    }
    lockBodyScroll(false);
    state.open = false;
    state.el = null;
    state.leadId = null;
    state.lead = null;
    state.place = null;
    state.current = null;
    state.apiError = null;
    state.apiRecommendation = null;
    state.apiGoogleReason = null;
  }

  function isOpen() {
    return !!state.open;
  }

  window.CA_RESEARCH_WORKSPACE = {
    open: openWorkspace,
    close: closeWorkspace,
    isOpen: isOpen,
    setResearchData: setResearchData,
    setSourceError: setSourceError,
    refreshGoogle: function () { refreshPanel('google'); },
    refreshMaps: function () { refreshPanel('maps'); },
  };
})(window, document);
