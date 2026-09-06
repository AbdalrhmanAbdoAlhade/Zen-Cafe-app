<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("branch.{$this->order->branch_id}.cashier"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'branch_id' => $this->order->branch_id,
            'table_id' => $this->order->table_id,
            'status' => $this->order->status,
            'total_amount' => (float) $this->order->total_amount,
        ];
    }
}
