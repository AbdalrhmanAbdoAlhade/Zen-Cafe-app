<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\InvalidOrderTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\HyperpayService;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerOrderCancelController extends Controller
{
    /** أنواع الأوردرات اللي العميل يقدر يكنسلها (مش أوردرات QR اللي جوه الفرع) */
    private const CANCELLABLE_TYPES = ['pre_order', 'store'];

    public function __construct(
        private readonly OrderStatusService $statusService,
        private readonly OrderService $orders,
        private readonly HyperpayService $hyperpay,
    ) {}

   public function __invoke(Request $request, Order $order): JsonResponse
{
    $customer = $request->user('customer');

    $result = DB::transaction(function () use ($customer, $order) {
        $model = Order::whereKey($order->id)
            ->where('customer_id', $customer->id)
            ->lockForUpdate()
            ->first();

        if (! $model) {
            return response()->json(['message' => 'الطلب غير موجود.'], 404);
        }

        if (! in_array($model->order_type, self::CANCELLABLE_TYPES, true)) {
            return response()->json([
                'message' => 'نوع الطلب ده مينفعش يتلغي من العميل.',
            ], 422);
        }

        if (in_array($model->status, [
            Order::STATUS_CANCELLED,
            Order::STATUS_REJECTED,
        ], true)) {
            return response()->json(['message' => 'الطلب ملغي أو مرفوض بالفعل.'], 422);
        }

        // لو فيه STATUS_REFUNDED
        if (defined(Order::class.'::STATUS_REFUNDED') && $model->status === Order::STATUS_REFUNDED) {
            return response()->json(['message' => 'الطلب مسترجع بالفعل.'], 422);
        }

        try {
            $model = $this->statusService->cancelByCustomer($model);
        } catch (InvalidOrderTransitionException $e) {
            return response()->json([
                'message' => 'مينفعش تلغي الطلب في حالته الحالية.',
            ], 422);
        }

        return $model;
    });

    if ($result instanceof JsonResponse) {
        return $result;
    }

    $result->load(
        $result->order_type === 'store'
            ? ['items.productVariant.product', 'shipment']
            : ['items.menuItem', 'items.options.menuOptionValue']
    );

    return response()->json([
        'message' => 'تم إلغاء الطلب بنجاح' . ($result->isPaidOnline() ? ' وتم استرجاع المبلغ.' : '.'),
        'order'   => $this->orders->serializeOrder($result),
    ]);
}
}