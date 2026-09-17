<?php

namespace App\Services;

use App\Exceptions\InvalidCouponException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class CouponService
{
    /**
     * التحقق من صلاحية الكوبون وإرجاع تفاصيل الخصم بدون تطبيقه.
     *
     * @throws InvalidCouponException
     */
    public function validate(
        string $code,
        float $subtotal,
        array $items = [],
        ?int $branchId = null,
        ?Customer $customer = null,
        string $channel = 'qr_menu',
        float $shippingFee = 0
    ): array {
        $coupon = Coupon::byCode($code)->first();

        if (!$coupon) {
            throw new InvalidCouponException('كود الكوبون غير موجود.');
        }

        if (!$coupon->isCurrentlyActive()) {
            throw new InvalidCouponException('هذا الكوبون غير نشط أو انتهت صلاحيته.');
        }

        if (!$coupon->hasRemainingUsage()) {
            throw new InvalidCouponException('تم استنفاد الحد الأقصى لاستخدام هذا الكوبون.');
        }

        if ($branchId && !$coupon->appliesToBranch($branchId)) {
            throw new InvalidCouponException('هذا الكوبون غير متاح في هذا الفرع.');
        }

        if (!$coupon->appliesToChannel($channel)) {
            throw new InvalidCouponException('هذا الكوبون غير متاح على هذه القناة.');
        }

        if ($coupon->first_order_only) {
            if (!$customer) {
                throw new InvalidCouponException('يجب تسجيل الدخول لاستخدام كوبون الطلب الأول.');
            }

            $previousOrders = Order::where('customer_id', $customer->id)
                ->whereIn('status', [
                    Order::STATUS_PAID,
                    Order::STATUS_SERVED,
                    Order::STATUS_READY,
                    Order::STATUS_PREPARING,
                    Order::STATUS_ACCEPTED,
                ])
                ->exists();

            if ($previousOrders) {
                throw new InvalidCouponException('هذا الكوبون مخصص للطلب الأول فقط.');
            }
        }

        if ($coupon->usage_limit_per_customer !== null && $customer) {
            $customerUses = CouponRedemption::where('coupon_id', $coupon->id)
                ->where('customer_id', $customer->id)
                ->count();

            if ($customerUses >= $coupon->usage_limit_per_customer) {
                throw new InvalidCouponException('وصلت للحد الأقصى لاستخدام هذا الكوبون.');
            }
        }

        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            throw new InvalidCouponException(
                'الحد الأدنى لاستخدام الكوبون هو ' . number_format((float) $coupon->min_order_amount, 2) . ' ر.س'
            );
        }

        $result = $this->calculateDiscount($coupon, $subtotal, $items, $shippingFee);

        if ($result['discount_amount'] <= 0 && !$result['free_shipping']) {
            throw new InvalidCouponException('لا يمكن تطبيق هذا الكوبون على الطلب الحالي.');
        }

        return [
            'coupon'          => $coupon,
            'discount_amount' => $result['discount_amount'],
            'free_shipping'   => $result['free_shipping'],
            'message'         => $result['message'],
        ];
    }

    protected function calculateDiscount(Coupon $coupon, float $subtotal, array $items, float $shippingFee): array
    {
        $discount = 0.0;
        $freeShipping = false;
        $message = '';

        $eligibleSubtotal = $this->getEligibleSubtotal($coupon, $items, $subtotal);

        switch ($coupon->type) {
            case Coupon::TYPE_PERCENTAGE:
                $discount = round($eligibleSubtotal * ((float) $coupon->discount_value / 100), 2);
                if ($coupon->max_discount_amount !== null) {
                    $discount = min($discount, (float) $coupon->max_discount_amount);
                }
                $message = "خصم {$coupon->discount_value}%";
                break;

            case Coupon::TYPE_FIXED:
                $discount = min((float) $coupon->discount_value, $eligibleSubtotal);
                $message = "خصم " . number_format((float) $coupon->discount_value, 2) . " ر.س";
                break;

            case Coupon::TYPE_FREE_SHIPPING:
                $freeShipping = true;
                $discount = 0;
                $message = 'شحن مجاني';
                break;

            case Coupon::TYPE_BUY_X_GET_Y:
                $discount = $this->calculateBuyXGetY($coupon, $items);
                $message = "اشتري {$coupon->buy_quantity} واحصل على {$coupon->get_quantity}";
                break;
        }

        $discount = min($discount, $eligibleSubtotal);

        return [
            'discount_amount' => round(max(0, $discount), 2),
            'free_shipping'   => $freeShipping,
            'message'         => $message,
        ];
    }

    protected function calculateBuyXGetY(Coupon $coupon, array $items): float
    {
        $buyQty = (int) $coupon->buy_quantity;
        $getQty = (int) $coupon->get_quantity;

        if ($buyQty < 1 || $getQty < 1) {
            return 0;
        }

        $units = [];
        foreach ($items as $item) {
            if (!$this->itemIsEligible($coupon, $item)) {
                continue;
            }
            $qty = (int) ($item['qty'] ?? 1);
            $price = (float) ($item['unit_price'] ?? 0);
            for ($i = 0; $i < $qty; $i++) {
                $units[] = $price;
            }
        }

        if (count($units) < ($buyQty + $getQty)) {
            return 0;
        }

        sort($units);

        $groupSize = $buyQty + $getQty;
        $groups = intdiv(count($units), $groupSize);
        $discount = 0.0;

        $freeUnits = array_slice($units, 0, $groups * $getQty);

        $getType = $coupon->get_discount_type ?? 'free';
        $getValue = (float) ($coupon->get_discount_value ?? 0);

        foreach ($freeUnits as $price) {
            if ($getType === 'free') {
                $discount += $price;
            } elseif ($getType === 'percentage') {
                $discount += $price * ($getValue / 100);
            } elseif ($getType === 'fixed') {
                $discount += min($getValue, $price);
            }
        }

        return round($discount, 2);
    }

    protected function getEligibleSubtotal(Coupon $coupon, array $items, float $fallbackSubtotal): float
    {
        $hasRestrictions = $coupon->products()->exists()
            || $coupon->menuItems()->exists()
            || $coupon->productCategories()->exists();

        if (!$hasRestrictions || empty($items)) {
            return $fallbackSubtotal;
        }

        $total = 0.0;
        foreach ($items as $item) {
            if ($this->itemIsEligible($coupon, $item)) {
                $total += ((float) ($item['unit_price'] ?? 0)) * ((int) ($item['qty'] ?? 1));
            }
        }

        return $total;
    }

    protected function itemIsEligible(Coupon $coupon, array $item): bool
    {
        $type = $item['type'] ?? 'menu_item';
        $id   = $item['id'] ?? null;
        $categoryId = $item['category_id'] ?? null;

        $hasProductRestriction  = $coupon->products()->exists();
        $hasMenuItemRestriction = $coupon->menuItems()->exists();
        $hasCategoryRestriction = $coupon->productCategories()->exists();

        if (!$hasProductRestriction && !$hasMenuItemRestriction && !$hasCategoryRestriction) {
            return true;
        }

        if ($type === 'product' && $hasProductRestriction && $id) {
            if ($coupon->products()->where('products.id', $id)->exists()) {
                return true;
            }
        }

        if ($type === 'menu_item' && $hasMenuItemRestriction && $id) {
            if ($coupon->menuItems()->where('menu_items.id', $id)->exists()) {
                return true;
            }
        }

        if ($hasCategoryRestriction && $categoryId) {
            if ($coupon->productCategories()->where('product_categories.id', $categoryId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * تطبيق الكوبون على الطلب وتسجيل الاستخدام (يُستدعى داخل transaction).
     */
    public function applyToOrder(Order $order, Coupon $coupon, float $discountAmount, bool $freeShipping = false): void
    {
        $order->update([
            'coupon_id'       => $coupon->id,
            'coupon_code'     => $coupon->code,
            'coupon_discount' => $discountAmount,
            'free_shipping'   => $freeShipping,
        ]);

        if ($freeShipping && $order->shipment) {
            $order->shipment->update(['shipping_fee' => 0]);
        }

        CouponRedemption::create([
            'coupon_id'       => $coupon->id,
            'order_id'        => $order->id,
            'customer_id'     => $order->customer_id,
            'discount_amount' => $discountAmount,
            'code_used'       => $coupon->code,
        ]);

        $coupon->increment('used_count');
    }

    /**
     * إلغاء استخدام الكوبون (في حالة رفض/إلغاء الطلب).
     */
    public function reverseRedemption(Order $order): void
    {
        if (!$order->coupon_id) {
            return;
        }

        $redemption = CouponRedemption::where('order_id', $order->id)->first();
        if ($redemption) {
            $redemption->delete();
            Coupon::where('id', $order->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
        }

        $order->update([
            'coupon_id'       => null,
            'coupon_code'     => null,
            'coupon_discount' => 0,
            'free_shipping'   => false,
        ]);
    }
}