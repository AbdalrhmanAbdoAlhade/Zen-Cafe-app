<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StaffLoginRequest;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class StaffAuthController extends Controller
{
    public function login(StaffLoginRequest $request): JsonResponse
    {
        $staff = Staff::where('username', $request->input('username'))->first();
        if (! $staff || ! $staff->is_active || ! Hash::check($request->input('password'), $staff->password)) {
            throw ValidationException::withMessages([
                'username' => 'بيانات الدخول غير صحيحة.',
            ]);
        }

        $token = $staff->createToken('staff-access')->plainTextToken;

        return response()->json([
            'token' => $token,
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'role' => $staff->role,
                'branch_id' => $staff->branch_id,
            ],
        ]);
    }
}
