<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

trait ValidatesFutureSchedule
{
    protected function futureScheduleField(): string
    {
        return 'scheduled_at';
    }

    protected function futureScheduleMessage(): string
    {
        return 'Please select a future date and time.';
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $field = $this->futureScheduleField();
            $value = $this->input($field);

            if ($value === null || $value === '') {
                return;
            }

            try {
                $scheduledAt = Carbon::parse($value);
            } catch (\Throwable) {
                return;
            }

            if ($scheduledAt->lte(now())) {
                $validator->errors()->add($field, $this->futureScheduleMessage());
            }
        });
    }
}
