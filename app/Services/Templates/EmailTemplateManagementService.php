<?php

namespace App\Services\Templates;

use App\Models\CaMaster;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Email\GoDaddyMailService;
use App\Services\Rbac\RbacService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailTemplateManagementService
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly CrmTemplateRenderService $renderService,
    ) {}

    public function ensureCanView(?User $user): void
    {
        if (! $user) {
            throw new AuthorizationException('Authentication required.');
        }

        $role = $this->rbacService->roleKey($user);
        if (! in_array($role, ['super_admin', 'admin', 'manager', 'employee'], true)) {
            throw new AuthorizationException('You do not have access to email templates.');
        }
    }

    public function ensureCanManage(?User $user): void
    {
        $this->ensureCanView($user);
        $role = $this->rbacService->roleKey($user);
        if (! in_array($role, ['super_admin', 'admin', 'manager'], true)) {
            throw new AuthorizationException('You do not have permission to manage email templates.');
        }
    }

    public function paginate(array $filters = [], ?User $user = null): LengthAwarePaginator
    {
        $this->ensureCanView($user);
        $role = $this->rbacService->roleKey($user);
        $employeeViewOnly = $role === 'employee';

        $query = EmailTemplate::query()->with(['creator', 'editor']);

        if ($employeeViewOnly) {
            $query->where('publish_status', 'active')->where('is_active', true);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'ilike', $term)
                    ->orWhere('subject', 'ilike', $term)
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

    public function listActive(): Collection
    {
        return EmailTemplate::query()
            ->where('is_active', true)
            ->where('publish_status', 'active')
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): EmailTemplate
    {
        return EmailTemplate::query()->with(['creator', 'editor'])->findOrFail($id);
    }

    public function findActive(int $id): EmailTemplate
    {
        $template = $this->find($id);
        if ($template->publish_status !== 'active' || ! $template->is_active) {
            throw ValidationException::withMessages([
                'email_template_id' => ['Selected email template is not active.'],
            ]);
        }

        return $template;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $user = null): EmailTemplate
    {
        $this->ensureCanManage($user);
        $slug = $this->uniqueSlug($data['name']);

        return EmailTemplate::query()->create([
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'category' => $data['category'],
            'header' => $data['header'] ?? null,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'footer' => $data['footer'] ?? null,
            'variables' => $this->extractVariables($data['body'] ?? '', $data['subject'] ?? '', $data['header'] ?? '', $data['footer'] ?? ''),
            'is_active' => ($data['publish_status'] ?? 'draft') === 'active',
            'publish_status' => $data['publish_status'] ?? 'draft',
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EmailTemplate $template, array $data, ?User $user = null): EmailTemplate
    {
        $this->ensureCanManage($user);

        $template->fill([
            'name' => $data['name'] ?? $template->name,
            'description' => $data['description'] ?? $template->description,
            'category' => $data['category'] ?? $template->category,
            'header' => array_key_exists('header', $data) ? $data['header'] : $template->header,
            'subject' => $data['subject'] ?? $template->subject,
            'body' => $data['body'] ?? $template->body,
            'footer' => array_key_exists('footer', $data) ? $data['footer'] : $template->footer,
            'publish_status' => $data['publish_status'] ?? $template->publish_status,
            'is_active' => ($data['publish_status'] ?? $template->publish_status) === 'active',
            'updated_by' => $user?->id,
        ]);

        $template->variables = $this->extractVariables(
            (string) $template->body,
            (string) $template->subject,
            (string) ($template->header ?? ''),
            (string) ($template->footer ?? ''),
        );
        $template->save();

        return $template->fresh(['creator', 'editor']);
    }

    public function duplicate(EmailTemplate $template, ?User $user = null): EmailTemplate
    {
        $this->ensureCanManage($user);
        $copy = $template->replicate(['slug']);
        $copy->name = $template->name.' (Copy)';
        $copy->slug = $this->uniqueSlug($copy->name);
        $copy->publish_status = 'draft';
        $copy->is_active = false;
        $copy->created_by = $user?->id;
        $copy->updated_by = $user?->id;
        $copy->save();

        return $copy;
    }

    public function setPublishStatus(EmailTemplate $template, string $status, ?User $user = null): EmailTemplate
    {
        $this->ensureCanManage($user);
        if (! in_array($status, config('template_variables.publish_statuses', []), true)) {
            throw ValidationException::withMessages(['publish_status' => ['Invalid status.']]);
        }
        $template->update([
            'publish_status' => $status,
            'is_active' => $status === 'active',
            'updated_by' => $user?->id,
        ]);

        return $template->fresh(['creator', 'editor']);
    }

    public function delete(EmailTemplate $template, ?User $user = null): void
    {
        $this->ensureCanManage($user);
        $template->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(EmailTemplate $template, ?CaMaster $lead, ?User $sender, ?GoDaddyMailService $mailService = null): array
    {
        $header = $this->renderService->render((string) ($template->header ?? ''), $lead, $sender);
        $body = $this->renderService->render((string) $template->body, $lead, $sender);
        $footer = $this->renderService->render((string) ($template->footer ?? ''), $lead, $sender);
        $fullBody = trim(implode("\n\n", array_filter([$header, $body, $footer])));

        if ($mailService && $lead) {
            $rendered = $mailService->renderEmailTemplate((string) $template->subject, $fullBody, $lead, $sender);

            return [
                'subject' => $rendered['subject'],
                'header' => $header,
                'body' => $rendered['body'],
                'footer' => $footer,
                'preview' => $mailService->toHtmlBody($rendered['body']),
            ];
        }

        $subject = $this->renderService->render((string) $template->subject, $lead, $sender);

        return [
            'subject' => $subject,
            'header' => $header,
            'body' => $fullBody,
            'footer' => $footer,
            'preview' => $this->renderService->formatBodyPreview($fullBody),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(EmailTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'title' => $template->name,
            'slug' => $template->slug,
            'description' => $template->description,
            'category' => $template->category,
            'header' => $template->header,
            'subject' => $template->subject,
            'body' => $template->body,
            'footer' => $template->footer,
            'variables' => $template->variables,
            'is_active' => (bool) $template->is_active,
            'publish_status' => $template->publish_status ?? ($template->is_active ? 'active' : 'draft'),
            'type' => 'email',
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

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'template';
        $slug = $base;
        $i = 1;
        while (EmailTemplate::query()->where('slug', $slug)->exists()) {
            $slug = $base.'_'.$i++;
        }

        return $slug;
    }
}
