<?php

namespace App\Mail;

use App\Models\Order;
use App\Services\Invoices\InvoiceRenderer;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewOrderMail extends Mailable
{
    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Новый заказ {$this->order->number} от {$this->order->company->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.new-order');
    }

    public function attachments(): array
    {
        $path = app(InvoiceRenderer::class)->ensure($this->order);

        return [Attachment::fromStorage($path)->as("Счёт {$this->order->number}.pdf")->withMime('application/pdf')];
    }
}
