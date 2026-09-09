<?php

namespace App\Services;

use App\Events\OrderCreated;
use App\Exceptions\OrderItemUnavailableException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MenuOptionValue;
use App\Models\Order;
use App\Models\QrCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function createOrder(QrCode $qrCode, array $payload): array
    {
        return $this->create($qrCode->branch, $payload, $qrCode, false);
    }

    public function createOnlineOrder(Branch $branch, array $payload): array
    {
        return $this->create($branch, $payload, null, true);
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

            $order = Order::create([
                'branch_id' => $branch->id,
                'table_id' => $qrCode?->table_id,
                'qr_code_id' => $qrCode?->id,
                'customer_id' => $customer?->id,
                'order_type' => $online ? 'pre_order' : 'in_branch',
                'status' => Order::STATUS_PENDING,
                'total_amount' => 0,
                'estimated_preparation_minutes' => 0,
                'notes' => $payload['notes'] ?? null,
            ]);

            $total = 0;
            $maxPrep = 0;

            foreach ($payload['items'] as $item) {
                [$line, $prep] = $this->addOrderItem($order, $branch, $item, $online);
                $total += $line;
                $maxPrep = max($maxPrep, $prep);
            }

            $order->update([
                'total_amount' => $total,
                'estimated_preparation_minutes' => $maxPrep,
                'estimated_ready_at' => now()->addMinutes($maxPrep),
            ]);

            app(LoyaltyService::class)->redeemPoints(
                $order,
                $customer,
                (int)($payload['redeemed_points'] ?? 0)
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

        if (!$item) {
            throw new OrderItemUnavailableException($cartItem['menu_item_id']);
        }

        $quantity = max(1, (int)($cartItem['quantity'] ?? 1));
        $unitPrice = (float)($item->pivot->price_override ?? $item->base_price);
        $prep = (int)$item->preparation_time_minutes;

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

            if (!$value) {
                continue;
            }

            $orderItem->options()->create([
                'menu_option_value_id' => $value->id,
                'extra_price' => $value->extra_price,
            ]);

            $optionsTotal += (float)$value->extra_price;
        }

        return [(($unitPrice + $optionsTotal) * $quantity), $prep];
    }

    private function findOrCreateCustomer(?string $phone, ?string $name, ?string $email, bool $online): array
    {
        if (!$phone) {
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
        return [
            'id' => $order->id,
            'order_type' => $order->order_type,
            'status' => $order->status,
            'total_amount' => (float)$order->total_amount,
            'estimated_preparation_minutes' => (int)$order->estimated_preparation_minutes,
            'estimated_ready_at' => $order->estimated_ready_at,
            'created_at' => $order->created_at,
            'redeemed_points' => (int)$order->redeemed_points,
            'redeemed_amount' => (float)$order->redeemed_amount,
            'payable_amount' => $order->payableAmount(),
            'earned_points' => (int)$order->earned_points,
            'items' => $order->items->map(fn ($item) => $this->serializeOrderItem($item))->values(),
        ];
    }

    private function serializeOrderItem($item): array
    {
        $optionsTotal = $item->options->sum('extra_price');

        return [
            'id' => $item->id,
            'menu_item_id' => $item->menu_item_id,
            'name_ar' => $item->menuItem?->name_ar,
            'name_en' => $item->menuItem?->name_en,
            'image' => $item->menuItem?->image,
            'quantity' => (int)$item->quantity,
            'unit_price' => (float)$item->unit_price,
            'vat' => (float)$item->vat,
            'preparation_time_minutes' => (int)$item->preparation_time_minutes,
            'notes' => $item->notes,
            'options' => $item->options->map(fn ($option) => [
                'id' => $option->id,
                'menu_option_value_id' => $option->menu_option_value_id,
                'name_ar' => $option->menuOptionValue?->name_ar,
                'name_en' => $option->menuOptionValue?->name_en,
                'extra_price' => (float)$option->extra_price,
            ])->values(),
            'line_total' => (float)(($item->unit_price + $optionsTotal) * $item->quantity),
        ];
    }
}