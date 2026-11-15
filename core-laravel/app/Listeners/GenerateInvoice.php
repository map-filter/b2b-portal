<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Services\Invoices\InvoiceRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;

class GenerateInvoice implements ShouldQueue
{
    public function __construct(private InvoiceRenderer $renderer) {}

    public function handle(OrderPlaced $event): void
    {
        $this->renderer->render($event->order);
    }
}
