<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Pagination\LengthAwarePaginator;

class MenuBuilderService
{
  public function getItemDetails(Branch $branch, int $itemId): ?array
{
    $item = $branch->menuItems()
        ->wherePivot('is_available', true)
        ->where('menu_items.id', $itemId)
        ->with(['category', 'options.values'])
        ->first();

    if (!$item) {
        return null;
    }

    return [
        'id'              => $item->id,
        'category_id'     => $item->category_id,
        'name_ar'         => $item->name_ar,
        'name_en'         => $item->name_en,
        'description_ar'  => $item->description_ar,
        'description_en'  => $item->description_en,
        'image'           => $item->image,
        'price'           => (float) ($item->pivot->price_override ?? $item->base_price),
        'is_featured'     => (bool) $item->pivot->is_featured,
        'preparation_time_minutes' => (int) $item->preparation_time_minutes,
        'is_available_online' => (bool) $item->is_available_online,
        'vat' => (float) $item->vat,
        'calories' => $item->calories,
        'allergens' => $item->allergens,
        'ingredients' => $item->ingredients,
        'category'        => [
            'id'       => $item->category->id,
            'name_ar'  => $item->category->name_ar,
            'name_en'  => $item->category->name_en,
            'sort_order' => $item->category->sort_order,
        ],
        'options' => $item->options->map(fn ($option) => [
            'id'          => $option->id,
            'name_ar'     => $option->name_ar,
            'name_en'     => $option->name_en,
            'type'        => $option->type,
            'is_required' => $option->is_required,
            'values'      => $option->values->map(fn ($value) => [
                'id'          => $value->id,
                'name_ar'     => $value->name_ar,
                'name_en'     => $value->name_en,
                'extra_price' => (float) $value->extra_price,
            ]),
        ]),
    ];
}
    /**
     * بناء المنيو لفرع معيّن مع دعم:
     * - فلتر حسب category_id
     * - Pagination (15 عنصر)
     */
    public function getMenuForBranch(
        Branch $branch,
        ?int $categoryId = null,
        int $perPage = 15,
        bool $onlineOnly = false
    ): array {
        $query = $branch->menuItems()
            ->where('menu_items.is_available', true)
            ->wherePivot('is_available', true)
            ->when($onlineOnly, fn ($q) => $q->where('menu_items.is_available_online', true))
            ->with(['category', 'options.values'])
            ->orderBy('category_id')
            ->orderBy('menu_items.id');

        // فلتر حسب الكاتيجوري
        if ($categoryId) {
            $query->where('menu_items.category_id', $categoryId);
        }

        // Pagination
        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        // بناء الـ items بنفس الشكل القديم
        $items = $paginator->getCollection()->map(function ($item) {
            return [
                'id'              => $item->id,
                'category_id'     => $item->category_id,
                'name_ar'         => $item->name_ar,
                'name_en'         => $item->name_en,
                'description_ar'  => $item->description_ar,
                'description_en'  => $item->description_en,
                'image'           => $item->image,
                'price'           => (float) ($item->pivot->price_override ?? $item->base_price),
                'is_featured'     => (bool) $item->pivot->is_featured,
                'preparation_time_minutes' => (int) $item->preparation_time_minutes,
                'is_available_online' => (bool) $item->is_available_online,
                'vat' => (float) $item->vat,
                'calories' => $item->calories,
                'allergens' => $item->allergens,
                'ingredients' => $item->ingredients,
                'category'        => [
                    'id'       => $item->category->id,
                    'name_ar'  => $item->category->name_ar,
                    'name_en'  => $item->category->name_en,
                    'sort_order' => $item->category->sort_order,
                ],
                'options' => $item->options->map(fn ($option) => [
                    'id'          => $option->id,
                    'name_ar'     => $option->name_ar,
                    'name_en'     => $option->name_en,
                    'type'        => $option->type,
                    'is_required' => $option->is_required,
                    'values'      => $option->values->map(fn ($value) => [
                        'id'          => $value->id,
                        'name_ar'     => $value->name_ar,
                        'name_en'     => $value->name_en,
                        'extra_price' => (float) $value->extra_price,
                    ]),
                ]),
            ];
        });

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last'  => $paginator->url($paginator->lastPage()),
                'prev'  => $paginator->previousPageUrl(),
                'next'  => $paginator->nextPageUrl(),
            ],
        ];
    }
}