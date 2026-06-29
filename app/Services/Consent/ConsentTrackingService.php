<?php

namespace App\Services\Consent;

use App\Models\CaMaster;
use App\Models\ConsentTracking;
use App\Services\Activity\ActivityLogService;
use App\Services\Concerns\SearchesListings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConsentTrackingService
{
    use SearchesListings;

    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(
            ConsentTracking::query()->with(['caMaster:ca_id,firm_name,mobile_no,email_id']),
            $params,
            'consent_trackings',
        );
    }

    public function list(?string $consentType = null): Collection
    {
        $params = $consentType ? ['consent_type' => $consentType] : [];

        return $this->listAllFromSearch(
            ConsentTracking::query()->with(['caMaster:ca_id,firm_name,mobile_no,email_id']),
            $params,
            'consent_trackings',
        );
    }

    public function metrics(): array
    {
        $approved = ConsentTracking::query()->where('consent_status', 'Yes')->count();
        $denied = ConsentTracking::query()->where('consent_status', 'No')->count();

        return [
            'consent_approved' => $approved,
            'consent_denied' => $denied,
        ];
    }

    public function upsert(array $data): ConsentTracking
    {
        return DB::transaction(function () use ($data) {
            $lead = CaMaster::query()->findOrFail($data['ca_id']);
            $consentDate = isset($data['consent_date']) && $data['consent_date']
                ? Carbon::parse($data['consent_date'])
                : now();

            $existing = ConsentTracking::query()
                ->where('ca_id', $data['ca_id'])
                ->where('consent_type', $data['consent_type'])
                ->first();

            $consent = ConsentTracking::query()->updateOrCreate(
                [
                    'ca_id' => $data['ca_id'],
                    'consent_type' => $data['consent_type'],
                ],
                [
                    'consent_status' => $data['consent_status'],
                    'consent_date' => $consentDate,
                ],
            );

            $action = $existing ? 'Consent Update' : 'Consent Add';
            $this->activityLogService->log(
                'CONSENT_TRACKING',
                $action,
                (string) $consent->id,
                ($lead->firm_name ?: 'Lead #'.$lead->ca_id).' · '.$data['consent_type'].' · '.$data['consent_status'],
                $data['performed_by'] ?? 'System',
            );

            return $consent->fresh(['caMaster:ca_id,firm_name,mobile_no,email_id']);
        });
    }
}
