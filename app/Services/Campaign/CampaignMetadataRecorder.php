<?php

namespace App\Services\Campaign;

use App\Models\EmailSetting;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\Email\EmailAccountService;
use App\Services\Email\EmailSettingsService;
use App\Services\Email\EmailTemplateService;
use App\Services\Sms\SmsDltTemplateService;
use App\Services\Sms\SmsSettingsService;
use App\Services\WhatsApp\WhatsAppSettingsService;

class CampaignMetadataRecorder
{
    public function __construct(
        private readonly EmailSettingsService $emailSettingsService,
        private readonly EmailAccountService $emailAccountService,
        private readonly EmailTemplateService $emailTemplateService,
        private readonly SmsSettingsService $smsSettingsService,
        private readonly SmsDltTemplateService $smsDltTemplateService,
        private readonly WhatsAppSettingsService $whatsappSettingsService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function emailCreateAttributes(array $data): array
    {
        $user = auth()->user();
        $accountId = isset($data['email_config_id']) ? (int) $data['email_config_id'] : null;
        $account = $accountId
            ? $this->emailAccountService->find($accountId)
            : EmailSetting::query()->where('is_default', true)->first()
                ?? EmailSetting::query()->orderBy('id')->first();

        $template = null;
        if (! empty($data['email_template_id'])) {
            $template = $this->emailTemplateService->findActive((int) $data['email_template_id']);
        }

        return [
            'created_by_user_id' => $user?->id,
            'performed_by' => $data['performed_by'] ?? $this->performerName($user),
            'sender_config_id' => $account instanceof EmailSetting ? $account->id : ($account?->id ?? null),
            'sender_snapshot' => $this->emailSenderSnapshot($account),
            'template_snapshot' => $this->emailTemplateSnapshot($template, $data),
            'status_history' => [[
                'status' => 'Draft',
                'note' => 'Campaign created',
                'at' => now()->toIso8601String(),
                'by' => $this->performerName($user),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function smsCreateAttributes(array $data, ?SmsTemplate $template = null): array
    {
        $user = auth()->user();
        $settings = $this->smsSettingsService->current();

        return [
            'created_by_user_id' => $user?->id,
            'performed_by' => $data['performed_by'] ?? $this->performerName($user),
            'sender_config_id' => $settings->id ?? null,
            'sender_snapshot' => [
                'provider_name' => $settings->provider_name ?? null,
                'sender_id' => $template?->sender_id ?? $data['sender_id'] ?? $settings->sender_id ?? null,
                'integration_status' => $settings->integration_status ?? null,
            ],
            'template_snapshot' => $template ? [
                'id' => $template->id,
                'template_name' => $template->template_name,
                'dlt_template_id' => $template->dlt_template_id,
                'body_template' => $template->body_template,
                'sender_id' => $template->sender_id,
            ] : [
                'body_template' => $data['message_template'] ?? null,
            ],
            'status_history' => [[
                'status' => 'Draft',
                'note' => 'Campaign created',
                'at' => now()->toIso8601String(),
                'by' => $this->performerName($user),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function whatsappCreateAttributes(array $data, ?object $template = null): array
    {
        $user = auth()->user();
        $settings = $this->whatsappSettingsService->current();

        return [
            'created_by_user_id' => $user?->id,
            'performed_by' => $data['performed_by'] ?? $this->performerName($user),
            'sender_config_id' => $settings->id ?? null,
            'sender_snapshot' => [
                'provider_name' => $settings->provider_name ?? 'Meta WhatsApp Cloud API',
                'phone_number_id' => $settings->phone_number_id ?? null,
                'display_phone_number' => $settings->display_phone_number ?? null,
                'integration_status' => $settings->integration_status ?? null,
            ],
            'template_snapshot' => $template ? [
                'id' => $template->id ?? null,
                'template_name' => $template->template_name ?? $data['template_name'] ?? null,
                'language_code' => $template->language_code ?? $data['language_code'] ?? null,
                'body_template' => $template->body_template ?? $data['message_template'] ?? null,
            ] : [
                'template_name' => $data['template_name'] ?? null,
                'body_template' => $data['message_template'] ?? null,
            ],
            'status_history' => [[
                'status' => 'Draft',
                'note' => 'Campaign created',
                'at' => now()->toIso8601String(),
                'by' => $this->performerName($user),
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emailSenderSnapshot(mixed $account): array
    {
        if ($account === null) {
            return [];
        }

        return [
            'id' => $account->id ?? null,
            'account_name' => $account->account_name ?? $account->provider_name ?? null,
            'from_email' => $account->from_email ?? $account->smtp_username ?? null,
            'from_name' => $account->from_name ?? null,
            'is_default' => (bool) ($account->is_default ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function emailTemplateSnapshot(?object $template, array $data): array
    {
        if ($template) {
            return [
                'id' => $template->id,
                'template_name' => $template->template_name ?? null,
                'subject' => $template->subject ?? null,
                'body' => $template->body ?? null,
            ];
        }

        return [
            'subject' => $data['subject'] ?? null,
            'body' => $data['body_template'] ?? null,
        ];
    }

    private function performerName(?User $user): string
    {
        if ($user?->name) {
            return $user->name;
        }

        if ($user?->email) {
            return $user->email;
        }

        return 'System';
    }
}
