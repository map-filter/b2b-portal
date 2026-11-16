<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Order;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderNumberGenerator;
use App\Services\Pricing\PriceResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CompanySeeder extends Seeder
{
    public const PASSWORD = 'portal2026';

    public function run(PriceResolver $prices, OrderNumberGenerator $numbers): void
    {
        mt_srand(7);

        User::create([
            'role' => UserRole::Admin,
            'name' => 'Администратор',
            'email' => 'admin@electroopt.kz',
            'password' => self::PASSWORD,
        ]);

        $managers = collect([
            ['name' => 'Айгерим Сапарова', 'email' => 'manager@electroopt.kz', 'phone' => '+7 700 000 00 01'],
            ['name' => 'Олег Ким', 'email' => 'manager2@electroopt.kz', 'phone' => '+7 700 000 00 02'],
        ])->map(fn (array $row) => User::create($row + ['role' => UserRole::Manager, 'password' => self::PASSWORD]));

        $types = PriceType::all()->keyBy('code');
        $products = Product::all();

        foreach ($this->companies() as $index => $row) {
            $company = Company::create([
                'name' => $row['name'],
                'legal_name' => 'ТОО «'.$row['name'].'»',
                'bin' => sprintf('2611%08d', $index + 1),
                'legal_address' => $row['address'],
                'phone' => sprintf('+7 718 200 %02d %02d', $index, $index + 10),
                'email' => "info@{$row['slug']}.kz",
                'manager_id' => $managers[$index % 2]->id,
                'credit_limit' => $row['limit'],
                'payment_delay_days' => $row['delay'],
            ]);

            $contract = Contract::create([
                'company_id' => $company->id,
                'price_type_id' => $types[$row['price_type']]->id,
                'number' => sprintf('Д-%03d/2026', $index + 1),
                'starts_at' => '2026-01-01',
                'ends_at' => '2026-12-31',
                'discount_percent' => $row['discount'],
                'is_default' => true,
            ]);

            if ($row['special']) {
                $rows = $products->random(15)->map(fn (Product $product) => [
                    'contract_id' => $contract->id,
                    'product_id' => $product->id,
                    'price' => bcmul((string) $product->base_price, '0.70', 2),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('contract_prices')->insert($rows->all());
            }

            $director = User::create([
                'company_id' => $company->id,
                'role' => UserRole::ClientAdmin,
                'name' => $row['director'],
                'email' => "director@{$row['slug']}.kz",
                'password' => self::PASSWORD,
            ]);

            $buyer = User::create([
                'company_id' => $company->id,
                'role' => UserRole::ClientBuyer,
                'name' => $row['buyer'],
                'email' => "buyer@{$row['slug']}.kz",
                'password' => self::PASSWORD,
            ]);

            $this->orders($company, $contract, [$director, $buyer], $products, $prices, $numbers);
        }
    }

    private function orders(Company $company, Contract $contract, array $users, $products, PriceResolver $prices, OrderNumberGenerator $numbers): void
    {
        $count = mt_rand(6, 14);

        for ($i = 0; $i < $count; $i++) {
            $createdAt = Carbon::parse('2026-11-14 10:00')->subDays(mt_rand(0, 120))->setTime(mt_rand(8, 19), mt_rand(0, 59));
            $lines = $products->random(mt_rand(2, 9));
            $linePrices = $prices->forContract($contract, $lines);
            $status = $this->statusFor($createdAt);

            $items = $lines->map(function (Product $product) use ($linePrices) {
                $quantity = max($product->min_quantity, $product->pack_quantity * mt_rand(1, 5));

                return [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'unit' => $product->unit,
                    'quantity' => $quantity,
                    'price' => $linePrices[$product->id],
                    'total' => bcmul($linePrices[$product->id], (string) $quantity, 2),
                ];
            });

            $order = new Order([
                'number' => $numbers->next(),
                'company_id' => $company->id,
                'user_id' => $users[mt_rand(0, 1)]->id,
                'contract_id' => $contract->id,
                'status' => $status,
                'total' => $items->reduce(fn (string $sum, array $item) => bcadd($sum, $item['total'], 2), '0'),
                'delivery_address' => $company->legal_address,
            ]);
            $order->created_at = $createdAt;
            $order->updated_at = $createdAt;
            $order->save();

            $order->items()->createMany($items->all());

            $order->statusHistory()->create([
                'user_id' => $order->user_id,
                'to_status' => OrderStatus::New,
                'created_at' => $createdAt,
            ]);

            if ($status !== OrderStatus::New) {
                $order->statusHistory()->create([
                    'user_id' => $company->manager_id,
                    'from_status' => OrderStatus::New,
                    'to_status' => $status,
                    'created_at' => $createdAt->copy()->addHours(mt_rand(1, 30)),
                ]);
            }
        }
    }

    private function statusFor(Carbon $createdAt): OrderStatus
    {
        $age = $createdAt->diffInDays(Carbon::parse('2026-11-15'));

        if ($age < 2) {
            return OrderStatus::New;
        }

        if ($age < 7) {
            return [OrderStatus::Confirmed, OrderStatus::Assembling, OrderStatus::Shipped][mt_rand(0, 2)];
        }

        return mt_rand(0, 9) === 0 ? OrderStatus::Cancelled : OrderStatus::Completed;
    }

    private function companies(): array
    {
        return [
            ['name' => 'ЭнергоМонтаж Павлодар', 'slug' => 'energomontazh', 'address' => 'г. Павлодар, ул. Ломова, 10', 'price_type' => 'large_wholesale', 'discount' => 3, 'limit' => 5000000, 'delay' => 30, 'special' => true, 'director' => 'Ерлан Жумабаев', 'buyer' => 'Анна Петрова'],
            ['name' => 'СтройЭлектро', 'slug' => 'stroyelectro', 'address' => 'г. Астана, пр. Абая, 22', 'price_type' => 'wholesale', 'discount' => 5, 'limit' => 2000000, 'delay' => 14, 'special' => false, 'director' => 'Марат Сейткали', 'buyer' => 'Дина Абенова'],
            ['name' => 'Иртыш Электрик', 'slug' => 'irtysh', 'address' => 'г. Павлодар, ул. Торайгырова, 5', 'price_type' => 'wholesale', 'discount' => 0, 'limit' => 1000000, 'delay' => 7, 'special' => false, 'director' => 'Сергей Иванов', 'buyer' => 'Мадина Оспанова'],
            ['name' => 'Северный Ток', 'slug' => 'severtok', 'address' => 'г. Петропавловск, ул. Конституции, 3', 'price_type' => 'wholesale', 'discount' => 2, 'limit' => 1500000, 'delay' => 14, 'special' => false, 'director' => 'Андрей Ли', 'buyer' => 'Ольга Смирнова'],
            ['name' => 'ТехноСвет Астана', 'slug' => 'technosvet', 'address' => 'г. Астана, ул. Кенесары, 40', 'price_type' => 'large_wholesale', 'discount' => 0, 'limit' => 3000000, 'delay' => 30, 'special' => true, 'director' => 'Нурлан Каримов', 'buyer' => 'Алия Мухамеджанова'],
            ['name' => 'ДомСтрой Сервис', 'slug' => 'domstroy', 'address' => 'г. Экибастуз, ул. Энергетиков, 12', 'price_type' => 'retail', 'discount' => 7, 'limit' => 0, 'delay' => 0, 'special' => false, 'director' => 'Виктор Ткаченко', 'buyer' => 'Жанна Бекова'],
            ['name' => 'Альфа Монтаж', 'slug' => 'alfamontazh', 'address' => 'г. Алматы, ул. Розыбакиева, 88', 'price_type' => 'wholesale', 'discount' => 4, 'limit' => 2500000, 'delay' => 14, 'special' => false, 'director' => 'Тимур Алиев', 'buyer' => 'Екатерина Волкова'],
            ['name' => 'ПромЭлектроСнаб', 'slug' => 'promelectro', 'address' => 'г. Караганда, ул. Бухар-Жырау, 51', 'price_type' => 'large_wholesale', 'discount' => 5, 'limit' => 8000000, 'delay' => 45, 'special' => true, 'director' => 'Асхат Нургалиев', 'buyer' => 'Ирина Кузнецова'],
        ];
    }
}
