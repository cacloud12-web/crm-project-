<?php

namespace App\Exceptions\Master;

use Exception;

class MasterRecordInUseException extends Exception
{
    /**
     * @param  list<array{module: string, count: int, filter_key?: string, filter_value?: int|string}>  $dependencies
     */
    public function __construct(
        string $message,
        private readonly array $dependencies = [],
        private readonly string $recordName = '',
        private readonly string $recommendedAction = 'deactivate',
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<array{module: string, count: int, filter_key?: string, filter_value?: int|string}>
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    public function recordName(): string
    {
        return $this->recordName;
    }

    public function recommendedAction(): string
    {
        return $this->recommendedAction;
    }

    public function totalDependencies(): int
    {
        return array_sum(array_column($this->dependencies, 'count'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiPayload(): array
    {
        return [
            'can_delete' => false,
            'total_dependencies' => $this->totalDependencies(),
            'dependencies' => $this->dependencies,
            'recommended_action' => $this->recommendedAction,
            'record_name' => $this->recordName,
            'message' => $this->getMessage(),
        ];
    }
}
