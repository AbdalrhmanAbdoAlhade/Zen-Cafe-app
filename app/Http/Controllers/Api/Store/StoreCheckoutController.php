<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreOrderRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Services\OnlinePaymentLinkService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Exceptions\PaymentException;
use App\Services\HyperpayService;

class StoreCheckoutController extends Controller
{
    public function __construct(
    private readonly OrderService $orderService,
    private readonly HyperpayService $hyperpay,
    ) {
    }

    /**
     * POST /api/store/orders
     * لو الـ body فيه "payment_method": "online" بيرجع رابط الدفع في payment.checkout_url
     */
  public function store(StoreOrderRequest $request): JsonResponse
{
    $branch = Branch::main()->first() ?? Branch::active()->firstOrFail();

    $payload = $request->validated();

    $result = $this->orderService->createStoreOrder($branch, $payload);
    $order  = $result['order'];

    $payment = null;
    $paymentError = null;

    if ($request->input('payment_method') === 'online') {
        try {
            $online = $this->hyperpay->initiate($order, [
                'email'      => $payload['customer']['email'] ?? null,
                'given_name' => $payload['customer']['name'] ?? null,
                'city'       => $payload['shipping']['city'] ?? null,
            ]);

            $payment = $this->hyperpay->checkoutPayload($online);
        } catch (PaymentException $e) {
            report($e);
            $paymentError = $e->getMessage();
        }
    }

    return response()->json([
        'order'                => $this->orderService->serializeOrder($order),
        'customer_auth_token'  => $result['customer_auth_token'],
        'customer_credentials' => $result['customer_credentials'],
        'payment'              => $payment,
        'payment_error'        => $paymentError,
    ], 201);
}
    /**
     * GET /api/store/orders/{order}
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->order_type === 'store', 404);

        return response()->json([
            'order' => $this->orderService->serializeOrder(
                $order->load(['items.productVariant.product', 'shipment'])
            ),
        ]);
    }
}