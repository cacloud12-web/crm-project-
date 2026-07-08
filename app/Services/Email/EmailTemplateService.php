<?php

namespace App\Services\Email;

use App\Models\CaMaster;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EmailTemplateService
{
    public function listActive(): Collection
    {
        return EmailTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function findActive(int $id): EmailTemplate
    {
        $template = EmailTemplate::query()->findOrFail($id);

        if (! $template->is_active) {
            throw ValidationException::withMessages([
                'email_template_id' => ['Selected email template is not active.'],
            ]);
        }

        return $template;
    }

    public function findBySlug(string $slug): ?EmailTemplate
    {
        return EmailTemplate::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(EmailTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'slug' => $template->slug,
            'description' => $template->description,
            'subject' => $template->subject,
            'body' => $template->body,
            'variables' => $template->variables,
            'is_active' => (bool) $template->is_active,
        ];
    }

    /**
     * @return array{subject: string, body: string, preview: string}
     */
    public function preview(EmailTemplate $template, CaMaster $lead, ?User $sender = null, GoDaddyMailService $mailService): array
    {
        $rendered = $mailService->renderEmailTemplate(
            (string) $template->subject,
            (string) $template->body,
            $lead,
            $sender,
        );

        return [
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            'preview' => $mailService->toHtmlBody($rendered['body']),
        ];
    }

    public function ensureCanView(?User $user): void
    {
        if (! $user) {
            throw new AuthorizationException('Authentication required.');
        }
    }
}
