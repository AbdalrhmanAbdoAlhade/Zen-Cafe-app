<?php

namespace App\Services\Zatca;

use App\Models\Order;
use App\Models\ZatcaSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZatcaQrService
{
    public const STATUS_GENERATED = 'generated';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public function generateForOrder(Order $order): Order
    {
        if ($order->zatca_qr_base64 && $order->zatca_status === self::STATUS_GENERATED) {
            return $order;
        }

        $settings = ZatcaSetting::current();

        if (! $settings || ! $settings->isReady()) {
            $order->update(['zatca_status' => self::STATUS_SKIPPED]);

            return $order->fresh();
        }

        try {
            $order->loadMissing('items.options');

            [$subtotalExVat, $vatAmount, $totalIncVat] = $this->resolveAmounts($order);

            $issuedAt = now()->utc();
            $invoiceNumber = $order->invoice_number ?: $settings->nextInvoiceNumber();
            $invoiceUuid = $order->invoice_uuid ?: (string) Str::uuid();

            $qrBase64 = $this->encodeTlvBase64([
                1 => $settings->seller_name,
                2 => $settings->vat_number,
                3 => $issuedAt->format('Y-m-d\TH:i:s\Z'),
                4 => $this->formatAmount($totalIncVat),
                5 => $this->formatAmount($vatAmount),
            ]);

            $order->update([
                'invoice_number' => $invoiceNumber,
                'invoice_uuid' => $invoiceUuid,
                'invoice_issued_at' => $issuedAt,
                'subtotal_ex_vat' => $subtotalExVat,
                'vat_amount' => $vatAmount,
                'total_inc_vat' => $totalIncVat,
                'zatca_qr_base64' => $qrBase64,
                'zatca_status' => self::STATUS_GENERATED,
            ]);

            return $order->fresh();
        } catch (\Throwable $e) {
            Log::error('ZATCA QR generation failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            $order->update(['zatca_status' => self::STATUS_FAILED]);

            return $order->fresh();
        }
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public function resolveAmounts(Order $order): array
    {
        $order->loadMissing('items.options');

        $grossBase = 0.0;
        $grossVat = 0.0;

        foreach ($order->items as $item) {
            $grossBase += $item->lineBaseAmount();
            $grossVat += $item->lineVatAmount();
        }

        $grossBase = round($grossBase, 2);
        $grossVat = round($grossVat, 2);
        $grossTotal = round($grossBase + $grossVat, 2);

        $discount = round(
            (float) $order->redeemed_amount + (float) $order->coupon_discount,
            2
        );

        if ($grossTotal <= 0) {
            $payable = round((float) $order->payableAmount(), 2);

            return [$payable, 0.0, $payable];
        }

        if ($discount <= 0) {
            return [$grossBase, $grossVat, $grossTotal];
        }

        $payableTotal = round(max(0, $grossTotal - $discount), 2);
        $ratio = $payableTotal / $grossTotal;
        $subtotalExVat = round($grossBase * $ratio, 2);
        $vatAmount = round($payableTotal - $subtotalExVat, 2);

        return [$subtotalExVat, $vatAmount, $payableTotal];
    }

    /**
     * @param  array<int, string>  $tags
     */
    public function encodeTlvBase64(array $tags): string
    {
        $buffer = '';

        foreach ($tags as $tag => $value) {
            $value = (string) $value;
            $length = strlen($value);

            if ($length > 255) {
                throw new \InvalidArgumentException("ZATCA TLV tag {$tag} exceeds 255 bytes.");
            }

            $buffer .= chr((int) $tag).chr($length).$value;
        }

        return base64_encode($buffer);
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}