<?php

namespace App\Services;

use App\Exceptions\InsufficientLoyaltyPointsException;
use App\Models\Customer;
use App\Models\LoyaltyPointsTransaction;
use App\Models\LoyaltySetting;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class LoyaltyService
{
    public function calculateEarnedPoints(float $paidAmount): int
    {
        $rate = (float) LoyaltySetting::current()->points_earn_rate;

        return $rate > 0 ? (int) floor(max(0, $paidAmount) / $rate) : 0;
    }

    public function canRedeem(Customer $customer, int $points, float $orderTotal): bool
    {
        if ($points <= 0) {
            return false;
        }

        $settings = LoyaltySetting::current();
        $value = (float) $settings->point_redemption_value;

        return $points >= (int) $settings->minimum_points_to_redeem
            && $points <= (int) $customer->loyalty_points_balance
            && $value > 0
            && ($points * $value) <= ($orderTotal + 0.01);
    }

    public function redeemPoints(Order $order, ?Customer $customer, int $points): void
    {
        if ($points === 0) {
            return;
        }

        if (! $customer) {
            throw new InsufficientLoyaltyPointsException('لاستخدام النقاط يجب إدخال رقم هاتف مرتبط بحساب العميل.');
        }

        if ($points < 0) {
            throw new InsufficientLoyaltyPointsException('عدد النقاط المطلوب استخدامه غير صالح.');
        }

        DB::transaction(function () use ($order, $customer, $points): void {
            $lockedCustomer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $settings = LoyaltySetting::current();
            $value = (float) $settings->point_redemption_value;
            $amount = round($points * $value, 2);

            if (! $this->canRedeem($lockedCustomer, $points, (float) $order->total_amount)) {
                throw new InsufficientLoyaltyPointsException(
                    'لا يمكن استخدام النقاط المطلوبة: تحقق من الحد الأدنى والرصيد وقيمة الطلب.'
                );
            }

            $lockedCustomer->decrement('loyalty_points_balance', $points);
            $balanceAfter = (int) $lockedCustomer->fresh()->loyalty_points_balance;

            $order->update([
                'redeemed_points' => $points,
                'redeemed_amount' => $amount,
            ]);

            LoyaltyPointsTransaction::create([
                'customer_id' => $lockedCustomer->id,
                'order_id' => $order->id,
                'type' => LoyaltyPointsTransaction::TYPE_REDEEM,
                'points' => $points,
                'balance_after' => $balanceAfter,
                'description' => 'استبدال نقاط على الطلب',
            ]);
        });
    }

    public function earnPoints(Order $order): int
    {
        if (! $order->customer_id || (int) $order->earned_points > 0) {
            return (int) $order->earned_points;
        }

        return DB::transaction(function () use ($order): int {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedOrder->earned_points > 0) {
                return (int) $lockedOrder->earned_points;
            }

            $points = $this->calculateEarnedPoints($lockedOrder->payableAmount());
            $customer = Customer::query()->whereKey($lockedOrder->customer_id)->lockForUpdate()->first();

            if (! $customer || $points === 0) {
                $lockedOrder->update(['earned_points' => 0]);
                return 0;
            }

            $customer->increment('loyalty_points_balance', $points);
            $balanceAfter = (int) $customer->fresh()->loyalty_points_balance;

            $lockedOrder->update(['earned_points' => $points]);
            LoyaltyPointsTransaction::create([
                'customer_id' => $customer->id,
                'order_id' => $lockedOrder->id,
                'type' => LoyaltyPointsTransaction::TYPE_EARN,
                'points' => $points,
                'balance_after' => $balanceAfter,
                'description' => 'اكتساب نقاط بعد تأكيد الدفع',
            ]);

            return $points;
        });
    }

    public function refundRedeemedPoints(Order $order): int
    {
        $points = (int) $order->redeemed_points;

        if (! $order->customer_id || $points === 0) {
            return 0;
        }

        return DB::transaction(function () use ($order, $points): int {
            $alreadyRefunded = LoyaltyPointsTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', LoyaltyPointsTransaction::TYPE_REFUND)
                ->exists();

            if ($alreadyRefunded) {
                return 0;
            }

            $customer = Customer::query()->whereKey($order->customer_id)->lockForUpdate()->first();
            if (! $customer) {
                return 0;
            }

            $customer->increment('loyalty_points_balance', $points);
            $balanceAfter = (int) $customer->fresh()->loyalty_points_balance;

            LoyaltyPointsTransaction::create([
                'customer_id' => $customer->id,
                'order_id' => $order->id,
                'type' => LoyaltyPointsTransaction::TYPE_REFUND,
                'points' => $points,
                'balance_after' => $balanceAfter,
                'description' => 'إرجاع النقاط بعد رفض أو إلغاء الطلب',
            ]);

            return $points;
        });
    }
}
