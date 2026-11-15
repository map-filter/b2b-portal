<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductMatcher
{
    private const LOOKALIKES = ['а' => 'a', 'в' => 'b', 'с' => 'c', 'е' => 'e', 'к' => 'k', 'м' => 'm', 'н' => 'h', 'о' => 'o', 'п' => 'p', 'р' => 'p', 'т' => 't', 'х' => 'x'];

    private const NOISE = ['мм2', 'мм²', 'мм', 'шт', 'штук', 'м', 'метров', 'метра', 'кабель', 'провод', 'и', 'для', 'на', 'по'];

    /**
     * Подбирает товары под строку заявки: «ВВГ 3х2,5», «автомат С16», артикул.
     *
     * @return Collection<int, array{product: Product, score: float}>
     */
    public function match(string $query, int $limit = 3): Collection
    {
        $tokens = $this->tokens($query);

        if ($tokens === []) {
            return collect();
        }

        $exact = Product::active()->whereRaw('upper(sku) = ?', [Str::upper(trim($query))])->first();
        if ($exact) {
            return collect([['product' => $exact, 'score' => 1.0]]);
        }

        $candidates = Product::active()
            ->where(function ($query) use ($tokens) {
                foreach ($tokens as $token) {
                    preg_match('/\d/', $token)
                        ? $query->orWhereRaw('translate(lower(name), ?, ?) like ?', [$this->cyrillic(), $this->latin(), "%{$token}%"])
                        : $query->orWhereRaw('lower(name) like ?', ["%{$token}%"]);
                }
            })
            ->withSum('stocks as stock_total', 'quantity')
            ->limit(400)
            ->get();

        return $candidates
            ->map(function (Product $product) use ($tokens) {
                $name = $this->normalize($product->name);
                $hits = collect($tokens)->filter(fn ($token) => $this->contains($name, $token));
                $numericMissed = collect($tokens)->filter(fn ($token) => preg_match('/\d/', $token))->diff($hits)->count();

                $wordStarts = $hits->filter(fn ($token) => preg_match('/(^|[^\pL\d])'.preg_quote($token, '/').'/u', $name))->count();

                return [
                    'product' => $product,
                    'score' => round($hits->count() / count($tokens) - $numericMissed * 0.5 + $wordStarts * 0.01 + ($product->stock_total > 0 ? 0.005 : 0) - mb_strlen($name) / 100000, 4),
                ];
            })
            ->filter(fn ($row) => $row['score'] >= 0.5)
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /**
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        $normalized = $this->normalize($query);
        $normalized = preg_replace('/(\d),(\d)/u', '$1.$2', $normalized);
        $parts = preg_split('/[\s\/;:()«»"]+/u', $normalized, flags: PREG_SPLIT_NO_EMPTY);

        $tokens = array_filter(
            $parts,
            fn ($part) => mb_strlen($part) > 1 && ! in_array($part, self::NOISE, true) && ! preg_match('/^\d+(\.\d+)?(м|шт|m)$/u', $part),
        );

        return array_values(array_unique(array_map(
            fn ($token) => ! preg_match('/\d/', $token) && mb_strlen($token) >= 5
                ? preg_replace('/(ами|ями|ого|его|ому|ему|ыми|ими|ая|яя|ое|ее|ые|ие|ый|ий|ой|ую|ов|ев|ей|ам|ям|ах|ях|ом|ем|а|я|ы|и|е|у|ю|о)$/u', '', $token)
                : $token,
            $tokens,
        )));
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/(\d)\s*[хx×*]\s*(\d)/u', '$1x$2', $value);
        $value = preg_replace('/(\d),(\d)/u', '$1.$2', $value);
        $value = preg_replace('/(\d)\s+(вт|w|ка|ka|а|a|ма|ma|мм|м|k|к)(?=$|[\s,.)])/u', '$1$2', $value);

        return preg_replace_callback('/\S*\d\S*/u', fn ($match) => strtr($match[0], self::LOOKALIKES), $value);
    }

    private function contains(string $name, string $token): bool
    {
        if (preg_match('/\d/', $token)) {
            return (bool) preg_match('/(^|[^\da-z.])'.preg_quote($token, '/').'($|[^\d.])/u', $name);
        }

        return str_contains($name, $token);
    }

    private function cyrillic(): string
    {
        return implode('', array_keys(self::LOOKALIKES));
    }

    private function latin(): string
    {
        return implode('', self::LOOKALIKES);
    }
}
