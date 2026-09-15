<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Order;
use Illuminate\Support\Facades\Route;
use App\Contracts\PaymentGatewayInterface;
use App\Services\PendingPaymentGateway;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, PendingPaymentGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
   public function boot(): void
{
    Route::model('order', Order::class);
}
}
