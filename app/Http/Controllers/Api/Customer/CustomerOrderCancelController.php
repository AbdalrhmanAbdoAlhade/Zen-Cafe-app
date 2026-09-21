<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\InvalidOrderTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\MpgsService;
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
        private readonly MpgsService $mpgs,
    ) {}

    public function __invoke(Request $request, Order $order): JsonResponse
    {
        $customer = $request->user('customer');

        $result = DB::transaction(function () use ($customer, $order) {
            // نعيد جلب الأوردر بـ lock عشان لو الكاشير بيقبله في نفس اللحظة ميحصلش تعارض،
            // ونتأكد إنه بتاع العميل ده (الـ binding لوحده مش بيفلتر بالعميل)
            $model = Order::whereKey($order->id)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            // أوردر مش بتاعه = 404 (منكشفش إنه موجود أصلاً)
            if (! $model) {
                return response()->json(['message' => 'الطلب غير موجود.'], 404);
            }

            if (! in_array($model->order_type, self::CANCELLABLE_TYPES, true)) {
                return response()->json([
                    'message' => 'نوع الطلب ده مينفعش يتلغي من العميل.',
                ], 422);
            }

            if ($model->status === Order::STATUS_CANCELLED) {
                return response()->json(['message' => 'الطلب ملغي بالفعل.'], 422);
            }

            // العميل يكنسل بس قبل ما الفرع يقبل الطلب
            if ($model->status !== Order::STATUS_PENDING) {
                return response()->json([
                    'message' => 'مينفعش تلغي الطلب بعد ما الفرع يبدأ يتعامل معاه.',
                    'status'  => $model->status,
                ], 422);
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

        // لو الطلب كان مدفوع أونلاين نرجّع الفلوس. بره الـ transaction عشان مانقفلش
        // الـ row أثناء ريكويست خارجي، ولو الاسترجاع فشل بيتسجل refund_failed ومبيوقفش الإلغاء.
        $this->mpgs->refundIfPaidOnline($result);

        $result->load(
            $result->order_type === 'store'
                ? ['items.productVariant.product', 'shipment']
                : ['items.menuItem', 'items.options.menuOptionValue']
        );

        return response()->json([
            'message' => 'تم إلغاء الطلب بنجاح.',
            'order'   => $this->orders->serializeOrder($result),
        ]);
    }
}