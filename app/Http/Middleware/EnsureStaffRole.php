<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $staff = $request->user('staff');

        if (! $staff || ! in_array($staff->role, $roles, true)) {
            return response()->json([
                'message' => 'غير مصرح لك بالوصول لهذا المسار.',
            ], 403);
        }

        if (! $staff->is_active) {
            return response()->json([
                'message' => 'حسابك موقوف حاليًا.',
            ], 403);
        }

        return $next($request);
    }
}
