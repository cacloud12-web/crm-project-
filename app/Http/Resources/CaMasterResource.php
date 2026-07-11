<?php

namespace App\Http\Resources;

use App\Services\Leads\LeadActivityTimelineService;
use App\Services\Leads\LeadOwnershipService;
use App\Services\Leads\LeadTeamMemberService;
use App\Services\Rbac\EmployeeLeadFieldGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class CaMasterResource extends JsonResource
{
    /**
     * @var array<string, string>
     */
    private const STATUS_TO_STAGE = [
        'New' => 'New Lead',
        'Cold' => 'New Lead',
        'Hot' => 'Negotiation',
        'Negotiation' => 'Negotiation',
        'Interested' => 'Negotiation',
        'Thinking' => 'Negotiation',
        'Purchasing' => 'Negotiation',
        'Demo Scheduled' => 'Demo Scheduled',
        'Demo Completed' => 'Demo Completed',
        'Details Shared' => 'Details Shared',
        'Pipeline' => 'Details Shared',
        'Warm' => 'Demo Completed',
        'Lost' => 'Lost',
        'Inactive' => 'Lost',
        'Not Interested' => 'Lost',
        'Active' => 'Won',
        'Purchased' => 'Won',
        'Purchasing' => 'Won',
        'Next Week' => 'Demo Completed',
        'Next Month' => 'Demo Completed',
        'Hold' => 'Demo Completed',
        'Follow Up Scheduled' => 'Details Shared',
        'Follow Up Reminder' => 'Details Shared',
    ];

    /**
     * @var array<int, array{id: int|null, name: string|null}>
     */
    private static array $executiveByCaId = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private static array $lastActivityByCaId = [];

    /**
     * @param  Collection<int, mixed>|array<int, mixed>  $leads
     */
    public static function prepareCollection(Collection|array $leads): void
    {
        $collection = $leads instanceof Collection ? $leads : collect($leads);
        if ($collection->isEmpty()) {
            self::$executiveByCaId = [];
            self::$lastActivityByCaId = [];

            return;
        }

        $caIds = $collection
            ->map(fn ($lead) => (int) (is_array($lead) ? ($lead['ca_id'] ?? 0) : $lead->ca_id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($caIds === []) {
            self::$executiveByCaId = [];
            self::$lastActivityByCaId = [];

            return;
        }

        $first = $collection->first();
        if (is_object($first) && method_exists($first, 'relationLoaded') && $first->relationLoaded('activeAssignment')) {
            self::$executiveByCaId = [];
        } else {
            self::$executiveByCaId = \App\Models\LeadAssignmentEngine::query()
                ->with('employee:employee_id,name')
                ->whereIn('ca_id', $caIds)
                ->where('status', 'Active')
                ->orderByDesc('assignment_id')
                ->get()
                ->unique('ca_id')
                ->mapWithKeys(fn ($assignment) => [
                    (int) $assignment->ca_id => [
                        'id' => $assignment->employee_id ? (int) $assignment->employee_id : null,
                        'name' => $assignment->employee?->name,
                    ],
                ])
                ->all();
        }

        self::$lastActivityByCaId = app(LeadActivityTimelineService::class)->summariesForCaIds($caIds);
    }

    public function toArray(Request $request): array
    {
        $executive = $this->resolveExecutive();
        $teamMembers = $this->resolveTeamMembersSummary();
        $lastActivity = $this->resolveLastActivity();

        return [
            'ca_id' => $this->ca_id,
            'ca_name' => $this->ca_name,
            'firm_name' => $this->firm_name,
            'mobile_no' => $this->mobile_no,
            'alternate_mobile_no' => $this->alternate_mobile_no,
            'email_id' => $this->email_id,
            'city_id' => $this->city_id,
            'state_id' => $this->state_id,
            'source_id' => $this->source_id,
            'city' => $this->city?->city_name,
            'city_name' => $this->city?->city_name,
            'state' => $this->state?->state_name,
            'state_name' => $this->state?->state_name,
            'source' => $this->sourceLead?->source_name,
            'source_name' => $this->sourceLead?->source_name,
            'team_size' => $this->team_size,
            'existing_software' => $this->existing_software,
            'website' => $this->website,
            'gst_no' => $this->gst_no,
            'pan_no' => $this->pan_no,
            'google_place_id' => $this->google_place_id,
            'verified_address' => $this->verified_address,
            'google_rating' => $this->google_rating,
            'google_review_count' => $this->google_review_count,
            'google_business_status' => $this->google_business_status,
            'google_maps_url' => $this->google_maps_url,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'verified_from_google' => (bool) $this->verified_from_google,
            'address' => $this->address,
            'researched_at' => $this->researched_at,
            'is_verified' => (bool) $this->is_verified,
            'is_wrong_number' => (bool) $this->is_wrong_number,
            'wrong_number_reason' => $this->wrong_number_reason,
            'rating' => $this->rating,
            'is_newly_established' => (bool) $this->is_newly_established,
            'status' => $this->status,
            'stage' => self::STATUS_TO_STAGE[$this->status] ?? 'New Lead',
            'master_pipeline_stage' => self::resolveMasterPipelineStage($this->status),
            'lead_tags' => $this->lead_tags ?? [],
            'priority' => $this->priority ?? 'Medium',
            'research_status' => $this->research_status,
            'view_count' => (int) ($this->view_count ?? 0),
            'last_viewed_at' => $this->last_viewed_at,
            'locked_by' => $this->locked_by,
            'locked_at' => $this->locked_at,
            'locked_by_name' => $this->whenLoaded('lockedByEmployee', fn () => $this->lockedByEmployee?->name),
            'lock' => $this->when(
                $request->user() !== null,
                fn () => app(\App\Services\Leads\LeadLockService::class)->lockInfo($this->resource, $request->user()),
            ),
            'executive_id' => $this->when($request->user() !== null, fn () => $executive['id']),
            'executive' => $this->when($request->user() !== null, fn () => $executive['name']),
            'executive_name' => $this->when($request->user() !== null, fn () => $executive['name']),
            'employee_name' => $this->when($request->user() !== null, fn () => $executive['name']),
            'team_members' => $this->when($request->user() !== null, fn () => $teamMembers),
            'team_members_count' => $this->when($request->user() !== null, fn () => $teamMembers['count']),
            'team_member_names' => $this->when($request->user() !== null, fn () => $teamMembers['names']),
            'lead_owner_id' => $this->when($request->user() !== null, fn () => $teamMembers['lead_owner_id']),
            'last_activity' => $this->when($request->user() !== null, fn () => $lastActivity),
            'last_activity_at' => $this->when(
                $request->user() !== null,
                fn () => $lastActivity ? ($lastActivity['occurred_at'] ?? null) : null,
            ),
            'mobile' => $this->mobile_no,
            'employee_cannot_edit_mobile' => $request->user()
                ? app(EmployeeLeadFieldGuard::class)
                    ->employeeCannotEditExistingMobile($request->user(), $this->resource)
                : false,
            'employee_locked_fields' => $this->when(
                $request->user() !== null,
                fn () => app(EmployeeLeadFieldGuard::class)
                    ->lockedFieldsForEmployee($request->user(), $this->resource),
            ),
            'is_read_only' => $request->user()
                ? app(LeadOwnershipService::class)->isReadOnlyForUser($request->user(), $this->resource)
                : false,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'created_by_name' => $this->whenLoaded('createdByEmployee', fn () => $this->createdByEmployee?->name),
            'created_by' => $this->whenLoaded('createdByEmployee', fn () => $this->createdByEmployee?->name),
        ];
    }

    /**
     * @return array{id: int|null, name: string|null}
     */
    private function resolveExecutive(): array
    {
        if ($this->relationLoaded('activeTeamAssignments')) {
            $assignment = $this->activeTeamAssignments->first();

            return [
                'id' => $assignment?->employee_id ? (int) $assignment->employee_id : null,
                'name' => $assignment?->employee?->name,
            ];
        }

        if ($this->relationLoaded('activeAssignment')) {
            $assignment = $this->activeAssignment;

            return [
                'id' => $assignment?->employee_id ? (int) $assignment->employee_id : null,
                'name' => $assignment?->employee?->name,
            ];
        }

        $cached = self::$executiveByCaId[(int) $this->ca_id] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        return ['id' => null, 'name' => null];
    }

    /**
     * @return array{count: int, names: list<string>, lead_owner_id: int|null}
     */
    private function resolveTeamMembersSummary(): array
    {
        if ($this->relationLoaded('activeTeamAssignments')) {
            return app(LeadTeamMemberService::class)->summarize($this->activeTeamAssignments);
        }

        $executive = $this->resolveExecutive();
        if ($executive['id'] === null || $executive['name'] === null) {
            return ['count' => 0, 'names' => [], 'lead_owner_id' => null];
        }

        return [
            'count' => 1,
            'names' => [(string) $executive['name']],
            'lead_owner_id' => $executive['id'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveLastActivity(): ?array
    {
        $cached = self::$lastActivityByCaId[(int) $this->ca_id] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $summaries = app(LeadActivityTimelineService::class)->summariesForCaIds([(int) $this->ca_id]);

        return $summaries[(int) $this->ca_id] ?? null;
    }

    private static function resolveMasterPipelineStage(?string $status): string
    {
        if ($status === null || $status === '') {
            return 'New Lead';
        }

        foreach (config('crm_master_pipeline.stage_statuses', []) as $stage => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $stage;
            }
        }

        return 'New Lead';
    }
}
