<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order, public string $previousStatus)
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        // الكاشير محتاج يعرف بكل تحديث، والمطبخ بس محتاج يعرف لما الطلب يتقبل
        // أو يدخل مرحلة التحضير أو يخلص - مش محتاج يعرف بحالات الدفع مثلاً.
        $channels = [
            new PrivateChannel("branch.{$this->order->branch_id}.cashier"),
        ];

        if (in_array($this->order->status, ['accepted', 'preparing', 'ready'], true)) {
            $channels[] = new PrivateChannel("branch.{$this->order->branch_id}.kitchen");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.status_updated';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'branch_id' => $this->order->branch_id,
            'previous_status' => $this->previousStatus,
            'status' => $this->order->status,
        ];
    }
}
