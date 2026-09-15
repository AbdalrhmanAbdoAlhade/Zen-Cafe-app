<?php

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\Branch;
use Illuminate\Pagination\LengthAwarePaginator;

class MenuBuilderService
{
  
/**
 * جلب الأقسام المتاحة في فرع معين (التي تحتوي على عناصر قائمة متاحة).
 *
 * @param Branch $branch
 * @param bool $onlineOnly إذا true فلن يُرجع إلا الأقسام التي تحتوي عناصر متاحة للطلب أونلاين.
 * @return array
 */
public function getCategoriesForBranch(Branch $branch, bool $onlineOnly = false): array
{
    $categoryIds = $branch->menuItems()
        ->where('menu_items.is_available', true)
        ->wherePivot('is_available', true)
        ->when($onlineOnly, fn($q) => $q->where('menu_items.is_available_online', true))
        ->pluck('menu_items.category_id')
        ->unique()
        ->values();

    $categories = MenuCategory::whereIn('id', $categoryIds)
        ->where('is_active', true)               // فقط الأقسام النشطة
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get();

    return $categories->map(fn($category) => [
        'id'         => $category->id,
        'name_ar'    => $category->name_ar,
        'name_en'    => $category->name_en,
        'sort_order' => $category->sort_order,
    ])->all();
}
  
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
    bool $onlineOnly = false,
    ?float $minPrice = null,
    ?float $maxPrice = null,
    ?string $sort = null
): array {
    $query = $branch->menuItems()
        ->where('menu_items.is_available', true)
        ->wherePivot('is_available', true)
        ->when($onlineOnly, fn ($q) => $q->where('menu_items.is_available_online', true))
        ->with(['category', 'options.values']);

    // فلتر الكاتيجوري
    if ($categoryId) {
        $query->where('menu_items.category_id', $categoryId);
    }

    // السعر الفعلي = price_override لو موجود وإلا base_price
    $effectivePrice = 'COALESCE(menu_item_branch.price_override, menu_items.base_price)';

    if ($minPrice !== null) {
        $query->whereRaw("{$effectivePrice} >= ?", [$minPrice]);
    }

    if ($maxPrice !== null) {
        $query->whereRaw("{$effectivePrice} <= ?", [$maxPrice]);
    }

    // الترتيب
    match ($sort) {
        'price_asc'  => $query->orderByRaw("{$effectivePrice} asc"),
        'price_desc' => $query->orderByRaw("{$effectivePrice} desc"),
        'newest'     => $query->orderBy('menu_items.created_at', 'desc'),
        default      => $query->orderBy('menu_items.category_id')->orderBy('menu_items.id'),
    };

    /** @var LengthAwarePaginator $paginator */
    $paginator = $query->paginate($perPage);

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