<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $couponId = $this->route('coupon')?->id;

        return [
            'code'                     => [
                'required', 'string', 'max:50',
                Rule::unique('coupons', 'code')->ignore($couponId),
            ],
            'name_ar'                  => ['required', 'string', 'max:255'],
            'name_en'                  => ['nullable', 'string', 'max:255'],
            'description_ar'           => ['nullable', 'string'],
            'description_en'           => ['nullable', 'string'],
            'type'                     => ['required', Rule::in(['percentage', 'fixed', 'buy_x_get_y', 'free_shipping'])],
            'discount_value'           => ['nullable', 'numeric', 'min:0'],
            'max_discount_amount'      => ['nullable', 'numeric', 'min:0'],
            'buy_quantity'             => ['nullable', 'integer', 'min:1'],
            'get_quantity'             => ['nullable', 'integer', 'min:1'],
            'get_discount_type'        => ['nullable', Rule::in(['free', 'percentage', 'fixed'])],
            'get_discount_value'       => ['nullable', 'numeric', 'min:0'],
            'min_order_amount'         => ['nullable', 'numeric', 'min:0'],
            'first_order_only'         => ['boolean'],
            'is_stackable'             => ['boolean'],
            'exclude_discounted_items' => ['boolean'],
            'starts_at'                => ['nullable', 'date'],
            'ends_at'                  => ['nullable', 'date', 'after_or_equal:starts_at'],
            'active_hours'             => ['nullable', 'array'],
            'active_hours.*.day'       => ['required_with:active_hours', 'integer', 'between:0,6'],
            'active_hours.*.from'      => ['required_with:active_hours', 'date_format:H:i'],
            'active_hours.*.to'        => ['required_with:active_hours', 'date_format:H:i'],
            'usage_limit'              => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:1'],
            'channels'                 => ['nullable', 'array'],
            'channels.*'               => [Rule::in(['qr_menu', 'online_menu', 'store'])],
            'is_active'                => ['boolean'],
            'branch_ids'               => ['nullable', 'array'],
            'branch_ids.*'             => ['integer', 'exists:branches,id'],
            'product_ids'              => ['nullable', 'array'],
            'product_ids.*'            => ['integer', 'exists:products,id'],
            'menu_item_ids'            => ['nullable', 'array'],
            'menu_item_ids.*'          => ['integer', 'exists:menu_items,id'],
            'product_category_ids'     => ['nullable', 'array'],
            'product_category_ids.*'   => ['integer', 'exists:product_categories,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $type = $this->input('type');

            if (in_array($type, ['percentage', 'fixed']) && empty($this->input('discount_value'))) {
                $validator->errors()->add('discount_value', 'قيمة الخصم مطلوبة لهذا النوع.');
            }

            if ($type === 'percentage' && $this->input('discount_value') > 100) {
                $validator->errors()->add('discount_value', 'النسبة لا يمكن أن تتجاوز 100%.');
            }

            if ($type === 'buy_x_get_y') {
                if (empty($this->input('buy_quantity')) || empty($this->input('get_quantity'))) {
                    $validator->errors()->add('buy_quantity', 'يجب تحديد كمية الشراء والحصول لعرض اشتري واحصل.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'code.required'    => 'كود الكوبون مطلوب.',
            'code.unique'      => 'كود الكوبون مستخدم بالفعل.',
            'type.required'    => 'نوع الكوبون مطلوب.',
            'name_ar.required' => 'الاسم بالعربية مطلوب.',
        ];
    }
}