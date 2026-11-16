<?php

namespace App\Enums\Kaspi;

enum KaspiState: string
{
    case New = 'NEW';
    case SignRequired = 'SIGN_REQUIRED';
    case Pickup = 'PICKUP';
    case Delivery = 'DELIVERY';
    case KaspiDelivery = 'KASPI_DELIVERY';
    case Archive = 'ARCHIVE';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новые',
            self::SignRequired => 'На подписании',
            self::Pickup => 'Самовывоз',
            self::Delivery => 'Своя доставка',
            self::KaspiDelivery => 'Kaspi Доставка',
            self::Archive => 'Архив',
        };
    }
}
