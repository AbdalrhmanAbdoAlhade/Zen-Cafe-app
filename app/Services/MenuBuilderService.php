<?php

namespace App\Services;

use App\Models\Branch;

class MenuBuilderService
{
    /**
     * بناء المنيو الكامل لفرع معيّن: تصنيفات > أصناف متاحة > أسعار وعروض خاصة
     * بالفرع > خيارات كل صنف. بيرجع اتنين اللغتين مع بعض عشان الفرونت يختار.
     */
    public function getMenuForBranch(Branch $branch): array
    {
        $branchWithItems = $branch->load([
            'menuItems' => function ($query) {
                $query->where('menu_items.is_available', true) // توفر عام للصنف
                    ->wherePivot('is_available', true) // توفر خاص بالفرع
                    ->with(['category', 'options.values'])
                    ->orderBy('category_id');
            },
        ]);

        $categoriesMap = [];

        foreach ($branchWithItems->menuItems as $item) {
            $categoryId = $item->category_id;

            if (! isset($categoriesMap[$categoryId])) {
                $categoriesMap[$categoryId] = [
                    'id' => $item->category->id,
                    'name_ar' => $item->category->name_ar,
                    'name_en' => $item->category->name_en,
                    'sort_order' => $item->category->sort_order,
                    'items' => [],
                ];
            }

            $categoriesMap[$categoryId]['items'][] = [
                'id' => $item->id,
                'name_ar' => $item->name_ar,
                'name_en' => $item->name_en,
                'description_ar' => $item->description_ar,
                'description_en' => $item->description_en,
                'image' => $item->image,
                'price' => (float) ($item->pivot->price_override ?? $item->base_price),
                'is_featured' => (bool) $item->pivot->is_featured,
                'options' => $item->options->map(fn ($option) => [
                    'id' => $option->id,
                    'name_ar' => $option->name_ar,
                    'name_en' => $option->name_en,
                    'type' => $option->type,
                    'is_required' => $option->is_required,
                    'values' => $option->values->map(fn ($value) => [
                        'id' => $value->id,
                        'name_ar' => $value->name_ar,
                        'name_en' => $value->name_en,
                        'extra_price' => (float) $value->extra_price,
                    ]),
                ]),
            ];
        }

        $categories = array_values($categoriesMap);
        usort($categories, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $categories;
    }
}
