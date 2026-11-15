<?php

namespace App\Services\Cart;

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class CartImporter
{
    private const MAX_ROWS = 1000;

    public function __construct(private CartService $cart) {}

    /**
     * @return array{added: int, not_found: list<array{row: int, sku: string}>, invalid: list<array{row: int, reason: string}>}
     */
    public function import(User $user, UploadedFile $file): array
    {
        $rows = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        [$skuColumn, $quantityColumn, $start] = $this->detectColumns($rows);

        $lines = [];
        $invalid = [];

        foreach (array_slice($rows, $start, self::MAX_ROWS, true) as $index => $row) {
            $sku = trim((string) ($row[$skuColumn] ?? ''));
            $rawQuantity = $row[$quantityColumn] ?? null;

            if ($sku === '' && ($rawQuantity === null || $rawQuantity === '')) {
                continue;
            }

            $quantity = (int) round((float) str_replace([' ', ','], ['', '.'], (string) $rawQuantity));

            if ($sku === '' || $quantity < 1) {
                $invalid[] = ['row' => $index + 1, 'reason' => $sku === '' ? 'Нет артикула' : "Неверное количество «{$rawQuantity}»"];

                continue;
            }

            $key = Str::upper($sku);
            $lines[$key] = ['row' => $index + 1, 'sku' => $sku, 'quantity' => ($lines[$key]['quantity'] ?? 0) + $quantity];
        }

        $products = Product::query()
            ->active()
            ->whereIn('sku', collect($lines)->pluck('sku')->map(fn ($sku) => Str::upper($sku)))
            ->get()
            ->keyBy(fn (Product $product) => Str::upper($product->sku));

        $notFound = [];
        $added = 0;

        foreach ($lines as $key => $line) {
            if (! $products->has($key)) {
                $notFound[] = ['row' => $line['row'], 'sku' => $line['sku']];

                continue;
            }

            $this->cart->add($user, $products[$key], $line['quantity']);
            $added++;
        }

        return ['added' => $added, 'not_found' => $notFound, 'invalid' => $invalid];
    }

    public function template(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Заказ');
        $sheet->fromArray([
            ['Артикул', 'Количество', 'Наименование (необязательно)'],
            ['DEMO-1001', 100, 'Кабель ВВГнг(А)-LS 2x1.5'],
            ['DEMO-1035', 24, 'Автоматический выключатель 1P C16'],
        ]);
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        foreach (['A' => 16, 'B' => 14, 'C' => 44] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        return $spreadsheet;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{int, int, int}
     */
    private function detectColumns(array $rows): array
    {
        foreach (array_slice($rows, 0, 5, true) as $index => $row) {
            $sku = $quantity = null;

            foreach ($row as $column => $value) {
                $title = Str::lower(trim((string) $value));
                if ($sku === null && Str::contains($title, ['артикул', 'код', 'sku'])) {
                    $sku = $column;
                } elseif ($quantity === null && Str::contains($title, ['кол', 'qty', 'quantity'])) {
                    $quantity = $column;
                }
            }

            if ($sku !== null && $quantity !== null) {
                return [$sku, $quantity, $index + 1];
            }
        }

        return [0, 1, 0];
    }
}
