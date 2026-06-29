<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CaMaster extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'ca_id';

    protected $fillable = [
        'ca_name',
        'firm_name',
        'mobile_no',
        'alternate_mobile_no',
        'email_id',
        'city_id',
        'state_id',
        'source_id',
        'bulk_action_id',
        'team_size',
        'team_size_id',
        'existing_software',
        'website',
        'gst_no',
        'rating',
        'is_newly_established',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_newly_established' => 'boolean',
            'rating' => 'integer',
            'team_size' => 'integer',
        ];
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
}
