<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Mail\NewOrderMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class NotifyManagerAboutOrder implements ShouldQueue
{
    public function handle(OrderPlaced $event): void
    {
        $order = $event->order->load(['company.manager', 'user', 'items']);
        $manager = $order->company->manager;

        if ($manager) {
            Mail::to($manager->email)->send(new NewOrderMail($order));
        }
    }
}
