<?php

namespace App\Services\Templates;

use App\Models\CaMaster;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Rbac\RbacService;
use App\Services\WhatsApp\WhatsAppMetaTemplateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WhatsAppTemplateManagementService
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly CrmTemplateRenderService $renderService,
        private readonly ActivityLogService $activityLogService,
        private readonly WhatsAppMetaTemplateService $metaTemplateService,
    ) {}

    public function ensureCanView(?User $user): void
    {
        if (! $user) {
            throw new AuthorizationException('Authentication required.');
        }
        $role = $this->rbacService->roleKey($user);
        if (! in_array($role, ['super_admin', 'admin', 'manager', 'employee'], true)) {
            throw new AuthorizationException('You do not have access to WhatsApp templates.');
        }
    }

    public function ensureCanManage(?User $user): void
    {
        $this->ensureCanView($user);
        $role = $this->rbacService->roleKey($user);
        if (! in_array($role, ['super_admin', 'admin', 'manager'], true)) {
            throw new AuthorizationException('You do not have permission to manage WhatsApp templates.');
        }
    }

    public function paginate(array $filters = [], ?User $user = null): LengthAwarePaginator
    {
        $this->ensureCanView($user);
        $employeeViewOnly = $this->rbacService->roleKey($user) === 'employee';

        $query = MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->with(['creator', 'editor']);

        if ($employeeViewOnly) {
            $query->where('publish_status', 'active')->where('is_active', true);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('display_name', 'ilike', $term)
                    ->orWhere('template_name', 'ilike', $term)
                    ->orWhere('category', 'ilike', $term);
            });
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['publish_status'])) {
            $query->where('publish_status', $filters['publish_status']);
        }

        $sort = $filters['sort'] ?? 'updated_at';
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir);

        return $query->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function listApproved(bool $dispatchableOnly = false): Collection
    {
        return MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('publish_status', 'active')
            ->where('is_active', true)
            ->when($dispatchableOnly, fn ($q) => $q->whereNotNull('meta_api_name')->where('meta_api_name', '!=', ''))
            ->orderBy('display_name')
            ->get();
    }

    public function find(int $id): MessageTemplate
    {
        return MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->with(['creator', 'editor'])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $user = null): MessageTemplate
    {
        $this->ensureCanManage($user);
        $templateName = $this->uniqueTemplateName($data['name']);

        return MessageTemplate::query()->create([
            'channel' => MessageTemplate::CHANNEL_WHATSAPP,
            'template_name' => $templateName,
            'display_name' => $data['name'],
            'category' => $data['category'],
            'header' => $data['header'] ?? null,
            'body_template' => $data['body'],
            'footer' => $data['footer'] ?? null,
            'language_code' => $data['language_code'] ?? 'en',
            'status' => MessageTemplate::STATUS_PENDING,
            'publish_status' => $data['publish_status'] ?? 'draft',
            'is_active' => ($data['publish_status'] ?? 'draft') === 'active',
            'variable_map' => $this->extractVariables($data['body'] ?? '', $data['header'] ?? '', $data['footer'] ?? ''),
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MessageTemplate $template, array $data, ?User $user = null): MessageTemplate
    {
        $this->ensureCanManage($user);

        $template->fill([
            'display_name' => $data['name'] ?? $template->display_name,
            'category' => $data['category'] ?? $template->category,
            'header' => array_key_exists('header', $data) ? $data['header'] : $template->header,
            'body_template' => $data['body'] ?? $template->body_template,
            'footer' => array_key_exists('footer', $data) ? $data['footer'] : $template->footer,
            'publish_status' => $data['publish_status'] ?? $template->publish_status,
            'is_active' => ($data['publish_status'] ?? $template->publish_status) === 'active',
            'meta_api_name' => array_key_exists('meta_api_name', $data) ? $data['meta_api_name'] : $template->meta_api_name,
            'updated_by' => $user?->id,
        ]);
        $template->variable_map = $this->extractVariables(
            (string) $template->body_template,
            (string) ($template->header ?? ''),
            (string) ($template->footer ?? ''),
        );
        $template->save();

        return $template->fresh(['creator', 'editor']);
    }

    public function duplicate(MessageTemplate $template, ?User $user = null): MessageTemplate
    {
        $this->ensureCanManage($user);
        $copy = $template->replicate(['template_name', 'meta_api_name', 'meta_template_id', 'meta_status']);
        $copy->display_name = $template->display_name.' (Copy)';
        $copy->template_name = $this->uniqueTemplateName($copy->display_name);
        $copy->publish_status = 'draft';
        $copy->is_active = false;
        $copy->status = MessageTemplate::STATUS_PENDING;
        $copy->meta_api_name = null;
        $copy->meta_template_id = null;
        $copy->meta_status = null;
        $copy->created_by = $user?->id;
        $copy->updated_by = $user?->id;
        $copy->save();

        return $copy;
    }

    public function setPublishStatus(MessageTemplate $template, string $status, ?User $user = null): MessageTemplate
    {
        $this->ensureCanManage($user);
        $template->update([
            'publish_status' => $status,
            'is_active' => $status === 'active',
            'updated_by' => $user?->id,
        ]);

        return $template->fresh(['creator', 'editor']);
    }

    public function delete(MessageTemplate $template, ?User $user = null): void
    {
        $this->ensureCanManage($user);
        $template->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(MessageTemplate $template, ?CaMaster $lead, ?User $sender): array
    {
        $header = $this->renderService->render((string) ($template->header ?? ''), $lead, $sender);
        $body = $this->renderService->render((string) $template->body_template, $lead, $sender);
        $footer = $this->renderService->render((string) ($template->footer ?? ''), $lead, $sender);

        return [
            'header' => $header,
            'body' => trim(implode("\n\n", array_filter([$header, $body, $footer]))),
            'footer' => $footer,
            'preview' => $this->renderService->formatBodyPreview(trim(implode("\n\n", array_filter([$header, $body, $footer])))),
        ];
    }

    /**
     * @return array{template: MessageTemplate, message: string}
     */
    public function submitToMeta(MessageTemplate $template, ?User $user = null): array
    {
        $this->ensureCanManage($user);
        $result = $this->metaTemplateService->createOnMeta($template);
        $this->activityLogService->log(
            'WHATSAPP_SETTINGS',
            'Template Submitted to Meta',
            (string) $template->id,
            $template->display_name ?? $template->template_name,
            $user?->name ?? 'System',
        );

        return $result;
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
            'name' => $template->display_name ?? $template->template_name,
            'title' => $template->display_name ?? $template->template_name,
            'meta_api_name' => $template->meta_api_name,
            'meta_template_id' => $template->meta_template_id,
            'meta_status' => $template->meta_status,
            'meta_rejection_reason' => $template->meta_rejection_reason,
            'meta_submitted_at' => $template->meta_submitted_at?->toIso8601String(),
            'meta_status_updated_at' => $template->meta_status_updated_at?->toIso8601String(),
            'meta_template_name' => $template->metaApiTemplateName(),
            'display_name' => $template->display_name,
            'language_code' => $template->language_code,
            'body' => $template->body_template,
            'body_template' => $template->body_template,
            'header' => $template->header,
            'footer' => $template->footer,
            'status' => $template->status,
            'publish_status' => $template->publish_status ?? 'active',
            'category' => $template->category,
            'variable_map' => $template->variable_map,
            'is_active' => (bool) $template->is_active,
            'type' => 'whatsapp',
            'created_by_name' => $template->creator?->name,
            'updated_by_name' => $template->editor?->name,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    private function extractVariables(string ...$parts): array
    {
        $found = [];
        foreach ($parts as $part) {
            if (preg_match_all('/\{\{[A-Z0-9_]+\}\}/', $part, $matches)) {
                foreach ($matches[0] as $token) {
                    $found[$token] = true;
                }
            }
        }

        return array_keys($found);
    }

    private function uniqueTemplateName(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'whatsapp_template';
        $slug = $base;
        $i = 1;
        while (MessageTemplate::query()->where('channel', MessageTemplate::CHANNEL_WHATSAPP)->where('template_name', $slug)->exists()) {
            $slug = $base.'_'.$i++;
        }

        return $slug;
    }
}
