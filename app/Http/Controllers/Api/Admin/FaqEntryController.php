<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FaqEntry;
use Illuminate\Http\Request;

class FaqEntryController extends Controller
{
    public function index()
    {
        $items = FaqEntry::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'intent_key' => ['required', 'string', 'max:50'],
            'keywords'   => ['required', 'array', 'min:1'],
            'keywords.*' => ['string', 'max:100'],
            'reply_ar'   => ['required', 'string'],
            'reply_en'   => ['nullable', 'string'],
            'is_active'  => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_active']  = $data['is_active'] ?? true;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        $item = FaqEntry::create($data);

        return response()->json([
            'message' => 'تم إنشاء الرد التلقائي',
            'data'    => $item,
        ], 201);
    }

    public function show(FaqEntry $faqEntry)
    {
        return response()->json(['data' => $faqEntry]);
    }

    public function update(Request $request, FaqEntry $faqEntry)
    {
        $data = $request->validate([
            'intent_key' => ['sometimes', 'string', 'max:50'],
            'keywords'   => ['sometimes', 'array', 'min:1'],
            'keywords.*' => ['string', 'max:100'],
            'reply_ar'   => ['sometimes', 'string'],
            'reply_en'   => ['nullable', 'string'],
            'is_active'  => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $faqEntry->update($data);

        return response()->json([
            'message' => 'تم التحديث',
            'data'    => $faqEntry->fresh(),
        ]);
    }

    public function destroy(FaqEntry $faqEntry)
    {
        $faqEntry->delete();

        return response()->json(['message' => 'تم الحذف']);
    }
}