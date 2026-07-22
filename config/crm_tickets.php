<?php

return [

    'storage_disk' => env('CRM_TICKET_STORAGE_DISK', 'local'),

    'max_attachment_mb' => (int) env('CRM_TICKET_MAX_ATTACHMENT_MB', 20),

    'organization_lookup_cache_ttl_minutes' => (int) env('CRM_TICKET_ORG_LOOKUP_CACHE_TTL', 15),

    'allowed_mime_types' => [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/tiff',
    ],

    'allowed_extensions' => [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv',
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'tif', 'tiff',
    ],

    'problem_types' => [
        'issue',
        'improvement',
        'new_feature',
    ],

    'statuses' => [
        'open',
        'under_review',
        'closed',
    ],

    'priorities' => [
        'low',
        'normal',
        'high',
        'urgent',
    ],

    'created_via_values' => [
        'crm_employee',
        'crm_client',
        'ca_cloud_desk',
        'api',
        'system',
    ],

    'email_verification_statuses' => [
        'unverified',
        'verified',
        'failed',
        'skipped',
    ],

    'notification_statuses' => [
        'pending',
        'queued',
        'sent',
        'failed',
        'skipped',
    ],

    'sync_statuses' => [
        'pending',
        'synced',
        'failed',
        'acknowledged',
        'outbound_pending',
    ],

    'source_systems' => [
        'crm',
        'ca_cloud_desk',
    ],

    'author_types' => [
        'employee',
        'client',
        'admin',
        'system',
    ],

    'comment_types' => [
        'reply',
        'internal_note',
        'system',
        'client_reply',
    ],

    'comment_visibilities' => [
        'public',
        'internal',
        'client',
    ],

    'sync_operations' => [
        'ticket_inbound',
        'ticket_outbound',
        'organization_lookup',
        'organization_verify',
        'acknowledgement',
    ],

    'notification_channels' => [
        'email',
        'whatsapp',
    ],

    'notification_event_types' => [
        'ticket_created',
        'status_changed',
        'reply_added',
        'ticket_closed',
    ],

    'ticket_number_prefix' => 'TKT',

];
