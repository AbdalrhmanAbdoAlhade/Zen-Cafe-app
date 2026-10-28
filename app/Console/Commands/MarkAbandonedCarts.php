<?php

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Command;

class MarkAbandonedCarts extends Command
{
    protected $signature = 'carts:mark-abandoned {--minutes=60 : دقائق بدون نشاط}';

    protected $description = 'تعليمليم السلال النشطة الخاملة كـ abandoned';

    public function handle(): int
    {
        $minutes = max(15, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);
        $count = 0;

        Cart::query()
            ->where('status', Cart::STATUS_ACTIVE)
            ->where('items_count', '>', 0)
            ->where(function ($q) use ($cutoff) {
                $q->where('last_activity_at', '<', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('last_activity_at')
                            ->where('updated_at', '<', $cutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($carts) use (&$count) {
                foreach ($carts as $cart) {
                    $cart->markAbandoned($cart->abandon_stage ?? Cart::STAGE_ITEMS_ADDED);
                    $count++;
                }
            });

        $this->info("Marked {$count} cart(s) as abandoned (idle > {$minutes} min).");

        return self::SUCCESS;
    }
}