/* global window, document */
(function (global) {
  'use strict';

  var loadPromise = null;
  var mapInstances = new WeakMap();

  function getKey() {
    return String(global.__CRM_GOOGLE_MAPS_KEY__ || '').trim();
  }

  function hasKey() {
    return getKey() !== '';
  }

  function loadMapsApi() {
    if (!hasKey()) {
      return Promise.reject(new Error('Google Maps JavaScript API key is not configured (VITE_GOOGLE_MAPS_API_KEY).'));
    }

    if (global.google && global.google.maps) {
      return Promise.resolve(global.google.maps);
    }

    if (loadPromise) {
      return loadPromise;
    }

    loadPromise = new Promise(function (resolve, reject) {
      var callbackName = '__crmGoogleMapsInit';
      global[callbackName] = function () {
        delete global[callbackName];
        if (global.google && global.google.maps) {
          resolve(global.google.maps);
        } else {
          reject(new Error('Google Maps JavaScript API loaded but google.maps is unavailable.'));
        }
      };

      var script = document.createElement('script');
      script.async = true;
      script.defer = true;
      script.src = 'https://maps.googleapis.com/maps/api/js?key=' +
        encodeURIComponent(getKey()) +
        '&libraries=places' +
        '&loading=async' +
        '&callback=' + callbackName;
      script.onerror = function () {
        loadPromise = null;
        reject(new Error('Failed to load Google Maps JavaScript API.'));
      };
      document.head.appendChild(script);
    });

    return loadPromise;
  }

  function renderMap(container, lat, lng, options) {
    options = options || {};
    if (!container) {
      return Promise.reject(new Error('Map container is missing.'));
    }

    var latitude = parseFloat(lat);
    var longitude = parseFloat(lng);
    if (!isFinite(latitude) || !isFinite(longitude)) {
      return Promise.reject(new Error('Valid latitude and longitude are required to render the map.'));
    }

    return loadMapsApi().then(function (maps) {
      var center = { lat: latitude, lng: longitude };
      var existing = mapInstances.get(container);
      if (existing) {
        existing.setCenter(center);
        if (existing.marker) {
          existing.marker.setPosition(center);
          if (options.title) {
            existing.marker.setTitle(String(options.title));
          }
        }
        return existing;
      }

      container.innerHTML = '';
      var map = new maps.Map(container, {
        center: center,
        zoom: options.zoom || 16,
        mapTypeControl: false,
        streetViewControl: true,
        fullscreenControl: true,
      });

      var marker = new maps.Marker({
        map: map,
        position: center,
        title: options.title ? String(options.title) : 'Selected firm',
      });

      map.marker = marker;
      mapInstances.set(container, map);
      return map;
    });
  }

  function clearMap(container) {
    if (!container) return;
    mapInstances.delete(container);
    container.innerHTML = '';
  }

  function readPlaceField(place, key) {
    if (!place) return null;
    if (typeof place[key] === 'function') {
      try {
        return place[key]();
      } catch (e) {
        return null;
      }
    }
    return place[key] != null ? place[key] : null;
  }

  function normalizeClientPlace(place) {
    var location = readPlaceField(place, 'location');
    var lat = null;
    var lng = null;
    if (location) {
      if (typeof location.lat === 'function') {
        lat = location.lat();
        lng = location.lng();
      } else {
        lat = location.lat != null ? location.lat : location.latitude;
        lng = location.lng != null ? location.lng : location.longitude;
      }
    }

    var displayName = readPlaceField(place, 'displayName');
    var businessName = displayName && displayName.text ? displayName.text : (typeof displayName === 'string' ? displayName : null);
    var placeId = readPlaceField(place, 'id') || readPlaceField(place, 'placeId');
    var mapsUrl = readPlaceField(place, 'googleMapsURI') || readPlaceField(place, 'googleMapsUri');
    if (!mapsUrl && placeId) {
      mapsUrl = 'https://www.google.com/maps/place/?q=place_id:' + encodeURIComponent(String(placeId));
    }

    return {
      place_id: placeId,
      google_place_id: placeId,
      business_name: businessName,
      address: readPlaceField(place, 'formattedAddress'),
      verified_address: readPlaceField(place, 'formattedAddress'),
      phone: readPlaceField(place, 'internationalPhoneNumber') || readPlaceField(place, 'nationalPhoneNumber'),
      mobile_no: readPlaceField(place, 'internationalPhoneNumber') || readPlaceField(place, 'nationalPhoneNumber'),
      website: readPlaceField(place, 'websiteURI') || readPlaceField(place, 'websiteUri'),
      rating: readPlaceField(place, 'rating'),
      google_rating: readPlaceField(place, 'rating'),
      google_review_count: readPlaceField(place, 'userRatingCount'),
      google_maps_url: mapsUrl,
      latitude: lat != null ? Number(lat) : null,
      longitude: lng != null ? Number(lng) : null,
    };
  }

  function searchPlacesText(query) {
    if (!query || !String(query).trim()) {
      return Promise.reject(new Error('Search query is required.'));
    }

    return loadMapsApi().then(function () {
      return global.google.maps.importLibrary('places');
    }).then(function (placesLib) {
      var Place = placesLib.Place;
      if (!Place || typeof Place.searchByText !== 'function') {
        throw new Error('Google Places library is unavailable in this browser.');
      }
      return Place.searchByText({
        textQuery: String(query).trim(),
        fields: [
          'id',
          'displayName',
          'formattedAddress',
          'location',
          'rating',
          'userRatingCount',
          'googleMapsURI',
          'internationalPhoneNumber',
          'websiteURI',
        ],
        maxResultCount: 10,
      });
    }).then(function (response) {
      var places = (response && response.places) || [];
      return places.map(normalizeClientPlace);
    });
  }

  global.CrmGoogleMaps = {
    getKey: getKey,
    hasKey: hasKey,
    loadMapsApi: loadMapsApi,
    renderMap: renderMap,
    clearMap: clearMap,
    searchPlacesText: searchPlacesText,
    normalizeClientPlace: normalizeClientPlace,
  };
})(window);
