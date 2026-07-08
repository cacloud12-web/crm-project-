<?php

namespace App\Jobs\Sms;

use App\Services\Sms\SmsCampaignService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessSmsCampaignJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $campaignId,
    ) {}

    public function uniqueId(): string
    {
        return 'sms-campaign-process-'.$this->campaignId;
    }

    public function handle(SmsCampaignService $smsCampaignService): void
    {
        $smsCampaignService->runProcess($this->campaignId);
    }

    public function failed(Throwable $exception): void
    {
        app(SmsCampaignService::class)->markProcessFailed($this->campaignId, $exception->getMessage());
    }
}
