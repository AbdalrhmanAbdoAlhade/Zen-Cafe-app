<?php

namespace App\Services;

use App\Exceptions\MixedCartException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CartService
{
    public function __construct(
        private readonly CartValidationService $cartValidation
    ) {}

    /**
     * تحويل السلة لـ converted وتفضيها (العناصر + العدّاد + الإجمالي).
     */
    public function markConvertedAndClear(Cart $cart, Order $order): Cart
    {
        return DB::transaction(function () use ($cart, $order) {
            if ($cart->status === Cart::STATUS_ABANDONED) {
                $cart->markRecovered();
            }

            $cart->markConverted($order);
            $cart->items()->delete();

            $cart->unsetRelation('items');
            $cart->recalculateTotals();

            return $cart->fresh(['items']);
        });
    }

    /**
     * إيجاد أو إنشاء سلة للعميل/الضيف.
     */
    public function resolveCart(array $payload): Cart
    {
        $customerId = $payload['customer_id'] ?? null;
        $sessionToken = $payload['session_token'] ?? null;
        $branchId = $payload['branch_id'] ?? null;
        $cartType = $payload['cart_type'] ?? Cart::TYPE_MENU;

        if (! in_array($cartType, [Cart::TYPE_MENU, Cart::TYPE_STORE], true)) {
            throw new InvalidArgumentException('cart_type يجب أن يكون menu أو store');
        }

        $query = Cart::query()
            ->where('status', Cart::STATUS_ACTIVE)
            ->where('cart_type', $cartType);

        if ($customerId) {
            $query->where('customer_id', $customerId);
        } elseif ($sessionToken) {
            $query->where('session_token', $sessionToken)->whereNull('customer_id');
        } else {
            $sessionToken = Str::random(40);
        }

        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        $cart = $query->latest('id')->first();

        if (! $cart) {
            $cart = Cart::create([
                'customer_id' => $customerId,
                'branch_id' => $branchId,
                'session_token' => $customerId ? null : ($sessionToken ?? Str::random(40)),
                'cart_type' => $cartType,
                'status' => Cart::STATUS_ACTIVE,
                'abandon_stage' => Cart::STAGE_BROWSING,
                'last_activity_at' => now(),
                'estimated_total' => 0,
                'items_count' => 0,
                'meta' => $payload['meta'] ?? null,
            ]);
        } else {
            $updates = ['last_activity_at' => now()];

            if ($branchId && ! $cart->branch_id) {
                $updates['branch_id'] = $branchId;
            }

            if ($customerId && ! $cart->customer_id) {
                $updates['customer_id'] = $customerId;
                $updates['session_token'] = null;
            }

            $cart->update($updates);
        }

        return $cart->load('items');
    }

    public function getCart(int $cartId, ?int $customerId = null, ?string $sessionToken = null): Cart
    {
        $cart = Cart::with(['items.menuItem', 'items.productVariant.product', 'branch'])
            ->findOrFail($cartId);

        $this->assertOwnership($cart, $customerId, $sessionToken);

        return $cart;
    }

    /**
     * لو السلة converted → سلة active جديدة لنفس العميل/الجلسة.
     * لو abandoned → خطأ (لازم recover).
     */
    public function ensureWritableCart(Cart $cart): Cart
    {
        if (in_array($cart->status, [Cart::STATUS_ACTIVE, Cart::STATUS_RECOVERED], true)) {
            return $cart;
        }

        if ($cart->status === Cart::STATUS_CONVERTED) {
            return $this->resolveCart([
                'customer_id' => $cart->customer_id,
                'session_token' => $cart->session_token,
                'branch_id' => $cart->branch_id,
                'cart_type' => $cart->cart_type,
            ]);
        }

        if ($cart->status === Cart::STATUS_ABANDONED) {
            throw new InvalidArgumentException('السلة متروكة — استدعِ /recover أولاً');
        }

        throw new InvalidArgumentException('لا يمكن تعديل سلة بحالة: ' . $cart->status);
    }

    public function addItem(Cart $cart, array $itemPayload): Cart
    {
        $cart = $this->ensureWritableCart($cart);

        $menuItemId = $itemPayload['menu_item_id'] ?? null;
        $variantId = $itemPayload['product_variant_id'] ?? null;

        if (! $menuItemId && ! $variantId) {
            throw new InvalidArgumentException('menu_item_id أو product_variant_id مطلوب');
        }

        if ($menuItemId && $variantId) {
            throw new InvalidArgumentException('لا يمكن إرسال menu_item_id و product_variant_id معاً');
        }

        if ($menuItemId && $cart->cart_type !== Cart::TYPE_MENU) {
            throw new InvalidArgumentException('هذه سلة متجر — أضف product_variant_id فقط');
        }

        if ($variantId && $cart->cart_type !== Cart::TYPE_STORE) {
            throw new InvalidArgumentException('هذه سلة منيو — أضف menu_item_id فقط');
        }

        $existing = $cart->items()->get(['menu_item_id', 'product_variant_id'])->map(fn ($i) => [
            'menu_item_id' => $i->menu_item_id,
            'product_variant_id' => $i->product_variant_id,
        ])->all();

        $existing[] = [
            'menu_item_id' => $menuItemId,
            'product_variant_id' => $variantId,
        ];

        $this->cartValidation->assertNotMixed($existing);

        $quantity = max(1, (int) ($itemPayload['quantity'] ?? 1));

        if ($menuItemId) {
            $menuItem = MenuItem::query()->findOrFail($menuItemId);

            if (! $menuItem->is_available) {
                throw new InvalidArgumentException('الصنف غير متاح');
            }

            $unitPrice = (float) $menuItem->base_price;
            $options = $itemPayload['options'] ?? null;

            $existingItem = $cart->items()
                ->where('menu_item_id', $menuItemId)
                ->when(
                    $options,
                    fn ($q) => $q->whereJsonContains('options', $options),
                    fn ($q) => $q->whereNull('options')
                )
                ->first();

            if ($existingItem) {
                $existingItem->update([
                    'quantity' => $existingItem->quantity + $quantity,
                    'unit_price' => $unitPrice,
                    'notes' => $itemPayload['notes'] ?? $existingItem->notes,
                ]);
            } else {
                $cart->items()->create([
                    'menu_item_id' => $menuItemId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'options' => $options,
                    'notes' => $itemPayload['notes'] ?? null,
                ]);
            }
        } else {
            $variant = ProductVariant::query()
                ->with('product')
                ->where('is_available', true)
                ->findOrFail($variantId);

            if ($variant->stock_quantity < $quantity) {
                throw new InvalidArgumentException('الكمية غير متوفرة في المخزون');
            }

            $unitPrice = $variant->effectivePrice();

            $existingItem = $cart->items()
                ->where('product_variant_id', $variantId)
                ->first();

            if ($existingItem) {
                $newQty = $existingItem->quantity + $quantity;

                if ($variant->stock_quantity < $newQty) {
                    throw new InvalidArgumentException('الكمية غير متوفرة في المخزون');
                }

                $existingItem->update([
                    'quantity' => $newQty,
                    'unit_price' => $unitPrice,
                    'notes' => $itemPayload['notes'] ?? $existingItem->notes,
                ]);
            } else {
                $cart->items()->create([
                    'product_variant_id' => $variantId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'notes' => $itemPayload['notes'] ?? null,
                ]);
            }
        }

        $cart->recalculateTotals();

        return $cart->fresh(['items.menuItem', 'items.productVariant.product', 'branch']);
    }

    public function updateItem(Cart $cart, int $itemId, array $payload): Cart
    {
        $cart = $this->ensureWritableCart($cart);

        $item = $cart->items()->whereKey($itemId)->firstOrFail();

        if (isset($payload['quantity'])) {
            $qty = (int) $payload['quantity'];

            if ($qty <= 0) {
                $item->delete();
            } else {
                if ($item->product_variant_id) {
                    $variant = ProductVariant::find($item->product_variant_id);

                    if ($variant && $variant->stock_quantity < $qty) {
                        throw new InvalidArgumentException('الكمية غير متوفرة في المخزون');
                    }
                }

                $item->update([
                    'quantity' => $qty,
                    'notes' => $payload['notes'] ?? $item->notes,
                ]);
            }
        } elseif (array_key_exists('notes', $payload)) {
            $item->update(['notes' => $payload['notes']]);
        }

        $cart->recalculateTotals();

        return $cart->fresh(['items.menuItem', 'items.productVariant.product', 'branch']);
    }

    public function removeItem(Cart $cart, int $itemId): Cart
    {
        $cart = $this->ensureWritableCart($cart);

        $cart->items()->whereKey($itemId)->delete();
        $cart->recalculateTotals();

        return $cart->fresh(['items.menuItem', 'items.productVariant.product', 'branch']);
    }

    public function clear(Cart $cart): Cart
    {
        $cart = $this->ensureWritableCart($cart);

        $cart->items()->delete();
        $cart->recalculateTotals();
        $cart->update(['abandon_stage' => Cart::STAGE_BROWSING]);

        return $cart->fresh(['items']);
    }

    public function markCheckoutStarted(Cart $cart): Cart
    {
        $cart = $this->ensureWritableCart($cart);

        $cart->update([
            'abandon_stage' => Cart::STAGE_CHECKOUT_STARTED,
            'last_activity_at' => now(),
        ]);

        return $cart->fresh(['items.menuItem', 'items.productVariant.product']);
    }

    public function markPaymentFailed(Cart $cart): Cart
    {
        $cart->update([
            'abandon_stage' => Cart::STAGE_PAYMENT_FAILED,
            'last_activity_at' => now(),
        ]);

        return $cart->fresh(['items']);
    }

    public function markConverted(Cart $cart, Order $order): Cart
    {
        return $this->markConvertedAndClear($cart, $order);
    }

    public function recover(Cart $cart): Cart
    {
        if ($cart->status !== Cart::STATUS_ABANDONED) {
            throw new InvalidArgumentException('السلة ليست في حالة abandoned');
        }

        $cart->update([
            'status' => Cart::STATUS_ACTIVE,
            'recovered_at' => now(),
            'abandoned_at' => null,
            'last_activity_at' => now(),
        ]);

        return $cart->fresh(['items.menuItem', 'items.productVariant.product']);
    }

    public function serialize(Cart $cart): array
    {
        $cart->loadMissing([
            'items.menuItem',
            'items.productVariant.product.images',
            'branch',
        ]);

        return [
            'id' => $cart->id,
            'customer_id' => $cart->customer_id,
            'branch_id' => $cart->branch_id,
            'session_token' => $cart->session_token,
            'cart_type' => $cart->cart_type,
            'status' => $cart->status,
            'abandon_stage' => $cart->abandon_stage,
            'items_count' => (int) $cart->items_count,
            'estimated_total' => round((float) $cart->estimated_total, 2),
            'last_activity_at' => optional($cart->last_activity_at)->toDateTimeString(),
            'abandoned_at' => optional($cart->abandoned_at)->toDateTimeString(),
            'recovered_at' => optional($cart->recovered_at)->toDateTimeString(),
            'converted_order_id' => $cart->converted_order_id,
            'items' => $cart->items->map(function (CartItem $item) {
                $image = null;

                if ($item->menuItem) {
                    $image = $item->menuItem->image
                        ? url('/storage/' . ltrim($item->menuItem->image, '/'))
                        : null;
                } elseif ($item->productVariant?->product) {
                    $firstImage = $item->productVariant->product->images->first();
                    $image = $firstImage?->url;
                }

                return [
                    'id' => $item->id,
                    'menu_item_id' => $item->menu_item_id,
                    'product_variant_id' => $item->product_variant_id,
                    'name_ar' => $item->menuItem->name_ar
                        ?? $item->productVariant?->product?->name_ar,
                    'name_en' => $item->menuItem->name_en
                        ?? $item->productVariant?->product?->name_en,
                    'image' => $image,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => round((float) $item->unit_price, 2),
                    'line_total' => $item->lineTotal(),
                    'options' => $item->options,
                    'notes' => $item->notes,
                ];
            })->values()->all(),
        ];
    }

    private function assertOwnership(Cart $cart, ?int $customerId, ?string $sessionToken): void
    {
        if ($customerId && (int) $cart->customer_id === (int) $customerId) {
            return;
        }

        if ($sessionToken && $cart->session_token && hash_equals($cart->session_token, $sessionToken)) {
            return;
        }

        abort(403, 'غير مصرح بالوصول لهذه السلة');
    }
}