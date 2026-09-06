<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerLoyaltyController extends Controller
{
    /**
     * GET /api/customer/loyalty
     */
    public function show(Request $request): JsonResponse
    {
        $customer = $request->user('customer');

        return response()->json([
            'loyalty_points_balance' => (int) $customer->loyalty_points_balance,
            'transactions' => $customer->loyaltyTransactions()
                ->with('order:id,status,total_amount,redeemed_points,redeemed_amount,earned_points')
                ->paginate(20),
        ]);
    }
}
