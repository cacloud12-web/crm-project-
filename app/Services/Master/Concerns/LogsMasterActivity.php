<?php

namespace App\Services\Master\Concerns;

use App\Services\Activity\ActivityLogService;

trait LogsMasterActivity
{
    protected function logMasterActivity(string $action, string $entity, string $recordId, string $description): void
    {
        app(ActivityLogService::class)->log(
            'CA_MASTER',
            $action,
            $recordId,
            $entity.': '.$description,
        );
    }
}
