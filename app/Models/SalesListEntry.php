<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesListEntry extends Model
{
    protected $fillable = [
        'serial_number',
        'ca_id',
        'purchased_customer_id',
        'demo_result_id',
        'sale_month',
        'points',
        'customer_name',
        'firm_name',
        'reference_name',
        'mobile_no',
        'city_name',
        'plan_purchased',
        'purchase_date',
        'cooling_period_days',
        'expiry_date',
        'total_amount',
        'amount_received',
        'balance_amount',
        'invoice_number',
        'payment_status',
        'employee_id',
        'manager_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'serial_number' => 'integer',
            'points' => 'integer',
            'purchase_date' => 'date',
            'cooling_period_days' => 'integer',
            'expiry_date' => 'date',
            'total_amount' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'balance_amount' => 'decimal:2',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function purchasedCustomer(): BelongsTo
    {
        return $this->belongsTo(PurchasedCustomer::class, 'purchased_customer_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id', 'employee_id');
    }

    public function editHistories(): HasMany
    {
        return $this->hasMany(SalesListEditHistory::class, 'sales_list_entry_id');
    }
}
