<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchasedCustomer extends Model
{
    protected $fillable = [
        'ca_id',
        'employee_id',
        'assigned_by_employee_id',
        'demo_schedule_id',
        'demo_result_id',
        'customer_name',
        'firm_name',
        'mobile_no',
        'email_id',
        'purchase_date',
        'software_name',
        'reference_employee_name',
        'status',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by_employee_id', 'employee_id');
    }
}
