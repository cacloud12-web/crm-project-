<?php

namespace App\Services\WhatsApp;

use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Rbac\RbacService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WhatsAppTemplateService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly RbacService $rbacService,
    ) {}

    public function listApproved(string $channel = MessageTemplate::CHANNEL_WHATSAPP, bool $dispatchableOnly = false): Collection
    {
        return MessageTemplate::query()
            ->where('channel', $channel)
            ->where('status', MessageTemplate::STATUS_APPROVED)
            ->where('is_active', true)
            ->when($dispatchableOnly, fn ($query) => $query
                ->whereNotNull('meta_api_name')
                ->where('meta_api_name', '!=', '')
                ->where(function ($inner) {
                    $inner->whereNull('meta_status')
                        ->orWhereIn('meta_status', ['APPROVED', 'REINSTATED']);
                }))
            ->orderBy('template_name')
            ->get();
    }

    public function findApproved(int $id): MessageTemplate
    {
        $template = MessageTemplate::query()->findOrFail($id);

        if (! $template->isApproved()) {
            throw ValidationException::withMessages([
                'message_template_id' => ['Only approved templates can be used for campaigns.'],
            ]);
        }

        return $template;
    }

    public function findByName(string $templateName, string $languageCode = 'en'): ?MessageTemplate
    {
        return MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', $templateName)
            ->where('language_code', $languageCode)
            ->where('status', MessageTemplate::STATUS_APPROVED)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(MessageTemplate $template): array
    {
        return [
            'id' => $template->id,
            'channel' => $template->channel,
            'template_name' => $template->template_name,
            'meta_api_name' => $template->meta_api_name,
            'meta_template_id' => $template->meta_template_id,
            'meta_status' => $template->meta_status,
            'meta_rejection_reason' => $template->meta_rejection_reason,
            'meta_submitted_at' => $template->meta_submitted_at?->toIso8601String(),
            'meta_status_updated_at' => $template->meta_status_updated_at?->toIso8601String(),
            'meta_template_name' => $template->metaApiTemplateName(),
            'display_name' => $template->display_name,
            'language_code' => $template->language_code,
            'body_template' => $template->body_template,
            'status' => $template->status,
            'category' => $template->category,
            'variable_map' => $template->variable_map,
            'is_active' => (bool) $template->is_active,
        ];
    }

    public function logTemplateSelected(MessageTemplate $template, ?User $user = null): void
    {
        $this->activityLogService->log(
            'WHATSAPP_SETTINGS',
            'Template Selected',
            (string) $template->id,
            $template->template_name.' ('.$template->language_code.')',
            $user?->name ?? $user?->email ?? 'System',
        );
    }

    public function ensureCanViewTemplates(?User $user): void
    {
        $role = $this->rbacService->roleKey($user);

        if (! in_array($role, ['admin', 'super_admin', 'manager', 'employee'], true)) {
            throw new AuthorizationException('You do not have access to WhatsApp templates.');
        }
    }

    public function ensureCanManageTemplates(?User $user): void
    {
        $role = $this->rbacService->roleKey($user);

        if (! in_array($role, ['admin', 'super_admin', 'manager'], true)) {
            throw new AuthorizationException('Only Admin, Super Admin, and Manager can manage WhatsApp templates.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function updateMetaMapping(MessageTemplate $template, array $data, ?User $user = null): array
    {
        $this->ensureCanManageTemplates($user);

        $template->update([
            'meta_api_name' => array_key_exists('meta_api_name', $data)
                ? ($data['meta_api_name'] !== '' ? $data['meta_api_name'] : null)
                : $template->meta_api_name,
            'body_template' => $data['body_template'] ?? $template->body_template,
            'variable_map' => $data['variable_map'] ?? $template->variable_map,
            'display_name' => $data['display_name'] ?? $template->display_name,
            'status' => MessageTemplate::STATUS_APPROVED,
            'meta_status' => 'APPROVED',
            'meta_status_updated_at' => now(),
            'meta_rejection_reason' => null,
            'is_active' => true,
        ]);

        $this->activityLogService->log(
            'WHATSAPP_SETTINGS',
            'Template Meta Mapping Updated',
            (string) $template->id,
            $template->metaApiTemplateName().' ('.$template->language_code.')',
            $user?->name ?? $user?->email ?? 'System',
        );

        return $this->toPublicArray($template->fresh());
    }
}
