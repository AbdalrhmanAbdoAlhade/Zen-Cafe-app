<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffController extends Controller
{
    /**
     * GET /api/admin/branches/{branch}/staff
     * قائمة موظفين فرع معيّن.
     */
    public function index(Branch $branch)
    {
        $staff = $branch->staff()
            ->orderBy('id', 'desc')
            ->get(['id', 'branch_id', 'name', 'phone', 'username', 'role', 'is_active', 'created_at']);

        return response()->json(['data' => $staff]);
    }

    /**
     * POST /api/admin/branches/{branch}/staff
     * إنشاء موظف جديد وربطه بالفرع ده مباشرة.
     */
    public function store(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'username' => ['required', 'string', 'max:255', 'unique:staff,username'],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', Rule::in(['cashier', 'kitchen', 'manager'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $staff = $branch->staff()->create([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'username' => $data['username'],
            'password' => $data['password'], // بيتشفّر تلقائيًا (cast 'hashed' في الموديل)
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'تم إنشاء الموظف بنجاح',
            'data' => $staff->makeHidden('password'),
        ], 201);
    }

    /**
     * GET /api/admin/staff/{staff}
     */
    public function show(Staff $staff)
    {
        $staff->load('branch:id,name_ar,name_en');

        return response()->json(['data' => $staff]);
    }

    /**
     * PUT/PATCH /api/admin/staff/{staff}
     * تعديل بيانات الموظف - بما فيها نقله لفرع تاني لو حبيت.
     */
    public function update(Request $request, Staff $staff)
    {
        $data = $request->validate([
            'branch_id' => ['sometimes', 'exists:branches,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'username' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('staff', 'username')->ignore($staff->id),
            ],
            'password' => ['nullable', 'string', Password::min(8)],
            'role' => ['sometimes', Rule::in(['cashier', 'kitchen', 'manager'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // لو الباسورد جاي فاضي أو مش موجود، متحدّثوش خالص
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $staff->update($data);

        return response()->json([
            'message' => 'تم تحديث بيانات الموظف',
            'data' => $staff->fresh()->makeHidden('password'),
        ]);
    }

    /**
     * DELETE /api/admin/staff/{staff}
     */
    public function destroy(Staff $staff)
    {
        // لو الموظف ليه سجل حركات (تحصيل دفع أو تغيير حالة أوردر)، نعطّله
        // بدل ما نحذفه فعليًا - عشان السجل التاريخي يفضل سليم.
        if ($staff->payments()->exists() || $staff->statusLogs()->exists()) {
            $staff->update(['is_active' => false]);

            return response()->json([
                'message' => 'الموظف له سجل عمليات سابق، تم تعطيله بدلاً من حذفه.',
            ]);
        }

        $staff->delete();

        return response()->json(['message' => 'تم حذف الموظف']);
    }
}