<?php

namespace App\Services;

use App\Exceptions\QrCodeNotAccessibleException;
use App\Models\Branch;
use App\Models\QrCode;
use App\Models\TableModel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class QrTokenService
{
    /**
     * إنشاء رمز QR جديد لفرع، أو لطاولة داخل الفرع لو اتحدد.
     */
    public function generate(
        Branch $branch,
        ?TableModel $table = null,
        ?int $radiusOverride = null,
        ?string $manualCode = null,
    ): QrCode {
        $qrCode = QrCode::create([
            'branch_id' => $branch->id,
            'table_id' => $table?->id,
            'token' => $this->generateUniqueToken(),
            'type' => $table ? 'table' : 'branch',
            'radius_override' => $radiusOverride,
            'manual_access_code' => $manualCode ? Hash::make($manualCode) : null,
            'is_active' => true,
        ]);

        if ($table) {
            $table->update(['qr_code_id' => $qrCode->id]);
        }

        return $qrCode;
    }

    /**
     * تدوير التوكين (لحالات الاشتباه في تسريب الرابط) - ده بيلغي الـ QR المطبوع
     * القديم فعليًا، فمحتاج طباعة QR جديد بعدها.
     */
    public function rotate(QrCode $qrCode): QrCode
    {
        $qrCode->update([
            'token' => $this->generateUniqueToken(),
        ]);

        return $qrCode->fresh();
    }

    /**
     * إيجاد الـ QR الفعّال من التوكين، أو رمي Exception لو مش صالح.
     */
    public function resolve(string $token): QrCode
    {
        $qrCode = QrCode::active()->where('token', $token)->first();

        if (! $qrCode) {
            throw new QrCodeNotAccessibleException;
        }

        return $qrCode;
    }

    /**
     * التحقق من الكود اليدوي (الـ Fallback لو المستخدم رفض مشاركة الموقع).
     */
    public function verifyManualCode(QrCode $qrCode, string $code): bool
    {
        if (! $qrCode->manual_access_code) {
            return false;
        }

        return Hash::check($code, $qrCode->manual_access_code);
    }

    public function regenerateManualCode(QrCode $qrCode, string $newCode): QrCode
    {
        $qrCode->update([
            'manual_access_code' => Hash::make($newCode),
        ]);

        return $qrCode->fresh();
    }

    private function generateUniqueToken(): string
    {
        do {
            $token = Str::random(48);
        } while (QrCode::where('token', $token)->exists());

        return $token;
    }
}
