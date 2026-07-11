<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
<script>window.__CRM_COMM_ASSETS__ = @json(asset('crm-ui/assets/communication/'));</script>
<script>window.__CRM_GOOGLE_MAPS_KEY__ = @json(config('services.google.maps_js_api_key'));</script>
<script src="{{ asset('crm-ui/src/constants/data.js') }}"></script>
<script src="{{ asset('crm-ui/src/utils/listing-search.js') }}"></script>
<script src="{{ asset('crm-ui/vendor/flatpickr/flatpickr.min.js') }}"></script>
<script src="{{ asset('crm-ui/vendor/flatpickr/plugins/confirmDate/confirmDate.js') }}"></script>
<script src="{{ asset('crm-ui/src/components/datetime-picker.js') }}"></script>
<script src="{{ asset('crm-ui/src/components/action-dropdown.js') }}"></script>
<script src="{{ asset('crm-ui/src/components/instant-tooltip.js') }}"></script>
<script src="{{ asset('crm-ui/src/components/table-pagination.js') }}"></script>
<script src="{{ asset('crm-ui/src/components/google-maps.js') }}"></script>
<script src="{{ asset('crm-ui/src/components/research-workspace.js') }}"></script>
<script src="{{ asset('crm-ui/src/api/crm.js') }}"></script>
<script src="{{ asset('crm-ui/src/components/lead-picker.js') }}"></script>
<script src="{{ asset('crm-ui/src/components/entity-lookup.js') }}"></script>
<script src="{{ asset('crm-ui/src/components/demo-calendar.js') }}"></script>
<script src="{{ asset('crm-ui/src/api/campaigns-hub.js') }}"></script>
<script src="{{ asset('crm-ui/src/components/state-city-dropdown.js') }}"></script>
<script src="{{ asset('crm-ui/src/components/guide-cards.js') }}"></script>
<script src="{{ asset('crm-ui/src/components/template-management.js') }}"></script>
<script src="{{ asset('crm-ui/src/pages/pages.js') }}"></script>
@php
    $crmUser = app(\App\Services\Rbac\RbacService::class)->userPayload(auth()->user());
@endphp
<script>window.__CRM_USER__ = @json($crmUser);</script>
<script src="{{ asset('crm-ui/src/services/rbac.js') }}"></script>
<script>window.__CRM_INITIAL_PAGE__ = @json($spaPage ?? null);</script>
<script src="{{ asset('crm-ui/src/app.js') }}"></script>
@stack('scripts')
