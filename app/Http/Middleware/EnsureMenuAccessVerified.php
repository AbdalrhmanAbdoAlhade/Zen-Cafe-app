<?php

namespace App\Http\Middleware;

use App\Services\MenuAccessService;
use App\Services\QrTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMenuAccessVerified
{
    public function __construct(
        private readonly QrTokenService $qrTokenService,
        private readonly MenuAccessService $menuAccessService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');
        $accessToken = $request->header('X-Menu-Access-Token');

        if (! $accessToken) {
            return response()->json([
                'message' => 'يجب التحقق من الموقع الجغرافي أو الكود اليدوي أولاً.',
            ], 401);
        }

        $qrCode = $this->qrTokenService->resolve($token);

        if (! $this->menuAccessService->isValidFor($accessToken, $qrCode)) {
            return response()->json([
                'message' => 'انتهت صلاحية جلسة الوصول، برجاء إعادة التحقق من الموقع.',
            ], 401);
        }

        // نحط الـ QrCode على الـ Request عشان الـ Controller يستخدمه من غير ما يعمل resolve تاني
        $request->attributes->set('resolved_qr_code', $qrCode);

        return $next($request);
    }
}
