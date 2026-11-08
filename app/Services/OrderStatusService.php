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
        private readonly StockService $stockService,
        private readonly CouponService $couponService,
        private readonly HyperpayService $hyperpay,
        private readonly OrderService $orderService,
    ) {
    }

    /**
     * الانتقال بحالة الطلب (منيو: in_branch / pre_order)
     */
    public function transition(Order $order, string $newStatus, ?Staff $staff = null): Order
    {
        $currentStatus = $order->status;
        $allowed = Order::allowedTransitions()[$currentStatus] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw new InvalidOrderTransitionException($currentStatus, $newStatus);
        }

        $updates = ['status' => $newStatus];

        // أول قبول لأوردر أونلاين → تسجيل received_at
        if (
            $newStatus === Order::STATUS_ACCEPTED
            && $order->order_type === 'pre_order'
            && ! $order->received_at
        ) {
            $updates['received_at'] = now();
        }

        $order->update($updates);

        if (in_array($newStatus, [Order::STATUS_REJECTED, Order::STATUS_CANCELLED], true)) {
            $fresh = $order->fresh();

            $this->loyaltyService->refundRedeemedPoints($fresh);
            $this->couponService->reverseRedemption($fresh);

            // إرجاع مخزون المنيو
            $this->orderService->refundMenuStockForOrder($fresh);

            // استرجاع الدفع الأونلاين إن وجد
            $this->hyperpay->refundIfPaidOnline($fresh);
        }

        $order->statusLogs()->create([
            'status' => $newStatus,
            'changed_by_staff_id' => $staff?->id,
        ]);

        event(new OrderStatusUpdated($order->fresh(), $currentStatus));

        return $order->fresh();
    }

    /**
     * إلغاء بواسطة العميل — مسموح فقط و الحالة pending
     */
   /**
 * إلغاء بواسطة العميل — مسموح في أي حالة ما عدا الملغي/المرفوض
 * + استرجاع نقاط الولاء + المخزون + الكوبون + الدفع الأونلاين
 */
public function cancelByCustomer(Order $order): Order
{
    $currentStatus = $order->status;

    // منع الإلغاء لو الطلب ملغي أو مرفوض بالفعل
    if (in_array($currentStatus, [
        Order::STATUS_CANCELLED,
        Order::STATUS_REJECTED,
    ], true)) {
        throw new InvalidOrderTransitionException($currentStatus, Order::STATUS_CANCELLED);
    }

    // لو فيه STATUS_REFUNDED على الموديل
    if (defined(Order::class.'::STATUS_REFUNDED') && $currentStatus === Order::STATUS_REFUNDED) {
        throw new InvalidOrderTransitionException($currentStatus, Order::STATUS_CANCELLED);
    }

    $order->update(['status' => Order::STATUS_CANCELLED]);

    $fresh = $order->fresh();

    if ($fresh->order_type === 'store') {
        // متجر → إرجاع مخزون الـ variants
        $this->stockService->refundForOrder($fresh);
    } else {
        // منيو → نقاط الولاء + مخزون menu_item_branch
        $this->loyaltyService->refundRedeemedPoints($fresh);
        $this->orderService->refundMenuStockForOrder($fresh);
    }

    $this->couponService->reverseRedemption($fresh);

    // استرجاع الدفع الأونلاين (HyperPay RF/RV)
    $this->hyperpay->refundIfPaidOnline($fresh);

    $order->statusLogs()->create([
        'status' => Order::STATUS_CANCELLED,
        'changed_by_staff_id' => null,
    ]);

    event(new OrderStatusUpdated($order->fresh(), $currentStatus));

    return $order->fresh();
}
    /**
     * انتقالات طلبات المتجر (store)
     */
    public function transitionStore(Order $order, string $newStatus, ?Staff $staff = null): Order
    {
        $currentStatus = $order->status;
        $allowed = Order::allowedStoreTransitions()[$currentStatus] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw new InvalidOrderTransitionException($currentStatus, $newStatus);
        }

        $order->update(['status' => $newStatus]);

        if (in_array($newStatus, [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED], true)) {
            $fresh = $order->fresh();

            $this->stockService->refundForOrder($fresh);
            $this->couponService->reverseRedemption($fresh);
            $this->hyperpay->refundIfPaidOnline($fresh);
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