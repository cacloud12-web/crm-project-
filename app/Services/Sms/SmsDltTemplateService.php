<?php

namespace App\Services\Sms;

use App\Models\CaMaster;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Rbac\RbacService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SmsDltTemplateService
{
    public const DEFAULT_VARIABLE_FIELDS = [
        'ca_name',
        'firm_name',
        'city',
        'state',
        'mobile_no',
        'source',
        'rating',
        'team_size',
        'existing_software',
    ];

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly RbacService $rbacService,
    ) {}

    public function listApproved(): Collection
    {
        return SmsTemplate::query()
            ->where('status', SmsTemplate::STATUS_APPROVED)
            ->where('is_active', true)
            ->orderBy('template_name')
            ->get();
    }

    public function listAll(): Collection
    {
        return SmsTemplate::query()->orderBy('template_name')->get();
    }

    public function find(int $id): SmsTemplate
    {
        return SmsTemplate::query()->findOrFail($id);
    }

    public function findApproved(int $id): SmsTemplate
    {
        $template = $this->find($id);

        if (! $template->isApproved()) {
            throw ValidationException::withMessages([
                'sms_template_id' => ['Only approved DLT templates can be used for sending SMS.'],
            ]);
        }

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(SmsTemplate $template): array
    {
        return [
            'id' => $template->id,
            'template_name' => $template->template_name,
            'sender_id' => $template->sender_id,
            'dlt_template_id' => $template->dlt_template_id,
            'body_template' => $template->body_template,
            'variable_map' => $template->variable_map ?? $this->defaultVariableMap($template),
            'status' => $template->status,
            'is_active' => (bool) $template->is_active,
            'placeholder_count' => $template->placeholderCount(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(array $data, User $user): array
    {
        $this->ensureCanManageTemplates($user);

        $status = $data['status'] ?? SmsTemplate::STATUS_PENDING;
        $dltTemplateId = $data['dlt_template_id'] ?? null;

        if ($status === SmsTemplate::STATUS_APPROVED && ! filled($dltTemplateId)) {
            throw ValidationException::withMessages([
                'dlt_template_id' => ['DLT Template ID is required for approved SMS templates.'],
            ]);
        }

        $template = SmsTemplate::create([
            'template_name' => $data['template_name'],
            'sender_id' => $data['sender_id'],
            'dlt_template_id' => $dltTemplateId,
            'body_template' => $data['body_template'],
            'variable_map' => $data['variable_map'] ?? $this->inferVariableMap($data['body_template']),
            'status' => $data['status'] ?? SmsTemplate::STATUS_PENDING,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $this->activityLogService->log(
            'SMS_TEMPLATE',
            'SMS Template Created',
            (string) $template->id,
            $template->template_name,
            $user->name ?? $user->email ?? 'System',
        );

        return $this->toPublicArray($template);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(SmsTemplate $template, array $data, User $user): array
    {
        $this->ensureCanManageTemplates($user);

        $bodyTemplate = $data['body_template'] ?? $template->body_template;
        $status = $data['status'] ?? $template->status;
        $dltTemplateId = array_key_exists('dlt_template_id', $data)
            ? ($data['dlt_template_id'] !== '' ? $data['dlt_template_id'] : null)
            : $template->dlt_template_id;

        if ($status === SmsTemplate::STATUS_APPROVED && ! filled($dltTemplateId)) {
            throw ValidationException::withMessages([
                'dlt_template_id' => ['DLT Template ID is required for approved SMS templates.'],
            ]);
        }

        $template->update([
            'template_name' => $data['template_name'] ?? $template->template_name,
            'sender_id' => $data['sender_id'] ?? $template->sender_id,
            'dlt_template_id' => array_key_exists('dlt_template_id', $data)
                ? ($data['dlt_template_id'] !== '' ? $data['dlt_template_id'] : null)
                : $template->dlt_template_id,
            'body_template' => $bodyTemplate,
            'variable_map' => $data['variable_map'] ?? $this->inferVariableMap($bodyTemplate),
            'status' => $data['status'] ?? $template->status,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $template->is_active,
        ]);

        $this->activityLogService->log(
            'SMS_TEMPLATE',
            'SMS Template Updated',
            (string) $template->id,
            $template->template_name,
            $user->name ?? $user->email ?? 'System',
        );

        return $this->toPublicArray($template->fresh());
    }

    public function delete(SmsTemplate $template, User $user): void
    {
        $this->ensureCanManageTemplates($user);

        $name = $template->template_name;
        $id = (string) $template->id;
        $template->delete();

        $this->activityLogService->log(
            'SMS_TEMPLATE',
            'SMS Template Deleted',
            $id,
            $name,
            $user->name ?? $user->email ?? 'System',
        );
    }

    public function renderBody(SmsTemplate $template, CaMaster $lead): string
    {
        $lead->loadMissing(['city', 'state', 'sourceLead']);
        $variableMap = $template->variable_map ?? $this->defaultVariableMap($template);
        $values = collect($variableMap)
            ->map(fn (string $field) => $this->resolveLeadValue($lead, $field))
            ->values()
            ->all();

        $index = 0;

        return (string) preg_replace_callback(
            '/\{#var#\}/',
            function () use (&$index, $values) {
                return $values[$index++] ?? '';
            },
            $template->body_template,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(SmsTemplate $template, int $leadId): array
    {
        $lead = CaMaster::query()->with(['city', 'state', 'sourceLead'])->findOrFail($leadId);
        $rendered = $this->renderBody($template, $lead);

        return [
            'template_id' => $template->id,
            'template_name' => $template->template_name,
            'sender_id' => $template->sender_id,
            'dlt_template_id' => $template->dlt_template_id,
            'lead_id' => $lead->ca_id,
            'firm_name' => $lead->firm_name,
            'mobile_no' => $lead->mobile_no,
            'body_template' => $template->body_template,
            'preview' => $rendered,
            'character_count' => mb_strlen($rendered),
            'sms_count' => (int) max(1, ceil(mb_strlen($rendered) / 160)),
        ];
    }

    public function ensureCanViewTemplates(?User $user): void
    {
        if (! $this->canViewTemplates($user)) {
            throw new AuthorizationException('You do not have access to SMS templates.');
        }
    }

    public function ensureCanManageTemplates(?User $user): void
    {
        if (! $this->canManageTemplates($user)) {
            throw new AuthorizationException('Only Admin and Super Admin can manage SMS templates.');
        }
    }

    public function canViewTemplates(?User $user): bool
    {
        return in_array($this->rbacService->roleKey($user), ['admin', 'super_admin', 'manager', 'employee'], true);
    }

    public function canManageTemplates(?User $user): bool
    {
        return in_array($this->rbacService->roleKey($user), ['admin', 'super_admin'], true);
    }

    /**
     * @return array<int, string>
     */
    public function inferVariableMap(string $bodyTemplate): array
    {
        $count = preg_match_all('/\{#var#\}/', $bodyTemplate) ?: 0;
        if ($count === 0) {
            return [];
        }

        return array_slice(self::DEFAULT_VARIABLE_FIELDS, 0, $count);
    }

    /**
     * @return array<int, string>
     */
    public function defaultVariableMap(SmsTemplate $template): array
    {
        return $this->inferVariableMap((string) $template->body_template);
    }

    private function resolveLeadValue(CaMaster $lead, string $field): string
    {
        if (str_starts_with($field, 'static:')) {
            return substr($field, 7);
        }

        return match ($field) {
            'ca_name', 'name' => (string) ($lead->ca_name ?? ''),
            'firm_name' => (string) ($lead->firm_name ?? ''),
            'mobile', 'mobile_no' => (string) ($lead->mobile_no ?? ''),
            'city' => (string) ($lead->city?->city_name ?? ''),
            'state' => (string) ($lead->state?->state_name ?? ''),
            'source' => (string) ($lead->sourceLead?->source_name ?? ''),
            'rating' => (string) ($lead->rating ?? ''),
            'team_size' => (string) ($lead->team_size ?? ''),
            'existing_software' => (string) ($lead->existing_software ?? ''),
            default => '',
        };
    }
}
