<?php

namespace App\Services;

use App\Events\OrderCreated;
use App\Exceptions\OrderItemUnavailableException;
use App\Exceptions\ReorderUnavailableException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MenuOptionValue;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\QrCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\OnlinePayment;

class OrderService
{
    public function __construct(
        private readonly CartValidationService $cartValidation,
        private readonly StockService $stockService,
        private readonly CouponService $couponService,
    ) {
    }

    public function createOrder(QrCode $qrCode, array $payload): array
    {
        return $this->create($qrCode->branch, $payload, $qrCode, false);
    }

    public function createOnlineOrder(Branch $branch, array $payload): array
    {
        return $this->create($branch, $payload, null, true);
    }

    /**
     * إعادة طلب سابق - بدون مفهوم "سلة" منفصلة، بيبني payload من نفس أصناف
     * الطلب القديم وبينادي createOnlineOrder() العادية.
     *
     * @throws ReorderUnavailableException لو مفيش أي صنف متاح
     */
    public function reorder(
        Customer $customer,
        Order $sourceOrder,
        int $redeemedPoints = 0,
        ?string $fulfillmentType = null
    ): array {
        $sourceOrder->loadMissing('items.menuItem', 'items.options');
        $branch = $sourceOrder->branch;

        $items = [];
        $skipped = [];

        foreach ($sourceOrder->items as $orderItem) {
            if (! $orderItem->menu_item_id) {
                continue;
            }

            $stillAvailable = $branch->menuItems()
                ->wherePivot('is_available', true)
                ->where('menu_items.id', $orderItem->menu_item_id)
                ->where('menu_items.is_available', true)
                ->where('menu_items.is_available_online', true)
                ->exists();

            if (! $stillAvailable) {
                $skipped[] = [
                    'menu_item_id' => $orderItem->menu_item_id,
                    'name_ar' => $orderItem->menuItem->name_ar ?? null,
                    'name_en' => $orderItem->menuItem->name_en ?? null,
                ];
                continue;
            }

            $items[] = [
                'menu_item_id' => $orderItem->menu_item_id,
                'quantity' => $orderItem->quantity,
                'notes' => $orderItem->notes,
                'option_value_ids' => $orderItem->options->pluck('menu_option_value_id')->all(),
            ];
        }

        if (empty($items)) {
            throw new ReorderUnavailableException();
        }

        // أولوية: القيمة الممررة → نوع الطلب القديم لو كان صالح → pickup افتراضي
        $resolvedFulfillment = $fulfillmentType
            ?? (in_array($sourceOrder->fulfillment_type, [
                Order::FULFILLMENT_PICKUP,
                Order::FULFILLMENT_DINE_IN,
            ], true) ? $sourceOrder->fulfillment_type : null)
            ?? Order::FULFILLMENT_PICKUP;

        $result = $this->createOnlineOrder($branch, [
            'customer' => [
                'phone' => $customer->phone,
                'name' => $customer->name,
                'email' => $customer->email,
            ],
            'items' => $items,
            'redeemed_points' => $redeemedPoints,
            'fulfillment_type' => $resolvedFulfillment,
        ]);

        $result['skipped_items'] = $skipped;

        return $result;
    }

