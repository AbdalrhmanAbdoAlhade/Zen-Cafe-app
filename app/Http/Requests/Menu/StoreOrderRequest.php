<?php

namespace App\Http\Requests\Menu;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer' => ['sometimes', 'array'],
            'customer.name' => ['nullable', 'string', 'max:150'],
            'customer.email' => ['nullable', 'email', 'max:150'],
            // الفون مش إجباري على مستوى الطلب، بس لو معملوش الزبون هيتسجل كـ Guest
            // من غير حساب (راجع OrderService::findOrCreateCustomer)
            'customer.phone' => ['nullable', 'string', 'max:30'],

            'notes' => ['nullable', 'string', 'max:500'],
            'redeemed_points' => ['nullable', 'integer', 'min:0'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.option_value_ids' => ['sometimes', 'array'],
            'items.*.option_value_ids.*' => ['integer', 'exists:menu_option_values,id'],
        ];
    }
}
