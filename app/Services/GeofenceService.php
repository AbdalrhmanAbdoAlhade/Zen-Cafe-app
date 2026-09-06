<?php

namespace App\Services;

class GeofenceService
{
    /**
     * نصف قطر الأرض بالمتر - مستخدم في معادلة Haversine.
     */
    private const EARTH_RADIUS_METERS = 6371000;

    /**
     * حساب المسافة بين نقطتين جغرافيتين بالمتر (معادلة Haversine).
     */
    public function distanceInMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * هل الموقع المُعطى واقع ضمن نطاق الفرع المسموح به؟
     */
    public function isWithinRange(
        float $branchLat,
        float $branchLng,
        float $userLat,
        float $userLng,
        int $radiusMeters,
    ): bool {
        return $this->distanceInMeters($branchLat, $branchLng, $userLat, $userLng) <= $radiusMeters;
    }
}
