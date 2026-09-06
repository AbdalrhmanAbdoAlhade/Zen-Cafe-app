<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MenuItem;
use Illuminate\Http\Request;

class MenuItemBranchController extends Controller
{
    /** كل الفروع اللي الصنف متوفر فيها */
    public function index(MenuItem $item)
    {
        $branches = $item->branches()->get();

        return response()->json(['data' => $branches]);
    }

    /** ربط صنف بفرع (أو تحديث لو موجود) */
    public function attach(Request $request, MenuItem $item)
    {
        $data = $request->validate([
            'branch_id'      => ['required', 'exists:branches,id'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'is_available'   => ['nullable', 'boolean'],
            'is_featured'    => ['nullable', 'boolean'],
        ]);

        $item->branches()->syncWithoutDetaching([
            $data['branch_id'] => [
                'price_override' => $data['price_override'] ?? null,
                'is_available'   => $data['is_available'] ?? true,
                'is_featured'    => $data['is_featured'] ?? false,
            ],
        ]);

        $pivot = $item->branches()->where('branch_id', $data['branch_id'])->first();

        return response()->json([
            'message' => 'تم ربط الصنف بالفرع بنجاح',
            'data'    => $pivot,
        ], 201);
    }

    /** تحديث إعدادات الصنف داخل فرع معين */
    public function update(Request $request, MenuItem $item, Branch $branch)
    {
        if (! $item->branches()->where('branch_id', $branch->id)->exists()) {
            return response()->json(['message' => 'الصنف غير مربوط بهذا الفرع'], 404);
        }

        $data = $request->validate([
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'is_available'   => ['nullable', 'boolean'],
            'is_featured'    => ['nullable', 'boolean'],
        ]);

        $item->branches()->updateExistingPivot($branch->id, array_filter([
            'price_override' => $data['price_override'] ?? null,
            'is_available'   => $data['is_available'] ?? null,
            'is_featured'    => $data['is_featured'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $pivot = $item->branches()->where('branch_id', $branch->id)->first();

        return response()->json([
            'message' => 'تم تحديث إعدادات الصنف في الفرع',
            'data'    => $pivot,
        ]);
    }

    /** فك الربط */
    public function detach(MenuItem $item, Branch $branch)
    {
        $item->branches()->detach($branch->id);

        return response()->json(['message' => 'تم فك ربط الصنف من الفرع']);
    }

    /** كل الأصناف المربوطة بفرع معين */
    public function itemsByBranch(Branch $branch)
    {
        $items = $branch->menuItems()
            ->with(['category', 'options.values'])
            ->get();

        return response()->json(['data' => $items]);
    }
}