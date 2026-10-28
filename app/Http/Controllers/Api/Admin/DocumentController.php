<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $query = Document::query()->ordered();

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('file_type')) {
            $query->where('file_type', $request->file_type);
        }

        $documents = $query->paginate(20);

        return response()->json($documents);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title_ar'    => ['required', 'string', 'max:255'],
            'title_en'    => ['required', 'string', 'max:255'],
            'file'        => ['required', 'file', 'max:10240'], // 10MB
            'is_active'   => ['nullable', 'boolean'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        $file = $request->file('file');
        $fileType = $this->detectFileType($file);

        if (! $fileType) {
            return response()->json([
                'message' => 'نوع الملف غير مدعوم. المسموح: PDF, Word, Excel, صور',
            ], 422);
        }

        $path = $file->store('documents', 'public');

        $document = Document::create([
            'title_ar'      => $data['title_ar'],
            'title_en'      => $data['title_en'],
            'file'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'file_type'     => $fileType,
            'mime_type'     => $file->getMimeType(),
            'size'          => $file->getSize(),
            'is_active'     => $data['is_active'] ?? true,
            'sort_order'    => $data['sort_order'] ?? 0,
        ]);

        return response()->json([
            'message' => 'تم إضافة الملف بنجاح',
            'data'    => $document,
        ], 201);
    }

    public function show(Document $document)
    {
        return response()->json(['data' => $document]);
    }

    public function update(Request $request, Document $document)
    {
        $data = $request->validate([
            'title_ar'   => ['sometimes', 'string', 'max:255'],
            'title_en'   => ['sometimes', 'string', 'max:255'],
            'file'       => ['nullable', 'file', 'max:10240'],
            'is_active'  => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileType = $this->detectFileType($file);

            if (! $fileType) {
                return response()->json([
                    'message' => 'نوع الملف غير مدعوم. المسموح: PDF, Word, Excel, صور',
                ], 422);
            }

            // حذف الملف القديم
            if ($document->file && Storage::disk('public')->exists($document->file)) {
                Storage::disk('public')->delete($document->file);
            }

            $path = $file->store('documents', 'public');

            $data['file']          = $path;
            $data['original_name'] = $file->getClientOriginalName();
            $data['file_type']     = $fileType;
            $data['mime_type']     = $file->getMimeType();
            $data['size']          = $file->getSize();
        }

        $document->update($data);

        return response()->json([
            'message' => 'تم تحديث الملف بنجاح',
            'data'    => $document->fresh(),
        ]);
    }

    public function destroy(Document $document)
    {
        if ($document->file && Storage::disk('public')->exists($document->file)) {
            Storage::disk('public')->delete($document->file);
        }

        $document->delete();

        return response()->json([
            'message' => 'تم حذف الملف بنجاح',
        ]);
    }

    /**
     * تحديد نوع الملف
     */
    private function detectFileType($file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime      = $file->getMimeType();

        // PDF
        if (in_array($extension, ['pdf']) || $mime === 'application/pdf') {
            return 'pdf';
        }

        // Word
        if (in_array($extension, ['doc', 'docx']) ||
            in_array($mime, [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])) {
            return 'word';
        }

        // Excel
        if (in_array($extension, ['xls', 'xlsx']) ||
            in_array($mime, [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])) {
            return 'excel';
        }

        // Images
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']) ||
            str_starts_with($mime, 'image/')) {
            return 'image';
        }

        return null;
    }
}