    /**
     * إنشاء أوردر متجر (منتجات قابلة للشحن أو استلام سريع).
     */
    public function createStoreOrder(Branch $branch, array $payload): array
    {
        $this->cartValidation->assertNotMixed($payload['items']);

        return DB::transaction(function () use ($branch, $payload) {
            [$customer, $plainPassword] = $this->findOrCreateCustomer(
                $payload['customer']['phone'] ?? null,
                $payload['customer']['name'] ?? null,
                $payload['customer']['email'] ?? null,
                true
            );

            $order = Order::create([
                'branch_id' => $branch->id,
                'customer_id' => $customer?->id,
                'order_type' => 'store',
                'fulfillment_type' => $payload['fulfillment_type'],
                'status' => Order::STATUS_PENDING,
                'total_amount' => 0,
                'estimated_preparation_minutes' => 0,
                'notes' => $payload['notes'] ?? null,
            ]);

            $total = 0;

            foreach ($payload['items'] as $item) {
                $total += $this->addStoreOrderItem($order, $item);
            }

            if ($payload['fulfillment_type'] === Order::FULFILLMENT_SHIPPING
                || $payload['fulfillment_type'] === 'shipping') {
                $shippingFee = (float) ($payload['shipping']['fee'] ?? 0);

                OrderShipment::create([
                    'order_id' => $order->id,
                    'recipient_name' => $payload['shipping']['recipient_name'],
                    'recipient_phone' => $payload['shipping']['recipient_phone'],
                    'city' => $payload['shipping']['city'],
                    'address_line' => $payload['shipping']['address_line'],
                    'building' => $payload['shipping']['building'] ?? null,
                    'landmark' => $payload['shipping']['landmark'] ?? null,
                    'shipping_fee' => $shippingFee,
                ]);

                $total += $shippingFee;
            }

            $order->update(['total_amount' => $total]);

            if (! empty($payload['coupon_code'])) {
                $shippingFee = 0;
                if ($order->shipment) {
                    $shippingFee = (float) $order->shipment->shipping_fee;
                }

                $itemsForCoupon = $order->items->map(function ($oi) {
                    $productId = $oi->productVariant?->product_id;
                    $categoryId = $oi->productVariant?->product?->product_category_id;

                    return [
                        'id' => $productId,
                        'type' => 'product',
                        'qty' => $oi->quantity,
                        'unit_price' => (float) $oi->unit_price,
                        'category_id' => $categoryId,
                    ];
                })->all();

                $subtotal = $total - $shippingFee;

                $result = $this->couponService->validate(
                    code: $payload['coupon_code'],
                    subtotal: $subtotal,
                    items: $itemsForCoupon,
                    branchId: $branch->id,
                    customer: $customer,
                    channel: 'store',
                    shippingFee: $shippingFee,
                );

                $this->couponService->applyToOrder(
                    $order,
                    $result['coupon'],
                    $result['discount_amount'],
                    $result['free_shipping']
                );

                if ($result['free_shipping'] && $shippingFee > 0) {
                    $order->update(['total_amount' => $total - $shippingFee]);
                }
            }

            event(new OrderCreated($order));

            return [
                'order' => $order->fresh(['items.productVariant.product', 'branch', 'shipment']),
                'customer_credentials' => $plainPassword ? [
                    'phone' => $customer->phone,
                    'password' => $plainPassword,
                ] : null,
                'customer_auth_token' => $customer
                    ? $customer->createToken('customer-access')->plainTextToken
                    : null,
            ];
        });
    }

    private function addStoreOrderItem(Order $order, array $cartItem): float
    {
        $variant = \App\Models\ProductVariant::with('product')
            ->where('is_available', true)
            ->whereHas('product', fn ($q) => $q->where('is_available', true))
            ->find($cartItem['product_variant_id']);

        if (! $variant) {
            throw new OrderItemUnavailableException($cartItem['product_variant_id']);
        }

        $quantity = max(1, (int) ($cartItem['quantity'] ?? 1));
        $unitPrice = $variant->effectivePrice();

        $this->stockService->deduct($variant, $quantity, $order);

        $order->items()->create([
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'notes' => $cartItem['notes'] ?? null,
        ]);

        return $unitPrice * $quantity;
    }

