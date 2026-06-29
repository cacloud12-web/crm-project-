# Orphan / future database tables

Tables below exist in schema but are not wired to active CRM UI flows. They are **kept for future modules** and may appear empty in demo.

| Table | Purpose | Module | Used now | Keep future | Hide in demo |
|-------|---------|--------|----------|-------------|--------------|
| `api_rate_limits` | API rate limiting counters | Security / API | No | Yes | Yes |
| `throttle_logs` | Throttle audit trail | Security / API | No | Yes | Yes |
| `retry_logics` | Message retry policies | Communications | No | Yes | Yes |
| `failed_queues` | Failed queue registry | Queue | No | Yes | Yes |
| `bounce_handlings` | Email bounce handling | Email | No | Yes | Yes |
| `spam_protections` | Spam protection rules | Communications | No | Yes | Yes |
| `queue_jobs` | Legacy CRM queue jobs | Queue | No | Yes | Yes |
| `queue_logs` | Legacy CRM queue logs | Queue | No | Yes | Yes |
| `admin_dashboard_metrics` | Pre-aggregated dashboard snapshots | Dashboard | No | Yes | Yes |
| `lead_actions` | Lead action history | CA Master | No | Yes | Yes |
| `lead_lockings` | Concurrent edit locks | CA Master | No | Yes | Yes |
| `lead_filter_preferences` | Saved filter presets | Listings | No | Yes | Yes |
| `notification_masters` | Notification templates | Notifications | No | Yes | Yes |
| `template_masters` | Message templates | Communications | No | Yes | Yes |
| `rating_masters` | Rating lookup master | Masters | No | Yes | Yes |
| `reason_masters` | Lost reason codes | Masters | No | Yes | Yes |
| `user_access_controls` | Fine-grained ACL | Security | No | Yes | Yes |
| `data_encryption_keys` | Encryption key store | Security | No | Yes | Yes |

Database Health classifies these as **Future module / intentionally empty** when row count is zero.

**Do not drop** these tables without a migration plan.
