<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'serial_number' => $this->serial_number,
            'sale_month' => $this->sale_month,
            'points' => $this->points,
            'customer_name' => $this->customer_name,
            'firm_name' => $this->firm_name,
            'reference_name' => $this->reference_name,
            'mobile_no' => $this->mobile_no,
            'city_name' => $this->city_name,
            'plan_purchased' => $this->plan_purchased,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'cooling_period_days' => $this->cooling_period_days,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'total_amount' => (float) $this->total_amount,
            'amount_received' => (float) $this->amount_received,
            'balance_amount' => (float) $this->balance_amount,
            'invoice_number' => $this->invoice_number,
            'payment_status' => $this->payment_status,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee?->name,
            'manager_id' => $this->manager_id,
            'manager_name' => $this->manager?->name,
            'ca_id' => $this->ca_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
