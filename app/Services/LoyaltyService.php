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

    public function canRedeem(Customer $customer, int $points, float $orderTotal): bool
    {
        return $this->validateRedemption($customer, $points, $orderTotal) === null;
    }

    /* ============================================================
     |  استبدال النقاط (عند إنشاء الأوردر) — FIFO
     ============================================================ */

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

            $reason = $this->validateRedemption(
                $lockedCustomer,
                $points,
                (float) $order->total_amount
            );

            if ($reason !== null) {
                throw new InsufficientLoyaltyPointsException($reason);
            }

            // ===== FIFO: خصم من أقدم النقاط الصالحة أولاً =====
            $needed = $points;

            $earnTxs = LoyaltyPointsTransaction::query()
                ->where('customer_id', $lockedCustomer->id)
                ->where('type', LoyaltyPointsTransaction::TYPE_EARN)
                ->where('remaining_points', '>', 0)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })
                ->orderByRaw('expires_at IS NULL ASC')
                ->orderBy('expires_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $available = (int) $earnTxs->sum('remaining_points');

            if ($available < $needed) {
                throw new InsufficientLoyaltyPointsException(
                    "رصيدك المتاح الصالح للاستخدام {$available} نقطة فقط (بعض النقاط قد تكون منتهية)."
                );
            }

            foreach ($earnTxs as $tx) {
                if ($needed <= 0) {
                    break;
                }

                $take = min($needed, (int) $tx->remaining_points);
                $tx->decrement('remaining_points', $take);
                $needed -= $take;
            }
            // ==================================================

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

    public function earnPoints(Order $order): int
    {
        if (! $order->customer_id || (int) $order->earned_points > 0) {
            return (int) $order->earned_points;
        }

        return DB::transaction(function () use ($order): int {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedOrder->earned_points > 0) {
                return (int) $lockedOrder->earned_points;
            }

            $paidAmount = (float) $lockedOrder->payableAmount();
            $points     = $this->calculateEarnedPoints($paidAmount);

            $customer = Customer::query()
                ->whereKey($lockedOrder->customer_id)
                ->lockForUpdate()
                ->first();

            if (! $customer || $points === 0) {
                $lockedOrder->update(['earned_points' => 0]);
                return 0;
            }

            $settings  = LoyaltySetting::current();
            $expiresAt = $settings->points_expiry_months > 0
                ? now()->addMonths((int) $settings->points_expiry_months)
                : null;

            $customer->increment('loyalty_points_balance', $points);
            $balanceAfter = (int) $customer->fresh()->loyalty_points_balance;

            // تحديث إجمالي المبلغ المدفوع + الرتبة (مرة واحدة فقط)
            $customer->increment('total_spent', $paidAmount);
            $this->updateCustomerTier($customer->fresh());

            $lockedOrder->update(['earned_points' => $points]);

            LoyaltyPointsTransaction::create([
                'customer_id'      => $customer->id,
                'order_id'         => $lockedOrder->id,
                'type'             => LoyaltyPointsTransaction::TYPE_EARN,
                'points'           => $points,
                'remaining_points' => $points,
                'balance_after'    => $balanceAfter,
                'description'      => 'اكتساب نقاط بعد تأكيد الدفع',
                'expires_at'       => $expiresAt,
            ]);

            return $points;
        });
    }

    /* ============================================================
     |  إرجاع النقاط المستخدمة (عند الرفض أو الإلغاء)
     ============================================================ */

    public function refundRedeemedPoints(Order $order): int
    {
        $points = (int) $order->redeemed_points;

        if (! $order->customer_id || $points === 0) {
            return 0;
        }

        if (! in_array($order->status, [
            Order::STATUS_REJECTED,
            Order::STATUS_CANCELLED,
        ], true)) {
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

    public function revokeEarnedPoints(Order $order): int
    {
        $points = (int) $order->earned_points;

        if (! $order->customer_id || $points === 0) {
            return 0;
        }

        return DB::transaction(function () use ($order, $points): int {
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

            $pointsToRevoke = min($points, (int) $customer->loyalty_points_balance);

            if ($pointsToRevoke === 0) {
                return 0;
            }

            $earnTx = LoyaltyPointsTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', LoyaltyPointsTransaction::TYPE_EARN)
                ->lockForUpdate()
                ->first();

            if ($earnTx && $earnTx->remaining_points > 0) {
                $fromRemaining = min($pointsToRevoke, (int) $earnTx->remaining_points);
                $earnTx->decrement('remaining_points', $fromRemaining);
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

    /* ============================================================
     |  انتهاء صلاحية النقاط
     ============================================================ */

    public function expirePoints(): int
    {
        $expiredTxs = LoyaltyPointsTransaction::query()
            ->where('type', LoyaltyPointsTransaction::TYPE_EARN)
            ->where('remaining_points', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $totalExpired = 0;

        foreach ($expiredTxs as $tx) {
            $expired = DB::transaction(function () use ($tx): int {
                $lockedTx = LoyaltyPointsTransaction::query()
                    ->whereKey($tx->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedTx || $lockedTx->remaining_points <= 0) {
                    return 0;
                }

                $points = (int) $lockedTx->remaining_points;

                $customer = Customer::query()
                    ->whereKey($lockedTx->customer_id)
                    ->lockForUpdate()
                    ->first();

                if (! $customer) {
                    $lockedTx->update(['remaining_points' => 0]);
                    return 0;
                }

                $toExpire = min($points, (int) $customer->loyalty_points_balance);

                if ($toExpire <= 0) {
                    $lockedTx->update(['remaining_points' => 0]);
                    return 0;
                }

                $customer->decrement('loyalty_points_balance', $toExpire);
                $lockedTx->update(['remaining_points' => 0]);

                LoyaltyPointsTransaction::create([
                    'customer_id'   => $customer->id,
                    'order_id'      => $lockedTx->order_id,
                    'type'          => LoyaltyPointsTransaction::TYPE_EXPIRE,
                    'points'        => $toExpire,
                    'balance_after' => (int) $customer->fresh()->loyalty_points_balance,
                    'description'   => 'انتهاء صلاحية نقاط',
                ]);

                return $toExpire;
            });

            $totalExpired += $expired;
        }

        return $totalExpired;
    }

    /* ============================================================
     |  تحديث رتبة العميل (Bronze / Silver / Gold)
     ============================================================ */

    public function updateCustomerTier(Customer $customer): void
    {
        $settings = LoyaltySetting::current();
        $spent    = (float) $customer->total_spent;

        $tier = match (true) {
            $spent >= (float) $settings->tier_gold_min_spent   => 'gold',
            $spent >= (float) $settings->tier_silver_min_spent => 'silver',
            default                                            => 'bronze',
        };

        if ($customer->tier !== $tier) {
            $customer->update(['tier' => $tier]);
        }
    }
}