<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerLoginRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CustomerAuthController extends Controller
{
    public function login(CustomerLoginRequest $request): JsonResponse
    {
        $customer = Customer::where('phone', $request->input('phone'))->first();

        // ملحوظة: guard "customer" شغّال بـ driver "sanctum"، وده guard مخصص للتحقق
        // من التوكينات الجاهزة بس (مش بيدعم attempt() بالباسورد زي الـ session guards)،
        // فالتحقق من الباسورد بيتم يدويًا هنا بـ Hash::check.
        if (! $customer || ! Hash::check($request->input('password'), $customer->password)) {
            throw ValidationException::withMessages([
                'phone' => 'بيانات الدخول غير صحيحة.',
            ]);
        }

        $token = $customer->createToken('customer-access')->plainTextToken;

        return response()->json([
            'token' => $token,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
            ],
        ]);
    }
}
