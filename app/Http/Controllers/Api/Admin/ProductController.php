<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
  use \App\Traits\HandlesWebpImages;
  
    public function index(Request $request)
    {
        $products = Product::with(['category', 'images', 'variants'])
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->query('category_id')))
            ->orderBy('id', 'desc')
            ->paginate(20);

        return response()->json($products->through(fn (Product $p) => $this->withPricing($p)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:product_categories,id'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_available' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'sku' => ['nullable', 'string', 'max:100', 'unique:products,sku'],
            'country_of_origin' => ['nullable', 'string', 'max:100'],
            'roast_date' => ['nullable', 'date'],
            'brewing_method' => ['nullable', 'string', 'max:100'],

            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:2048'],

            'variants' => ['required', 'array', 'min:1'],
            'variants.*.attributes' => ['nullable', 'array'],
            'variants.*.price_override' => ['nullable', 'numeric', 'min:0'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.stock_quantity' => ['required', 'integer', 'min:0'],
            'variants.*.low_stock_threshold' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['slug'] = Str::slug($data['name_en']) . '-' . Str::random(6);
        $data['is_available'] = $data['is_available'] ?? true;
        $data['is_featured'] = $data['is_featured'] ?? false;

      $product = DB::transaction(function () use ($data, $request) {
    $product = Product::create(collect($data)->except(['images', 'variants'])->all());

    foreach ($data['variants'] as $i => $variantData) {
        ProductVariant::create([
            'product_id' => $product->id,
            'attributes' => $variantData['attributes'] ?? null,
            'price_override' => $variantData['price_override'] ?? null,
            'sku' => $variantData['sku'] ?? null,
            'stock_quantity' => $variantData['stock_quantity'],
            'low_stock_threshold' => $variantData['low_stock_threshold'] ?? 5,
        ]);
    }

        foreach ($request->file('images', []) as $index => $image) {
            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $this->storeAsWebp($image, 'products', quality: 80, maxWidth: 1200),
                'sort_order' => $index,
            ]);
        }

        return $product;
    });

        return response()->json([
            'message' => 'تم إنشاء المنتج بنجاح',
            'data' => $this->withPricing($product->fresh(['category', 'images', 'variants'])),
        ], 201);
    }

    public function show(Product $product)
    {
        $product->load(['category', 'images', 'variants', 'reviews']);

        return response()->json(['data' => $this->withPricing($product)]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'category_id' => ['sometimes', 'exists:product_categories,id'],
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'base_price' => ['sometimes', 'numeric', 'min:0'],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_available' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'country_of_origin' => ['nullable', 'string', 'max:100'],
            'roast_date' => ['nullable', 'date'],
            'brewing_method' => ['nullable', 'string', 'max:100'],
        ]);

        $product->update($data);

        return response()->json([
            'message' => 'تم تحديث المنتج',
            'data' => $this->withPricing($product->fresh(['category', 'images', 'variants'])),
        ]);
    }

    public function destroy(Product $product)
    {
        if ($product->variants()->whereHas('stockMovements')->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف منتج له حركة مبيعات سابقة. عطّله (is_available = false) بدلاً من حذفه.',
            ], 422);
        }

        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->image_path);
        }

        $product->delete();

        return response()->json(['message' => 'تم حذف المنتج']);
    }

    /**
     * يضيف pricing للمنتج (بناءً على base_price) ولكل variant (بناءً على سعره الفعلي):
     * price (بدون ضريبة) + vat (%) + vat_amount + total_price (شامل الضريبة).
     */
    private function withPricing(Product $product): Product
    {
        $product->setAttribute('pricing', $product->priceWithVat());

        if ($product->relationLoaded('variants')) {
            foreach ($product->variants as $variant) {
                $variant->setAttribute('pricing', $product->priceWithVat(
                    $variant->price_override !== null ? (float) $variant->price_override : null
                ));
            }
        }

        return $product;
    }

    /**
     * GET /api/admin/products/low-stock
     */
    public function lowStock()
    {
        $variants = ProductVariant::with('product:id,name_ar,name_en')
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->get();

        return response()->json(['data' => $variants]);
    }

    public function addVariant(Request $request, Product $product)
    {
        $data = $request->validate([
            'attributes' => ['nullable', 'array'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'sku' => ['nullable', 'string', 'max:100'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
        ]);

        $variant = $product->variants()->create($data);

        return response()->json(['message' => 'تمت إضافة الخيار', 'data' => $variant], 201);
    }

    public function updateVariant(Request $request, ProductVariant $variant)
    {
        $data = $request->validate([
            'attributes' => ['nullable', 'array'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'is_available' => ['nullable', 'boolean'],
        ]);

        $variant->update($data);

        return response()->json(['message' => 'تم تحديث الخيار', 'data' => $variant->fresh()]);
    }

    public function restock(Request $request, ProductVariant $variant)
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1']]);

        DB::transaction(function () use ($variant, $data) {
            $variant->increment('stock_quantity', $data['quantity']);

            \App\Models\ProductStockMovement::create([
                'product_variant_id' => $variant->id,
                'type' => \App\Models\ProductStockMovement::TYPE_RESTOCK,
                'quantity' => $data['quantity'],
            ]);
        });

        return response()->json(['message' => 'تم تحديث المخزون', 'data' => $variant->fresh()]);
    }
}