<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // 1) الموظف
        $staff = $request->user('staff');

        if ($staff) {
            if (! in_array($staff->role, $roles, true)) {
                return response()->json([
                    'message' => 'غير مصرح لك بالوصول لهذا المسار.',
                    'required_roles' => $roles,
                    'your_role' => $staff->role,
                ], 403);
            }

            if (! $staff->is_active) {
                return response()->json([
                    'message' => 'حسابك موقوف حاليًا.',
                ], 403);
            }

            $request->attributes->set('actor_type', 'staff');
            $request->attributes->set('actor', $staff);

            return $next($request);
        }

        // 2) الأدمن
        $admin = $request->user('sanctum');

        if ($admin instanceof User) {
            if (! in_array($admin->role, ['super_admin', 'admin'], true)) {
                return response()->json([
                    'message' => 'غير مصرح لك بالوصول لهذا المسار.',
                    'your_role' => $admin->role,
                ], 403);
            }

            $request->attributes->set('actor_type', 'admin');
            $request->attributes->set('actor', $admin);

            return $next($request);
        }

        // 3) مفيش توكن
        return response()->json([
            'message' => 'غير مصرح لك بالوصول لهذا المسار.',
        ], 401);
    }
}