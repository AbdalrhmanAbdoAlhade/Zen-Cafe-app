<?php

namespace App\Services;

use App\Events\OrderStatusUpdated;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Models\Staff;

class OrderStatusService
{
    public function __construct(
        private readonly LoyaltyService $loyaltyService,
    ) {
    }

    /**
     * الانتقال بحالة الطلب من الحالة الحالية لحالة جديدة، بعد التحقق إن
     * الانتقال ده مسموح بيه في خريطة Order::allowedTransitions().
     */
    public function transition(Order $order, string $newStatus, ?Staff $staff = null): Order
    {
        $currentStatus = $order->status;
        $allowed = Order::allowedTransitions()[$currentStatus] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw new InvalidOrderTransitionException($currentStatus, $newStatus);
        }

        $order->update(['status' => $newStatus]);

        if (in_array($newStatus, [Order::STATUS_REJECTED, Order::STATUS_CANCELLED], true)) {
            $this->loyaltyService->refundRedeemedPoints($order->fresh());
        }

        $order->statusLogs()->create([
            'status' => $newStatus,
            'changed_by_staff_id' => $staff?->id,
        ]);

        event(new OrderStatusUpdated($order->fresh(), $currentStatus));

        return $order->fresh();
    }

    public function accept(Order $order, Staff $staff): Order
    {
        return $this->transition($order, Order::STATUS_ACCEPTED, $staff);
    }

    public function reject(Order $order, Staff $staff): Order
    {
        return $this->transition($order, Order::STATUS_REJECTED, $staff);
    }

    public function startPreparing(Order $order, Staff $staff): Order
    {
        return $this->transition($order, Order::STATUS_PREPARING, $staff);
    }

    public function markReady(Order $order, Staff $staff): Order
    {
        return $this->transition($order, Order::STATUS_READY, $staff);
    }

    public function markServed(Order $order, ?Staff $staff = null): Order
    {
        return $this->transition($order, Order::STATUS_SERVED, $staff);
    }
}
