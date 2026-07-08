<?php

namespace App\Jobs\Email;

use App\Services\Email\EmailCampaignService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessEmailCampaignDeliveryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $campaignId,
        public readonly string $dispatchToken,
    ) {}

    public function uniqueId(): string
    {
        return 'email-campaign-delivery-'.$this->campaignId;
    }

    public function handle(EmailCampaignService $emailCampaignService): void
    {
        $emailCampaignService->runDelivery($this->campaignId, $this->dispatchToken);
    }

    public function failed(Throwable $exception): void
    {
        app(EmailCampaignService::class)->markDeliveryFailed($this->campaignId, $exception->getMessage());
    }
}
