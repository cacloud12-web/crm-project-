<?php

namespace App\Http\Requests\Concerns;

use App\Models\FollowUp;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

trait ValidatesFollowUpSchedule
{
    protected function appendFollowUpScheduleValidation(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $value = $this->input('scheduled_date');

            if ($value === null || $value === '') {
                return;
            }

            try {
                $scheduledAt = Carbon::parse($value);
            } catch (\Throwable) {
                return;
            }

            if ($scheduledAt->gt(now())) {
                return;
            }

            $followUpId = $this->route('follow_up') ?? $this->route('followup') ?? $this->route('id');
            if ($followUpId) {
                $existing = FollowUp::query()->find($followUpId);
                if ($existing?->scheduled_date && Carbon::parse($existing->scheduled_date)->equalTo($scheduledAt)) {
                    return;
                }
            }

            $validator->errors()->add('scheduled_date', 'Please select a future date and time.');
        });
    }
}
