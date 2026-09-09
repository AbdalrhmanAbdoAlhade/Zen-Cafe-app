<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;

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
          'address' => ['nullable', 'string', 'max:1000'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'default_radius_meters' => ['nullable', 'integer', 'min:10'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['default_radius_meters'] = $data['default_radius_meters'] ?? 100;
        $data['is_active'] = $data['is_active'] ?? true;

        $branch = Branch::create($data);

        return response()->json([
            'message' => 'تم إنشاء الفرع بنجاح',
            'data' => $branch,
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
          'address' => ['sometimes', 'string', 'max:1000'],
            'lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180'],
            'default_radius_meters' => ['nullable', 'integer', 'min:10'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $branch->update($data);

        return response()->json([
            'message' => 'تم تحديث الفرع',
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

    public function destroy(Branch $branch)
    {
        // منع حذف فرع لسه عنده طاولات أو موظفين أو أوردرات مرتبطة بيه
        if ($branch->tables()->exists() || $branch->staff()->exists() || $branch->orders()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف الفرع لوجود طاولات أو موظفين أو طلبات مرتبطة به. عطّله بدلاً من حذفه.',
            ], 422);
        }

        $branch->delete();

        return response()->json(['message' => 'تم حذف الفرع']);
    }
}