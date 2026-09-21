<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerLoyaltyController extends Controller
{
    /**
     * GET /api/customer/loyalty
     */
    public function show(Request $request, LoyaltyService $loyalty): JsonResponse
    {
        $customer = $request->user('customer');

        return response()->json([
            // النقاط الصالحة فعليًا بس (مش المستعملة ولا المنتهية)
            'loyalty_points_balance' => $loyalty->availablePoints($customer),
            'transactions' => $customer->loyaltyTransactions()
                ->with('order:id,status,total_amount,redeemed_points,redeemed_amount,earned_points')
                ->paginate(20),
        ]);
    }
}