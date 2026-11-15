<?php

namespace App\Providers;

use App\Listeners\PublishOrderEvents;
use App\Listeners\SyncOrderToBitrix24;
use App\Services\Messaging\MessageBus;
use App\Services\Messaging\RabbitMq;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MessageBus::class, RabbitMq::class);
    }

    public function boot(): void
    {
        Event::subscribe(PublishOrderEvents::class);
        Event::subscribe(SyncOrderToBitrix24::class);
    }
}
