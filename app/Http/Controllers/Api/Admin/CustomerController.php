<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /** الشرائح اللي بيرجّعها endpoint العملاء المميزين */
    private const PREMIUM_TIERS = ['silver', 'gold'];

    /**
     * GET /api/admin/customers/premium
     * عملاء الشريحة الفضية والذهبية فقط، الأعلى إنفاقًا الأول.
     *
     * Query (كلها اختيارية):
     *  - tier=silver|gold   → شريحة واحدة بس
     *  - search=...         → بحث بالاسم / التليفون / الإيميل
     *  - per_page=1..100    → الافتراضي 20
     */
    public function premium(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tier'     => ['nullable', Rule::in(self::PREMIUM_TIERS)],
            'search'   => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $tiers = isset($data['tier']) ? [$data['tier']] : self::PREMIUM_TIERS;

        $customers = Customer::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'tier',
                'loyalty_points_balance',
                'total_spent',
                'created_at',
            ])
            ->whereIn('tier', $tiers)
            ->when($data['search'] ?? null, function ($query, string $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('total_spent')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json($customers);
    }
}
