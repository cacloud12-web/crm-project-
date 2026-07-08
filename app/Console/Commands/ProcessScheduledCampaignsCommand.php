<?php

namespace App\Console\Commands;

use App\Models\EmailCampaign;
use App\Models\SmsCampaign;
use App\Models\WhatsAppCampaign;
use App\Services\Email\EmailCampaignService;
use App\Services\Sms\SmsCampaignService;
use App\Services\WhatsApp\WhatsAppCampaignService;
use Illuminate\Console\Command;
use Throwable;

class ProcessScheduledCampaignsCommand extends Command
{
    protected $signature = 'campaigns:process-scheduled';

    protected $description = 'Launch email, SMS, and WhatsApp campaigns whose scheduled time has passed';

    public function handle(
        EmailCampaignService $emailCampaignService,
        SmsCampaignService $smsCampaignService,
        WhatsAppCampaignService $whatsAppCampaignService,
    ): int {
        $now = now();
        $processed = 0;

        EmailCampaign::query()
            ->where('status', 'Scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')
            ->limit(20)
            ->get()
            ->each(function (EmailCampaign $campaign) use ($emailCampaignService, &$processed) {
                try {
                    $emailCampaignService->process($campaign->id);
                    $processed++;
                    $this->line('Email campaign #'.$campaign->id.' queued for delivery.');
                } catch (Throwable $exception) {
                    $this->error('Email campaign #'.$campaign->id.': '.$exception->getMessage());
                }
            });

        SmsCampaign::query()
            ->where('status', 'Scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')
            ->limit(20)
            ->get()
            ->each(function (SmsCampaign $campaign) use ($smsCampaignService, &$processed) {
                try {
                    $smsCampaignService->process($campaign->id);
                    $processed++;
                    $this->line('SMS campaign #'.$campaign->id.' queued for delivery.');
                } catch (Throwable $exception) {
                    $this->error('SMS campaign #'.$campaign->id.': '.$exception->getMessage());
                }
            });

        WhatsAppCampaign::query()
            ->where('status', 'Scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')
            ->limit(20)
            ->get()
            ->each(function (WhatsAppCampaign $campaign) use ($whatsAppCampaignService, &$processed) {
                try {
                    $whatsAppCampaignService->process($campaign->id);
                    $processed++;
                    $this->line('WhatsApp campaign #'.$campaign->id.' queued for delivery.');
                } catch (Throwable $exception) {
                    $this->error('WhatsApp campaign #'.$campaign->id.': '.$exception->getMessage());
                }
            });

        $this->info('Scheduled campaigns processed: '.$processed);

        return self::SUCCESS;
    }
}
