<?php

namespace App\Http\Requests\Sales;

use App\Models\SalesListEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSalesListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $plans = array_keys(config('sales_plans.plans', []));
        $entryId = $this->route('id');

        return [
            'points' => ['sometimes', 'integer', 'min:0'],
            'customer_name' => ['sometimes', 'string', 'max:255'],
            'firm_name' => ['sometimes', 'string', 'max:255'],
            'reference_name' => ['nullable', 'string', 'max:255'],
            'mobile_no' => ['nullable', 'string', 'max:20'],
            'city_name' => ['nullable', 'string', 'max:120'],
            'plan_purchased' => ['sometimes', 'string', Rule::in($plans)],
            'purchase_date' => ['sometimes', 'date'],
            'cooling_period_days' => ['sometimes', 'integer', 'min:0'],
            'total_amount' => ['sometimes', 'numeric', 'min:0'],
            'amount_received' => ['sometimes', 'numeric', 'min:0'],
            'invoice_number' => [
                'sometimes',
                'string',
                'max:64',
                Rule::unique('sales_list_entries', 'invoice_number')->ignore($entryId),
            ],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'employee_id')],
            'manager_id' => ['nullable', 'integer', Rule::exists('employees', 'employee_id')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_status' => ['prohibited'],
            'balance_amount' => ['prohibited'],
            'expiry_date' => ['prohibited'],
            'sale_month' => ['prohibited'],
        ];
    }
}
