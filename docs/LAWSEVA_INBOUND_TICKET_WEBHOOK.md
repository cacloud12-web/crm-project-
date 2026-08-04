# CRM Inbound Ticket Webhook (LawSeva → CRM)

Share this with the LawSeva / partner portal developer.

## Purpose

Whenever a ticket is **created or updated** on LawSeva (`partner.caclouddesk.com`), call this CRM webhook so the ticket appears in CA Cloud Desk CRM.

## Endpoint

| Item | Value |
|------|--------|
| **Method** | `POST` |
| **URL (live)** | `https://crm.caclouddesk.com/webhooks/ca-cloud-desk/tickets` |
| **URL (local)** | `http://127.0.0.1:8001/webhooks/ca-cloud-desk/tickets` |
| **Auth header** | `X-Integration-Token: <CA_CLOUD_DESK_INTEGRATION_TOKEN>` |
| **Alt auth header** | `X-Api-Key: <same token>` (also accepted) |
| **Content-Type** | `application/json` |
| **JWT** | Not required |

Ask CRM admin for the shared `CA_CLOUD_DESK_INTEGRATION_TOKEN` value (separate from LawSeva `EXTERNAL_AUTH_KEY`).

## Required / recommended body

CRM accepts **LawSeva ticket shape** or explicit CRM field names.

### Preferred LawSeva-shaped example

```json
{
  "id": 1234,
  "ticket_id": "TCK-1234",
  "organization": 56,
  "organization_name": "vikash ltd",
  "partner": 460,
  "partner_data": {
    "id": 460,
    "first_name": "devansh.agrawal",
    "email": "devansh.agrawal@plutonic.co.in",
    "phone": "8273337174"
  },
  "category": "Add Document Template",
  "description": "<p>Need help with document template</p>",
  "documents": [],
  "is_solved": false,
  "created_at": "2026-07-23T05:38:00.000000Z",
  "modified_at": "2026-07-23T05:38:00.000000Z"
}
```

### Field mapping

| LawSeva field | CRM uses as |
|---------------|-------------|
| `id` or `ticket_id` or `external_ticket_id` | `external_ticket_id` (**required**, unique per LawSeva ticket) |
| `organization` / `organization_number` | Organization id |
| `organization_name` | Organization name |
| `partner_data.first_name` / `partner_name` | Raised by |
| `partner_data.phone` / `mobile_number` | Mobile (defaults to `0000000000` if missing) |
| `partner_data.email` / `email` | Email |
| `description` or `summery` | Description (**required**) |
| `category` | Stored in admin remarks; also maps loosely to problem type |
| `is_solved` / `is_rejected` | Status `closed` when true |

### Explicit CRM fields (also accepted)

```json
{
  "external_ticket_id": "1234",
  "organization_number": "56",
  "organization_name": "vikash ltd",
  "customer_name": "vikash ltd",
  "raised_by_name": "devansh.agrawal",
  "mobile_number": "8273337174",
  "email": "devansh.agrawal@plutonic.co.in",
  "problem_type": "issue",
  "priority": "normal",
  "status": "open",
  "description": "Need help with document template",
  "category": "Add Document Template"
}
```

`problem_type`: `issue` | `improvement` | `new_feature`  
`priority`: `low` | `normal` | `high` | `urgent`  
`status`: `open` | `under_review` | `closed`

## Success response

**201** (created) or **200** (updated — same `external_ticket_id`):

```json
{
  "success": true,
  "message": "Ticket created in CRM",
  "data": {
    "created": true,
    "crm_ticket_id": 15,
    "ticket_number": "TKT-000015",
    "serial_number": 15,
    "external_ticket_id": "1234",
    "status": "open",
    "sync_status": "synced"
  }
}
```

## Errors

| Status | Meaning |
|--------|---------|
| `401` | Missing/wrong integration token |
| `422` | Validation failed (missing org / description / etc.) |
| `503` | CRM inbound token not configured |

## Idempotency

Re-posting the same LawSeva `id` / `external_ticket_id` **updates** the existing CRM ticket (does not create duplicates).

## When to call

Call this webhook **after** LawSeva successfully creates (or updates) a ticket on the portal — ideally from the same code path as `auth_ticket` / partner ticket create success.
