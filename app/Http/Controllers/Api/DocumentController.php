<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    /**
     * قائمة الملفات المتاحة للعامة
     */
    public function index(Request $request)
    {
        $query = Document::active()->ordered();

        if ($request->filled('file_type')) {
            $query->where('file_type', $request->file_type);
        }

        $documents = $query->get()->map(fn ($doc) => [
            'id'            => $doc->id,
            'title_ar'      => $doc->title_ar,
            'title_en'      => $doc->title_en,
            'file_url'      => $doc->file_url,
            'file_type'     => $doc->file_type,
            'original_name' => $doc->original_name,
            'size'          => $doc->size,
            'sort_order'    => $doc->sort_order,
            'created_at'    => $doc->created_at,
        ]);

        return response()->json(['data' => $documents]);
    }

    /**
     * تفاصيل ملف واحد
     */
    public function show(Document $document)
    {
        if (! $document->is_active) {
            return response()->json(['message' => 'الملف غير متاح'], 404);
        }

        return response()->json([
            'data' => [
                'id'            => $document->id,
                'title_ar'      => $document->title_ar,
                'title_en'      => $document->title_en,
                'file_url'      => $document->file_url,
                'file_type'     => $document->file_type,
                'original_name' => $document->original_name,
                'size'          => $document->size,
                'created_at'    => $document->created_at,
            ],
        ]);
    }
}