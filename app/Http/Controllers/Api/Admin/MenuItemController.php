<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Traits\HandlesWebpImages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MenuItemController extends Controller
{
    use HandlesWebpImages;

    public function index(Request $request)
    {
        $query = MenuItem::with(['category', 'options.values'])
            ->withCount('branches');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('is_available')) {
            $query->where('is_available', filter_var($request->is_available, FILTER_VALIDATE_BOOLEAN));
        }

        $items = $query->orderBy('id', 'desc')->paginate(20);

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id'     => ['required', 'exists:menu_categories,id'],
            'name_ar'         => ['required', 'string', 'max:255'],
            'name_en'         => ['required', 'string', 'max:255'],
            'description_ar'  => ['nullable', 'string'],
            'description_en'  => ['nullable', 'string'],
            'image'           => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
            'base_price'      => ['required', 'numeric', 'min:0'],
            'preparation_time_minutes' => ['nullable', 'integer', 'min:0'],
            'is_available'    => ['nullable', 'boolean'],
            'is_available_online' => ['nullable', 'boolean'],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'calories' => ['nullable', 'integer', 'min:0'],
            'allergens' => ['nullable', 'array'],
            'ingredients' => ['nullable', 'array'],

            'options'                        => ['nullable', 'array'],
            'options.*.name_ar'              => ['required_with:options', 'string', 'max:255'],
            'options.*.name_en'              => ['required_with:options', 'string', 'max:255'],
            'options.*.type'                 => ['required_with:options', Rule::in(['single', 'multiple'])],
            'options.*.is_required'          => ['nullable', 'boolean'],
            'options.*.values'               => ['nullable', 'array'],
            'options.*.values.*.name_ar'     => ['required', 'string', 'max:255'],
            'options.*.values.*.name_en'     => ['required', 'string', 'max:255'],
            'options.*.values.*.extra_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item = DB::transaction(function () use ($request, $data) {
            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = $this->storeAsWebp(
                    file: $request->file('image'),
                    directory: 'items',
                    quality: 80,
                    maxWidth: 1200
                );
            }

            $item = MenuItem::create([
                'category_id'    => $data['category_id'],
                'name_ar'        => $data['name_ar'],
                'name_en'        => $data['name_en'],
                'description_ar' => $data['description_ar'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'image'          => $imagePath,
                'base_price'     => $data['base_price'],
                'preparation_time_minutes' => $data['preparation_time_minutes'] ?? 0,
                'is_available'   => $data['is_available'] ?? true,
                'is_available_online' => $data['is_available_online'] ?? true,
                'vat' => $data['vat'] ?? 0,
                'calories' => $data['calories'] ?? null,
                'allergens' => $data['allergens'] ?? null,
                'ingredients' => $data['ingredients'] ?? null,
            ]);

            if (! empty($data['options'])) {
                foreach ($data['options'] as $opt) {
                    $option = $item->options()->create([
                        'name_ar'     => $opt['name_ar'],
                        'name_en'     => $opt['name_en'],
                        'type'        => $opt['type'],
                        'is_required' => $opt['is_required'] ?? false,
                    ]);

                    if (! empty($opt['values'])) {
                        foreach ($opt['values'] as $val) {
                            $option->values()->create([
                                'name_ar'     => $val['name_ar'],
                                'name_en'     => $val['name_en'],
                                'extra_price' => $val['extra_price'] ?? 0,
                            ]);
                        }
                    }
                }
            }

            return $item->load(['category', 'options.values']);
        });

        return response()->json([
            'message' => 'تم إنشاء الصنف بنجاح',
            'data'    => $item,
        ], 201);
    }

    public function show(MenuItem $item)
    {
        $item->load(['category', 'options.values', 'branches']);

        return response()->json(['data' => $item]);
    }

    public function update(Request $request, MenuItem $item)
    {
        $data = $request->validate([
            'category_id'    => ['sometimes', 'exists:menu_categories,id'],
            'name_ar'        => ['sometimes', 'string', 'max:255'],
            'name_en'        => ['sometimes', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'image'          => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
            'base_price'     => ['sometimes', 'numeric', 'min:0'],
            'preparation_time_minutes' => ['sometimes', 'integer', 'min:0'],
            'is_available'   => ['nullable', 'boolean'],
            'is_available_online' => ['nullable', 'boolean'],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'calories' => ['nullable', 'integer', 'min:0'],
            'allergens' => ['nullable', 'array'],
            'ingredients' => ['nullable', 'array'],
        ]);

        if ($request->hasFile('image')) {
            $data['image'] = $this->storeAsWebp(
                file: $request->file('image'),
                directory: 'items',
                quality: 80,
                maxWidth: 1200,
                oldPath: $item->image
            );
        }

        $item->update($data);

        return response()->json([
            'message' => 'تم تحديث الصنف',
            'data'    => $item->fresh(['category', 'options.values']),
        ]);
    }

    public function destroy(MenuItem $item)
    {
        $this->deleteImage($item->image);

        $item->delete(); // cascade على options + menu_item_branch

        return response()->json(['message' => 'تم حذف الصنف']);
    }

    // ===== Options =====

    public function storeOption(Request $request, MenuItem $item)
    {
        $data = $request->validate([
            'name_ar'              => ['required', 'string', 'max:255'],
            'name_en'              => ['required', 'string', 'max:255'],
            'type'                 => ['required', Rule::in(['single', 'multiple'])],
            'is_required'          => ['nullable', 'boolean'],
            'values'               => ['nullable', 'array'],
            'values.*.name_ar'     => ['required', 'string', 'max:255'],
            'values.*.name_en'     => ['required', 'string', 'max:255'],
            'values.*.extra_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $option = DB::transaction(function () use ($item, $data) {
            $option = $item->options()->create([
                'name_ar'     => $data['name_ar'],
                'name_en'     => $data['name_en'],
                'type'        => $data['type'],
                'is_required' => $data['is_required'] ?? false,
            ]);

            if (! empty($data['values'])) {
                foreach ($data['values'] as $val) {
                    $option->values()->create([
                        'name_ar'     => $val['name_ar'],
                        'name_en'     => $val['name_en'],
                        'extra_price' => $val['extra_price'] ?? 0,
                    ]);
                }
            }

            return $option->load('values');
        });

        return response()->json(['message' => 'تمت إضافة الخيار', 'data' => $option], 201);
    }

    public function updateOption(Request $request, MenuItem $item, MenuOption $option)
    {
        if ($option->menu_item_id !== $item->id) {
            return response()->json(['message' => 'الخيار لا يتبع هذا الصنف'], 404);
        }

        $data = $request->validate([
            'name_ar'     => ['sometimes', 'string', 'max:255'],
            'name_en'     => ['sometimes', 'string', 'max:255'],
            'type'        => ['sometimes', Rule::in(['single', 'multiple'])],
            'is_required' => ['nullable', 'boolean'],
        ]);

        $option->update($data);

        return response()->json(['message' => 'تم تحديث الخيار', 'data' => $option->fresh('values')]);
    }

    public function destroyOption(MenuItem $item, MenuOption $option)
    {
        if ($option->menu_item_id !== $item->id) {
            return response()->json(['message' => 'الخيار لا يتبع هذا الصنف'], 404);
        }

        $option->delete();

        return response()->json(['message' => 'تم حذف الخيار']);
    }
}