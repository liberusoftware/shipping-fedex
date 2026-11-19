<?php

declare(strict_types=1);

namespace Liberu\Shipping\FedEx;

use Illuminate\Support\ServiceProvider;
use Liberu\Shipping\Contracts\Contracts\ShippingProviderRegistryContract;

final class FedExShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FedExShippingAdapter::class);
    }

    public function boot(): void
    {
        if ($this->app->bound(ShippingProviderRegistryContract::class)) {
            $this->app->make(ShippingProviderRegistryContract::class)->register(
                $this->app->make(FedExShippingAdapter::class),
            );
        }
    }
}