    private function create(Branch $branch, array $payload, ?QrCode $qrCode, bool $online): array
    {
        return DB::transaction(function () use ($branch, $payload, $qrCode, $online) {
            [$customer, $plainPassword] = $this->findOrCreateCustomer(
                $payload['customer']['phone'] ?? null,
                $payload['customer']['name'] ?? null,
                $payload['customer']['email'] ?? null,
                $online
            );

            // QR على الطاولة: fulfillment_type = null
            // منيو أونلاين: إجباري pickup | dine_in
            $fulfillmentType = null;

            if ($online) {
                $fulfillmentType = $payload['fulfillment_type'] ?? null;

                if (! in_array($fulfillmentType, [
                    Order::FULFILLMENT_PICKUP,
                    Order::FULFILLMENT_DINE_IN,
                ], true)) {
                    throw new \InvalidArgumentException(
                        'طلب المنيو الأونلاين يتطلب fulfillment_type: pickup أو dine_in.'
                    );
                }
            }

            $order = Order::create([
                'branch_id' => $branch->id,
                'table_id' => $qrCode?->table_id,
                'qr_code_id' => $qrCode?->id,
                'customer_id' => $customer?->id,
                'order_type' => $online ? 'pre_order' : 'in_branch',
                'fulfillment_type' => $fulfillmentType,
                'status' => Order::STATUS_PENDING,
                'total_amount' => 0,
                'estimated_preparation_minutes' => 0,
                'received_at' => $online ? ($payload['received_at'] ?? null) : null,
                'notes' => $payload['notes'] ?? null,
            ]);

            $total = 0;
            $maxPrep = 0;

            foreach ($payload['items'] as $item) {
                [$line, $prep] = $this->addOrderItem($order, $branch, $item, $online);
                $total += $line;
                $maxPrep = max($maxPrep, $prep);
            }

            $totalPrep = $maxPrep + (int) $branch->current_prep_offset_minutes;

            $order->update([
                'total_amount' => $total,
                'estimated_preparation_minutes' => $totalPrep,
                'estimated_ready_at' => now()->addMinutes($totalPrep),
            ]);

            if (! empty($payload['coupon_code'])) {
                $channel = $online ? 'online_menu' : 'qr_menu';

                $itemsForCoupon = $order->items->map(fn ($oi) => [
                    'id' => $oi->menu_item_id,
                    'type' => 'menu_item',
                    'qty' => $oi->quantity,
                    'unit_price' => (float) $oi->unit_price,
                ])->all();

                $result = $this->couponService->validate(
                    code: $payload['coupon_code'],
                    subtotal: $total,
                    items: $itemsForCoupon,
                    branchId: $branch->id,
                    customer: $customer,
                    channel: $channel,
                );

                $this->couponService->applyToOrder(
                    $order,
                    $result['coupon'],
                    $result['discount_amount'],
                    $result['free_shipping']
                );
            }

            app(LoyaltyService::class)->redeemPoints(
                $order,
                $customer,
                (int) ($payload['redeemed_points'] ?? 0)
            );

            event(new OrderCreated($order));

            return [
                'order' => $order->fresh(['items.menuItem', 'items.options.menuOptionValue', 'branch', 'table']),
                'customer_credentials' => $plainPassword ? [
                    'phone' => $customer->phone,
                    'password' => $plainPassword,
                ] : null,
                'customer_auth_token' => ($online && $customer)
                    ? $customer->createToken('customer-access')->plainTextToken
                    : null,
            ];
        });
    }

    private function addOrderItem(Order $order, Branch $branch, array $cartItem, bool $online): array
    {
        $item = $branch->menuItems()
            ->wherePivot('is_available', true)
            ->where('menu_items.is_available', true)
            ->when($online, fn ($q) => $q->where('menu_items.is_available_online', true))
            ->where('menu_items.id', $cartItem['menu_item_id'])
            ->first();

        if (! $item) {
            throw new OrderItemUnavailableException($cartItem['menu_item_id']);
        }

        $quantity = max(1, (int) ($cartItem['quantity'] ?? 1));
        $unitPrice = (float) ($item->pivot->price_override ?? $item->base_price);
        $prep = (int) $item->preparation_time_minutes;

        $orderItem = $order->items()->create([
            'menu_item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'preparation_time_minutes' => $prep,
            'vat' => $item->vat,
            'notes' => $cartItem['notes'] ?? null,
        ]);

        $optionsTotal = 0;

        foreach ($cartItem['option_value_ids'] ?? [] as $id) {
            $value = MenuOptionValue::whereHas('option', fn ($q) => $q->where('menu_item_id', $item->id))
                ->find($id);

            if (! $value) {
                continue;
            }

            $orderItem->options()->create([
                'menu_option_value_id' => $value->id,
                'extra_price' => $value->extra_price,
            ]);

            $optionsTotal += (float) $value->extra_price;
        }

        return [(($unitPrice + $optionsTotal) * $quantity), $prep];
    }

