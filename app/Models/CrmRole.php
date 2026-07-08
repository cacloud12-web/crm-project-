<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CrmRole extends Model
{
    protected $fillable = [
        'key',
        'label',
        'is_system',
        'is_editable',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_editable' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(CrmPermission::class, 'crm_role_permissions')
            ->withPivot('granted')
            ->withTimestamps();
    }
}
