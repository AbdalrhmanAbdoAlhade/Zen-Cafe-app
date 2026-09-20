<?php

namespace App\Console\Commands;

use App\Services\LoyaltyService;
use Illuminate\Console\Command;

class ExpireLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:expire-points';
    protected $description = 'إسقاط نقاط الولاء المنتهية الصلاحية';

    public function handle(LoyaltyService $loyalty): int
    {
        $count = $loyalty->expirePoints();
        $this->info("تم إسقاط {$count} نقطة منتهية.");
        return self::SUCCESS;
    }
}