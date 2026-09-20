<?php

namespace App\Services;

use App\Events\OrderStatusUpdated;
use App\Exceptions\PaymentAmountMismatchException;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

class OrderPaymentService
{
    public function __construct(
        private readonly OrderStatusService $orderStatusService,
        private readonly LoyaltyService $loyaltyService,
    ) {
    }

    /**
     * المسار العادي: بعد served فقط (عبر خريطة الانتقالات).
     */
    public function recordPayment(Order $order, Staff $staff, string $method, float $amount): OrderPayment
    {
        return $this->finalizePayment($order, $staff, $method, $amount, forceStatus: false);
    }

    /**
     * اختصار الكاشير: قفل + دفع من أي حالة
     * ما عدا: paid / rejected / cancelled / refunded
     * لا يغيّر allowedTransitions — استثناء فقط.
     */
    public function settleAndPay(Order $order, Staff $staff, string $method, float $amount): OrderPayment
    {
        $blocked = [
            Order::STATUS_PAID,
            Order::STATUS_REJECTED,
            Order::STATUS_CANCELLED,
        ];

        // لو عندك STATUS_REFUNDED على الموديل
        if (defined(Order::class.'::STATUS_REFUNDED')) {
            $blocked[] = Order::STATUS_REFUNDED;
        }

        if (in_array($order->status, $blocked, true)) {
            throw new \InvalidArgumentException(
                "لا يمكن قفل الطلب وهو في حالة: {$order->status}"
            );
        }

      // طلب المتجر: مسموح بس لو خريطة المتجر بتسمح بالانتقال لـ paid من حالته الحالية (pending)
if (
    $order->order_type === 'store'
    && ! in_array(Order::STATUS_PAID, Order::allowedStoreTransitions()[$order->status] ?? [], true)
) {
    throw new \InvalidArgumentException(
        "لا يمكن قفل طلب المتجر وهو في حالة: {$order->status}"
    );
}

        return $this->finalizePayment($order, $staff, $method, $amount, forceStatus: true);
    }

    private function finalizePayment(
        Order $order,
        Staff $staff,
        string $method,
        float $amount,
        bool $forceStatus = false
    ): OrderPayment {
        $expected = $order->payableAmount();

        if (abs($expected - $amount) > 0.01) {
            throw new PaymentAmountMismatchException($expected, $amount);
        }

        if ($order->payment()->exists()) {
            throw new \InvalidArgumentException('الطلب مدفوع مسبقًا.');
        }

        return DB::transaction(function () use ($order, $staff, $method, $amount, $forceStatus) {
            $payment = OrderPayment::create([
                'order_id' => $order->id,
                'staff_id' => $staff->id,
                'method' => $method,
                'amount' => $amount,
            ]);

            $from = $order->status;

            if ($forceStatus) {
                $updates = ['status' => Order::STATUS_PAID];

                if ($order->order_type === 'pre_order' && ! $order->received_at) {
                    $updates['received_at'] = now();
                }

                $order->update($updates);

                $order->statusLogs()->create([
                    'status' => Order::STATUS_PAID,
                    'changed_by_staff_id' => $staff->id,
                ]);

                event(new OrderStatusUpdated($order->fresh(), $from));
            } else {
                // served → paid فقط حسب الخريطة
                $this->orderStatusService->transition($order, Order::STATUS_PAID, $staff);
            }

            $this->loyaltyService->earnPoints($order->fresh());

            return $payment;
        });
    }
}