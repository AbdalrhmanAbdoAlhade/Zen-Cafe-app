<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Placeholder - لحد ما يتحدد EdfaPay/Paymob/غيرهم.
 * initiatePayment هنا مش بتوجه لأي بوابة فعلية، بس بترجع رسالة توضيحية.
 * استبدلها بربط الـ interface بالـ implementation الحقيقية في AppServiceProvider
 * أول ما يتحدد اختيار البوابة، من غير ما تحتاج تلمس أي كود تاني في المشروع.
 */
class PendingPaymentGateway implements PaymentGatewayInterface
{
    public function initiatePayment(Order $order): array
    {
        return [
            'status' => 'pending_gateway_setup',
            'message' => 'بوابة الدفع الإلكتروني لسه ما اتفعّلتش - الأوردر متسجل وفي انتظار تفعيل الدفع.',
            'redirect_url' => null,
        ];
    }

    public function verifyCallback(Request $request): bool
    {
        return false;
    }

    public function refund(Order $order): bool
    {
        return false;
    }
}