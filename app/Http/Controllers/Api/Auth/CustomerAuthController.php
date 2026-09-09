<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerLoginRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class CustomerAuthController extends Controller
{
    public function login(CustomerLoginRequest $request): JsonResponse
    {
        $phone = trim($request->string('phone')->toString());

        $customer = Customer::firstOrCreate(
            ['phone' => $phone],
            [
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'password' => null,
            ]
        );

        if (! $customer->wasRecentlyCreated && ($request->filled('name') || $request->filled('email'))) {
            $customer->update(array_filter([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
            ], fn ($value) => $value !== null));
        }

        return response()->json([
            'token' => $customer->createToken('customer-access')->plainTextToken,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ],
        ]);
    }
}