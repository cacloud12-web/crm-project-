# Meta WhatsApp Cloud API — CRM Mapping Report

Mapping-only architecture. **No live API calls** are made. Credentials are stored encrypted and never returned to the frontend.

## Existing Tables (unchanged structure)

| Table | Status |
|-------|--------|
| `whatsapp_campaigns` | Exists — extended with Cloud API columns |
| `wa_message_logs` | Exists — extended with payload/response columns |
| `ca_masters` | Exists — `mobile_no` is recipient source |
| `employees` | Exists — assigned employee for logs |
| `users` | Exists — settings editors / activity performer |
| `activity_logs` | Exists — audit trail |

## New Tables

| Table | Purpose |
|-------|---------|
| `whatsapp_settings` | Meta Cloud API credentials & mode |
| `message_templates` | Approved template names, language, body variables |

---

## Field Mapping

| Meta Field | CRM Table | CRM Column | Used By |
|------------|-----------|------------|---------|
| Phone Number ID | `whatsapp_settings` | `phone_number_id` | `WhatsAppSettingsService`, `WhatsAppCloudMappingService` |
| Business Account ID | `whatsapp_settings` | `business_account_id` | `WhatsAppSettingsService`, validation |
| Permanent Access Token | `whatsapp_settings` | `access_token` (encrypted) | `WhatsAppSettingsService` (never exposed) |
| API Version | `whatsapp_settings` | `api_version` | Payload endpoint mapping |
| Template Name | `message_templates` | `template_name` | `WhatsAppTemplateService`, campaign + logs |
| Language Code | `message_templates` | `language_code` | Cloud payload `template.language.code` |
| Recipient Mobile | `ca_masters` | `mobile_no` | `WhatsAppCloudMappingService` → `request_body.to` |

---

## Template Variable Mapping

| Variable | CRM Source |
|----------|------------|
| `{{name}}` | `ca_masters.ca_name` |
| `{{firm_name}}` | `ca_masters.firm_name` |
| `{{mobile}}` | `ca_masters.mobile_no` |
| `{{city}}` | `cities.city_name` (via `ca_masters.city_id`) |
| `{{state}}` | `states.state_name` (via `ca_masters.state_id`) |
| `{{demo_date}}` | Latest demo `follow_ups.scheduled_date` |
| `{{demo_time}}` | Latest demo `follow_ups.scheduled_date` (formatted) |
| `{{employee_name}}` | `employees.name` (active `lead_assignment_engines`) |

Resolved by: `WhatsAppCloudMappingService::resolveVariables()`

---

## Campaign Workflow (mapping stops before HTTP)

```
Select Leads → Read mobile_no → Select approved template → Replace variables
→ Generate Cloud payload → Store in wa_message_logs.api_payload → STOP
```

| Step | Service |
|------|---------|
| Audience resolution | `WhatsAppCampaignService` |
| Template lookup | `WhatsAppTemplateService` |
| Variable + payload build | `WhatsAppCloudMappingService` |
| Log persistence | `WhatsAppLogService` |

---

## Log Mapping (future API response)

| Logical Field | CRM Table | CRM Column |
|---------------|-----------|------------|
| campaign_id | `wa_message_logs` | `campaign_id` |
| lead_id | `wa_message_logs` | `ca_id` |
| employee_id | `wa_message_logs` | `employee_id` |
| mobile_no | `wa_message_logs` | `mobile_no` |
| template_name | `wa_message_logs` | `template_name` |
| message | `wa_message_logs` | `message` |
| status | `wa_message_logs` | `message_status` |
| provider_response | `wa_message_logs` | `provider_response` (JSON) |
| error_message | `wa_message_logs` | `error_message` |
| sent_at | `wa_message_logs` | `sent_at` |
| delivered_at | `wa_message_logs` | `delivered_at` |
| read_at | `wa_message_logs` | `read_at` |
| API payload (pre-send) | `wa_message_logs` | `api_payload` (JSON) |

Future response mapping: `WhatsAppLogService::applyProviderResponse()`

---

## Service Mapping

| Service | Responsibility |
|---------|----------------|
| `WhatsAppSettingsService` | Settings CRUD, encryption, admin-only edit |
| `WhatsAppTemplateService` | Approved template listing/selection |
| `WhatsAppCloudMappingService` | Variable resolution, payload structure |
| `WhatsAppCampaignService` | Campaign orchestration (no HTTP) |
| `WhatsAppLogService` | Log field mapping, payload storage |

---

## Controller / Route Mapping

| Route | Controller | Action |
|-------|------------|--------|
| `GET /whatsapp-settings` | `WhatsAppSettingsController` | Load settings (no token) |
| `PUT /whatsapp-settings` | `WhatsAppSettingsController` | Admin save |
| `POST /whatsapp-settings/validate` | `WhatsAppSettingsController` | Mapping validation only |
| `GET /message-templates/whatsapp` | `MessageTemplateController` | Approved templates |
| `POST /whatsapp-campaigns` | `WhatsAppCampaignController` | Create + map payloads |
| `GET /whatsapp-campaigns/{id}/payload-preview` | `WhatsAppCampaignController` | Single-lead preview |

---

## Security

- **Edit settings:** Admin, Super Admin only
- **View settings:** Admin, Super Admin, Manager (no access token in response)
- **Access token:** `encrypted` cast on `WhatsAppSetting` model
- **Employees:** Cannot access `/whatsapp-settings` or see `access_token`

---

## Activity Logs

| Action | Module |
|--------|--------|
| WhatsApp Settings Updated | `WHATSAPP_SETTINGS` |
| Template Selected | `WHATSAPP_SETTINGS` |
| Campaign Created | `WHATSAPP_CAMPAIGN` |
| Payload Generated | `WHATSAPP_CAMPAIGN` |
| Campaign Processed | `WHATSAPP_CAMPAIGN` |

---

## Migrations

1. `2026_06_29_210000_create_whatsapp_cloud_mapping_tables.php` — `whatsapp_settings`, `message_templates`
2. `2026_06_29_210100_add_whatsapp_cloud_mapping_columns.php` — ALTER campaigns + logs

Seed approved template placeholders: `php artisan db:seed --class=WhatsAppCloudMappingSeeder`
