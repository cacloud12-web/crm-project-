<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeAssignment extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'max_capacity', 'current_load', 'department'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}