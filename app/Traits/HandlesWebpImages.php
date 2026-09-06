<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

trait HandlesWebpImages
{
    /**
     * رفع صورة → تحويل WebP + ضغط + تصغير → حذف القديمة
     */
    protected function storeAsWebp(
        UploadedFile $file,
        string $directory,
        int $quality = 80,
        ?int $maxWidth = 1200,
        ?string $oldPath = null
    ): string {
        if ($oldPath) {
            $this->deleteImage($oldPath);
        }

        if (! function_exists('imagewebp')) {
            throw new RuntimeException('PHP GD لا يدعم WebP على هذا السيرفر.');
        }

        $filename = Str::uuid()->toString() . '.webp';
        $path     = trim($directory, '/') . '/' . $filename;
        $fullPath = Storage::disk('public')->path($path);

        // تأكد إن الفولدر موجود
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $sourcePath = $file->getRealPath();
        $mime       = $file->getMimeType();

        // إنشاء resource من نوع الصورة
        $src = match ($mime) {
            'image/jpeg', 'image/jpg' => imagecreatefromjpeg($sourcePath),
            'image/png'              => imagecreatefrompng($sourcePath),
            'image/gif'              => imagecreatefromgif($sourcePath),
            'image/webp'             => imagecreatefromwebp($sourcePath),
            default                  => throw new RuntimeException("نوع الصورة غير مدعوم: {$mime}"),
        };

        if ($src === false) {
            throw new RuntimeException('فشل قراءة الصورة المرفوعة.');
        }

        $origW = imagesx($src);
        $origH = imagesy($src);

        // تصغير العرض مع الحفاظ على النسبة
        if ($maxWidth && $origW > $maxWidth) {
            $newW = $maxWidth;
            $newH = (int) round($origH * ($maxWidth / $origW));
        } else {
            $newW = $origW;
            $newH = $origH;
        }

        $dst = imagecreatetruecolor($newW, $newH);

        // دعم الشفافية (PNG / WebP / GIF)
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        // حفظ كـ WebP
        $success = imagewebp($dst, $fullPath, $quality);

        imagedestroy($src);
        imagedestroy($dst);

        if (! $success) {
            throw new RuntimeException('فشل حفظ الصورة بصيغة WebP.');
        }

        return $path;
    }

    protected function deleteImage(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}