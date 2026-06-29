# GoDaddy Email SMTP Mapping Report

This document describes the **mapping-only** integration architecture for GoDaddy Business Email SMTP. No SMTP authentication or email sending occurs in this phase.

API reference: GoDaddy outbound SMTP (`smtpout.secureserver.net`, port 465 SSL typical).

## Existing tables reviewed

| Table | Status | Notes |
|-------|--------|-------|
| `email_settings` | **Created** | New singleton settings table |
| `email_campaigns` | Exists | `subject`, `body_template` already present |
| `email_logs` | Exists | Extended with mapping columns |
| `email_templates` | **Created** | Reusable templates with variable placeholders |
| `ca_masters` | Exists | `email_id` used as recipient |
| `employees` | Exists | Linked via `email_logs.employee_id` |
| `users` | Exists | Resolves employee for logs |
| `activity_logs` | Exists | Settings/campaign/email events logged |

## ALTER / CREATE migrations

Migration: `2026_06_29_130000_create_godaddy_email_mapping_tables.php`

### New: `email_settings`

| Column | Maps to | Default |
|--------|---------|---------|
| `provider_name` | — | `GoDaddy SMTP` |
| `smtp_host` | `MAIL_HOST` | `smtpout.secureserver.net` |
| `smtp_port` | `MAIL_PORT` | `465` |
| `smtp_username` | `MAIL_USERNAME` | `null` |
| `smtp_password` | `MAIL_PASSWORD` | `null` (encrypted when set) |
| `smtp_encryption` | `MAIL_ENCRYPTION` | `ssl` |
| `from_email` | `MAIL_FROM_ADDRESS` | `null` |
| `from_name` | `MAIL_FROM_NAME` | `null` |
| `mode` | — | `simulation` |

### New: `email_templates`

| Column | Purpose |
|--------|---------|
| `name` | Template label |
| `subject` | Subject with `{{variables}}` |
| `body` | Body with `{{variables}}` |
| `variables` | JSON list of supported placeholders |
| `is_active` | Enable/disable |

### Extended: `email_logs`

| Column | Purpose |
|--------|---------|
| `employee_id` | User who triggered campaign |
| `provider_response` | Mapped/future SMTP JSON |
| `error_message` | Validation or provider error |
| `opened_at` | Future open tracking |

Existing columns used: `ca_id` (lead_id), `recipient_email`, `subject`, `body` (message), `email_status` (status), `sent_at`, `clicked_at`.

## Complete SMTP field mapping

| GoDaddy / Laravel SMTP | CRM table | CRM column | Used by |
|------------------------|-----------|------------|---------|
| `MAIL_HOST` | `email_settings` | `smtp_host` | `GoDaddyMailService::buildMailTransport()` |
| `MAIL_PORT` | `email_settings` | `smtp_port` | `GoDaddyMailService::buildMailTransport()` |
| `MAIL_USERNAME` | `email_settings` | `smtp_username` | `GoDaddyMailService::buildMailTransport()` |
| `MAIL_PASSWORD` | `email_settings` | `smtp_password` | `GoDaddyMailService` (encrypted, never in API) |
| `MAIL_ENCRYPTION` | `email_settings` | `smtp_encryption` | `GoDaddyMailService::buildMailTransport()` |
| `MAIL_FROM_ADDRESS` | `email_settings` | `from_email` | `GoDaddyMailService::buildMailObject()` |
| `MAIL_FROM_NAME` | `email_settings` | `from_name` | `GoDaddyMailService::buildMailObject()` |
| Recipient | `ca_masters` | `email_id` | `GoDaddyMailService::prepareForLead()` |
| Subject | `email_campaigns` | `subject` | Rendered per lead |
| Body | `email_campaigns` | `body_template` | Rendered → `email_logs.body` |

## Template variable mapping

| Variable | CA Master field |
|----------|-----------------|
| `{{name}}` | `ca_name` |
| `{{firm_name}}` | `firm_name` |
| `{{city}}` | `city.city_name` |
| `{{state}}` | `state.state_name` |
| `{{mobile}}` | `mobile_no` |
| `{{email}}` | `email_id` |

## Service mapping

```
EmailSettingsService
    ├── current() / update() — password encrypted, never exposed in API
    └── ensureCanManageSettings() — admin & super_admin only

GoDaddyMailService
    ├── buildMailTransport()
    ├── buildMailObject()
    ├── validateDispatchPrerequisites()
    ├── prepareForLead()
    ├── buildCampaignMailObjects()
    ├── renderTemplate()
    └── mapProviderResponseToLogAttributes() [future]

EmailCampaignService
    ├── createMappedLog() — stores mapped mail in provider_response
    └── payloadPreview()

EmailLogService
    ├── mapLogRecord()
    ├── markQueued() / markSent() / markFailed()

SendGoDaddyEmailJob (queue)
    └── Mapping phase only — calls prepareQueuedDispatch(), no SMTP
```

## Controller mapping

| Route | Controller | Access |
|-------|------------|--------|
| `GET /email-settings` | `EmailSettingsController@show` | Admin, Super Admin |
| `PUT /email-settings` | `EmailSettingsController@update` | Admin, Super Admin |
| `GET /email-campaigns/{id}/payload-preview` | `EmailCampaignController@payloadPreview` | Email module RBAC |
| Existing email campaign/log routes | Unchanged | |

## Queue mapping

```
SendGoDaddyEmailJob
    email_log_id
        ↓
GoDaddyMailService::prepareQueuedDispatch()
        ↓
email_logs.provider_response = mapped mail JSON
        ↓
STOP — no SMTP connection
```

## Security

- SMTP password stored with Laravel `encrypted` cast
- API returns `has_smtp_password` boolean, never the password
- Only `admin` and `super_admin` roles can view or modify email settings
- Employees cannot access `/email-settings`

## Activity logs

| Action | Module |
|--------|--------|
| Email Settings Updated | `EMAIL_SETTINGS` |
| Email Campaign Created | `EMAIL_CAMPAIGN` |
| Email Queued | `EMAIL_LOG` |
| Email Sent | `EMAIL_LOG` (future live phase) |
| Email Failed | `EMAIL_LOG` |

## Campaign workflow (mapping only)

```
Select Leads
    ↓
ca_masters.email_id → recipient_email
    ↓
email_campaigns.subject + body_template → rendered per lead
    ↓
email_settings → SMTP transport mapping
    ↓
GoDaddyMailService::buildMailObject()
    ↓
Store in email_logs.provider_response
    ↓
STOP — no email sent
```

## Ready for next phase

When manager provides GoDaddy credentials:

1. Enter SMTP host, port, username, password, from email in **Settings → Integrations → GoDaddy Email**
2. Switch mode to **Live** when SMTP dispatch is implemented
3. `SendGoDaddyEmailJob` will call real SMTP using `buildMailTransport()` with decrypted password (server-side only)
4. Map responses via `mapProviderResponseToLogAttributes()`

No dummy credentials are stored. No emails are sent in this phase.
