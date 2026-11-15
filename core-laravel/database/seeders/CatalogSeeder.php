<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    private int $skuCounter = 1000;

    public function run(): void
    {
        mt_srand(42);

        $warehouses = collect([
            ['name' => 'Павлодар, основной склад', 'address' => 'г. Павлодар, ул. Демо-Промышленная, 1'],
            ['name' => 'Астана', 'address' => 'г. Астана, ул. Демо-Складская, 7'],
            ['name' => 'Алматы', 'address' => 'г. Алматы, ул. Демо-Логистическая, 15'],
        ])->map(fn (array $row) => Warehouse::create($row));

        $priceTypes = collect([
            ['name' => 'Розничная', 'code' => 'retail', 'factor' => '1'],
            ['name' => 'Оптовая', 'code' => 'wholesale', 'factor' => '0.88'],
            ['name' => 'Крупный опт', 'code' => 'large_wholesale', 'factor' => '0.80'],
        ])->map(fn (array $row) => [
            'model' => PriceType::create(['name' => $row['name'], 'code' => $row['code']]),
            'factor' => $row['factor'],
        ]);

        foreach ($this->tree() as $rootName => $children) {
            $root = $this->category($rootName);

            foreach ($children as $childName => $generator) {
                $category = $this->category($childName, $root->id);

                foreach ($this->{$generator}() as $item) {
                    $product = $this->product($category, $item);
                    $this->prices($product, $priceTypes);
                    $this->stocks($product, $warehouses);
                }
            }
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function tree(): array
    {
        return [
            'Кабель и провод' => [
                'Кабель силовой' => 'powerCables',
                'Провод монтажный' => 'wires',
            ],
            'Модульное оборудование' => [
                'Автоматические выключатели' => 'breakers',
                'УЗО' => 'rcds',
                'Дифференциальные автоматы' => 'rcbos',
            ],
            'Электроустановочные изделия' => [
                'Розетки' => 'sockets',
                'Выключатели' => 'switches',
            ],
            'Светотехника' => [
                'Светодиодные лампы' => 'lamps',
                'Светильники и панели' => 'panels',
                'Прожекторы' => 'floodlights',
            ],
            'Щиты и боксы' => [
                'Щиты распределительные' => 'boxes',
            ],
            'Удлинители и фильтры' => [
                'Удлинители' => 'extensions',
            ],
        ];
    }

    private function category(string $name, ?int $parentId = null): Category
    {
        static $sort = 0;

        return Category::create([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => Str::slug($name),
            'sort' => ++$sort,
        ]);
    }

    /**
     * @param  array{name: string, brand: string, price: int|float, unit?: string, pack?: int, min?: int, specs: array<string, mixed>, description?: string}  $item
     */
    private function product(Category $category, array $item): Product
    {
        $sku = 'DEMO-'.(++$this->skuCounter);

        return Product::create([
            'category_id' => $category->id,
            'sku' => $sku,
            'name' => $item['name'],
            'slug' => Str::slug($item['name']).'-'.Str::lower($sku),
            'brand' => $item['brand'],
            'description' => $item['description'] ?? null,
            'unit' => $item['unit'] ?? 'шт',
            'min_quantity' => $item['min'] ?? 1,
            'pack_quantity' => $item['pack'] ?? 1,
            'base_price' => round($item['price'], 2),
            'specs' => $item['specs'],
        ]);
    }

    private function prices(Product $product, $priceTypes): void
    {
        $rows = $priceTypes->map(fn (array $type) => [
            'product_id' => $product->id,
            'price_type_id' => $type['model']->id,
            'price' => bcmul((string) $product->base_price, $type['factor'], 2),
            'updated_at' => now(),
        ]);

        DB::table('product_prices')->insert($rows->all());
    }

    private function stocks(Product $product, $warehouses): void
    {
        $scale = $product->unit === 'м' ? 50 : 1;

        $rows = $warehouses->map(fn (Warehouse $warehouse, int $index) => [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => mt_rand(0, 10) < 2 ? 0 : mt_rand(5, $index === 0 ? 400 : 120) * $scale,
            'updated_at' => now(),
        ]);

        DB::table('stocks')->insert($rows->all());
    }

    private function powerCables(): array
    {
        $items = [];

        foreach (['ВВГнг(А)-LS' => 1.0, 'ВВГ' => 0.85, 'NYM' => 1.15] as $mark => $factor) {
            foreach (['2x1.5' => 310, '3x1.5' => 420, '3x2.5' => 640, '3x4' => 980, '3x6' => 1450, '5x2.5' => 1020, '5x4' => 1600] as $section => $price) {
                $items[] = [
                    'name' => "Кабель {$mark} {$section} мм² (демо)",
                    'brand' => 'КабельСтандарт',
                    'price' => $price * $factor,
                    'unit' => 'м',
                    'pack' => 100,
                    'min' => 100,
                    'specs' => ['Марка' => $mark, 'Сечение' => $section, 'Материал жилы' => 'медь', 'Бухта, м' => 100],
                ];
            }
        }

        return $items;
    }

    private function wires(): array
    {
        $items = [];

        foreach (['ПВС' => ['2x0.75' => 150, '2x1.5' => 260, '3x1.5' => 360, '3x2.5' => 560], 'ПуГВ' => ['1x1.5' => 95, '1x2.5' => 150, '1x4' => 240, '1x6' => 350]] as $mark => $sections) {
            foreach ($sections as $section => $price) {
                $items[] = [
                    'name' => "Провод {$mark} {$section} мм² (демо)",
                    'brand' => 'КабельСтандарт',
                    'price' => $price,
                    'unit' => 'м',
                    'pack' => 100,
                    'min' => 100,
                    'specs' => ['Марка' => $mark, 'Сечение' => $section, 'Материал жилы' => 'медь', 'Бухта, м' => 100],
                ];
            }
        }

        return $items;
    }

    private function breakers(): array
    {
        $items = [];

        foreach ([1 => 1.0, 2 => 2.3, 3 => 3.6] as $poles => $factor) {
            foreach ([6, 10, 16, 20, 25, 32, 40, 50, 63] as $current) {
                foreach (['B', 'C'] as $curve) {
                    $items[] = [
                        'name' => "Автоматический выключатель ВА-47 {$poles}P {$curve}{$current} 4,5 кА (демо)",
                        'brand' => 'Модуль-Э',
                        'price' => (1650 + $current * 12) * $factor,
                        'pack' => 12,
                        'specs' => ['Полюсов' => $poles, 'Номинальный ток, А' => $current, 'Характеристика' => $curve, 'Отключающая способность, кА' => 4.5],
                    ];
                }
            }
        }

        return $items;
    }

    private function rcds(): array
    {
        $items = [];

        foreach ([2 => 1.0, 4 => 1.9] as $poles => $factor) {
            foreach ([16, 25, 32, 40, 63] as $current) {
                foreach ([30, 100] as $leak) {
                    $items[] = [
                        'name' => "УЗО ВД-63 {$poles}P {$current} А {$leak} мА тип AC (демо)",
                        'brand' => 'Модуль-Э',
                        'price' => (7800 + $current * 45) * $factor,
                        'pack' => 6,
                        'specs' => ['Полюсов' => $poles, 'Номинальный ток, А' => $current, 'Ток утечки, мА' => $leak, 'Тип' => 'AC'],
                    ];
                }
            }
        }

        return $items;
    }

    private function rcbos(): array
    {
        $items = [];

        foreach ([10, 16, 20, 25, 32, 40] as $current) {
            $items[] = [
                'name' => "Дифавтомат АД-12 1P+N C{$current} 30 мА (демо)",
                'brand' => 'Модуль-Э',
                'price' => 9400 + $current * 60,
                'pack' => 6,
                'specs' => ['Полюсов' => '1P+N', 'Номинальный ток, А' => $current, 'Характеристика' => 'C', 'Ток утечки, мА' => 30],
            ];
        }

        return $items;
    }

    private function sockets(): array
    {
        $items = [];

        foreach (['Лайн' => 1.0, 'Классика' => 1.35, 'Студио' => 1.9] as $series => $factor) {
            foreach (['белый', 'бежевый', 'антрацит'] as $color) {
                foreach (['с заземлением' => 1250, 'с заземлением и шторками' => 1490, 'двойная с заземлением' => 2290, 'с USB A+C' => 4900] as $type => $price) {
                    $items[] = [
                        'name' => "Розетка {$series} {$type}, {$color} (демо)",
                        'brand' => 'ЭлектроДом',
                        'price' => $price * $factor,
                        'pack' => 10,
                        'specs' => ['Серия' => $series, 'Цвет' => $color, 'Исполнение' => $type, 'Монтаж' => 'скрытый'],
                    ];
                }
            }
        }

        return $items;
    }

    private function switches(): array
    {
        $items = [];

        foreach (['Лайн' => 1.0, 'Классика' => 1.35, 'Студио' => 1.9] as $series => $factor) {
            foreach (['белый', 'бежевый', 'антрацит'] as $color) {
                foreach (['одноклавишный' => 1100, 'двухклавишный' => 1480, 'одноклавишный с подсветкой' => 1690] as $type => $price) {
                    $items[] = [
                        'name' => "Выключатель {$series} {$type}, {$color} (демо)",
                        'brand' => 'ЭлектроДом',
                        'price' => $price * $factor,
                        'pack' => 10,
                        'specs' => ['Серия' => $series, 'Цвет' => $color, 'Исполнение' => $type, 'Монтаж' => 'скрытый'],
                    ];
                }
            }
        }

        return $items;
    }

    private function lamps(): array
    {
        $items = [];

        foreach (['A60 E27' => [7, 10, 12, 15, 20], 'C37 E14' => [5, 7, 9], 'GU10' => [5, 7]] as $form => $powers) {
            foreach ($powers as $power) {
                foreach ([3000, 4000, 6500] as $temp) {
                    $items[] = [
                        'name' => "Лампа светодиодная {$form} {$power} Вт {$temp}K (демо)",
                        'brand' => 'Светоч',
                        'price' => 390 + $power * 38,
                        'pack' => 10,
                        'specs' => ['Цоколь' => explode(' ', $form)[1] ?? $form, 'Мощность, Вт' => $power, 'Цветовая температура, K' => $temp],
                    ];
                }
            }
        }

        return $items;
    }

    private function panels(): array
    {
        $items = [];

        foreach (['Панель 595x595 36 Вт' => 6900, 'Панель 595x595 40 Вт' => 7600, 'Линейный 1200 мм 36 Вт' => 5900, 'Даунлайт 18 Вт' => 3400, 'Пылевлагозащищённый IP65 1200 мм' => 8900] as $type => $price) {
            foreach ([4000, 6500] as $temp) {
                $items[] = [
                    'name' => "Светильник {$type} {$temp}K (демо)",
                    'brand' => 'Светоч',
                    'price' => $price,
                    'pack' => 4,
                    'specs' => ['Тип' => $type, 'Цветовая температура, K' => $temp],
                ];
            }
        }

        return $items;
    }

    private function floodlights(): array
    {
        $items = [];

        foreach ([10, 20, 30, 50, 100, 150, 200] as $power) {
            $items[] = [
                'name' => "Прожектор светодиодный СДО {$power} Вт 6500K IP65 (демо)",
                'brand' => 'Светоч',
                'price' => 1900 + $power * 95,
                'specs' => ['Мощность, Вт' => $power, 'Степень защиты' => 'IP65', 'Цветовая температура, K' => 6500],
            ];
        }

        return $items;
    }

    private function boxes(): array
    {
        $items = [];

        foreach (['ЩРН-П (накладной)' => 1.0, 'ЩРВ-П (встраиваемый)' => 1.2, 'ЩМП металлический' => 2.6] as $type => $factor) {
            foreach ([4, 8, 12, 18, 24, 36] as $modules) {
                $items[] = [
                    'name' => "Щит {$type} на {$modules} модулей (демо)",
                    'brand' => 'ВольтПро',
                    'price' => (2200 + $modules * 210) * $factor,
                    'specs' => ['Тип' => $type, 'Модулей' => $modules],
                ];
            }
        }

        return $items;
    }

    private function extensions(): array
    {
        $items = [];

        foreach ([3, 4, 6] as $outlets) {
            foreach ([1.5, 3, 5] as $length) {
                $items[] = [
                    'name' => "Удлинитель {$outlets} гнезда с заземлением, {$length} м (демо)",
                    'brand' => 'ВольтПро',
                    'price' => 2100 + $outlets * 320 + $length * 260,
                    'pack' => 10,
                    'specs' => ['Гнёзд' => $outlets, 'Длина, м' => $length, 'Заземление' => 'да'],
                ];
            }
        }

        return $items;
    }
}
