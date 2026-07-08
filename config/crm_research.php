<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Places / Maps API
    |--------------------------------------------------------------------------
    |
    | Set GOOGLE_PLACES_API_KEY in .env. All Google API calls are made from the
    | Laravel backend — never expose the key in frontend JavaScript.
    |
    */

    'google_places_api_key' => env('GOOGLE_PLACES_API_KEY', env('GOOGLE_MAPS_API_KEY')),

    'google_maps_js_api_key' => env('VITE_GOOGLE_MAPS_API_KEY', env('GOOGLE_MAPS_API_KEY', env('GOOGLE_PLACES_API_KEY'))),

    'places_new_search_url' => 'https://places.googleapis.com/v1/places:searchText',

    'places_new_details_url' => 'https://places.googleapis.com/v1',

    'places_new_search_field_mask' => 'places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount,places.businessStatus,places.googleMapsUri,places.location,places.nationalPhoneNumber,places.internationalPhoneNumber,places.websiteUri',

    'places_new_details_field_mask' => 'id,displayName,formattedAddress,rating,userRatingCount,businessStatus,googleMapsUri,location,nationalPhoneNumber,internationalPhoneNumber,websiteUri',

    'max_search_results' => 10,

    'timeout_seconds' => 10,

];
