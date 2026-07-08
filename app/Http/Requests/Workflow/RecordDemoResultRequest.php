<?php

namespace App\Http\Requests\Workflow;

use App\Http\Requests\Concerns\SanitizesUserText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordDemoResultRequest extends FormRequest
{
    use SanitizesUserText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields([
            'notes',
            'software_name',
            'customer_name',
            'firm_name',
            'reference_name',
            'mobile_no',
            'city_name',
            'invoice_number',
        ]);

        if ($this->filled('software_name') && ! $this->filled('plan_purchased')) {
            $this->merge(['plan_purchased' => $this->input('software_name')]);
        }
    }

    public function rules(): array
    {
        $plans = array_keys(config('sales_plans.plans', []));

        return [
            'result' => ['required', 'string', Rule::in(config('lead_workflow.demo_results', []))],
            'notes' => 'nullable|string|max:2000',
            'purchase_date' => ['nullable', 'required_if:result,Purchased,Purchasing', 'date'],
            'software_name' => 'nullable|string|max:120',
            'plan_purchased' => ['nullable', 'required_if:result,Purchased,Purchasing', 'string', Rule::in($plans)],
            'customer_name' => ['nullable', 'required_if:result,Purchased,Purchasing', 'string', 'max:255'],
            'firm_name' => ['nullable', 'required_if:result,Purchased,Purchasing', 'string', 'max:255'],
            'reference_name' => 'nullable|string|max:255',
            'mobile_no' => 'nullable|string|max:20',
            'city_name' => 'nullable|string|max:120',
            'points' => 'nullable|integer|min:0',
            'cooling_period_days' => 'nullable|integer|min:0',
            'total_amount' => ['nullable', 'required_if:result,Purchased,Purchasing', 'numeric', 'min:0'],
            'amount_received' => 'nullable|numeric|min:0',
            'invoice_number' => 'nullable|string|max:64',
            'employee_id' => 'nullable|integer|exists:employees,employee_id',
            'manager_id' => 'nullable|integer|exists:employees,employee_id',
        ];
    }
}
