<?php

namespace App\Http\Requests\Customer;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class CustomerOrdersFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * بنحوّل status لو جه كـ string مفصول بفواصل (status=pending,paid)
     * إلى array عشان الـ validation والـ query يشتغلوا بنفس الشكل.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('status') && is_string($this->input('status'))) {
            $this->merge([
                'status' => array_values(array_filter(
                    array_map('trim', explode(',', $this->input('status')))
                )),
            ]);
        }

        if ($this->filled('order_type') && is_string($this->input('order_type'))) {
            $this->merge([
                'order_type' => array_values(array_filter(
                    array_map('trim', explode(',', $this->input('order_type')))
                )),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'array'],
            'status.*' => ['string', 'in:' . implode(',', self::allowedStatuses())],

            'order_type' => ['nullable', 'array'],
            'order_type.*' => ['string', 'in:in_branch,pre_order,store'],

            'shipping_status' => ['nullable', 'string', 'in:pending,shipped,delivered'],

            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],

            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],

            'sort' => ['nullable', 'string', 'in:latest,oldest,highest,lowest'],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.*.in' => 'حالة الطلب المطلوبة غير معروفة.',
            'order_type.*.in' => 'نوع الطلب المطلوب غير معروف.',
            'to.after_or_equal' => 'تاريخ النهاية لازم يكون بعد تاريخ البداية.',
        ];
    }

    /**
     * كل الحالات المسموحة — مأخوذة من ثوابت الـ Order model
     * عشان لو اتضافت حالة جديدة مفيش حتة تانية محتاجة تتعدل.
     */
    public static function allowedStatuses(): array
    {
        return [
            Order::STATUS_PENDING,
            Order::STATUS_ACCEPTED,
            Order::STATUS_REJECTED,
            Order::STATUS_PREPARING,
            Order::STATUS_READY,
            Order::STATUS_SERVED,
            Order::STATUS_PAID,
            Order::STATUS_CANCELLED,
            Order::STATUS_REFUNDED,
        ];
    }
}
