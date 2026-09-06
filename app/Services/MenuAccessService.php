<?php

namespace App\Services;

use App\Models\QrCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MenuAccessService
{
    private const TTL_MINUTES = 30;

    private const CACHE_PREFIX = 'menu_access_token:';

    /**
     * توليد توكين وصول مؤقت بعد نجاح التحقق (جيوفنسينج أو كود يدوي)،
     * ومربوط بالـ QrCode لمدة محدودة.
     */
    public function issueToken(QrCode $qrCode): string
    {
        $accessToken = Str::random(60);

        Cache::put(
            self::CACHE_PREFIX.$accessToken,
            $qrCode->id,
            now()->addMinutes(self::TTL_MINUTES),
        );

        return $accessToken;
    }

    /**
     * التحقق إن توكين الوصول المؤقت ده صالح ومربوط بنفس الـ QrCode المطلوب.
     */
    public function isValidFor(string $accessToken, QrCode $qrCode): bool
    {
        $boundQrCodeId = Cache::get(self::CACHE_PREFIX.$accessToken);

        return $boundQrCodeId !== null && (int) $boundQrCodeId === $qrCode->id;
    }

    public function revoke(string $accessToken): void
    {
        Cache::forget(self::CACHE_PREFIX.$accessToken);
    }
}
