<?php

namespace App\Providers;

use App\Listeners\PublishOrderEvents;
use App\Listeners\SyncOrderToBitrix24;
use App\Services\Messaging\MessageBus;
use App\Services\Messaging\RabbitMq;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MessageBus::class, RabbitMq::class);
    }

    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)->by($request->user()?->id ?: $request->ip()));
        Gate::define('viewApiDocs', fn ($user = null) => true);

        Event::subscribe(PublishOrderEvents::class);
        Event::subscribe(SyncOrderToBitrix24::class);
    }
}
