<?php

namespace App\Http\Requests\Concerns;

trait ValidatesFollowUpRemarksOnlyType
{
    /** @var list<string> */
    protected function remarksOnlyFollowUpTypes(): array
    {
        return ['Not Interested'];
    }

    protected function isRemarksOnlyFollowUpType(): bool
    {
        return in_array((string) $this->input('followup_type'), $this->remarksOnlyFollowUpTypes(), true);
    }
}
