<?php

namespace App\Support\Demo;

final class DemoDataCatalog
{
    public const DEMO_LEAD_EMAIL_PREFIX = 'manager.demo.lead';

    public const DEMO_LEAD_EMAIL_DOMAIN = '@ca.local';

    /** @var array<string, string> */
    public const DEMO_CAMPAIGN_NAMES = [
        'whatsapp' => 'WhatsApp',
        'email' => 'Email',
        'sms' => 'SMS',
    ];

    /** @var list<string> Legacy seeded campaign titles (pre-prefix cleanup). */
    public const LEGACY_DEMO_CAMPAIGN_NAMES = [
        'Manager Demo — WhatsApp',
        'Manager Demo — Email',
        'Manager Demo — SMS',
    ];

    /** @var list<string> */
    public const DEMO_EMPLOYEE_EMAILS = [
        'employee@ca.local',
        'manager.demo.exec2@ca.local',
        'manager.demo.exec3@ca.local',
    ];

    /** @var list<string> */
    public const QA_LEAD_PATTERNS = [
        'QA %',
        'QA Retest%',
        'FilterTest%',
    ];

    /**
     * Tables classified as future modules in Database Health (empty by design).
     *
     * @var array<string, string>
     */
    public const FUTURE_MODULE_TABLES = [
        'api_rate_limits' => 'API rate limiting',
        'throttle_logs' => 'Request throttling audit',
        'retry_logics' => 'Message retry policies',
        'failed_queues' => 'Failed queue registry',
        'bounce_handlings' => 'Email bounce handling',
        'spam_protections' => 'Spam protection',
        'queue_jobs' => 'CRM queue jobs (legacy)',
        'queue_logs' => 'CRM queue logs (legacy)',
        'admin_dashboard_metrics' => 'Pre-aggregated dashboard snapshots',
        'lead_lockings' => 'Lead edit locks',
        'lead_filter_preferences' => 'Saved lead filter prefs',
        'notification_masters' => 'Notification templates',
        'template_masters' => 'Message templates',
        'rating_masters' => 'Rating lookup master',
        'reason_masters' => 'Lost reason codes',
        'user_access_controls' => 'Fine-grained ACL',
        'data_encryption_keys' => 'Encryption key store',
    ];

    public static function isDemoLeadEmail(?string $email): bool
    {
        if (! $email) {
            return false;
        }

        return str_starts_with($email, self::DEMO_LEAD_EMAIL_PREFIX)
            && str_ends_with($email, self::DEMO_LEAD_EMAIL_DOMAIN);
    }

    /**
     * @return list<string>
     */
    public static function demoCampaignNameList(): array
    {
        return array_values(self::DEMO_CAMPAIGN_NAMES);
    }

    /**
     * @return list<string>
     */
    public static function allKnownDemoCampaignNames(): array
    {
        return array_values(array_unique(array_merge(
            self::demoCampaignNameList(),
            self::LEGACY_DEMO_CAMPAIGN_NAMES,
        )));
    }

    public static function isDemoCampaignName(?string $name): bool
    {
        if (! $name) {
            return false;
        }

        return in_array($name, self::allKnownDemoCampaignNames(), true)
            || str_starts_with($name, 'Manager Demo');
    }

    public static function stripVisiblePrefix(string $value): string
    {
        foreach (['Manager Demo — ', 'Manager Demo —', 'Manager Demo - '] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return trim(substr($value, strlen($prefix)));
            }
        }

        return $value;
    }
}
