<?php

namespace App\Enums\Kaspi;

enum KaspiMode: string
{
    case OneC = 'one_c';
    case Standalone = 'standalone';

    public function label(): string
    {
        return match ($this) {
            self::OneC => 'С 1С: остатки и цены приходят из 1С, заказы уходят в 1С',
            self::Standalone => 'Без 1С: остатки ведутся в панели, принятый заказ списывает остаток',
        };
    }
}
