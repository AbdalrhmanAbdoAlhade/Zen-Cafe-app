<?php

namespace App\Services;

use App\Exceptions\PaymentAmountMismatchException;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

class OrderPaymentService
{
    public function __construct(
        private readonly OrderStatusService $orderStatusService,
    ) {
    }

    /**
     * تسجيل تحصيل الدفع (كاش/بطاقة) يدويًا من الكاشير، وإقفال الطلب كمدفوع.
     * الطلب لازم يكون في حالة 'served' قبل التحصيل (زي ما هو متفق في دورة الحالة).
     */
    public function recordPayment(Order $order, Staff $staff, string $method, float $amount): OrderPayment
    {
        $expected = (float) $order->total_amount;

        if (abs($expected - $amount) > 0.01) {
            throw new PaymentAmountMismatchException($expected, $amount);
        }

        return DB::transaction(function () use ($order, $staff, $method, $amount) {
            $payment = OrderPayment::create([
                'order_id' => $order->id,
                'staff_id' => $staff->id,
                'method' => $method,
                'amount' => $amount,
            ]);

            $this->orderStatusService->transition($order, Order::STATUS_PAID, $staff);

            return $payment;
        });
    }
}
