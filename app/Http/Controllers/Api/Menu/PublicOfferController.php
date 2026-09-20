<?php

namespace App\Http\Controllers\Api\Menu;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicOfferController extends Controller
{
    /**
     * GET /api/offers?branch_id=1
     * العروض النشطة (داخل فترتها) مرتبة، مع صورها.
     * - مع branch_id: عروض الفرع + العروض العامة
     * - من غير branch_id: العروض العامة بس (branch_id = null)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['branch_id' => ['nullable', 'integer']]);

        $offers = Offer::active()
            ->forBranch($request->filled('branch_id') ? $request->integer('branch_id') : null)
            ->with('images')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $offers->map(fn (Offer $offer) => $this->format($offer))->values(),
        ]);
    }

    /**
     * GET /api/offers/{id}
     */
    public function show(int $id): JsonResponse
    {
        $offer = Offer::active()->with('images')->findOrFail($id);

        return response()->json(['data' => $this->format($offer)]);
    }

    private function format(Offer $offer): array
    {
        return [
            'id'             => $offer->id,
            'name_ar'        => $offer->name_ar,
            'name_en'        => $offer->name_en,
            'description_ar' => $offer->description_ar,
            'description_en' => $offer->description_en,
            'branch_id'      => $offer->branch_id,
            'images'         => $offer->images->map(fn ($image) => [
                'id'         => $image->id,
                'url'        => $image->url,
                'sort_order' => $image->sort_order,
            ])->values(),
            'starts_at'      => $offer->starts_at?->toIso8601String(),
            'ends_at'        => $offer->ends_at?->toIso8601String(),
        ];
    }
}
