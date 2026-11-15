<?php

namespace App\Enums\Kaspi;

enum KaspiCancelReason: string
{
    case BuyerCancellation = 'BUYER_CANCELLATION_BY_MERCHANT';
    case BuyerNotReachable = 'BUYER_NOT_REACHABLE';
    case OutOfStock = 'MERCHANT_OUT_OF_STOCK';

    public function label(): string
    {
        return match ($this) {
            self::BuyerCancellation => 'Отказ покупателя',
            self::BuyerNotReachable => 'Не удалось связаться с покупателем',
            self::OutOfStock => 'Товара нет в наличии',
        };
    }

    public static function describe(?string $value): ?string
    {
        return match ($value) {
            null, '' => null,
            'BUYER_CANCELLATION' => 'Покупатель отменил в приложении',
            'MERCHANT_ACCEPT_TIMEOUT' => 'Не принят вовремя',
            default => self::tryFrom($value)?->label() ?? $value,
        };
    }
}
