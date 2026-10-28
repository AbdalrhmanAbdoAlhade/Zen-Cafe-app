<?php

namespace App\Http\Controllers\Api\Menu;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Menu\StoreOrderRequest;
use App\Models\Branch;
use App\Models\BranchWorkingHour;
use App\Models\Order;
use App\Services\HyperpayService;
use App\Services\MenuBuilderService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnlineMenuController extends Controller
{
    public function __construct(
        private readonly MenuBuilderService $menuBuilder,
        private readonly OrderService $orders,
        private readonly HyperpayService $hyperpay,
    ) {}

    /**
     * جلب جميع الفروع المفعلة للعميل
     * - افتراضيًا: بيانات الفرع بس (+ مواعيد العمل)
     * - مع ?with_menu=1 : كل فرع ومعاه المنيو
     */
    public function index(Request $request): JsonResponse
    {
        $withMenu = $request->boolean('with_menu');

        $branches = Branch::where('is_active', true)
            ->with('workingHours')
            ->get();

        return response()->json([
            'data' => $branches
                ->map(fn (Branch $branch) => $this->branchPayload($branch, $withMenu))
                ->values(),
        ]);
    }

    /**
     * فرع واحد بكل بياناته + المنيو كامل
     */
    public function show(int $branchId): JsonResponse
    {
        $branch = $this->branch($branchId);

        return response()->json($this->branchPayload($branch, true));
    }

    public function items(Request $request, int $branchId): JsonResponse
    {
        $branch = $this->branch($branchId);

        return response()->json(
            $this->menuBuilder->getMenuForBranch(
                branch: $branch,
                categoryId: $request->query('category_id') ? (int) $request->query('category_id') : null,
                perPage: 15,
                onlineOnly: true,
                minPrice: $request->query('min_price') !== null ? (float) $request->query('min_price') : null,
                maxPrice: $request->query('max_price') !== null ? (float) $request->query('max_price') : null,
                sort: $request->query('sort'),
            )
        );
    }

    public function itemDetails(int $branchId, int $itemId): JsonResponse
    {
        $branch = $this->branch($branchId);

        $item = $this->menuBuilder->getItemDetails($branch, $itemId);
        abort_unless($item, 404);

        return response()->json(['data' => $item]);
    }

    /**
     * POST /api/menu/branches/{branchId}/orders  (حسب الـ route عندك)
     * لو الـ body فيه "payment_method": "online" بيرجع رابط الدفع في payment.checkout_url
     */
    public function store(StoreOrderRequest $request, int $branchId): JsonResponse
    {
        $branch = $this->branch($branchId);

        if ($branch->is_online_paused) {
            return response()->json([
                'message' => 'الطلبات الخارجية متوقفة مؤقتًا لهذا الفرع.',
                'reason'  => $branch->pause_reason,
            ], 423);
        }

        if (empty($request->input('customer.phone'))) {
            return response()->json([
                'message' => 'رقم الهاتف مطلوب للطلب الخارجي.',
            ], 422);
        }

        $payload = $request->validated();

        // 1) إنشاء الأوردر (جوه transaction جوه OrderService)
        $result = $this->orders->createOnlineOrder($branch, $payload);
        $order  = $result['order'];

        // 2) الدفع بعد ما الـ transaction تخلص، عشان فشل البوابة ما يلغيش الأوردر
        $payment      = null;
        $paymentError = null;

        if ($request->input('payment_method') === 'online') {
            try {
                $online = $this->hyperpay->initiate($order, [
                    'email'      => $payload['customer']['email'] ?? null,
                    'given_name' => $payload['customer']['name'] ?? null,
                ]);

                $payment = $this->hyperpay->checkoutPayload($online);
            } catch (PaymentException $e) {
                report($e);
                $paymentError = $e->getMessage();
            }
        }

        // 3) serializeOrder بعد initiate، عشان online_payment_status يطلع "initiated"
        return response()->json([
            'order'               => $this->orders->serializeOrder($order),
            'customer_auth_token' => $result['customer_auth_token'],
            'payment'             => $payment,
            'payment_error'       => $paymentError,
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

    private function branch(int $branchId): Branch
    {
        return Branch::whereKey($branchId)
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * الـ payload الكامل للفرع:
     * كل أعمدة الفرع + مواعيد العمل + is_open_now + حالة الطلبات الأونلاين
     * + categories (قايمة الأقسام) + menu (كل الأصناف) لو مطلوب.
     */
    private function branchPayload(Branch $branch, bool $withMenu = true): array
    {
        $branch->loadMissing('workingHours');

        $payload = [
            'branch' => array_merge($branch->toArray(), [
                'lat'                   => (float) $branch->lat,
                'lng'                   => (float) $branch->lng,
                'default_radius_meters' => (int) $branch->default_radius_meters,
                'is_active'             => (bool) $branch->is_active,
                'is_main'               => (bool) $branch->is_main,
                'is_online_paused'      => (bool) $branch->is_online_paused,
                'is_open_now'           => $branch->isOpenNow(),
                'working_hours'         => $branch->workingHours
                    ->map(fn (BranchWorkingHour $h) => [
                        'day_of_week' => $h->day_of_week,
                        'day_name'    => BranchWorkingHour::DAYS[$h->day_of_week] ?? null,
                        'opens_at'    => $h->opens_at,
                        'closes_at'   => $h->closes_at,
                        'is_closed'   => $h->isClosed(),
                    ])
                    ->values()
                    ->all(),
            ]),
            'online_ordering_paused'      => (bool) $branch->is_online_paused,
            'pause_reason'                => $branch->pause_reason,
            // وقت الذروة الحالي - بيتضاف على وقت تجهيز أي طلب جديد
            'current_prep_offset_minutes' => (int) $branch->current_prep_offset_minutes,
        ];

        if ($withMenu) {
            $payload['categories'] = $this->menuBuilder->getCategoriesForBranch($branch, true);

            // كل الأصناف الأونلاين للفرع (بدون pagination عملي)
            $payload['menu'] = $this->menuBuilder->getMenuForBranch(
                branch: $branch,
                categoryId: null,
                perPage: 1000,
                onlineOnly: true,
                minPrice: null,
                maxPrice: null,
                sort: null,
            );
        }

        return $payload;
    }
}