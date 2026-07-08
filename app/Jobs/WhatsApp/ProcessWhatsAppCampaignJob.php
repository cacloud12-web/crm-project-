<?php

namespace App\Jobs\WhatsApp;

use App\Services\WhatsApp\WhatsAppCampaignService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWhatsAppCampaignJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $campaignId,
    ) {}

    public function uniqueId(): string
    {
        return 'whatsapp-campaign-process-'.$this->campaignId;
    }

    public function handle(WhatsAppCampaignService $whatsAppCampaignService): void
    {
        $whatsAppCampaignService->runProcess($this->campaignId);
    }

    public function failed(Throwable $exception): void
    {
        app(WhatsAppCampaignService::class)->markProcessFailed($this->campaignId, $exception->getMessage());
    }
}
