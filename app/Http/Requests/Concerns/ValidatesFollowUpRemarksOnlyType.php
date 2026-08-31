<?php

namespace App\Http\Requests\Concerns;

trait ValidatesFollowUpRemarksOnlyType
{
    /** @var list<string> */
    protected function remarksOnlyFollowUpTypes(): array
    {
        return config('crm_followups.remarks_only_types', ['Not Interested', 'Do Not Disturb']);
    }

    protected function isRemarksOnlyFollowUpType(): bool
    {
        return in_array((string) $this->input('followup_type'), $this->remarksOnlyFollowUpTypes(), true);
    }
}
