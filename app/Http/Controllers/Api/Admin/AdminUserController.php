<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    /**
     * عرض قائمة جميع المستخدمين (الأدمنز)
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::select('id', 'name', 'email', 'role', 'created_at')
                          ->orderByDesc('id')
                          ->get()
        ]);
    }

    /**
     * إنشاء مستخدم أدمن جديد
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role'     => ['required', Rule::in(['super_admin', 'admin', 'viewer'])],
        ]);

        $data['password'] = Hash::make($data['password']);
        $data['email_verified_at'] = now();

        $user = User::create($data);

        return response()->json([
            'message' => 'تم إنشاء المستخدم بنجاح',
            'data'    => $user->only(['id', 'name', 'email', 'role']),
        ], 201);
    }

    /**
     * تحديث بيانات مستخدم أدمن
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['sometimes', 'string', 'max:255'],
            'email'    => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => ['sometimes', 'string', 'min:8'],
            'role'     => ['sometimes', Rule::in(['super_admin', 'admin', 'viewer'])],
        ]);

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        return response()->json([
            'message' => 'تم تحديث بيانات المستخدم',
            'data'    => $user->fresh()->only(['id', 'name', 'email', 'role']),
        ]);
    }

    /**
     * حذف مستخدم أدمن
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        // منع المستخدم من حذف نفسه
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'لا يمكنك حذف حسابك الخاص.'], 422);
        }

        // منع حذف آخر Super Admin في النظام
        if ($user->isSuperAdmin() && User::where('role', 'super_admin')->count() <= 1) {
            return response()->json(['message' => 'لا يمكن حذف آخر حساب Super Admin.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'تم حذف المستخدم بنجاح']);
    }
}