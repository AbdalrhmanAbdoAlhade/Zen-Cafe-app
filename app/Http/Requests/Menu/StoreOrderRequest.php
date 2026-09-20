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
        $rules = [
            'customer' => ['sometimes', 'array'],
            'customer.name' => ['nullable', 'string', 'max:150'],
            'customer.email' => ['nullable', 'email', 'max:150'],
            'customer.phone' => ['nullable', 'string', 'max:30'],

            'notes' => ['nullable', 'string', 'max:500'],
            'redeemed_points' => ['nullable', 'integer', 'min:0'],
            'received_at' => ['nullable', 'date', 'after_or_equal:now'],
            'coupon_code' => ['nullable', 'string', 'max:50'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.option_value_ids' => ['sometimes', 'array'],
            'items.*.option_value_ids.*' => ['integer', 'exists:menu_option_values,id'],
        ];

        // طلب المنيو الأونلاين فقط (مش QR)
        if ($this->isOnlineMenuOrder()) {
            $rules['fulfillment_type'] = ['required', 'in:pickup,dine_in'];
            $rules['customer.phone'] = ['required', 'string', 'max:30'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'fulfillment_type.required' => 'يجب اختيار طريقة الاستلام: أخذ من الفرع أو شرب في الفرع.',
            'fulfillment_type.in' => 'قيمة fulfillment_type يجب أن تكون pickup أو dine_in.',
            'customer.phone.required' => 'رقم الهاتف مطلوب للطلب الخارجي.',
        ];
    }

    /**
     * OnlineMenuController::store → /online-menu/{branch}/orders أو مسار مشابه
     * MenuOrderController::store → /menu/{token}/orders (QR)
     */
private function isOnlineMenuOrder(): bool
{
    return $this->route('branchId') !== null;
}
}