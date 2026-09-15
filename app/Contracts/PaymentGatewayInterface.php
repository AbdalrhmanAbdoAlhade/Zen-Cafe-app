<?php

namespace App\Contracts;

use App\Models\Order;
use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    /**
     * يبدأ عملية الدفع للأوردر - يرجع array فيها على الأقل redirect_url أو payment_token
     * حسب البوابة، عشان الفرونت يوجّه العميل لإتمام الدفع.
     */
    public function initiatePayment(Order $order): array;

    /**
     * يتحقق من صحة الـ callback/webhook الجاي من البوابة، ويرجع true لو الدفع نجح فعلاً.
     */
    public function verifyCallback(Request $request): bool;

    /**
     * استرداد مبلغ أوردر بعد ما كان مدفوع.
     */
    public function refund(Order $order): bool;
}