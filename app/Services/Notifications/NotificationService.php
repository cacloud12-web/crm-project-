<?php

namespace App\Services\Notifications;

use App\Events\NotificationCreated;
use App\Models\CrmNotification;
use App\Models\CrmNotificationRead;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    public function notifyUser(
        int $userId,
        string $type,
        string $title,
        string $message,
        array $extra = [],
    ): ?CrmNotification {
        return $this->create([
            'user_id' => $userId,
            'audience' => 'user',
            'type' => $type,
            'title' => $title,
            'message' => $message,
        ] + $extra);
    }

    public function notifyRoles(
        array $roles,
        string $type,
        string $title,
        string $message,
        array $extra = [],
    ): ?CrmNotification {
        $roles = array_values(array_unique(array_filter($roles)));

        if ($roles === []) {
            return null;
        }

        return $this->create([
            'user_id' => null,
            'audience' => 'roles',
            'audience_roles' => $roles,
            'type' => $type,
            'title' => $title,
            'message' => $message,
        ] + $extra);
    }

    public function notifyManagement(
        string $type,
        string $title,
        string $message,
        array $extra = [],
    ): ?CrmNotification {
        return $this->notifyRoles(
            config('notifications.management_roles', []),
            $type,
            $title,
            $message,
            $extra,
        );
    }

    public function notifyActor(?User $actor, string $type, string $title, string $message, array $extra = []): void
    {
        if ($actor) {
            $this->notifyUser((int) $actor->id, $type, $title, $message, $extra);
        }
    }

    public function listForUser(User $user, array $params = []): array
    {
        $perPage = min(max((int) ($params['per_page'] ?? 50), 1), 100);
        $page = max((int) ($params['page'] ?? 1), 1);
        $filter = $params['filter'] ?? 'all';

        $query = CrmNotification::query()
            ->visibleTo($user)
            ->with(['reads' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderByDesc('id');

        if ($filter === 'unread') {
            $query->unreadFor($user);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'notifications' => $this->transformCollection($paginator->getCollection(), $user),
            'unread_count' => $this->unreadCount($user),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'poll_interval_seconds' => config('notifications.poll_interval_seconds', 30),
            'realtime' => [
                'channel' => config('notifications.broadcast_channel_prefix').$user->id,
                'event' => 'notification.created',
            ],
        ];
    }

    public function poll(User $user, ?int $afterId = null): array
    {
        $query = CrmNotification::query()
            ->visibleTo($user)
            ->with(['reads' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderBy('id');

        if ($afterId) {
            $query->where('id', '>', $afterId);
        }

        $items = $query->limit(50)->get();

        return [
            'notifications' => $this->transformCollection($items, $user),
            'unread_count' => $this->unreadCount($user),
            'latest_id' => CrmNotification::query()->max('id') ?? 0,
            'poll_interval_seconds' => config('notifications.poll_interval_seconds', 30),
        ];
    }

    public function unreadCount(User $user): int
    {
        return CrmNotification::query()->unreadFor($user)->count();
    }

    public function markRead(User $user, int $notificationId): bool
    {
        $notification = CrmNotification::query()
            ->visibleTo($user)
            ->find($notificationId);

        if (! $notification) {
            return false;
        }

        if ($this->isReadBy($notification, $user)) {
            return true;
        }

        CrmNotificationRead::query()->create([
            'crm_notification_id' => $notification->id,
            'user_id' => $user->id,
            'read_at' => now(),
        ]);

        return true;
    }

    public function markAllRead(User $user): int
    {
        $unread = CrmNotification::query()
            ->unreadFor($user)
            ->pluck('id');

        if ($unread->isEmpty()) {
            return 0;
        }

        $now = now();
        $rows = $unread->map(fn (int $id) => [
            'crm_notification_id' => $id,
            'user_id' => $user->id,
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('crm_notification_reads')->insert($rows);

        return count($rows);
    }

    public function resolveUserIdsByRoles(array $roles): array
    {
        return User::query()
            ->whereIn('crm_role', $roles)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function resolveUserIdByPerformer(?string $performer): ?int
    {
        if (! $performer || $performer === 'System') {
            return null;
        }

        $user = User::query()
            ->where(function ($query) use ($performer) {
                $query->where('name', $performer)
                    ->orWhere('email', $performer);
            })
            ->where('is_active', true)
            ->first();

        return $user ? (int) $user->id : null;
    }

    public function resolveUserIdByEmployeeEmail(?string $email): ?int
    {
        if (! $email) {
            return null;
        }

        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        return $user ? (int) $user->id : null;
    }

    public function customerReplyReceived(
        string $fromEmail,
        string $subject,
        ?int $caId,
        int $inboundMessageId,
        ?int $employeeUserId = null,
    ): void {
        $title = 'Customer reply received';
        $message = ($subject ?: 'No subject').' from '.$fromEmail;
        $extra = [
            'entity_type' => 'email_inbound',
            'entity_id' => (string) $inboundMessageId,
            'payload' => [
                'ca_id' => $caId,
                'from_email' => $fromEmail,
                'subject' => $subject,
                'suggest_followup' => true,
            ],
        ];

        if ($employeeUserId) {
            $this->notifyUser($employeeUserId, 'email_reply_received', $title, $message, $extra);
        }

        $this->notifyManagement('email_reply_received', $title, $message, $extra);
    }

    public function campaignCompleted(
        string $channel,
        string $name,
        int $sent,
        int $delivered,
        int|string $campaignId,
    ): void {
        $channelLabel = ucfirst($channel);
        $title = $channelLabel.' campaign completed';
        $message = $name.' — '.$delivered.' of '.$sent.' delivered';
        $extra = [
            'entity_type' => $channel.'_campaign',
            'entity_id' => (string) $campaignId,
            'payload' => [
                'channel' => $channel,
                'sent' => $sent,
                'delivered' => $delivered,
            ],
        ];

        $this->notifyManagement('campaign_completed', $title, $message, $extra);

        if ($actor = auth()->user()) {
            $this->notifyActor($actor, 'campaign_completed', $title, $message, $extra);
        }
    }

    public function importCompleted(
        string $fileName,
        int $inserted,
        int $failed,
        int $total,
        int|string $bulkActionId,
        ?string $performedBy = null,
    ): void {
        $title = 'Bulk import completed';
        $message = ($fileName ?: 'Import').' — '.$inserted.' inserted, '.$failed.' failed of '.$total.' rows';
        $extra = [
            'entity_type' => 'bulk_action',
            'entity_id' => (string) $bulkActionId,
            'payload' => [
                'action_type' => 'ca_master_import',
                'inserted' => $inserted,
                'failed' => $failed,
                'total' => $total,
            ],
        ];

        $actorId = $this->resolveUserIdByPerformer($performedBy);
        if ($actorId) {
            $this->notifyUser($actorId, 'import_completed', $title, $message, $extra);
        }

        $this->notifyManagement('import_completed', $title, $message, $extra);
    }

    public function exportCompleted(
        string $fileName,
        int $exported,
        string $format,
        int|string $bulkActionId,
        ?string $performedBy = null,
    ): void {
        $title = 'Bulk export ready';
        $message = $fileName.' — '.$exported.' records exported ('.strtoupper($format).')';
        $extra = [
            'entity_type' => 'bulk_action',
            'entity_id' => (string) $bulkActionId,
            'payload' => [
                'action_type' => 'ca_master_export',
                'exported' => $exported,
                'format' => $format,
            ],
        ];

        $actorId = $this->resolveUserIdByPerformer($performedBy);
        if ($actorId) {
            $this->notifyUser($actorId, 'export_completed', $title, $message, $extra);
        }

        $this->notifyManagement('export_completed', $title, $message, $extra);
    }

    public function activityAlert(string $action, string $description, ?string $performedBy = null): void
    {
        $title = 'Activity alert: '.$action;
        $message = $description.($performedBy ? ' — '.$performedBy : '');
        $extra = [
            'payload' => [
                'action' => $action,
                'performed_by' => $performedBy,
            ],
        ];

        $this->notifyManagement('activity_alert', $title, $message, $extra);
    }

    public function newEmployee(string $name, string $role): void
    {
        $title = 'New employee added';
        $message = $name.' joined as '.$role;
        $this->notifyManagement('new_employee', $title, $message, [
            'payload' => ['name' => $name, 'role' => $role],
        ]);
    }

    private function create(array $data): ?CrmNotification
    {
        $type = $data['type'];
        $typeConfig = config("notifications.types.{$type}");

        if (! $typeConfig) {
            return null;
        }

        if (! empty($data['dedup_key'])) {
            $dedupQuery = CrmNotification::query()->where('dedup_key', $data['dedup_key']);

            if (! empty($data['user_id'])) {
                $dedupQuery->where('user_id', $data['user_id']);
            } else {
                $dedupQuery->whereNull('user_id');
            }

            if ($dedupQuery->exists()) {
                return null;
            }
        }

        $notification = CrmNotification::create([
            'user_id' => $data['user_id'] ?? null,
            'audience' => $data['audience'] ?? 'user',
            'audience_roles' => $data['audience_roles'] ?? null,
            'type' => $type,
            'title' => $data['title'],
            'message' => $data['message'],
            'severity' => $data['severity'] ?? $typeConfig['severity'],
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => isset($data['entity_id']) ? (string) $data['entity_id'] : null,
            'payload' => $data['payload'] ?? null,
            'dedup_key' => $data['dedup_key'] ?? null,
        ]);

        $this->dispatchRealtime($notification);

        return $notification;
    }

    private function dispatchRealtime(CrmNotification $notification): void
    {
        $recipientIds = [];

        if ($notification->user_id) {
            $recipientIds[] = (int) $notification->user_id;
        } elseif ($notification->audience === 'all') {
            $recipientIds = User::query()
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } elseif ($notification->audience === 'roles' && is_array($notification->audience_roles)) {
            $recipientIds = $this->resolveUserIdsByRoles($notification->audience_roles);
        }

        foreach (array_unique($recipientIds) as $userId) {
            event(new NotificationCreated($notification, $userId));
        }
    }

    private function transformCollection(Collection $items, User $user): array
    {
        return $items->map(fn (CrmNotification $item) => $this->transform($item, $user))->values()->all();
    }

    private function transform(CrmNotification $notification, User $user): array
    {
        return [
            'notification_id' => (string) $notification->id,
            'title' => $notification->title,
            'message' => $notification->message,
            'time' => $notification->created_at?->diffForHumans() ?? '',
            'created_at' => $notification->created_at?->toIso8601String(),
            'type' => $notification->severity,
            'notification_type' => $notification->type,
            'read' => $this->isReadBy($notification, $user),
            'entity_type' => $notification->entity_type,
            'entity_id' => $notification->entity_id,
            'payload' => $notification->payload,
        ];
    }

    private function isReadBy(CrmNotification $notification, User $user): bool
    {
        if ($notification->relationLoaded('reads')) {
            return $notification->reads->isNotEmpty();
        }

        return $notification->reads()
            ->where('user_id', $user->id)
            ->exists();
    }
}
