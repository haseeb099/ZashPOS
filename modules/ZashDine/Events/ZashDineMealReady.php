<?php

namespace Modules\ZashDine\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Ready-meal alert for the responsible waiter (order.author_id).
 * Also creates a durable Notification; this event powers Floor snackbars.
 */
class ZashDineMealReady implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $eventId,
        public int $waiterUserId,
        public int $orderItemId,
        public int $orderId,
        public ?int $tableId,
        public string $title,
        public string $description,
        public ?string $occurredAt = null,
    ) {
        //
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel( 'App.User.' . $this->waiterUserId ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'zash-dine.meal-ready';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'waiter_user_id' => $this->waiterUserId,
            'order_item_id' => $this->orderItemId,
            'order_id' => $this->orderId,
            'table_id' => $this->tableId,
            'title' => $this->title,
            'description' => $this->description,
            'occurred_at' => $this->occurredAt ?? now()->toIso8601String(),
        ];
    }
}