    private function findOrCreateCustomer(?string $phone, ?string $name, ?string $email, bool $online): array
    {
        if (! $phone) {
            return [null, null];
        }

        $phone = trim($phone);
        $existing = Customer::where('phone', $phone)->first();

        if ($existing) {
            return [$existing, null];
        }

        $plainPassword = $online ? null : Str::password(8, symbols: false);

        $customer = Customer::create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $plainPassword,
        ]);

        return [$customer, $plainPassword];
    }

       public function serializeOrder(Order $order): array
    {
        // كويري واحدة (أو صفر لو onlinePayments متحملة eager)
        $payments = $order->relationLoaded('onlinePayments')
            ? $order->onlinePayments
            : $order->onlinePayments()->get();

        $paidOnline    = $payments->contains('status', OnlinePayment::STATUS_PAID);
        $latestPayment = $payments->sortByDesc('id')->first();

        $canPayOnline = in_array($order->order_type, ['pre_order', 'store'], true)
            && ! in_array($order->status, [
                Order::STATUS_CANCELLED,
                Order::STATUS_REJECTED,
                Order::STATUS_PAID,
            ], true)
            && ! $paidOnline
            && (float) $order->payableAmount() > 0;

        $data = [
            'id' => $order->id,
            'order_type' => $order->order_type,
            'status' => $order->status,
            'total_amount' => (float) $order->total_amount,
            'estimated_preparation_minutes' => (int) $order->estimated_preparation_minutes,
            'estimated_ready_at' => $order->estimated_ready_at,
            'created_at' => $order->created_at,
            'redeemed_points' => (int) $order->redeemed_points,
            'redeemed_amount' => (float) $order->redeemed_amount,
            'coupon_code' => $order->coupon_code,
            'coupon_discount' => (float) $order->coupon_discount,
            'free_shipping' => (bool) $order->free_shipping,
            'payable_amount' => $order->payableAmount(),
            'earned_points' => (int) $order->earned_points,
            // 💳 الدفع الأونلاين
            'paid_online' => $paidOnline,
            'online_payment_status' => $latestPayment?->status, // initiated | paid | failed | refunded | refund_failed | null
            'can_pay_online' => $canPayOnline,
            'items' => $order->items->map(fn ($item) => $this->serializeOrderItem($item))->values(),
        ];

        // منيو أونلاين + ستور: نرجّع نوع الاستلام
        if (in_array($order->order_type, ['pre_order', 'store'], true)) {
            $data['fulfillment_type'] = $order->fulfillment_type;
            $data['fulfillment_label_ar'] = method_exists($order, 'fulfillmentLabelAr')
                ? $order->fulfillmentLabelAr()
                : match ($order->fulfillment_type) {
                    'pickup' => 'أخذ من الفرع',
                    'dine_in' => 'شرب في الفرع',
                    'shipping' => 'شحن',
                    default => null,
                };
        }

        if ($order->order_type === 'pre_order') {
            $data['received_at'] = $order->received_at;
        }

        if ($order->order_type === 'store') {
            $data['shipment'] = $order->shipment ? [
                'city' => $order->shipment->city,
                'address_line' => $order->shipment->address_line,
                'status' => $order->shipment->status,
                'tracking_number' => $order->shipment->tracking_number,
                'shipping_fee' => (float) $order->shipment->shipping_fee,
                'shipped_at' => $order->shipment->shipped_at,
                'delivered_at' => $order->shipment->delivered_at,
            ] : null;
        }

        return $data;
    }

    private function serializeOrderItem($item): array
    {
        if ($item->product_variant_id) {
            return $this->serializeStoreOrderItem($item);
        }

        $optionsTotal = $item->options->sum('extra_price');

        return [
            'id' => $item->id,
            'menu_item_id' => $item->menu_item_id,
            'name_ar' => $item->menuItem?->name_ar,
            'name_en' => $item->menuItem?->name_en,
            'image' => $item->menuItem?->image,
            'quantity' => (int) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'vat' => (float) $item->vat,
            'preparation_time_minutes' => (int) $item->preparation_time_minutes,
            'notes' => $item->notes,
            'options' => $item->options->map(fn ($option) => [
                'id' => $option->id,
                'menu_option_value_id' => $option->menu_option_value_id,
                'name_ar' => $option->menuOptionValue?->name_ar,
                'name_en' => $option->menuOptionValue?->name_en,
                'extra_price' => (float) $option->extra_price,
            ])->values(),
            'line_total' => (float) (($item->unit_price + $optionsTotal) * $item->quantity),
        ];
    }

    private function serializeStoreOrderItem($item): array
    {
        $variant = $item->productVariant;
        $product = $variant?->product;

        return [
            'id' => $item->id,
            'product_variant_id' => $item->product_variant_id,
            'name_ar' => $product?->name_ar,
            'name_en' => $product?->name_en,
            'attributes' => $variant?->attributes,
            'quantity' => (int) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'notes' => $item->notes,
            'line_total' => (float) ($item->unit_price * $item->quantity),
        ];
    }
}