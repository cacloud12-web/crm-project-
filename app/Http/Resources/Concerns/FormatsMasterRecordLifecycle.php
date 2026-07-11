<?php

namespace App\Http\Resources\Concerns;

trait FormatsMasterRecordLifecycle
{
    /**
     * @return array<string, mixed>
     */
    protected function masterLifecycleFields(): array
    {
        return [
            'is_active' => (bool) ($this->is_active ?? true),
            'is_system' => (bool) ($this->is_system ?? false),
            'deactivated_at' => $this->deactivated_at,
            'deactivated_by' => $this->deactivated_by,
        ];
    }
}
