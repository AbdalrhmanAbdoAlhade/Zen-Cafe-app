<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductBrowseController extends Controller
{
    /**
     * GET /api/store/categories
     */
    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => ProductCategory::active()->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * GET /api/store/products?category_id=&min_price=&max_price=&sort=&search=&page=
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->available()
            ->with(['category', 'images', 'variants' => fn ($q) => $q->where('is_available', true)]);

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->query('category_id'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(fn ($q) => $q
                ->where('name_ar', 'like', "%{$search}%")
                ->orWhere('name_en', 'like', "%{$search}%"));
        }

        if ($request->filled('min_price')) {
            $query->where('base_price', '>=', (float) $request->query('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('base_price', '<=', (float) $request->query('max_price'));
        }

        match ($request->query('sort')) {
            'price_asc' => $query->orderBy('base_price', 'asc'),
            'price_desc' => $query->orderBy('base_price', 'desc'),
            'newest' => $query->orderBy('created_at', 'desc'),
            default => $query->orderBy('is_featured', 'desc')->orderBy('id'),
        };

        return response()->json($query->paginate(20));
    }

    /**
     * GET /api/store/products/{product}
     */
    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'images', 'variants', 'reviews' => fn ($q) => $q->approved()->latest()]);

        return response()->json(['data' => $product]);
    }

    /**
     * GET /api/store/products/{product}/similar
     */
    public function similar(Product $product): JsonResponse
    {
        $similar = Product::available()
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->with(['images', 'variants' => fn ($q) => $q->where('is_available', true)])
            ->limit(8)
            ->get();

        return response()->json(['data' => $similar]);
    }
}