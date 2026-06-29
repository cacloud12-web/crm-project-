<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedListingFilter extends Model
{
    protected $fillable = [
        'listing_key',
        'name',
        'filters',
        'user_id',
        'is_preset',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_preset' => 'boolean',
        ];
    }
}
