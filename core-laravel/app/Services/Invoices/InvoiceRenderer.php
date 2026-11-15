<?php

namespace App\Services\Invoices;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class InvoiceRenderer
{
    public function path(Order $order): string
    {
        return "invoices/{$order->number}.pdf";
    }

    public function render(Order $order): string
    {
        $order->loadMissing(['items', 'company', 'contract']);
        $supplier = config('b2b.supplier');
        $vatRate = (string) $supplier['vat_percent'];
        $vat = bcdiv(bcmul((string) $order->total, $vatRate, 4), bcadd('100', $vatRate, 4), 2);

        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $order,
            'supplier' => $supplier,
            'vat' => $vat,
            'validUntil' => $order->created_at->copy()->addDays(config('b2b.invoice_valid_days')),
        ])->setPaper('a4');

        Storage::put($this->path($order), $pdf->output());

        return $this->path($order);
    }

    public function ensure(Order $order): string
    {
        return Storage::exists($this->path($order)) ? $this->path($order) : $this->render($order);
    }
}
