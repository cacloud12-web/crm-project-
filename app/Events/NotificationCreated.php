<?php

namespace App\Events;

use App\Models\CrmNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public CrmNotification $notification,
        public int $recipientUserId,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(config('notifications.broadcast_channel_prefix').$this->recipientUserId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'message' => $this->notification->message,
            'severity' => $this->notification->severity,
            'entity_type' => $this->notification->entity_type,
            'entity_id' => $this->notification->entity_id,
            'created_at' => $this->notification->created_at?->toIso8601String(),
        ];
    }
}
