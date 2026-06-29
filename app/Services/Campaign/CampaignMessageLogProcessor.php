<?php

namespace App\Services\Campaign;

use App\Jobs\Communication\ProcessCampaignMessageLogsJob;
use App\Services\Email\EmailCampaignService;
use App\Services\Sms\SmsCampaignService;
use App\Services\WhatsApp\WhatsAppCampaignService;
use InvalidArgumentException;

class CampaignMessageLogProcessor
{
    public function shouldQueue(int $recipientCount): bool
    {
        return $recipientCount > (int) config('crm_queue.campaign_log_sync_limit', 50);
    }

    public function dispatch(string $channel, int $campaignId): void
    {
        ProcessCampaignMessageLogsJob::dispatch($channel, $campaignId);
    }

    public function process(string $channel, int $campaignId): void
    {
        match ($channel) {
            'whatsapp' => app(WhatsAppCampaignService::class)->generateMessageLogs($campaignId),
            'email' => app(EmailCampaignService::class)->generateMessageLogs($campaignId),
            'sms' => app(SmsCampaignService::class)->generateMessageLogs($campaignId),
            default => throw new InvalidArgumentException('Unknown campaign channel: '.$channel),
        };
    }

    public function markFailed(string $channel, int $campaignId): void
    {
        match ($channel) {
            'whatsapp' => app(WhatsAppCampaignService::class)->markLogGenerationFailed($campaignId),
            'email' => app(EmailCampaignService::class)->markLogGenerationFailed($campaignId),
            'sms' => app(SmsCampaignService::class)->markLogGenerationFailed($campaignId),
            default => null,
        };
    }
}
