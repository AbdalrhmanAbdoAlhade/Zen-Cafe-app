<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
          'cart_type' => ['nullable', 'in:menu,store'],
            'period' => ['nullable', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'from' => ['nullable', 'date', 'required_if:period,custom'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_if:period,custom'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'order_type' => ['nullable', 'string', 'max:50'],
            'group_by' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'gateway' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function filters(): array
    {
        return [
          'cart_type' => $this->input('cart_type'),
            'period' => $this->input('period', 'daily'),
            'from' => $this->input('from'),
            'to' => $this->input('to'),
            'branch_id' => $this->input('branch_id'),
            'order_type' => $this->input('order_type'),
            'group_by' => $this->input('group_by'),
            'gateway' => $this->input('gateway'),
        ];
    }

    public function messages(): array
    {
        return [
            'from.required_if' => 'from مطلوب عند period=custom',
            'to.required_if' => 'to مطلوب عند period=custom',
            'to.after_or_equal' => 'to يجب أن يكون بعد أو يساوي from',
            'period.in' => 'period يجب أن يكون: daily, weekly, monthly, أو custom',
        ];
    }
}