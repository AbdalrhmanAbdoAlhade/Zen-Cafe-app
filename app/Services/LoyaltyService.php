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
    /* ============================================================
     |  حساب النقاط
     ============================================================ */

    /**
     * حساب النقاط المكتسبة من مبلغ معين
     *
     * المعادلة: floor(المبلغ ÷ points_earn_rate)
     * مثال: rate = 10 → 100 جنيه = 10 نقاط
     */
    public function calculateEarnedPoints(float $paidAmount): int
    {
        $rate = (float) LoyaltySetting::current()->points_earn_rate;

        return $rate > 0
            ? (int) floor(max(0, $paidAmount) / $rate)
            : 0;
    }

    /* ============================================================
     |  التحقق من إمكانية الاستبدال
     ============================================================ */

    /**
     * التحقق من إمكانية الاستبدال مع إرجاع سبب واضح للفشل.
     *
     * الشروط:
     *  1. عدد النقاط موجب
     *  2. خدمة الاستبدال مفعّلة (قيمة النقطة > 0)
     *  3. النقاط >= الحد الأدنى للاستبدال
     *  4. النقاط <= رصيد العميل
     *  5. قيمة الخصم لا تتجاوز قيمة الطلب
     *
     * @return string|null  ترجع سبب الفشل، أو null لو كل الشروط سليمة.
     */
    public function validateRedemption(Customer $customer, int $points, float $orderTotal): ?string
    {
        if ($points <= 0) {
            return 'عدد النقاط المطلوب استخدامه غير صالح.';
        }

        $settings = LoyaltySetting::current();
        $value    = (float) $settings->point_redemption_value;
        $min      = (int) $settings->minimum_points_to_redeem;
        $balance  = (int) $customer->loyalty_points_balance;

        if ($value <= 0) {
            return 'خدمة استبدال النقاط غير مفعّلة حاليًا. تواصل مع الدعم.';
        }

        if ($points < $min) {
            return "الحد الأدنى لاستخدام النقاط هو {$min} نقطة.";
        }

        if ($points > $balance) {
            return "رصيدك الحالي {$balance} نقطة فقط، وطلبت استخدام {$points} نقطة.";
        }

        $discount = round($points * $value, 2);

        if ($discount > $orderTotal + 0.01) {
            return 'قيمة الخصم الناتجة عن النقاط أكبر من قيمة الطلب. قلّل عدد النقاط المطلوبة.';
        }

        return null;
    }

    /**
     * التحقق من إمكانية الاستبدال (boolean) — للتوافق مع الكود القديم.
     */
    public function canRedeem(Customer $customer, int $points, float $orderTotal): bool
    {
        return $this->validateRedemption($customer, $points, $orderTotal) === null;
    }

    /* ============================================================
     |  استبدال النقاط (عند إنشاء الأوردر)
     ============================================================ */

    /**
     * خصم النقاط من رصيد العميل عند استخدامها في الأوردر.
     * بتنشئ record في loyalty_points_transactions بنوع 'redeem'.
     */
    public function redeemPoints(Order $order, ?Customer $customer, int $points): void
    {
        if ($points === 0) {
            return;
        }

        if (! $customer) {
            throw new InsufficientLoyaltyPointsException(
                'لاستخدام النقاط يجب إدخال رقم هاتف مرتبط بحساب العميل.'
            );
        }

        if ($points < 0) {
            throw new InsufficientLoyaltyPointsException(
                'عدد النقاط المطلوب استخدامه غير صالح.'
            );
        }

        DB::transaction(function () use ($order, $customer, $points): void {
            $lockedCustomer = Customer::query()
                ->whereKey($customer->id)
                ->lockForUpdate()
                ->firstOrFail();

            $settings = LoyaltySetting::current();
            $value    = (float) $settings->point_redemption_value;
            $amount   = round($points * $value, 2);

            // رسالة خطأ دقيقة حسب السبب الفعلي
            $reason = $this->validateRedemption(
                $lockedCustomer,
                $points,
                (float) $order->total_amount
            );

            if ($reason !== null) {
                throw new InsufficientLoyaltyPointsException($reason);
            }

            $lockedCustomer->decrement('loyalty_points_balance', $points);
            $balanceAfter = (int) $lockedCustomer->fresh()->loyalty_points_balance;

            $order->update([
                'redeemed_points' => $points,
                'redeemed_amount' => $amount,
            ]);

            LoyaltyPointsTransaction::create([
                'customer_id'   => $lockedCustomer->id,
                'order_id'      => $order->id,
                'type'          => LoyaltyPointsTransaction::TYPE_REDEEM,
                'points'        => $points,
                'balance_after' => $balanceAfter,
                'description'   => 'استبدال نقاط على الطلب',
            ]);
        });
    }

    /* ============================================================
     |  كسب النقاط (عند تأكيد الدفع)
     ============================================================ */

    /**
     * منح العميل نقاطًا مكتسبة بعد تأكيد دفع الأوردر.
     *
     * ملاحظة: النقاط تُحسب على payableAmount() = total_amount - redeemed_amount
     * لو عايز تحسبها على القيمة الكاملة قبل الخصم، غيّرها إلى:
     *     $this->calculateEarnedPoints((float) $lockedOrder->total_amount);
     *
     * الدالة idempotent — لو اتنادت مرتين على نفس الأوردر مش هتضاعف النقاط.
     */
    public function earnPoints(Order $order): int
    {
        // حماية سريعة قبل الدخول على الـ transaction
        if (! $order->customer_id || (int) $order->earned_points > 0) {
            return (int) $order->earned_points;
        }

        return DB::transaction(function () use ($order): int {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            // حماية مزدوجة داخل الـ transaction
            if ((int) $lockedOrder->earned_points > 0) {
                return (int) $lockedOrder->earned_points;
            }

            $points = $this->calculateEarnedPoints((float) $lockedOrder->payableAmount());

            $customer = Customer::query()
                ->whereKey($lockedOrder->customer_id)
                ->lockForUpdate()
                ->first();

            if (! $customer || $points === 0) {
                $lockedOrder->update(['earned_points' => 0]);
                return 0;
            }

            $customer->increment('loyalty_points_balance', $points);
            $balanceAfter = (int) $customer->fresh()->loyalty_points_balance;

            $lockedOrder->update(['earned_points' => $points]);

            LoyaltyPointsTransaction::create([
                'customer_id'   => $customer->id,
                'order_id'      => $lockedOrder->id,
                'type'          => LoyaltyPointsTransaction::TYPE_EARN,
                'points'        => $points,
                'balance_after' => $balanceAfter,
                'description'   => 'اكتساب نقاط بعد تأكيد الدفع',
            ]);

            return $points;
        });
    }

    /* ============================================================
     |  إرجاع النقاط المستخدمة (عند الرفض أو الإلغاء)
     ============================================================ */

    /**
     * إرجاع النقاط اللي العميل استخدمها في أوردر اترفض أو اتلغى.
     *
     * بتشتغل بس لو:
     *  - الأوردر حالته rejected أو cancelled
     *  - الأوردر مستخدم فيه نقاط (redeemed_points > 0)
     *  - ما اترجعتش قبل كده
     */
    public function refundRedeemedPoints(Order $order): int
    {
        $points = (int) $order->redeemed_points;

        if (! $order->customer_id || $points === 0) {
            return 0;
        }

        // ما نرجّعش النقاط إلا لو الأوردر مرفوض/ملغي فعلاً
        if (! in_array($order->status, [
            Order::STATUS_REJECTED,
            Order::STATUS_CANCELLED,
        ], true)) {
            return 0;
        }

        return DB::transaction(function () use ($order, $points): int {
            // حماية من الإرجاع المكرر
            $alreadyRefunded = LoyaltyPointsTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', LoyaltyPointsTransaction::TYPE_REFUND)
                ->exists();

            if ($alreadyRefunded) {
                return 0;
            }

            $customer = Customer::query()
                ->whereKey($order->customer_id)
                ->lockForUpdate()
                ->first();

            if (! $customer) {
                return 0;
            }

            $customer->increment('loyalty_points_balance', $points);
            $balanceAfter = (int) $customer->fresh()->loyalty_points_balance;

            LoyaltyPointsTransaction::create([
                'customer_id'   => $customer->id,
                'order_id'      => $order->id,
                'type'          => LoyaltyPointsTransaction::TYPE_REFUND,
                'points'        => $points,
                'balance_after' => $balanceAfter,
                'description'   => 'إرجاع النقاط بعد رفض أو إلغاء الطلب',
            ]);

            return $points;
        });
    }

    /* ============================================================
     |  سحب النقاط المكتسبة (عند إلغاء أوردر مدفوع)
     ============================================================ */

    /**
     * سحب النقاط اللي العميل كسبها من أوردر اترفض أو اتلغى *بعد الدفع*.
     *
     * السيناريو: عميل دفع → كسب نقاط → الأوردر اتلغى لاحقًا.
     * في الحالة دي لازم نسحب النقاط المكتسبة.
     *
     * ملاحظات:
     *  - مش بنسحب أكتر من الرصيد المتاح حاليًا (لو العميل صرفها)
     *  - idempotent — لو اتنادت مرتين مش هتسحب مرتين
     */
    public function revokeEarnedPoints(Order $order): int
    {
        $points = (int) $order->earned_points;

        if (! $order->customer_id || $points === 0) {
            return 0;
        }

        return DB::transaction(function () use ($order, $points): int {
            // حماية من السحب المكرر
            $alreadyRevoked = LoyaltyPointsTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', LoyaltyPointsTransaction::TYPE_REVOKE)
                ->exists();

            if ($alreadyRevoked) {
                return 0;
            }

            $customer = Customer::query()
                ->whereKey($order->customer_id)
                ->lockForUpdate()
                ->first();

            if (! $customer) {
                return 0;
            }

            // ما نسحبش أكتر من الرصيد المتاح (لو العميل صرف النقاط)
            $pointsToRevoke = min($points, (int) $customer->loyalty_points_balance);

            if ($pointsToRevoke === 0) {
                return 0;
            }

            $customer->decrement('loyalty_points_balance', $pointsToRevoke);
            $balanceAfter = (int) $customer->fresh()->loyalty_points_balance;

            LoyaltyPointsTransaction::create([
                'customer_id'   => $customer->id,
                'order_id'      => $order->id,
                'type'          => LoyaltyPointsTransaction::TYPE_REVOKE,
                'points'        => $pointsToRevoke,
                'balance_after' => $balanceAfter,
                'description'   => 'سحب النقاط المكتسبة بعد إلغاء الطلب',
            ]);

            return $pointsToRevoke;
        });
    }
}