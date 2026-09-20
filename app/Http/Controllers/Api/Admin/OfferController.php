<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AddOfferImagesRequest;
use App\Http\Requests\Admin\StoreOfferRequest;
use App\Models\Offer;
use App\Models\OfferImage;
use App\Traits\HandlesWebpImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class OfferController extends Controller
{
    use HandlesWebpImages;

    private const IMAGES_DIR = 'offers';
    private const IMAGE_MAX_WIDTH = 1600;
    private const IMAGE_QUALITY = 80;

    /**
     * GET /api/admin/offers?branch_id=1&is_active=1
     */
    public function index(Request $request): JsonResponse
    {
        $offers = Offer::with(['images', 'branch:id,name_ar,name_en'])
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->query('branch_id')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($offers);
    }

    /**
     * POST /api/admin/offers   (multipart: بيانات + images[])
     */
    public function store(StoreOfferRequest $request): JsonResponse
    {
        $data = $this->cleanData($request->validated());
        unset($data['images']);

        $files = $request->file('images', []);

        // الصور بتترفع الأول؛ لو أي حاجة فشلت بنمسح اللي اترفع عشان منسيبش ملفات يتيمة
        $paths = [];

        try {
            $paths = $this->storeImageFiles($files);

            $offer = DB::transaction(function () use ($data, $paths) {
                $offer = Offer::create($data);

                foreach ($paths as $index => $path) {
                    $offer->images()->create([
                        'image_path' => $path,
                        'sort_order' => $index,
                    ]);
                }

                return $offer;
            });
        } catch (Throwable $e) {
            foreach ($paths as $path) {
                $this->deleteImage($path);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'تم إنشاء العرض بنجاح',
            'data'    => $offer->fresh(['images', 'branch']),
        ], 201);
    }

    /**
     * GET /api/admin/offers/{offer}
     */
    public function show(Offer $offer): JsonResponse
    {
        return response()->json(['data' => $offer->load(['images', 'branch'])]);
    }

    /**
     * PUT /api/admin/offers/{offer}   (JSON — الصور من endpoints منفصلة)
     */
    public function update(StoreOfferRequest $request, Offer $offer): JsonResponse
    {
        $data = $this->cleanData($request->validated());

        // تأكد إن ترتيب التواريخ سليم حتى لو اتبعت واحد بس منهم
        $startsAt = array_key_exists('starts_at', $data) ? $data['starts_at'] : $offer->starts_at;
        $endsAt   = array_key_exists('ends_at', $data) ? $data['ends_at'] : $offer->ends_at;

        if ($startsAt && $endsAt && strtotime((string) $endsAt) < strtotime((string) $startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => ['تاريخ نهاية العرض لازم يكون بعد أو يساوي تاريخ البداية.'],
            ]);
        }

        $offer->update($data);

        return response()->json([
            'message' => 'تم تحديث العرض',
            'data'    => $offer->fresh(['images', 'branch']),
        ]);
    }

    /**
     * DELETE /api/admin/offers/{offer}
     */
    public function destroy(Offer $offer): JsonResponse
    {
        $paths = $offer->images()->pluck('image_path')->all();

        $offer->delete(); // صفوف الصور بتتمسح بالـ cascade

        foreach ($paths as $path) {
            $this->deleteImage($path);
        }

        return response()->json(['message' => 'تم حذف العرض']);
    }

    /**
     * POST /api/admin/offers/{offer}/images   (multipart: images[])
     */
    public function addImages(AddOfferImagesRequest $request, Offer $offer): JsonResponse
    {
        $files = $request->file('images', []);

        if ($offer->images()->count() + count($files) > StoreOfferRequest::MAX_IMAGES) {
            throw ValidationException::withMessages([
                'images' => ['الحد الأقصى ' . StoreOfferRequest::MAX_IMAGES . ' صور للعرض الواحد.'],
            ]);
        }

        $nextOrder = ((int) $offer->images()->max('sort_order')) + ($offer->images()->exists() ? 1 : 0);

        $paths = [];

        try {
            $paths = $this->storeImageFiles($files);

            DB::transaction(function () use ($offer, $paths, $nextOrder) {
                foreach ($paths as $index => $path) {
                    $offer->images()->create([
                        'image_path' => $path,
                        'sort_order' => $nextOrder + $index,
                    ]);
                }
            });
        } catch (Throwable $e) {
            foreach ($paths as $path) {
                $this->deleteImage($path);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'تم إضافة الصور',
            'data'    => $offer->fresh(['images', 'branch']),
        ], 201);
    }

    /**
     * DELETE /api/admin/offers/{offer}/images/{image}
     */
    public function destroyImage(Offer $offer, OfferImage $image): JsonResponse
    {
        abort_unless((int) $image->offer_id === (int) $offer->id, 404, 'الصورة دي مش تابعة للعرض ده.');

        $path = $image->image_path;

        $image->delete();
        $this->deleteImage($path);

        return response()->json(['message' => 'تم حذف الصورة']);
    }

    /* ============================================================
     |  Helpers
     ============================================================ */

    /**
     * @param  \Illuminate\Http\UploadedFile[]  $files
     * @return string[]
     */
    private function storeImageFiles(array $files): array
    {
        $paths = [];

        try {
            foreach ($files as $file) {
                $paths[] = $this->storeAsWebp(
                    $file,
                    self::IMAGES_DIR,
                    quality: self::IMAGE_QUALITY,
                    maxWidth: self::IMAGE_MAX_WIDTH,
                );
            }
        } catch (Throwable $e) {
            foreach ($paths as $path) {
                $this->deleteImage($path);
            }

            throw $e;
        }

        return $paths;
    }

    /**
     * الأعمدة اللي مش nullable في الجدول (is_active / sort_order):
     * لو اتبعتت فاضية بنشيلها بدل ما نكتب null — الـ default (أو القيمة الحالية) بيفضل.
     */
    private function cleanData(array $data): array
    {
        foreach (['is_active', 'sort_order'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
