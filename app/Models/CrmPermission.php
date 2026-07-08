<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CrmPermission extends Model
{
    protected $fillable = [
        'module',
        'action',
        'label',
        'sort_order',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(CrmRole::class, 'crm_role_permissions')
            ->withPivot('granted')
            ->withTimestamps();
    }
}
