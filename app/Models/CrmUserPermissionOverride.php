<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmUserPermissionOverride extends Model
{
    protected $table = 'crm_user_permission_overrides';

    protected $fillable = [
        'user_id',
        'crm_permission_id',
        'effect',
        'created_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(CrmPermission::class, 'crm_permission_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
