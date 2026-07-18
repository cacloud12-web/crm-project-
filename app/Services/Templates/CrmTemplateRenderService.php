<?php

namespace App\Services\Templates;

use App\Models\CaMaster;
use App\Models\User;
use Illuminate\Support\Carbon;

class CrmTemplateRenderService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function render(string $template, ?CaMaster $lead = null, ?User $sender = null, array $context = []): string
    {
        return strtr($template, $this->buildReplacements($lead, $sender, $context));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    public function buildReplacements(?CaMaster $lead = null, ?User $sender = null, array $context = []): array
    {
        if ($lead) {
            $lead->loadMissing(['city', 'state', 'sourceLead', 'createdByEmployee']);
        }

        $companyName = (string) config('app.name', 'CA CloudDesk');
        $demoAt = isset($context['demo_at']) ? Carbon::parse($context['demo_at']) : null;
        $followupAt = isset($context['followup_at']) ? Carbon::parse($context['followup_at']) : null;

        $preview = config('crm_defaults.template_preview', []);

        $values = [
            '{{CA_NAME}}' => (string) ($lead?->ca_name ?? ($preview['ca_name'] ?? 'Sample CA')),
            '{{FIRM_NAME}}' => (string) ($lead?->firm_name ?? ($preview['firm_name'] ?? 'Sample Firm Pvt Ltd')),
            '{{CITY}}' => (string) ($lead?->city?->city_name ?? ($preview['city'] ?? 'Sample City')),
            '{{STATE}}' => (string) ($lead?->state?->state_name ?? ($preview['state'] ?? 'Sample State')),
            '{{ADDRESS}}' => (string) ($lead?->address ?? ($preview['address'] ?? '123 Sample Street')),
            '{{PINCODE}}' => (string) ($lead?->pincode ?? ($preview['pincode'] ?? '000000')),
            '{{MOBILE}}' => (string) ($lead?->mobile_no ?? ($preview['mobile'] ?? '+91 90000 00000')),
            '{{EMAIL}}' => (string) ($lead?->email_id ?? ($preview['email'] ?? 'client@example.com')),
            '{{SOURCE}}' => (string) ($lead?->sourceLead?->source_name ?? 'Website'),
            '{{EMPLOYEE_NAME}}' => (string) ($lead?->createdByEmployee?->name ?? $sender?->name ?? ($preview['employee_name'] ?? 'Account Manager')),
            '{{EMPLOYEE_EMAIL}}' => (string) ($lead?->createdByEmployee?->email_id ?? $sender?->email ?? ($preview['employee_email'] ?? 'manager@example.com')),
            '{{EMPLOYEE_PHONE}}' => (string) ($lead?->createdByEmployee?->mobile_no ?? ($preview['employee_phone'] ?? '+91 90000 00001')),
            '{{EMPLOYEE_ROLE}}' => (string) ($lead?->createdByEmployee?->role ?? 'Manager'),
            '{{DEMO_DATE}}' => $demoAt?->format('d M Y') ?? ($context['demo_date'] ?? '15 Jul 2026'),
            '{{DEMO_TIME}}' => $demoAt?->format('h:i A') ?? ($context['demo_time'] ?? '11:00 AM'),
            '{{MEETING_LINK}}' => (string) ($context['meeting_link'] ?? ($preview['meeting_link'] ?? 'https://meet.example.com/demo')),
            '{{DEMO_PROVIDER}}' => (string) ($context['demo_provider'] ?? ($sender?->name ?? 'Demo Specialist')),
            '{{TEAM_SIZE}}' => (string) ($lead?->team_size ?? '10'),
            '{{FOLLOWUP_DATE}}' => $followupAt?->format('d M Y') ?? ($context['followup_date'] ?? '16 Jul 2026'),
            '{{FOLLOWUP_TIME}}' => $followupAt?->format('h:i A') ?? ($context['followup_time'] ?? '04:00 PM'),
            '{{NEXT_ACTION}}' => (string) ($context['next_action'] ?? 'Call back'),
            '{{REMARKS}}' => (string) ($context['remarks'] ?? 'Interested in annual plan'),
            '{{FOLLOWUP_TYPE}}' => (string) ($context['followup_type'] ?? 'Call Status'),
            '{{PLAN_NAME}}' => (string) ($context['plan_name'] ?? 'CRM Annual'),
            '{{AMOUNT}}' => (string) ($context['amount'] ?? '₹25,000'),
            '{{BALANCE}}' => (string) ($context['balance'] ?? '₹0'),
            '{{INVOICE_NO}}' => (string) ($context['invoice_no'] ?? 'INV-2026-001'),
            '{{PURCHASE_DATE}}' => (string) ($context['purchase_date'] ?? '01 Jul 2026'),
            '{{COOLING_PERIOD}}' => (string) ($context['cooling_period'] ?? '30 days'),
            '{{EXPIRY_DATE}}' => (string) ($context['expiry_date'] ?? '30 Jun 2027'),
            '{{PAYMENT_STATUS}}' => (string) ($context['payment_status'] ?? 'Paid'),
            '{{COMPANY_NAME}}' => (string) ($context['company_name'] ?? $companyName),
            '{{SUPPORT_EMAIL}}' => (string) ($context['support_email'] ?? ($preview['support_email'] ?? 'support@example.com')),
            '{{SUPPORT_PHONE}}' => (string) ($context['support_phone'] ?? ($preview['support_phone'] ?? '+91 1800 000 000')),
            '{{WEBSITE}}' => (string) ($context['website'] ?? ($preview['website'] ?? 'https://example.com')),
            '{{COMPANY_ADDRESS}}' => (string) ($context['company_address'] ?? ($preview['company_address'] ?? 'Sample City, Sample State')),
            '{{CLIENT_NAME}}' => (string) ($lead?->ca_name ?? 'Sample CA'),
            '{{CA_ORGANIZATION_NAME}}' => (string) ($lead?->firm_name ?? $companyName),
            '{{SENDER_NAME}}' => (string) ($sender?->name ?? 'CRM Team'),
        ];

        $legacy = [];
        foreach ($values as $key => $value) {
            $legacy[str_replace(['{{', '}}'], ['{', '}'], $key)] = $value;
            $legacy[strtolower($key)] = $value;
        }

        return array_merge($legacy, $values, $context['extra'] ?? []);
    }

    public function formatBodyPreview(string $body): string
    {
        $escaped = e($body);
        $escaped = preg_replace('/\*([^*]+)\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/_([^_]+)_/', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('/~([^~]+)~/', '<s>$1</s>', $escaped) ?? $escaped;

        return nl2br($escaped, false);
    }
}
