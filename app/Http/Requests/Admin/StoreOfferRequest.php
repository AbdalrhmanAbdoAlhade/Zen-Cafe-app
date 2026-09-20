<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * بيخدم الإنشاء (POST) والتعديل (PUT/PATCH).
 * الصور بتتقبل في الإنشاء بس — التعديل على الصور من endpoints منفصلة.
 */
class StoreOfferRequest extends FormRequest
{
    public const MAX_IMAGES = 10;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');

        $rules = [
            'name_ar'        => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'name_en'        => ['nullable', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'is_active'      => ['nullable', 'boolean'],
            'starts_at'      => ['nullable', 'date'],
            'ends_at'        => ['nullable', 'date', 'after_or_equal:starts_at'],
            'sort_order'     => ['nullable', 'integer', 'min:0'],
            'branch_id'      => ['nullable', 'integer', 'exists:branches,id'],
        ];

        if ($create) {
            $rules['images']   = ['nullable', 'array', 'max:' . self::MAX_IMAGES];
            $rules['images.*'] = ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'];
        }

        return $rules;
    }
}
