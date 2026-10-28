<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function isConfigured(): bool
    {
        if (! config('services.whatsapp.enabled')) {
            return false;
        }

        return match (config('services.whatsapp.provider')) {
            'meta'   => filled(config('services.whatsapp.token'))
                        && filled(config('services.whatsapp.phone_id')),
            'twilio' => filled(config('services.whatsapp.token'))
                        && filled(config('services.whatsapp.from'))
                        && filled(config('services.whatsapp.account_sid')),
            default  => filled(config('services.whatsapp.api_url'))
                        && filled(config('services.whatsapp.token')),
        };
    }

    /** رقم E.164 أو null */
    public function normalizePhone(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if (! $digits) {
            return null;
        }

        // 05xxxxxxxx → 9665xxxxxxxx
        if (str_starts_with($digits, '05') && strlen($digits) === 10) {
            $digits = '966' . substr($digits, 1);
        }

        // 5xxxxxxxx (9 أرقام)
        if (strlen($digits) === 9 && str_starts_with($digits, '5')) {
            $digits = '966' . $digits;
        }

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    /** @return array{success: bool, message_id: ?string, error: ?string} */
    public function sendText(string $toE164, string $body): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message_id' => null, 'error' => 'WhatsApp not configured'];
        }

        try {
            return match (config('services.whatsapp.provider')) {
                'meta'   => $this->sendViaMeta($toE164, $body),
                'twilio' => $this->sendViaTwilio($toE164, $body),
                default  => $this->sendViaCustom($toE164, $body),
            };
        } catch (\Throwable $e) {
            Log::error('WhatsApp send exception', [
                'to'      => $this->mask($toE164),
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function sendViaMeta(string $to, string $body): array
    {
        $phoneId = config('services.whatsapp.phone_id');
        $url     = rtrim(config('services.whatsapp.api_url'), '/') . "/{$phoneId}/messages";

        $response = Http::withToken(config('services.whatsapp.token'))
            ->acceptJson()
            ->timeout(20)
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['preview_url' => false, 'body' => $body],
            ]);

        if (! $response->successful()) {
            return [
                'success'    => false,
                'message_id' => null,
                'error'      => 'HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 500),
            ];
        }

        $id = data_get($response->json(), 'messages.0.id');

        return [
            'success'    => (bool) $id,
            'message_id' => $id,
            'error'      => $id ? null : 'No message id',
        ];
    }

    private function sendViaTwilio(string $to, string $body): array
    {
        $from = config('services.whatsapp.from');
        $url  = config('services.whatsapp.api_url')
            ?: 'https://api.twilio.com/2010-04-01/Accounts/' . config('services.whatsapp.account_sid') . '/Messages.json';

        $response = Http::asForm()
            ->withBasicAuth(config('services.whatsapp.account_sid'), config('services.whatsapp.token'))
            ->timeout(20)
            ->post($url, [
                'From' => str_starts_with($from, 'whatsapp:') ? $from : 'whatsapp:' . $from,
                'To'   => 'whatsapp:+' . ltrim($to, '+'),
                'Body' => $body,
            ]);

        if (! $response->successful()) {
            return [
                'success'    => false,
                'message_id' => null,
                'error'      => 'HTTP ' . $response->status(),
            ];
        }

        $id = $response->json('sid');

        return [
            'success'    => (bool) $id,
            'message_id' => $id,
            'error'      => $id ? null : 'No sid',
        ];
    }

    private function sendViaCustom(string $to, string $body): array
    {
        $response = Http::withToken(config('services.whatsapp.token'))
            ->acceptJson()
            ->timeout(20)
            ->post(config('services.whatsapp.api_url'), [
                'to'   => $to,
                'body' => $body,
            ]);

        if (! $response->successful()) {
            return ['success' => false, 'message_id' => null, 'error' => 'HTTP ' . $response->status()];
        }

        $json = $response->json() ?? [];

        return [
            'success'    => true,
            'message_id' => $json['id'] ?? $json['message_id'] ?? null,
            'error'      => null,
        ];
    }

    private function mask(string $phone): string
    {
        return strlen($phone) < 6 ? '***' : substr($phone, 0, 4) . '****' . substr($phone, -3);
    }
}