<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuCategory;
use Illuminate\Http\Request;

class MenuCategoryController extends Controller
{
    public function index()
    {
        $categories = MenuCategory::withCount('menuItems')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name_ar'    => ['required', 'string', 'max:255'],
            'name_en'    => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_active']  = $data['is_active'] ?? true;

        $category = MenuCategory::create($data);

        return response()->json([
            'message' => 'تم إنشاء التصنيف بنجاح',
            'data'    => $category,
        ], 201);
    }

    public function show(MenuCategory $category)
    {
        $category->load(['menuItems' => fn ($q) => $q->with('options.values')]);

        return response()->json(['data' => $category]);
    }

    public function update(Request $request, MenuCategory $category)
    {
        $data = $request->validate([
            'name_ar'    => ['sometimes', 'string', 'max:255'],
            'name_en'    => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        $category->update($data);

        return response()->json([
            'message' => 'تم تحديث التصنيف',
            'data'    => $category->fresh(),
        ]);
    }

    public function destroy(MenuCategory $category)
    {
        $category->delete();

        return response()->json(['message' => 'تم حذف التصنيف']);
    }
}