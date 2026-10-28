<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService
    ) {}

    /**
     * إنشاء أو استرجاع سلة نشطة
     * POST /api/cart
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cart_type' => ['required', 'in:menu,store'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'session_token' => ['nullable', 'string', 'max:64'],
            'meta' => ['nullable', 'array'],
        ]);

        $customer = $request->user('customer');
        $session = $data['session_token']
            ?? $request->header('X-Cart-Session');

        try {
            $cart = $this->cartService->resolveCart([
                'customer_id' => $customer?->id,
                'session_token' => $session,
                'branch_id' => $data['branch_id'] ?? null,
                'cart_type' => $data['cart_type'],
                'meta' => $data['meta'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->cartService->serialize($cart),
        ], 201);
    }

    /**
     * عرض سلة
     * GET /api/cart/{cart}
     */
    public function show(Request $request, Cart $cart): JsonResponse
    {
        $customer = $request->user('customer');
        $session = $request->header('X-Cart-Session') ?? $request->query('session_token');

        try {
            $cart = $this->cartService->getCart(
                $cart->id,
                $customer?->id,
                $session
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json([
            'data' => $this->cartService->serialize($cart),
        ]);
    }

    /**
     * إضافة عنصر
     * POST /api/cart/{cart}/items
     */
    public function addItem(Request $request, Cart $cart): JsonResponse
    {
        $this->authorizeCart($request, $cart);

        $data = $request->validate([
            'menu_item_id' => ['nullable', 'integer', 'exists:menu_items,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'options' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $cart = $this->cartService->addItem($cart, $data);
        } catch (InvalidArgumentException | \App\Exceptions\MixedCartException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->cartService->serialize($cart),
        ]);
    }

    /**
     * تحديث عنصر
     * PATCH /api/cart/{cart}/items/{item}
     */
    public function updateItem(Request $request, Cart $cart, int $item): JsonResponse
    {
        $this->authorizeCart($request, $cart);

        $data = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:0', 'max:99'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $cart = $this->cartService->updateItem($cart, $item, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->cartService->serialize($cart),
        ]);
    }

    /**
     * حذف عنصر
     * DELETE /api/cart/{cart}/items/{item}
     */
    public function removeItem(Request $request, Cart $cart, int $item): JsonResponse
    {
        $this->authorizeCart($request, $cart);

        try {
            $cart = $this->cartService->removeItem($cart, $item);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->cartService->serialize($cart),
        ]);
    }

    /**
     * تفريغ السلة
     * DELETE /api/cart/{cart}/items
     */
    public function clear(Request $request, Cart $cart): JsonResponse
    {
        $this->authorizeCart($request, $cart);

        try {
            $cart = $this->cartService->clear($cart);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->cartService->serialize($cart),
        ]);
    }

    /**
     * بدء الدفع / checkout
     * POST /api/cart/{cart}/checkout-started
     */
    public function checkoutStarted(Request $request, Cart $cart): JsonResponse
    {
        $this->authorizeCart($request, $cart);

        try {
            $cart = $this->cartService->markCheckoutStarted($cart);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->cartService->serialize($cart),
        ]);
    }

    /**
     * فشل الدفع
     * POST /api/cart/{cart}/payment-failed
     */
    public function paymentFailed(Request $request, Cart $cart): JsonResponse
    {
        $this->authorizeCart($request, $cart);

        $cart = $this->cartService->markPaymentFailed($cart);

        return response()->json([
            'data' => $this->cartService->serialize($cart),
        ]);
    }

    /**
     * استعادة سلة متروكة
     * POST /api/cart/{cart}/recover
     */
    public function recover(Request $request, Cart $cart): JsonResponse
    {
        $this->authorizeCart($request, $cart);

        try {
            $cart = $this->cartService->recover($cart);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->cartService->serialize($cart),
        ]);
    }

    /**
     * ربط السلة بطلب بعد الإنشاء
     * POST /api/cart/{cart}/convert
     * Body: order_id
     */
    public function convert(Request $request, Cart $cart): JsonResponse
    {
        $this->authorizeCart($request, $cart);

        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
        ]);

        $order = \App\Models\Order::findOrFail($data['order_id']);
        $cart = $this->cartService->markConverted($cart, $order);

        return response()->json([
            'data' => $this->cartService->serialize($cart),
        ]);
    }

    private function authorizeCart(Request $request, Cart $cart): void
    {
        $customer = $request->user('customer');
        $session = $request->header('X-Cart-Session') ?? $request->input('session_token');

        if ($customer && (int) $cart->customer_id === (int) $customer->id) {
            return;
        }

        if ($session && $cart->session_token && hash_equals((string) $cart->session_token, (string) $session)) {
            return;
        }

        abort(403, 'غير مصرح بالوصول لهذه السلة');
    }
}