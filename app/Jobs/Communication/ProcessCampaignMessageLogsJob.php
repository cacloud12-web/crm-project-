<?php

namespace App\Jobs\Communication;

use App\Services\Campaign\CampaignMessageLogProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessCampaignMessageLogsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly string $channel,
        public readonly int $campaignId,
    ) {}

    public function handle(CampaignMessageLogProcessor $processor): void
    {
        $processor->process($this->channel, $this->campaignId);
    }

    public function failed(Throwable $exception): void
    {
        app(CampaignMessageLogProcessor::class)->markFailed($this->channel, $this->campaignId);
    }
}
