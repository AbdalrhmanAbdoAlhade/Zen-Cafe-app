<?php

namespace App\Http\Controllers\Api\Menu;

use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\StoreOrderRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Services\MenuBuilderService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnlineMenuController extends Controller
{
    public function __construct(
        private readonly MenuBuilderService $menuBuilder,
        private readonly OrderService $orders
    ) {}
  public function itemDetails(int $branchId, int $itemId): JsonResponse
{
    $branch = $this->branch($branchId);

    $item = $this->menuBuilder->getItemDetails($branch, $itemId);

    abort_unless($item, 404);

    return response()->json(['data' => $item]);
}
/**
     * جلب جميع الفروع المفعلة للعميل
     */
    public function index(): JsonResponse
    {
        $branches = Branch::where('is_active', true)
            ->select([
                'id',
                'name_ar',
                'name_en',
                'address',
                'lat',
                'lng',
                'is_online_paused',
                'pause_reason'
            ])
            ->get();

        return response()->json([
            'data' => $branches,
        ]);
    }
    private function branch(int $branchId): Branch
    {
        return Branch::whereKey($branchId)
            ->where('is_active', true)
            ->firstOrFail();
    }

    public function show(int $branchId): JsonResponse
    {
        $branch = $this->branch($branchId);

        return response()->json([
            'branch' => [
                'id' => $branch->id,
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
                'address' => $branch->address,
            ],
            'online_ordering_paused' => (bool) $branch->is_online_paused,
            'pause_reason' => $branch->pause_reason,
        ]);
    }

    public function items(Request $request, int $branchId): JsonResponse
    {
        $branch = $this->branch($branchId);

        return response()->json(
            $this->menuBuilder->getMenuForBranch(
                $branch,
                $request->integer('category_id') ?: null,
                15,
                true
            )
        );
    }

    public function store(StoreOrderRequest $request, int $branchId): JsonResponse
    {
        $branch = $this->branch($branchId);

        if ($branch->is_online_paused) {
            return response()->json([
                'message' => 'الطلبات الخارجية متوقفة مؤقتًا لهذا الفرع.',
                'reason' => $branch->pause_reason,
            ], 423);
        }

        if (empty($request->input('customer.phone'))) {
            return response()->json([
                'message' => 'رقم الهاتف مطلوب للطلب الخارجي.',
            ], 422);
        }

        $result = $this->orders->createOnlineOrder($branch, $request->validated());

        return response()->json([
            'order' => $this->orders->serializeOrder($result['order']),
            'customer_auth_token' => $result['customer_auth_token'],
        ], 201);
    }

   public function showOrder(int $branchId, Order $order): JsonResponse
{
    $branch = $this->branch($branchId);

    abort_unless($order->branch_id === $branch->id && $order->order_type === 'pre_order', 404);

    return response()->json([
        'order' => $this->orders->serializeOrder(
            $order->load(['items.menuItem', 'items.options.menuOptionValue'])
        ),
    ]);
}
}