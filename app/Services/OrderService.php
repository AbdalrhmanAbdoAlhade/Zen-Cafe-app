<?php

namespace App\Services;

use App\Events\OrderCreated;
use App\Exceptions\OrderItemUnavailableException;
use App\Models\Customer;
use App\Models\MenuOptionValue;
use App\Models\Order;
use App\Models\QrCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OrderService
{
    /**
     * إنشاء طلب جديد من بيانات السلة (Cart) اللي بعتها الزبون.
     *
     * @return array{order: Order, customer_credentials: array{phone: string, password: string}|null}
     */
    public function createOrder(QrCode $qrCode, array $cartPayload): array
    {
        return DB::transaction(function () use ($qrCode, $cartPayload) {
            $branch = $qrCode->branch;

            [$customer, $plainPassword] = $this->findOrCreateCustomer(
                $cartPayload['customer']['phone'] ?? null,
                $cartPayload['customer']['name'] ?? null,
                $cartPayload['customer']['email'] ?? null,
            );

            $order = Order::create([
                'branch_id' => $branch->id,
                'table_id' => $qrCode->table_id,
                'qr_code_id' => $qrCode->id,
                'customer_id' => $customer?->id,
                'status' => Order::STATUS_PENDING,
                'total_amount' => 0,
                'notes' => $cartPayload['notes'] ?? null,
            ]);

            $totalAmount = 0;

            foreach ($cartPayload['items'] as $cartItem) {
                $totalAmount += $this->addOrderItem($order, $branch, $cartItem);
            }

            $order->update(['total_amount' => $totalAmount]);

            app(LoyaltyService::class)->redeemPoints(
                $order,
                $customer,
                (int) ($cartPayload['redeemed_points'] ?? 0),
            );

            event(new OrderCreated($order));

            return [
                'order' => $order->fresh(['items.options', 'branch', 'table']),
                'customer_credentials' => $plainPassword
                    ? ['phone' => $customer->phone, 'password' => $plainPassword]
                    : null,
            ];
        });
    }

    /**
     * إضافة صنف واحد للطلب مع خياراته، والتحقق من التوفر والسعر من الداتابيز
     * مباشرة (مش من أي سعر جاي من الفرونت) - يرجع إجمالي سعر السطر ده.
     */
    private function addOrderItem(Order $order, $branch, array $cartItem): float
    {
        $menuItem = $branch->menuItems()
            ->wherePivot('is_available', true)
            ->where('menu_items.is_available', true)
            ->where('menu_items.id', $cartItem['menu_item_id'])
            ->first();

        if (! $menuItem) {
            throw new OrderItemUnavailableException($cartItem['menu_item_id']);
        }

        $quantity = max(1, (int) ($cartItem['quantity'] ?? 1));
        $unitPrice = (float) ($menuItem->pivot->price_override ?? $menuItem->base_price);

        $orderItem = $order->items()->create([
            'menu_item_id' => $menuItem->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'notes' => $cartItem['notes'] ?? null,
        ]);

        $optionsTotal = 0;

        foreach ($cartItem['option_value_ids'] ?? [] as $optionValueId) {
            $optionValue = MenuOptionValue::whereHas('option', function ($q) use ($menuItem) {
                $q->where('menu_item_id', $menuItem->id);
            })->find($optionValueId);

            if (! $optionValue) {
                continue; // تجاهل قيمة خيار غير تابعة لنفس الصنف
            }

            $orderItem->options()->create([
                'menu_option_value_id' => $optionValue->id,
                'extra_price' => $optionValue->extra_price,
            ]);

            $optionsTotal += (float) $optionValue->extra_price;
        }

        return ($unitPrice + $optionsTotal) * $quantity;
    }

    /**
     * إيجاد عميل موجود بنفس رقم الهاتف، أو إنشاء حساب جديد بباسورد عشوائي.
     *
     * @return array{0: Customer|null, 1: string|null} [العميل, الباسورد الصريح لو حساب جديد]
     */
    private function findOrCreateCustomer(?string $phone, ?string $name, ?string $email): array
    {
        if (! $phone) {
            return [null, null]; // مفيش رقم = طلب Guest من غير حساب
        }

        $existing = Customer::where('phone', $phone)->first();

        if ($existing) {
            return [$existing, null];
        }

        $plainPassword = Str::password(8, symbols: false);

        $customer = Customer::create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make($plainPassword),
        ]);

        return [$customer, $plainPassword];
    }
}
