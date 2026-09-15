<?php

namespace App\Http\Requests\Store;

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
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:150'],
            'customer.email' => ['nullable', 'email', 'max:150'],
            'customer.phone' => ['required', 'string', 'max:30'],

            'notes' => ['nullable', 'string', 'max:500'],

            'fulfillment_type' => ['required', 'in:pickup,shipping'],

            'shipping' => ['required_if:fulfillment_type,shipping', 'array'],
            'shipping.recipient_name' => ['required_if:fulfillment_type,shipping', 'string', 'max:150'],
            'shipping.recipient_phone' => ['required_if:fulfillment_type,shipping', 'string', 'max:30'],
            'shipping.city' => ['required_if:fulfillment_type,shipping', 'string', 'max:100'],
            'shipping.address_line' => ['required_if:fulfillment_type,shipping', 'string', 'max:500'],
            'shipping.building' => ['nullable', 'string', 'max:100'],
            'shipping.landmark' => ['nullable', 'string', 'max:255'],
            'shipping.fee' => ['nullable', 'numeric', 'min:0'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'shipping.required_if' => 'بيانات الشحن مطلوبة عند اختيار الشحن كطريقة استلام.',
        ];
    }
}