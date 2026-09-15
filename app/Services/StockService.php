<?php

namespace App\Services;

use App\Events\LowStockAlert;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * خصم الكمية من المخزون فورًا وقت إنشاء أوردر المتجر (قبل الدفع).
     * لازم تتنفذ جوه نفس الـ DB transaction بتاعة إنشاء الأوردر.
     */
    public function deduct(ProductVariant $variant, int $quantity, Order $order): void
    {
        // lockForUpdate يمنع سباق بين طلبين بيحاولوا ياخدوا آخر قطعة في نفس اللحظة
        $locked = ProductVariant::whereKey($variant->id)->lockForUpdate()->first();

        if ($locked->stock_quantity < $quantity) {
            throw new InsufficientStockException($variant->id, $quantity, $locked->stock_quantity);
        }

        $locked->decrement('stock_quantity', $quantity);

        ProductStockMovement::create([
            'product_variant_id' => $variant->id,
            'type' => ProductStockMovement::TYPE_SALE,
            'quantity' => -$quantity,
            'reference_order_id' => $order->id,
        ]);

        $this->checkLowStock($locked->fresh());
    }

    /**
     * إرجاع الكمية للمخزون - يُستخدم عند إلغاء/استرداد أوردر متجر بعد ما كان اتخصم.
     */
    public function refundForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->loadMissing('items');

            foreach ($order->items as $item) {
                if (! $item->product_variant_id) {
                    continue;
                }

                $variant = ProductVariant::whereKey($item->product_variant_id)->lockForUpdate()->first();

                if (! $variant) {
                    continue;
                }

                $variant->increment('stock_quantity', $item->quantity);

                ProductStockMovement::create([
                    'product_variant_id' => $variant->id,
                    'type' => ProductStockMovement::TYPE_CANCELLATION_REFUND,
                    'quantity' => $item->quantity,
                    'reference_order_id' => $order->id,
                ]);
            }
        });
    }

    private function checkLowStock(ProductVariant $variant): void
    {
        if ($variant->isLowStock()) {
            event(new LowStockAlert($variant));
        }
    }
}