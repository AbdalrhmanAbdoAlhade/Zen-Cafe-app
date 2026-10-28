<?php

namespace App\Http\Controllers\Api\Menu;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\StoreOrderRequest;
use App\Models\Order;
use App\Services\HyperpayService;
use App\Services\OrderService;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuOrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly QrTokenService $qrTokenService,
        private readonly HyperpayService $hyperpay,
    ) {
    }

    /**
     * POST /api/menu/{token}/orders
     * لو الـ body فيه "payment_method": "online" بيرجع رابط الدفع في payment.checkout_url
     */
    public function store(StoreOrderRequest $request, string $token): JsonResponse
    {
        $qrCode = $this->qrTokenService->resolve($token);

        $payload = $request->validated();

        // 1) إنشاء الأوردر (جوه transaction جوه OrderService)
        $result = $this->orderService->createOrder($qrCode, $payload);
        $order  = $result['order'];

        // 2) الدفع بعد ما الـ transaction تخلص، عشان فشل البوابة ما يلغيش الأوردر
        $payment      = null;
        $paymentError = null;

        if ($request->input('payment_method') === 'online') {
            try {
                $online = $this->hyperpay->initiate($order, [
                    'email'      => $payload['customer']['email'] ?? $request->input('customer_email'),
                    'given_name' => $payload['customer']['name'] ?? $request->input('customer_name'),
                ]);

                $payment = $this->hyperpay->checkoutPayload($online);
            } catch (PaymentException $e) {
                report($e);
                $paymentError = $e->getMessage();
            }
        }

        return response()->json([
            'order' => [
                'id' => $order->id,
                'status' => $order->status,
                'order_type' => $order->order_type,
                'table' => $order->table ? [
                    'id' => $order->table->id,
                    'table_number' => $order->table->table_number,
                ] : null,
                'estimated_preparation_minutes' => (int) $order->estimated_preparation_minutes,
                'estimated_ready_at' => $order->estimated_ready_at,
                'total_amount' => (float) $order->total_amount,
                'redeemed_points' => (int) $order->redeemed_points,
                'redeemed_amount' => (float) $order->redeemed_amount,
                'payable_amount' => $order->payableAmount(),
                'earned_points' => (int) $order->earned_points,
                'items' => $order->items,
            ],
            // بترجع بس أول مرة يتعمل فيها الحساب - الفرونت يطبعها للزبون
            'customer_credentials' => $result['customer_credentials'],
            'payment'              => $payment,
            'payment_error'        => $paymentError,
        ], 201);
    }

    /**
     * GET /api/menu/{token}/orders/{order}
     * متابعة أوردر تابع لنفس الـ QR بس.
     */
    public function show(Request $request, string $token, Order $order): JsonResponse
    {
        $qrCode = $this->qrTokenService->resolve($token);

        if ($order->qr_code_id !== $qrCode->id) {
            abort(404, 'هذا الطلب غير موجود لهذا الرمز.');
        }

        return response()->json([
            'order' => [
                'id' => $order->id,
                'status' => $order->status,
                'table' => $order->table ? [
                    'id' => $order->table->id,
                    'table_number' => $order->table->table_number,
                ] : null,
                'total_amount' => (float) $order->total_amount,
                'redeemed_points' => (int) $order->redeemed_points,
                'redeemed_amount' => (float) $order->redeemed_amount,
                'payable_amount' => $order->payableAmount(),
                'earned_points' => (int) $order->earned_points,
                'items' => $order->items()
                    ->with(['menuItem', 'options'])
                    ->get(),
            ],
        ]);
    }
}