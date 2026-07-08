<?php

namespace App\Services\Campaign;

use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\SmsCampaign;
use App\Models\SmsLog;
use App\Models\WaMessageLog;
use App\Models\WhatsAppCampaign;
use App\Services\Activity\ActivityLogService;
use App\Services\Email\EmailCampaignService;
use App\Services\Email\EmailRecipientValidationService;
use App\Services\Sms\SmsCampaignService;
use App\Services\WhatsApp\WhatsAppCampaignService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CampaignActionService
{
    public function __construct(
        private readonly CampaignScopeService $scopeService,
        private readonly EmailCampaignService $emailCampaignService,
        private readonly SmsCampaignService $smsCampaignService,
        private readonly WhatsAppCampaignService $whatsappCampaignService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function duplicate(string $channel, int|string $id): Model
    {
        $source = $this->scopeService->ensureCanMutateCampaign($channel, $id, 'duplicate');

        return DB::transaction(function () use ($source, $channel) {
            $attrs = $source->toArray();
            unset($attrs['id'], $attrs['created_at'], $attrs['updated_at']);
            $attrs['campaign_uuid'] = (string) Str::uuid();
            $attrs['campaign_name'] = $this->duplicateName((string) $source->campaign_name);
            $attrs['status'] = 'Draft';
            $attrs['scheduled_at'] = null;
            $attrs['paused_at'] = null;
            $attrs['cancelled_at'] = null;
            $attrs['completed_at'] = null;
            $attrs['retry_count'] = 0;
            $attrs['created_by_user_id'] = auth()->id();
            $attrs['performed_by'] = auth()->user()?->name ?? auth()->user()?->email ?? 'System';
            $history = is_array($attrs['status_history'] ?? null) ? $attrs['status_history'] : [];
            $history[] = [
                'status' => 'Draft',
                'note' => 'Duplicated from campaign #'.$source->id,
                'at' => now()->toIso8601String(),
                'by' => $attrs['performed_by'],
            ];
            $attrs['status_history'] = $history;

            return match (strtolower($channel)) {
                'email' => EmailCampaign::query()->create($attrs),
                'sms' => SmsCampaign::query()->create($attrs),
                'whatsapp' => WhatsAppCampaign::query()->create($attrs),
                default => throw new InvalidArgumentException('Unsupported campaign channel.'),
            };
        });
    }

    public function pause(string $channel, int|string $id): Model
    {
        $campaign = $this->scopeService->ensureCanMutateCampaign($channel, $id, 'pause');

        if (! in_array($campaign->status, ['Scheduled', 'Processing', 'Draft'], true)) {
            throw new InvalidArgumentException('Only draft, scheduled, or processing campaigns can be paused.');
        }

        $campaign->recordStatusChange('Paused', 'Campaign paused by user');
        $campaign->paused_at = now();
        $campaign->save();

        return $campaign->fresh();
    }

    public function resume(string $channel, int|string $id): Model
    {
        $campaign = $this->scopeService->ensureCanMutateCampaign($channel, $id, 'resume');

        if ($campaign->status !== 'Paused') {
            throw new InvalidArgumentException('Campaign is not paused.');
        }

        $previous = $this->previousStatusFromHistory($campaign) ?? 'Scheduled';
        $campaign->recordStatusChange($previous, 'Campaign resumed by user');
        $campaign->paused_at = null;
        $campaign->save();

        return $campaign->fresh();
    }

    public function cancel(string $channel, int|string $id): Model
    {
        $campaign = $this->scopeService->ensureCanMutateCampaign($channel, $id, 'cancel');

        if (in_array($campaign->status, ['Completed', 'Cancelled'], true)) {
            throw new InvalidArgumentException('Campaign cannot be cancelled in its current state.');
        }

        $campaign->recordStatusChange('Cancelled', 'Campaign cancelled by user');
        $campaign->cancelled_at = now();
        $campaign->save();

        return $campaign->fresh();
    }

    public function retryFailed(string $channel, int|string $id): Model
    {
        $this->scopeService->ensureCanMutateCampaign($channel, $id, 'retry');

        return match (strtolower($channel)) {
            'email' => $this->emailCampaignService->retryFailed($id),
            'sms' => $this->retrySmsFailed($id),
            'whatsapp' => $this->retryWhatsappFailed($id),
            default => throw new InvalidArgumentException('Unsupported campaign channel.'),
        };
    }

    public function delete(string $channel, int|string $id): void
    {
        $campaign = $this->scopeService->ensureCanMutateCampaign($channel, $id, 'delete');

        match (strtolower($channel)) {
            'email' => $this->emailCampaignService->delete($campaign),
            'sms' => $this->smsCampaignService->delete($campaign),
            'whatsapp' => $this->whatsappCampaignService->delete($campaign),
            default => throw new InvalidArgumentException('Unsupported campaign channel.'),
        };
    }

    /**
     * @return array{filename: string, content: string, mime: string}
     */
    public function exportReport(string $channel, int|string $id): array
    {
        $campaign = $this->scopeService->ensureCanAccessCampaign($channel, $id);
        $rows = app(UnifiedCampaignService::class)->recipientRows($channel, (int) $campaign->id);

        $headers = array_keys($rows[0] ?? ['lead' => '', 'status' => '']);
        $lines = [implode(',', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                fn ($value) => '"'.str_replace('"', '""', (string) $value).'"',
                array_values($row),
            ));
        }

        return [
            'filename' => strtolower($channel).'-campaign-'.$campaign->id.'-report.csv',
            'content' => implode("\n", $lines),
            'mime' => 'text/csv',
        ];
    }

    private function retrySmsFailed(int|string $id): SmsCampaign
    {
        return DB::transaction(function () use ($id) {
            $campaign = SmsCampaign::query()->findOrFail($id);
            $failed = SmsLog::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('sms_status', ['Failed', 'API Error'])
                ->count();

            if ($failed === 0) {
                throw new InvalidArgumentException('No failed messages to retry.');
            }

            SmsLog::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('sms_status', ['Failed', 'API Error'])
                ->update([
                    'sms_status' => 'Pending',
                    'error_message' => null,
                    'failed_reason' => null,
                ]);

            $campaign->update([
                'status' => 'Draft',
                'retry_count' => (int) $campaign->retry_count + 1,
            ]);

            return $this->smsCampaignService->process($campaign->id);
        });
    }

    private function retryWhatsappFailed(int|string $id): WhatsAppCampaign
    {
        return DB::transaction(function () use ($id) {
            $campaign = WhatsAppCampaign::query()->findOrFail($id);
            $failed = WaMessageLog::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('message_status', ['Failed', 'API Error'])
                ->count();

            if ($failed === 0) {
                throw new InvalidArgumentException('No failed messages to retry.');
            }

            WaMessageLog::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('message_status', ['Failed', 'API Error'])
                ->delete();

            $campaign->update([
                'retry_count' => (int) $campaign->retry_count + 1,
            ]);

            return $this->whatsappCampaignService->process($campaign->id);
        });
    }

    private function duplicateName(string $name): string
    {
        return str_ends_with($name, ' (Copy)') ? $name : $name.' (Copy)';
    }

    private function previousStatusFromHistory(Model $campaign): ?string
    {
        $history = is_array($campaign->status_history) ? array_reverse($campaign->status_history) : [];
        foreach ($history as $entry) {
            $status = (string) ($entry['status'] ?? '');
            if ($status !== '' && $status !== 'Paused') {
                return $status;
            }
        }

        return null;
    }
}
