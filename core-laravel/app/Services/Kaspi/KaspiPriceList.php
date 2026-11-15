<?php

namespace App\Services\Kaspi;

use App\Models\Kaspi\KaspiProduct;
use App\Models\Kaspi\KaspiSettings;
use App\Models\ProductPrice;
use App\Models\Stock;
use Illuminate\Support\Collection;
use XMLWriter;

/**
 * Прайс-лист в формате кабинета продавца Kaspi (kaspi_catalog). Kaspi забирает его по ссылке.
 */
class KaspiPriceList
{
    public function __construct(private KaspiStock $stock) {}

    /**
     * @return Collection<int, array{product: KaspiProduct, price: string, stocks: array<string, int>}>
     */
    public function offers(): Collection
    {
        $settings = KaspiSettings::current();
        $stores = $this->stock->stores();
        $links = KaspiProduct::query()
            ->where('is_published', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->with('product')
            ->orderBy('sku')
            ->get();

        $productIds = $links->pluck('product_id');
        $stocks = Stock::whereIn('product_id', $productIds)->whereIn('warehouse_id', $stores->pluck('warehouse_id'))->get()
            ->groupBy('product_id');
        $prices = ! $settings->standalone() && $settings->price_type_id
            ? ProductPrice::whereIn('product_id', $productIds)->where('price_type_id', $settings->price_type_id)->pluck('price', 'product_id')
            : collect();

        return $links->map(fn (KaspiProduct $link) => [
            'product' => $link,
            'price' => $this->price($link, $prices[$link->product_id] ?? null),
            'stocks' => $stores->mapWithKeys(fn ($store) => [
                $store->store_id => (int) floor((float) ($stocks[$link->product_id] ?? collect())->firstWhere('warehouse_id', $store->warehouse_id)?->quantity),
            ])->all(),
        ])->filter(fn (array $offer) => bccomp($offer['price'], '0', 2) > 0)->values();
    }

    public function xml(): string
    {
        $settings = KaspiSettings::current();
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'utf-8');
        $xml->startElement('kaspi_catalog');
        $xml->writeAttribute('date', now()->format('Y-m-d H:i'));
        $xml->writeAttribute('xmlns', 'kaspiShopping');
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->writeAttribute('xsi:schemaLocation', 'kaspiShopping http://kaspi.kz/kaspishopping.xsd');
        $xml->writeElement('company', (string) $settings->company_name);
        $xml->writeElement('merchantid', (string) $settings->merchant_id);
        $xml->startElement('offers');

        foreach ($this->offers() as $offer) {
            $product = $offer['product']->product;
            $xml->startElement('offer');
            $xml->writeAttribute('sku', $offer['product']->sku);
            $xml->writeElement('model', $product->name);
            $xml->writeElement('brand', (string) $product->brand);
            $xml->startElement('availabilities');
            foreach ($offer['stocks'] as $storeId => $count) {
                $xml->startElement('availability');
                $xml->writeAttribute('available', $count > 0 ? 'yes' : 'no');
                $xml->writeAttribute('storeId', $storeId);
                $xml->writeAttribute('preOrder', '');
                $xml->writeAttribute('stockCount', (string) max($count, 0));
                $xml->endElement();
            }
            $xml->endElement();
            $xml->writeElement('price', (string) (int) round((float) $offer['price']));
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    public function price(KaspiProduct $link, ?string $fromOneC = null): string
    {
        return (string) ($fromOneC ?? $link->price ?? $link->product->base_price);
    }
}
