<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class CaMaster extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'ca_id';

    protected $fillable = [
        'ca_name',
        'normalized_ca_name',
        'firm_name',
        'normalized_firm_name',
        'mobile_no',
        'normalized_mobile',
        'mobile_no_type',
        'alternate_mobile_no',
        'normalized_alternate_mobile',
        'alternate_mobile_no_type',
        'email_id',
        'normalized_email',
        'city_id',
        'state_id',
        'normalized_state',
        'source_id',
        'bulk_action_id',
        'created_by_employee_id',
        'verified_by',
        'is_verified',
        'is_wrong_number',
        'wrong_number_reason',
        'team_size',
        'team_size_id',
        'existing_software',
        'website',
        'normalized_website',
        'google_place_id',
        'verified_address',
        'google_rating',
        'google_review_count',
        'google_business_status',
        'google_maps_url',
        'latitude',
        'longitude',
        'verified_from_google',
        'google_places_cache',
        'researched_at',
        'gst_no',
        'membership_no',
        'frn',
        'address',
        'pincode',
        'pan_no',
        'rating',
        'field_confidence',
        'is_newly_established',
        'status',
        'workflow_stage',
        'call_status',
        'demo_status',
        'software_purchased',
        'purchase_date',
        'lead_tags',
        'priority',
        'research_status',
        'view_count',
        'last_viewed_at',
        'locked_by',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_newly_established' => 'boolean',
            'is_verified' => 'boolean',
            'is_wrong_number' => 'boolean',
            'software_purchased' => 'boolean',
            'purchase_date' => 'date',
            'rating' => 'integer',
            'team_size' => 'integer',
            'lead_tags' => 'array',
            'field_confidence' => 'array',
            'view_count' => 'integer',
            'google_rating' => 'float',
            'google_review_count' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'verified_from_google' => 'boolean',
            'google_places_cache' => 'array',
            'last_viewed_at' => 'datetime',
            'researched_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function leadViews(): HasMany
    {
        return $this->hasMany(LeadView::class, 'ca_id', 'ca_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id', 'state_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id', 'city_id');
    }

    public function sourceLead(): BelongsTo
    {
        return $this->belongsTo(SourceLead::class, 'source_id', 'source_id');
    }

    public function bulkAction(): BelongsTo
    {
        return $this->belongsTo(BulkAction::class, 'bulk_action_id', 'bulk_action_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'ca_id', 'ca_id');
    }

    public function leadAssignments(): HasMany
    {
        return $this->hasMany(LeadAssignmentEngine::class, 'ca_id', 'ca_id');
    }

    public function partners(): HasMany
    {
        return $this->hasMany(CaMasterPartner::class, 'ca_id', 'ca_id')
            ->orderByDesc('is_primary')
            ->orderBy('sequence_no')
            ->orderBy('id');
    }

    public function primaryPartner(): HasOne
    {
        return $this->hasOne(CaMasterPartner::class, 'ca_id', 'ca_id')
            ->where('is_primary', true)
            ->orderBy('id');
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(LeadAssignmentEngine::class, 'ca_id', 'ca_id')
            ->where('status', 'Active')
            ->orderByDesc('assignment_id');
    }

    public function activeTeamAssignments(): HasMany
    {
        return $this->hasMany(LeadAssignmentEngine::class, 'ca_id', 'ca_id')
            ->where('status', 'Active')
            ->orderByDesc('assignment_id');
    }

    public function lockedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'locked_by', 'employee_id');
    }

    public function createdByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_employee_id', 'employee_id');
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(LeadPhoneNumber::class, 'ca_id', 'ca_id');
    }

    /**
     * Leads with landline-only primary numbers are excluded from KPIs and reports.
     */
    public function scopeCountableInStatistics(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            $inner->whereNull('mobile_no_type')
                ->orWhere('mobile_no_type', 'mobile');
        });
    }

    public function isLandlinePrimary(): bool
    {
        return $this->mobile_no_type === 'landline';
    }

    public function qualityHistories(): HasMany
    {
        return $this->hasMany(LeadQualityHistory::class, 'ca_id', 'ca_id');
    }

    public function verifiedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'verified_by', 'employee_id');
    }
}
