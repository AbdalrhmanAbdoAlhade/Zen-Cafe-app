<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
    public function index()
    {
        return response()->json(['data' => ProductCategory::orderBy('sort_order')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['slug'] = Str::slug($data['name_en']);
        $data['is_active'] = $data['is_active'] ?? true;

        $category = ProductCategory::create($data);

        return response()->json(['message' => 'تم إنشاء التصنيف', 'data' => $category], 201);
    }

    public function update(Request $request, ProductCategory $category)
    {
        $data = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category->update($data);

        return response()->json(['message' => 'تم تحديث التصنيف', 'data' => $category->fresh()]);
    }

    public function destroy(ProductCategory $category)
    {
        if ($category->products()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف تصنيف مرتبط بمنتجات.'], 422);
        }

        $category->delete();

        return response()->json(['message' => 'تم حذف التصنيف']);
    }
}