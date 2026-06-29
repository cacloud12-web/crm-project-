# SMS Alert API Mapping Report

Mapping-only integration for SMS Alert (`push.json`). **No HTTP calls are made. No SMS is sent.**

## Phase status

| Phase | Status |
|-------|--------|
| SMS Settings Module | Complete |
| SMS Campaign Preparation | Complete |
| Message Template Variables | Complete |
| Payload Preview (audit logs) | Complete |
| Validation | Complete |
| SMS Logs (`Mapped` status) | Complete |
| Dashboard Widgets | Complete |
| RBAC | Complete |
| Live HTTP dispatch | **Not started** — awaiting API credentials |

## Payload mapping

| SMS Alert API Field | CRM Table | CRM Column | Used By |
|-------------------|-----------|------------|---------|
| `apikey` | `sms_settings` | `api_key` (encrypted) | `SmsAlertMappingService::buildPushPayload()` |
| `sender` | `sms_settings` | `sender_id` | `SmsAlertMappingService::buildPushPayload()` |
| `mobileno` | `ca_masters` | `mobile_no` | `SmsAlertMappingService::prepareForLead()` |
| `text` | `sms_campaigns` | `message_template` (rendered) | `SmsAlertMappingService::renderMessage()` |

## Template variables

| Variable | CA Master Field |
|----------|-----------------|
| `{{name}}` | `ca_name` |
| `{{firm_name}}` | `firm_name` |
| `{{city}}` | `city.city_name` |
| `{{state}}` | `state.state_name` |
| `{{mobile}}` | `mobile_no` |

## Services

- `SmsSettingsService` — settings CRUD, validation-only test, reset, API key never in API responses
- `SmsAlertMappingService` — payload build, validation, template render, duplicate removal, masked preview
- `SmsCampaignService` — draft campaigns, payload preview with `sms_logs` persistence

## Routes

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/sms-settings` | Load settings (masked) |
| PUT | `/sms-settings` | Save settings (Admin/Super Admin) |
| POST | `/sms-settings/test` | Validate config only — no API call |
| POST | `/sms-settings/reset` | Reset to defaults |
| POST | `/sms-campaigns` | Save campaign draft |
| POST | `/sms-campaigns/validate` | Pre-flight validation |
| POST | `/sms-campaigns/preview-message` | Template preview for one lead |
| GET | `/sms-campaigns/{id}/payload-preview` | Read-only payload mapping |
| POST | `/sms-campaigns/{id}/generate-payload-preview` | Persist `Mapped` logs + return preview |
| GET | `/sms-logs` | Audit log listing |

## RBAC matrix

| Role | SMS Settings | SMS Campaigns |
|------|--------------|---------------|
| Super Admin | Full | Full |
| Admin | Full | Full |
| Manager | Read only | Read only |
| Employee | No access | No access |

## Dashboard widgets

- SMS Mapped Campaigns (`sms_mapped`)
- SMS Pending Campaigns (`sms_pending_campaigns`)
- SMS Simulation Mode (`sms_mode_simulation`)
- SMS Live Mode (`sms_mode_live`)

## Ready for live phase

When manager provides API Key and Sender ID:

1. Enter credentials in **Settings → Integrations → SMS Alert**
2. Switch mode to **Live**
3. Implement HTTP POST to `api_url` using `buildPushPayload()` (server-side only, decrypted key)
4. Map responses via `mapProviderResponseToLogAttributes()`

No dummy credentials are stored. No SMS is sent in this phase.
