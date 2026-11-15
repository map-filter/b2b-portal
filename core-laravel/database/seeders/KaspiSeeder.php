<?php

namespace Database\Seeders;

use App\Enums\Kaspi\KaspiMode;
use App\Models\Kaspi\KaspiProduct;
use App\Models\Kaspi\KaspiSettings;
use App\Models\Kaspi\KaspiStore;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Kaspi\KaspiCoreOrders;
use Illuminate\Database\Seeder;

class KaspiSeeder extends Seeder
{
    public function run(KaspiCoreOrders $core): void
    {
        mt_srand(11);

        KaspiSettings::current()->update([
            'mode' => config('services.kaspi.mode', KaspiMode::OneC->value),
            'merchant_id' => 'DEMO01',
            'company_name' => 'ЭлектроОпт (демо)',
            'price_type_id' => null,
            'notify_user_id' => User::where('email', 'manager@demo.kz')->value('id'),
            'poll_minutes' => 1,
            'sync_days' => 60,
        ]);

        KaspiStore::create([
            'store_id' => 'PP1',
            'name' => 'Павлодар, пункт выдачи на складе',
            'warehouse_id' => Warehouse::orderBy('id')->value('id'),
        ]);

        $retail = PriceType::where('code', 'retail')->value('id');
        $prices = ProductPrice::where('price_type_id', $retail)->pluck('price', 'product_id');

        Product::query()->active()->where('unit', 'шт')->orderBy('id')->get()
            ->shuffle(11)
            ->take(90)
            ->values()
            ->each(fn (Product $product, int $index) => KaspiProduct::create([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'price' => ceil((float) ($prices[$product->id] ?? $product->base_price) * 1.05 / 10) * 10,
                'is_published' => $index % 18 !== 17,
            ]));

        $core->channel();
    }
}
