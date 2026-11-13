<?php

namespace Modules\ZashDine\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Signals Kitchen and Floor clients to refresh from the API.
 * Payload is intentionally thin; the database remains authoritative.
 */
class ZashDineBoardUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $eventId,
        public string $reason,
        public ?int $orderItemId = null,
        public ?int $orderId = null,
        public ?string $status = null,
        public ?int $tableId = null,
        public ?int $kitchenId = null,
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
            new PrivateChannel( 'zash-dine.kitchen' ),
            new PrivateChannel( 'zash-dine.floor' ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'zash-dine.board-updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'reason' => $this->reason,
            'order_item_id' => $this->orderItemId,
            'order_id' => $this->orderId,
            'status' => $this->status,
            'table_id' => $this->tableId,
            'kitchen_id' => $this->kitchenId,
            'occurred_at' => $this->occurredAt ?? now()->toIso8601String(),
        ];
    }
}
