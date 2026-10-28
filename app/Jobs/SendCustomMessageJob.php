<?php

namespace App\Jobs;

use App\Mail\CustomMessageMail;
use App\Models\CustomMessageLog;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCustomMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 45;

    public function __construct(public int $logId) {}

    public function handle(WhatsAppService $whatsapp): void
    {
        $log = CustomMessageLog::find($this->logId);
        if (! $log || $log->status === CustomMessageLog::STATUS_SENT) {
            return;
        }

        $log->increment('attempts');

        try {
            if ($log->channel === CustomMessageLog::CHANNEL_EMAIL) {
                $this->sendEmail($log);
            } else {
                $this->sendWhatsApp($log, $whatsapp);
            }
        } catch (\Throwable $e) {
            Log::error('Custom message job failed', [
                'log_id'  => $log->id,
                'channel' => $log->channel,
                'message' => $e->getMessage(),
            ]);

            $log->update([
                'status'        => CustomMessageLog::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            throw $e;
        }
    }

    private function sendEmail(CustomMessageLog $log): void
    {
        Mail::to($log->to_address)->send(new CustomMessageMail(
            subjectLine: $log->subject ?: 'رسالة من زين كافيه',
            body: $log->body,
        ));

        $log->update([
            'status'        => CustomMessageLog::STATUS_SENT,
            'sent_at'       => now(),
            'error_message' => null,
        ]);
    }

    private function sendWhatsApp(CustomMessageLog $log, WhatsAppService $whatsapp): void
    {
        $result = $whatsapp->sendText($log->to_address, $log->body);

        if (! empty($result['success'])) {
            $log->update([
                'status'              => CustomMessageLog::STATUS_SENT,
                'provider_message_id' => $result['message_id'] ?? null,
                'sent_at'             => now(),
                'error_message'       => null,
            ]);
            return;
        }

        $log->update([
            'status'        => CustomMessageLog::STATUS_FAILED,
            'error_message' => mb_substr((string) ($result['error'] ?? 'unknown'), 0, 1000),
        ]);

        throw new \RuntimeException('WhatsApp failed: ' . ($result['error'] ?? 'unknown'));
    }
}