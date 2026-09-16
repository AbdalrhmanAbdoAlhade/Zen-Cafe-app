<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BranchController extends Controller
{
    public function index()
    {
        $branches = Branch::withCount(['tables', 'staff', 'qrCodes'])
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['data' => $branches]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'address_ar' => ['nullable', 'string', 'max:1000'],   // ← جديد
            'address_en' => ['nullable', 'string', 'max:1000'], 
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'default_radius_meters' => ['nullable', 'integer', 'min:10'],
            'is_active' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'social_links' => ['nullable', 'array'],
            'social_links.facebook' => ['nullable', 'url', 'max:255'],
            'social_links.instagram' => ['nullable', 'url', 'max:255'],
            'social_links.twitter' => ['nullable', 'url', 'max:255'],
            'social_links.tiktok' => ['nullable', 'url', 'max:255'],
            'social_links.whatsapp' => ['nullable', 'string', 'max:50'],
            'social_links.snapchat' => ['nullable', 'url', 'max:255'],
        ]);

        $data['default_radius_meters'] = $data['default_radius_meters'] ?? 100;
        $data['is_active'] = $data['is_active'] ?? true;

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('branches', 'public');
        }

        $branch = Branch::create($data);

        // أول فرع يتعمل يبقى تلقائيًا الفرع الرئيسي لو مفيش فرع رئيسي أصلاً
        if (! Branch::main()->exists()) {
            $branch->update(['is_main' => true]);
        }

        return response()->json([
            'message' => 'تم إنشاء الفرع بنجاح',
            'data' => $branch->fresh(),
        ], 201);
    }

    public function show(Branch $branch)
    {
        $branch->loadCount(['tables', 'staff', 'qrCodes'])
            ->load(['staff:id,branch_id,name,username,role,is_active']);

        return response()->json(['data' => $branch]);
    }

    public function update(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'address_ar' => ['nullable', 'string', 'max:1000'],   // ← جديد
            'address_en' => ['nullable', 'string', 'max:1000'], 
            'lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180'],
            'default_radius_meters' => ['nullable', 'integer', 'min:10'],
            'is_active' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'social_links' => ['nullable', 'array'],
            'social_links.facebook' => ['nullable', 'url', 'max:255'],
            'social_links.instagram' => ['nullable', 'url', 'max:255'],
            'social_links.twitter' => ['nullable', 'url', 'max:255'],
            'social_links.tiktok' => ['nullable', 'url', 'max:255'],
            'social_links.whatsapp' => ['nullable', 'string', 'max:50'],
            'social_links.snapchat' => ['nullable', 'url', 'max:255'],
        ]);

        if ($request->boolean('remove_logo') && $branch->logo) {
            Storage::disk('public')->delete($branch->logo);
            $data['logo'] = null;
        } elseif ($request->hasFile('logo')) {
            if ($branch->logo) {
                Storage::disk('public')->delete($branch->logo);
            }
            $data['logo'] = $request->file('logo')->store('branches', 'public');
        }

        unset($data['remove_logo']);

        $branch->update($data);

        return response()->json([
            'message' => 'تم تحديث الفرع',
            'data' => $branch->fresh(),
        ]);
    }

    /**
     * POST /admin/branches/{branch}/set-main
     * تعيين الفرع ده كفرع رئيسي، وإلغاء الرئيسي عن أي فرع تاني تلقائيًا.
     */
    public function setMain(Branch $branch)
    {
        DB::transaction(function () use ($branch) {
            Branch::where('id', '!=', $branch->id)->update(['is_main' => false]);
            $branch->update(['is_main' => true]);
        });

        return response()->json([
            'message' => 'تم تعيين الفرع كفرع رئيسي',
            'data' => $branch->fresh(),
        ]);
    }

    public function pause(Request $request, Branch $branch)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $branch->update(['is_online_paused' => true, 'pause_reason' => $data['reason'] ?? null, 'paused_at' => now()]);
        return response()->json(['message' => 'تم إيقاف الطلبات الخارجية مؤقتًا', 'data' => $branch->fresh()]);
    }

    public function resume(Branch $branch)
    {
        $branch->update(['is_online_paused' => false, 'pause_reason' => null, 'paused_at' => null]);
        return response()->json(['message' => 'تم استئناف الطلبات الخارجية', 'data' => $branch->fresh()]);
    }

    /**
     * PUT /admin/branches/{branch}/prep-offset  (وكمان /manager/branches/{branch}/prep-offset)
     * "زمن تجهيز الطلب المسبق" - رقم بالدقايق بيدخله مدير الفرع وقت الذروة.
     * بيتضاف تلقائي فوق preparation_time_minutes بتاعة الأصناف على كل الطلبات
     * الجديدة (OrderService::create) من غير ما يأثر على الطلبات القايمة أصلاً،
     * وبيظهر للعميل في المنيو قبل ما يطلب.
     */
    public function updatePrepOffset(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'current_prep_offset_minutes' => ['required', 'integer', 'min:0', 'max:180'],
        ]);

        $branch->update([
            'current_prep_offset_minutes' => $data['current_prep_offset_minutes'],
            'prep_offset_updated_at' => now(),
        ]);

        return response()->json([
            'message' => $data['current_prep_offset_minutes'] > 0
                ? 'تم تحديث زمن تجهيز الطلب المسبق - هيتضاف على كل الطلبات الجديدة تلقائيًا.'
                : 'تم إلغاء زمن الذروة الإضافي - الطلبات هترجع لوقتها الطبيعي.',
            'data' => $branch->fresh(),
        ]);
    }

    public function destroy(Branch $branch)
    {
        if ($branch->tables()->exists() || $branch->staff()->exists() || $branch->orders()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف الفرع لوجود طاولات أو موظفين أو طلبات مرتبطة به. عطّله بدلاً من حذفه.',
            ], 422);
        }

        if ($branch->is_main) {
            return response()->json([
                'message' => 'لا يمكن حذف الفرع الرئيسي. عيّن فرعًا رئيسيًا آخر أولاً.',
            ], 422);
        }

        if ($branch->logo) {
            Storage::disk('public')->delete($branch->logo);
        }

        $branch->delete();

        return response()->json(['message' => 'تم حذف الفرع']);
    }
}